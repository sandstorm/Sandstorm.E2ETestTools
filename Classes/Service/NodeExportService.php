<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Service;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Sandstorm\E2ETestTools\Fixture\NodeFixture;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureCollector;

/**
 * Exports existing content as node fixture (export button and CLI): the node's closest document with all its
 * ancestors and descendants, plus the references between them.
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

    public function exportNodeTree(NodeAddress $nodeAddress): NodeFixture
    {
        $subgraph = $this->subgraph($nodeAddress->contentRepositoryId, $nodeAddress->workspaceName, $nodeAddress->dimensionSpacePoint);
        $node = $subgraph->findNodeById($nodeAddress->aggregateId)
            ?? throw new NodeNotFoundException(sprintf('Node "%s" not found in workspace "%s", dimension %s', $nodeAddress->aggregateId->value, $nodeAddress->workspaceName->value, $nodeAddress->dimensionSpacePoint->toJson()), 1727100001);

        $collector = new NodeFixtureCollector($subgraph, $this->contentRepositoryRegistry->get($nodeAddress->contentRepositoryId)->getNodeTypeManager());
        return $collector->fixtureFor($collector->collectExportTree($node));
    }

    /**
     * @param string $uriPath see {@see DocumentUriPathResolver::resolve()}
     */
    public function documentAddressByUriPath(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        DimensionSpacePoint $dimensionSpacePoint,
        string $uriPath,
        ?string $siteNodeName = null,
    ): NodeAddress {
        $resolver = new DocumentUriPathResolver(
            $this->subgraph($contentRepositoryId, $workspaceName, $dimensionSpacePoint),
            $this->contentRepositoryRegistry->get($contentRepositoryId)->getNodeTypeManager()
        );
        return NodeAddress::fromNode($resolver->resolve($uriPath, $siteNodeName));
    }

    private function subgraph(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, DimensionSpacePoint $dimensionSpacePoint): ContentSubgraphInterface
    {
        // hidden nodes are content too - export them as well (with their hidden state)
        return $this->contentRepositoryRegistry->get($contentRepositoryId)
            ->getContentGraph($workspaceName)
            ->getSubgraph($dimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
    }
}
