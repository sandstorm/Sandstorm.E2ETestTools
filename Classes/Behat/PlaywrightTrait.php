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
use Behat\Step\When;
use Behat\Testwork\Tester\Result\TestResult;
use Closure;
use Neos\Utility\Files;
use Sandstorm\E2ETestTools\Debugging\LogDirectory;
use Sandstorm\E2ETestTools\Playwright\JsCode;
use Sandstorm\E2ETestTools\Playwright\JsValue;

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
 * In your custom steps, run scripts with $this->playwrightRun('your-playwright-script-here', ...$values) - every %s
 * becomes the next value as escaped JS literal (JsValue), so step parameters can't break the script. Wrap JavaScript
 * the step builds itself (a locator, a matcher) in JsCode::of(). playwrightArray() and playwrightStrings() check the
 * type of what the script returned.
 *
 *
 * EXAMPLE:
 *
 * $this->playwrightRun('
 *     vars.page = await context.newPage();
 *     await vars.page.goto("BASEURL" + %s);
 * ', $uriPath);
 *
 *
 * Assertions: Playwright's web-first assertions (`expect`) wait until the page is in the expected state and fail with
 * a readable message - read values into PHP only for what expect can't check:
 *
 * $this->playwrightRun('await expect(vars.page.getByRole("heading", {level: 2})).toHaveText(%s, {timeout: %s});', $headline, $this->playwrightTimeout);
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
     * The failure hint of {@see playwrightRunOnVisible()} - an async JS function (matches, visible, error) returning
     * the text to append, or "". Counted only when a step fails.
     */
    private const PLAYWRIGHT_VISIBILITY_HINT = <<<'JS'
        async (matches, visible, error) => {
            if (error.message.includes('strict mode violation')) {
                // opacity: 0 on the element or a parent, sr-only (1 x 1 px) or positioned outside the page
                const unseen = await visible.evaluateAll((elements) => elements.some((element) => {
                    for (let current = element; current; current = current.parentElement) {
                        const style = getComputedStyle(current);
                        const box = current.getBoundingClientRect();
                        if (style.opacity === '0' || (box.width <= 1 && box.height <= 1 && style.overflow !== 'visible')) {
                            return true;
                        }
                    }
                    const box = element.getBoundingClientRect();
                    return box.right + scrollX <= 0 || box.bottom + scrollY <= 0
                        || box.left + scrollX >= document.documentElement.scrollWidth;
                }));
                return unseen
                    ? '\nSeveral visible matches - at least one is hidden by opacity: 0, off-screen positioning or sr-only (on itself or a parent); Playwright counts these as visible.'
                    : '';
            }
            const visibleCount = await visible.count();
            const hiddenCount = await matches.count() - visibleCount;
            return visibleCount === 0 && hiddenCount > 0
                ? `\nNo visible match - found ${hiddenCount} hidden match${hiddenCount === 1 ? '' : 'es'}.`
                : '';
        }
    JS;

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

    /**
     * How long the assertions of the steps wait for the expected state, in milliseconds - see setPlaywrightTimeout().
     */
    protected int $playwrightTimeout = 5000;

    private int $playwrightTracingMode = self::PLAYWRIGHT_TRACING_MODE_ON_ERROR;

    /**
     * Whether the current scenario records a trace - each step then opens a group in it.
     */
    private bool $playwrightTracing = false;

    private bool $playwrightStepGroupOpen = false;

    /**
     * The bridge is checked once per Behat run.
     */
    private static bool $playwrightBridgeChecked = false;

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
     * How long the assertions of the shipped steps wait for the expected state (default: 5000 ms). Raise it for a slow
     * system under test, e.g. in CI.
     */
    protected final function setPlaywrightTimeout(int $milliseconds): void
    {
        $this->playwrightTimeout = $milliseconds;
    }

    /**
     * When traces are written: self::PLAYWRIGHT_TRACING_MODE_ON_ERROR (default), _ALWAYS or _OFF.
     * The environment variable E2E_TRACE=on-error|always|off overrides it for a single run.
     */
    protected final function setPlaywrightTracingMode(int $mode): void
    {
        $this->playwrightTracingMode = $mode;
    }

    /**
     * The tracing mode of this run: E2E_TRACE if set, otherwise the one from setPlaywrightTracingMode().
     */
    protected final function getPlaywrightTracingMode(): int
    {
        $mode = getenv('E2E_TRACE');
        return match ($mode) {
            false, '' => $this->playwrightTracingMode,
            'on-error' => self::PLAYWRIGHT_TRACING_MODE_ON_ERROR,
            'always' => self::PLAYWRIGHT_TRACING_MODE_ALWAYS,
            'off' => self::PLAYWRIGHT_TRACING_MODE_OFF,
            default => throw new \RuntimeException(
                sprintf('E2E_TRACE must be "on-error", "always" or "off" - got "%s".', $mode),
                1728000001
            ),
        };
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
        $this->playwrightStepGroupOpen = false;
        if (!self::$playwrightBridgeChecked) {
            // older copies of the bridge hand the scripts only "context" and "vars" - the steps need "expect"
            if ($this->playwrightConnector->execute($this->playwrightContext, 'return typeof expect;') !== 'function') {
                throw new \RuntimeException(
                    'The playwright-bridge is too old: its scripts get no "expect". Update your copy from Sandstorm.E2ETestTools/Templates/playwright-bridge - see the CHANGELOG of Sandstorm.E2ETestTools.',
                    1728000002
                );
            }
            self::$playwrightBridgeChecked = true;
        }
        $this->playwrightTracing = $this->getPlaywrightTracingMode() !== self::PLAYWRIGHT_TRACING_MODE_OFF;
        if ($this->playwrightTracing) {
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
        if ($this->playwrightContext && $this->playwrightTracing) {
            $mode = $this->getPlaywrightTracingMode();
            $keepTrace = ($mode === self::PLAYWRIGHT_TRACING_MODE_ON_ERROR && $event->getTestResult()->getResultCode() === TestResult::FAILED)
                || $mode === self::PLAYWRIGHT_TRACING_MODE_ALWAYS;
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
        if (!$this->playwrightContext) {
            return;
        }
        $stepText = $event->getStep()->getKeyword() . ' ' . $event->getStep()->getText();
        if ($this->playwrightTracing) {
            $this->playwrightConnector->startStepGroup($this->playwrightContext, $stepText);
            $this->playwrightStepGroupOpen = true;
        } else {
            $this->playwrightConnector->setStepForDebugging($this->playwrightContext, $stepText);
        }
    }

    #[AfterStep]
    public function playwrightAfterStep(AfterStepScope $event): void
    {
        try {
            $this->takeErrorScreenshotOfFailedStep($event);
        } finally {
            // after the error screenshot, so it's part of the step's group
            if ($this->playwrightContext && $this->playwrightStepGroupOpen) {
                $this->playwrightStepGroupOpen = false;
                $this->playwrightConnector->endStepGroup($this->playwrightContext);
            }
        }
    }

    private function takeErrorScreenshotOfFailedStep(AfterStepScope $event): void
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
     * Runs the script in the browser context of the scenario and returns what it returned. Every %s (or %1$s, ...) in
     * $script is replaced by the next value as JS literal - a {@see JsCode} as it is. Without values, the script is
     * sent unchanged (a % in it needs no escaping then).
     */
    protected function playwrightRun(string $script, mixed ...$values): mixed
    {
        return $this->playwrightConnector->execute($this->requirePlaywrightContext(), self::playwrightScript($script, ...$values));
    }

    /**
     * Like {@see playwrightRun()}, for scripts that return an object or array, e.g. `return {page: "...", notice: "..."};`
     *
     * @return array<mixed>
     */
    protected function playwrightArray(string $script, mixed ...$values): array
    {
        $result = $this->playwrightRun($script, ...$values);
        if (!is_array($result)) {
            throw new \UnexpectedValueException(sprintf('The Playwright script returned %s instead of an array.', get_debug_type($result)));
        }
        return $result;
    }

    /**
     * Like {@see playwrightRun()}, for scripts that return a list, e.g. `return [...new Set(hosts)];` - the entries as
     * strings.
     *
     * @return list<string>
     */
    protected function playwrightStrings(string $script, mixed ...$values): array
    {
        return array_values(array_map(static fn (mixed $value): string => self::playwrightString($value), $this->playwrightArray($script, ...$values)));
    }

    /**
     * Like {@see playwrightRun()}, on the copy of an element the visitor sees: in $script, `visible` is $locator (JS,
     * e.g. `vars.page.getByLabel("Name", {exact: true})`) filtered to visible elements. Markup that renders a control
     * twice (desktop and mobile, one of them hidden) works; two visible matches still fail in Playwright's strict mode.
     *
     * A failure says why nothing or too much matched: hidden matches, or visible matches the visitor doesn't see
     * (Playwright counts opacity: 0, off-screen and sr-only elements as visible).
     */
    protected function playwrightRunOnVisible(string $locator, string $script, mixed ...$values): mixed
    {
        return $this->playwrightRun(<<<'JS'
            const matches = %1$s;
            const visible = matches.filter({visible: true});
            try {
                %2$s
            } catch (error) {
                throw new Error(error.message + await (%3$s)(matches, visible, error));
            }
        JS,
            JsCode::of($locator),
            JsCode::of(self::playwrightScript($script, ...$values)),
            JsCode::of(self::PLAYWRIGHT_VISIBILITY_HINT)
        );
    }

    /**
     * A value of a script result as string - for assertions and failure messages.
     */
    protected static function playwrightString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value) || $value === null) {
            return var_export($value, true);
        }
        return (string)json_encode($value);
    }

    /**
     * The script {@see playwrightRun()} sends.
     */
    protected static function playwrightScript(string $script, mixed ...$values): string
    {
        if ($values === []) {
            return $script;
        }
        return sprintf($script, ...array_map(
            static fn (mixed $value): string => $value instanceof JsCode ? $value->code : JsValue::of($value),
            $values
        ));
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
    #[When('I debug the Playwright script')]
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
    #[When('I take a screenshot :filename')]
    public function iTakeAScreenshot(string $filename): void
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
