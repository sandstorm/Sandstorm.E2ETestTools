<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Playwright;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Playwright\JsCode;

class JsCodeTest extends UnitTestCase
{
    #[Test]
    public function theCodeIsKeptAsItIs(): void
    {
        self::assertSame('vars.page.locator("nav").first()', JsCode::of('vars.page.locator("nav").first()')->code);
    }
}
