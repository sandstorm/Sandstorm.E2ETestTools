<?php

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Behat\Gherkin\Node\TableNode;
use Symfony\Component\Yaml\Yaml;

/**
 */
trait NodeImportTrait
{
    /**
     * =======================================================================
     * ========================= Utility methods =============================
     * =======================================================================
     */

    /**
     * Convert a via Symfony parsed yaml to Gherkin TableNode
     *
     * @param array $yamlArray parsed yaml
     * @return TableNode
     */
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
     * Get absolute fixture file path from to test file relative path
     *
     * @param string $relativeFilePath relative file path to test file
     * @return string absolute fixture file path
     */
    private function getAbsoluteFixturePathFromFileName(string $relativeFilePath): string
    {
        $pwdParts = explode("/", $this->getCurrentTestFilePath());
        $pwdParts[count($pwdParts) - 1] = $relativeFilePath;
        return implode("/", $pwdParts);
    }

    /**
     * =======================================================================
     * =========================== Test steps ================================
     * =======================================================================
     */

    /**
     * @Given I have the following nodes from file :fileName
     * @When I create the following nodes from file :fileName
     */
    public function iHaveTheFollowingNodesFromFile(string $fileName): void
    {
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
