<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Behat;

use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\BeforeStepScope;
use Behat\Behat\Tester\Result\StepResult;
use Behat\Gherkin\Node\FeatureNode;
use Behat\Gherkin\Node\ScenarioNode;
use Behat\Gherkin\Node\StepNode;
use Behat\Testwork\Environment\StaticEnvironment;
use Behat\Testwork\Suite\GenericSuite;
use Behat\Testwork\Tester\Result\TestResult;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Utility\Files;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Behat\PlaywrightTrait;
use Sandstorm\E2ETestTools\Behat\StepTimingTrait;

/**
 * The hooks with hand-made scopes - the bridge statistics stay 0, as no request is sent.
 */
class StepTimingTraitTest extends UnitTestCase
{
    private string|false $e2eTimings;

    private string $resultsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->e2eTimings = getenv('E2E_TIMINGS');
        $this->resultsDir = sys_get_temp_dir() . '/step-timing-test-' . bin2hex(random_bytes(4));
        StepTimingTraitTestContext::startNewRun();
    }

    protected function tearDown(): void
    {
        putenv($this->e2eTimings === false ? 'E2E_TIMINGS' : 'E2E_TIMINGS=' . $this->e2eTimings);
        if (is_dir($this->resultsDir)) {
            Files::removeDirectoryRecursively($this->resultsDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function withoutE2eTimingsNothingIsWritten(): void
    {
        putenv('E2E_TIMINGS');

        $this->runScenario(TestResult::PASSED);

        self::assertDirectoryDoesNotExist($this->resultsDir);
    }

    #[Test]
    public function withE2eTimingsEveryStepAndTheScenarioGetALine(): void
    {
        putenv('E2E_TIMINGS=1');

        $this->runScenario(TestResult::FAILED);

        $rows = array_map(
            static fn (string $line): mixed => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->resultsDir . '/step-timings.jsonl', FILE_IGNORE_NEW_LINES) ?: []
        );
        self::assertCount(2, $rows);
        [$step, $scenario] = $rows;
        self::assertIsArray($step);
        self::assertIsArray($scenario);
        self::assertSame(
            ['type' => 'step', 'feature' => 'Timing.feature', 'line' => 7, 'step' => 'When I wait', 'result' => 'failed', 'bridgeCalls' => 0],
            array_diff_key($step, ['ms' => 0, 'bridgeMs' => 0])
        );
        self::assertSame(
            ['type' => 'scenario', 'feature' => 'Timing.feature', 'line' => 6, 'scenario' => 'waiting', 'result' => 'failed', 'bridgeCalls' => 0],
            array_diff_key($scenario, ['ms' => 0, 'bridgeMs' => 0])
        );
        self::assertGreaterThanOrEqual($step['ms'], $scenario['ms']);
    }

    #[Test]
    public function aNewRunStartsANewFile(): void
    {
        putenv('E2E_TIMINGS=true');

        $this->runScenario(TestResult::PASSED);
        $this->runScenario(TestResult::PASSED);

        // the second scenario of the same run appends, a new run starts over
        self::assertCount(4, file($this->resultsDir . '/step-timings.jsonl') ?: []);
        StepTimingTraitTestContext::startNewRun();
        $this->runScenario(TestResult::PASSED);
        self::assertCount(2, file($this->resultsDir . '/step-timings.jsonl') ?: []);
    }

    private function runScenario(int $resultCode): void
    {
        $context = new StepTimingTraitTestContext($this->resultsDir);
        $environment = new StaticEnvironment(new GenericSuite('default', []));
        $step = new StepNode('When', 'I wait', [], 7);
        $scenario = new ScenarioNode('waiting', [], [$step], 'Scenario', 6);
        $feature = new FeatureNode('Timing', null, [], null, [$scenario], 'Feature', 'en', '/features/Timing.feature', 1);
        $stepResult = $this->createStub(StepResult::class);
        $stepResult->method('getResultCode')->willReturn($resultCode);
        $scenarioResult = $this->createStub(TestResult::class);
        $scenarioResult->method('getResultCode')->willReturn($resultCode);

        $context->stepTimingBeforeScenario(new BeforeScenarioScope($environment, $feature, $scenario));
        $context->stepTimingBeforeStep(new BeforeStepScope($environment, $feature, $step));
        $context->stepTimingAfterStep(new AfterStepScope($environment, $feature, $step, $stepResult));
        $context->stepTimingAfterScenario(new AfterScenarioScope($environment, $feature, $scenario, $scenarioResult));
    }
}

class StepTimingTraitTestContext
{
    use PlaywrightTrait;
    use StepTimingTrait;

    public function __construct(string $resultsDir)
    {
        $this->resultsDir = $resultsDir;
    }

    /**
     * The file is emptied once per Behat run - the run is the PHP process, so a test resets it.
     */
    public static function startNewRun(): void
    {
        self::$stepTimingFileReset = false;
    }
}
