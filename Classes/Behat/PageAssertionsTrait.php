<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Step\Then;
use Sandstorm\E2ETestTools\Playwright\JsCode;

/**
 * Assertions on the page in the browser: title, texts, focus, elements by role and accessible name or by test id
 * (`data-testid`).
 *
 * Elements found by role and accessible name are found like a screen reader user finds them - an element hidden from
 * assistive technology (inert, aria-hidden) counts as missing.
 *
 * Needs {@see PlaywrightTrait}. Opt-in: use it in your FeatureContext; remove project steps with the same wording first,
 * otherwise Behat reports them as ambiguous.
 */
trait PageAssertionsTrait
{
    /**
     * @deprecated use setPlaywrightTimeout() in the constructor of your FeatureContext
     */
    private ?int $pageAssertions_timeout = null;

    #[Then('the page title should be :title')]
    public function thePageTitleShouldBe(string $title): void
    {
        $this->pageAssertions_expect('vars.page', 'toHaveTitle(%s, %s)', $title);
    }

    /**
     * Checks the rendered text (`innerText`, case-sensitive) - text in hidden elements counts as absent.
     */
    #[Then('there should be the text :text on the page')]
    public function thereShouldBeTheTextOnThePage(string $text): void
    {
        $this->pageAssertions_expect('vars.page.locator("body")', 'toContainText(%s, {useInnerText: true, ...%s})', $text);
    }

    /**
     * Checks the rendered text (`innerText`, case-sensitive) - text in hidden elements counts as absent.
     */
    #[Then('there should not be the text :text on the page')]
    public function thereShouldNotBeTheTextOnThePage(string $text): void
    {
        $this->pageAssertions_expect('vars.page.locator("body")', 'not.toContainText(%s, {useInnerText: true, ...%s})', $text);
    }

    #[Then('there should be the text :text in :selector')]
    public function thereShouldBeTheTextIn(string $text, string $selector): void
    {
        $this->pageAssertions_expect($this->pageAssertions_css($selector), 'toContainText(%s, {useInnerText: true, ...%s})', $text);
    }

    #[Then('there should not be the text :text in :selector')]
    public function thereShouldNotBeTheTextIn(string $text, string $selector): void
    {
        $this->pageAssertions_expect($this->pageAssertions_css($selector), 'not.toContainText(%s, {useInnerText: true, ...%s})', $text);
    }

    /**
     * :role is an ARIA role (button, link, heading, textbox, ...), :name the accessible name - the exact caption or
     * label. Fails with the element that has the focus instead.
     */
    #[Then('the :role :name should be focused')]
    public function theShouldBeFocused(string $role, string $name): void
    {
        $this->pageAssertions_expectFocused(
            $this->pageAssertions_role($role, $name),
            sprintf(
                'await %s.count() === 0 && await %s.count() > 0',
                $this->pageAssertions_role($role, $name),
                $this->pageAssertions_role($role, $name, true)
            ),
            sprintf('The %s "%s" is hidden from assistive technology (inert or aria-hidden), so it can\'t be focused.', $role, $name)
        );
    }

    /**
     * The element is focused or contains the focused element (`:focus-within`).
     */
    #[Then('the focus should be inside :selector')]
    public function theFocusShouldBeInside(string $selector): void
    {
        $this->pageAssertions_expectFocused(
            sprintf('%s.and(vars.page.locator(":focus-within"))', $this->pageAssertions_css($selector)),
            'false',
            '',
            'toHaveCount(1, %s)'
        );
    }

    #[Then('the :role :name should be in the viewport')]
    public function theShouldBeInTheViewport(string $role, string $name): void
    {
        $this->pageAssertions_expect($this->pageAssertions_role($role, $name), 'toBeInViewport(%s)');
    }

    #[Then('the :role :name should not be in the viewport')]
    public function theShouldNotBeInTheViewport(string $role, string $name): void
    {
        $this->pageAssertions_expect($this->pageAssertions_role($role, $name), 'not.toBeInViewport(%s)');
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
        if ($state === 'focused') {
            $this->pageAssertions_expectFocused($this->pageAssertions_testId($testId), 'false', '');
            return;
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
        return self::playwrightScript('vars.page.getByTestId(%s).first()', $testId);
    }

    private function pageAssertions_css(string $selector): string
    {
        return self::playwrightScript('vars.page.locator(%s).first()', $selector);
    }

    private function pageAssertions_role(string $role, string $name, bool $includeHidden = false): string
    {
        return self::playwrightScript(
            'vars.page.getByRole(%s, %s).first()',
            $role,
            ['name' => $name, 'exact' => true] + ($includeHidden ? ['includeHidden' => true] : [])
        );
    }

    private function pageAssertions_timeoutOptions(): string
    {
        return self::playwrightScript('%s', ['timeout' => $this->pageAssertions_timeout ?? $this->playwrightTimeout]);
    }

    /**
     * Runs a web-first assertion: `await expect(<subject>).<matcher>` - it waits up to the timeout for the expected
     * state and fails with Playwright's message (expected and received value, call log). In $matcher the last %s is
     * the options with the timeout, a %s before it the expected value.
     */
    private function pageAssertions_expect(string $subject, string $matcher, ?string $expected = null): void
    {
        $options = JsCode::of($this->pageAssertions_timeoutOptions());
        $this->playwrightRun(
            'await expect(%s).' . $matcher . ';',
            JsCode::of($subject),
            ...($expected === null ? [$options] : [$expected, $options])
        );
    }

    /**
     * Like {@see pageAssertions_expect()} (default matcher: toBeFocused), but the failure also tells which element has
     * the focus instead - or $hiddenMessage, if $hiddenCheck (a JS condition) is true.
     */
    private function pageAssertions_expectFocused(string $subject, string $hiddenCheck, string $hiddenMessage, string $matcher = 'toBeFocused(%s)'): void
    {
        $this->playwrightRun(<<<'JS'
            try {
                await expect(%1$s).%2$s;
            } catch (error) {
                if (%3$s) {
                    throw new Error(%4$s);
                }
                const focused = await vars.page.evaluate(() => document.activeElement?.outerHTML.slice(0, 150) ?? 'nothing');
                throw new Error(`${error.message}\nFocused instead: ${focused}`);
            }
        JS,
            JsCode::of($subject),
            JsCode::of(sprintf($matcher, $this->pageAssertions_timeoutOptions())),
            JsCode::of($hiddenCheck),
            $hiddenMessage
        );
    }
}
