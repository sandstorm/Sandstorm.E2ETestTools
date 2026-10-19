<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Behat;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Behat\DebuggingTrait;
use Sandstorm\E2ETestTools\Behat\PlaywrightConnector;
use Sandstorm\E2ETestTools\Behat\PlaywrightTrait;

/**
 * The pause itself needs a visible browser and can't run in CI - only its guard and the request are tested.
 */
class DebuggingTraitTest extends UnitTestCase
{
    #[Test]
    public function pausingWithAHeadlessBridgeFailsRightAway(): void
    {
        $connector = $this->createMock(PlaywrightConnector::class);
        $connector->method('isBridgeHeadless')->willReturn(true);
        $connector->expects(self::never())->method('execute');

        $this->expectExceptionCode(1728120002);
        (new DebuggingTraitTestContext($connector))->iPauseForDebugging();
    }

    #[Test]
    public function pausingWaitsWithoutTimeout(): void
    {
        $connector = $this->createMock(PlaywrightConnector::class);
        $connector->method('isBridgeHeadless')->willReturn(false);
        $connector->expects(self::once())->method('execute')->with('scenario', 'await vars.page.pause();', 0);

        (new DebuggingTraitTestContext($connector))->iPauseForDebugging();
    }

    #[Test]
    public function anOlderBridgeWithoutStatusPausesAnyway(): void
    {
        $connector = $this->createMock(PlaywrightConnector::class);
        $connector->method('isBridgeHeadless')->willReturn(null);
        $connector->expects(self::once())->method('execute');

        (new DebuggingTraitTestContext($connector))->iPauseForDebugging();
    }
}

class DebuggingTraitTestContext
{
    use PlaywrightTrait;
    use DebuggingTrait;

    public function __construct(PlaywrightConnector $connector)
    {
        $this->playwrightConnector = $connector;
        $this->playwrightContext = 'scenario';
    }
}
