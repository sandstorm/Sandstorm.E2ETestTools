<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Behat;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Behat\PlaywrightTrait;

/**
 * Tracing itself needs the bridge and is covered by the E2E suite - only the choice of the mode is tested.
 */
class PlaywrightTraitTest extends UnitTestCase
{
    private string|false $e2eTrace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->e2eTrace = getenv('E2E_TRACE');
        putenv('E2E_TRACE');
    }

    protected function tearDown(): void
    {
        putenv($this->e2eTrace === false ? 'E2E_TRACE' : 'E2E_TRACE=' . $this->e2eTrace);
        parent::tearDown();
    }

    #[Test]
    public function withoutE2eTraceTheConfiguredModeIsUsed(): void
    {
        $context = $this->contextWithTracingMode(PlaywrightTraitTestContext::PLAYWRIGHT_TRACING_MODE_ALWAYS);

        self::assertSame(PlaywrightTraitTestContext::PLAYWRIGHT_TRACING_MODE_ALWAYS, $context->tracingMode());
    }

    #[Test]
    public function anEmptyE2eTraceCountsAsUnset(): void
    {
        putenv('E2E_TRACE=');
        $context = $this->contextWithTracingMode(PlaywrightTraitTestContext::PLAYWRIGHT_TRACING_MODE_OFF);

        self::assertSame(PlaywrightTraitTestContext::PLAYWRIGHT_TRACING_MODE_OFF, $context->tracingMode());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function e2eTraceValues(): array
    {
        return [
            'on-error' => ['on-error', PlaywrightTraitTestContext::PLAYWRIGHT_TRACING_MODE_ON_ERROR],
            'always' => ['always', PlaywrightTraitTestContext::PLAYWRIGHT_TRACING_MODE_ALWAYS],
            'off' => ['off', PlaywrightTraitTestContext::PLAYWRIGHT_TRACING_MODE_OFF],
        ];
    }

    #[Test]
    #[DataProvider('e2eTraceValues')]
    public function e2eTraceWinsOverTheConfiguredMode(string $e2eTrace, int $expectedMode): void
    {
        putenv('E2E_TRACE=' . $e2eTrace);
        // a mode different from every expected one, so the configured mode can't pass by accident
        $context = $this->contextWithTracingMode(-1);

        self::assertSame($expectedMode, $context->tracingMode());
    }

    #[Test]
    public function anUnknownE2eTraceFails(): void
    {
        putenv('E2E_TRACE=sometimes');
        $context = $this->contextWithTracingMode(PlaywrightTraitTestContext::PLAYWRIGHT_TRACING_MODE_ON_ERROR);

        $this->expectExceptionCode(1728000001);
        $context->tracingMode();
    }

    private function contextWithTracingMode(int $mode): PlaywrightTraitTestContext
    {
        $context = new PlaywrightTraitTestContext();
        $context->configureTracingMode($mode);
        return $context;
    }
}

/**
 * Exposes the protected API of the trait.
 */
class PlaywrightTraitTestContext
{
    use PlaywrightTrait;

    public function configureTracingMode(int $mode): void
    {
        $this->setPlaywrightTracingMode($mode);
    }

    public function tracingMode(): int
    {
        return $this->getPlaywrightTracingMode();
    }
}
