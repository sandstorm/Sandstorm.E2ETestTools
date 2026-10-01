<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Fixture;

use Neos\Flow\Annotations as Flow;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The YAML format of a {@see NodeFixture} - written by the export (button, CLI), read by
 * "I have the following nodes from file ...". Same fields as the Gherkin tables:
 *
 *   nodes:
 *     - nodeAggregateId: homepage
 *       parent: ''                      # empty: the site node; "owner/name": tethered child; else a NodeAggregateId
 *       nodeType: 'Your.SitePackageKey:Document.StartPage'
 *       properties: { uriPathSegment: site, title: Homepage }
 *       dimensionSpacePoint: { language: de }
 *       hidden: true                    # optional, default false
 *   references:                        # optional
 *     - nodeAggregateId: homepage
 *       referenceName: privacyPage
 *       targets: [privacy]
 *       dimensionSpacePoint: { language: de }
 *       properties: { label: Privacy }  # optional reference properties
 *
 * The file is hand-edited, so reading validates every entry and names its position on errors.
 *
 * @Flow\Proxy(false)
 */
final class NodeFixtureYaml
{
    public static function dump(NodeFixture $fixture): string
    {
        $yaml = ['nodes' => array_map(self::dumpNode(...), $fixture->nodes)];
        if ($fixture->references !== []) {
            $yaml['references'] = array_map(self::dumpReference(...), $fixture->references);
        }
        return Yaml::dump($yaml, 99, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    public static function parseFile(string $path): NodeFixture
    {
        if (!is_file($path)) {
            throw new \RuntimeException('YAML fixture file not found: ' . $path, 1727700001);
        }
        try {
            $yaml = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new \RuntimeException(sprintf('YAML fixture file %s is invalid: %s', $path, $e->getMessage()), 1727700002, $e);
        }
        if (!is_array($yaml) || !is_array($yaml['nodes'] ?? null)) {
            throw new \RuntimeException('Invalid YAML structure. Expected top-level key "nodes" with a list of nodes. Path: ' . $path, 1727700003);
        }
        return self::fromArray($yaml);
    }

    /**
     * @param array<mixed> $yaml the parsed file
     */
    public static function fromArray(array $yaml): NodeFixture
    {
        $nodes = $yaml['nodes'] ?? [];
        if (!is_array($nodes) || !array_is_list($nodes)) {
            throw new \RuntimeException('Invalid YAML fixture format: "nodes" must be a list. Nodes keyed by identifier (with path/type/children) are the old Neos 8 export format - re-export the content with the current export.', 1727700004);
        }
        $references = $yaml['references'] ?? [];
        if (!is_array($references)) {
            throw new \RuntimeException('Invalid YAML fixture format: "references" must be a list.', 1727700010);
        }
        return new NodeFixture(
            array_map(self::parseNode(...), $nodes, array_keys($nodes)),
            array_map(self::parseReference(...), $references, array_keys($references)),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function dumpNode(NodeFixtureRow $node): array
    {
        $yaml = [
            'nodeAggregateId' => $node->nodeAggregateId,
            'parent' => $node->parent,
            'nodeType' => $node->nodeType,
            'properties' => $node->properties,
            'dimensionSpacePoint' => $node->dimensionSpacePoint,
        ];
        if ($node->hidden) {
            $yaml['hidden'] = true;
        }
        return $yaml;
    }

    /**
     * @return array<string,mixed>
     */
    private static function dumpReference(ReferenceFixtureRow $reference): array
    {
        $yaml = [
            'nodeAggregateId' => $reference->nodeAggregateId,
            'referenceName' => $reference->referenceName,
            'targets' => $reference->targets,
            'dimensionSpacePoint' => $reference->dimensionSpacePoint,
        ];
        if ($reference->properties !== []) {
            $yaml['properties'] = $reference->properties;
        }
        return $yaml;
    }

    private static function parseNode(mixed $entry, int $index): NodeFixtureRow
    {
        $position = sprintf('Node #%d in YAML fixture', $index);
        $entry = self::requireMapEntry($entry, $position);
        $hidden = $entry['hidden'] ?? false;
        // `hidden: "no"` must not end up hidden just because the string is non-empty
        if (!is_bool($hidden)) {
            throw new \RuntimeException(sprintf('%s: "hidden" must be true or false.', $position), 1727700009);
        }
        return new NodeFixtureRow(
            self::requireId($entry, 'nodeAggregateId', $position),
            (string)($entry['parent'] ?? ''),
            self::requireId($entry, 'nodeType', $position),
            self::optionalMap($entry, 'properties', $position),
            self::optionalMap($entry, 'dimensionSpacePoint', $position),
            $hidden,
        );
    }

    private static function parseReference(mixed $entry, int|string $index): ReferenceFixtureRow
    {
        $position = sprintf('Reference #%s in YAML fixture', $index);
        $entry = self::requireMapEntry($entry, $position);
        $targets = array_map(strval(...), (array)($entry['targets'] ?? []));
        // an empty target would later fail with an unrelated "invalid NodeAggregateId ''" error
        if ($targets === [] || in_array('', $targets, true)) {
            throw new \RuntimeException(sprintf('%s needs "targets" with at least one non-empty NodeAggregateId.', $position), 1727700006);
        }
        return new ReferenceFixtureRow(
            self::requireId($entry, 'nodeAggregateId', $position),
            self::requireId($entry, 'referenceName', $position),
            array_values($targets),
            self::optionalMap($entry, 'dimensionSpacePoint', $position),
            self::optionalMap($entry, 'properties', $position),
        );
    }

    /**
     * @return array<mixed>
     */
    private static function requireMapEntry(mixed $entry, string $position): array
    {
        if (!is_array($entry) || array_is_list($entry)) {
            throw new \RuntimeException(sprintf('%s must be a map (field: value).', $position), 1727700011);
        }
        return $entry;
    }

    /**
     * Unquoted numeric ids (`nodeAggregateId: 123`) are parsed as int by YAML - accept them as string.
     *
     * @param array<mixed> $entry
     */
    private static function requireId(array $entry, string $field, string $position): string
    {
        $value = $entry[$field] ?? null;
        if (!is_string($value) && !is_int($value) || (string)$value === '') {
            throw new \RuntimeException(sprintf('%s needs a non-empty "%s".', $position, $field), 1727700007);
        }
        return (string)$value;
    }

    /**
     * @param array<mixed> $entry
     * @return array<string,mixed>
     */
    private static function optionalMap(array $entry, string $field, string $position): array
    {
        $value = $entry[$field] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \RuntimeException(sprintf('%s: "%s" must be a map (name: value).', $position, $field), 1727700008);
        }
        return $value;
    }
}
