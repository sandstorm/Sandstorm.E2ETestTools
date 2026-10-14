<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Playwright;

use Neos\Flow\Annotations as Flow;

/**
 * Turns step parameters into CSS string literals for selectors, e.g. `[data-name=%s]`.
 *
 * Like {@see JsValue} for scripts: a quote or backslash in the value would end the string or change the selector.
 * The selector itself still goes into the script with JsValue::of().
 *
 * @Flow\Proxy(false)
 */
final class CssValue
{
    public static function of(string $value): string
    {
        // a line break can't be in a CSS string, only as escape - the space ends the hex escape
        return '"' . strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\a ',
            "\r" => '\\d ',
            "\f" => '\\c ',
            "\0" => "\u{FFFD}",
        ]) . '"';
    }
}
