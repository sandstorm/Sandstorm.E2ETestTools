<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Fixture;

use Neos\Flow\Annotations as Flow;

/**
 * One row of the "the following node references:" table / one entry of the YAML "references:" list.
 *
 * @Flow\Proxy(false)
 */
final readonly class ReferenceFixtureRow
{
    /**
     * @param list<string> $targets NodeAggregateIds
     * @param array<string,string> $dimensionSpacePoint
     * @param array<string,mixed> $properties serialized reference property values, the same for all targets of the row
     */
    public function __construct(
        public string $nodeAggregateId,
        public string $referenceName,
        public array $targets,
        public array $dimensionSpacePoint,
        public array $properties = [],
    ) {
    }

    /**
     * References to nodes that aren't part of a fixture couldn't be set on import - keep only the targets that are.
     *
     * @param list<string> $nodeAggregateIds the nodes of the fixture
     * @return self|null null if no target is left
     */
    public function withTargetsLimitedTo(array $nodeAggregateIds): ?self
    {
        $targets = array_values(array_intersect($this->targets, $nodeAggregateIds));
        return $targets === [] ? null : new self($this->nodeAggregateId, $this->referenceName, $targets, $this->dimensionSpacePoint, $this->properties);
    }

    /**
     * @return array<string,mixed>
     */
    public function toYamlArray(): array
    {
        $yaml = [
            'nodeAggregateId' => $this->nodeAggregateId,
            'referenceName' => $this->referenceName,
            'targets' => $this->targets,
            'dimensionSpacePoint' => $this->dimensionSpacePoint,
        ];
        // optional field - only written when the references carry properties
        if ($this->properties !== []) {
            $yaml['properties'] = $this->properties;
        }
        return $yaml;
    }

    /**
     * @return array<string,string> cells of the "the following node references:" table; the optional "Properties"
     *     cell only when the references carry properties
     */
    public function toTableCells(): array
    {
        $cells = [
            'NodeAggregateId' => $this->nodeAggregateId,
            'ReferenceName' => $this->referenceName,
            'Targets' => implode(', ', $this->targets),
            'DimensionSpacePoint' => $this->dimensionSpacePoint === [] ? '' : json_encode($this->dimensionSpacePoint, JSON_THROW_ON_ERROR),
        ];
        if ($this->properties !== []) {
            $cells['Properties'] = json_encode($this->properties, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $cells;
    }
}
