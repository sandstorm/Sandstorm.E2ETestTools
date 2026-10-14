<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Gherkin\Node\TableNode;
use Behat\Step\Then;
use PHPUnit\Framework\Assert;

/**
 * Checks every page should pass, whatever its content - a smoke test per page type: no JavaScript errors, no broken
 * requests to the site under test, every image loads and has an alt attribute.
 *
 * The page of the last `I access the URI path` is checked, with what Playwright collected since it was opened
 * (`page.pageErrors()`, `page.consoleMessages()`, `page.requests()` - the last 200 errors and messages, the last 100
 * requests).
 *
 * Needs {@see PlaywrightTrait}. Opt-in: use it in your FeatureContext; remove project steps with the same wording first,
 * otherwise Behat reports them as ambiguous.
 */
trait PageHealthTrait
{
    /**
     * Uncaught errors and `console.error()` - waits until the network is idle first, an error might still come.
     */
    #[Then('there should be no JavaScript errors')]
    public function thereShouldBeNoJavaScriptErrors(): void
    {
        $errors = $this->playwrightStrings(self::pageHealth_afterLoading() . <<<'JS'
            const consoleErrors = (await vars.page.consoleMessages())
                // Chromium logs every failed resource - that's a failed request, not a script error
                .filter((message) => message.type() === 'error' && !message.text().startsWith('Failed to load resource'))
                .map((message) => 'console.error: ' + message.text());
            return [...(await vars.page.pageErrors()).map((error) => error.message), ...consoleErrors];
        JS);
        Assert::assertSame([], $errors, sprintf('JavaScript errors: %s', implode('; ', $errors)));
    }

    /**
     * Requests to the site under test that failed or got a 4xx/5xx answer - other hosts are ignored (see NetworkTrait).
     */
    #[Then('there should be no failed requests')]
    public function thereShouldBeNoFailedRequests(): void
    {
        $failed = $this->pageHealth_failedRequests();
        Assert::assertSame([], $failed, sprintf('Failed requests: %s', implode(', ', $failed)));
    }

    /**
     * Column "URI path", matched as part of the path - for a known gap (e.g. /favicon.ico) until it's fixed.
     */
    #[Then('there should be no failed requests except:')]
    public function thereShouldBeNoFailedRequestsExcept(TableNode $exceptions): void
    {
        $paths = array_filter(array_map(static fn (array $row): string => $row['URI path'] ?? '', $exceptions->getHash()));
        $failed = array_values(array_filter(
            $this->pageHealth_failedRequests(),
            static fn (string $request) => array_filter($paths, static fn (string $path) => str_contains($request, $path)) === []
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
        $broken = $this->playwrightStrings(self::pageHealth_requirePage() . <<<'JS'
            return await vars.page.evaluate(async () => {
                document.querySelectorAll('img[loading="lazy"]').forEach((image) => image.loading = 'eager');
                const timeout = new Promise((resolve) => setTimeout(resolve, 15000, 'still loading after 15 s'));
                const problems = await Promise.all([...document.images].map((image) => Promise.race([
                    // decode() waits for the image and fails if it can't be shown - unlike naturalWidth, also fine for SVGs without a size
                    image.decode().then(() => null, () => 'not loaded'),
                    timeout,
                ]).then((problem) => problem && `${image.currentSrc || image.src} (${problem})`)));
                return problems.filter(Boolean);
            });
        JS);
        Assert::assertSame([], $broken, sprintf('Images: %s', implode(', ', $broken)));
    }

    /**
     * An empty alt (`alt=""`, a decorative image) passes, so do images hidden from assistive technology
     * (`role="presentation"`/`"none"`, inside `aria-hidden="true"`).
     */
    #[Then('every image should have an alt attribute')]
    public function everyImageShouldHaveAnAltAttribute(): void
    {
        $withoutAlt = $this->playwrightStrings(self::pageHealth_requirePage() . <<<'JS'
            return await vars.page.locator('img:not([alt]):not([role=presentation]):not([role=none])').evaluateAll((images) => images
                .filter((image) => !image.closest('[aria-hidden="true"]'))
                .map((image) => image.currentSrc || image.src));
        JS);
        Assert::assertSame([], $withoutAlt, sprintf('Images without alt attribute: %s', implode(', ', $withoutAlt)));
    }

    /**
     * @return list<string> e.g. "GET /missing.png (404)"
     */
    private function pageHealth_failedRequests(): array
    {
        return $this->playwrightStrings(self::pageHealth_afterLoading() . <<<'JS'
            const siteUnderTest = new URL("BASEURL").host;
            return (await vars.page.requests())
                .filter((request) => new URL(request.url()).host === siteUnderTest)
                .map((request) => {
                    const status = request.existingResponse()?.status() ?? 0;
                    const failure = request.failure()?.errorText;
                    return status >= 400 || failure ? `${request.method()} ${new URL(request.url()).pathname} (${failure ?? status})` : null;
                })
                .filter(Boolean);
        JS);
    }

    private static function pageHealth_requirePage(): string
    {
        return 'if (!vars.page) { throw new Error(\'No page - use "I access the URI path" first.\'); }' . "\n";
    }

    private static function pageHealth_afterLoading(): string
    {
        return self::pageHealth_requirePage() . 'await vars.page.waitForLoadState("networkidle");' . "\n";
    }
}
