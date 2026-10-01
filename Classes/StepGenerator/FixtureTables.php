<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\StepGenerator;

use Neos\Flow\Annotations as Flow;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureRow;
use Sandstorm\E2ETestTools\Fixture\ReferenceFixtureRow;

/**
 * The Gherkin tables for "I have the following nodes in site ...:" and "the following node references:" - with the
 * optional "Hidden" / "Properties" columns only when at least one row needs them.
 *
 * @Flow\Proxy(false)
 */
final class FixtureTables
{
    /**
     * @param list<NodeFixtureRow> $rows
     */
    public static function nodes(array $rows): GherkinTable
    {
        $columns = ['NodeAggregateId', 'Parent', 'NodeType', 'Properties', 'DimensionSpacePoint'];
        if (array_filter($rows, fn (NodeFixtureRow $row) => $row->hidden) !== []) {
            $columns[] = 'Hidden';
        }
        $table = new GherkinTable($columns);
        foreach ($rows as $row) {
            $table->addRow($row->toTableCells());
        }
        return $table;
    }

    /**
     * @param list<ReferenceFixtureRow> $rows
     */
    public static function references(array $rows): GherkinTable
    {
        $columns = ['NodeAggregateId', 'ReferenceName', 'Targets', 'DimensionSpacePoint'];
        if (array_filter($rows, fn (ReferenceFixtureRow $row) => $row->properties !== []) !== []) {
            $columns[] = 'Properties';
        }
        $table = new GherkinTable($columns);
        foreach ($rows as $row) {
            $table->addRow($row->toTableCells());
        }
        return $table;
    }
}
