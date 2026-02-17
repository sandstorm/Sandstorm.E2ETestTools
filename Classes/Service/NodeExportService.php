<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Service;

use Doctrine\ORM\EntityManagerInterface;
use Neos\ContentRepository\Domain\Model\Node;
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
     * Get node with given node identifier
     *
     * @param string $identifier - Node Identifier
     * @throws Exception
     * @throws \Neos\Eel\Exception
     */
    public function getNeosNodeFromIdentifier(string $identifier): Node
    {
        $site = $this->siteRepository->findDefault();
        $contentContext = $this->contentContextFactory->create([
            'currentSite' => $site
        ]);
        $siteNode = $contentContext->getCurrentSiteNode();

        return (new FlowQuery([$siteNode]))
            ->find('#' . $identifier)
            ->get(0);
    }

    /**
     * Get a node's nearest parent node of type 'Neos.Neos:Document'
     */
    private function getNearestDocumentNodeParent($node): Node
    {
        $currentNode = $node;
        while (!$currentNode->getNodeType()->isOfType('Neos.Neos:Document')) {
            $currentNode = $currentNode->getParent();
        }

        return $currentNode;
    }

    /**
     * Get all parent nodes of given node
     * @param Node $node
     * @return array<Node>
     */
    private function getNodeParents(Node $node): array
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
     * (Given order => nth element is n+1's parent, first element is root node)
     *
     *
     */
    private function buildNodeTree(array $nodes): array
    {
        $indexed = [];
        $root = [];

        foreach ($nodes as $node) {
            $indexed[$node->getIdentifier()] = $this->nodeToYamlConverter->nodeToArray($node);
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

    public function getNodeTreeArrayByNode(Node $node): array
    {
        $nearestDocumentParent = $this->getNearestDocumentNodeParent($node);
        /** @var array<Node> $nearestDocumentChildren */
        $nearestDocumentChildren = $nearestDocumentParent->findChildNodes()->toArray();
        /** @var array<Node> $documentParents */
        $documentParents = $this->getNodeParents($nearestDocumentParent);

        $tree = $documentParents;
        $tree[] = $nearestDocumentParent;

        foreach ($nearestDocumentChildren as $child) {
            $tree[] = $child;
        }

        return $this->buildNodeTree($tree);
    }
}
