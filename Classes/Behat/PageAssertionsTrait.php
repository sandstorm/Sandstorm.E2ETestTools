<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Step\Then;
use Sandstorm\E2ETestTools\Playwright\JsCode;

/**
 * Assertions on the page in the browser: title, texts, focus, elements by role and accessible name or by test id
 * (`data-testid`).
 *
 * An element step checks the copy the visitor sees - a hidden copy (e.g. the desktop variant on a mobile viewport) is
 * ignored, two visible matches are an error ("the button" is one button). "should be hidden" passes when no copy is
 * visible, also when there is none at all.
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
        $this->pageAssertions_expectVisible($this->pageAssertions_css($selector), 'toContainText(%s, {useInnerText: true, ...%s})', $text);
    }

    /**
     * Also passes when no element matches :selector visibly - check that it's visible first, if that matters.
     */
    #[Then('there should not be the text :text in :selector')]
    public function thereShouldNotBeTheTextIn(string $text, string $selector): void
    {
        $this->playwrightRunOnVisible(
            $this->pageAssertions_css($selector),
            'if (await visible.count() > 0) { await expect(visible).not.toContainText(%s, {useInnerText: true, ...%s}); }',
            $text,
            JsCode::of($this->pageAssertions_timeoutOptions())
        );
    }

    /**
     * :role is an ARIA role (button, link, heading, textbox, ...) or a word of the form steps (field, radio button),
     * :name the accessible name - the exact caption or label. Fails with the element that has the focus instead.
     */
    #[Then('the :role :name should be focused')]
    public function theShouldBeFocused(string $role, string $name): void
    {
        $this->pageAssertions_expectFocused(
            $this->pageAssertions_role($role, $name) . '.filter({visible: true})',
            // getByRole() finds inert elements too - so check the ancestors
            sprintf(
                'await %s.evaluateAll((elements) => elements.length > 0 && elements.every((element) => element.closest("[inert], [aria-hidden=true]") !== null))',
                $this->pageAssertions_role($role, $name, true)
            ),
            sprintf('The %s "%s" is hidden from assistive technology (inert or aria-hidden), so it can\'t be focused.', $role, $name)
        );
    }

    /**
     * An element matching :selector is focused or contains the focused element (`:focus-within`) - nested matches are
     * fine.
     */
    #[Then('the focus should be inside :selector')]
    public function theFocusShouldBeInside(string $selector): void
    {
        $this->pageAssertions_expectFocused(
            sprintf('%s.and(vars.page.locator(":focus-within")).first()', $this->pageAssertions_css($selector)),
            'false',
            '',
            'toBeAttached(%s)'
        );
    }

    #[Then('the :role :name should be visible')]
    public function theShouldBeVisible(string $role, string $name): void
    {
        $this->pageAssertions_expectVisible($this->pageAssertions_role($role, $name), 'toBeVisible(%s)');
    }

    /**
     * No visible copy - finds elements hidden from assistive technology too. Like Playwright's toBeHidden() it also
     * passes if there is no such element - check the element is visible before the action that should hide it.
     */
    #[Then('the :role :name should be hidden')]
    public function theShouldBeHidden(string $role, string $name): void
    {
        $this->pageAssertions_expectVisible($this->pageAssertions_role($role, $name, true), 'toHaveCount(0, %s)');
    }

    #[Then('the :role :name should be enabled')]
    public function theShouldBeEnabled(string $role, string $name): void
    {
        $this->pageAssertions_expectVisible($this->pageAssertions_role($role, $name), 'toBeEnabled(%s)');
    }

    /**
     * Also passes for aria-disabled="true".
     */
    #[Then('the :role :name should be disabled')]
    public function theShouldBeDisabled(string $role, string $name): void
    {
        $this->pageAssertions_expectVisible($this->pageAssertions_role($role, $name), 'toBeDisabled(%s)');
    }

    /**
     * "radio button" is two words - :role matches one.
     */
    #[Then('the radio button :name should be visible')]
    public function theRadioButtonShouldBeVisible(string $name): void
    {
        $this->theShouldBeVisible('radio button', $name);
    }

    #[Then('the radio button :name should be hidden')]
    public function theRadioButtonShouldBeHidden(string $name): void
    {
        $this->theShouldBeHidden('radio button', $name);
    }

    #[Then('the radio button :name should be enabled')]
    public function theRadioButtonShouldBeEnabled(string $name): void
    {
        $this->theShouldBeEnabled('radio button', $name);
    }

    #[Then('the radio button :name should be disabled')]
    public function theRadioButtonShouldBeDisabled(string $name): void
    {
        $this->theShouldBeDisabled('radio button', $name);
    }

    #[Then('the :role :name should be in the viewport')]
    public function theShouldBeInTheViewport(string $role, string $name): void
    {
        $this->pageAssertions_expectVisible($this->pageAssertions_role($role, $name), 'toBeInViewport(%s)');
    }

    #[Then('the :role :name should not be in the viewport')]
    public function theShouldNotBeInTheViewport(string $role, string $name): void
    {
        $this->pageAssertions_expectVisible($this->pageAssertions_role($role, $name), 'not.toBeInViewport(%s)');
    }

    #[Then('the element with test id :testId should be visible')]
    public function theElementWithTestIdShouldBeVisible(string $testId): void
    {
        $this->pageAssertions_expectVisible($this->pageAssertions_testId($testId), 'toBeVisible(%s)');
    }

    /**
     * No visible copy - also passes if there is no such element.
     */
    #[Then('the element with test id :testId should be hidden')]
    public function theElementWithTestIdShouldBeHidden(string $testId): void
    {
        $this->pageAssertions_expectVisible($this->pageAssertions_testId($testId), 'toHaveCount(0, %s)');
    }

    #[Then('the element with test id :testId should be focused')]
    public function theElementWithTestIdShouldBeFocused(string $testId): void
    {
        $this->pageAssertions_expectFocused($this->pageAssertions_testId($testId) . '.filter({visible: true})', 'false', '');
    }

    #[Then('the element with test id :testId should be enabled')]
    public function theElementWithTestIdShouldBeEnabled(string $testId): void
    {
        $this->pageAssertions_expectVisible($this->pageAssertions_testId($testId), 'toBeEnabled(%s)');
    }

    /**
     * Also passes for aria-disabled="true".
     */
    #[Then('the element with test id :testId should be disabled')]
    public function theElementWithTestIdShouldBeDisabled(string $testId): void
    {
        $this->pageAssertions_expectVisible($this->pageAssertions_testId($testId), 'toBeDisabled(%s)');
    }

    #[Then('the element with test id :testId should be in the viewport')]
    public function theElementWithTestIdShouldBeInTheViewport(string $testId): void
    {
        $this->pageAssertions_expectVisible($this->pageAssertions_testId($testId), 'toBeInViewport(%s)');
    }

    #[Then('the element with test id :testId should not be in the viewport')]
    public function theElementWithTestIdShouldNotBeInTheViewport(string $testId): void
    {
        $this->pageAssertions_expectVisible($this->pageAssertions_testId($testId), 'not.toBeInViewport(%s)');
    }

    private function pageAssertions_testId(string $testId): string
    {
        return self::playwrightScript('vars.page.getByTestId(%s)', $testId);
    }

    private function pageAssertions_css(string $selector): string
    {
        return self::playwrightScript('vars.page.locator(%s)', $selector);
    }

    private function pageAssertions_role(string $role, string $name, bool $includeHidden = false): string
    {
        return self::playwrightRoleLocator($role, $name, $includeHidden);
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
     * Like {@see pageAssertions_expect()}, on the visible copy of $locator - see {@see playwrightRunOnVisible()}.
     */
    private function pageAssertions_expectVisible(string $locator, string $matcher, ?string $expected = null): void
    {
        $options = JsCode::of($this->pageAssertions_timeoutOptions());
        $this->playwrightRunOnVisible(
            $locator,
            'await expect(visible).' . $matcher . ';',
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
