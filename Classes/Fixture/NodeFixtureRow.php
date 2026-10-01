<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Fixture;

use Neos\Flow\Annotations as Flow;

/**
 * One row of the "I have the following nodes in site ..." table / one entry of a YAML node fixture.
 *
 * @Flow\Proxy(false)
 */
final readonly class NodeFixtureRow
{
    /**
     * @param string $parent '' for the site node, "ownerId/tetheredName" for tethered parents, otherwise a NodeAggregateId
     * @param array<string,mixed> $properties serialized property values, as the node creation step expects them
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
     * @return array<string,mixed> YAML fixture entry, see {@see \Sandstorm\E2ETestTools\Service\NodeImportService}
     */
    public function toYamlArray(): array
    {
        $yaml = [
            'nodeAggregateId' => $this->nodeAggregateId,
            'parent' => $this->parent,
            'nodeType' => $this->nodeType,
            'properties' => $this->properties,
            'dimensionSpacePoint' => $this->dimensionSpacePoint,
        ];
        // optional field - only written for hidden nodes
        if ($this->hidden) {
            $yaml['hidden'] = true;
        }
        return $yaml;
    }

    /**
     * @return array<string,string> cells of the "I have the following nodes in site ..." table; the optional "Hidden"
     *     cell only for hidden nodes
     */
    public function toTableCells(): array
    {
        $cells = [
            'NodeAggregateId' => $this->nodeAggregateId,
            'Parent' => $this->parent,
            'NodeType' => $this->nodeType,
            'Properties' => $this->properties === [] ? '{}' : json_encode($this->properties, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'DimensionSpacePoint' => $this->dimensionSpacePoint === [] ? '' : json_encode($this->dimensionSpacePoint, JSON_THROW_ON_ERROR),
        ];
        if ($this->hidden) {
            $cells['Hidden'] = 'true';
        }
        return $cells;
    }
}
