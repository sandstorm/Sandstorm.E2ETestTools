<?php

namespace Sandstorm\E2ETestTools\DTO;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class NodeTreeElement
{
    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $dimensions
     * @param array<NodeTreeElement> $children
     */
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly array  $properties,
        public readonly array  $dimensions,
        public readonly array  $children = []
    )
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->type,
            'properties' => $this->properties,
            'dimensions' => $this->dimensions,
            'children' => array_map(
                fn(self $child) => $child->toArray(),
                $this->children
            ),
        ];
    }
}
