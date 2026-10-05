<?php

declare(strict_types=1);

use Behat\Gherkin\Node\PyStringNode;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureYaml;
use Sandstorm\E2ETestTools\Playwright\JsValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Steps for testing the fixture export itself (the "Export Node" button and `./flow e2efixture:export`) - only this
 * package's suite needs them, so they aren't shipped.
 *
 * The command steps run `./flow` from Behat, which the testing guide advises against in project tests: here the
 * command is the system under test, and its argument parsing is only covered by calling it for real.
 */
trait FixtureExportTrait
{
    private string $fixtureExport_output = '';

    private int $fixtureExport_exitCode = 0;

    /**
     * The export button sits in the inspector's "Metadata" tab.
     */
    #[When('I open the inspector tab :title')]
    public function iOpenTheInspectorTab(string $title): void
    {
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(
            'await vars.page.locator("#neos-Inspector").getByRole("tab", {name: %s, exact: true}).click();',
            JsValue::of($title)
        ));
    }

    /**
     * Clicks a button that starts a download and keeps the downloaded file as exported fixture.
     */
    #[When('I download the file of the button :caption')]
    public function iDownloadTheFileOfTheButton(string $caption): void
    {
        $this->fixtureExport_output = (string)$this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(<<<'JS'
            const [download] = await Promise.all([
                vars.page.waitForEvent("download"),
                vars.page.getByRole("button", {name: %s, exact: true}).click(),
            ]);
            const chunks = [];
            for await (const chunk of await download.createReadStream()) {
                chunks.push(chunk);
            }
            return Buffer.concat(chunks).toString("utf8");
        JS, JsValue::of($caption)));
        $this->fixtureExport_exitCode = 0;
    }

    #[Then('the button :caption should be disabled')]
    public function theButtonShouldBeDisabled(string $caption): void
    {
        $disabled = $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(
            'return await vars.page.getByRole("button", {name: %s, exact: true}).isDisabled();',
            JsValue::of($caption)
        ));
        Assert::assertTrue($disabled, sprintf('The button "%s" is enabled.', $caption));
    }

    /**
     * The arguments as doc string - they're shell arguments with quotes, e.g. homepage --dimension '{"language":"de"}'
     */
    #[When('I run the fixture export with the arguments:')]
    public function iRunTheFixtureExportWithTheArguments(PyStringNode $arguments): void
    {
        // runs in this process' Flow context (Testing/Behat), which shares the database with the system under test
        exec(FLOW_PATH_ROOT . 'flow e2efixture:export ' . $arguments->getRaw() . ' 2>&1', $output, $exitCode);
        $this->fixtureExport_output = implode("\n", $output);
        $this->fixtureExport_exitCode = $exitCode;
    }

    #[Then('the export should fail with :message')]
    public function theExportShouldFailWith(string $message): void
    {
        Assert::assertSame(1, $this->fixtureExport_exitCode, 'The export exited with ' . $this->fixtureExport_exitCode . ":\n" . $this->fixtureExport_output);
        Assert::assertStringContainsString($message, $this->fixtureExport_output);
    }

    #[Then('the exported fixture should contain the nodes :nodeAggregateIds')]
    public function theExportedFixtureShouldContainTheNodes(string $nodeAggregateIds): void
    {
        $exported = array_map(static fn ($row) => $row->nodeAggregateId, $this->fixtureExport_fixture()->nodes);
        foreach (array_map('trim', explode(',', $nodeAggregateIds)) as $nodeAggregateId) {
            Assert::assertContains($nodeAggregateId, $exported, sprintf('Node "%s" is missing in the export.', $nodeAggregateId));
        }
    }

    #[Then('the exported steps should contain :text')]
    public function theExportedStepsShouldContain(string $text): void
    {
        $this->fixtureExport_assertSucceeded();
        Assert::assertStringContainsString($text, $this->fixtureExport_output);
    }

    /**
     * The round trip: whatever the export writes, the import creates unchanged.
     */
    #[When('I import the exported fixture into an empty content repository in site :siteName')]
    public function iImportTheExportedFixtureIntoAnEmptyContentRepository(string $siteName): void
    {
        $fixture = $this->fixtureExport_fixture();
        $this->setupContentRepository();
        $this->importNodeFixture($fixture, $siteName);
    }

    private function fixtureExport_fixture(): \Sandstorm\E2ETestTools\Fixture\NodeFixture
    {
        $this->fixtureExport_assertSucceeded();
        $yaml = Yaml::parse($this->fixtureExport_output);
        Assert::assertIsArray($yaml, "The export is no YAML:\n" . $this->fixtureExport_output);
        return NodeFixtureYaml::fromArray($yaml);
    }

    private function fixtureExport_assertSucceeded(): void
    {
        Assert::assertSame(0, $this->fixtureExport_exitCode, 'The export exited with ' . $this->fixtureExport_exitCode . ":\n" . $this->fixtureExport_output);
    }
}
