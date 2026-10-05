<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Fixture;

use Neos\Flow\Annotations as Flow;

/**
 * One node of a {@see NodeFixture}: a row of the "I have the following nodes in site ..." table, an entry of the YAML
 * "nodes:" list.
 *
 * @Flow\Proxy(false)
 */
final readonly class NodeFixtureRow
{
    /**
     * @param string $parent '' for the site node, "ownerId/tetheredName" for tethered parents, otherwise a NodeAggregateId
     * @param array<string,mixed> $properties serialized property values (as stored in the event store)
     * @param array<string,string> $dimensionSpacePoint
     * @param bool $hidden explicitly hidden (disabled) - descendants inherit that on import
     */
    public function __construct(
        public string $nodeAggregateId,
        public string $parent,
        public string $nodeType,
        public array $properties,
        public array $dimensionSpacePoint,
        public bool $hidden = false,
    ) {
    }

    /**
     * @param array<string,mixed> $properties
     */
    public function withProperties(array $properties): self
    {
        return new self($this->nodeAggregateId, $this->parent, $this->nodeType, $properties, $this->dimensionSpacePoint, $this->hidden);
    }

    /**
     * @param array<string,string> $dimensionSpacePoint
     */
    public function withDimensionSpacePoint(array $dimensionSpacePoint): self
    {
        return new self($this->nodeAggregateId, $this->parent, $this->nodeType, $this->properties, $dimensionSpacePoint, $this->hidden);
    }
}
