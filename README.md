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
  - [6. CI Pipeline (optional)](#6-ci-pipeline-optional)
- [Writing Behat Tests](#writing-behat-tests)
  - [Fixture Setup](#fixture-setup)
  - [Fusion Component Testcases](#fusion-component-testcases)
  - [Fusion Integration Testcases](#fusion-integration-testcases)
  - [Full-Page Snapshot Testcases](#full-page-snapshot-testcases)
  - [Style Guide](#style-guide)
- [Running Behat Tests](#running-behat-tests)
- [Troubleshooting](#troubleshooting)
- [Architecture](#architecture)
- [TODO](#todo)
  - [Writing Behat Tests examples are outdated](#writing-behat-tests-examples-are-outdated)
  - [Setup command](#setup-command)
  - [Symfony support](#symfony-support)

<!-- /TOC -->

# Setup

No one-shot setup command exists (see [TODO](#todo)), so these steps are done by hand, once per
project, in order. None are optional: skip step 2 and running Behat against your normal dev
database will delete its content.

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

## 6. CI Pipeline (optional)

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
  [Two Flow Contexts, Two Ports](#two-flow-contexts-two-ports)), copy that config file into the running container
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

Here, we try to give examples for common Behat scenarios; such that you can easily get started.

## Fixture Setup

The Sandstorm.E2ETestTools Package provides inline, delegated and hybrid fixture setups.
We recommend using a hybrid approach.

### Delegated / Hybrid

In your Neos Backend, select a node you want to test, go to the meta tab and click "export node".
This will download a yaml file containing all the selected node's parents and all descendants of the
nearest document parent (in case of dependencies as such references). Afterwards, move the downloaded yaml file into
test directory and use them in your .feature file like such:
```gherkin
Given I have a site for Site Node "www-my-site" with name "www.my.side"
And I have the following nodes from file "relative-path-from-test-file-to.yaml"
```

Also, you can override node properties inline:
```gherkin
Given I have the following nodes from file "relative-path-from-test-file-to.yaml" with overwrites
| identifier                           | property      | value |
| 5cb3a5f7-b501-40b2-b5a8-9de169ef1105 | title         | Foo   |
```

### Inline
```gherkin
Given I have the following nodes:
| Identifier                           | Path               | Node Type                | Properties                   | Language |
| 5cb3a5f7-b501-40b2-b5a8-9de169ef1105 | /sites             | unstructured             | {}                           | de       |
```

## Fusion Component Testcases

You can use a test case like the following for testing components - analogous to what you usually do with Monocle.
`When I render the Fusion object ...:` renders a Fusion path against your site package's Fusion (plus the Fusion
snippet you pass in) — no nodes needed, as long as the component doesn't render links.

```gherkin
@playwright
Feature: Button component renders

  Scenario: primary button
    When I render the Fusion object "/testcase":
    """
    testcase = PACKAGEKEY:Component.Button {
      title = 'Click me'
      type = 'primary'
    }
    """
    Then in the fusion output, the inner HTML of CSS selector "button span" matches "Click me"
    Then I store the Fusion output in the styleguide as "Button_Component_Primary"
```

`@playwright` is only needed for the last step (see [Style Guide](#style-guide)).

> **TODO — outdated:** components that render links need a node as context
> (`When I render the Fusion object ... with the current context node:`), which still relies on the
> pre-Neos-9 `$this->currentNodes` — see
> [Writing Behat Tests examples are outdated](#writing-behat-tests-examples-are-outdated).

## Fusion Integration Testcases

> **TODO — outdated:** same as above, plus `Given I get a node by path ... with the following
> context:` isn't a step this package provides at all anymore. Needs rewriting against the
> current node-creation/lookup API.

It is especially valuable to not just test the Fusion component (which is more or less like a pure function), but
instead test that a given *Node* renders in a certain way - so that the *wiring between Node and Fusion component*
is set up correctly.

A test case can look like the following one:

```gherkin
@fixtures
@playwright
Feature: Testcase for Button Integration

  Background:
    Given I have a site for Site Node "site"
    Given I have the following nodes:
      | Identifier                           | Path               | Node Type                | Properties                   | Language |
      | 5cb3a5f7-b501-40b2-b5a8-9de169ef1105 | /sites             | unstructured             | {}                           | de       |
      | 5e312d5b-9559-4bd2-8251-0182e11b4950 | /sites/site        | PACKAGEKEY:Document.Page | {}                           | de       |
      | 9cbaa2e2-d779-4936-aa02-0dab324da93e | /sites/site/nested | PACKAGEKEY:Document.Page | {"uriPathSegment": "nested"} | de       |


  Scenario: Secondary Button
    Given I create the following nodes:
      | Path                      | Node Type                 | Properties                                                                   | Language |
      | /sites/site/main/testnode | PACKAGEKEY:Content.Button | {"type": "secondary", "link": "node://9cbaa2e2-d779-4936-aa02-0dab324da93e"} | de       |
    Given I get a node by path "/sites/site/main/testnode" with the following context:
      | Workspace | Dimension: language |
      | live      | de                  |

    When I render the Fusion object "/testcase" with the current context node:
    """
    testcase = PACKAGEKEY:Content.Button
    """
    Then in the fusion output, the attributes of CSS selector "a" are:
      | Key  | Value      |
      | href | /de/nested |

    Then I store the Fusion output in the styleguide as "Button_Integration_Secondary"

```

## Full-Page Snapshot Testcases

> **TODO — outdated:** the `StepGeneratorCommandController` example below type-hints
> `Neos\ContentRepository\Domain\Model\NodeInterface` and `ContextFactoryInterface`, both
> pre-Neos-9 classes that no longer exist — it won't compile. `NodeTableBuilder`'s real API is
> also `withFixturesBaseDirectory($packageKey, $subPath)`, not `withFixtureBasePath(...)` as
> shown, and `NodeTable::print()` still emits the dead bare `Given I have the following nodes:`
> syntax (see the two TODOs above). `Given I accepted the Cookie Consent` below also isn't a step
> this package provides — it's a stray project-specific step that shouldn't be in this example.
> Needs a full rewrite: port `NodeTableBuilder`/`NodeTable` to the current CR API, and fix the
> emitted step syntax.

This tests a complete page rendering, and not just single components. It is meant mostly for visual checking; and most
likely you'll work less with specific assertions.

In this case, the rendering depends on many more nodes - so setting up the behat fixture with all the relevant nodes can
be a bit tedious. Luckily, there are helpers in this package to help with the process. We suggest writing a
CommandController like the following:

```php
<?php

namespace PACKAGEKEY\Command;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Sandstorm\E2ETestTools\StepGenerator\NodeTableBuilderService;

class StepGeneratorCommandController extends CommandController
{
    /**
     * @Flow\Inject
     */
    protected ContextFactoryInterface $contextFactory;

    /**
     * Main API for creating NodeTable instances to print BDD steps.
     *
     * @Flow\Inject
     */
    protected NodeTableBuilderService $nodeTableBuilderService;

    public function homepageCommand()
    {
        $nodeTable = $this->nodeTableBuilderService->nodeTable()
            ->withDefaultNodeProperties(['Language' => 'de'])
            ->build();
        $siteNode = $this->getSiteNode();

        $nodeTable->addParents($siteNode);
        $nodeTable->addNode($siteNode);
        $nodeTable->addNodesUnderneathExcludingAutoGeneratedChildNodes($siteNode, '!Neos.Neos:Document'); // we recurse into the content of the homepage
        $nodeTable->addNodesUnderneathExcludingAutoGeneratedChildNodes($siteNode, 'Neos.Neos:Document'); // we render the remaining document nodes so we can have a menu rendered (but without content)

        $nodeTable->print();
    }

    /**
     * @return NodeInterface
     */
    public function getSiteNode(): NodeInterface
    {
        $context = $this->contextFactory->create([
            'workspaceName' => 'live',
            'invisibleContentShown' => true,
            'dimensions' => [
                'language' => ['de']
            ],
            'targetDimensions' => [
                'language' => 'de'
            ]
        ]);
        return $context->getCurrentSiteNode();
    }
}
```

Now, when you run `./flow stepGenerator:homepage`, you'll get a table like the following:

```gherkin
Given I have the following nodes:
| Path   | Node Type    | Properties | HiddenInIndex | Language |
| /sites | unstructured | []         | false         | de       |
    # ... many more nodes here in this table ...
```

This is ready to be pasted into a test case like the following:

```gherkin
@fixtures
@playwright
Feature: Homepage Rendering

  Scenario: Full Homepage Rendering
    Given I have a site for Site Node "site"
    # to regenerate, use: ./flow stepGenerator:homepage
    Given I have the following nodes:
      | Path   | Node Type    | Properties | HiddenInIndex | Language |
      | /sites | unstructured | []         | false         | de       |
    # ... many more nodes here ...

    Given I get a node by path "/sites/site" with the following context:
      | Workspace | Dimension: language |
      | live      | de                  |

    Given I accepted the Cookie Consent
    When I render the page
    Then I store the Fusion output in the styleguide as "Page_Homepage"
    Then I store the Fusion output in the styleguide as "Page_Homepage_Mobile" using viewport width "320"
```

This enables to generate **responsive, reproducible screenshots** of the different pages, and being able to re-generate
this when the dummy data changes.

### persistent resources in BDD tests

In case, your node fixtures point to some assets from the Neos.Media module, you can generate fixtures for them as well.
You need to pass the second parameter ($fixtureBasePath) when creating a NodeTable.

You probably want to store you asset fixtures near your feature files.

# TODO explain how to set fixture base path

```php
    // ... Step Generator Command Controller

    public function homepageCommand()
    {
        $nodeTable = $this->nodeTableBuilderService->nodeTable()
            ->withDefaultNodeProperties(['Language' => 'de'])
            // !!! Here you setup your directory for storing your fixture files.
            // It will print a path relative to the Flow package directory.
            //  -> most likely: Sites/Your.PackageKey/Tests/Behavior/Features/Homepage/Resources/someSHA1.png (depending on the type of the composer package)
            ->withFixtureBasePath('Your.PackageKey', 'Tests/Behavior/Features/Homepage/Resources/')
            ->build();
        $siteNode = $this->getSiteNode();

        $nodeTable->addParents($siteNode);
        $nodeTable->addNode($siteNode);
        $nodeTable->addNodesUnderneathExcludingAutoGeneratedChildNodes($siteNode, '!Neos.Neos:Document'); // we recurse into the content of the homepage
        $nodeTable->addNodesUnderneathExcludingAutoGeneratedChildNodes($siteNode, 'Neos.Neos:Document'); // we render the remaining document nodes so we can have a menu rendered (but without content)

        // when the table is printed, it includes other tables containing asset fixtures
        $nodeTable->print();
    }

    // ...

```

Let's say you have three images in your node data fixtures (node property of type `ImageInterface`). Your output could
look like:

```gherkin

Given I have the following images:
| Image ID                             | Width | Height | Filename            | Collection | Relative Publication Path | Path                                                                                                        |
| 3a28c97c-58f1-45c5-b1ad-2f491c904467 |       |        | Map-circle-blue.svg | persistent |                           | Sites/Your.Package/Tests/Behavior/Features/Homepage/Resources/9600acebed149b1e0178b214a7f3a82bc7a829a4.svg  |
| 846d085f-091b-4d08-82bb-e5f04150c594 | 615   | 418    | cat_caviar.jpeg     | persistent |                           | Sites/Your.Package/Tests/Behavior/Features/Homepage/Resources/ee53c207588c199b4e5359f5e06d241b0d93b78e.jpeg |
| 3ca6e806-182a-4af2-9a60-50d2ff0bcbdb | 4500  | 4500   | mark-man-stock.png  | persistent |                           | Sites/Your.Package/Tests/Behavior/Features/Homepage/Resources/9784f58d2f6810b773807b3cfd56dcbe2b3a1c65.png  |
Given I have the following nodes:
| Path | Node Type | Properties | HiddenInIndex | Language |
    # ... nodes go here here with reference to Image ID in their serialized properties
    # a property might look like: { ..., "myImageProperty":{"__flow_object_type":"Neos\\Media\\Domain\\Model\\Image","__identifier":"3a28c97c-58f1-45c5-b1ad-2f491c904467"}, ...
```

Note, that the `Path` column values are printed and read relative to the Flow package directory. That should keep your
tests more or less environment independent.
Usually, the files are stored inside a DistributionPackages/* package which is symlinked into the Flow package
directory (and thus is readable from your Test and writable from your Command Controller).
Also, those files should be added to git, since they are part of your test cases.

### dynamic modification of SUT URL via step

By default, the SUT URL is configured statically via environment variable. In some cases, that is not sufficient.

Use cases:

#### custom content dimension resolving based on host info

Let's say, your Neos project has a custom content dimension value resolver, f.e. by host name or subdomain. The SUT base
URL is configured statically via environment variable. But in the mentioned special case, you need dynamic base URLs
that are modified via your own custom steps.

#### multi-site setup

When your Neos application has multiple sites, the host name also needs to be defined via custom step.

The `PlaywrightConnector` has an API for that purpose:

public API: `PlaywrightTrait#setSystemUnderTestUrlModifier(\Closure $urlModifier): void`
delegates to internal: `PlaywrightConnector#setSystemUnderTestUrlModifier(\Closure $urlModifier): void`

Note, that the modifier is reset after each scenario.

You need to call that setter from your custom step, that could look like:

```php
...

    /**
     * @Given my base URL is :baseUrl
     */
    public function myBaseUrlIs($baseUrl)
    {
        $this->setSystemUnderTestUrlModifier(function (string $staticBaseUrl) use ($baseUrl) {
            return $baseUrl;
        });
    }

    /**
     * @Given my subdomain is :subdomain
     */
    public function mySubdomainIs($subdomain)
    {
        $this->setSystemUnderTestUrlModifier(function (string $baseUrl) use ($subdomain) {
            return sprintf("%s://%s.%s.nip.io:%s/%s",
                parse_url($baseUrl, PHP_URL_SCHEME),
                $subdomain,
                parse_url($baseUrl, PHP_URL_HOST),
                parse_url($baseUrl, PHP_URL_PORT),
                parse_url($baseUrl, PHP_URL_PATH),
            );
        });
    }

...

```

and behat call:

```gherkin
Given my subdomain is "de"
```

## Usage for Site Packages that use Sandstorm.NeosAcl

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

## pause for debugging

If you want to use the pause functionality of playwright, please start the test with
`PAUSE_FOR_DEBUGGING=true` to prevent curl timeouts when communicating with the playwright-bridge.

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
./flow behat:setup
bin/behat -c Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/behat.yml.dist
```

Behat also supports running single tests or single files - they need to be specified after the config file, e.g.

```bash

# run all scenarios in a given folder
bin/behat -c Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/behat.yml.dist Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/Features/Fusion/

# run all scenarios in the single feature file
bin/behat -c Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/behat.yml.dist Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/Features/WebsiteRendering.feature

# run the scenario starting at line 27
bin/behat -c Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/behat.yml.dist Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/Features/WebsiteRendering.feature:27
```

In case of exceptions, it might be helpful to run the tests with `--stop-on-failure`, which stops the test cases at the
first error. Then, you can inspect the testing database and manually reproduce the bug.

Additionally, `-vvv` is a helpful CLI flag (extra-verbose) - this displays the full exception stack trace in case of
errors.

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
[Two Flow Contexts, Two Ports](#two-flow-contexts-two-ports). Wiring the second port to the right context is exactly
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

# TODO

## Writing Behat Tests examples are outdated

[Fusion Integration Testcases](#fusion-integration-testcases) and
[Full-Page Snapshot Testcases](#full-page-snapshot-testcases) are written against the
pre-Neos-9 Content Repository — dead tags/steps, and in the last case, classes that no longer
exist and code that won't compile. See the TODO callout inline in each section for specifics.
Node-free [Fusion Component Testcases](#fusion-component-testcases) work; everything that needs a
context node (`When I render the Fusion object ... with the current context node:`,
`When I render the page`) still reads the pre-Neos-9 `$this->currentNodes` and needs porting too.
Needs a full rewrite against the current CR API.

## Setup command

`behat:setup` / `behat:kickstart` (and this package's own `e2e:setup`, which called both) used to
scaffold most of [Setup](#setup) automatically. `behat:setup` is now a deprecated stub
that only prints an error and exits; `behat:kickstart` doesn't exist anymore at all. Either revive
an equivalent command in this package, or remove `e2e:setup`/`e2e:fix` if they're not worth
keeping now that they just shell out to dead commands.

## Symfony support

The Symfony variant ([README.Symfony.md](./README.Symfony.md)) hasn't been revisited alongside the Neos 9 changes in
this README — needs a pass later to confirm it's still accurate.
