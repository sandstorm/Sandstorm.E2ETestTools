<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Playwright;

use Neos\Flow\Annotations as Flow;

/**
 * Turns step parameters into JavaScript literals for Playwright scripts.
 *
 * Step texts come from feature files and contain quotes, backslashes or line breaks - interpolated as raw strings
 * they break the script or change what it does. A JSON value is a valid JavaScript literal.
 *
 * @Flow\Proxy(false)
 */
final class JsValue
{
    public static function of(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
