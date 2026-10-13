<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Sandstorm\E2ETestTools\Playwright\JsCode;

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
        $this->playwrightRun('await context.addCookies([{name: %s, value: %s, url: "BASEURL"}]);', $name, $value);
    }

    #[Then('the cookie :name should be set')]
    public function theCookieShouldBeSet(string $name): void
    {
        $this->browserState_expectCookie($name, 'not.toBeNull()');
    }

    #[Then('the cookie :name should not be set')]
    public function theCookieShouldNotBeSet(string $name): void
    {
        $this->browserState_expectCookie($name, 'toBeNull()');
    }

    /**
     * Compares the URL-decoded value - consent tools usually store URL-encoded JSON.
     */
    #[Then('the cookie :name should have the value :value')]
    public function theCookieShouldHaveTheValue(string $name, string $value): void
    {
        $this->browserState_expectCookie($name, self::playwrightScript('toBe(%s)', $value), true);
    }

    #[When('I delete the cookie :name')]
    public function iDeleteTheCookie(string $name): void
    {
        $this->playwrightRun('await context.clearCookies({name: %s});', $name);
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
        $this->playwrightRun(<<<'JS'
            await context.addInitScript(([storage, key, value, origin]) => {
                if (window.location.origin === origin && window[storage].getItem(key) === null) {
                    window[storage].setItem(key, value);
                }
            }, [%s, %s, %s, new URL("BASEURL").origin]);
        JS, $this->browserState_storageObject($storage), $key, $value);
    }

    /**
     * :storage is "local" or "session"
     */
    #[Then('the :storage storage key :key should have the value :value')]
    public function theStorageKeyShouldHaveTheValue(string $storage, string $key, string $value): void
    {
        $this->browserState_expectStorage($storage, $key, self::playwrightScript('toBe(%s)', $value));
    }

    #[Then('the :storage storage key :key should not be set')]
    public function theStorageKeyShouldNotBeSet(string $storage, string $key): void
    {
        $this->browserState_expectStorage($storage, $key, 'toBeNull()');
    }

    #[When('I delete the :storage storage key :key')]
    public function iDeleteTheStorageKey(string $storage, string $key): void
    {
        $this->playwrightRun('await vars.page.evaluate((key) => window[%s].removeItem(key), %s);', $this->browserState_storageObject($storage), $key);
    }

    /**
     * Polls the cookie value (null if it isn't set) until $matcher passes - a page sets cookies e.g. after a click,
     * asynchronously. $decoded compares the URL-decoded value.
     */
    private function browserState_expectCookie(string $name, string $matcher, bool $decoded = false): void
    {
        $this->playwrightRun(<<<'JS'
            await expect.poll(async () => {
                const cookie = (await context.cookies()).find((cookie) => cookie.name === %1$s);
                return cookie === undefined ? null : %2$s;
            }, {message: %3$s, timeout: %4$s}).%5$s;
        JS,
            $name,
            JsCode::of($decoded ? 'decodeURIComponent(cookie.value)' : 'cookie.value'),
            sprintf('value of the cookie "%s"', $name),
            $this->playwrightTimeout,
            JsCode::of($matcher)
        );
    }

    private function browserState_expectStorage(string $storage, string $key, string $matcher): void
    {
        $this->playwrightRun(
            'await expect.poll(() => vars.page.evaluate((key) => window[%s].getItem(key), %s), {message: %s, timeout: %s}).%s;',
            $this->browserState_storageObject($storage),
            $key,
            sprintf('value of the %s storage key "%s"', $storage, $key),
            $this->playwrightTimeout,
            JsCode::of($matcher)
        );
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
