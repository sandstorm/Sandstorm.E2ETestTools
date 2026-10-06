<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;
use Sandstorm\E2ETestTools\Playwright\JsValue;

/**
 * Cookies and web storage of the browser context - consent, settings and anything else a page remembers.
 *
 * The "has the value" steps preset state before the first page visit, e.g. the consent decision, so the banner
 * doesn't cover the page in every scenario.
 *
 * Needs {@see PlaywrightTrait}. Opt-in: use it in your FeatureContext; remove project steps with the same wording first,
 * otherwise Behat reports them as ambiguous.
 */
trait BrowserStateTrait
{
    /**
     * The value is stored as given - URL-encode it yourself if the page expects that.
     */
    #[Given('the cookie :name has the value :value')]
    public function theCookieHasTheValue(string $name, string $value): void
    {
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(
            'await context.addCookies([{name: %s, value: %s, url: "BASEURL"}]);',
            JsValue::of($name),
            JsValue::of($value)
        ));
    }

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
     * Works before the first page visit: an init script writes the key on every page load of the site under test -
     * as long as the key is missing, so the page can still change the value (a removed key comes back on the next load).
     *
     * :storage is "local" or "session"
     */
    #[Given('the :storage storage key :key has the value :value')]
    public function theStorageKeyHasTheValue(string $storage, string $key, string $value): void
    {
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(<<<'JS'
            await context.addInitScript(([storage, key, value, origin]) => {
                if (window.location.origin === origin && window[storage].getItem(key) === null) {
                    window[storage].setItem(key, value);
                }
            }, [%s, %s, %s, new URL("BASEURL").origin]);
        JS, JsValue::of($this->browserState_storageObject($storage)), JsValue::of($key), JsValue::of($value)));
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
