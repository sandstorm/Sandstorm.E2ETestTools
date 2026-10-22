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
 * The steps act on the copy the visitor sees - a hidden copy (e.g. the desktop variant on a mobile viewport) is
 * ignored, two visible matches are an error. A control hidden with display: none can't be used, also not with the
 * keyboard - the step fails.
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
        $this->formInteraction_run($this->formInteraction_role('button', $name), 'click()');
    }

    #[When('I click the link :name')]
    public function iClickTheLink(string $name): void
    {
        $this->formInteraction_run($this->formInteraction_role('link', $name), 'click()');
    }

    #[When('I click the element with test id :testId')]
    public function iClickTheElementWithTestId(string $testId): void
    {
        $this->formInteraction_run(self::playwrightScript('vars.page.getByTestId(%s)', $testId), 'click()');
    }

    #[When('I fill :value into the field :label')]
    public function iFillIntoTheField(string $value, string $label): void
    {
        $this->formInteraction_run($this->formInteraction_label($label), 'fill(%s)', $value);
    }

    #[Then('the field :label should have the value :value')]
    public function theFieldShouldHaveTheValue(string $label, string $value): void
    {
        $this->formInteraction_expect($this->formInteraction_label($label), 'toHaveValue(%s, %s)', $value);
    }

    #[When('I check the checkbox :label')]
    public function iCheckTheCheckbox(string $label): void
    {
        $this->formInteraction_run($this->formInteraction_label($label), 'check()');
    }

    #[When('I uncheck the checkbox :label')]
    public function iUncheckTheCheckbox(string $label): void
    {
        $this->formInteraction_run($this->formInteraction_label($label), 'uncheck()');
    }

    #[Then('the checkbox :label should be checked')]
    public function theCheckboxShouldBeChecked(string $label): void
    {
        $this->formInteraction_expect($this->formInteraction_label($label), 'toBeChecked(%s)');
    }

    #[Then('the checkbox :label should not be checked')]
    public function theCheckboxShouldNotBeChecked(string $label): void
    {
        $this->formInteraction_expect($this->formInteraction_label($label), 'not.toBeChecked(%s)');
    }

    #[When('I choose the radio button :label')]
    public function iChooseTheRadioButton(string $label): void
    {
        $this->formInteraction_run($this->formInteraction_label($label), 'check()');
    }

    #[When('I select :option in the field :label')]
    public function iSelectInTheField(string $option, string $label): void
    {
        $this->formInteraction_run($this->formInteraction_label($label), 'selectOption({label: %s})', $option);
    }

    #[Then('the field :label should have :option selected')]
    public function theFieldShouldHaveSelected(string $label, string $option): void
    {
        $this->playwrightRunOnVisible(
            $this->formInteraction_label($label),
            'await expect.poll(() => visible.evaluate((select) => Array.from(select.selectedOptions).map((option) => option.label)), {message: %s, timeout: %s}).toContain(%s);',
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
        $this->playwrightRunOnVisible(
            $this->formInteraction_label($label),
            'await expect.poll(() => visible.evaluate((field) => field.validity.valid), {message: %s, timeout: %s}).toBe(false);',
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
        $this->playwrightRunOnVisible(
            $this->formInteraction_label($label),
            'await visible.setInputFiles({name: %s, mimeType: %s, buffer: Buffer.from(%s, "base64")});',
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
        $this->formInteraction_run($this->formInteraction_role($role, $name), 'focus()');
    }

    private function formInteraction_label(string $label): string
    {
        return self::playwrightScript('vars.page.getByLabel(%s, {exact: true})', $label);
    }

    private function formInteraction_role(string $role, string $name): string
    {
        return self::playwrightScript('vars.page.getByRole(%s, {name: %s, exact: true})', $role, $name);
    }

    /**
     * A web-first assertion on the visible copy of $locator: `await expect(visible).<matcher>` waits for the expected
     * state and fails with Playwright's message. %s in $matcher are replaced by the arguments as JS literals, in this
     * order - the last %s by the options with the timeout.
     */
    private function formInteraction_expect(string $locator, string $matcher, string ...$arguments): void
    {
        $this->playwrightRunOnVisible(
            $locator,
            'await expect(visible).' . $matcher . ';',
            ...[...$arguments, ['timeout' => $this->playwrightTimeout]]
        );
    }

    /**
     * Runs `await visible.<call>` on the visible copy of $locator - every %s in $call is replaced by the next argument
     * as JS literal - and returns its result.
     */
    private function formInteraction_run(string $locator, string $call, string ...$arguments): mixed
    {
        return $this->playwrightRunOnVisible($locator, 'return await visible.' . $call . ';', ...$arguments);
    }
}
