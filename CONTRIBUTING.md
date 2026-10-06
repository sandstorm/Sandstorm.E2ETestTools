# Developing Sandstorm.E2ETestTools

How the package is structured, tested and built inside. For using it in a project, see the [README](README.md).

## Folder structure

The rule: **whatever a project uses is shipped from `Classes/`, `Configuration/`, `Resources/` or `Templates/`;
`Tests/` holds only the package's own tests** - deleting `Tests/` mustn't break any project.

```
Sandstorm.E2ETestTools/
├── Classes/                  # shipped PHP, autoloaded
│   ├── Behat/                # the step traits + PlaywrightConnector - the package's main product
│   └── Fixture/ StepGenerator/ WireMock/ Playwright/ Debugging/ ...
├── Configuration/            # shipped Flow configuration
├── Resources/                # shipped Flow resources (Fusion, the export button of the Neos UI)
├── Templates/                # copied into projects once, then owned by the project
│   ├── FeatureContext.php.default
│   └── playwright-bridge/
├── Tests/                    # the package's own tests - not shipped (export-ignore)
│   ├── Unit/  Functional/    # PHPUnit
│   ├── E2E/                  # Behat suite, doubles as examples
│   └── SystemUnderTest/      # development distribution the tests run in
├── mise.toml                 # tasks for the development distribution
└── .github/workflows/        # CI
```

Why there:

- **Step traits in `Classes/Behat/`:** projects use them in their own `FeatureContext`, so they're API, not the
  package's tests - the same move Neos.Behat made for Neos 9 (`Classes/FlowBootstrapTrait` replaces the deprecated
  `Tests/Behat/FlowContextTrait`). Steps for the package's *own* suite stay in `Tests/E2E/Features/Bootstrap/`.
- **Templates in `Templates/`, not `Resources/`:** `Resources/` is what Flow serves and reads at runtime. The templates
  are never used at runtime; they're copied into a project once and changed there.
- **Development distribution in `Tests/SystemUnderTest/`:** it exists only to run the tests. "System under test" is the
  term the package uses everywhere (`Production/E2E-SUT`, `SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT`).
- **`Tests/` stays out of Flow:** composer.json autoloads it (the tests run from the installed package), but
  `Neos.Flow.object.includeClasses` keeps Flow from reflecting it.

## Development distribution

The package brings its own Dockerised Neos distribution in `Tests/SystemUnderTest/`, so all its tests run without a
project:

- `<version>/` (`neos9`) - one distribution per supported Neos version, built from the shared `Dockerfile` and
  `docker-compose.base.yml`;
- `neos-root/` - copied into the image: Caddy config, PHP ini and the Flow contexts `Production/E2E-SUT` (system under
  test, port 9090 in the container, `127.0.0.1:19090` on the host), `Testing/Behat` and `Testing` (functional tests);
- `DistributionPackages/Sandstorm.E2ETestTools.TestSite/` - the minimal site the E2E suite runs against;
- services: neos (SUT + Behat), maria-db, redis-cache, playwright-bridge (built from `Templates/playwright-bridge`),
  wiremock.

The package code is mounted into the container, so changes need no rebuild - only a changed `composer.json` or
`Dockerfile` does.

```bash
mise trust              # once
mise run build          # build the images
mise run start          # start and wait until the system under test answers
mise run tests          # unit, functional and E2E tests
mise run tests:e2e --tags @playwright   # only some scenarios
mise run down           # remove containers and volumes
```

`SUT=<version>` selects another distribution. Screenshots, traces and the logs of failed scenarios land in
`Tests/SystemUnderTest/e2e-results/`. To watch the browser, start the bridge on the host (`HEADLESS=false node index.js`
in `Templates/playwright-bridge`) and the distribution with `PLAYWRIGHT_API_URL=http://host.docker.internal:3000 mise
run start`.

GitHub Actions (`.github/workflows/tests.yml`) runs the same tasks for every distribution. The image layers come from
the GitHub Actions cache (`Tests/SystemUnderTest/docker-compose.ci-cache.yml`), so the image is only rebuilt when the
`Dockerfile` or a `composer.json` changes - a newer Neos patch release arrives with such a change, or after deleting the
cache (repository → Actions → Caches). In the log of "Build the distribution", a working cache shows `CACHED` for the
Dockerfile steps and an export to the GitHub Actions cache without error.

## Tests

- **Unit and functional (PHPUnit, `Tests/Unit`, `Tests/Functional`):** the fixture tooling (YAML format, export/import
  contract, Gherkin escaping, node tree collection, URI path lookup, export endpoint security), the WireMock admin
  client, script escaping (`JsValue`), the log copying (`LogDirectory`) and the guard of the pause step.
- **E2E (Behat, [`Tests/E2E/`](Tests/E2E/README.md)):** every shipped step, against the test site. The features double
  as the examples the README links to.

## Fixture tooling

Export and import share one model, so whatever an export writes, the import creates unchanged:

```
 export                                                    import
 ──────                                                    ──────
 subgraph (workspace + dimension)                          YAML file ─── NodeFixtureYaml::parseFile()
   │                                                       Gherkin table ─ NodeFixtureGherkin::nodesFromTable()
   ▼                                                           │
 NodeFixtureCollector ──▶ NodeFixture ◀────────────────────────┘
 (button, CLI, StepGenerator)   │  nodes: NodeFixtureRow[]      │
                                │  references: ReferenceFixtureRow[]
                 ┌──────────────┴──────────┐                    ▼
                 ▼                         ▼             NodeFixtureImporter ──▶ content repository commands
       NodeFixtureYaml::dump()   NodeFixtureGherkin::steps()     (create nodes, hide, set references)
```

- Rows hold **serialized** property values, as the event store has them: plain JSON/YAML values, assets as
  `{"__flow_object_type": ..., "__identifier": ...}`. The importer turns them into PHP values with the property types
  the NodeType declares - so a fixture stays readable and diffable, and needs no PHP objects.
- Each format (YAML, Gherkin tables) lives in one class, writing and reading - format changes happen in one place and
  are pinned by round-trip tests (`Tests/Unit/Fixture`).
- Classes in `Classes/` hold the logic; the Behat traits only map steps to it.
