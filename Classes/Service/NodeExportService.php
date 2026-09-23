<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Service;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureCollector;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureRow;
use Sandstorm\E2ETestTools\Fixture\ReferenceFixtureRow;

/**
 * Exports existing content as node fixture (export button and CLI): the node's closest document with all its
 * ancestors and descendants, plus their references.
 *
 * @Flow\Scope("singleton")
 */
class NodeExportService
{
    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * @return array{nodes: list<NodeFixtureRow>, references: list<ReferenceFixtureRow>}
     */
    public function exportNodeTree(NodeAddress $nodeAddress): array
    {
        $subgraph = $this->subgraph($nodeAddress->contentRepositoryId, $nodeAddress->workspaceName, $nodeAddress->dimensionSpacePoint);
        $node = $subgraph->findNodeById($nodeAddress->aggregateId)
            ?? throw new \InvalidArgumentException(sprintf('Node "%s" not found in workspace "%s", dimension %s', $nodeAddress->aggregateId->value, $nodeAddress->workspaceName->value, $nodeAddress->dimensionSpacePoint->toJson()), 1727100001);

        $collector = new NodeFixtureCollector($subgraph, $this->contentRepositoryRegistry->get($nodeAddress->contentRepositoryId)->getNodeTypeManager());
        $nodes = $collector->collectExportTree($node);
        $exportedIds = array_map(fn ($node) => $node->aggregateId->value, $nodes);

        $references = [];
        foreach ($nodes as $exportedNode) {
            foreach ($collector->referencesFor($exportedNode) as $reference) {
                // references to nodes outside the export couldn't be set on import
                $targets = array_values(array_intersect($reference->targets, $exportedIds));
                if ($targets !== []) {
                    $references[] = new ReferenceFixtureRow($reference->nodeAggregateId, $reference->referenceName, $targets, $reference->dimensionSpacePoint);
                }
            }
        }

        return [
            'nodes' => array_map($collector->rowFor(...), $nodes),
            'references' => $references,
        ];
    }

    public function nodeAddress(string $nodeAggregateId, string $workspaceName = 'live', string $dimensionSpacePointJson = '{}', string $contentRepositoryId = 'default'): NodeAddress
    {
        return NodeAddress::create(
            ContentRepositoryId::fromString($contentRepositoryId),
            WorkspaceName::fromString($workspaceName),
            DimensionSpacePoint::fromJsonString($dimensionSpacePointJson),
            NodeAggregateId::fromString($nodeAggregateId),
        );
    }

    private function subgraph(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, DimensionSpacePoint $dimensionSpacePoint): ContentSubgraphInterface
    {
        // hidden nodes are content too - export them as well
        return $this->contentRepositoryRegistry->get($contentRepositoryId)
            ->getContentGraph($workspaceName)
            ->getSubgraph($dimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
    }
}
