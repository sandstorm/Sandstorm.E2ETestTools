<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Fixture;

use Neos\Flow\Annotations as Flow;

/**
 * References of one node to one or more targets: a row of the "the following node references:" table, an entry of the
 * YAML "references:" list.
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
     * @param list<string> $nodeAggregateIds
     * @return self|null null if no target is left
     */
    public function withTargetsLimitedTo(array $nodeAggregateIds): ?self
    {
        $targets = array_values(array_intersect($this->targets, $nodeAggregateIds));
        return $targets === [] ? null : new self($this->nodeAggregateId, $this->referenceName, $targets, $this->dimensionSpacePoint, $this->properties);
    }
}
