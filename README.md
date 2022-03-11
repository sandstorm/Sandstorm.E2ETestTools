# End-To-End Test Tools

... for Neos and Flow Projects.

**for SYMFONY projects, see [README.Symfony.md](./README.Symfony.md).**

We use [Playwright](https://playwright.dev) as browser orchestrator for tests involving a real browser. We use Behat
as the test framework for writing all kinds of BDD tests.

- a way to test Fusion code with Behat

- Utilities for integrating the [Playwright](https://playwright.dev) browser orchestrator and the Behat test
  framework

<!-- TOC -->

- [End-To-End Test Tools](#end-to-end-test-tools)
- [Architecture](#architecture)
- [Installation and Setup Instructions](#installation-and-setup-instructions)
    - [Setting up Playwright](#setting-up-playwright)
    - [Creating a FeatureContext](#creating-a-featurecontext)
    - [Loading CSS and JavaScript for the Styleguide](#loading-css-and-javascript-for-the-styleguide)
- [Running Behat Tests](#running-behat-tests)
    - [Style Guide](#style-guide)
- [Writing Behat Tests](#writing-behat-tests)
    - [Fusion Component Testcases](#fusion-component-testcases)
    - [Fusion Integration Testcases](#fusion-integration-testcases)
    - [Full-Page Snapshot Testcases](#full-page-snapshot-testcases)

<!-- /TOC -->

# Architecture

> We suggest to skim this part roughly to get an overview of the general architecture.
> As long as you do not do in-depth modifications, you do not need to read it in detail.

The architecture for running behavioral tests is as follows:

```                                                                                              
   ╔╦══════════════════╦╗   1  ┌────────────────────┐
   ║│Behat Test Runner ├╬──────▶   E2E-Testrunner   │
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

2) The E2E Testrunner wraps Playwright (which is a browser orchestrator) and exposes a HTTP API. It is running as
   associated service. Behat communicates to the test runner via HTTP (1).

3) Then, the testrunner calls the unmodified application via HTTP (2).

4) The application then calls other services like Redis and the database - just as usual.

There is one catch with big implications, though: **The E2E tests need full control over the database** to work
reliably. As we do not want to clear our development database each time we run our tests, we need to **use two
databases**: one for Testing, and the other one for Development.

Additionally, the E2E tests need to reach the system wired to the *testing environment* through HTTP. This means we
need **two web server ports** as well: One for development, and one for the testing context.

This setup is somewhat complicated; so the following image helps to illustrate how the different contexts
interact **during development time and during production/CI**:

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

# Installation and Setup Instructions

> This is MANDATORY to read for people who want to integrate BDD into the project.

```
composer require sandstorm/e2etesttools @dev
./flow behat:setup
./flow behat:kickstart Your.SitePackageKey http://127.0.0.1:8081
rm bin/selenium-server.jar # we do not need this
```

- you can delete `behat.yml` and only keep `behat.yml.dist`
- in `behat.yml.dist`, remove the `Behat\MinkExtension` part completely.

  > Mink is generic a "browser controller API" which in our experience
  > is a bit brittle to use and adds unnecessary complexity. We recommend
  > to instead use Playwright directly.

- You should configure the Flow/Neos `Configuration/Testing/Behat/Settings.yaml` and copy the production `Settings.yaml`
  there; to ensure that Behat is accessing the same Database like the production application.

- You should create a `Configuration/Development/Docker/Behat/Settings.yaml` with the following contents:

  ```yaml
  Neos:
    Flow:
      persistence:
        backendOptions:
          dbname: '%env:DB_NEOS_DATABASE_E2ETEST%'
  ```

- You should create a `Configuration/Production/Kubernetes/Behat/Settings.yaml` with the following contents:

  ```yaml
  Neos:
    Flow:
      persistence:
        backendOptions:
          dbname: '%env:DB_NEOS_DATABASE_E2ETEST%'
  ```


## Setting up Playwright

We suggest copying `Resources/Private/e2e-testrunner-template` of this package to the root of the Git Repository and
name the folder `e2e-testrunner` (in our projects, usually one level ABOVE the Neos Root Directory).

Additionally, you'll need the following `.gitlab-ci.yml` for *BUILDING*

```yaml

package_app:
  stage: build
  image: docker-hub.sandstorm.de/docker-infrastructure/php-app/build:7.4-v2
  interruptible: true
  script:
    - cd app
    # NOTE: for E2E tests we HAVE also to install DEV dependencies; otherwise we won't be able to run behavioral tests then.
    - COMPOSER_CACHE_DIR=.composer-cache composer install --dev --ignore-platform-reqs
    - cd ..

    # set up Behat
    - mkdir -p app/Build && cp -R app/Packages/Application/Neos.Behat/Resources/Private/Build/Behat app/Build/Behat
    - cd app/Build/Behat && COMPOSER_CACHE_DIR=../../.composer-cache composer install && cd ../../../

    # build image
    - docker login -u gitlab-ci-token -p $CI_BUILD_TOKEN $CI_REGISTRY
    - docker build -t $CI_REGISTRY_IMAGE:$CI_BUILD_REF_SLUG .
    - docker push $CI_REGISTRY_IMAGE:$CI_BUILD_REF_SLUG
  tags:
    - docker
    - privileged
  cache:
    key: PROJECTNAME__composer
    paths:
      - app/.composer-cache



build_e2e_testrunner:
  stage: build
  image: docker-hub.sandstorm.de/docker-infrastructure/php-app/build:7.4-v2
  interruptible: true
  script:
    - cd e2e-testrunner
    - docker login -u gitlab-ci-token -p $CI_BUILD_TOKEN $CI_REGISTRY
    - docker build -t $CI_REGISTRY_IMAGE:$CI_BUILD_REF_SLUG-e2e-testrunner .
    - docker push $CI_REGISTRY_IMAGE:$CI_BUILD_REF_SLUG-e2e-testrunner
    - cd ..
  tags:
    - docker
    - privileged
```

Then, for *running* the tests, you'll need something like the following snippet in `.gitlab-ci.yml`.

Every related service (like redis, database, ...) needs to be started using a `servives` entry. Ensure the Docker image
version of the service matches the development and production image from `docker-compose.yml`.

The *environment variables* of the job are passed on to *all services* - so all connected services and the main job
share the same environment variables. Thus, you need to add the environment variables for BOTH the SUT (which is the
main job) and all related services to the `variables` section of the test job.

```yaml
e2e_test:
  stage: test
  interruptible: true
  # we're running this job inside the production image we've just built previously
  image:
    name: $CI_REGISTRY_IMAGE:$CI_BUILD_REF_SLUG
    # we may need to override the entrypoint here
    entrypoint: [ "" ]
  dependencies: [ ] # we do not need any artifacts from prior steps
  variables:
    # service mariadb
    MYSQL_USER: 'ci_user'
    MYSQL_PASSWORD: 'ci_db_password'
    MYSQL_DATABASE: 'ci_test'

    # System under Test
    FLOW_CONTEXT: 'Production/Kubernetes'
    DB_NEOS_HOST: 'mariadb'
    DB_NEOS_PORT: '3306'
    DB_NEOS_USER: '${MYSQL_USER}'
    DB_NEOS_PASSWORD: '${MYSQL_PASSWORD}'
    DB_NEOS_DATABASE: '${MYSQL_DATABASE}'
  services:
    - name: mariadb:10.5
    # here, we make the e2e-testrunner available
    - name: $CI_REGISTRY_IMAGE:$CI_BUILD_REF_SLUG-e2e-testrunner
      alias: e2e-testrunner
  script:
    # ADJUST: the following lines must be adjusted to match the *entrypoint*
    - cd /app && ./flow doctrine:migrate
    # make E2E Test Server available (port 9090)
    - ln -s /etc/nginx/nginx-e2etest-server-prod.conf /etc/nginx/conf.d/nginx-e2etest-server-prod.conf

    - /bin/sh /start.sh &
    # the playwright API URL does not need to be adjusted as long as the service alias for playwright is `e2e-testrunner`.
    - export PLAYWRIGHT_API_URL=http://e2e-testrunner:3000

    # ADJUST: you might need to adjust the SUT URL; and the wait URL below
    - export SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT=http://$(hostname -i):9090
    - |
      # now wait until system under test is up and running
      until $(curl --output /dev/null --silent --head --fail http://127.0.0.1:9090); do
          printf '.'
          sleep 5
      done

    # actually run the tests
    # ADJUST: use your pacakge key here
    - cd /app && rm -Rf e2e-results && mkdir e2e-results && bin/behat     --format junit --out e2e-results       --format pretty --out std       -c Packages/Application/PACKAGEKEY/Tests/Behavior/behat.yml.dist
    - cp -R /app/e2e-results $CI_PROJECT_DIR/e2e-results
    - cp -R /app/Web/styleguide $CI_PROJECT_DIR/styleguide
  artifacts:
    expire_in: 4 weeks
    paths:
      - e2e-results
      - styleguide
    reports:
      junit: e2e-results/behat.xml
```

## Creating a FeatureContext

The `FeatureContext` is the PHP class containing the step definitions for the Behat scenarios.
We provide base traits you should use for various functionality. The skeleton of the `FeatureContext`
should look as follows:

```php
<?php

use Behat\Behat\Context\Context;
use Neos\Behat\Tests\Behat\FlowContextTrait;
use Neos\ContentRepository\Tests\Behavior\Features\Bootstrap\NodeOperationsTrait;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Tests\Behavior\Features\Bootstrap\SecurityOperationsTrait;
use Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap\FusionRenderingTrait;
use Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap\PlaywrightTrait;

require_once(__DIR__ . '/../../../../../../Packages/Application/Neos.Behat/Tests/Behat/FlowContextTrait.php');
require_once(__DIR__ . '/../../../../../../Packages/Application/Neos.ContentRepository/Tests/Behavior/Features/Bootstrap/NodeOperationsTrait.php');
require_once(__DIR__ . '/../../../../../../Packages/Framework/Neos.Flow/Tests/Behavior/Features/Bootstrap/SecurityOperationsTrait.php');
require_once(__DIR__ . '/../../../../../../Packages/Application/Sandstorm.E2ETestTools/Tests/Behavior/Bootstrap/FusionRenderingTrait.php');
require_once(__DIR__ . '/../../../../../../Packages/Application/Sandstorm.E2ETestTools/Tests/Behavior/Bootstrap/PlaywrightTrait.php');

class FeatureContext implements Context
{
    // This is for integration with Flow (so you have access to $this->objectManager of Flow).  (part of Neos.Behat)
    use FlowContextTrait;
    
    // prerequisite of NodeOperationsTrait (part of Neos.Flow)
    use SecurityOperationsTrait;
    
    // create Nodes etc. in Behat tests (part of Neos.ContentRepository)
    use NodeOperationsTrait {
        // take overridden "iHaveTheFollowingNodes" from FusionRenderingTrait
        FusionRenderingTrait::iHaveTheFollowingNodes insteadof NodeOperationsTrait;
    }
    
    // Render Fusion code and Styleguide (part of Sandstorm.E2ETestTools)
    use FusionRenderingTrait;
    
    // Browser Automation
    use PlaywrightTrait;

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    public function __construct()
    {
        if (self::$bootstrap === null) {
            self::$bootstrap = $this->initializeFlow();
        }
        $this->objectManager = self::$bootstrap->getObjectManager();
        $this->setupSecurity();
        $this->setupPlaywright();
        
        // !!! You need to add the Site Package Key here, so that we are able to load the Fusion code properly.
        $this->setupFusionRendering('Site.Package.Key.Here');
    }

    /**
     * @return ObjectManagerInterface
     */
    public function getObjectManager(): ObjectManagerInterface
    {
        return $this->objectManager;
    }
}
```

## Loading CSS and JavaScript for the Styleguide

In your Fusion code, add the JavaScript and CSS of your page to the `Sandstorm.E2ETestTools:StyleguideStylesheets`
and `Sandstorm.E2ETestTools:StyleguideJavascripts` prototypes, e.g. in the following way:

```neosfusion
prototype(Sandstorm.E2ETestTools:StyleguideStylesheets) {
    headerAssets = PACKAGEKEY:Resources.HeaderAssets
}
```

> Additionally, the base URL needs to be configured correctly. This package sets it to "/" in the `Testing/Behat`
> context which will work in most cases out of the box.

# Running Behat Tests

> This is MANDATORY to read for everybody.
> We suggest that this section is COPIED to the readme of your project.

First, you need to start the **Playwright Server** on your development machine. For that, go to `e2e-testrunner`
in your Git Repo, and do:

```bash
npm install
node index.js
# now, the server is running on localhost:3000.
# Keep the server running as long as you want to execute Behavioral Tests. You can leave the server
# running for a very long time (e.g. a day).
```

Second, **ensure the docker containers are running**; usually by `docker-compose build && docker-compose up -d`.
Then, enter the `neos` container: `docker-compose exec neos /bin/bash` and run the following commands inside
the container:

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

In case of exceptions, it might be helpful to run the tests with `--stop-on-failure`, which stops the test cases at the first
error. Then, you can inspect the testing database and manually reproduce the bug.

Additionally, `-vvv` is a helpful CLI flag (extra-verbose) - this displays the full exception stack trace in case of errors.

**For hints how to write Behat tests, we suggest to read [Sandstorm.E2ETestTools README](./Packages/Application/Sandstorm.E2ETestTools/README.md).**

## Style Guide

If you use the Style Guide feature (`Then I store the Fusion output in the styleguide as "Button_Component_Basic"`), then
your tests need to be annotated with `@playwright` and the playwright dev server needs to be running.

You can then access the style guide using [127.0.0.1:8080/styleguide/](http://127.0.0.1:8080/styleguide/). The style guide
contains BOTH HTML snapshots; and rendered images of the HTML.

# Writing Behat Tests

Here, we try to give examples for common Behat scenarios; such that you can easily get started.

## Fusion Component Testcases

You can use a test case like the following for testing components - analogous to what you usually do with Monocle.

Some hints:

- We need to set up a minimal node tree, as otherwise we cannot render links.

```gherkin
@fixtures
@playwright
Feature: Testcase for Button Component

  Background:
    Given I have a site for Site Node "site"
    Given I have the following nodes:
      | Identifier                           | Path               | Node Type                | Properties                   | Language |
      | 5cb3a5f7-b501-40b2-b5a8-9de169ef1105 | /sites             | unstructured             | {}                           | de       |
      | 5e312d5b-9559-4bd2-8251-0182e11b4950 | /sites/site        | PACKAGEKEY:Document.Page | {}                           | de       |
      | 9cbaa2e2-d779-4936-aa02-0dab324da93e | /sites/site/nested | PACKAGEKEY:Document.Page | {"uriPathSegment": "nested"} | de       |


    Given I get a node by path "/sites/site" with the following context:
      | Workspace | Dimension: language |
      | live      | de                  |


  Scenario: Basic Button (external link)
    When I render the Fusion object "/testcase" with the current context node:
    """
    testcase = PACKAGEKEY:Component.Button {
      text = "External Link"
      link = "https://spiegel.de"
      isExternalLink = true
    }
    """
    Then in the fusion output, the inner HTML of CSS selector "a" matches "External Link"
    Then in the fusion output, the attributes of CSS selector "a" are:
      | Key    | Value              |
      | class  | button             |
      | href   | https://spiegel.de |
      | target | _blank             |
    Then I store the Fusion output in the styleguide as "Button_Component_Basic"
```


## Fusion Integration Testcases

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
      | Key    | Value                    |
      | href   | /de/nested               |

    Then I store the Fusion output in the styleguide as "Button_Integration_Secondary"

```

## Full-Page Snapshot Testcases

This tests a complete page rendering, and not just single components. It is meant mostly for visual checking; and most likely you'll work
less with specific assertions.

In this case, the rendering depends on many more nodes - so setting up the behat fixture with all the relevant nodes can be a bit tedious.
Luckily, there are helpers in this package to help with the process. We suggest writing a CommandController like the following:

```php
<?php

namespace PACKAGEKEY\Command;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Sandstorm\E2ETestTools\StepGenerator\NodeTable;

class StepGeneratorCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    public function homepageCommand()
    {
        $nodeTable = new NodeTable(['Language' => 'de']);
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
  ... many more nodes here in this table ...
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
    ... many more nodes here ...

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

### custom content dimension resolving based on host info

Let's say, your Neos project has a custom content dimension value resolver, f.e. by host name or subdomain.
The SUT base URL is configured statically via environment variable. But in the mentioned special case, you need
dynamic base URLs that are modified via your own custom steps.

The `PlaywrightConnector` has an API for that purpose:

public API: `PlaywrightTrait#setSystemUnderTestUrlModifier(\Closure $urlModifier): void`
delegates to internal: `PlaywrightConnector#setSystemUnderTestUrlModifier(\Closure $urlModifier): void`

You need to call that setter from your custom step, that could look like:

```php
...

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

Note, that the modifier is reset after each scenario.
