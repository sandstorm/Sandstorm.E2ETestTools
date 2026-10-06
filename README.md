# End-To-End Test Tools for Neos

Behat steps and glue code for testing Neos 9 sites at three levels: Fusion rendering in-process, full pages in a real
browser via Playwright, and the Neos backend. Tests are written in Gherkin and run against a content repository that
is reset and filled with fixtures for every scenario.

```gherkin
@flowEntities
Feature: Headline integration

  Scenario: a headline node renders as h1
    Given I have a site for Site Node "site" with name "My Site"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                   | Properties                                   | DimensionSpacePoint |
      | homepage        |               | My.Site:Document.StartPage | {"uriPathSegment":"home","title":"Homepage"} | {"language":"de"}   |
      | headline        | homepage/main | My.Site:Content.Headline   | {"title":"Hello"}                            | {"language":"de"}   |
    And I get the node "headline" in dimension '{"language":"de"}'
    When I render the Fusion object "/testcase" with the current context node:
      """
      testcase = My.Site:Content.Headline
      """
    Then in the fusion output, the inner HTML of CSS selector "h1" matches "Hello"
```

## What you get

- **Fusion rendering in the Behat process** - render a component or a NodeType's integration with a context node and
  assert on the HTML via CSS selectors. No web server or browser involved.
- **Browser tests via Playwright** - Behat sends Playwright scripts to a small bridge service (HTTP), the browser hits
  your application on a dedicated port with its own Flow context and database.
- **Neos backend steps** - create users, log in, navigate menus and the document tree.
- **Generic browser steps** - page texts and titles, buttons, links and form fields by their caption or label, cookies
  and web storage (including presetting a cookie consent before the first visit).
- **Content fixtures** - nodes, references, hidden state and assets as Gherkin tables or YAML files, created through
  the Neos 9 content repository API.
- **Fixture export** - export existing pages (backend button or CLI) into that format: realistic test data without
  writing node tables by hand.
- **Mocked third-party APIs** - WireMock stubs per scenario, and checks of the calls your site made.
- **Debugging and CI** - screenshots of failing steps, Playwright traces and server logs of failing scenarios, a
  pause step, a GitLab CI example running the tests inside the deployed image.

Requires Neos 9 / Flow 9. For Neos 8, use the 8.x releases (latest: 8.3.2). For Symfony projects, see
[README.Symfony.md](./README.Symfony.md).

<!-- TOC -->

- [What you get](#what-you-get)
- [Setup](#setup)
  - [1. Install the package](#1-install-the-package)
  - [2. Two Flow Contexts, Two Ports](#2-two-flow-contexts-two-ports)
  - [3. behat.yml.dist](#3-behatymldist)
  - [4. FeatureContext.php](#4-featurecontextphp)
  - [5. Playwright (playwright-bridge)](#5-playwright-playwright-bridge)
  - [6. Project tasks (recommended)](#6-project-tasks-recommended)
  - [7. CI Pipeline (optional)](#7-ci-pipeline-optional)
- [Writing Behat Tests](#writing-behat-tests)
  - [Tags](#tags)
  - [Fixtures](#fixtures)
    - [Default dimension space point](#default-dimension-space-point)
  - [Fixtures from existing content](#fixtures-from-existing-content)
    - [Fixtures for AI agents](#fixtures-for-ai-agents)
  - [Steps](#steps)
  - [Dynamic SUT URL](#dynamic-sut-url)
  - [Mocked third-party APIs (WireMock)](#mocked-third-party-apis-wiremock)
- [Running Behat Tests](#running-behat-tests)
  - [Debugging](#debugging)
- [Migrating tests from Neos 8](#migrating-tests-from-neos-8)
- [Troubleshooting](#troubleshooting)
- [Developing this package](#developing-this-package)
  - [Folder structure](#folder-structure)
  - [Development distribution](#development-distribution)
  - [Unit and functional tests](#unit-and-functional-tests)
  - [Architecture](#architecture)
    - [Fixture tooling](#fixture-tooling)

<!-- /TOC -->

# Setup

Setup is done by hand, once per project, in order — there's deliberately no setup command, since web server,
ports, Docker and CI differ from project to project. Steps 1–5 are required: skip step 2 and running Behat against
your normal dev database will delete its content.

## 1. Install the package

```bash
# Pulls in the traits (PlaywrightTrait, FusionRenderingTrait, NeosBackendControlTrait, ...)
# your FeatureContext.php will use below, plus this package's own Behat/Playwright glue code.
composer require --dev sandstorm/e2etesttools
```

## 2. Two Flow Contexts, Two Ports

E2E tests need full control over the database (creating/deleting nodes, resetting workspaces),
so they need their own database — which means their own Flow context. That context also needs
to be reachable over HTTP for Playwright, so it needs its own port too. Two contexts are involved:

- **`Testing/Behat`** — the context the Behat CLI process itself runs under. It creates the fixtures in the database
  the SUT serves, so it needs the same database settings as your SUT context (plus the cache and resource settings
  below) in `Configuration/Testing/Behat/Settings.yaml`.
- **Your SUT context** — whatever context actually serves the app for Playwright to hit (e.g.
  `Production/E2E-SUT`, `Development/Docker/Behat` — name it after your own environment). Needs a
  second entry point on its own port; how you configure that is up to your web server — see
  [Troubleshooting](#troubleshooting) for a gotcha that applies regardless of which one you use.

On top of your normal configuration, both point to a separate database — e.g. via `DB_NEOS_DATABASE_E2ETEST`:

```yaml
Neos:
  Flow:
    persistence:
      backendOptions:
        dbname: '%env:DB_NEOS_DATABASE_E2ETEST%'
```

Create that database once, then migrate it in the SUT context (again after pulling new migrations):

```bash
mysql -e 'CREATE DATABASE IF NOT EXISTS neos_e2etest'   # or your DB's equivalent
FLOW_CONTEXT=Production/E2E-SUT ./flow doctrine:migrate
```

Caches the test runner must invalidate between scenarios (e.g. the Fusion content cache) need the same storage in both
contexts (e.g. the same Redis database), so a flush in the Behat process reaches the SUT — see
[Troubleshooting](#troubleshooting) item 4.

If you use asset fixtures (`I have a textual persistent resource ...`, `I have the following images:`), the Behat
context also needs the SUT's persistent resource storage and target — Flow's `Testing` defaults use separate ones
(`.../Test/`, `_Resources/Testing/`), and the SUT would 404 on the files. In `Configuration/Testing/Behat/Settings.yaml`
(values as in your SUT context):

```yaml
Neos:
  Flow:
    resource:
      storages:
        defaultPersistentResourcesStorage:
          storageOptions:
            path: '%FLOW_PATH_DATA%Persistent/Resources/'
      targets:
        localWebDirectoryPersistentResourcesTarget:
          targetOptions:
            path: '%FLOW_PATH_WEB%_Resources/Persistent/'
            baseUri: '_Resources/Persistent/'
            subdivideHashPathSegment: true
```

## 3. behat.yml.dist

Behat needs its own minimal config telling it where your feature files and step-definition
context live — create `DistributionPackages/Your.SitePackageKey/Tests/Behavior/behat.yml.dist`:

```yaml
default:
  autoload:
    '': "%paths.base%/Features/Bootstrap"
  suites:
    behat:
      paths:
        - "%paths.base%/Features"
      contexts:
        - FeatureContext
```

## 4. FeatureContext.php

`FeatureContext` is the PHP class Behat calls into for every step. This package ships a working
skeleton with the trait wiring already correct, so copy it rather than assembling that boilerplate
yourself:

```bash
cp Packages/Application/Sandstorm.E2ETestTools/Templates/FeatureContext.php.default \
   DistributionPackages/Your.SitePackageKey/Tests/Behavior/Features/Bootstrap/FeatureContext.php
```

The traits live in `Classes/Behat/` (namespace `Sandstorm\E2ETestTools\Behat`) and are autoloaded by Composer - no
`require_once` needed. Replace the site package key passed to `setupFusionRendering(...)` - the template ships with a
placeholder that fails loudly if left unedited.

## 5. Playwright (playwright-bridge)

The Playwright↔Behat bridge (`index.js`) is deliberately **not** composer/npm-installed — it's
meant to be copied and adjusted per project:

```bash
cp -r Packages/Application/Sandstorm.E2ETestTools/Templates/playwright-bridge ./playwright-bridge
cd playwright-bridge && npm install && npx playwright install && cd ..
```

We suggest naming the folder `playwright-bridge` at the root of your Git repository (in our projects, usually one level
above the Neos root directory). See [Running Behat Tests](#running-behat-tests) below for starting it and running
the suite.

The bridge runs on your host (it drives a real browser), Behat usually inside your app container. `setupPlaywright()`
needs two environment variables in the Behat process, e.g. in your `docker-compose.yml`:

```yaml
environment:
  # where Behat reaches the bridge (from inside a container: the host)
  PLAYWRIGHT_API_URL: 'http://host.docker.internal:3000'
  # where the browser (on the host) reaches the SUT port from step 2
  SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT: 'http://127.0.0.1:9090'
```

## 6. Project tasks (recommended)

The recurring commands — start/stop the bridge, create + migrate the E2E database, run Behat (optionally for a single
file or scenario) — are worth wrapping in your project's task runner (mise, make, npm scripts, ...), so nobody has to
remember them. For example, the Neos-on-Docker kickstart ships `mise run tests:e2e:start-bridge` and
`mise run tests:e2e [path]`, the latter roughly doing:

```bash
docker compose exec maria-db /createTestingDB.sh
docker compose exec neos bash -c "FLOW_CONTEXT=Production/E2E-SUT ./flow doctrine:migrate"
docker compose exec neos bin/behat -c Packages/Sites/Your.SitePackageKey/Tests/Behavior/behat.yml.dist $1
```

## 7. CI Pipeline (optional)

The idea, regardless of CI system: run the E2E job **inside the same image you deploy** (build once, test that
artifact — not a separate CI-only build), give it a database and Redis service, and give it the Playwright bridge
as its own service too (build `playwright-bridge`'s own small image separately, e.g. from its `Dockerfile`).

A CI job usually only ever needs to serve the SUT context — unlike local dev, which runs both your normal dev vhost
*and* the SUT vhost from the same long-lived container. That means the context-routing gotcha in
[Troubleshooting](#troubleshooting) doesn't apply in CI: just set `FLOW_CONTEXT` to your SUT context directly as a
job variable, no `$_SERVER`-to-`getenv()` bridge needed there.

Two things commonly need doing at job-runtime rather than at image-build-time, since a production image is usually
built lean:

- If your production image is built with `--no-dev`, Behat and this package's dev-only pieces won't be installed —
  re-run `composer install --dev` (or your dev-dependency equivalent) as the job's first step.
- If the web server config serving your SUT vhost only ships in a local-dev image layer (see
  [Two Flow Contexts, Two Ports](#2-two-flow-contexts-two-ports)), copy that config file into the running container
  before starting the server.

Then: migrate/warm the SUT's caches, start the web server in the background, point
`PLAYWRIGHT_API_URL`/`SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT` at the right hostnames for your CI system's networking,
and run `bin/behat` — a JUnit-format report (`--format junit --out <dir>`) is worth adding so your CI system can show
per-scenario results rather than just a pass/fail job.

Illustrated with GitLab CI, since that's what we use — the same shape (image reuse, services, env vars shared with
those services, JUnit reporting) applies to any CI system:

```yaml
e2e_test:
  stage: test
  image:
    name: $CI_REGISTRY_IMAGE/neos:$CI_COMMIT_REF_SLUG   # the image you already build for deployment
    entrypoint: [ "" ]                                   # skip its normal startup sequence
  variables:
    FLOW_CONTEXT: Production/E2E-SUT
    DB_NEOS_DATABASE_E2ETEST: ci_test
    REDIS_HOST: redis
    REDIS_PORT: 6379
  services:
    - name: mariadb:11.8
    - name: redis:7
    - name: $CI_REGISTRY_IMAGE/playwright-bridge:$CI_COMMIT_REF_SLUG
      alias: playwright-bridge
  script:
    - composer install --dev
    - FLOW_CONTEXT=Production/E2E-SUT ./flow doctrine:migrate
    - FLOW_CONTEXT=Production/E2E-SUT ./flow cache:warmup
    - your-web-server-start-command &
    - export PLAYWRIGHT_API_URL=http://playwright-bridge:3000
    - export SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT=http://$(hostname -i):9090
    - ./bin/behat --format junit --out e2e-results -c Packages/Sites/Your.SitePackageKey/Tests/Behavior/behat.yml.dist
  artifacts:
    reports:
      junit: e2e-results/*.xml
```

Screenshots and traces go to the results directory (see [Debugging](#debugging)) - point it to the same place as
the JUnit `--out` if your CI keeps one artifact folder.

**Parallelising**: split the feature files across several CI jobs (GitLab `parallel:`), each with its **own** services
(database, Redis, mock server, Playwright bridge) - generate a Behat config per shard, ideally balanced by the feature
durations from the last JUnit report. Never run several Behat processes against one set of services: the database is
reset per scenario, mocks are reset per scenario, and runner and SUT share caches on purpose.

One GitLab-specific quirk worth knowing regardless of the example above: a job's *environment variables* are passed
to *all* its `services:` too — so DB/Redis credentials set for the main job are what the DB/Redis services
themselves also start with; there's no separate place to configure them.

# Writing Behat Tests

Feature files live in your site package (`Tests/Behavior/Features/`), step definitions come from the traits wired up
in your `FeatureContext` (see [Setup](#4-featurecontextphp)). **Working, commented Neos 9 examples for everything
below are in [`Tests/E2E/Features/`](Tests/E2E/README.md)** — the package's own E2E suite; copy one and adapt it.

What to test in a Neos project, where to test it, and the pitfalls specific to Neos, Flow and this package:
**[Neos E2E Testing Guide](NEOS_E2E_TESTING_GUIDE.md)**.

## Tags

- `@flowEntities` — resets the content repository before the scenario (prunes it, creates the live workspace and the
  `/sites` root) via your `FeatureContext`'s `@BeforeScenario @flowEntities` hook. Needed for every scenario that
  creates nodes.
- `@playwright` — starts a browser context in the playwright-bridge. Needed for page visits, backend steps and
  screenshots.
- `@wireMock` — resets WireMock to its base stubs before the scenario (`WireMockTrait`, see
  [Mocked third-party APIs](#mocked-third-party-apis-wiremock)).

Other tags in the guide's examples, like `@mailpit`, are project tags with a hook of your own.

## Fixtures

Create the site, then its nodes — as table, or from a YAML file with the same rows:

```gherkin
Given I have a site for Site Node "site" with name "YourSiteName"
And I have the following nodes in site "site":
  | NodeAggregateId | Parent        | NodeType                               | Properties                                   | DimensionSpacePoint |
  | homepage        |               | Your.SitePackageKey:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} | {"language":"de"}   |
  | section         | homepage/main | Your.SitePackageKey:Content.Section    | {}                                           | {"language":"de"}   |
  | headline        | section       | Your.SitePackageKey:Content.Headline   | {"title":"<h1>It works<\/h1>"}               | {"language":"de"}   |
```

- `Parent` empty: the site node itself (created with the node name given in `in site "..."`).
- `Parent` `homepage/main`: the tethered child node `main` of `homepage`; deeper paths like `homepage/main/foo` work
  too. `Parent` `section`: a plain child of a node created earlier, by its `NodeAggregateId`.
- `DimensionSpacePoint` must match your content dimensions — with a `language` dimension, every row needs one.
  There's no fallback: an empty cell is `{}`, which the content repository rejects. Set a default instead of repeating
  it (see [Default dimension space point](#default-dimension-space-point)).
- `Properties` is a JSON object; invalid JSON, unknown NodeTypes and properties the NodeType doesn't declare fail the
  step. Gherkin unescapes `\\` to `\` in table cells, so a JSON-escaped backslash (e.g. in a PHP class name) is
  written as `\\\\`.
- Respect NodeType `constraints` (see [Troubleshooting](#troubleshooting) item 2).
- Fixture steps bypass node and workspace permissions: the `Testing/Behat` context uses the `StaticAuthProviderFactory`
  (see `Configuration/Testing/Behat/Settings.yaml`). The system under test still applies your permissions, for example
  when you log in through backend steps in the browser.
- Optional `Hidden` column: `true` hides the node (and with it its descendants); empty, `false` or no column at all
  means visible.
- References: `And the following node references:` with columns `NodeAggregateId | ReferenceName | Targets |
  DimensionSpacePoint` (Targets comma-separated), optional `Properties` (JSON, set for every target of the row, typed
  by the reference's property declaration in the NodeType) — see
  [References.feature](Tests/E2E/Features/Fixtures/References.feature).
- YAML: `I have the following nodes from file "homepage.yaml" in site "site"` (optionally `... with overwrites:`),
  path relative to the feature file, format see
  [homepage.yaml](Tests/E2E/Features/Fixtures/homepage.yaml) — same fields as the table (`hidden: true` for the
  optional hidden flag), plus an optional `references:` list (`nodeAggregateId`, `referenceName`, `targets`,
  `dimensionSpacePoint`, optional `properties`). Invalid entries fail with their position in the file; nodes keyed by
  identifier (the old Neos 8 export) are rejected.
- Overwrite values (`nodeAggregateId | property | value`) are decoded when they are valid JSON (`true`, `42`, `null`,
  `"42"`, `[...]`, `{...}`) and used as text otherwise; an overwrite for a node that isn't in the file fails.
- Assets: `I have a textual persistent resource ...` and `I have the following images:` create file/image assets
  that node properties can reference — see
  [Download.feature](Tests/E2E/Features/PersistentResources/Download.feature). They're published right away;
  the Behat context must share the persistent resource storage/target with the SUT (Setup step 2).

### Default dimension space point

Set it with a step and leave out the `DimensionSpacePoint` column. It lasts until the end of the scenario - put it
into the Background, so every feature shows which dimension its fixtures are in:

```gherkin
Background:
  Given the default dimension space point is '{"language":"de"}'
```

Rows without a dimension space point get the default - in node and reference tables and YAML files - and so does
`I get the node "..."` without `in dimension`. Rows with their own keep it, so only the rows of another variant need
the column ("mixed mode"):

```gherkin
Given the default dimension space point is '{"language":"de"}'
And I have the following nodes in site "site":
  | NodeAggregateId | Parent        | NodeType                               | Properties                                  | DimensionSpacePoint |
  | homepage        |               | Your.SitePackageKey:Document.StartPage | {"uriPathSegment":"site","title":"Home"}    |                     |
  | section         | homepage/main | Your.SitePackageKey:Content.Section    | {}                                          |                     |
  | swiss-teaser    | section       | Your.SitePackageKey:Content.Teaser     | {"title":"Nur in der Schweiz"}              | {"language":"ch"}   |
```

A row of another dimension needs a parent that's visible there (e.g. `ch` specializing `de`) - the fixtures create
no variants. See [DefaultDimension.feature](Tests/E2E/Features/Fixtures/DefaultDimension.feature).

## Fixtures from existing content

**Why export?** Writing node tables by hand is what keeps most people from writing tests: every parent node, tethered
child, property format and NodeType constraint has to be right before the first assertion runs. Exporting a page that
editors actually built removes that barrier and makes feature files meaningful: real NodeType combinations, real texts,
the edge cases real content has. And since the export only writes what the current NodeTypes declare, the fixture fits
your code as it is today. Use the button when you're in the backend anyway, the CLI for scripts and coding agents.

All three ways produce the format above and export the same tree: the node's closest document with all its ancestors
and descendants, plus references between them, including the hidden state of explicitly hidden nodes and reference
properties. Tethered nodes and nodes of unknown NodeTypes are left out, as are properties the NodeType doesn't declare
(anymore). **Assets are not exported** — asset properties keep the asset id; create those assets in the scenario
(`I have the following images:` …). Every row keeps its dimension space point, even with a default set: an export works
in any scenario, with or without one.

- **Export Node button** (inspector, tab with the gear icon, group "Export"): downloads the YAML for the selected
  node, from the current workspace and dimension. **Administrators only** (`Configuration/Policy.yaml`): other
  backend users see the button disabled, and the endpoint answers them with 403. To allow other roles, grant them
  the privilege target `Sandstorm.E2ETestTools:NodeExport` in your project's `Policy.yaml` - button and endpoint
  both follow it.
- **CLI**: `./flow e2efixture:export <nodeAggregateId> --dimension '{"language":"de"}'` prints the YAML. Instead of
  the id, `--uri-path about/team` selects the page by its URI path (without dimension prefix and suffix, `/` is the
  homepage; `--source-site <siteNodeName>` only with several sites). `--format gherkin --site-name site` prints the
  inline steps instead; `--workspace` defaults to `live`.
- **StepGenerator** — for your own command controllers, when you want a different selection of nodes, or image
  fixture files (written to `withFixturesBaseDirectory()` and printed as `I have the following images:`):

  ```php
  public function homepageCommand(): void
  {
      $subgraph = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString('default'))
          ->getContentGraph(WorkspaceName::forLive())
          ->getSubgraph(DimensionSpacePoint::fromArray(['language' => 'de']), NeosVisibilityConstraints::excludeRemoved());
      $homepage = $subgraph->findNodeById(NodeAggregateId::fromString('...'));

      $nodeTable = $this->nodeTableBuilderService->nodeTable() // Sandstorm\E2ETestTools\StepGenerator\NodeTableBuilderService
          ->withFixturesBaseDirectory('Your.SitePackageKey', 'Tests/Behavior/Features/Homepage/Resources/')
          ->build($subgraph);
      $nodeTable->addParents($homepage);
      $nodeTable->addNode($homepage);
      $nodeTable->addChildNodesRecursively($homepage, '!Neos.Neos:Document'); // the homepage's content
      $nodeTable->addChildNodesRecursively($homepage, 'Neos.Neos:Document');  // other documents, so menus render
      $nodeTable->print('site'); // site node name for "... in site"
  }
  ```

  References are only printed for targets that are part of the table.

### Fixtures for AI agents

The CLI is the way for coding agents to get fixtures - no backend login, plain stdout, and the import creates exactly
what was exported. A workflow that works:

1. Build an example of the feature's content in the backend (a person or the agent) - the export can only export
   existing nodes.
2. Export by URL: `./flow e2efixture:export --uri-path <path> --dimension '<json>' > Features/<Feature>/<page>.yaml`.
   A wrong segment fails with the segments that exist at that level, so the path can be corrected without database
   access.
3. Prefer the YAML file over `--format gherkin`: a page easily has hundreds of nodes. Keep the feature short with
   `I have the following nodes from file ... in site ...` and set only what the scenario asserts via `with overwrites:`.
4. Assets aren't exported - add `I have the following images:` (or a textual persistent resource) for the asset ids
   the YAML references.
5. The exported ids are UUIDs. Rename ids only consistently (`nodeAggregateId`, `parent`, `targets` and `node://`
   links in properties) - or leave them.

Run the command in the Flow context with the content you export (e.g. inside your app container).

## Steps

| Trait | Steps |
|---|---|
| `FusionRenderingTrait` | `I have a site for Site Node :siteNodeName [with name :siteName]` · `I have/create the following nodes in site :siteName:` · `the following node references:` · `the default dimension space point is :dimensionSpacePoint` · `I get the node :nodeAggregateId [in dimension :dimensionSpacePoint]` · `I render the Fusion object :fusionPath:` · `I render the Fusion object :fusionPath with the current context node:` · `I render the page` · `the Fusion output should equal to :expected` · `in the fusion output, the inner HTML of CSS selector :selector matches :expected` · `in the fusion output, the attributes of CSS selector :selector are:` |
| `NodeImportTrait` | `I have/create the following nodes from file :fileName in site :siteName [with overwrites:]` |
| `PersistentResourceTrait` (via `FusionRenderingTrait`) | `I have a textual persistent resource :uuid named :filename with the following content:` · `I have the following images:` |
| `PlaywrightTrait` | `I do a screenshot :filename` · `I debug the playwright script` (prints the generated Playwright JS) |
| `NeosBackendControlTrait` | `I access the URI path :uriPath` · `the response status code should be :status` · `there should be the text :expected on the page` · `the URI path should be :uriPath` (waits for the navigation) · `I have a Neos backend user :username with password :password and role :role` · `I log into the backend using credentials :username :password [with username placeholder ... and password placeholder ...]` · `I click the main menu item :menuItem` · `I click the overview dashboard tile :tileTitle` · `I click the document tree entry :documentTitle` |
| `PageAssertionsTrait` | `the page title should be :title` · `there should not be the text :text on the page` · `there should (not) be the text :text in :selector` · `the element with test id :testId should be visible/hidden/focused/enabled/disabled` · `the element with test id :testId should (not) be in the viewport` |
| `FormInteractionTrait` | `I click the button :caption` · `I click the link :caption` · `I click the element with test id :testId` · `I fill :value into the field :label` · `the field :label should have the value :value` · `I check/uncheck the checkbox :label` · `the checkbox :label should (not) be checked` · `I choose the radio button :label` · `I select :option in the field :label` · `the field :label should have :option selected` · `I upload the file :fileName to the field :label` (relative to the feature file) · `I press the key :key` |
| `BrowserStateTrait` | `the cookie :name has the value :value` (before the first visit, e.g. consent) · `the local/session storage key :key has the value :value` · `the cookie :name should (not) be set` · `the cookie :name should have the value :value` · `I delete the cookie :name` · `the local/session storage key :key should have the value :value` · `the local/session storage key :key should not be set` · `I remove the local/session storage key :key` |
| `DebuggingTrait` | `I pause for debugging` (see [Debugging](#debugging)) |
| `WireMockTrait` | see [Mocked third-party APIs](#mocked-third-party-apis-wiremock) |

The traits in the last five rows are opt-in: `FeatureContext.php.default` uses all but `WireMockTrait`. Remove project
steps with the same wording before using them, otherwise Behat reports the steps as ambiguous. Buttons and links are
found by their accessible name, fields by their label, elements by `data-testid`.

Notes on the steps:

- `the Fusion output should equal to` compares the whole HTML - it breaks with every changed space or class. Prefer
  the CSS selector steps.
- `... the inner HTML of CSS selector ... matches ...` compares for equality, not as a regular expression.
- Escaped quotes (`\"`) inside a `"..."` step parameter don't match the step - put values that contain double quotes
  in single quotes (`'{"all":true}'`).
- Screenshots document a result, they don't check it.

Examples by level (when to use which: [testing guide](NEOS_E2E_TESTING_GUIDE.md#where-to-test)):

- **Component** (a Fusion prototype, like a pure function): `I render the Fusion object` without nodes —
  [FusionComponent/Button.feature](Tests/E2E/Features/FusionComponent/Button.feature).
- **Integration** (node → Fusion wiring): create nodes, `I get the node`, `... with the current context node` —
  [FusionIntegration/Button.feature](Tests/E2E/Features/FusionIntegration/Button.feature).
- **Page in the browser**: `I access the URI path` + assertions/screenshot —
  [PageRendering/Homepage.feature](Tests/E2E/Features/PageRendering/Homepage.feature).
- **Page via Fusion** (no request, no browser): `I render the page` —
  [PageRendering/FusionPage.feature](Tests/E2E/Features/PageRendering/FusionPage.feature).

Project-specific steps go into your `FeatureContext` or a trait of your project; the shipped traits in
`Classes/Behat/` (e.g. `DebuggingTrait`) show how to drive the browser with
`$this->playwrightConnector->execute()`.

## Dynamic SUT URL

The SUT base URL comes from `SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT`. When it has to change per scenario (e.g. content
dimensions resolved by host/subdomain, multi-site setups), call `setSystemUnderTestUrlModifier()` (from
`PlaywrightTrait`) in a custom step; the modifier is reset after each scenario:

```php
/**
 * @Given my subdomain is :subdomain
 */
public function mySubdomainIs(string $subdomain): void
{
    $this->setSystemUnderTestUrlModifier(fn (string $baseUrl) => sprintf('%s://%s.%s:%s%s',
        parse_url($baseUrl, PHP_URL_SCHEME),
        $subdomain,
        parse_url($baseUrl, PHP_URL_HOST),
        parse_url($baseUrl, PHP_URL_PORT),
        parse_url($baseUrl, PHP_URL_PATH),
    ));
}
```

## Mocked third-party APIs (WireMock)

When the site talks to an external API (shop backend, CRM, newsletter service), tests run against
[WireMock](https://wiremock.org) instead of the real API:

1. Run WireMock next to the app, locally and in CI (Docker image `wiremock/wiremock`), and point the system under
   test's E2E context to it (the API base URL setting of your project, e.g. `http://wiremock:8080/shop-api`).
2. Use `WireMockTrait` in your `FeatureContext` and configure the APIs in its constructor:

   ```php
   $this->setupWireMock(getenv('WIREMOCK_ADMIN_URL') ?: 'http://wiremock:8080', [
       'shop' => ['fixtures' => __DIR__ . '/../WireMock/shop', 'pathPrefix' => '/shop-api'],
   ]);
   ```
3. Tag features that use the mock with `@wireMock` - WireMock is reset before each of their scenarios.

Per API, the fixture directory holds `_base/*.json` (WireMock mapping files, loaded before every scenario), response
bodies for "serves response" and one directory per scenario ("tape") of mapping files:

| Step | |
|---|---|
| `the API :api path :path on :method serves response :file [with status :status]` | answers one call with a body file (`.json` may be omitted); wins over base stubs; a path with query string must match exactly |
| `I load the stubs :tape of the API :api` | imports all mapping files of the tape directory |
| `I clear all API stubs` | back to the base stubs, e.g. when the API's answer changes after an action |
| `the API :api should have received :method :path [:count times]` | asserts the outgoing call - when the call is the feature |

While writing a test, `'proxyBaseUrl' => 'https://real.api.example.com'` in the API config forwards unmatched requests
to the real API, so you see which calls happen - never in CI. The WireMock admin API (`/__admin/mappings`) lists the
active stubs.

# Running Behat Tests

1. Start the Playwright bridge on your machine and keep it running (e.g. all day):

   ```bash
   cd playwright-bridge && node index.js   # listens on localhost:3000; HEADLESS=false node index.js shows the browser
   ```

2. Make sure your application runs and the E2E database exists and is migrated (see
   [Two Flow Contexts, Two Ports](#2-two-flow-contexts-two-ports)).
3. Run Behat where your application runs (e.g. `docker compose exec neos ...`, `kubectl exec ...`, or locally):

   ```bash
   bin/behat -c Packages/Sites/Your.SitePackageKey/Tests/Behavior/behat.yml.dist
   ```

   Add a folder, a feature file or a `file:line` after the config to run only those scenarios:

   ```bash
   bin/behat -c Packages/Sites/Your.SitePackageKey/Tests/Behavior/behat.yml.dist Packages/Sites/Your.SitePackageKey/Tests/Behavior/Features/Fusion/
   bin/behat -c Packages/Sites/Your.SitePackageKey/Tests/Behavior/behat.yml.dist Packages/Sites/Your.SitePackageKey/Tests/Behavior/Features/WebsiteRendering.feature:27
   ```

IDE "run" buttons for Behat usually don't work when Behat runs inside a Docker container — use the CLI (or your
project's tasks, see [Project tasks](#6-project-tasks-recommended)).

## Debugging

- **Watch the tests**: start the bridge with `HEADLESS=false node index.js` to see the browser while writing or
  debugging a test - worth a project task next to the headless one used by CI and agents.
- **Screenshots**: add `And I do a screenshot "name.png"` to a `@playwright` scenario. When a step fails, an
  `error_*.png` screenshot is taken automatically. Both are written to the results directory — `e2e-results/` relative
  to where Behat runs, or whatever you pass to `setupPlaywright($resultsDir)` in your `FeatureContext` (e.g. read from
  your `Settings.yaml`).
- **Traces**: for failed `@playwright` scenarios, a Playwright trace `report_<feature>_<scenario>.zip` is written to the
  results directory. Open it with
  `npx playwright show-trace path/to/report_....zip` (e.g. after `npm install -g playwright`). To keep traces of passing
  scenarios too (or none), call `setPlaywrightTracingMode()` in your `FeatureContext`.
- **Server logs**: an exception in the site under test shows up in the browser only as an error page - the stack
  trace is in the logs. The Flow logs (`Data/Logs`) are cleared before every scenario, and a failed scenario gets its
  log files and exception files copied into `logs_<feature>_<line>_<scenario>/` in the results directory. When the
  site under test writes its logs somewhere else (another container), call
  `setFlowLogsDirectory('/path/as/seen/from/behat')` in your `FeatureContext`; `setFlowLogsDirectory(null)` switches
  clearing and copying off, for example when your development site shares `Data/Logs` and you need its logs.
- **Stop at the first failure**: `--stop-on-failure` stops the run at the first error, so you can inspect the E2E
  database and reproduce the bug manually.
- **Stack traces**: `-vvv` (extra verbose) prints the full exception stack trace.
- **Run only what you're debugging**: tag scenarios (e.g. `@debug`) and run `bin/behat ... --tags=debug`.
- **Pausing the browser**: `And I pause for debugging` (`DebuggingTrait`, calls Playwright's `page.pause()`) opens
  the Playwright inspector on the bridge side. It needs a bridge with a visible browser (`HEADLESS=false`) and Behat
  run with `PAUSE_FOR_DEBUGGING=true` - otherwise the connection to the playwright-bridge times out after 30 seconds,
  so the step fails right away without it. The site under test and its database keep the scenario's state, so you
  can click around and inspect it.
- **Let an agent analyse failures**: after a failed run, the results directory holds the error screenshots, traces,
  server logs and - with `--format junit --out <dir>` - the JUnit report. A coding agent like Claude can read them and
  propose a fix; tell it where the results directory is.

# Migrating tests from Neos 8

Start your `FeatureContext` from [`FeatureContext.php.default`](Templates/FeatureContext.php.default)
(Neos 9 Behat traits, content repository reset) instead of patching the old one. The traits moved from
`Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap\` to `Sandstorm\E2ETestTools\Behat\` (`Classes/Behat/`); drop the
`require_once` lines - Composer autoloads them. In the feature files:

| Neos 8 | Neos 9 |
|---|---|
| tag `@fixtures` | `@flowEntities`: Neos.Behat resets the database on this tag now (`FlowEntitiesTrait`; the old `FlowContextTrait` with `@fixtures` is deprecated) |
| `I have/create the following nodes:` | `I have/create the following nodes in site "site":` |
| columns `Identifier \| Path \| Node Type \| Properties \| Language` | `NodeAggregateId \| Parent \| NodeType \| Properties \| DimensionSpacePoint`, optional `Hidden` |
| `Path` (`/sites/site/main/foo`), a `/sites` row | `Parent`: empty = site node, `homepage/main` = tethered child, otherwise the parent's `NodeAggregateId`; no `/sites` row |
| `Language`: `de` | `DimensionSpacePoint`: `{"language":"de"}` |
| `HiddenInIndex` column | `"hiddenInMenu": true` in `Properties` |
| reference properties with node identifiers in `Properties` | `And the following node references:` |
| `I get a node by path "/sites/site" with the following context:` + table | `I get the node "homepage" in dimension '{"language":"de"}'` |
| `I have the following nodes from file "x.yaml" [with overwrites]` | `... from file "x.yaml" in site "site" [with overwrites:]` |
| overwrite columns `identifier \| property \| value` | `nodeAggregateId \| property \| value` |
| YAML exported with the Neos 8 button (nodes keyed by identifier, nested `children`) | rejected - export again ([Fixtures from existing content](#fixtures-from-existing-content)) |

Before:

```gherkin
@fixtures
Scenario:
  Given I have a site for Site Node "site"
  And I have the following nodes:
    | Identifier                           | Path                    | Node Type                 | Properties      | Language |
    | 5cb3a5f7-b501-40b2-b5a8-9de169ef1105 | /sites                  | unstructured              | {}              | de       |
    | 5e312d5b-9559-4bd2-8251-0182e11b4950 | /sites/site             | Your.SitePackageKey:Document.Page  | {}              | de       |
    | 9cbaa2e2-d779-4936-aa02-0dab324da93e | /sites/site/main/button | Your.SitePackageKey:Content.Button | {"title": "Go"} | de       |
  And I get a node by path "/sites/site/main/button" with the following context:
    | Workspace | Dimension: language |
    | live      | de                  |
```

After:

```gherkin
@flowEntities
Scenario:
  Given I have a site for Site Node "site"
  And I have the following nodes in site "site":
    | NodeAggregateId | Parent        | NodeType                  | Properties                                   | DimensionSpacePoint |
    | homepage        |               | Your.SitePackageKey:Document.Page  | {"uriPathSegment":"site","title":"Homepage"} | {"language":"de"}   |
    | button          | homepage/main | Your.SitePackageKey:Content.Button | {"title":"Go"}                               | {"language":"de"}   |
  And I get the node "button" in dimension '{"language":"de"}'
```

Details: [Fixtures](#fixtures).

# Troubleshooting

1. **The system-under-test serves the wrong content / wrong database, even though the response status is 200.**

   Cause: your web server sets the SUT's Flow context per-vhost, but the *container* (or host) also has a real,
   process-wide `FLOW_CONTEXT` env var — needed for normal CLI/`./flow` usage — and Flow's own
   `Bootstrap::getEnvironmentConfigurationSetting()` checks real `getenv()` *before* `$_SERVER`. The container-wide
   value always wins, silently, regardless of what your web server sets per-vhost.

   Verify by checking which context's cache/temp directory actually got populated for the request that hit the wrong
   content (e.g. `Data/Temporary/<Context>/<SubContext>/Cache/...` for a Flow-default setup) — if it's not the
   context you configured for that vhost, this is it.

   Fix: bridge `$_SERVER` → real env inside a PHP file that runs before every request (e.g. via `auto_prepend_file`
   in php.ini), so the per-vhost value takes precedence over the container-wide fallback:

   ```php
   if (isset($_SERVER['FLOW_CONTEXT'])) {
       putenv('FLOW_CONTEXT=' . $_SERVER['FLOW_CONTEXT']);
       $_ENV['FLOW_CONTEXT'] = $_SERVER['FLOW_CONTEXT'];
   }
   ```

   This applies to any web server that only sets per-request `$_SERVER`/CGI-style env vars rather than a real,
   process-wide one (Caddy/FrankenPHP's `env` directive behaves this way, for example) — the underlying
   `getenv()`-precedence behavior lives in Flow itself, not in any particular web server.

2. **`NodeConstraintException: Node type "..." is not allowed below tethered child nodes "..."` when creating
   fixtures.**

   Cause: the target NodeType's `constraints.nodeTypes` only allows one specific wrapper type directly under that
   tethered collection (commonly a "section"/"row" type) — content nodes nest inside *that*, not directly under the
   collection. Check the NodeType's `constraints` before picking a fixture node type.

3. **`FeatureContext.php` fatals with a missing class on boot, inherited from an older setup.**

   Cause: a `FeatureContext.php` written against an older version of this package references classes/traits that no
   longer exist. Compare against the *current* `Templates/FeatureContext.php.default` in this package
   and rewrite against that, rather than patching the old one forward.

4. **Content changes don't show up on the SUT after resetting the content repository.**

   Cause: a cache of the SUT (e.g. a full-page/HTTP response cache) isn't flushed between scenarios -
   `setupContentRepository()` flushes no caches - or it isn't on storage shared between the runner's context and the
   SUT's context, so a flush in the runner doesn't reach it.

   Fix: give the cache the same storage in both contexts' `Caches.yaml` (e.g. the same Redis database; for a file
   backend, `backendOptions.cacheDirectory` in `Testing/Behat` pointing to the SUT's cache directory), and flush it in
   PHP from a `@BeforeScenario` hook - not with a `./flow` command:

   ```php
   $this->getObject(CacheManager::class)->getCache('<YourCacheIdentifier>')->flush();
   ```

5. **Files/images created by fixture steps return 404 on the SUT.**

   Cause: the Behat context (`Testing/Behat`) stores and publishes persistent resources somewhere else than the SUT
   context, which shares the database but looks for the files in its own storage/target. Fix: use the SUT's storage
   and target in `Testing/Behat` — see [Setup step 2](#2-two-flow-contexts-two-ports).

# Developing this package

For contributors: how the package is tested and how it works inside.

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
  `Tests/Behat/FlowContextTrait`). A project package keeps steps for *its own* features in `Tests/Behavior/`, as this
  package does for its suite (`Tests/E2E/Features/Bootstrap/`).
- **Templates in `Templates/`, not `Resources/`:** `Resources/` is what Flow serves and reads at runtime
  (`resource://`, publishing). The templates are never used at runtime; they're copied into a project once and changed
  there.
- **Development distribution in `Tests/SystemUnderTest/`:** it exists only to run the tests. "System under test" is the
  term the package uses everywhere (Flow context `Production/E2E-SUT`, `SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT`).
- **`Tests/` stays out of Flow:** composer.json autoloads it (the tests run from the installed package), but
  `Neos.Flow.object.includeClasses` keeps Flow from reflecting it.

## Development distribution

The package brings its own Dockerised Neos distribution in `Tests/SystemUnderTest/`, so all its tests run without a
project:

- `Tests/SystemUnderTest/<version>/` — one distribution per supported Neos version (`neos9`), built from the shared
  `Dockerfile` and `docker-compose.base.yml`;
- `Tests/SystemUnderTest/neos-root/` — copied into the image: Caddy config, PHP ini and the Flow contexts
  `Production/E2E-SUT` (system under test, port 9090 in the container, `127.0.0.1:19090` on the host), `Testing/Behat`
  (Behat) and `Testing` (functional tests);
- `Tests/SystemUnderTest/DistributionPackages/Sandstorm.E2ETestTools.TestSite/` — the minimal site the E2E suite runs
  against;
- services: neos (SUT + Behat), maria-db, redis-cache, playwright-bridge (built from
  `Templates/playwright-bridge`), wiremock.

The package code is mounted into the container, so changes need no rebuild - only a changed `composer.json` or
`Dockerfile` does (`mise run build`).

```bash
mise trust              # once
mise run build          # build the images
mise run start          # start and wait until the system under test answers
mise run tests          # unit, functional and E2E tests
mise run tests:e2e --tags @playwright   # only some scenarios
mise run down           # remove containers and volumes
```

`SUT=<version>` selects another distribution. Screenshots, traces and the logs of failed scenarios land in
`Tests/SystemUnderTest/e2e-results/`. To watch the browser, start the bridge on the host (`HEADLESS=false node index.js` in
`Templates/playwright-bridge`) and start the distribution with
`PLAYWRIGHT_API_URL=http://host.docker.internal:3000 mise run start`.

GitHub Actions (`.github/workflows/tests.yml`) runs the same tasks for every distribution. Its image layers come from
the GitHub Actions cache (`Tests/SystemUnderTest/docker-compose.ci-cache.yml`), so the image is only rebuilt when the
`Dockerfile` or a `composer.json` changes - a newer Neos patch release arrives with such a change, or after deleting the
cache (repository → Actions → Caches).

## Unit and functional tests

The classes in `Classes/` are covered by PHPUnit tests in `Tests/Unit` and `Tests/Functional`: the fixture tooling
(YAML format, export/import contract, Gherkin escaping, node tree collection, URI path lookup, export endpoint
security), the WireMock admin client, script escaping (`JsValue`), the log copying (`LogDirectory`) and the guard of the
pause step. The Behat traits are covered by the E2E suite in [`Tests/E2E/Features/`](Tests/E2E/README.md).

## Architecture

```
   ╔╦══════════════════╦╗   1  ┌────────────────────┐
   ║│Behat Test Runner ├╬──────▶ Playwright Bridge  │
   ║└──────────────────┘║      │(Playwright Server -│
   ║ Application Docker ║      │  Chrome Browser)   │
   ║  Container (SUT)   ║◀─────┤                    │
   ╚══════════╦═════════╝   2  └────────────────────┘
              │
             3│
   ┌──────────▼─────────┐
   │other services (DB, │
   │    Redis, ...)     │
   └────────────────────┘
```

Behat runs inside the application container (or wherever the application runs), so it can use all application code
and has the same environment, database and library versions as the application itself.

1. Behat sends Playwright scripts to the **Playwright bridge** via HTTP. The bridge wraps Playwright (a browser
   orchestrator) and runs next to the application, e.g. on your machine or as CI service.
2. The browser calls the unmodified application - the **system under test (SUT)** - via HTTP.
3. The application uses its services (database, Redis, ...) as usual.

E2E tests need **full control over the database**, and you don't want them to wipe your development data. So they get
their own database - and with it their own Flow context and web server port:

| Context (example names) | Runs | Database | Reached via |
|---|---|---|---|
| your dev context, e.g. `Development/Docker` | your normal dev web server | dev database | e.g. port 8080 |
| SUT context, e.g. `Production/E2E-SUT` | the web server the browser tests hit | E2E database | e.g. port 9090 (`SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT`) |
| `Testing/Behat` | `bin/behat`: fixtures, Fusion rendering | E2E database | - (CLI) |

In CI, only the last two exist. The SUT context and `Testing/Behat` must agree on the database, on caches that are
flushed between scenarios and on the persistent resource storage - see
[Two Flow Contexts, Two Ports](#2-two-flow-contexts-two-ports). Making the second port really use the SUT context is
where [Troubleshooting item 1](#troubleshooting) tends to bite.

### Fixture tooling

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
- Both formats (YAML, Gherkin tables) live in one class each, writing and reading - format changes happen in one place
  and are pinned by round-trip tests (`Tests/Unit/Fixture`).
- Classes in `Classes/` hold the logic; the Behat traits only map steps to it.
