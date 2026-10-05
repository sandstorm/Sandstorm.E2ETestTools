<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Fixture;

use Neos\Flow\Annotations as Flow;

/**
 * Nodes plus the references between them - the one model all fixture tooling works on:
 *
 *   export: subgraph -> {@see NodeFixtureCollector} -> NodeFixture -> {@see NodeFixtureYaml} / {@see NodeFixtureGherkin}
 *   import: YAML file / Gherkin table -> NodeFixture (rows) -> {@see NodeFixtureImporter} -> content repository commands
 *
 * Nodes are ordered parents before children, as they have to be created.
 *
 * @Flow\Proxy(false)
 */
final readonly class NodeFixture
{
    /**
     * @param list<NodeFixtureRow> $nodes
     * @param list<ReferenceFixtureRow> $references
     */
    public function __construct(
        public array $nodes,
        public array $references = [],
    ) {
    }

    /**
     * References to nodes outside the fixture can't be set on import - drop those targets (and rows left without one).
     */
    public function withReferencesLimitedToNodes(): self
    {
        $nodeAggregateIds = array_map(fn (NodeFixtureRow $node) => $node->nodeAggregateId, $this->nodes);
        $references = array_map(fn (ReferenceFixtureRow $reference) => $reference->withTargetsLimitedTo($nodeAggregateIds), $this->references);
        return new self($this->nodes, array_values(array_filter($references)));
    }

    /**
     * Rows without a dimension space point get the default; rows with one keep theirs - so a scenario sets the default
     * once and writes the dimension only on the rows of another variant.
     *
     * @param array<string,string> $dimensionSpacePoint [] keeps the fixture as it is
     */
    public function withDefaultDimensionSpacePoint(array $dimensionSpacePoint): self
    {
        if ($dimensionSpacePoint === []) {
            return $this;
        }
        return new self(
            array_map(fn (NodeFixtureRow $node) => $node->dimensionSpacePoint === [] ? $node->withDimensionSpacePoint($dimensionSpacePoint) : $node, $this->nodes),
            array_map(fn (ReferenceFixtureRow $reference) => $reference->dimensionSpacePoint === [] ? $reference->withDimensionSpacePoint($dimensionSpacePoint) : $reference, $this->references),
        );
    }

    /**
     * @param array<string,array<string,string>> $overwrites ['<nodeAggregateId>' => ['<property>' => '<value>']] from
     *     the overwrite table - values that are valid JSON are decoded (true, 42, null, "42", [...], {...}), so non-string
     *     properties can be overwritten too; everything else is used as text
     */
    public function withPropertyOverwrites(array $overwrites): self
    {
        $nodeAggregateIds = array_map(fn (NodeFixtureRow $node) => $node->nodeAggregateId, $this->nodes);
        // a typo in the overwrite table would otherwise silently overwrite nothing
        $unknownIds = array_diff(array_map(strval(...), array_keys($overwrites)), $nodeAggregateIds);
        if ($unknownIds !== []) {
            throw new \RuntimeException(sprintf('Overwrites for nodes that are not part of the fixture: %s', implode(', ', $unknownIds)), 1727700005);
        }
        $nodes = array_map(
            fn (NodeFixtureRow $node) => array_key_exists($node->nodeAggregateId, $overwrites)
                ? $node->withProperties(array_replace($node->properties, array_map(self::decodeOverwriteValue(...), $overwrites[$node->nodeAggregateId])))
                : $node,
            $this->nodes
        );
        return new self($nodes, $this->references);
    }

    private static function decodeOverwriteValue(string $value): mixed
    {
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }
    }
}
