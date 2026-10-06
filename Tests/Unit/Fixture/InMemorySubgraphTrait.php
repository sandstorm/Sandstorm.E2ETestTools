<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Fixture;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTags;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeTags;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Reference;
use Neos\ContentRepository\Core\Projection\ContentGraph\References;
use Neos\ContentRepository\Core\Projection\ContentGraph\Timestamps;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Node\ReferenceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Symfony\Component\Serializer\Serializer;

/**
 * An in-memory node tree behind a mocked ContentSubgraphInterface, with real Node objects - for testing code that
 * reads a subgraph without a database. Set $nodeTypeManager, then build the tree with addNode()/addReference().
 */
trait InMemorySubgraphTrait
{
    /**
     * @var array<string,Node>
     */
    private array $nodes = [];

    /**
     * @var array<string,string> child id => parent id
     */
    private array $parents = [];

    /**
     * @var list<Reference>[] node id => references
     */
    private array $references = [];

    private NodeTypeManager $nodeTypeManager;


    private function addNode(
        string $id,
        ?string $parentId,
        string $nodeType,
        NodeAggregateClassification $classification = NodeAggregateClassification::CLASSIFICATION_REGULAR,
        ?string $name = null,
        array $properties = [],
        ?SubtreeTags $tags = null,
        ?SubtreeTags $inheritedTags = null,
    ): void {
        $this->nodes[$id] = Node::create(
            ContentRepositoryId::fromString('default'),
            WorkspaceName::forLive(),
            DimensionSpacePoint::fromArray(['language' => 'de']),
            NodeAggregateId::fromString($id),
            OriginDimensionSpacePoint::fromArray(['language' => 'de']),
            $classification,
            NodeTypeName::fromString($nodeType),
            $this->propertyCollection($properties),
            $name !== null ? NodeName::fromString($name) : null,
            NodeTags::create($tags ?? SubtreeTags::createEmpty(), $inheritedTags ?? SubtreeTags::createEmpty()),
            // the CR rejects timestamps that aren't UTC
            Timestamps::create(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), new \DateTimeImmutable('now', new \DateTimeZone('UTC')), null, null),
            VisibilityConstraints::createEmpty(),
        );
        if ($parentId !== null) {
            $this->parents[$id] = $parentId;
        }
    }

    private function addReference(string $sourceId, string $name, string $targetId, array $properties = []): void
    {
        $this->references[$sourceId][] = new Reference(
            $this->nodes[$targetId],
            ReferenceName::fromString($name),
            $properties === [] ? null : $this->propertyCollection($properties)
        );
    }

    private function propertyCollection(array $plainValues): PropertyCollection
    {
        return new PropertyCollection(
            SerializedPropertyValues::fromArray(array_map(fn (mixed $value) => ['value' => $value, 'type' => get_debug_type($value)], $plainValues)),
            new PropertyConverter(new Serializer())
        );
    }

    private function subgraph(): ContentSubgraphInterface
    {
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $subgraph->method('findParentNode')->willReturnCallback(
            fn (NodeAggregateId $id) => isset($this->parents[$id->value]) ? $this->nodes[$this->parents[$id->value]] : null
        );
        $subgraph->method('getDimensionSpacePoint')->willReturn(DimensionSpacePoint::fromArray(['language' => 'de']));
        $subgraph->method('findRootNodeByType')->willReturnCallback(function (NodeTypeName $nodeTypeName) {
            foreach ($this->nodes as $node) {
                if ($node->classification->isRoot() && $node->nodeTypeName->equals($nodeTypeName)) {
                    return $node;
                }
            }
            return null;
        });
        $subgraph->method('findChildNodes')->willReturnCallback(function (NodeAggregateId $id, FindChildNodesFilter $filter) {
            // only the explicitly allowed NodeTypes of the filter (incl. sub types) are supported
            $allowed = $filter->nodeTypes !== null ? iterator_to_array($filter->nodeTypes->explicitlyAllowedNodeTypeNames) : [];
            $children = array_map(fn (string $childId) => $this->nodes[$childId], array_keys($this->parents, $id->value, true));
            return Nodes::fromArray(array_values(array_filter($children, fn (Node $child) => $allowed === [] || array_filter(
                $allowed,
                fn (NodeTypeName $nodeTypeName) => $this->nodeTypeManager->getNodeType($child->nodeTypeName)?->isOfType($nodeTypeName->value) ?? false
            ) !== [])));
        });
        $subgraph->method('findClosestNode')->willReturnCallback(function (NodeAggregateId $id, FindClosestNodeFilter $filter) {
            $nodeTypeNames = iterator_to_array($filter->nodeTypes->explicitlyAllowedNodeTypeNames);
            for ($current = $id->value; $current !== null; $current = $this->parents[$current] ?? null) {
                $nodeType = $this->nodeTypeManager->getNodeType($this->nodes[$current]->nodeTypeName);
                foreach ($nodeTypeNames as $nodeTypeName) {
                    if ($nodeType?->isOfType($nodeTypeName)) {
                        return $this->nodes[$current];
                    }
                }
            }
            return null;
        });
        $subgraph->method('findReferences')->willReturnCallback(
            fn (NodeAggregateId $id) => References::fromArray($this->references[$id->value] ?? [])
        );
        return $subgraph;
    }
}
