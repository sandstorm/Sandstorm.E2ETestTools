<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Step\Then;
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
     * How long the assertions wait for the expected state, in milliseconds.
     */
    private int $pageAssertions_timeout = 5000;

    #[Then('the page title should be :title')]
    public function thePageTitleShouldBe(string $title): void
    {
        $this->pageAssertions_expect('vars.page', 'toHaveTitle(%s, %s)', $title);
    }

    /**
     * Checks the rendered text (`innerText`) - text in hidden elements counts as absent.
     */
    #[Then('there should not be the text :text on the page')]
    public function thereShouldNotBeTheTextOnThePage(string $text): void
    {
        $this->pageAssertions_expect('vars.page.locator("body")', 'not.toContainText(%s, {useInnerText: true, ...%s})', $text);
    }

    #[Then('there should be the text :text in :selector')]
    public function thereShouldBeTheTextIn(string $text, string $selector): void
    {
        $this->pageAssertions_expect(sprintf('vars.page.locator(%s).first()', JsValue::of($selector)), 'toContainText(%s, {useInnerText: true, ...%s})', $text);
    }

    #[Then('there should not be the text :text in :selector')]
    public function thereShouldNotBeTheTextIn(string $text, string $selector): void
    {
        $this->pageAssertions_expect(sprintf('vars.page.locator(%s).first()', JsValue::of($selector)), 'not.toContainText(%s, {useInnerText: true, ...%s})', $text);
    }

    /**
     * :state is one of visible, hidden, focused, enabled, disabled
     */
    #[Then('the element with test id :testId should be :state')]
    public function theElementWithTestIdShouldBe(string $testId, string $state): void
    {
        $matchers = [
            'visible' => 'toBeVisible',
            'hidden' => 'toBeHidden',
            'focused' => 'toBeFocused',
            'enabled' => 'toBeEnabled',
            'disabled' => 'toBeDisabled',
        ];
        if (!array_key_exists($state, $matchers)) {
            throw new \InvalidArgumentException(sprintf('Unknown state "%s" - use one of: %s.', $state, implode(', ', array_keys($matchers))));
        }
        $this->pageAssertions_expect($this->pageAssertions_testId($testId), $matchers[$state] . '(%s)');
    }

    #[Then('the element with test id :testId should be in the viewport')]
    public function theElementWithTestIdShouldBeInTheViewport(string $testId): void
    {
        $this->pageAssertions_expect($this->pageAssertions_testId($testId), 'toBeInViewport(%s)');
    }

    #[Then('the element with test id :testId should not be in the viewport')]
    public function theElementWithTestIdShouldNotBeInTheViewport(string $testId): void
    {
        $this->pageAssertions_expect($this->pageAssertions_testId($testId), 'not.toBeInViewport(%s)');
    }

    private function pageAssertions_testId(string $testId): string
    {
        return sprintf('vars.page.getByTestId(%s).first()', JsValue::of($testId));
    }

    /**
     * Runs a web-first assertion: `await expect(<subject>).<matcher>` - it waits up to the timeout for the expected
     * state and fails with Playwright's message (expected and received value, call log). In $matcher the last %s is
     * the options with the timeout, a %s before it the expected value.
     */
    private function pageAssertions_expect(string $subject, string $matcher, ?string $expected = null): void
    {
        $options = JsValue::of(['timeout' => $this->pageAssertions_timeout]);
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(
            'await expect(%s).%s;',
            $subject,
            $expected === null ? sprintf($matcher, $options) : sprintf($matcher, JsValue::of($expected), $options)
        ));
    }
}
