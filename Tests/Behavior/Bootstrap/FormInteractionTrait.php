<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\BeforeScenario;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;
use Sandstorm\E2ETestTools\Playwright\JsValue;

/**
 * Clicks, form fields and keys in the visitor's words: buttons and links by their caption, fields by their label.
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

    #[When('I click the button :caption')]
    public function iClickTheButton(string $caption): void
    {
        $this->formInteraction_run('getByRole("button", {name: %s, exact: true}).click()', $caption);
    }

    #[When('I click the link :caption')]
    public function iClickTheLink(string $caption): void
    {
        $this->formInteraction_run('getByRole("link", {name: %s, exact: true}).click()', $caption);
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
        Assert::assertSame($value, $this->formInteraction_run('getByLabel(%s, {exact: true}).inputValue()', $label), sprintf('Unexpected value of the field "%s".', $label));
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
        Assert::assertTrue($this->formInteraction_run('getByLabel(%s, {exact: true}).isChecked()', $label), sprintf('The checkbox "%s" is not checked.', $label));
    }

    #[Then('the checkbox :label should not be checked')]
    public function theCheckboxShouldNotBeChecked(string $label): void
    {
        Assert::assertFalse($this->formInteraction_run('getByLabel(%s, {exact: true}).isChecked()', $label), sprintf('The checkbox "%s" is checked.', $label));
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
        $selected = $this->formInteraction_run('getByLabel(%s, {exact: true}).evaluate((select) => Array.from(select.selectedOptions).map((option) => option.label))', $label);
        Assert::assertContains($option, (array)$selected, sprintf('"%s" is not selected in the field "%s" (selected: %s).', $option, $label, implode(', ', (array)$selected)));
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
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(
            'await vars.page.getByLabel(%s, {exact: true}).setInputFiles({name: %s, mimeType: %s, buffer: Buffer.from(%s, "base64")});',
            JsValue::of($label),
            JsValue::of(basename($path)),
            JsValue::of($mimeType !== false ? $mimeType : 'application/octet-stream'),
            JsValue::of(base64_encode((string)file_get_contents($path)))
        ));
    }

    /**
     * :key in Playwright's notation, e.g. Tab, Enter, Escape, ArrowDown, Shift+Tab
     */
    #[When('I press the key :key')]
    public function iPressTheKey(string $key): void
    {
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf('await vars.page.keyboard.press(%s);', JsValue::of($key)));
    }

    /**
     * Runs `await vars.page.<call>` - every %s in $call is replaced by the next argument as JS literal - and returns
     * its result.
     */
    private function formInteraction_run(string $call, string ...$arguments): mixed
    {
        return $this->playwrightConnector->execute(
            $this->requirePlaywrightContext(),
            'return await vars.page.' . sprintf($call, ...array_map(JsValue::of(...), $arguments)) . ';'
        );
    }
}
