<?php

namespace Sandstorm\E2ETestTools\Service;

use Behat\Gherkin\Node\TableNode;
use Symfony\Component\Yaml\Yaml;

class NodeImportService
{
    public static function parseYamlFile(string $absoluteYamlFilePath): array
    {
        try {
            $yaml = Yaml::parseFile($absoluteYamlFilePath);
        } catch (\Exception $e) {
            throw new \RuntimeException("YAML file not found. Path: " . $absoluteYamlFilePath . " \n Error Message: " . $e);
        }
        if (!is_array($yaml) || !isset($yaml['nodes']) || !is_array($yaml['nodes'])) {
            throw new \RuntimeException('Invalid YAML structure. Expected top-level key "nodes". Path: ' . $absoluteYamlFilePath);
        }
        return $yaml;
    }

    /**
     * Convert a via Symfony parsed yaml to Gherkin TableNode
     *
     * @param array $yamlArray parsed yaml
     * @return TableNode
     */
    public static function createTableNodeFromYamlArray(array $yamlArray): TableNode
    {
        $tableRows = [];

        $tableRows[] = [
            'Path' => 'Path',
            'Node Type' => 'Node Type',
            'Properties' => 'Properties',
            'HiddenInIndex' => 'HiddenInIndex',
        ];

        foreach ($yamlArray['nodes'] as $identifier => $nodeArray) {
            NodeImportService::recursiveCreateTableRowFromChildren($identifier, $nodeArray,$tableRows);
        }

        return new TableNode($tableRows);
    }

    private static function recursiveCreateTableRowFromChildren(string $identifier, array $nodeArray, array &$rows): void
    {
        $node = [];
        $node['Path'] = $nodeArray['path'] ?: '/';
        $node['Node Type'] = $nodeArray['type'];
        $node['Properties'] = json_encode($nodeArray['properties']);
        $node['HiddenInIndex'] = "false";

        $rows[] = $node;

        foreach ($nodeArray['children'] as $identifier => $child) {
            NodeImportService::recursiveCreateTableRowFromChildren($identifier, $child, $rows);
        }
    }

}
