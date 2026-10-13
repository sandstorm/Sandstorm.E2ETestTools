<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Behat\Step\Then;
use PHPUnit\Framework\Assert;

/**
 * Checks every page should pass, whatever its content - a smoke test per page type: no JavaScript errors, no broken
 * requests to the site under test, every image loads and has an alt attribute.
 *
 * Needs {@see PlaywrightTrait}. Opt-in: use it in your FeatureContext; remove project steps with the same wording first,
 * otherwise Behat reports them as ambiguous.
 */
trait PageHealthTrait
{
    /**
     * Before the first visit. Registered on the browser context, so it also covers the pages opened later by
     * `I access the URI path`. Records uncaught errors and `console.error()` of the pages, and requests to the site under
     * test that fail or answer with 4xx/5xx. Requests to other hosts are ignored - see NetworkTrait for them.
     */
    #[Given('browser errors are recorded')]
    public function browserErrorsAreRecorded(): void
    {
        $this->playwrightRun(<<<'JS'
            const siteUnderTest = new URL("BASEURL").host;
            const errors = vars.browserErrors = {js: [], requests: []};
            const pathOf = (url) => { try { return new URL(url).pathname; } catch { return url; } };
            const where = (page) => page ? ` (on ${pathOf(page.url())})` : '';
            const isSiteUnderTest = (url) => { try { return new URL(url).host === siteUnderTest; } catch { return false; } };
            context.on('weberror', (webError) => errors.js.push(webError.error().message + where(webError.page())));
            context.on('console', (message) => {
                // Chromium logs every failed resource - that's a failed request, recorded below
                if (message.type() === 'error' && !message.text().startsWith('Failed to load resource')) {
                    errors.js.push('console.error: ' + message.text() + where(message.page()));
                }
            });
            context.on('response', (response) => {
                if (response.status() >= 400 && isSiteUnderTest(response.url())) {
                    errors.requests.push(`${response.request().method()} ${pathOf(response.url())} (${response.status()})`);
                }
            });
            context.on('requestfailed', (request) => {
                if (isSiteUnderTest(request.url())) {
                    errors.requests.push(`${request.method()} ${pathOf(request.url())} (${request.failure()?.errorText ?? 'failed'})`);
                }
            });
        JS);
    }

    /**
     * Waits until the network is idle first - an error might still come.
     */
    #[Then('there should be no JavaScript errors')]
    public function thereShouldBeNoJavaScriptErrors(): void
    {
        $errors = $this->pageHealth_recorded('js');
        Assert::assertSame([], $errors, sprintf('JavaScript errors: %s', implode('; ', $errors)));
    }

    /**
     * Requests to the site under test only. Waits until the network is idle first.
     */
    #[Then('there should be no failed requests')]
    public function thereShouldBeNoFailedRequests(): void
    {
        $failed = $this->pageHealth_recorded('requests');
        Assert::assertSame([], $failed, sprintf('Failed requests: %s', implode(', ', $failed)));
    }

    /**
     * Column "URI path", matched as part of the path - for a known gap (e.g. /favicon.ico) until it's fixed.
     */
    #[Then('there should be no failed requests except:')]
    public function thereShouldBeNoFailedRequestsExcept(TableNode $exceptions): void
    {
        $paths = array_map(static fn (array $row): string => $row['URI path'] ?? '', $exceptions->getHash());
        $failed = array_values(array_filter(
            $this->pageHealth_recorded('requests'),
            static fn (string $request) => array_filter($paths, static fn (string $path) => $path !== '' && str_contains($request, $path)) === []
        ));
        Assert::assertSame([], $failed, sprintf('Failed requests: %s', implode(', ', $failed)));
    }

    /**
     * Loads lazy images right away, then waits up to 15 seconds for all images - Neos generates thumbnails on their
     * first request. Fails with the images the browser can't show (missing, not an image, broken file).
     */
    #[Then('every image should be loaded')]
    public function everyImageShouldBeLoaded(): void
    {
        $broken = $this->playwrightStrings(<<<'JS'
            if (!vars.page) { throw new Error('No page - use "I access the URI path" first.'); }
            return await vars.page.evaluate(async () => {
                document.querySelectorAll('img[loading="lazy"]').forEach((image) => image.loading = 'eager');
                const timeout = new Promise((resolve) => setTimeout(() => resolve('timeout'), 15000));
                const results = await Promise.all([...document.images].map((image) => Promise.race([
                    // decode() waits for the image and fails if it can't be shown - also for SVGs without a size
                    image.decode().then(() => null, () => image.currentSrc || image.src || image.outerHTML.slice(0, 100)),
                    timeout.then(() => `${image.currentSrc || image.src} (still loading after 15 s)`),
                ])));
                return results.filter((result) => result !== null);
            });
        JS);
        Assert::assertSame([], $broken, sprintf('Images that are not loaded: %s', implode(', ', $broken)));
    }

    /**
     * An empty alt (`alt=""`, a decorative image) passes, so do images hidden from assistive technology
     * (`role="presentation"`/`"none"`, inside `aria-hidden="true"`).
     */
    #[Then('every image should have an alt attribute')]
    public function everyImageShouldHaveAnAltAttribute(): void
    {
        $withoutAlt = $this->playwrightStrings(<<<'JS'
            if (!vars.page) { throw new Error('No page - use "I access the URI path" first.'); }
            return await vars.page.evaluate(() => [...document.querySelectorAll('img:not([alt])')]
                .filter((image) => !['presentation', 'none'].includes(image.getAttribute('role')) && !image.closest('[aria-hidden="true"]'))
                .map((image) => image.currentSrc || image.src || image.outerHTML.slice(0, 100)));
        JS);
        Assert::assertSame([], $withoutAlt, sprintf('Images without alt attribute: %s', implode(', ', $withoutAlt)));
    }

    /**
     * @param 'js'|'requests' $kind
     * @return list<string>
     */
    private function pageHealth_recorded(string $kind): array
    {
        return $this->playwrightStrings(<<<'JS'
            if (!vars.browserErrors) { throw new Error('No browser errors are recorded - use "browser errors are recorded" first.'); }
            if (vars.page) { await vars.page.waitForLoadState('networkidle'); }
            return vars.browserErrors[%s];
        JS, $kind);
    }
}
