<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Fixture;

use Behat\Gherkin\Node\TableNode;
use Neos\Flow\Annotations as Flow;
use Sandstorm\E2ETestTools\StepGenerator\GherkinTable;

/**
 * The Gherkin format of a {@see NodeFixture}: the tables of "I have the following nodes in site ...:" and
 * "the following node references:" - printed by the CLI export and the StepGenerator, read by those steps.
 *
 * Properties and dimension space points are JSON in a cell. Optional columns ("Hidden", reference "Properties") are
 * only printed when a row needs them, so generated tables stay as small as hand-written ones.
 *
 * @Flow\Proxy(false)
 */
final class NodeFixtureGherkin
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function steps(NodeFixture $fixture, string $siteNodeName): string
    {
        $steps = sprintf('Given I have the following nodes in site "%s":', $siteNodeName) . "\n" . self::nodeTable($fixture->nodes)->toString();
        if ($fixture->references !== []) {
            $steps .= "And the following node references:\n" . self::referenceTable($fixture->references)->toString();
        }
        return $steps;
    }

    /**
     * @param list<NodeFixtureRow> $nodes
     */
    public static function nodeTable(array $nodes): GherkinTable
    {
        $columns = ['NodeAggregateId', 'Parent', 'NodeType', 'Properties', 'DimensionSpacePoint'];
        $withHidden = array_filter($nodes, fn (NodeFixtureRow $node) => $node->hidden) !== [];
        $table = new GherkinTable($withHidden ? [...$columns, 'Hidden'] : $columns);
        foreach ($nodes as $node) {
            $table->addRow([
                'NodeAggregateId' => $node->nodeAggregateId,
                'Parent' => $node->parent,
                'NodeType' => $node->nodeType,
                'Properties' => $node->properties === [] ? '{}' : json_encode($node->properties, self::JSON_FLAGS),
                'DimensionSpacePoint' => self::dimensionSpacePointCell($node->dimensionSpacePoint),
                ...($node->hidden ? ['Hidden' => 'true'] : []),
            ]);
        }
        return $table;
    }

    /**
     * @param list<ReferenceFixtureRow> $references
     */
    public static function referenceTable(array $references): GherkinTable
    {
        $columns = ['NodeAggregateId', 'ReferenceName', 'Targets', 'DimensionSpacePoint'];
        $withProperties = array_filter($references, fn (ReferenceFixtureRow $reference) => $reference->properties !== []) !== [];
        $table = new GherkinTable($withProperties ? [...$columns, 'Properties'] : $columns);
        foreach ($references as $reference) {
            $table->addRow([
                'NodeAggregateId' => $reference->nodeAggregateId,
                'ReferenceName' => $reference->referenceName,
                'Targets' => implode(', ', $reference->targets),
                'DimensionSpacePoint' => self::dimensionSpacePointCell($reference->dimensionSpacePoint),
                ...($reference->properties !== [] ? ['Properties' => json_encode($reference->properties, self::JSON_FLAGS)] : []),
            ]);
        }
        return $table;
    }

    /**
     * Columns: NodeAggregateId, Parent, NodeType, Properties, DimensionSpacePoint, optional Hidden
     *
     * @return list<NodeFixtureRow>
     */
    public static function nodesFromTable(TableNode $table): array
    {
        return array_map(function (array $row): NodeFixtureRow {
            $nodeAggregateId = self::requireCell($row, 'NodeAggregateId', 'node table');
            $position = sprintf('node "%s"', $nodeAggregateId);
            return new NodeFixtureRow(
                $nodeAggregateId,
                $row['Parent'] ?? '',
                self::requireCell($row, 'NodeType', $position),
                self::jsonMapCell($row, 'Properties', $position),
                self::jsonMapCell($row, 'DimensionSpacePoint', $position),
                match ($row['Hidden'] ?? '') {
                    'true' => true,
                    'false', '' => false,
                    default => throw new \RuntimeException(sprintf('Invalid Hidden value "%s" of %s - use "true", "false" or leave it empty.', $row['Hidden'], $position), 1727700012),
                },
            );
        }, $table->getHash());
    }

    /**
     * Columns: NodeAggregateId, ReferenceName, Targets (comma-separated), DimensionSpacePoint, optional Properties
     *
     * @return list<ReferenceFixtureRow>
     */
    public static function referencesFromTable(TableNode $table): array
    {
        return array_map(function (array $row): ReferenceFixtureRow {
            $nodeAggregateId = self::requireCell($row, 'NodeAggregateId', 'reference table');
            $referenceName = self::requireCell($row, 'ReferenceName', sprintf('references of node "%s"', $nodeAggregateId));
            $position = sprintf('reference "%s" of node "%s"', $referenceName, $nodeAggregateId);
            $targets = array_map(trim(...), explode(',', $row['Targets'] ?? ''));
            if (in_array('', $targets, true)) {
                throw new \RuntimeException(sprintf('Targets of %s must be comma-separated, non-empty NodeAggregateIds.', $position), 1727700013);
            }
            return new ReferenceFixtureRow(
                $nodeAggregateId,
                $referenceName,
                $targets,
                self::jsonMapCell($row, 'DimensionSpacePoint', $position),
                self::jsonMapCell($row, 'Properties', $position),
            );
        }, $table->getHash());
    }

    /**
     * @param array<string,string> $dimensionSpacePoint
     */
    private static function dimensionSpacePointCell(array $dimensionSpacePoint): string
    {
        return $dimensionSpacePoint === [] ? '' : json_encode($dimensionSpacePoint, self::JSON_FLAGS);
    }

    /**
     * @param array<string,string> $row
     */
    private static function requireCell(array $row, string $column, string $position): string
    {
        return ($row[$column] ?? '') !== ''
            ? $row[$column]
            : throw new \RuntimeException(sprintf('Missing %s in %s.', $column, $position), 1727700014);
    }

    /**
     * Invalid JSON fails loudly - silently dropping all properties would make the test pass for the wrong reason.
     * Note that Gherkin unescapes "\\" to "\" in cells: a JSON-escaped backslash (e.g. in a PHP class name) is
     * written as "\\\\" in the feature file.
     *
     * @param array<string,string> $row
     * @return array<string,mixed> empty cell: []
     */
    private static function jsonMapCell(array $row, string $column, string $position): array
    {
        $cell = $row[$column] ?? '';
        if ($cell === '') {
            return [];
        }
        try {
            $value = json_decode($cell, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Invalid JSON in %s of %s: %s', $column, $position, $e->getMessage()), 1727700015, $e);
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \RuntimeException(sprintf('%s of %s must be a JSON object.', $column, $position), 1727700016);
        }
        return $value;
    }
}
