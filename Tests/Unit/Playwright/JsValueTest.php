<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Playwright;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Playwright\JsValue;

class JsValueTest extends UnitTestCase
{
    /**
     * Step parameters come from feature files - whatever they contain must end up as the same string in the script.
     */
    #[Test]
    #[DataProvider('strings')]
    public function stringsBecomeJavaScriptLiteralsOfTheSameValue(string $value): void
    {
        $literal = JsValue::of($value);

        self::assertMatchesRegularExpression('/^".*"$/s', $literal);
        // a JSON string literal is a JavaScript string literal - decoding it gives the original value back
        self::assertSame($value, json_decode($literal, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString("\n", $literal, 'a raw line break would end the JS statement');
    }

    public static function strings(): iterable
    {
        yield 'double quotes' => ['Say "hello"'];
        yield 'single quotes and backticks' => ["it's a `template`"];
        yield 'backslashes' => ['C:\\dir\\file'];
        yield 'line break' => ["line 1\nline 2"];
        yield 'script end tag and template expression' => ['</script>${alert(1)}'];
        yield 'umlauts and slashes stay readable' => ['Ümläut /path'];
        yield 'empty' => [''];
    }

    #[Test]
    public function readableOutputForUmlautsAndSlashes(): void
    {
        self::assertSame('"Ümläut /path"', JsValue::of('Ümläut /path'));
    }
}
