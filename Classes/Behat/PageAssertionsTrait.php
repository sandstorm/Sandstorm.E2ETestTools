<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Step\Then;
use PHPUnit\Framework\Assert;
use Sandstorm\E2ETestTools\Playwright\JsValue;

/**
 * Assertions on the page in the browser: title, texts, elements by test id (`data-testid`).
 *
 * Needs {@see PlaywrightTrait}. Opt-in: use it in your FeatureContext; remove project steps with the same wording first,
 * otherwise Behat reports them as ambiguous.
 */
trait PageAssertionsTrait
{
    /**
     * How long element state assertions wait for the state, in milliseconds.
     */
    private int $pageAssertions_timeout = 5000;

    #[Then('the page title should be :title')]
    public function thePageTitleShouldBe(string $title): void
    {
        $actual = $this->playwrightConnector->execute($this->requirePlaywrightContext(), <<<'JS'
            await vars.page.waitForLoadState();
            return await vars.page.title();
        JS);
        Assert::assertSame($title, $actual, 'Unexpected page title.');
    }

    /**
     * Checks the rendered text (`innerText`) - text in hidden elements counts as absent.
     */
    #[Then('there should not be the text :text on the page')]
    public function thereShouldNotBeTheTextOnThePage(string $text): void
    {
        $pageText = $this->playwrightConnector->execute($this->requirePlaywrightContext(), <<<'JS'
            await vars.page.waitForLoadState();
            return await vars.page.evaluate(() => document.body.innerText);
        JS);
        Assert::assertStringNotContainsString($text, (string)$pageText, sprintf('The text "%s" is on the page.', $text));
    }

    #[Then('there should be the text :text in :selector')]
    public function thereShouldBeTheTextIn(string $text, string $selector): void
    {
        Assert::assertStringContainsString($text, $this->innerTextOf($selector), sprintf('The text "%s" is not in "%s".', $text, $selector));
    }

    #[Then('there should not be the text :text in :selector')]
    public function thereShouldNotBeTheTextIn(string $text, string $selector): void
    {
        Assert::assertStringNotContainsString($text, $this->innerTextOf($selector), sprintf('The text "%s" is in "%s".', $text, $selector));
    }

    /**
     * :state is one of visible, hidden, focused, enabled, disabled
     */
    #[Then('the element with test id :testId should be :state')]
    public function theElementWithTestIdShouldBe(string $testId, string $state): void
    {
        $predicates = [
            'visible' => null,
            'hidden' => null,
            'focused' => 'element === document.activeElement',
            'enabled' => "!element.disabled && element.getAttribute('aria-disabled') !== 'true'",
            'disabled' => "element.disabled || element.getAttribute('aria-disabled') === 'true'",
        ];
        if (!array_key_exists($state, $predicates)) {
            throw new \InvalidArgumentException(sprintf('Unknown state "%s" - use one of: %s.', $state, implode(', ', array_keys($predicates))));
        }
        Assert::assertTrue(
            $this->waitForTestIdState($testId, $state, $predicates[$state]),
            sprintf('The element with test id "%s" is not %s (waited %d ms).', $testId, $state, $this->pageAssertions_timeout)
        );
    }

    #[Then('the element with test id :testId should be in the viewport')]
    public function theElementWithTestIdShouldBeInTheViewport(string $testId): void
    {
        Assert::assertTrue(
            $this->waitForTestIdState($testId, 'in the viewport', 'pageAssertionsInViewport(element)'),
            sprintf('The element with test id "%s" is not in the viewport (waited %d ms).', $testId, $this->pageAssertions_timeout)
        );
    }

    #[Then('the element with test id :testId should not be in the viewport')]
    public function theElementWithTestIdShouldNotBeInTheViewport(string $testId): void
    {
        Assert::assertTrue(
            $this->waitForTestIdState($testId, 'not in the viewport', '!pageAssertionsInViewport(element)'),
            sprintf('The element with test id "%s" is in the viewport (waited %d ms).', $testId, $this->pageAssertions_timeout)
        );
    }

    private function innerTextOf(string $selector): string
    {
        return (string)$this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(<<<'JS'
            return await vars.page.locator(%s).first().innerText();
        JS, JsValue::of($selector)));
    }

    /**
     * Waits until the element is in the state - visible/hidden via Playwright, everything else via a predicate on the
     * element evaluated in the page. Returns false instead of throwing, so the step can fail with a readable message.
     */
    private function waitForTestIdState(string $testId, string $state, ?string $predicate): bool
    {
        if ($predicate === null) {
            $script = sprintf(<<<'JS'
                try {
                    await vars.page.getByTestId(%s).first().waitFor({state: %s, timeout: %d});
                    return true;
                } catch (e) {
                    return false;
                }
            JS, JsValue::of($testId), JsValue::of($state), $this->pageAssertions_timeout);
        } else {
            $script = sprintf(<<<'JS'
                try {
                    await vars.page.waitForFunction((testId) => {
                        const pageAssertionsInViewport = (el) => {
                            const rect = el.getBoundingClientRect();
                            return rect.bottom > 0 && rect.right > 0 && rect.top < window.innerHeight && rect.left < window.innerWidth;
                        };
                        const element = document.querySelector('[data-testid="' + CSS.escape(testId) + '"]');
                        return element !== null && (%s);
                    }, %s, {timeout: %d});
                    return true;
                } catch (e) {
                    return false;
                }
            JS, $predicate, JsValue::of($testId), $this->pageAssertions_timeout);
        }
        return $this->playwrightConnector->execute($this->requirePlaywrightContext(), $script) === true;
    }
}
