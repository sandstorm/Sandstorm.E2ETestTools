<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Service;

use Doctrine\ORM\EntityManagerInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Exception;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContextFactory;

class NodeExportService
{
    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * Doctrine's Entity Manager.
     *
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var NodeToYamlConverter
     */
    protected $nodeToYamlConverter;

    /**
     * Get node by given node identifier or get root node
     *
     * @param ?string $identifier - Node Identifier
     * @throws Exception
     * @throws \Neos\Eel\Exception
     */
    public function getNeosNodeFromIdentifier(?string $identifier = null): NodeInterface
    {
        $site = $this->siteRepository->findDefault();
        $contentContext = $this->contentContextFactory->create([
            'currentSite' => $site
        ]);

        if ($identifier == null) {
            return $contentContext->getRootNode();
        }

        $siteNode = $contentContext->getCurrentSiteNode();
        return (new FlowQuery([$siteNode]))
            ->find('#' . $identifier)
            ->get(0);
    }

    /**
     * Get a node's nearest parent node of type 'Neos.Neos:Document'
     *
     * @param NodeInterface $node
     * @return NodeInterface closest parent document
     */
    private function getClosestParentDocument(NodeInterface $node): NodeInterface
    {
        $currentNode = $node;
        while (!$currentNode->getNodeType()->isOfType('Neos.Neos:Document')) {
            $currentNode = $currentNode->getParent();
        }
        return $currentNode;
    }

    /**
     * Get all parent nodes of given node
     *
     * @param NodeInterface $node
     * @return array<NodeInterface>
     */
    private function getNodeParents(NodeInterface $node): array
    {
        $parents = [];
        $currentNode = $node;
        while ($currentNode->getContextPath() != "/") {
            try {
                $newParent = $currentNode->findParentNode();
                $parents[] = $newParent;
                $currentNode = $newParent;
            } catch (\Exception $e) {
                break;
            }
        }

        return array_reverse($parents);
    }

    /**
     * Build hierarchical node tree array from given nodes in given order
     * (Given order => nth element is n+1's parent or sibling, first element is root node)
     *
     * @param array<NodeInterface> $nodes
     * @return array
     */
    private function buildNodeTree(array $nodes): array
    {
        $indexed = [];
        $root = [];

        foreach ($nodes as $node) {
            $indexed[$node->getIdentifier()] = $this->nodeToYamlConverter->nodeToNodeTreeElement($node)->toArray();
        }

        foreach ($nodes as $node) {
            $identifier = $node->getIdentifier();
            $parent = $node->getParent();

            if ($parent === null || !isset($indexed[$parent->getIdentifier()])) {
                $root[$identifier] = &$indexed[$identifier];
            } else {
                $indexed[$parent->getIdentifier()]['children'][$identifier] = &$indexed[$identifier];
            }
        }

        return ['nodes' => $root];
    }

    /**
     * Mutates given array to have all of the given node's descendants
     *
     * @param NodeInterface $node
     * @param array<NodeInterface> &$descendants array holding all of node's descendants; given and mutated, not returned
     * @return void
     */
    private function getNodeDescendants(NodeInterface $node, array &$descendants): void
    {
        foreach ($node->findChildNodes() as $childNode) {
            $descendants[] = $childNode;
            $this->getNodeDescendants($childNode, $descendants);
        }
    }

    /**
     * Returns an array of all of the provided node's parents, children and "siblings"
     * (node's closest parent document's children) in hierarchical order
     *
     * @param NodeInterface $node node to build node tree for
     * @return array
     */
    public function getNodeTreeArrayByNode(NodeInterface $node): array
    {
        $closestParentDocument = ($node->getPath() === '/')
            ? $node
            : $this->getClosestParentDocument($node);

        $tree = ($closestParentDocument->getPath() === '/')
            ? [$closestParentDocument]
            : array_merge($this->getNodeParents($closestParentDocument), [$closestParentDocument]);

        $this->getNodeDescendants($closestParentDocument, $tree);

        return $this->buildNodeTree($tree);
    }
}
