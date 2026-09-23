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
     */
    public function __construct(
        public string $nodeAggregateId,
        public string $referenceName,
        public array $targets,
        public array $dimensionSpacePoint,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toYamlArray(): array
    {
        return [
            'nodeAggregateId' => $this->nodeAggregateId,
            'referenceName' => $this->referenceName,
            'targets' => $this->targets,
            'dimensionSpacePoint' => $this->dimensionSpacePoint,
        ];
    }

    /**
     * @return array<string,string> cells of the "the following node references:" table
     */
    public function toTableCells(): array
    {
        return [
            'NodeAggregateId' => $this->nodeAggregateId,
            'ReferenceName' => $this->referenceName,
            'Targets' => implode(', ', $this->targets),
            'DimensionSpacePoint' => $this->dimensionSpacePoint === [] ? '' : json_encode($this->dimensionSpacePoint, JSON_THROW_ON_ERROR),
        ];
    }
}
