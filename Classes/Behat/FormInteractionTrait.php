<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\BeforeScenario;
use Behat\Step\Then;
use Behat\Step\When;

/**
 * Clicks, form fields, focus and keys in the visitor's words: buttons and links by their caption, fields by their label.
 * Locating by label also checks that the field has one.
 *
 * Needs {@see PlaywrightTrait}. Opt-in: use it in your FeatureContext; remove project steps with the same wording first,
 * otherwise Behat reports them as ambiguous.
 */
trait FormInteractionTrait
{
    /**
     * Upload paths are relative to the feature file.
     */
    private string $formInteraction_currentFeatureFile = '';

    #[BeforeScenario]
    public function formInteractionBeforeScenario(BeforeScenarioScope $scope): void
    {
        $this->formInteraction_currentFeatureFile = $scope->getFeature()->getFile() ?? '';
    }

    #[When('I click the button :name')]
    public function iClickTheButton(string $name): void
    {
        $this->formInteraction_run('getByRole("button", {name: %s, exact: true}).click()', $name);
    }

    #[When('I click the link :name')]
    public function iClickTheLink(string $name): void
    {
        $this->formInteraction_run('getByRole("link", {name: %s, exact: true}).click()', $name);
    }

    #[When('I click the element with test id :testId')]
    public function iClickTheElementWithTestId(string $testId): void
    {
        $this->formInteraction_run('getByTestId(%s).click()', $testId);
    }

    #[When('I fill :value into the field :label')]
    public function iFillIntoTheField(string $value, string $label): void
    {
        $this->formInteraction_run('getByLabel(%s, {exact: true}).fill(%s)', $label, $value);
    }

    #[Then('the field :label should have the value :value')]
    public function theFieldShouldHaveTheValue(string $label, string $value): void
    {
        $this->formInteraction_expect('getByLabel(%s, {exact: true})', 'toHaveValue(%s, %s)', $label, $value);
    }

    #[When('I check the checkbox :label')]
    public function iCheckTheCheckbox(string $label): void
    {
        $this->formInteraction_run('getByLabel(%s, {exact: true}).check()', $label);
    }

    #[When('I uncheck the checkbox :label')]
    public function iUncheckTheCheckbox(string $label): void
    {
        $this->formInteraction_run('getByLabel(%s, {exact: true}).uncheck()', $label);
    }

    #[Then('the checkbox :label should be checked')]
    public function theCheckboxShouldBeChecked(string $label): void
    {
        $this->formInteraction_expect('getByLabel(%s, {exact: true})', 'toBeChecked(%s)', $label);
    }

    #[Then('the checkbox :label should not be checked')]
    public function theCheckboxShouldNotBeChecked(string $label): void
    {
        $this->formInteraction_expect('getByLabel(%s, {exact: true})', 'not.toBeChecked(%s)', $label);
    }

    #[When('I choose the radio button :label')]
    public function iChooseTheRadioButton(string $label): void
    {
        $this->formInteraction_run('getByLabel(%s, {exact: true}).check()', $label);
    }

    #[When('I select :option in the field :label')]
    public function iSelectInTheField(string $option, string $label): void
    {
        $this->formInteraction_run('getByLabel(%s, {exact: true}).selectOption({label: %s})', $label, $option);
    }

    #[Then('the field :label should have :option selected')]
    public function theFieldShouldHaveSelected(string $label, string $option): void
    {
        $this->playwrightRun(
            'await expect.poll(() => vars.page.getByLabel(%s, {exact: true}).evaluate((select) => Array.from(select.selectedOptions).map((option) => option.label)), {message: %s, timeout: %s}).toContain(%s);',
            $label,
            sprintf('selected options of the field "%s"', $label),
            $this->playwrightTimeout,
            $option
        );
    }

    /**
     * The browser's own validation (required, type="email", pattern, ...) - it blocks the submit before the server
     * sees it.
     */
    #[Then('the field :label should be invalid')]
    public function theFieldShouldBeInvalid(string $label): void
    {
        $this->playwrightRun(
            'await expect.poll(() => vars.page.getByLabel(%s, {exact: true}).evaluate((field) => field.validity.valid), {message: %s, timeout: %s}).toBe(false);',
            $label,
            sprintf('The field "%s" is invalid', $label),
            $this->playwrightTimeout
        );
    }

    /**
     * The file is sent as content, not as path: Behat and the browser usually run on different machines.
     *
     * @param string $fileName relative to the feature file
     */
    #[When('I upload the file :fileName to the field :label')]
    public function iUploadTheFileToTheField(string $fileName, string $label): void
    {
        $path = dirname($this->formInteraction_currentFeatureFile) . '/' . $fileName;
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('File to upload not found: %s', $path));
        }
        $mimeType = mime_content_type($path);
        $this->playwrightRun(
            'await vars.page.getByLabel(%s, {exact: true}).setInputFiles({name: %s, mimeType: %s, buffer: Buffer.from(%s, "base64")});',
            $label,
            basename($path),
            $mimeType !== false ? $mimeType : 'application/octet-stream',
            base64_encode((string)file_get_contents($path))
        );
    }

    /**
     * :key in Playwright's notation, e.g. Tab, Enter, Escape, ArrowDown, Shift+Tab
     */
    #[When('I press the key :key')]
    public function iPressTheKey(string $key): void
    {
        $this->playwrightRun('await vars.page.keyboard.press(%s);', $key);
    }

    /**
     * Like reaching the element with Tab - without pressing Tab through the whole page. :role is an ARIA role
     * (button, link, textbox, ...), :name the accessible name.
     */
    #[When('I focus the :role :name')]
    public function iFocusThe(string $role, string $name): void
    {
        $this->formInteraction_run('getByRole(%s, {name: %s, exact: true}).first().focus()', $role, $name);
    }

    /**
     * A web-first assertion: `await expect(vars.page.<locator>).<matcher>` waits for the expected state and fails with
     * Playwright's message. %s in $locator and $matcher are replaced by the arguments as JS literals, in this order -
     * the last %s of $matcher by the options with the timeout.
     */
    private function formInteraction_expect(string $locator, string $matcher, string ...$arguments): void
    {
        $this->playwrightRun(
            'await expect(vars.page.' . $locator . ').' . $matcher . ';',
            ...[...$arguments, ['timeout' => $this->playwrightTimeout]]
        );
    }

    /**
     * Runs `await vars.page.<call>` - every %s in $call is replaced by the next argument as JS literal - and returns
     * its result.
     */
    private function formInteraction_run(string $call, string ...$arguments): mixed
    {
        return $this->playwrightRun('return await vars.page.' . $call . ';', ...$arguments);
    }
}
