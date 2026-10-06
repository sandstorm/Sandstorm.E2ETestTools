<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\BeforeStepScope;
use Behat\Hook\AfterScenario;
use Behat\Hook\AfterStep;
use Behat\Hook\BeforeScenario;
use Behat\Hook\BeforeStep;
use Behat\Step\Then;
use Behat\Testwork\Tester\Result\TestResult;
use Closure;
use Neos\Utility\Files;
use Sandstorm\E2ETestTools\Debugging\LogDirectory;

/**
 * This trait should be included in your `FeatureContext` for integration with Playwright.
 *
 * For each Scenario, we use an extra playwright BrowserContext, but we reuse the same Playwright instance; so we
 * do not close the browser between tests. This makes the system very fast.
 *
 * In case of errors, a screenshot is taken automatically. The Flow logs are cleared before every scenario and copied
 * into the results directory when it fails - with or without browser.
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
 * You can use $this->playwrightConnector->execute($this->requirePlaywrightContext(), 'your-playwright-script-here')
 * in your custom steps. Pass step parameters into the script with JsValue::of() (escaped JS literal).
 *
 *
 * EXAMPLE:
 *
 * $this->playwrightConnector->execute($this->requirePlaywrightContext(), "
 *     vars.page = await context.newPage();
 *     await vars.page.goto('BASEURL');
 * ");
 *
 *
 * $actualHeadlineContent = $this->playwrightConnector->execute($this->requirePlaywrightContext(), "
 *     return await vars.page.textContent('h2');
 * ");
 * Assert::assertEquals($headlineName, $actualHeadlineContent, 'Headlines do not match');
 */
trait PlaywrightTrait
{
    /**
     * Never write traces.
     */
    final public const PLAYWRIGHT_TRACING_MODE_OFF = 0;

    /**
     * Always write traces after a scenario.
     */
    final public const PLAYWRIGHT_TRACING_MODE_ALWAYS = 1;

    /**
     * Only write traces when the scenario failed (default).
     */
    final public const PLAYWRIGHT_TRACING_MODE_ON_ERROR = 2;

    /**
     * @deprecated use the constant self::PLAYWRIGHT_TRACING_MODE_OFF
     */
    protected static $PLAYWRIGHT_TRACING_MODE_OFF = self::PLAYWRIGHT_TRACING_MODE_OFF;

    /**
     * @deprecated use the constant self::PLAYWRIGHT_TRACING_MODE_ALWAYS
     */
    protected static $PLAYWRIGHT_TRACING_MODE_ALWAYS = self::PLAYWRIGHT_TRACING_MODE_ALWAYS;

    /**
     * @deprecated use the constant self::PLAYWRIGHT_TRACING_MODE_ON_ERROR
     */
    protected static $PLAYWRIGHT_TRACING_MODE_ON_ERROR = self::PLAYWRIGHT_TRACING_MODE_ON_ERROR;

    protected PlaywrightConnector $playwrightConnector;

    protected ?string $playwrightContext = null;

    protected string $resultsDir = 'e2e-results';

    private int $playwrightTracingMode = self::PLAYWRIGHT_TRACING_MODE_ON_ERROR;

    /**
     * Data/Logs by default; null in projects without Flow.
     */
    private ?string $flowLogsDirectory = null;

    private bool $flowLogsDirectoryConfigured = false;

    /**
     * Where the site under test writes its Flow logs, as seen from the Behat process - only needed when they don't
     * share Data/Logs (e.g. a separate container with the logs mounted somewhere else). null switches clearing and
     * copying off - for example when the development site writes into the same directory.
     */
    protected final function setFlowLogsDirectory(?string $directory): void
    {
        $this->flowLogsDirectory = $directory;
        $this->flowLogsDirectoryConfigured = true;
    }

    /**
     * When traces are written: self::PLAYWRIGHT_TRACING_MODE_ON_ERROR (default), _ALWAYS or _OFF.
     */
    protected final function setPlaywrightTracingMode(int $mode): void
    {
        $this->playwrightTracingMode = $mode;
    }

    /**
     * @param ?string $resultsDir Where screenshots/error-screenshots/trace zips get written (relative to CWD).
     *   Defaults to "e2e-results". To drive it from your configuration (e.g. Settings.yaml), resolve it before
     *   calling setupPlaywright() and pass it in here.
     */
    public function setupPlaywright(?string $resultsDir = null): void
    {
        PhpUnitAssertions::enableFailureMessages();
        if ($resultsDir !== null) {
            $this->resultsDir = $resultsDir;
        }

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

            For running the tests locally, this should be e.g. "http://127.0.0.1:9090" (as playwright is running on the host, and this port is where
            the system under test is exposed)');
        }


        $this->playwrightConnector = new PlaywrightConnector($playwrightApiUrl, $systemUnderTestUrl, $this->resultsDir);
        if (!$this->flowLogsDirectoryConfigured && defined('FLOW_PATH_DATA')) {
            $this->flowLogsDirectory = FLOW_PATH_DATA . 'Logs';
        }
    }

    /**
     * Changes the SUT base URL (BASEURL in scripts) for the current scenario - it's reset before the next one.
     */
    public function setSystemUnderTestUrlModifier(?Closure $urlModifier): void
    {
        $this->playwrightConnector->setSystemUnderTestUrlModifier($urlModifier);
    }

    #[BeforeScenario('@playwright')]
    public function playwrightBeforeScenario(BeforeScenarioScope $event): void
    {
        $this->playwrightContext = (string)preg_replace('/[^a-zA-Z0-9_]/', '', basename($event->getFeature()->getFile()) . '_' . $event->getScenario()->getTitle());
        $this->playwrightConnector->stopContext($this->playwrightContext);
        $this->playwrightConnector->setSystemUnderTestUrlModifier(null);
        if ($this->playwrightTracingMode !== self::PLAYWRIGHT_TRACING_MODE_OFF) {
            $this->playwrightConnector->startTracing(
                $this->playwrightContext,
                $event->getFeature()->getFile(),
                $event->getScenario()->getTitle(),
                $event->getScenario()->getLine());
        }
    }

    #[AfterScenario('@playwright')]
    public function playwrightAfterScenario(AfterScenarioScope $event): void
    {
        if ($this->playwrightContext && $this->playwrightTracingMode !== self::PLAYWRIGHT_TRACING_MODE_OFF) {
            $keepTrace = ($this->playwrightTracingMode === self::PLAYWRIGHT_TRACING_MODE_ON_ERROR && $event->getTestResult()->getResultCode() === TestResult::FAILED)
                || $this->playwrightTracingMode === self::PLAYWRIGHT_TRACING_MODE_ALWAYS;
            $this->playwrightConnector->finishTracing(
                $this->playwrightContext,
                $event->getFeature()->getFile(),
                $event->getScenario()->getTitle(),
                $event->getScenario()->getLine(),
                $keepTrace
            );
        }
    }

    #[BeforeScenario]
    public function clearFlowLogsBeforeScenario(): void
    {
        if ($this->flowLogsDirectory !== null) {
            (new LogDirectory($this->flowLogsDirectory))->clear();
        }
    }

    /**
     * An exception in the site under test only shows up as an error page in the browser - the stack trace is in the
     * logs, so they're put next to the error screenshot.
     */
    #[AfterScenario]
    public function copyFlowLogsOfFailedScenario(AfterScenarioScope $event): void
    {
        if ($this->flowLogsDirectory === null || $event->getTestResult()->getResultCode() !== TestResult::FAILED) {
            return;
        }
        $target = sprintf(
            '%s/logs_%s',
            $this->resultsDir,
            preg_replace('/[^a-zA-Z0-9_]/', '', basename($event->getFeature()->getFile()) . '_' . $event->getScenario()->getLine() . '_' . $event->getScenario()->getTitle())
        );
        if ((new LogDirectory($this->flowLogsDirectory))->copyTo($target) !== []) {
            echo sprintf("You can find the log entries of this scenario in %s\n", $target);
        }
    }

    #[BeforeStep]
    public function playwrightBeforeStep(BeforeStepScope $event): void
    {
        if ($this->playwrightContext) {
            $this->playwrightConnector->setStepForDebugging($this->playwrightContext, $event->getStep()->getText());
        }
    }

    #[AfterStep]
    public function playwrightAfterStep(AfterStepScope $event): void
    {
        if ($this->playwrightContext && $event->getTestResult()->getResultCode() === TestResult::FAILED) {
            $errorScreenshotFileName = (string)preg_replace('/[^a-zA-Z0-9_]/', '', basename($event->getFeature()->getFile()) . '_' . $event->getStep()->getText());

            // NOTE: intentionally no "path" option here - the resulting buffer is returned to PHP
            // and written to $resultsDir below. Passing "path" would make Playwright *also* write
            // the file itself, relative to the bridge (Node) process's own CWD - i.e. a second,
            // un-configurable copy outside of $resultsDir.
            $base64Image = $this->playwrightConnector->execute($this->playwrightContext, '
                if (vars && vars.page) {
                    const buffer = await vars.page.screenshot({fullPage: true});
                    return buffer.toString("base64");
                }
                return "";
            ');
            if (is_string($base64Image) && $base64Image !== '') {
                $image = base64_decode($base64Image);
                Files::createDirectoryRecursively($this->resultsDir);
                file_put_contents(sprintf('%s/error_%s.png', $this->resultsDir, $errorScreenshotFileName), $image);
                echo sprintf("You can find the file error_%s.png in %s\n", $errorScreenshotFileName, $this->resultsDir);
            }
        }
    }

    private function requirePlaywrightContext(): string
    {
        if ($this->playwrightContext === null) {
            throw new \RuntimeException('No active Playwright context. Did you forget the @playwright tag on this scenario?');
        }
        return $this->playwrightContext;
    }

    /**
     * Closes the scenario's browser context (after the trace was written).
     */
    #[AfterScenario('@playwright')]
    public function ensurePlaywrightIsRunning(): void
    {
        if ($this->playwrightContext !== null) {
            $this->playwrightConnector->stopContext($this->playwrightContext);
            $this->playwrightContext = null;
        }
    }

    /**
     * Prints the Playwright script of the scenario so far.
     */
    #[Then('I debug the playwright script')]
    public function iDebugThePlaywrightScript(): void
    {
        $js = $this->playwrightConnector->getCurrentJsCode($this->requirePlaywrightContext());
        echo $js;

        // we flush the output here so that we do not have it wrapped in another block; but it's directly copy/pastable
        if (ob_get_level() > 0) {
            ob_flush();
        }
    }

    /**
     * The file name is relative to the results directory.
     */
    #[Then('I do a screenshot :filename')]
    public function iDoAScreenshot(string $filename): void
    {
        // NOTE: intentionally no "path" option here - see the comment in playwrightAfterStep().
        $base64Image = $this->playwrightConnector->execute($this->requirePlaywrightContext(), '
                const buffer = await vars.page.screenshot({fullPage: true});
                return buffer.toString("base64");
            ');
        $image = base64_decode((string)$base64Image);
        Files::createDirectoryRecursively(dirname($this->resultsDir . '/' . $filename));
        file_put_contents($this->resultsDir . '/' . $filename, $image);
    }
}
