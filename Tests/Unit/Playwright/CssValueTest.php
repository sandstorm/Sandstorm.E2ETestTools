<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Playwright;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Playwright\CssValue;

class CssValueTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function strings(): iterable
    {
        yield 'plain' => ['youtube', '"youtube"'];
        yield 'double quotes' => ['Say "hello"', '"Say \\"hello\\""'];
        yield 'single quotes stay' => ["it's", '"it\'s"'];
        yield 'backslashes' => ['a\\b', '"a\\\\b"'];
        yield 'line break' => ["a\nb", '"a\\a b"'];
        yield 'brackets stay' => ['contact[name]', '"contact[name]"'];
        yield 'empty' => ['', '""'];
    }

    #[Test]
    #[DataProvider('strings')]
    public function stringsBecomeCssStringLiterals(string $value, string $expected): void
    {
        self::assertSame($expected, CssValue::of($value));
    }
}
