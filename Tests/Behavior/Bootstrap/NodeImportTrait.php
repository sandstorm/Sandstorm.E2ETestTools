<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Sandstorm\E2ETestTools\Service\NodeImportService;

/**
 * Creates nodes from a YAML fixture file (format: see {@see NodeImportService}).
 * Needs {@see FusionRenderingTrait} in the same context, which does the actual node creation.
 */
trait NodeImportTrait
{
    private string $nodeImport_currentFeatureFile = '';

    /**
     * @BeforeScenario
     */
    public function nodeImportBeforeScenario(BeforeScenarioScope $scope): void
    {
        $this->nodeImport_currentFeatureFile = $scope->getFeature()->getFile() ?? '';
    }

    #[Given("I have the following nodes from file :fileName in site :siteName")]
    #[Given("I create the following nodes from file :fileName in site :siteName")]
    public function iHaveTheFollowingNodesFromFileInSite(string $fileName, string $siteName): void
    {
        $this->createNodesFromYaml($fileName, $siteName);
    }

    /**
     * Overwrite table columns: nodeAggregateId | property | value
     */
    #[Given("I have the following nodes from file :fileName in site :siteName with overwrites:")]
    #[Given("I create the following nodes from file :fileName in site :siteName with overwrites:")]
    public function iHaveTheFollowingNodesFromFileInSiteWithOverwrites(string $fileName, string $siteName, TableNode $overwriteTable): void
    {
        $this->createNodesFromYaml($fileName, $siteName, $overwriteTable);
    }

    /**
     * @param string $fileName YAML fixture file, relative to the feature file
     */
    private function createNodesFromYaml(string $fileName, string $siteName, ?TableNode $overwriteTable = null): void
    {
        $yaml = NodeImportService::parseYamlFile(dirname($this->nodeImport_currentFeatureFile) . '/' . $fileName);
        $overwrites = [];
        foreach ($overwriteTable?->getHash() ?? [] as $row) {
            $overwrites[$row['nodeAggregateId']][$row['property']] = $row['value'];
        }
        $this->iHaveTheFollowingNodesInSite($siteName, NodeImportService::createTableNodeFromYamlArray($yaml, $overwrites));
        $referencesTable = NodeImportService::createReferencesTableNodeFromYamlArray($yaml);
        if ($referencesTable !== null) {
            $this->iSetTheFollowingNodeReferencesInSite($referencesTable);
        }
    }
}
