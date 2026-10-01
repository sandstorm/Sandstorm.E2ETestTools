<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Service;

use Behat\Gherkin\Node\TableNode;
use Symfony\Component\Yaml\Exception\ParseException;
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
 *       hidden: true                    # optional, default false
 *   references:                        # optional, set after all nodes exist
 *     - nodeAggregateId: homepage
 *       referenceName: privacyPage
 *       targets: [privacy]
 *       dimensionSpacePoint: { language: de }
 *       properties: { label: Privacy }  # optional reference properties
 */
class NodeImportService
{
    public static function parseYamlFile(string $absoluteYamlFilePath): array
    {
        if (!is_file($absoluteYamlFilePath)) {
            throw new \RuntimeException('YAML fixture file not found: ' . $absoluteYamlFilePath, 1727700001);
        }
        try {
            $yaml = Yaml::parseFile($absoluteYamlFilePath);
        } catch (ParseException $e) {
            throw new \RuntimeException(sprintf('YAML fixture file %s is invalid: %s', $absoluteYamlFilePath, $e->getMessage()), 1727700002, $e);
        }
        if (!is_array($yaml) || !is_array($yaml['nodes'] ?? null)) {
            throw new \RuntimeException('Invalid YAML structure. Expected top-level key "nodes" with a list of nodes. Path: ' . $absoluteYamlFilePath, 1727700003);
        }
        return $yaml;
    }

    /**
     * @param array $yamlArray parsed yaml, see class comment
     * @param array<string,array<string,string>> $overwrites property overwrites from a Gherkin table -
     *     ['<nodeAggregateId>' => ['<property>' => '<value>', ...], ...]; values that are valid JSON are decoded
     *     (true, 42, null, "text", [...], {...}), everything else is used as text
     */
    public static function createTableNodeFromYamlArray(array $yamlArray, array $overwrites = []): TableNode
    {
        $nodes = $yamlArray['nodes'] ?? [];
        if (!array_is_list($nodes)) {
            throw new \RuntimeException('Invalid YAML fixture format: "nodes" must be a list. Nodes keyed by identifier (with path/type/children) are the old Neos 8 export format - re-export the content with the current export.', 1727700004);
        }

        $tableRows = [['NodeAggregateId', 'Parent', 'NodeType', 'Properties', 'DimensionSpacePoint', 'Hidden']];
        $nodeAggregateIds = [];
        foreach ($nodes as $index => $node) {
            $nodeAggregateId = self::requireNonEmptyString($node, 'nodeAggregateId', 'Node', $index);
            $nodeType = self::requireNonEmptyString($node, 'nodeType', 'Node', $index);
            $properties = self::optionalMap($node, 'properties', 'Node', $index);
            $dimensionSpacePoint = self::optionalMap($node, 'dimensionSpacePoint', 'Node', $index);
            $hidden = $node['hidden'] ?? false;
            if (!is_bool($hidden)) {
                throw new \RuntimeException(sprintf('Node #%s in YAML fixture: "hidden" must be true or false.', $index), 1727700009);
            }

            foreach ($overwrites[$nodeAggregateId] ?? [] as $propertyName => $value) {
                $properties[$propertyName] = self::decodeOverwriteValue($value);
            }
            $nodeAggregateIds[] = $nodeAggregateId;

            $tableRows[] = [
                $nodeAggregateId,
                (string)($node['parent'] ?? ''),
                $nodeType,
                $properties === [] ? '{}' : json_encode($properties, JSON_THROW_ON_ERROR),
                $dimensionSpacePoint === [] ? '' : json_encode($dimensionSpacePoint, JSON_THROW_ON_ERROR),
                $hidden ? 'true' : '',
            ];
        }

        $unknownOverwriteIds = array_diff(array_map(strval(...), array_keys($overwrites)), $nodeAggregateIds);
        if ($unknownOverwriteIds !== []) {
            throw new \RuntimeException(sprintf('Overwrites for nodes that are not part of the YAML fixture: %s', implode(', ', $unknownOverwriteIds)), 1727700005);
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
        $tableRows = [['NodeAggregateId', 'ReferenceName', 'Targets', 'DimensionSpacePoint', 'Properties']];
        foreach ($yamlArray['references'] as $index => $reference) {
            $nodeAggregateId = self::requireNonEmptyString($reference, 'nodeAggregateId', 'Reference', $index);
            $referenceName = self::requireNonEmptyString($reference, 'referenceName', 'Reference', $index);
            $targets = array_map(strval(...), (array)($reference['targets'] ?? []));
            if ($targets === [] || in_array('', $targets, true)) {
                throw new \RuntimeException(sprintf('Reference #%d in YAML fixture needs "targets" with at least one non-empty NodeAggregateId.', $index), 1727700006);
            }
            $dimensionSpacePoint = self::optionalMap($reference, 'dimensionSpacePoint', 'Reference', $index);
            $properties = self::optionalMap($reference, 'properties', 'Reference', $index);
            $tableRows[] = [
                $nodeAggregateId,
                $referenceName,
                implode(',', $targets),
                $dimensionSpacePoint === [] ? '' : json_encode($dimensionSpacePoint, JSON_THROW_ON_ERROR),
                $properties === [] ? '' : json_encode($properties, JSON_THROW_ON_ERROR),
            ];
        }
        return new TableNode($tableRows);
    }

    private static function requireNonEmptyString(mixed $entry, string $field, string $entryType, int|string $index): string
    {
        $value = is_array($entry) ? ($entry[$field] ?? null) : null;
        if (!is_string($value) && !is_int($value) || (string)$value === '') {
            throw new \RuntimeException(sprintf('%s #%s in YAML fixture needs a non-empty "%s".', $entryType, $index, $field), 1727700007);
        }
        return (string)$value;
    }

    /**
     * @return array<string,mixed>
     */
    private static function optionalMap(array $entry, string $field, string $entryType, int|string $index): array
    {
        $value = $entry[$field] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \RuntimeException(sprintf('%s #%s in YAML fixture: "%s" must be a map (name: value).', $entryType, $index, $field), 1727700008);
        }
        return $value;
    }

    private static function decodeOverwriteValue(string $value): mixed
    {
        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }
    }
}
