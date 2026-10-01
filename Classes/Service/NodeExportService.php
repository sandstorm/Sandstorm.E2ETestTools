<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Service;

use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
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
        $contentRepository = $this->contentRepositoryRegistry->get($nodeAddress->contentRepositoryId);
        // hidden nodes are content too - export them as well (with their hidden state)
        $subgraph = $contentRepository->getContentGraph($nodeAddress->workspaceName)
            ->getSubgraph($nodeAddress->dimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
        $node = $subgraph->findNodeById($nodeAddress->aggregateId)
            ?? throw new NodeNotFoundException(sprintf('Node "%s" not found in workspace "%s", dimension %s', $nodeAddress->aggregateId->value, $nodeAddress->workspaceName->value, $nodeAddress->dimensionSpacePoint->toJson()), 1727100001);

        $collector = new NodeFixtureCollector($subgraph, $contentRepository->getNodeTypeManager());
        return $collector->fixtureFor($collector->collectExportTree($node));
    }
}
