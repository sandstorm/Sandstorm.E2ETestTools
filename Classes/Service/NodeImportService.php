<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Service;

use Behat\Gherkin\Node\TableNode;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns a node fixture YAML file into the same table "I have the following nodes in site ..." takes.
 *
 * Expected format (one entry per row of that table):
 *
 *   nodes:
 *     - nodeAggregateId: homepage
 *       parent: ''                      # empty: the site node; "owner/name": tethered child; else a NodeAggregateId
 *       nodeType: 'Your.SitePackageKey:Document.StartPage'
 *       properties: { uriPathSegment: site, title: Homepage }
 *       dimensionSpacePoint: { language: de }
 *   references:                        # optional, set after all nodes exist
 *     - nodeAggregateId: homepage
 *       referenceName: privacyPage
 *       targets: [privacy]
 *       dimensionSpacePoint: { language: de }
 */
class NodeImportService
{
    public static function parseYamlFile(string $absoluteYamlFilePath): array
    {
        try {
            $yaml = Yaml::parseFile($absoluteYamlFilePath);
        } catch (\Exception $e) {
            throw new \RuntimeException("YAML file not found. Path: " . $absoluteYamlFilePath . " \n Error Message: " . $e);
        }
        if (!is_array($yaml) || !is_array($yaml['nodes'] ?? null)) {
            throw new \RuntimeException('Invalid YAML structure. Expected top-level key "nodes". Path: ' . $absoluteYamlFilePath);
        }
        return $yaml;
    }

    /**
     * @param array $yamlArray parsed yaml, see class comment
     * @param array $overwrites optional property overwrites - ['<nodeAggregateId>' => ['<property>' => <value>, ...], ...]
     */
    public static function createTableNodeFromYamlArray(array $yamlArray, array $overwrites = []): TableNode
    {
        $tableRows = [['NodeAggregateId', 'Parent', 'NodeType', 'Properties', 'DimensionSpacePoint']];

        foreach ($yamlArray['nodes'] as $index => $node) {
            if (($node['nodeAggregateId'] ?? null) === null || ($node['nodeType'] ?? null) === null) {
                throw new \RuntimeException(sprintf('Node #%d in YAML fixture needs at least "nodeAggregateId" and "nodeType".', $index));
            }
            $properties = array_merge($node['properties'] ?? [], $overwrites[$node['nodeAggregateId']] ?? []);
            $tableRows[] = [
                $node['nodeAggregateId'],
                $node['parent'] ?? '',
                $node['nodeType'],
                $properties === [] ? '{}' : json_encode($properties, JSON_THROW_ON_ERROR),
                ($node['dimensionSpacePoint'] ?? []) === [] ? '' : json_encode($node['dimensionSpacePoint'], JSON_THROW_ON_ERROR),
            ];
        }

        return new TableNode($tableRows);
    }

    /**
     * @return TableNode|null the "the following node references:" table, null if the YAML has no references
     */
    public static function createReferencesTableNodeFromYamlArray(array $yamlArray): ?TableNode
    {
        if (($yamlArray['references'] ?? []) === []) {
            return null;
        }
        $tableRows = [['NodeAggregateId', 'ReferenceName', 'Targets', 'DimensionSpacePoint']];
        foreach ($yamlArray['references'] as $index => $reference) {
            if (($reference['nodeAggregateId'] ?? null) === null || ($reference['referenceName'] ?? null) === null || ($reference['targets'] ?? null) === null) {
                throw new \RuntimeException(sprintf('Reference #%d in YAML fixture needs "nodeAggregateId", "referenceName" and "targets".', $index));
            }
            $tableRows[] = [
                $reference['nodeAggregateId'],
                $reference['referenceName'],
                implode(',', (array)$reference['targets']),
                ($reference['dimensionSpacePoint'] ?? []) === [] ? '' : json_encode($reference['dimensionSpacePoint'], JSON_THROW_ON_ERROR),
            ];
        }
        return new TableNode($tableRows);
    }
}
