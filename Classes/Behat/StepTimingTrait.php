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
use Behat\Testwork\Tester\Result\TestResult;
use Neos\Utility\Files;

/**
 * Writes the duration of every step and scenario to step-timings.jsonl in the results directory, with the number of
 * Playwright bridge requests and the time spent in them - to find slow steps and to compare runs. Opt-in with
 * E2E_TIMINGS=1, otherwise the hooks do nothing.
 *
 * A step's time includes the hooks Behat runs between this trait's hooks (e.g. the trace group of PlaywrightTrait), so
 * compare runs with the same FeatureContext. The file is written anew by every Behat run.
 *
 * Needs {@see PlaywrightTrait} (for the results directory).
 */
trait StepTimingTrait
{
    private static bool $stepTimingFileReset = false;

    private ?int $stepTimingStepStart = null;

    private ?int $stepTimingScenarioStart = null;

    private int $stepTimingScenarioCalls = 0;

    private float $stepTimingScenarioMs = 0.0;

    #[BeforeScenario]
    public function stepTimingBeforeScenario(BeforeScenarioScope $event): void
    {
        if (!self::stepTimingEnabled()) {
            return;
        }
        if (!self::$stepTimingFileReset) {
            Files::createDirectoryRecursively($this->resultsDir);
            file_put_contents($this->stepTimingFile(), '');
            self::$stepTimingFileReset = true;
        }
        PlaywrightConnector::takeRequestStatistics();
        $this->stepTimingScenarioCalls = 0;
        $this->stepTimingScenarioMs = 0.0;
        $this->stepTimingScenarioStart = hrtime(true);
    }

    #[BeforeStep]
    public function stepTimingBeforeStep(BeforeStepScope $event): void
    {
        if (!self::stepTimingEnabled()) {
            return;
        }
        // requests of the hooks between two steps only count for the scenario
        $this->stepTimingBridgeStatistics(false);
        $this->stepTimingStepStart = hrtime(true);
    }

    #[AfterStep]
    public function stepTimingAfterStep(AfterStepScope $event): void
    {
        if (!self::stepTimingEnabled() || $this->stepTimingStepStart === null) {
            return;
        }
        $this->writeStepTiming([
            'type' => 'step',
            'feature' => basename($event->getFeature()->getFile() ?? ''),
            'line' => $event->getStep()->getLine(),
            'step' => $event->getStep()->getKeyword() . ' ' . $event->getStep()->getText(),
            'ms' => (hrtime(true) - $this->stepTimingStepStart) / 1e6,
            'result' => self::stepTimingResult($event->getTestResult()->getResultCode()),
        ] + $this->stepTimingBridgeStatistics(false));
        $this->stepTimingStepStart = null;
    }

    #[AfterScenario]
    public function stepTimingAfterScenario(AfterScenarioScope $event): void
    {
        if (!self::stepTimingEnabled() || $this->stepTimingScenarioStart === null) {
            return;
        }
        $this->writeStepTiming([
            'type' => 'scenario',
            'feature' => basename($event->getFeature()->getFile() ?? ''),
            'line' => $event->getScenario()->getLine(),
            'scenario' => $event->getScenario()->getTitle(),
            'ms' => (hrtime(true) - $this->stepTimingScenarioStart) / 1e6,
            'result' => self::stepTimingResult($event->getTestResult()->getResultCode()),
        ] + $this->stepTimingBridgeStatistics(true));
        $this->stepTimingScenarioStart = null;
    }

    private static function stepTimingEnabled(): bool
    {
        return in_array(getenv('E2E_TIMINGS'), ['1', 'true'], true);
    }

    private function stepTimingFile(): string
    {
        return $this->resultsDir . '/step-timings.jsonl';
    }

    /**
     * The requests since the last call - a scenario row sums up all of its requests, including the hooks around its steps.
     *
     * @return array{bridgeCalls: int, bridgeMs: float}
     */
    private function stepTimingBridgeStatistics(bool $wholeScenario): array
    {
        $statistics = PlaywrightConnector::takeRequestStatistics();
        $this->stepTimingScenarioCalls += $statistics['calls'];
        $this->stepTimingScenarioMs += $statistics['ms'];
        return $wholeScenario
            ? ['bridgeCalls' => $this->stepTimingScenarioCalls, 'bridgeMs' => $this->stepTimingScenarioMs]
            : ['bridgeCalls' => $statistics['calls'], 'bridgeMs' => $statistics['ms']];
    }

    private static function stepTimingResult(int $resultCode): string
    {
        return match ($resultCode) {
            TestResult::PASSED => 'passed',
            TestResult::SKIPPED => 'skipped',
            TestResult::PENDING => 'pending',
            TestResult::FAILED => 'failed',
            default => 'undefined',
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function writeStepTiming(array $row): void
    {
        file_put_contents($this->stepTimingFile(), json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
}
