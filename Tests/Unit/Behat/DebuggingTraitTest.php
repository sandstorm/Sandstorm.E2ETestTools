<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Behat;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Behat\DebuggingTrait;

/**
 * The pause itself needs a visible browser and can't run in CI - only its guard is tested.
 */
class DebuggingTraitTest extends UnitTestCase
{
    private string|false $pauseForDebugging;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pauseForDebugging = getenv('PAUSE_FOR_DEBUGGING');
        putenv('PAUSE_FOR_DEBUGGING');
    }

    protected function tearDown(): void
    {
        putenv($this->pauseForDebugging === false ? 'PAUSE_FOR_DEBUGGING' : 'PAUSE_FOR_DEBUGGING=' . $this->pauseForDebugging);
        parent::tearDown();
    }

    #[Test]
    public function pausingWithoutPauseForDebuggingFailsRightAway(): void
    {
        $context = new class {
            use DebuggingTrait;
        };

        $this->expectExceptionCode(1728120002);
        $context->iPauseForDebugging();
    }
}
