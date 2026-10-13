<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Playwright;

use Neos\Flow\Annotations as Flow;

/**
 * Raw JavaScript for a placeholder of {@see \Sandstorm\E2ETestTools\Behat\PlaywrightTrait::playwrightRun()} - e.g. a
 * locator or a matcher built by the step itself. Never wrap step parameters in it: they come from feature files and
 * belong into the script as {@see JsValue}.
 *
 * @Flow\Proxy(false)
 */
final class JsCode
{
    private function __construct(
        public readonly string $code
    ) {
    }

    public static function of(string $code): self
    {
        return new self($code);
    }
}
