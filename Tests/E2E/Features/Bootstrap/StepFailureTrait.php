<?php

declare(strict_types=1);

use Behat\Behat\Definition\Pattern\Policy\TurnipPatternPolicy;
use Behat\Step\Definition;
use Behat\Step\Then;
use PHPUnit\Framework\Assert;

/**
 * Only for testing the failure messages of the shipped steps: runs another step of this context and expects it to fail.
 *
 * Write the step in single quotes when it contains double quotes:
 *   Then the step 'the button "Send" should be focused' should fail with "Focused instead"
 */
trait StepFailureTrait
{
    /**
     * The step runs with a short timeout (500 ms) - it's expected to fail, so it would wait the full timeout.
     */
    #[Then('the step :step should fail with :message')]
    public function theStepShouldFailWith(string $step, string $message): void
    {
        [$method, $arguments] = $this->stepFailure_find($step);
        $timeout = $this->playwrightTimeout;
        $this->setPlaywrightTimeout(500);
        try {
            $this->$method(...$arguments);
        } catch (\Throwable $error) {
            Assert::assertStringContainsString($message, $error->getMessage(), sprintf('The step "%s" failed with another message.', $step));
            return;
        } finally {
            $this->setPlaywrightTimeout($timeout);
        }
        Assert::fail(sprintf('The step "%s" passed, but should fail with "%s".', $step, $message));
    }

    /**
     * The method of this context whose step pattern matches $step, and its arguments by name - like Behat finds it.
     *
     * @return array{string, array<string, mixed>}
     */
    private function stepFailure_find(string $step): array
    {
        $policy = new TurnipPatternPolicy();
        foreach ((new \ReflectionObject($this))->getMethods() as $method) {
            foreach ($method->getAttributes(Definition::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $pattern = $attribute->newInstance()->getPattern();
                if ($pattern === null || preg_match($policy->transformPatternToRegex($pattern), $step, $matches) !== 1) {
                    continue;
                }
                $arguments = [];
                foreach ($method->getParameters() as $parameter) {
                    $value = $matches[$parameter->getName()] ?? null;
                    if ($value === null) {
                        continue;
                    }
                    $type = $parameter->getType();
                    $arguments[$parameter->getName()] = match ($type instanceof \ReflectionNamedType ? $type->getName() : 'string') {
                        'int' => (int)$value,
                        'float' => (float)$value,
                        default => $value,
                    };
                }
                return [$method->getName(), $arguments];
            }
        }
        throw new \InvalidArgumentException(sprintf('No step matches "%s".', $step));
    }
}
