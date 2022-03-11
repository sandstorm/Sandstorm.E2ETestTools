<?php

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Behat\Testwork\Tester\Result\TestResult;
use Closure;
use Neos\Utility\Files;

/**
 * This trait is useful both for Symfony and for Neos.
 *
 * This trait should be included in your `FeatureContext` for integration with Playwright.
 *
 * For each Scenario, we use an extra playwright BrowserContext, but we reuse the same Playwright instance; so we
 * do not close the browser between tests. This makes the system very fast.
 *
 * In case of errors, a screenshot is taken automatically.
 *
 *
 * SET UP:
 *
 * 1) include the trait in your FeatureContext
 * 2) call `setupPlaywright` in the constructor.
 *   This needs the PLAYWRIGHT_API_URL and SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT environment variables defined.
 *
 *
 * USAGE:
 *
 * You can use $this->playwrightConnector->execute($this->playwrightContext, 'your-playwright-script-here')
 * in your custom scenarios.
 *
 *
 * EXAMPLE:
 *
 * $this->playwrightConnector->execute($this->playwrightContext, "
 *     vars.page = await context.newPage();
 *     await vars.page.goto('BASEURL');
 * ");
 *
 *
 * $actualHeadlineContent = $this->playwrightConnector->execute($this->playwrightContext, "
 *     return await vars.page.textContent('h2');
 * ");
 * Assert::assertEquals($headlineName, $actualHeadlineContent, 'Headlines do not match');
 */
trait PlaywrightTrait
{

    /**
     * Never write traces.
     */
    protected static $PLAYWRIGHT_TRACING_MODE_OFF = 0;

    /**
     * Always write traces after a scenario.
     */
    protected static $PLAYWRIGHT_TRACING_MODE_ALWAYS = 1;

    /**
     * Only write error traces when the scenario failed.
     */
    protected static $PLAYWRIGHT_TRACING_MODE_ON_ERROR = 2;

    /**
     * @var PlaywrightConnector
     */
    protected $playwrightConnector;

    protected ?string $playwrightContext = null;

    // on_error per default
    private int $playwrightTracingMode = 2;

    /**
     * This is the programmatic API, env var 'PLAYWRIGHT_TRACE_MODE' TODO !!!
     * @param int $mode
     */
    protected final function setPlaywrightTracingMode(int $mode)
    {
        $this->playwrightTracingMode = $mode;
    }

    public function setupPlaywright()
    {
        // Playwright API URL, as seen from the perspective of the Behat test runner (inside the Docker container).
        $playwrightApiUrl = getenv('PLAYWRIGHT_API_URL');
        if (empty($playwrightApiUrl)) {
            throw new \RuntimeException('!!! PLAYWRIGHT_API_URL missing.

            This is the Playwright API URL, as seen from the perspective of the Behat test runner (inside the Docker container).

            For running the tests locally, this should be "http://host.docker.internal:3000" (as playwright is running on the HOST system)');
        }


        // System under Test URL, as seen from Playwright.
        $systemUnderTestUrl = getenv('SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT');

        if (empty($systemUnderTestUrl)) {
            throw new \RuntimeException('!!! SYSTEM_UNDER_TEST_URL_FOR_PLAYWRIGHT missing.

            This is the System under Test URL, as seen from the perspective of Playwright.

            For running the tests locally, this should be "http://127.0.0.1:8081" (as playwright is running on the host, and this port is where
            the application is exposed');
        }


        $this->playwrightConnector = new PlaywrightConnector($playwrightApiUrl, $systemUnderTestUrl);
    }

    /**
     * @param ?Closure $urlModifier
     */
    public function setUrlModifier(?Closure $urlModifier): void {
        $this->playwrightConnector->setUrlModifier($urlModifier);
    }

    /**
     * @BeforeScenario @playwright
     */
    public function playwrightBeforeScenario(\Behat\Behat\Hook\Scope\BeforeScenarioScope $event): void
    {
        $this->playwrightContext = (string)preg_replace('/[^a-zA-Z_]/', '', basename($event->getFeature()->getFile()) . '_' . $event->getScenario()->getTitle());
        $this->playwrightConnector->stopContext($this->playwrightContext);
        if ($this->playwrightTracingMode !== self::$PLAYWRIGHT_TRACING_MODE_OFF) {
            $this->playwrightConnector->startTracing(
                $this->playwrightContext,
                $event->getFeature()->getFile(),
                $event->getScenario()->getTitle(),
                $event->getScenario()->getLine());
        }
    }

    /**
     * @AfterScenario @playwright
     */
    public function playwrightAfterScenario(\Behat\Behat\Hook\Scope\AfterScenarioScope $event): void
    {
        if ($this->playwrightContext && $this->playwrightTracingMode !== self::$PLAYWRIGHT_TRACING_MODE_OFF) {
            $keepTrace = ($this->playwrightTracingMode === self::$PLAYWRIGHT_TRACING_MODE_ON_ERROR && $event->getTestResult()->getResultCode() === TestResult::FAILED)
                || $this->playwrightTracingMode === self::$PLAYWRIGHT_TRACING_MODE_ALWAYS;
            $this->playwrightConnector->finishTracing(
                $this->playwrightContext,
                $event->getFeature()->getFile(),
                $event->getScenario()->getTitle(),
                $event->getScenario()->getLine(),
                $keepTrace
            );
        }
    }

    /**
     * @BeforeStep
     */
    public function playwrightBeforeStep(\Behat\Behat\Hook\Scope\BeforeStepScope $event): void
    {
        if ($this->playwrightContext) {
            $this->playwrightConnector->setStepForDebugging($this->playwrightContext, $event->getStep()->getText());
        }
    }

    /**
     * @AfterStep
     */
    public function playwrightAfterStep(\Behat\Behat\Hook\Scope\AfterStepScope $event): void
    {
        if ($this->playwrightContext && $event->getTestResult()->getResultCode() === TestResult::FAILED) {
            $errorScreenshotFileName = (string)preg_replace('/[^a-zA-Z_]/', '', basename($event->getFeature()->getFile()) . '_' . $event->getStep()->getText());

            // TODO: make "page" a specific API
            $base64Image = $this->playwrightConnector->execute($this->playwrightContext, sprintf('
                if (vars && vars.page) {
                    const buffer = await vars.page.screenshot({path: "error_%s.png", fullPage: true});
                    return buffer.toString("base64");
                }
                return "";
            ', $errorScreenshotFileName));
            if (strlen($base64Image)) {
                $image = base64_decode($base64Image);
                Files::createDirectoryRecursively('e2e-results');
                file_put_contents(sprintf('e2e-results/error_%s.png', $errorScreenshotFileName), $image);
                echo sprintf("You can find the file error_%s.png BOTH in the current PHP execution directory (where you started the tests from),\n", $errorScreenshotFileName);
                echo "and as well in the e2e-testrunner/ folder.";
            }
        }
    }

    /**
     * @AfterScenario @playwright
     */
    public function ensurePlaywrightIsRunning($event): void
    {
        if ($this->playwrightContext !== null) {
            $this->playwrightConnector->stopContext($this->playwrightContext);
            $this->playwrightContext = null;
        }
    }

    /**
     * @Then I debug the playwright script
     */
    public function iDebugThePlaywrightScript()
    {
        $js = $this->playwrightConnector->getCurrentJsCode($this->playwrightContext);
        echo $js;

        // we flush the output here so that we do not have it wrapped in another block; but it's directly copy/pastable
        ob_flush();
    }

    /**
     * @Then I do a screenshot :filename
     */
    public function iDoAScreenshot($filename)
    {
        // TODO: make "page" a specific API
        $base64Image = $this->playwrightConnector->execute($this->playwrightContext, sprintf('
                const buffer = await vars.page.screenshot({path: "%s", fullPage: true});
                return buffer.toString("base64");
            ', $filename));
        $image = base64_decode($base64Image);
        Files::createDirectoryRecursively('e2e-results');
        file_put_contents('e2e-results/' . $filename, $image);
    }
}
