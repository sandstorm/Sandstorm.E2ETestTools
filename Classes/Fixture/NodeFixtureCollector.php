<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Fixture;

use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;

/**
 * Turns existing nodes of one subgraph into fixture rows - shared by the export button, the CLI export and the
 * StepGenerator, so all of them produce what "I have the following nodes in site ..." / the YAML import read.
 *
 * @Flow\Proxy(false)
 */
final readonly class NodeFixtureCollector
{
    public function __construct(
        private ContentSubgraphInterface $subgraph,
        private NodeTypeManager $nodeTypeManager,
    ) {
    }

    /**
     * Whether a node has to be created by a fixture at all: the root and tethered nodes are created automatically
     * (by setupContentRepository() resp. from the NodeType's childNodes).
     */
    public function needsRow(Node $node): bool
    {
        return !$node->classification->isRoot() && !$node->classification->isTethered();
    }

    /**
     * Content can still contain nodes of NodeTypes that don't exist anymore (e.g. from a removed package) - they
     * can't be created, so exports skip them together with their subtree.
     */
    public function hasKnownNodeType(Node $node): bool
    {
        return $this->nodeTypeManager->hasNodeType($node->nodeTypeName);
    }

    public function rowFor(Node $node): NodeFixtureRow
    {
        return new NodeFixtureRow(
            $node->aggregateId->value,
            $this->parentReference($node),
            $node->nodeTypeName->value,
            $this->declaredProperties($node),
            $node->dimensionSpacePoint->coordinates,
            // only explicitly hidden nodes - descendants inherit it on import
            $node->tags->withoutInherited()->contain(NeosSubtreeTag::disabled()),
        );
    }

    /**
     * References without properties are grouped into one row per reference name; references with properties get a
     * row each (the properties belong to that single target). Rows keep the order of first appearance.
     *
     * @return list<ReferenceFixtureRow>
     */
    public function referencesFor(Node $node): array
    {
        /** @var list<array{name: string, targets: list<string>, properties: array<string,mixed>}> $groups */
        $groups = [];
        $plainGroupIndexByName = [];
        foreach ($this->subgraph->findReferences($node->aggregateId, FindReferencesFilter::create()) as $reference) {
            $name = $reference->name->value;
            $target = $reference->node->aggregateId->value;
            $properties = $reference->properties?->serialized()->getPlainValues() ?? [];
            if ($properties !== []) {
                $groups[] = ['name' => $name, 'targets' => [$target], 'properties' => $properties];
            } elseif (array_key_exists($name, $plainGroupIndexByName)) {
                $groups[$plainGroupIndexByName[$name]]['targets'][] = $target;
            } else {
                $plainGroupIndexByName[$name] = count($groups);
                $groups[] = ['name' => $name, 'targets' => [$target], 'properties' => []];
            }
        }
        return array_map(
            fn (array $group) => new ReferenceFixtureRow($node->aggregateId->value, $group['name'], $group['targets'], $node->dimensionSpacePoint->coordinates, $group['properties']),
            $groups
        );
    }

    /**
     * The node tree the export button exports: all ancestors of the node's closest document (from the site node
     * down), the document itself and all its descendants.
     *
     * @return list<Node> parents before children
     */
    public function collectExportTree(Node $node): array
    {
        $document = $this->subgraph->findClosestNode($node->aggregateId, FindClosestNodeFilter::create('Neos.Neos:Document')) ?? $node;
        $nodes = [...$this->ancestors($document), $document, ...$this->descendants($document)];
        return array_values(array_filter($nodes, $this->needsRow(...)));
    }

    /**
     * @return list<Node> ancestors from the top down, excluding the node itself
     */
    public function ancestors(Node $node): array
    {
        $ancestors = [];
        $current = $node;
        while (($parent = $this->subgraph->findParentNode($current->aggregateId)) !== null) {
            array_unshift($ancestors, $parent);
            $current = $parent;
        }
        return $ancestors;
    }

    /**
     * @return list<Node> depth-first, parents before children
     */
    private function descendants(Node $node): array
    {
        $descendants = [];
        foreach ($this->subgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create()) as $childNode) {
            if (!$this->hasKnownNodeType($childNode)) {
                continue;
            }
            array_push($descendants, $childNode, ...$this->descendants($childNode));
        }
        return $descendants;
    }

    /**
     * Serialized values of the properties the NodeType declares. Older content can still carry properties that were
     * removed from the NodeType since - they can't be written anymore, so they're left out.
     *
     * @return array<string,mixed>
     */
    private function declaredProperties(Node $node): array
    {
        $nodeType = $this->nodeTypeManager->getNodeType($node->nodeTypeName);
        return array_filter(
            $node->properties->serialized()->getPlainValues(),
            fn (string $propertyName) => $nodeType?->hasProperty($propertyName) ?? false,
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * '' for the site node (the node creation step creates it below the sites root), "ownerId/name" for tethered
     * parents (they get generated ids on creation, so they are addressed via their owner), otherwise the parent's id.
     */
    private function parentReference(Node $node): string
    {
        $parent = $this->subgraph->findParentNode($node->aggregateId);
        if ($parent === null || $parent->classification->isRoot()) {
            return '';
        }
        $path = [];
        while ($parent->classification->isTethered()) {
            array_unshift($path, $parent->name->value ?? throw new \RuntimeException(sprintf('Tethered node "%s" has no name.', $parent->aggregateId->value)));
            $parent = $this->subgraph->findParentNode($parent->aggregateId)
                ?? throw new \RuntimeException(sprintf('Tethered node "%s" has no parent.', $node->aggregateId->value));
        }
        return implode('/', [$parent->aggregateId->value, ...$path]);
    }
}
