<?php

namespace Sandstorm\E2ETestTools\Service;

use Doctrine\Common\Collections\Collection;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Media\Domain\Model\AssetInterface;

class NodeToYamlConverter
{
    /**
     * Normalize given value to fit yaml format
     */
    private function normalizeYamlValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM); // ISO 8601
        }

        if ($value instanceof \JsonSerializable) {
            return $this->normalizeYamlValue($value->jsonSerialize());
        }

        if ($value instanceof Collection) {
            return $this->normalizeYamlValue($value->toArray());
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalizeYamlValue($v);
            }
            return $out;
        }

        if ($value instanceof AssetInterface) {
            return [
                '_type' => 'asset',
                'identifier' => $value->getIdentifier(),
            ];
        }

        if ($value instanceof NodeInterface) {
            return [
                '_type' => 'node',
                'identifier' => $value->getIdentifier(),
                'path' => $value->getPath(),
            ];
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        if (is_object($value)) {
            return [
                '_type' => 'object',
                'class' => $value::class,
            ];
        }

        return null;
    }

    /**
    */
    public function nodeToArray(Node $node): array
    {
        $properties = [];
        foreach ($node->getPropertyNames() as $name) {
            $properties[$name] = $this->normalizeYamlValue($node->getProperty($name));
        }

        return [
            'path' => $node->getPath(),
            'type' => $node->getNodeTypeName()->getValue(),
            'properties' => $properties,
            'dimensions' => $node->getDimensions(),
            'children' => []
        ];
    }
}
