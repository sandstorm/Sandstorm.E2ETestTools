<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;
use Sandstorm\E2ETestTools\Playwright\JsValue;

/**
 * Cookies and web storage of the browser context - consent, settings and anything else a page remembers.
 *
 * Needs {@see PlaywrightTrait}. Opt-in: use it in your FeatureContext; remove project steps with the same wording first,
 * otherwise Behat reports them as ambiguous.
 */
trait BrowserStateTrait
{
    #[Then('the cookie :name should be set')]
    public function theCookieShouldBeSet(string $name): void
    {
        Assert::assertNotNull($this->browserState_cookieValue($name), sprintf('The cookie "%s" is not set.', $name));
    }

    #[Then('the cookie :name should not be set')]
    public function theCookieShouldNotBeSet(string $name): void
    {
        Assert::assertNull($this->browserState_cookieValue($name), sprintf('The cookie "%s" is set.', $name));
    }

    /**
     * Compares the URL-decoded value - consent tools usually store URL-encoded JSON.
     */
    #[Then('the cookie :name should have the value :value')]
    public function theCookieShouldHaveTheValue(string $name, string $value): void
    {
        $actual = $this->browserState_cookieValue($name);
        Assert::assertNotNull($actual, sprintf('The cookie "%s" is not set.', $name));
        Assert::assertSame($value, rawurldecode($actual), sprintf('Unexpected value of the cookie "%s".', $name));
    }

    #[When('I delete the cookie :name')]
    public function iDeleteTheCookie(string $name): void
    {
        // clearCookies() only takes a filter since Playwright 1.43 - clear all and add the others back
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(<<<'JS'
            const cookies = await context.cookies();
            await context.clearCookies();
            const remaining = cookies.filter((cookie) => cookie.name !== %s);
            if (remaining.length > 0) {
                await context.addCookies(remaining);
            }
        JS, JsValue::of($name)));
    }

    /**
     * :storage is "local" or "session"
     */
    #[Then('the :storage storage key :key should have the value :value')]
    public function theStorageKeyShouldHaveTheValue(string $storage, string $key, string $value): void
    {
        Assert::assertSame($value, $this->browserState_storageValue($storage, $key), sprintf('Unexpected value of the %s storage key "%s".', $storage, $key));
    }

    #[Then('the :storage storage key :key should not be set')]
    public function theStorageKeyShouldNotBeSet(string $storage, string $key): void
    {
        Assert::assertNull($this->browserState_storageValue($storage, $key), sprintf('The %s storage key "%s" is set.', $storage, $key));
    }

    #[When('I remove the :storage storage key :key')]
    public function iRemoveTheStorageKey(string $storage, string $key): void
    {
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(
            'await vars.page.evaluate((key) => window[%s].removeItem(key), %s);',
            JsValue::of($this->browserState_storageObject($storage)),
            JsValue::of($key)
        ));
    }

    private function browserState_cookieValue(string $name): ?string
    {
        return $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(<<<'JS'
            const cookie = (await context.cookies()).find((cookie) => cookie.name === %s);
            return cookie === undefined ? null : cookie.value;
        JS, JsValue::of($name)));
    }

    private function browserState_storageValue(string $storage, string $key): ?string
    {
        return $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(
            'return await vars.page.evaluate((key) => window[%s].getItem(key), %s);',
            JsValue::of($this->browserState_storageObject($storage)),
            JsValue::of($key)
        ));
    }

    private function browserState_storageObject(string $storage): string
    {
        return match ($storage) {
            'local' => 'localStorage',
            'session' => 'sessionStorage',
            default => throw new \InvalidArgumentException(sprintf('Unknown storage "%s" - use "local" or "session".', $storage)),
        };
    }
}
