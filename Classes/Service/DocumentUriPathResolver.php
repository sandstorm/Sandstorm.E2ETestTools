<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Service;

use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;

/**
 * Finds a document by its URI path ("about/team"), so the export can be started from a URL instead of a node id.
 *
 * Walks the subgraph segment by segment via the documents' "uriPathSegment" property instead of asking Neos' URI path
 * projection: that one is internal and only knows the live workspace, the export reads any workspace.
 *
 * @Flow\Proxy(false)
 */
final readonly class DocumentUriPathResolver
{
    public function __construct(
        private ContentSubgraphInterface $subgraph,
        private NodeTypeManager $nodeTypeManager,
    ) {
    }

    /**
     * @param string $uriPath without dimension prefix and suffix; "" or "/" is the site node (homepage)
     * @param string|null $siteNodeName null: the only site
     */
    public function resolve(string $uriPath, ?string $siteNodeName = null): Node
    {
        $document = $this->siteNode($siteNodeName);
        $resolvedPath = '';
        foreach (array_filter(explode('/', trim($uriPath, '/')), fn (string $segment) => $segment !== '') as $segment) {
            $children = iterator_to_array($this->subgraph->findChildNodes($document->aggregateId, FindChildNodesFilter::create('Neos.Neos:Document')));
            $segmentsOfChildren = array_map(fn (Node $child) => (string)$child->getProperty('uriPathSegment'), $children);
            $matchingIndex = array_search($segment, $segmentsOfChildren, true);
            if ($matchingIndex === false) {
                throw new NodeNotFoundException(sprintf(
                    'No document with URI path segment "%s" below "/%s". Existing segments there: %s',
                    $segment,
                    $resolvedPath,
                    $segmentsOfChildren === [] ? '(none)' : implode(', ', $segmentsOfChildren)
                ), 1727800001);
            }
            $document = $children[$matchingIndex];
            $resolvedPath = ltrim($resolvedPath . '/' . $segment, '/');
        }
        return $document;
    }

    private function siteNode(?string $siteNodeName): Node
    {
        $sitesRoot = $this->subgraph->findRootNodeByType(NodeTypeName::fromString('Neos.Neos:Sites'))
            ?? throw new NodeNotFoundException(sprintf('No Neos.Neos:Sites root node in dimension %s - does the dimension match your content dimensions?', $this->subgraph->getDimensionSpacePoint()->toJson()), 1727800002);
        $sites = [];
        foreach ($this->subgraph->findChildNodes($sitesRoot->aggregateId, FindChildNodesFilter::create()) as $child) {
            if ($child->name !== null && $this->nodeTypeManager->getNodeType($child->nodeTypeName)?->isOfType('Neos.Neos:Site') === true) {
                $sites[$child->name->value] = $child;
            }
        }
        $siteNames = implode(', ', array_keys($sites));
        if ($siteNodeName !== null) {
            return $sites[$siteNodeName]
                ?? throw new NodeNotFoundException(sprintf('Site node "%s" not found. Sites: %s', $siteNodeName, $siteNames), 1727800003);
        }
        if (count($sites) !== 1) {
            throw new NodeNotFoundException(sprintf('%s - give the site node name. Sites: %s', $sites === [] ? 'No site found' : 'Several sites', $siteNames), 1727800004);
        }
        return reset($sites);
    }
}
