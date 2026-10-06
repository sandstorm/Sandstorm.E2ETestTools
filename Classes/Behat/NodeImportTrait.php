<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Sandstorm\E2ETestTools\Fixture\NodeFixture;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureYaml;

/**
 * Creates nodes from a YAML fixture file (format: see {@see NodeFixtureYaml}).
 * Needs {@see FusionRenderingTrait} in the same context, which does the actual node creation.
 */
trait NodeImportTrait
{
    abstract protected function importNodeFixture(NodeFixture $fixture, string $siteName): void;

    /**
     * Fixture file paths are relative to the feature file.
     */
    private string $nodeImport_currentFeatureFile = '';

    #[BeforeScenario]
    public function nodeImportBeforeScenario(BeforeScenarioScope $scope): void
    {
        $this->nodeImport_currentFeatureFile = $scope->getFeature()->getFile() ?? '';
    }

    #[Given("I have the following nodes from file :fileName in site :siteName")]
    #[Given("I create the following nodes from file :fileName in site :siteName")]
    public function iHaveTheFollowingNodesFromFileInSite(string $fileName, string $siteName): void
    {
        $this->importNodeFixture($this->parseFixtureFile($fileName), $siteName);
    }

    /**
     * Overwrite table columns: nodeAggregateId | property | value
     */
    #[Given("I have the following nodes from file :fileName in site :siteName with overwrites:")]
    #[Given("I create the following nodes from file :fileName in site :siteName with overwrites:")]
    public function iHaveTheFollowingNodesFromFileInSiteWithOverwrites(string $fileName, string $siteName, TableNode $overwriteTable): void
    {
        $overwrites = [];
        foreach ($overwriteTable->getHash() as $row) {
            $overwrites[$row['nodeAggregateId']][$row['property']] = $row['value'];
        }
        $this->importNodeFixture($this->parseFixtureFile($fileName)->withPropertyOverwrites($overwrites), $siteName);
    }

    private function parseFixtureFile(string $fileName): NodeFixture
    {
        return NodeFixtureYaml::parseFile(dirname($this->nodeImport_currentFeatureFile) . '/' . $fileName);
    }
}
