<?php

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Behat\Gherkin\Node\TableNode;
use Symfony\Component\Yaml\Yaml;

/**
 */
trait NodeImportTrait
{
    /**
     * @Given I have the following nodes from file :fileName
     * @When I create the following nodes from file :fileName
     */
    public function iHaveTheFollowingNodesFromFile(string $fileName): void
    {
        $this->executeFlowCommand("cache:flushone --identifier Neos_Fusion_Content ", "flush Fusion cache");
        $this->executeFlowCommand("cache:warmup", "warmup Fusion cache");

        $pwd = $this->getAbsoluteFixturePathFromFileName($fileName);
        try {
            $yaml = Yaml::parseFile($pwd);
        } catch (\Exception $e) {
            throw new \RuntimeException("YAML file not found. Path: " . $pwd . " \n Error Message: " . $e);
        }
        if (!is_array($yaml) || !isset($yaml['nodes']) || !is_array($yaml['nodes'])) {
            throw new \RuntimeException('Invalid YAML structure. Expected top-level key "nodes". Path: ' . $pwd);
        }

        $table = $this->createTableNodeFromYamlArray($yaml);

        $this->iHaveTheFollowingNodes($table);
    }

    private function createTableNodeFromYamlArray(array $yamlArray): TableNode
    {
        $tableRows = [];
        foreach ($yamlArray['nodes'] as $identifier => $nodeArray) {
            $tableRows = $this->recursiveCreateTableRowFromChildren($identifier, $nodeArray, $tableRows);
        }

        return new TableNode($tableRows);
    }

    private function recursiveCreateTableRowFromChildren(string $identifier, array $nodeArray, array &$rows): array
    {
        $node = [];
        $node['Path'] = $nodeArray['path'] ?: '/';
        $node['Identifier'] = $identifier;
        $node['Properties'] = json_encode($nodeArray['properties']);
        $node['Node Type'] = $nodeArray['type'];
        $rows[] = $node;

        foreach ($nodeArray['children'] as $identifier => $child) {
            $this->recursiveCreateTableRowFromChildren($identifier, $child, $rows);
        }
        return $rows;
    }

    /**
     * Get the
     */
    private function getAbsoluteFixturePathFromFileName(string $fileName): string
    {
        $pwdParts = explode("/", $this->getCurrentTestFilePath());
        $pwdParts[count($pwdParts) - 1] = $fileName;
        return implode("/", $pwdParts);
    }


    /**
     * @When /^I overwrite node properties with following values:$/
     */
    public function iOverwriteNodePropertiesWithFollowingValues($fileName, $table): void
    {
    }

    /**
     * @When /^I overwrite following alias with values:$/
     */
    public function iOverwriteFollowingAliasWithValues($fileName, $table): void
    {
    }

}
