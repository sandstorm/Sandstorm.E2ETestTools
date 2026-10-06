<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Behat;

use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Behat\FusionRenderingTrait;

/**
 * The default dimension space point of "the default dimension space point is ..." lasts one scenario - the next one
 * starts without (the E2E suite can't express "a table without the column fails then").
 */
class DefaultDimensionSpacePointTest extends UnitTestCase
{
    #[Test]
    public function theStepSetsTheDefault(): void
    {
        $context = $this->context();

        $context->theDefaultDimensionSpacePointIs('{"language":"en"}');

        self::assertSame(['language' => 'en'], $this->defaultOf($context));
    }

    #[Test]
    public function everyScenarioStartsWithoutDefault(): void
    {
        $context = $this->context();
        $context->theDefaultDimensionSpacePointIs('{"language":"en"}');

        $context->resetDefaultDimensionSpacePoint();

        self::assertSame([], $this->defaultOf($context));
    }

    #[Test]
    public function invalidDefaultFails(): void
    {
        $this->expectExceptionMessageMatches('/Invalid JSON.*default dimension space point/');

        $this->context()->theDefaultDimensionSpacePointIs('{language}');
    }

    private function context(): object
    {
        return new class {
            use FusionRenderingTrait;

            public function getObjectManager(): ObjectManagerInterface
            {
                throw new \LogicException('not needed');
            }
        };
    }

    /**
     * @return array<string,string>
     */
    private function defaultOf(object $context): array
    {
        return (new \ReflectionProperty($context, 'defaultDimensionSpacePoint'))->getValue($context);
    }
}
