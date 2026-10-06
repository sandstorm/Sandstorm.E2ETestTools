<?php

declare(strict_types=1);

use Behat\Behat\Context\Context;
use Behat\Hook\BeforeScenario;
use Neos\Behat\FlowBootstrapTrait;
use Neos\Behat\FlowEntitiesTrait;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Sandstorm\E2ETestTools\Behat\BrowserStateTrait;
use Sandstorm\E2ETestTools\Behat\DebuggingTrait;
use Sandstorm\E2ETestTools\Behat\FormInteractionTrait;
use Sandstorm\E2ETestTools\Behat\FusionRenderingTrait;
use Sandstorm\E2ETestTools\Behat\NeosBackendControlTrait;
use Sandstorm\E2ETestTools\Behat\NodeImportTrait;
use Sandstorm\E2ETestTools\Behat\PageAssertionsTrait;
use Sandstorm\E2ETestTools\Behat\PlaywrightTrait;
use Sandstorm\E2ETestTools\Behat\WireMockTrait;

/**
 * The package's own E2E suite, against the test site of the development distribution (Tests/SystemUnderTest/). Uses every
 * shipped trait, like a project's FeatureContext (see Templates/FeatureContext.php.default).
 */
class FeatureContext implements Context
{
    use FlowBootstrapTrait;
    use FlowEntitiesTrait;
    use PlaywrightTrait;
    use FusionRenderingTrait;
    use NeosBackendControlTrait;
    use NodeImportTrait;
    use PageAssertionsTrait;
    use FormInteractionTrait;
    use BrowserStateTrait;
    use DebuggingTrait;
    use WireMockTrait;

    // only for testing the fixture export of this package
    use FixtureExportTrait;

    public function __construct()
    {
        self::bootstrapFlow();
        $this->setupPlaywright();
        $this->setupFusionRendering('Sandstorm.E2ETestTools.TestSite');
        // the test site's weather API (WEATHER_API_URL in the system under test)
        $this->setupWireMock(getenv('WIREMOCK_ADMIN_URL') ?: 'http://wiremock:8080', [
            'weather' => ['fixtures' => __DIR__ . '/../WireMock/weather', 'pathPrefix' => '/weather-api'],
        ]);
    }

    public function getObjectManager(): ObjectManagerInterface
    {
        return self::$bootstrap->getObjectManager();
    }

    #[BeforeScenario('@flowEntities')]
    public function beforeFixturesScenario(): void
    {
        $this->setupContentRepository();
    }
}
