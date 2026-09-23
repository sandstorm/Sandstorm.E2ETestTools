# End-To-End Test Tools

... for Neos and Flow Projects.

**for SYMFONY projects, see [README.Symfony.md](./README.Symfony.md).**

We use [Playwright](https://playwright.dev) as browser orchestrator for tests involving a real browser. We use Behat as
the test framework for writing all kinds of BDD tests.

- a way to test Fusion code with Behat

- Utilities for integrating the [Playwright](https://playwright.dev) browser orchestrator and the Behat test framework

<!-- TOC -->

- [End-To-End Test Tools](#end-to-end-test-tools)
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
  - [Fixtures from existing content](#fixtures-from-existing-content)
  - [Steps](#steps)
  - [Style Guide](#style-guide)
  - [Dynamic SUT URL](#dynamic-sut-url)
  - [Sandstorm.NeosAcl](#sandstormneosacl)
- [Running Behat Tests](#running-behat-tests)
  - [Debugging](#debugging)
- [Troubleshooting](#troubleshooting)
- [Architecture](#architecture)

<!-- /TOC -->

# Setup

Setup is done by hand, once per project, in order — there's deliberately no setup command, since web server,
ports, Docker and CI differ from project to project. Steps 1–5 are required: skip step 2 and running Behat against
your normal dev database will delete its content.

## 1. Install the package

```bash
# Pulls in the traits (PlaywrightTrait, FusionRenderingTrait, NeosBackendControlTrait, ...)
# your FeatureContext.php will use below, plus this package's own Behat/Playwright glue code.
composer require sandstorm/e2etesttools @dev
```

## 2. Two Flow Contexts, Two Ports

E2E tests need full control over the database (creating/deleting nodes, resetting workspaces),
so they need their own database — which means their own Flow context. That context also needs
to be reachable over HTTP for Playwright, so it needs its own port too. Two contexts are involved:

- **`Testing/Behat`** — the context the Behat CLI process itself runs under. Copy your production
  `Settings.yaml` into `Configuration/Testing/Behat/Settings.yaml` for matching DB access.
- **Your SUT context** — whatever context actually serves the app for Playwright to hit (e.g.
  `Production/E2E-SUT`, `Development/Docker/Behat` — name it after your own environment). Needs a
  second entry point on its own port; how you configure that is up to your web server — see
  [Troubleshooting](#troubleshooting) for a gotcha that applies regardless of which one you use.

Both only need to override the database name — point `DB_NEOS_DATABASE_E2ETEST` at a separate
database from your normal one:

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

Caches the test runner must invalidate between scenarios (e.g. Fusion content cache) either need a backend shared by
both contexts (e.g. the same Redis database), or an explicit flush in the SUT's context — see
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
cp Packages/Application/Sandstorm.E2ETestTools/Tests/Behavior/Bootstrap/FeatureContext.php.default \
   DistributionPackages/Your.SitePackageKey/Tests/Behavior/Bootstrap/FeatureContext.php
```

The `require_once` paths at the top are relative to **your copy's own location**, not the
package's — 6 `../` to reach `Packages/Application/Sandstorm.E2ETestTools/...` assumes your file
sits at `DistributionPackages/<Your.PackageName>/Tests/Behavior/Bootstrap/FeatureContext.php`. A
different depth needs a different number of `../`. Then edit two
placeholders — the template ships with values that intentionally don't work, so it fails loudly
if left unedited rather than silently pointing at the wrong package/context:

- the site package key passed to `setupFusionRendering(...)`.
- **`$flowContextForSystemUnderTest`** — the context the *served* SUT actually runs under (step 2
  above), **not** this runner's own context. Used by `executeFlowCommand()` to shell commands
  into the SUT; leaving it at its default is a common source of confusing failures.

## 5. Playwright (playwright-bridge)

The Playwright↔Behat bridge (`index.js`) is deliberately **not** composer/npm-installed — it's
meant to be copied and adjusted per project:

```bash
cp -r Packages/Application/Sandstorm.E2ETestTools/Resources/Private/playwright-bridge-template ./playwright-bridge
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
    E2E_FLOW_CONTEXT: Production/E2E-SUT
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

`setupPlaywright()` writes screenshots/error-screenshots/trace zips to `e2e-results` by default.
It takes an optional `?string $resultsDir` param if you want that driven by your own project's
config instead (e.g. Neos: resolve it from `Settings.yaml` in your `FeatureContext`'s constructor
before calling `setupPlaywright($resultsDir)` — see this project's own consuming project for a
worked example). Keep it in sync with whatever `--out` path you pass to Behat above.

One GitLab-specific quirk worth knowing regardless of the example above: a job's *environment variables* are passed
to *all* its `services:` too — so DB/Redis credentials set for the main job are what the DB/Redis services
themselves also start with; there's no separate place to configure them.

# Writing Behat Tests

Feature files live in your site package (`Tests/Behavior/Features/`), step definitions come from the traits wired up
in your `FeatureContext` (see [Setup](#4-featurecontextphp)). **Working, commented Neos 9 examples for everything
below are in [`Tests/Behavior/Examples/`](Tests/Behavior/Examples/README.md)** — copy one and adapt it.

## Tags

- `@flowEntities` — resets the content repository before the scenario (prunes it, creates the live workspace and the
  `/sites` root) via your `FeatureContext`'s `@BeforeScenario @flowEntities` hook. Needed for every scenario that
  creates nodes.
- `@playwright` — starts a browser context in the playwright-bridge. Needed for page visits, backend steps,
  screenshots and the style guide.

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
- `DimensionSpacePoint` must match your content dimensions — with a `language` dimension, every row needs it.
- `Properties` is JSON; invalid JSON fails the step. Gherkin unescapes `\\` to `\` in table cells, so a JSON-escaped
  backslash (e.g. in a PHP class name) is written as `\\\\`.
- Respect NodeType `constraints` (see [Troubleshooting](#troubleshooting) item 2).
- References: `And the following node references:` with columns `NodeAggregateId | ReferenceName | Targets |
  DimensionSpacePoint` (Targets comma-separated) — see
  [References.feature](Tests/Behavior/Examples/Fixtures/References.feature).
- YAML: `I have the following nodes from file "homepage.yaml" in site "site"` (optionally `... with overwrites:`),
  path relative to the feature file, format see
  [homepage.yaml](Tests/Behavior/Examples/Fixtures/homepage.yaml) — same fields as the table, plus an optional
  `references:` list (`nodeAggregateId`, `referenceName`, `targets`, `dimensionSpacePoint`).
- Assets: `I have a textual persistent resource ...` and `I have the following images:` create file/image assets
  that node properties can reference — see
  [Download.feature](Tests/Behavior/Examples/PersistentResources/Download.feature). They're published right away;
  the Behat context must share the persistent resource storage/target with the SUT (Setup step 2).

## Fixtures from existing content

Instead of writing node tables by hand, build the content in the Neos backend and export it. All three ways produce
the format above and export the same tree: the node's closest document with all its ancestors and descendants, plus
references between them. Tethered nodes and nodes of unknown NodeTypes are left out, as are properties the NodeType
doesn't declare (anymore). **Assets are not exported** — asset properties keep the asset id; create those assets in
the scenario (`I have the following images:` …).

- **Export Node button** (inspector, tab with the gear icon, group "Export"): downloads the YAML for the selected
  node, from the current workspace and dimension. Backend users only (`Configuration/Policy.yaml`).
- **CLI**: `./flow e2efixture:export <nodeAggregateId> --dimension '{"language":"de"}'` prints the YAML;
  `--format gherkin --site-name site` prints the inline steps instead; `--workspace` defaults to `live`.
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

## Steps

| Trait | Steps |
|---|---|
| `FusionRenderingTrait` | `I have a site for Site Node :siteNodeName [with name :siteName]` · `I have/create the following nodes in site :siteName:` · `the following node references:` · `I get the node :nodeAggregateId [in dimension :dimensionSpacePoint]` · `I render the Fusion object :fusionPath:` · `I render the Fusion object :fusionPath with the current context node:` · `I render the page` · `the Fusion output should equal to :expected` · `in the fusion output, the inner HTML of CSS selector :selector matches :expected` · `in the fusion output, the attributes of CSS selector :selector are:` · `I store the Fusion output in the styleguide as :name [using viewport width :viewportWidth]` |
| `NodeImportTrait` | `I have/create the following nodes from file :fileName in site :siteName [with overwrites:]` |
| `PersistentResourceTrait` (via `FusionRenderingTrait`) | `I have a textual persistent resource :uuid named :filename with the following content:` · `I have the following images:` |
| `PlaywrightTrait` | `I do a screenshot :filename` · `I debug the playwright script` (prints the generated Playwright JS) |
| `NeosBackendControlTrait` | `I access the URI path :uriPath` · `the response status code should be :status` · `there should be the text :expected on the page` · `the URI path should be :uriPath` · `I have a Neos backend user :username with password :password and role :role` · `I log into the backend using credentials :username :password [with username placeholder ... and password placeholder ...]` · `I click the main menu item :menuItem` · `I click the overview dashboard tile :tileTitle` · `I click the document tree entry :documentTitle` |

What to test how:

- **Component** (a Fusion prototype, like a pure function): `I render the Fusion object` without nodes —
  [FusionComponent/Button.feature](Tests/Behavior/Examples/FusionComponent/Button.feature).
- **Integration** (node → Fusion wiring): create nodes, `I get the node`, `... with the current context node` —
  [FusionIntegration/Button.feature](Tests/Behavior/Examples/FusionIntegration/Button.feature).
- **Page in the browser**: `I access the URI path` + assertions/screenshot —
  [PageRendering/Homepage.feature](Tests/Behavior/Examples/PageRendering/Homepage.feature).
- **Page snapshot** (responsive, reproducible screenshots): `I render the page` + style guide —
  [PageRendering/FusionPageSnapshot.feature](Tests/Behavior/Examples/PageRendering/FusionPageSnapshot.feature).

Project-specific steps go into your `FeatureContext`; `Tests/Behavior/Bootstrap/FeatureContext.php.default` has a few
examples (`I pause for debugging`, `I should see the page title :title`) using `$this->playwrightConnector->execute()`.

## Style Guide

Every rendering can additionally be stored in a **style guide**: a static HTML page listing a screenshot of each stored
rendering, each linking to its HTML snapshot.

```gherkin
Then I store the Fusion output in the styleguide as "Button_Component_Primary"
Then I store the Fusion output in the styleguide as "Button_Component_Primary_Mobile" using viewport width "320"
```

- The feature must be annotated with `@playwright`, and the playwright-bridge must be running — it screenshots the
  stored HTML through the system under test (`SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT`).
- Output goes to `Web/styleguide/` (`<name>.html`, `<name>.png`, `index.html`). It's wiped at the start of each Behat
  run, and `index.html` is regenerated at the end; Behat prints the URL (e.g.
  [127.0.0.1:9090/styleguide/](http://127.0.0.1:9090/styleguide/)).
- The rendering is wrapped in `Sandstorm.E2ETestTools:StyleguidePage` (see `Resources/Private/Fusion/Root.fusion`),
  which ships **without CSS/JS**. Add your project's assets so screenshots look like the real site:

  ```neosfusion
  prototype(Sandstorm.E2ETestTools:StyleguideStylesheets) {
      main = Neos.Fusion:Tag {
          tagName = 'link'
          attributes.rel = 'stylesheet'
          attributes.href = Neos.Fusion:ResourceUri {
              path = 'resource://PACKAGEKEY/Public/main.css'
          }
      }
  }
  ```
- In CI, `Web/styleguide` can be kept as a job artifact (see the `.gitlab-ci.yml` in this package).

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

## Sandstorm.NeosAcl

add this to your Policy.yaml in the `Testing/Behat` context:

```yaml
roles:
  # this is necessary to allow the test runner to create fixtures when neos
  # acl package is installed
  'Neos.Flow:Everybody':
    privileges:
      - privilegeTarget: 'Sandstorm.NeosAcl:EditAllNodes'
        permission: GRANT
      - privilegeTarget: 'Sandstorm.NeosAcl:CreateAllNodes'
        permission: GRANT
      - privilegeTarget: 'Sandstorm.NeosAcl:RemoveAllNodes'
        permission: GRANT
```

# Running Behat Tests

> This is MANDATORY to read for everybody.
> We suggest that this section is COPIED to the readme of your project.

First, you need to start the **Playwright Server** on your development machine. For that, go to `playwright-bridge`
in your Git Repo, and do:

```bash
npm install
node index.js
# now, the server is running on localhost:3000.
# Keep the server running as long as you want to execute Behavioral Tests. You can leave the server
# running for a very long time (e.g. a day).
```

Second, **ensure your application is running** in whatever way your project does that (Docker Compose, a local PHP
server, Kubernetes, ...). Then, enter your application's container (however your project runs one — `docker compose
exec`, `kubectl exec`, or none at all if running locally) and run the following commands:

```bash
bin/behat -c Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/behat.yml.dist
```

(Make sure the E2E database exists and is migrated, see
[Two Flow Contexts, Two Ports](#2-two-flow-contexts-two-ports).)

Behat also supports running single tests or single files - they need to be specified after the config file, e.g.

```bash

# run all scenarios in a given folder
bin/behat -c Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/behat.yml.dist Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/Features/Fusion/

# run all scenarios in the single feature file
bin/behat -c Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/behat.yml.dist Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/Features/WebsiteRendering.feature

# run the scenario starting at line 27
bin/behat -c Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/behat.yml.dist Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/Features/WebsiteRendering.feature:27
```

IDE "run" buttons for Behat usually don't work when Behat runs inside a Docker container — use the CLI (or your
project's tasks, see [Project tasks](#6-project-tasks-recommended)).

## Debugging

- **Screenshots**: add `And I do a screenshot "name.png"` to a `@playwright` scenario. When a step fails, an
  `error_*.png` screenshot is taken automatically. Both are written to the results directory — `e2e-results/` relative
  to where Behat runs, or whatever you pass to `setupPlaywright($resultsDir)`.
- **Traces**: for failed `@playwright` scenarios, a Playwright trace `report_<feature>_<scenario>.zip` is written to the
  results directory. Open it with
  `npx playwright show-trace path/to/report_....zip` (e.g. after `npm install -g playwright`). To keep traces of passing
  scenarios too (or none), call `setPlaywrightTracingMode()` in your `FeatureContext`.
- **Stop at the first failure**: `--stop-on-failure` stops the run at the first error, so you can inspect the E2E
  database and reproduce the bug manually.
- **Stack traces**: `-vvv` (extra verbose) prints the full exception stack trace.
- **Run only what you're debugging**: tag scenarios (e.g. `@debug`) and run `bin/behat ... --tags=debug`.
- **Pausing the browser**: `And I pause for debugging` (from `FeatureContext.php.default`, calls Playwright's
  `page.pause()`) opens the Playwright inspector on the bridge side. Run Behat with `PAUSE_FOR_DEBUGGING=true` —
  otherwise the connection to the playwright-bridge times out after 30 seconds.

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
   longer exist. Compare against the *current* `Tests/Behavior/Bootstrap/FeatureContext.php.default` in this package
   and rewrite against that, rather than patching the old one forward.

4. **Content changes don't show up on the SUT after resetting the content repository.**

   Cause: a project-specific cache (e.g. a full-page/HTTP response cache) isn't covered by
   `setupContentRepository()`'s generic cache flush, and/or isn't on a cache backend shared between the runner's
   context and the SUT's context.

   Fix: flush it explicitly, in the SUT's own context, from a `@BeforeScenario` hook:

   ```php
   exec("FLOW_CONTEXT=$this->flowContextForSystemUnderTest ./flow cache:flushone <YourCacheIdentifier>");
   ```

   unless it's already on a backend shared between both contexts (e.g. the same Redis database), in which case an
   in-process flush from the runner reaches it directly.

5. **Files/images created by fixture steps return 404 on the SUT.**

   Cause: the Behat context (`Testing/Behat`) stores and publishes persistent resources somewhere else than the SUT
   context, which shares the database but looks for the files in its own storage/target. Fix: use the SUT's storage
   and target in `Testing/Behat` — see [Setup step 2](#2-two-flow-contexts-two-ports).

# Architecture

> We suggest to skim this part roughly to get an overview of the general architecture.
> As long as you do not do in-depth modifications, you do not need to read it in detail.

The architecture for running behavioral tests is as follows:

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

1) We add the Behat test runner to the Development or Production App Docker Container (SUT - System under Test), so that
   the Behat test runner can access any code from the application, and has the exact same environment, database, and
   library versions like the production application.

2) The Playwright Bridge wraps Playwright (which is a browser orchestrator) and exposes a HTTP API. It is running as
   associated service. Behat communicates to the test runner via HTTP (1).

3) Then, the bridge calls the unmodified application via HTTP (2).

4) The application then calls other services like Redis and the database - just as usual.

There is one catch with big implications, though: **The E2E tests need full control over the database** to work
reliably. As we do not want to clear our development database each time we run our tests, we need to **use two
databases**: one for Testing, and the other one for Development.

Additionally, the E2E tests need to reach the system wired to the *testing environment* through HTTP. This means we
need **two web server ports** as well: One for development, and one for the testing context.

This setup is somewhat complicated; so the following image helps to illustrate how the different contexts interact
**during development time and during production/CI**. The context names below (`Development/Docker`,
`Production/Kubernetes`, ...) are examples — name yours after your own environments; see
[Two Flow Contexts, Two Ports](#2-two-flow-contexts-two-ports). Wiring the second port to the right context is exactly
where [Troubleshooting item 1](#troubleshooting) tends to bite:

```

                               Main Development Web                                              Behat CLI
                               Server (usually port         Web Server used by                  (bin/behat)
                                      8080)                Behat Tests (usually                        │
                                                                Port 9090)                             │
                                         │                                                             │
                                         │                           ┌────────────────┐                │
                                         │                           │                │                │
                                         │                           ▼                │                ▼
                                         │       ╔═════════════════════════╗        ╔══════════════════════════╗
    ######  ####### #     #              │       ║Development/Docker/Behat ║        ║  Testing/Behat Context   ║
    #     # #       #     #              │       ║         Context         ║        ║                          ║
    #     # #       #     #              │       ║                         ║        ║ behat tests executed as  ║
    #     # #####   #     #              │       ║   only overrides the    ║        ║ this context; so config  ║
    #     # #        #   #               │       ║      database name      ║        ║       should match       ║
    #     # #         # #                │       ╚═════════════════════════╝        ║ Development/Docker/Behat ║
    ######  #######    #                 │                                          ║                          ║
                                         ▼                                          ║                          ║
                                        ╔══════════════════════════════════╗        ║                          ║
                                        ║    Development/Docker Context    ║        ║                          ║
                                        ║                                  ║        ║                          ║
                                        ║ contains the main configuration  ║        ║                          ║
                                        ║             for DEV              ║        ║                          ║
                                        ╚══════════════════════════════════╝        ╚══════════════════════════╝




                               Main Production Web                                              Behat CLI
                               Server (usually port         Web Server used by                  (bin/behat)
                                      8080)                Behat Tests (usually                        │
                                                                Port 9090)                             │
                                         │                                                             │
                                         │                           ┌────────────────┐                │
                                         │                           │                │                │
          #####  ###                     │                           ▼                │                ▼
         #     #  #                      │       ╔═════════════════════════╗        ╔══════════════════════════╗
         #        #                      │       ║Production/Kubernetes/Beh║        ║  Testing/Behat Context   ║
         #        #                      │       ║       at Context        ║        ║                          ║
         #        #                      │       ║                         ║        ║ behat tests executed as  ║
         #     #  #                      │       ║   only overrides the    ║        ║ this context; so config  ║
          #####  ###                     │       ║      database name      ║        ║       should match       ║
                                         │       ╚═════════════════════════╝        ║Development/Kubernetes/Beh║
                                         │                                          ║            at            ║
                                         ▼                                          ║                          ║
                                        ╔══════════════════════════════════╗        ║                          ║
                                        ║  Development/Kubernetes Context  ║        ║                          ║
                                        ║                                  ║        ║                          ║
                                        ║ contains the main configuration  ║        ║                          ║
                                        ║             for PROD             ║        ║                          ║
                                        ╚══════════════════════════════════╝        ╚══════════════════════════╝
```
