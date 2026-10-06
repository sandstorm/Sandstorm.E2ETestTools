<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Fixture;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValue;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Command\SetNodeReferences;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferencesForName;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferencesToWrite;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferenceToWrite;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Node\ReferenceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;

/**
 * Creates the nodes and references of a {@see NodeFixture} in the live workspace.
 *
 * Fixtures hold serialized property values (as the event store has them, so exports can be written as JSON/YAML);
 * the CR commands want PHP values - they're converted with the property types the NodeType declares, using the
 * same {@see PropertyConverter} the CR uses when reading events.
 *
 * @Flow\Proxy(false)
 * @internal used by the Behat steps, see FusionRenderingTrait
 */
final readonly class NodeFixtureImporter
{
    public function __construct(
        private ContentRepository $contentRepository,
        private PropertyConverter $propertyConverter,
        private NodeAggregateId $sitesNodeAggregateId,
    ) {
    }

    public function import(NodeFixture $fixture, string $siteNodeName): void
    {
        foreach ($fixture->nodes as $node) {
            $this->createNode($node, $siteNodeName);
        }
        // after all nodes, so references can point to nodes further down the fixture
        foreach ($fixture->references as $reference) {
            $this->setReferences($reference);
        }
    }

    /**
     * @param string $siteNodeName node name of a node with empty parent - site nodes are found by their name
     */
    public function createNode(NodeFixtureRow $node, string $siteNodeName): void
    {
        $nodeAggregateId = NodeAggregateId::fromString($node->nodeAggregateId);
        $dimensionSpacePoint = DimensionSpacePoint::fromArray($node->dimensionSpacePoint);
        $nodeType = $this->nodeType($node->nodeType, $node->nodeAggregateId);

        $command = CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $nodeAggregateId,
            $nodeType->name,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($dimensionSpacePoint),
            $this->resolveParent($node->parent),
        )->withInitialPropertyValues(PropertyValuesToWrite::fromArray($this->deserialize(
            $node->properties,
            fn (string $propertyName) => $nodeType->hasProperty($propertyName) ? $nodeType->getPropertyType($propertyName) : null,
            sprintf('node "%s"', $node->nodeAggregateId)
        )));
        if ($node->parent === '') {
            // node names are deprecated for regular nodes, but site nodes are still identified by theirs in Neos 9
            $command = $command->withNodeName(NodeName::fromString($siteNodeName));
        }
        $this->contentRepository->handle($command);

        if ($node->hidden) {
            $this->contentRepository->handle(TagSubtree::create(
                WorkspaceName::forLive(),
                $nodeAggregateId,
                $dimensionSpacePoint,
                NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
                NeosSubtreeTag::disabled()
            ));
        }
    }

    public function setReferences(ReferenceFixtureRow $reference): void
    {
        $sourceNodeAggregateId = NodeAggregateId::fromString($reference->nodeAggregateId);
        $dimensionSpacePoint = DimensionSpacePoint::fromArray($reference->dimensionSpacePoint);
        $referenceName = ReferenceName::fromString($reference->referenceName);
        $targetIds = array_map(NodeAggregateId::fromString(...), $reference->targets);

        if ($reference->properties === []) {
            $references = NodeReferencesForName::fromTargets($referenceName, NodeAggregateIds::create(...$targetIds));
        } else {
            $position = sprintf('reference "%s" of node "%s"', $reference->referenceName, $reference->nodeAggregateId);
            $propertyDeclarations = $this->sourceNodeType($sourceNodeAggregateId, $dimensionSpacePoint, $position)
                ->getReferences()[$reference->referenceName]['properties'] ?? [];
            $properties = PropertyValuesToWrite::fromArray($this->deserialize(
                $reference->properties,
                fn (string $propertyName) => $propertyDeclarations[$propertyName]['type'] ?? null,
                $position
            ));
            $references = NodeReferencesForName::fromReferences($referenceName, array_map(
                fn (NodeAggregateId $targetId) => NodeReferenceToWrite::fromTargetAndProperties($targetId, $properties),
                $targetIds
            ));
        }

        $this->contentRepository->handle(SetNodeReferences::create(
            WorkspaceName::forLive(),
            $sourceNodeAggregateId,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($dimensionSpacePoint),
            NodeReferencesToWrite::create($references)
        ));
    }

    /**
     * '' is the site node (child of the sites root), "ownerId/main" a tethered child node - tethered nodes get
     * generated ids on creation, so a fixture can only address them via their owner and name.
     */
    private function resolveParent(string $parent): NodeAggregateId
    {
        if ($parent === '') {
            return $this->sitesNodeAggregateId;
        }
        [$ownerId, $tetheredPath] = explode('/', $parent, 2) + [1 => ''];
        $parentId = NodeAggregateId::fromString($ownerId);
        $contentGraph = $this->contentRepository->getContentGraph(WorkspaceName::forLive());
        foreach (array_filter(explode('/', $tetheredPath), fn (string $childName) => $childName !== '') as $childName) {
            $parentId = $contentGraph->findChildNodeAggregateByName($parentId, NodeName::fromString($childName))->nodeAggregateId
                ?? throw new \RuntimeException(sprintf('No tethered child "%s" found under node "%s". Make sure the parent node is created before this row.', $childName, $parentId->value), 1727700017);
        }
        return $parentId;
    }

    /**
     * @param array<string,mixed> $serializedValues
     * @param \Closure(string): ?string $declaredType property name -> declared type, null if not declared
     * @return array<string,mixed>
     */
    private function deserialize(array $serializedValues, \Closure $declaredType, string $position): array
    {
        $values = [];
        foreach ($serializedValues as $propertyName => $value) {
            $type = $declaredType($propertyName)
                ?? throw new \RuntimeException(sprintf('Property "%s" of %s is not declared in the NodeType.', $propertyName, $position), 1727700018);
            // null unsets a property - there's nothing to convert
            $values[$propertyName] = $value === null ? null : $this->propertyConverter->deserializePropertyValue(SerializedPropertyValue::create($value, $type));
        }
        return $values;
    }

    private function nodeType(string $nodeTypeName, string $nodeAggregateId): NodeType
    {
        return $this->contentRepository->getNodeTypeManager()->getNodeType(NodeTypeName::fromString($nodeTypeName))
            ?? throw new \RuntimeException(sprintf('Unknown NodeType "%s" of node "%s".', $nodeTypeName, $nodeAggregateId), 1727700019);
    }

    private function sourceNodeType(NodeAggregateId $nodeAggregateId, DimensionSpacePoint $dimensionSpacePoint, string $position): NodeType
    {
        $node = $this->contentRepository->getContentGraph(WorkspaceName::forLive())
            ->getSubgraph($dimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved())
            ->findNodeById($nodeAggregateId)
            ?? throw new \RuntimeException(sprintf('Source node of %s not found.', $position), 1727700020);
        return $this->nodeType($node->nodeTypeName->value, $nodeAggregateId->value);
    }
}
