# End-To-End Test Tools for Neos

Behat steps and glue code for testing Neos 9 sites at three levels: Fusion rendering in-process, full pages in a real
browser via Playwright, and the Neos backend. Tests are written in Gherkin and run against a content repository that
is reset and filled with fixtures for every scenario.

```gherkin
@flowEntities
Feature: Headline integration

  Scenario: a headline node renders as h1
    Given I have a site for the site node "site" with name "My Site"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                   | Properties                                   | DimensionSpacePoint |
      | homepage        |               | My.Site:Document.StartPage | {"uriPathSegment":"home","title":"Homepage"} | {"language":"de"}   |
      | headline        | homepage/main | My.Site:Content.Headline   | {"title":"Hello"}                            | {"language":"de"}   |
    And I get the node "headline" in dimension '{"language":"de"}'
    When I render the Fusion object "/testcase" with the current context node:
      """
      testcase = My.Site:Content.Headline
      """
    Then the inner HTML of "h1" in the Fusion output should be "Hello"
```

## What you get

- **Fusion rendering in the Behat process** - render a component or a NodeType's integration and assert on the HTML
  via CSS selectors, without web server or browser.
- **Browser tests via Playwright** - Behat sends scripts to a small bridge service; the browser hits your application
  on its own port, Flow context and database.
- **Neos backend steps** - create users, log in, use the menu, the dashboard and the document tree.
- **Generic browser steps** - texts, titles, buttons, links, form fields, focus and keyboard, screen size and
  scrolling, cookies and web storage (e.g. a preset cookie consent), requests to third parties, sent mails (Mailpit),
  and a smoke test per page (JavaScript errors, failed requests, images).
- **Content fixtures** - nodes, references, hidden state and assets as Gherkin tables or YAML files, created through
  the content repository API.
- **Fixture export** - turn existing pages into fixtures (backend button or CLI) instead of writing tables by hand.
- **Mocked third-party APIs** - WireMock stubs per scenario and checks of the calls your site made.
- **Debugging and CI** - screenshots, traces and server logs of failed scenarios, a pause step and a CI example.

Requires Neos 9.1+ / Flow 9.1+. For Neos 8, use the 8.x releases (latest: 8.3.2). Developing the package itself:
see [CONTRIBUTING.md](CONTRIBUTING.md).

<!-- TOC -->

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
    - [Writing fixtures by hand](#writing-fixtures-by-hand)
    - [Exporting existing content](#exporting-existing-content)
  - [Steps](#steps)
  - [Dynamic SUT URL](#dynamic-sut-url)
  - [Mocked third-party APIs (WireMock)](#mocked-third-party-apis-wiremock)
- [Running Behat Tests](#running-behat-tests)
  - [Debugging](#debugging)
- [Migrating tests from Neos 8](#migrating-tests-from-neos-8)
- [Upgrading the steps](#upgrading-the-steps)
- [Upgrading the playwright-bridge](#upgrading-the-playwright-bridge)
- [Troubleshooting](#troubleshooting)

<!-- /TOC -->

# Setup

Setup is done by hand, once per project, in this order - web server, ports, Docker and CI differ too much between
projects for a setup command. Steps 1–5 are required; without step 2, Behat empties your development database.

## 1. Install the package

```bash
composer require --dev sandstorm/e2etesttools
```

## 2. Two Flow Contexts, Two Ports

E2E tests delete and create content for every scenario, so they need their own database - and with it their own Flow
context, and a web server port of their own for the browser:

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

Behat runs where the application runs, with the same code, environment and services. (1) It sends Playwright scripts to
the bridge via HTTP, (2) the browser calls the unmodified application - the **system under test (SUT)** - and (3) the
application uses its services as usual.

| Context (example names) | Runs | Database | Reached via |
|---|---|---|---|
| your dev context, e.g. `Development/Docker` | your normal dev web server | dev database | e.g. port 8080 |
| SUT context, e.g. `Production/E2E-SUT` | the web server the browser tests hit | E2E database | e.g. port 9090 (`SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT`) |
| `Testing/Behat` | `bin/behat`: fixtures, Fusion rendering | E2E database | - (CLI) |

In CI, only the last two exist. How the SUT context gets its own port depends on your web server - mind
[Troubleshooting item 1](#troubleshooting). Neos.Behat only runs in `Testing/Behat` (or a sub context like
`Testing/Behat/Docker`), so `Configuration/Testing/Behat/Settings.yaml` gets the SUT's database, cache and resource
settings.

Both contexts point to the separate database, e.g. via `DB_NEOS_DATABASE_E2ETEST`:

```yaml
Neos:
  Flow:
    persistence:
      backendOptions:
        dbname: '%env:DB_NEOS_DATABASE_E2ETEST%'
```

Create it once, then migrate it in the SUT context (again after pulling new migrations):

```bash
mysql -e 'CREATE DATABASE IF NOT EXISTS neos_e2etest'   # or your DB's equivalent
FLOW_CONTEXT=Production/E2E-SUT ./flow doctrine:migrate
```

Caches that Behat flushes between scenarios (e.g. the Fusion content cache) need the same storage in both contexts
(e.g. the same Redis database) and the same cache prefix - Flow's default contains the context name:

```yaml
Neos:
  Flow:
    cache:
      applicationIdentifier: 'app'
```

Asset fixtures (`I have a textual persistent resource ...`, `I have the following images:`) need the SUT's persistent
resource storage and target - Flow's `Testing` defaults use separate ones, and the SUT would answer 404. In
`Configuration/Testing/Behat/Settings.yaml` (values as in your SUT context):

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

Create `DistributionPackages/Your.SitePackageKey/Tests/Behavior/behat.yml.dist`:

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

`FeatureContext` is the class Behat calls for every step. Copy the template, which wires up the traits:

```bash
cp Packages/Application/Sandstorm.E2ETestTools/Templates/FeatureContext.php.default \
   DistributionPackages/Your.SitePackageKey/Tests/Behavior/Features/Bootstrap/FeatureContext.php
```

Then replace the placeholder passed to `setupFusionRendering(...)` with your site package key - it fails loudly
otherwise. The traits (`Sandstorm\E2ETestTools\Behat`) are autoloaded by Composer.

## 5. Playwright (playwright-bridge)

The bridge between Behat and Playwright is a small Node service you copy into your project and adjust there - we put
it at the root of the Git repository, next to the Neos root directory:

```bash
cp -r Packages/Application/Sandstorm.E2ETestTools/Templates/playwright-bridge ./playwright-bridge
cd playwright-bridge && npm ci && npx playwright install chromium && cd ..
```

It uses Playwright 1.64 and needs Node 20 or newer (`.nvmrc`); its `Dockerfile` (for CI) is based on the Playwright
image of the same version. Keep the two in sync when you update Playwright - the image brings the browsers. The scripts
the steps send get `context` (the scenario's
[BrowserContext](https://playwright.dev/docs/api/class-browsercontext)), `vars` (kept between the steps of a
scenario), `expect` ([assertions](https://playwright.dev/docs/test-assertions)), `browser`, `contextName` and `fs`.

It runs on your host (it drives a real browser), Behat usually in your app container. `setupPlaywright()` reads two
environment variables, e.g. from your `docker-compose.yml`:

```yaml
environment:
  # where Behat reaches the bridge (from inside a container: the host)
  PLAYWRIGHT_API_URL: 'http://host.docker.internal:3000'
  # where the browser (on the host) reaches the SUT port from step 2
  SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT: 'http://127.0.0.1:9090'
```

## 6. Project tasks (recommended)

Wrap the recurring commands in your task runner (mise, make, npm scripts, ...). The Neos-on-Docker kickstart, for
example, has `mise run tests:e2e:start-bridge` and `mise run tests:e2e [path]`, which roughly does:

```bash
docker compose exec maria-db /createTestingDB.sh
docker compose exec neos bash -c "FLOW_CONTEXT=Production/E2E-SUT ./flow doctrine:migrate"
docker compose exec -e FLOW_CONTEXT=Testing/Behat neos bin/behat -c DistributionPackages/Your.SitePackageKey/Tests/Behavior/behat.yml.dist $1
```

## 7. CI Pipeline (optional)

Run the E2E job **inside the image you deploy** (build once, test that artifact), with a database, Redis and the
Playwright bridge as services (build the bridge's image from its `Dockerfile`). A CI job only serves the SUT context,
so set `FLOW_CONTEXT` to it as job variable - the routing problem of [Troubleshooting item 1](#troubleshooting) doesn't
occur. Production images are usually lean, so the job may have to:

- install the dev dependencies (`composer install --dev`) when the image is built with `--no-dev`;
- copy in the web server config of the SUT port when only a local-dev image layer has it.

Then migrate and warm up the SUT, start the web server in the background, point the two Playwright variables to your
CI's hostnames and run Behat in `Testing/Behat`. A JUnit report (`--format junit --out <dir>`) shows results per
scenario. With GitLab CI:

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
    - FLOW_CONTEXT=Testing/Behat ./bin/behat --format junit --out e2e-results -c DistributionPackages/Your.SitePackageKey/Tests/Behavior/behat.yml.dist
  artifacts:
    reports:
      junit: e2e-results/*.xml
```

GitLab passes the job's variables to all its services too - the DB and Redis services start with the same credentials.
Point the results directory ([Debugging](#debugging)) to the JUnit `--out` folder to keep one artifact folder.

**Parallelising**: split the feature files across several jobs (GitLab `parallel:`), ideally balanced by the durations
of the last JUnit report, each with its **own** services. Never run several Behat processes against one set of
services - database and mocks are reset per scenario, and runner and SUT share caches on purpose.

# Writing Behat Tests

Feature files live in your site package (`Tests/Behavior/Features/`). **Working, commented examples for everything
below are in [`Tests/E2E/Features/`](Tests/E2E/README.md)** - the package's own suite; copy one and adapt it. What to
test in a Neos project, where, and the pitfalls: **[Neos E2E Testing Guide](E2E_TESTING_GUIDE.md)**.

## Tags

- `@flowEntities` - resets the content repository before the scenario (prunes it, creates the live workspace and the
  `/sites` root) via your `FeatureContext`'s hook. Needed for every scenario that creates nodes.
- `@playwright` - starts a browser context in the bridge. Needed for page visits, backend steps and screenshots.
- `@wireMock` - resets WireMock to its base stubs ([Mocked third-party APIs](#mocked-third-party-apis-wiremock)).
- `@mailpit` - empties the Mailpit inbox ([MailpitTrait](#mailpittrait)).

Other tags in the guide are project tags with a hook of your own.

## Fixtures

Every scenario creates its content: write the nodes as Gherkin table or YAML file, or export existing pages into the
same format.

### Writing fixtures by hand

Create the site, then its nodes - as table, or from a YAML file with the same rows:

```gherkin
Given I have a site for the site node "site" with name "YourSiteName"
And I have the following nodes in site "site":
  | NodeAggregateId | Parent        | NodeType                               | Properties                                   | DimensionSpacePoint |
  | homepage        |               | Your.SitePackageKey:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} | {"language":"de"}   |
  | section         | homepage/main | Your.SitePackageKey:Content.Section    | {}                                           | {"language":"de"}   |
  | headline        | section       | Your.SitePackageKey:Content.Headline   | {"title":"<h1>It works<\/h1>"}               | {"language":"de"}   |
```

- `Parent`: empty is the site node (named as in `in site "..."`); `homepage/main` is the tethered child `main` of
  `homepage` (deeper paths work too); otherwise the `NodeAggregateId` of a node created earlier.
- `DimensionSpacePoint` must match your content dimensions - with a `language` dimension, every row needs one, either
  in the column or as [default](#default-dimension-space-point). An empty cell without default is `{}`, which the
  content repository rejects.
- `Properties` is a JSON object; invalid JSON, unknown NodeTypes and undeclared properties fail the step. Gherkin
  unescapes `\\` to `\` in table cells, so a JSON-escaped backslash (e.g. in a PHP class name) is written as `\\\\`.
- Optional `Hidden` column: `true` hides the node and its descendants.
- Mind NodeType `constraints` ([Troubleshooting item 2](#troubleshooting)).
- Fixture steps bypass node and workspace permissions (`StaticAuthProviderFactory` in `Testing/Behat`); the SUT still
  applies yours, e.g. when you log in through the backend steps.
- References: `And the following node references:` with columns `NodeAggregateId | ReferenceName | Targets |
  DimensionSpacePoint` (Targets comma-separated) and optional `Properties` (JSON, for every target of the row) - see
  [References.feature](Tests/E2E/Features/Fixtures/References.feature).
- YAML: `I have the following nodes from file "homepage.yaml" in site "site"`, path relative to the feature file, same
  fields as the table plus an optional `references:` list - see [homepage.yaml](Tests/E2E/Features/Fixtures/homepage.yaml).
  Invalid entries fail with their position in the file; the old Neos 8 export format is rejected.
- `... with overwrites:` (`nodeAggregateId | property | value`) changes single properties of the file. Values that are
  valid JSON (`true`, `42`, `null`, `"42"`, `[...]`, `{...}`) are decoded, others used as text; an overwrite for a node
  that isn't in the file fails.
- Assets: `I have a textual persistent resource ...` and `I have the following images:` create file and image assets
  for node properties - see [Download.feature](Tests/E2E/Features/PersistentResources/Download.feature). They're
  published right away (resource settings: [Setup step 2](#2-two-flow-contexts-two-ports)).

#### Default dimension space point

Set it in the Background and leave out the `DimensionSpacePoint` column - every feature then shows which dimension its
fixtures are in:

```gherkin
Background:
  Given the default dimension space point is '{"language":"de"}'
```

It lasts until the end of the scenario and applies to rows without a dimension space point (node and reference tables,
YAML files) and to `I get the node "..."` without `in dimension`. Rows with their own keep it, so only the rows of
another variant need the column ("mixed mode"):

```gherkin
Given the default dimension space point is '{"language":"de"}'
And I have the following nodes in site "site":
  | NodeAggregateId | Parent        | NodeType                               | Properties                                  | DimensionSpacePoint |
  | homepage        |               | Your.SitePackageKey:Document.StartPage | {"uriPathSegment":"site","title":"Home"}    |                     |
  | section         | homepage/main | Your.SitePackageKey:Content.Section    | {}                                          |                     |
  | swiss-teaser    | section       | Your.SitePackageKey:Content.Teaser     | {"title":"Nur in der Schweiz"}              | {"language":"ch"}   |
```

A row of another dimension needs a parent that's visible there (e.g. `ch` specializing `de`) - the fixtures create no
variants. See [DefaultDimension.feature](Tests/E2E/Features/Fixtures/DefaultDimension.feature).

### Exporting existing content

Writing node tables by hand keeps most people from writing tests: every parent, tethered child, property format and
constraint has to be right before the first assertion. Exporting a page editors built gives real NodeType
combinations, texts and edge cases - and fits your current NodeTypes, since only declared properties are exported.

Button and CLI export the same tree in the format above: the node's closest document with all its ancestors and
descendants, references between them, the hidden state and reference properties. Tethered nodes, unknown NodeTypes and
undeclared properties are left out, and every row keeps its dimension space point. **Assets are not exported** - asset
properties keep the asset id; create those assets in the scenario.

- **Export Node button** (inspector, tab with the gear icon, group "Export"): downloads the YAML for the selected node
  from the current workspace and dimension. **Administrators only** - other users see it disabled, and the endpoint
  answers 403. To allow other roles, grant them the privilege target `Sandstorm.E2ETestTools:NodeExport` in your
  `Policy.yaml`.
- **CLI**: `./flow e2efixture:export <nodeAggregateId> --dimension '{"language":"de"}'` prints the YAML; run it in the
  Flow context with the content (e.g. inside your app container). Instead of the id, `--uri-path about/team` selects
  the page by its URI path (without dimension prefix and suffix, `/` is the homepage; `--source-site <siteNodeName>`
  with several sites) - a wrong segment fails with the segments that exist at that level.
  `--format gherkin --site-name site` prints the steps instead; `--workspace` defaults to `live`,
  `--content-repository` to `default`.

Prefer the YAML file over inline steps - a page easily has hundreds of nodes - and set only what the scenario asserts
via `with overwrites:`. The exported ids are UUIDs; rename them only consistently (`nodeAggregateId`, `parent`,
`targets` and `node://` links in properties) or leave them.

The CLI also suits coding agents: no backend login, plain stdout, and the import creates exactly what was exported -
build an example page in the backend, export it by URI path into the feature's folder, add the assets it references.

## Steps

All traits are in `Sandstorm\E2ETestTools\Behat`. `FeatureContext.php.default` uses all but `WireMockTrait` and
`MailpitTrait` (they need a WireMock or Mailpit service); the browser traits need `PlaywrightTrait`, but not each other -
use the ones you need. Remove project steps with the same wording before using them, otherwise Behat reports the steps
as ambiguous. Elements are found by their role and accessible name (`the button "Send"`, `the link "Next page"` -
:role is an [ARIA role](https://www.w3.org/TR/wai-aria-1.2/#role_definitions)), fields by their label, otherwise by
`data-testid`.

### FusionRenderingTrait

- `I have a site for the site node :siteNodeName [with name :siteName]`
- `I have/create the following nodes in site :siteName:`
- `the following node references:`
- `the default dimension space point is :dimensionSpacePoint`
- `I get the node :nodeAggregateId [in dimension :dimensionSpacePoint]`
- `I render the Fusion object :fusionPath:`
- `I render the Fusion object :fusionPath with the current context node:`
- `I render the page`
- `the Fusion output should be :expected`
- `the inner HTML of :selector in the Fusion output should be :expected`
- `the attributes of :selector in the Fusion output should be:`

### NodeImportTrait

- `I have/create the following nodes from file :fileName in site :siteName [with overwrites:]`

### PersistentResourceTrait

Comes with `FusionRenderingTrait`.

- `I have a textual persistent resource :uuid named :filename with the following content:`
- `I have the following images:`

### PlaywrightTrait

- `I take a screenshot :filename`
- `I debug the Playwright script` - prints the generated Playwright JS

### NavigationTrait

- `I access the URI path :uriPath` - opens a new page
- `I reload the page`
- `the URI path should be :uriPath` - waits for the navigation
- `the response status code should be :status`
- `the viewport is :width x :height px` - reloads the page
- `the screen is :width x :height px with pixel ratio :ratio` - clears the browser cache and reloads; Chromium only
- `I scroll to the bottom of the page`
- `I scroll up by :pixels px`
- `the page should be scrolled to the top`

### NeosBackendControlTrait

- `I have a Neos backend user :username with password :password and role :role`
- `I log into the backend using credentials :username :password [with username placeholder ... and password placeholder ...]`
- `I click the main menu item :menuItem`
- `I click the overview dashboard tile :tileTitle`
- `I click the document tree entry :documentTitle`

### PageAssertionsTrait

- `the page title should be :title`
- `there should (not) be the text :text on the page` - rendered text: hidden text counts as absent
- `there should (not) be the text :text in :selector`
- `the :role :name should be focused` - fails with the focused element, or if the element is hidden from assistive
  technology
- `the focus should be inside :selector`
- `the :role :name should (not) be in the viewport`
- `the :role :name should be visible/hidden/enabled/disabled` - `hidden` also finds elements hidden from assistive
  technology, and passes if there is no such element
- `the element with test id :testId should be visible/hidden/focused/enabled/disabled`
- `the element with test id :testId should (not) be in the viewport`

### FormInteractionTrait

- `I click the button :name`
- `I click the link :name`
- `I click the element with test id :testId`
- `I fill :value into the field :label`
- `the field :label should have the value :value`
- `I check/uncheck the checkbox :label`
- `the checkbox :label should (not) be checked`
- `I choose the radio button :label`
- `I select :option in the field :label`
- `the field :label should have :option selected`
- `the field :label should be invalid` - the browser's validation (`required`, `type="email"`, ...)
- `I upload the file :fileName to the field :label` - relative to the feature file
- `I press the key :key`
- `I focus the :role :name` - like reaching it with Tab

### BrowserStateTrait

- `the cookie :name has the value :value` - before the first visit, e.g. consent
- `the local/session storage key :key has the value :value`
- `the cookie :name should (not) be set`
- `the cookie :name should have the value :value`
- `I delete the cookie :name`
- `the local/session storage key :key should have the value :value`
- `the local/session storage key :key should not be set`
- `I delete the local/session storage key :key`

### NetworkTrait

- `requests to other hosts are recorded and blocked` - before the first visit; the site under test is not blocked
- `I forget the recorded requests`
- `there should (not) be a request to :host` - part of the host name, e.g. `youtube`
- `all links in :selector should respond with 200` - follows redirects

### PageHealthTrait

A smoke test for every page type. It checks the page of the last `I access the URI path`, with what Playwright
collected since (`page.pageErrors()`, `page.consoleMessages()`, `page.requests()` - the last 200 errors and console
messages, the last 100 requests).

- `there should be no JavaScript errors` - uncaught errors and `console.error()`
- `there should be no failed requests [except:]` - requests to the site under test that failed or got 4xx/5xx (other
  hosts are ignored - see `NetworkTrait`); the table's column `URI path` lists known gaps (part of the path)
- `every image should be loaded` - loads lazy images right away, waits up to 15 seconds (thumbnails are generated on
  their first request)
- `every image should have an alt attribute` - `alt=""` and images hidden from assistive technology pass

```gherkin
Scenario: the homepage is healthy
  When I access the URI path "/"
  Then there should be no JavaScript errors
  And there should be no failed requests
  And every image should be loaded
  And every image should have an alt attribute
```

### MailpitTrait

Needs [Mailpit](https://mailpit.axllent.org) as SMTP server of the SUT; Behat reaches its API at `MAILPIT_API_URL`
(default `http://mailpit:8025`). Tag the scenarios with `@mailpit`: the inbox is emptied before them.

- `exactly :count mail(s) should have been sent to :recipient` - waits up to 5 seconds (`0 mails` always waits)
- `the mail to :recipient should have the subject :subject`
- `the mail to :recipient should contain :text` - the plain text part
- `the mail to :recipient should reply to :address`
- `the mail to :recipient should have the attachment :fileName`

### DebuggingTrait

- `I pause for debugging` - see [Debugging](#debugging)

### StepTimingTrait

No steps: with `E2E_TIMINGS=1` it writes the duration of every step and scenario to `step-timings.jsonl` in the results
directory - see [Debugging](#debugging). A local dev tool for measuring the suite - not meant for the pipeline.

### WireMockTrait

- `the API :api serves :file for :method :path [with status :status]`
- `I load the stubs :tape of the API :api`
- `I clear all API stubs`
- `the API :api should have received :method :path [:count times]`

Setup and details: [Mocked third-party APIs](#mocked-third-party-apis-wiremock).

### Notes on the steps

- `the Fusion output should be` compares the whole HTML and breaks with every changed space or class - prefer
  the CSS selector steps. `the inner HTML of ... should be` compares for equality, not as regular expression.
- Escaped quotes (`\"`) inside a `"..."` parameter don't match the step - put values with double quotes in single
  quotes (`'{"all":true}'`).
- The assertions on the page wait for the expected state (Playwright's
  [web-first assertions](https://playwright.dev/docs/test-assertions)): up to 5 seconds - change it with
  `$this->setPlaywrightTimeout(10000)` in the `FeatureContext` constructor. A negative check (`should not`, `hidden`)
  passes as soon as the state is reached.
- Screenshots document a result, they don't check it.
- Project-specific steps go into your `FeatureContext` or a trait of your project. Drive the browser with
  `$this->playwrightRun($script, ...$values)`: every `%s` in the script becomes the next value as JS literal, so
  quotes in step parameters can't break it - JavaScript the step builds itself goes in as `JsCode::of(...)`, a value
  inside a CSS selector through `CssValue::of()` first (a CSS string, e.g. `[data-name=%s]`). Assert with `expect` in
  the script - it waits and reports expected and received value - instead of returning a value and asserting in PHP;
  where you need the value in PHP, `playwrightArray()` and `playwrightStrings()` check its type:

  ```php
  #[Then('the teaser :title should link to :uriPath')]
  public function theTeaserShouldLinkTo(string $title, string $uriPath): void
  {
      $this->playwrightRun(
          'await expect(vars.page.getByRole("article").filter({hasText: %s}).getByRole("link")).toHaveAttribute("href", %s);',
          $title,
          $uriPath
      );
  }
  ```

### Ideas for more steps

Not shipped yet - we add them when projects need them (open an issue with your use case):

- links and attributes: `the link :name should point to :href`, `the :role :name should have the attribute ...`,
  counting elements, `there should be the text :text in the :role :name`
- the response: headers (cache, security headers), redirects with their status
- page meta and SEO: meta tags, canonical URL, `lang`, hreflang alternates
- interactions: hover, downloads, links that open a new tab

### Examples by level

When to use which: [testing guide](E2E_TESTING_GUIDE.md#where-to-test).

- **Component** (a Fusion prototype, like a pure function): `I render the Fusion object` without nodes -
  [FusionComponent/Button.feature](Tests/E2E/Features/FusionComponent/Button.feature).
- **Integration** (node → Fusion wiring): create nodes, `I get the node`, `... with the current context node` -
  [FusionIntegration/Button.feature](Tests/E2E/Features/FusionIntegration/Button.feature).
- **Page in the browser**: `I access the URI path` + assertions/screenshot -
  [PageRendering/Homepage.feature](Tests/E2E/Features/PageRendering/Homepage.feature).
- **Page via Fusion** (no request, no browser): `I render the page` -
  [PageRendering/FusionPage.feature](Tests/E2E/Features/PageRendering/FusionPage.feature).

## Dynamic SUT URL

The SUT base URL comes from `SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT`. When it changes per scenario (e.g. dimensions or
sites resolved by host), call `setSystemUnderTestUrlModifier()` in a step of your own; it's reset before the next
scenario:

```php
#[Given('my subdomain is :subdomain')]
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
[WireMock](https://wiremock.org) instead:

1. Run WireMock next to the app, locally and in CI (Docker image `wiremock/wiremock`), and point the SUT context's API
   base URL to it (e.g. `http://wiremock:8080/shop-api`).
2. Use `WireMockTrait` and configure the APIs in the constructor of your `FeatureContext`:

   ```php
   $this->setupWireMock(getenv('WIREMOCK_ADMIN_URL') ?: 'http://wiremock:8080', [
       'shop' => ['fixtures' => __DIR__ . '/../WireMock/shop', 'pathPrefix' => '/shop-api'],
   ]);
   ```
3. Tag the features with `@wireMock` - WireMock is reset before each of their scenarios.

Per API, the fixture directory holds `_base/*.json` (mapping files loaded before every scenario), response bodies and
one directory of mapping files per "tape":

- `the API :api serves :file for :method :path [with status :status]` - answers one call with a body file
  (`.json` may be omitted); wins over base stubs; a path with query string must match exactly
- `I load the stubs :tape of the API :api` - imports all mapping files of the tape directory
- `I clear all API stubs` - back to the base stubs, e.g. when the API's answer changes after an action
- `the API :api should have received :method :path [:count times]` - asserts an outgoing call, when the call is the
  feature

While writing a test, `'proxyBaseUrl' => 'https://real.api.example.com'` in the API config forwards unmatched requests
to the real API, so you see which calls happen - never in CI. `/__admin/mappings` lists the active stubs.

# Running Behat Tests

1. Start the bridge on your machine and keep it running (e.g. all day). It runs every script it receives and listens
   on all interfaces, so containers reach it - keep it in a trusted network:

   ```bash
   cd playwright-bridge && node index.js   # port 3000; HEADLESS=false node index.js shows the browser
   ```

2. Make sure your application runs and the E2E database is migrated ([Setup step 2](#2-two-flow-contexts-two-ports)).
3. Run Behat where your application runs. Neos.Behat boots Flow in `FLOW_CONTEXT` and accepts only `Testing/Behat` or a
   sub context - set it when your container has another one. A folder, feature file or `file:line` after the config
   runs only those scenarios:

   ```bash
   FLOW_CONTEXT=Testing/Behat bin/behat -c DistributionPackages/Your.SitePackageKey/Tests/Behavior/behat.yml.dist
   FLOW_CONTEXT=Testing/Behat bin/behat -c DistributionPackages/Your.SitePackageKey/Tests/Behavior/behat.yml.dist DistributionPackages/Your.SitePackageKey/Tests/Behavior/Features/Fusion/
   FLOW_CONTEXT=Testing/Behat bin/behat -c DistributionPackages/Your.SitePackageKey/Tests/Behavior/behat.yml.dist DistributionPackages/Your.SitePackageKey/Tests/Behavior/Features/WebsiteRendering.feature:27
   ```

IDE "run" buttons usually don't work when Behat runs in a container - use the CLI or your
[project tasks](#6-project-tasks-recommended).

## Debugging

- **Watch the tests**: start the bridge with `HEADLESS=false node index.js` - worth a project task next to the
  headless one.
- **Screenshots**: `And I take a screenshot "name.png"` in a `@playwright` scenario; failed steps get an `error_*.png`
  automatically. Both go to the results directory: `e2e-results/` where Behat runs, or what you pass to
  `setupPlaywright($resultsDir)`.
- **Traces**: failed `@playwright` scenarios write `report_<feature>_<scenario>.zip` to the results directory - open it
  with `npx playwright show-trace <file>`. Each Behat step is a group in the trace, so the Trace Viewer shows the
  actions per step. For traces of every scenario (or none), call
  `$this->setPlaywrightTracingMode(self::PLAYWRIGHT_TRACING_MODE_ALWAYS)` (or `..._OFF`; default `..._ON_ERROR`) in
  the `FeatureContext` constructor. For a single run, set `E2E_TRACE=on-error|always|off` where Behat runs - it wins
  over `setPlaywrightTracingMode()` (with `docker compose exec`, pass it on with `-e E2E_TRACE`).
- **Server logs**: an exception in the SUT only shows up as error page, the stack trace is in the logs. The Flow logs
  (`Data/Logs`) are cleared before every scenario, and a failed scenario gets its log and exception files copied to
  `logs_<feature>_<line>_<scenario>/` in the results directory. If the SUT logs elsewhere, call
  `setFlowLogsDirectory('/path/as/seen/from/behat')`; `setFlowLogsDirectory(null)` switches clearing and copying off
  (e.g. when your development site shares `Data/Logs`).
- **Narrow it down**: `--stop-on-failure` stops at the first error, so you can inspect the E2E database; `-vvv` prints
  full stack traces; tag scenarios (e.g. `@debug`) and run them with `--tags=debug`.
- **Pause the browser**: `And I pause for debugging` opens the Playwright inspector (`page.pause()`) on the bridge
  side. It needs a visible browser (`HEADLESS=false`) and Behat run with `PAUSE_FOR_DEBUGGING=true`, otherwise the step
  fails right away. The SUT and its database keep the scenario's state, so you can click around.
- **Slow steps**: use `StepTimingTrait` in your `FeatureContext` and run Behat with `E2E_TIMINGS=1` (with
  `docker compose exec`: `-e E2E_TIMINGS`). `step-timings.jsonl` in the results directory then has one line per step
  and scenario: feature, line, step text, duration (`ms`), result, and the number of requests to the bridge with their
  time (`bridgeCalls`, `bridgeMs`) - the rest is PHP (fixtures, cache, database). The kickstart sums it up with
  `mise run tests:e2e:timings <label>` and compares two labels with `mise run tests:e2e:timings:compare <a> <b>`.
  It's a local dev tool for measuring the suite - don't set `E2E_TIMINGS` in the pipeline.
- **Let an agent analyse failures**: the results directory holds screenshots, traces, server logs and - with
  `--format junit --out <dir>` - the JUnit report. Tell a coding agent like Claude where it is.

# Migrating tests from Neos 8

Start your `FeatureContext` from [`FeatureContext.php.default`](Templates/FeatureContext.php.default) instead of
patching the old one - a `FeatureContext` of an older version fails with missing classes. Also new:

- The traits moved from `Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap\` to `Sandstorm\E2ETestTools\Behat\` and are
  autoloaded - drop the `require_once` lines.
- The templates are in `Templates/` (`FeatureContext.php.default`, `playwright-bridge/`) - compare your copy of the
  bridge with the new one.
- `Testing/Behat` needs the SUT's cache storage and `applicationIdentifier` and its persistent resource storage/target
  ([Setup step 2](#2-two-flow-contexts-two-ports)).
- Behat must run with `FLOW_CONTEXT=Testing/Behat` (or a sub context) - Neos.Behat 9.1 refuses other contexts, e.g. a
  container-wide `Development/...` or the SUT context of a CI job.
- Symfony projects are no longer supported - stay on 8.x there.

In the feature files ([Fixtures](#fixtures) shows the result):

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
| YAML exported with the Neos 8 button (nodes keyed by identifier, nested `children`) | rejected - export again ([Exporting existing content](#exporting-existing-content)) |

# Upgrading the steps

**Split traits** - the steps of a page visit moved out of `NeosBackendControlTrait`, and steps many projects wrote
themselves are shipped now:

- Add `use NavigationTrait;` to your `FeatureContext` - `I access the URI path`, `the URI path should be` and `the
  response status code should be` are there now. Without it, Behat reports these steps as undefined.
- `there should be the text ... on the page` moved to `PageAssertionsTrait`.
- New: `NavigationTrait` (reload, screen size, scrolling), `NetworkTrait` (requests to other hosts, links), `MailpitTrait`,
  and in `PageAssertionsTrait`/`FormInteractionTrait` the steps by role and accessible name (`I focus the :role :name`,
  `the :role :name should be focused`, ...) and `the field :label should be invalid`. Delete project steps with the same
  wording, or keep yours and don't use the trait - Behat reports both as ambiguous.

**Changed behaviour:**

- `there should be the text ... on the page` checks the rendered text now, like its `not` variant: text in hidden
  elements counts as absent, the case matters, and it waits 5 seconds instead of 15. A step that found hidden text
  before fails now - check the element that shows the text, or wait for it with another step first.
- All assertions wait `setPlaywrightTimeout()` (default 5000 ms). `$pageAssertions_timeout` still works, but is
  deprecated.

**Renamed steps** - one wording for the same thing (`px`, `delete`, `should not be`, `:method :path`, `should be`):

| Before | Now |
|---|---|
| `I have a site for Site Node ...` | `I have a site for the site node ...` |
| `the Fusion output should equal to :expected` | `the Fusion output should be :expected` |
| `in the fusion output, the inner HTML of CSS selector :selector matches :expected` | `the inner HTML of :selector in the Fusion output should be :expected` |
| `in the fusion output, the attributes of CSS selector :selector are:` | `the attributes of :selector in the Fusion output should be:` |
| `I do a screenshot :filename` | `I take a screenshot :filename` |
| `I debug the playwright script` | `I debug the Playwright script` |
| `the viewport size is :width x :height` | `the viewport is :width x :height px` |
| `I scroll up by :pixels pixels` | `I scroll up by :pixels px` |
| `I remove the local/session storage key :key` | `I delete the local/session storage key :key` |
| `there should be no request to :host` | `there should not be a request to :host` |
| `the API :api path :path on :method serves response :file` | `the API :api serves :file for :method :path` |

Most of them with sed (GNU sed; on macOS `sed -i ''`), from the directory with your features:

```bash
find . -name '*.feature' -exec sed -i -E \
  -e 's/I have a site for Site Node/I have a site for the site node/' \
  -e 's/the Fusion output should equal to/the Fusion output should be/' \
  -e 's/in the fusion output, the inner HTML of CSS selector ("[^"]*") matches/the inner HTML of \1 in the Fusion output should be/' \
  -e 's/in the fusion output, the attributes of CSS selector ("[^"]*") are:/the attributes of \1 in the Fusion output should be:/' \
  -e 's/I do a screenshot/I take a screenshot/' \
  -e 's/I debug the playwright script/I debug the Playwright script/' \
  -e 's/the viewport size is ([^ ]+) x ([^ ]+)/the viewport is \1 x \2 px/' \
  -e 's/I scroll up by ([^ ]+) pixels/I scroll up by \1 px/' \
  -e 's/I remove the ([^ ]+) storage key/I delete the \1 storage key/' \
  -e 's/there should be no request to/there should not be a request to/' \
  -e 's/the API ("[^"]*") path ("[^"]*") on ("[^"]*") serves response ("[^"]*")/the API \1 serves \4 for \3 \2/' \
  {} +
```

New: `the :role :name should be visible/hidden/enabled/disabled` - delete a project step like `the button :caption
should be disabled`, it's ambiguous now.

New: `PageHealthTrait` - JavaScript errors, failed requests and images of a page.

**Custom steps:** `$this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(..., JsValue::of(...)))`
still works; `$this->playwrightRun('...%s...', $value)` is shorter. The private helpers of the traits
(`formInteraction_expect()`, `pageAssertions_expect()`) changed their signatures - don't call them from your steps.

# Upgrading the playwright-bridge

Your copy of the bridge ([Setup step 5](#5-playwright-playwright-bridge)) doesn't update with the package. Compare it
with [`Templates/playwright-bridge/`](Templates/playwright-bridge) after an update - if you haven't changed it, replace
it and run `npm ci && npx playwright install chromium`. Build the CI image again.

**To Playwright 1.64** (from 1.51):

- The steps assert with `expect` - a bridge that doesn't hand it to the scripts fails the first `@playwright` scenario
  with "The playwright-bridge is too old" (#1728000002). The scripts now get
  `(contextName, browser, context, vars, fs, expect)`; the template had `(context, vars)`, some project copies the
  long list already. A custom step that declares one of these names itself (e.g. `const fs = require("fs")`) is a
  `SyntaxError` now - rename the variable.
- `package.json` has `@playwright/test` (for `expect`) next to `playwright`, both 1.64.0; the `Dockerfile` is based on
  `mcr.microsoft.com/playwright:v1.64.0-noble`; Node 20 or newer (`.nvmrc`).
- `chromium.launch()` no longer takes `devtools` (removed in 1.58) - if your copy passes it, drop it (or use
  `args: ['--auto-open-devtools-for-tabs']`). `HEADLESS=false` or `--no-headless` show the browser.
- Since 1.57 the browser is a "Chrome for Testing" build - screenshots can differ by a few pixels. Compare visual
  baselines again, if you have any.
- The assertions of `PageAssertionsTrait`, `FormInteractionTrait`, `BrowserStateTrait` and `the URI path should be` /
  `there should be the text ... on the page` wait for the expected state now instead of checking once - a step that
  was flaky because of a slow page passes now. Their failure messages come from Playwright (expected, received, call
  log) instead of PHPUnit.
- `I delete the cookie` uses `context.clearCookies({name})` - other cookies stay as they are (before, all were
  removed and added again).

# Troubleshooting

1. **The SUT serves the wrong content or database, although the response status is 200.**

   Your web server sets the SUT's Flow context per vhost, but the container also has a process-wide `FLOW_CONTEXT`
   (for `./flow`), and Flow checks `getenv()` before `$_SERVER` - so the container-wide value silently wins. This
   happens with every web server that sets only per-request variables (e.g. Caddy/FrankenPHP's `env` directive). Check
   which context's cache directory the request filled (e.g. `Data/Temporary/Production/SubContextE2E-SUT/Cache/...`).

   Fix: copy `$_SERVER` to the real environment in a file that runs before every request (e.g. `auto_prepend_file` in
   php.ini):

   ```php
   if (isset($_SERVER['FLOW_CONTEXT'])) {
       putenv('FLOW_CONTEXT=' . $_SERVER['FLOW_CONTEXT']);
       $_ENV['FLOW_CONTEXT'] = $_SERVER['FLOW_CONTEXT'];
   }
   ```

2. **`NodeConstraintException: Node type "..." is not allowed below tethered child nodes "..."` when creating
   fixtures.**

   The NodeType's `constraints.nodeTypes` allow only a specific wrapper type (often a "section" or "row") directly in
   that collection - content nodes go inside the wrapper. Check the `constraints` before picking a fixture NodeType.

3. **Content changes don't show up on the SUT after resetting the content repository.**

   `setupContentRepository()` flushes only the routing caches and the Fusion content cache - not e.g. a full-page cache
   or caches of your project - and a flush only reaches the SUT when both contexts share the cache storage and
   `applicationIdentifier` ([Setup step 2](#2-two-flow-contexts-two-ports)). Give the cache the same storage in both
   contexts' `Caches.yaml` (for a file backend: `backendOptions.cacheDirectory` in `Testing/Behat` pointing to the
   SUT's directory) and flush it in a `@BeforeScenario` hook - in PHP, not with `./flow`:

   ```php
   $this->getObject(CacheManager::class)->getCache('<YourCacheIdentifier>')->flush();
   ```

4. **Files and images created by fixture steps return 404 on the SUT.**

   `Testing/Behat` stores and publishes them somewhere else than the SUT looks - use the SUT's resource storage and
   target there ([Setup step 2](#2-two-flow-contexts-two-ports)).

5. **`RuntimeException: broken` in a `BeforeScenario` hook (`setupContentRepository()`), with Neos 9.2 or later.**

   Neos 9.2 wires the `FakeClock` of `neos/contentrepository-testsuite` in every `Testing` context - a dev dependency
   that projects usually don't install. The package switches it back to the real clock for `Testing/Behat`
   (`Configuration/Testing/Behat/Objects.yaml`); if Behat runs in another `Testing` sub context, copy that file into
   your configuration for that context.

6. **`Could not resolve ini file` when Flow starts (Flow 9.2 or later).**

   Flow passes the loaded php.ini to its CLI sub-requests and fails if PHP loaded none - as in the official PHP and
   FrankenPHP images, which only ship `php.ini-production`/`php.ini-development`. Copy one of them to `php.ini` in the
   image (see `Tests/SystemUnderTest/Dockerfile`).
