# End-To-End Test Tools

... for Symfony projects

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
- [Running Behat Tests](#running-behat-tests)

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
composer require --dev sandstorm/e2etesttools @dev
composer require --dev friends-of-behat/symfony-extension:^2.0 -W

APP_ENV=test vendor/bin/behat
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

Every related service (like redis, database, ...) needs to be started using a `services` entry. Ensure the Docker image
version of the service matches the development and production image from `docker-compose.yml`.

The *environment variables* of the job are passed on to *all services* - so all connected services and the main job
share the same environment variables. Thus, you need to add the environment variables for BOTH the SUT (which is the
main job) and all related services to the `variables` section of the test job.

```yaml
.... TODO FIGURE THIS OUT FOR SYMFONY ....
```

## Creating a FeatureContext

The `FeatureContext` is the PHP class containing the step definitions for the Behat scenarios.
We provide base traits you should use for various functionality. The skeleton of the `FeatureContext`
should look as follows:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Behat\Context\Context;
use Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap\PlaywrightTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;


class FeatureContext implements Context
{
    // Browser Automation
    use PlaywrightTrait;

    /** @var KernelInterface */
    private $kernel;

    /** @var Response|null */
    private $response;

    public function __construct(KernelInterface $kernel)
    {
        $this->kernel = $kernel;
        $this->setupPlaywright();
    }

    /**
     * @When a demo scenario sends a request to :path
     */
    public function aDemoScenarioSendsARequestTo(string $path): void
    {
        // language=js
        $this->playwrightConnector->execute($this->playwrightContext,"
            vars.page = await context.newPage();
            await vars.page.goto('BASEURL');
        ");
    }
}
```

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
... TODO ...
```

Alternatively, you can also run the tests locally on your  machine by using:

```bash
# !!! on your local machine, testing an application served by "symfony serve"
PLAYWRIGHT_API_URL=http://127.0.0.1:3000 SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT=http://127.0.0.1:8000 APP_ENV=test vendor/bin/behat
```

Behat also supports running single tests or single files - they need to be specified after the config file, e.g.

```bash

# run all scenarios in a given folder
vendor/bin/behat Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/Features/Fusion/

# run all scenarios in the single feature file
vendor/bin/behat Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/Features/WebsiteRendering.feature

# run the scenario starting at line 27
vendor/bin/behat Packages/Sites/[SITEPACKAGE_NAME]/Tests/Behavior/Features/WebsiteRendering.feature:27
```

In case of exceptions, it might be helpful to run the tests with `--stop-on-failure`, which stops the test cases at the first
error. Then, you can inspect the testing database and manually reproduce the bug.

Additionally, `-vvv` is a helpful CLI flag (extra-verbose) - this displays the full exception stack trace in case of errors.

**For hints how to write Behat tests, we suggest to read [Sandstorm.E2ETestTools README](./Packages/Application/Sandstorm.E2ETestTools/README.md).**
