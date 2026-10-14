<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;

/**
 * Page visits, reloads and their response; screen size and scrolling.
 *
 * Needs {@see PlaywrightTrait}. Opt-in: use it in your FeatureContext; remove project steps with the same wording first,
 * otherwise Behat reports them as ambiguous.
 */
trait NavigationTrait
{
    /**
     * Opens a new page of the browser context - cookies and storage of the scenario stay.
     */
    #[When('I access the URI path :uriPath')]
    public function iAccess(string $uriPath): void
    {
        $this->playwrightRun('
            vars.page = await context.newPage();
            vars.response = await vars.page.goto("BASEURL" + %s);
        ', $uriPath);
    }

    /**
     * A normal reload (like Cmd+R): the browser asks the server instead of reusing its cached copy - e.g. after
     * changing content while a page cache allows the browser to keep pages.
     */
    #[When('I reload the page')]
    public function iReloadThePage(): void
    {
        $this->playwrightRun('vars.response = await vars.page.reload();');
    }

    /**
     * Waits for the URL first - after a click or redirect the navigation may still be running.
     */
    #[Then('the URI path should be :uriPath')]
    public function theUriPathShouldBe(string $uriPath): void
    {
        $this->playwrightRun(
            'await expect.poll(() => new URL(vars.page.url()).pathname.replace(/\/$/, ""), {message: "URI path", timeout: %s}).toBe(%s);',
            $this->playwrightTimeout,
            rtrim($uriPath, '/')
        );
    }

    #[Then('the response status code should be :status')]
    public function theResponseStatusCodeShouldBe(string $status): void
    {
        $actualStatusCode = $this->playwrightRun(
            // goto() and reload() return null for a navigation within the page (e.g. to an anchor)
            'if (!vars.response) { throw new Error("No response of a page load - use \"I access the URI path\" first."); } return vars.response.status();'
        );
        Assert::assertEquals($status, $actualStatusCode, 'HTTP response status code mismatch');
    }

    /**
     * Resizes the current page and reloads it - after `I access the URI path`, which opens a new page. The reload lets
     * the page start at this size: a resize of the loaded page only fires the resize handlers of its scripts.
     */
    #[Given('the viewport is :width x :height px')]
    public function theViewportIs(int $width, int $height): void
    {
        $this->playwrightRun('
            await vars.page.setViewportSize({width: %s, height: %s});
            vars.response = await vars.page.reload();
        ', $width, $height);
    }

    /**
     * Emulates a screen: viewport size and device pixel ratio, then reloads, so the page loads its images for this
     * screen. Use it after `I access the URI path`, which opens a new page. Chromium only (Chrome DevTools Protocol -
     * Playwright sets the pixel ratio only for a new browser context).
     *
     * The browser cache is cleared before: the first visit loaded the images for the default screen, and Chromium may
     * reuse an already loaded larger srcset image instead of the one for this screen.
     */
    #[Given('the screen is :width x :height px with pixel ratio :ratio')]
    public function theScreenIsWithPixelRatio(int $width, int $height, float $ratio): void
    {
        $this->playwrightRun('
            const cdp = await vars.page.context().newCDPSession(vars.page);
            await cdp.send("Network.clearBrowserCache");
            await cdp.send("Emulation.setDeviceMetricsOverride", {width: %s, height: %s, deviceScaleFactor: %s, mobile: false});
            vars.response = await vars.page.reload();
        ', $width, $height, $ratio);
    }

    /**
     * Fails if the page isn't long enough to scroll.
     */
    #[When('I scroll to the bottom of the page')]
    public function iScrollToTheBottomOfThePage(): void
    {
        $this->playwrightRun('
            await vars.page.evaluate(() => window.scrollTo({top: document.documentElement.scrollHeight, behavior: "instant"}));
            await expect.poll(
                () => vars.page.evaluate(() => window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 1),
                {message: "The page is scrolled to the bottom - is it long enough?", timeout: %s}
            ).toBe(true);
        ' . self::navigation_afterScroll(), $this->playwrightTimeout);
    }

    #[When('I scroll up by :pixels px')]
    public function iScrollUpBy(int $pixels): void
    {
        $this->playwrightRun('
            const before = await vars.page.evaluate(() => window.scrollY);
            await vars.page.evaluate((pixels) => window.scrollBy({top: -pixels, behavior: "instant"}), %s);
            await expect.poll(() => vars.page.evaluate(() => window.scrollY), {message: "scrollY after scrolling up", timeout: %s}).toBeLessThan(before);
        ' . self::navigation_afterScroll(), $pixels, $this->playwrightTimeout);
    }

    /**
     * Waits for smooth scrolling to end.
     */
    #[Then('the page should be scrolled to the top')]
    public function thePageShouldBeScrolledToTheTop(): void
    {
        $this->playwrightRun(
            'await expect.poll(() => vars.page.evaluate(() => window.scrollY), {message: "scrollY", timeout: %s}).toBe(0);',
            $this->playwrightTimeout
        );
    }

    /**
     * Scroll events fire once per frame - the page sees this scroll before the next one.
     */
    private static function navigation_afterScroll(): string
    {
        return 'await vars.page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));';
    }
}
