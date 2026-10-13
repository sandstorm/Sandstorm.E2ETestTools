<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;

/**
 * Requests of the browser: which third parties a page tries to load (e.g. before and after a cookie consent), and
 * whether links lead somewhere.
 *
 * Needs {@see PlaywrightTrait}. Opt-in: use it in your FeatureContext; remove project steps with the same wording first,
 * otherwise Behat reports them as ambiguous.
 */
trait NetworkTrait
{
    /**
     * Before the first visit. Registered on the browser context, so it also covers the pages opened later by
     * `I access the URI path`. Requests to other hosts than the system under test are aborted: the scenarios check
     * whether the site *tries* to load a third party - without real traffic, so they also run without internet access.
     */
    #[Given('requests to other hosts are recorded and blocked')]
    public function requestsToOtherHostsAreRecordedAndBlocked(): void
    {
        $this->playwrightRun(<<<'JS'
            const siteUnderTest = new URL("BASEURL").host;
            vars.externalHosts = [];
            await context.route((url) => url.host !== siteUnderTest, (route) => {
                vars.externalHosts.push(new URL(route.request().url()).host);
                return route.abort();
            });
        JS);
    }

    /**
     * E.g. before a reload, to check what the reloaded page requests.
     */
    #[When('I forget the recorded requests')]
    public function iForgetTheRecordedRequests(): void
    {
        $this->playwrightRun(self::network_requireRecording() . 'vars.externalHosts.length = 0;');
    }

    /**
     * Waits for the request. :host is matched as part of the host name, e.g. "youtube" for www.youtube-nocookie.com
     */
    #[Then('there should be a request to :host')]
    public function thereShouldBeARequestTo(string $host): void
    {
        $this->playwrightRun(self::network_requireRecording() . <<<'JS'
            // the requested hosts in the failure message, not just "false"
            await expect.poll(() => [...new Set(vars.externalHosts)], {message: %2$s, timeout: %3$s})
                .toContainEqual(expect.stringContaining(%1$s));
        JS, $host, sprintf('Requested hosts should include "%s"', $host), $this->playwrightTimeout);
    }

    /**
     * Waits until the network is idle first - an unwanted request might still be on its way. :host is matched as part
     * of the host name.
     */
    #[Then('there should be no request to :host')]
    public function thereShouldBeNoRequestTo(string $host): void
    {
        $hosts = $this->playwrightStrings(self::network_requireRecording() . <<<'JS'
            await vars.page.waitForLoadState('networkidle');
            return [...new Set(vars.externalHosts)];
        JS);
        Assert::assertEmpty(
            array_filter($hosts, static fn (string $requested) => str_contains($requested, $host)),
            sprintf('Unexpected request to "%s". Requested hosts: %s', $host, implode(', ', $hosts))
        );
    }

    /**
     * Requests every link in the element (following redirects, e.g. of shortcuts) and expects status 200 - also links
     * of closed menus. Fails with the links that answer differently.
     */
    #[Then('all links in :selector should respond with 200')]
    public function allLinksInShouldRespondWith200(string $selector): void
    {
        $statusByHref = $this->playwrightArray(<<<'JS'
            const hrefs = await vars.page.locator(%s).first().locator('a[href]').evaluateAll(
                (links) => [...new Set(links.map((link) => link.href))]
            );
            const result = {};
            for (const href of hrefs) {
                result[href] = (await vars.page.request.get(href)).status();
            }
            return result;
        JS, $selector);

        Assert::assertNotEmpty($statusByHref, sprintf('"%s" contains no links.', $selector));
        Assert::assertSame([], array_filter($statusByHref, static fn ($status) => $status !== 200), 'These links do not respond with 200.');
    }

    private static function network_requireRecording(): string
    {
        return 'if (!vars.externalHosts) { throw new Error(\'No requests are recorded - use "requests to other hosts are recorded and blocked" first.\'); }' . "\n";
    }
}
