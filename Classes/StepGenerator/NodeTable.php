<?php

namespace Sandstorm\E2ETestTools\StepGenerator;

use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 *
 * @internal
 */
class NodeTable
{
    private GherkinTable $gherkinTable;

    private array $defaultProperties;

    public function __construct(array $defaultProperties = [])
    {
        $this->defaultProperties = $defaultProperties;
        $this->gherkinTable = new GherkinTable(array_merge([
            'Path',
            'Node Type',
            'Properties',
            'HiddenInIndex'
        ], array_keys($defaultProperties)));
    }

    public function addNode(NodeInterface $node): void
    {
        if ($node->getPath() === '/') {
            // we never need to add the "/" node
            return;
        }
        if ($node->isAutoCreated()) {
            return;
        }
        $this->gherkinTable->addRow(array_merge($this->defaultProperties, [
            'Path' => $node->getPath(),
            'Node Type' => $node->getNodeType()->getName(),
            'Properties' => json_encode($node->getNodeData()->getProperties()),
            'HiddenInIndex' => $node->isHiddenInIndex() ? 'true' : 'false'
        ]));
    }

    /**
     * Auto generated child-nodes are excluded here. But we don't need them anyways, since they
     * are created automatically during import based on the node type yaml.
     *
     * @param NodeInterface $node the top level node
     * @param $nodeTypeFilter string|null pass in null for no filter
     */
    public function addNodesUnderneathExcludingAutoGeneratedChildNodes(NodeInterface $node, $nodeTypeFilter): void
    {
        foreach ($node->getChildNodes($nodeTypeFilter) as $childNode) {
            $this->addNode($childNode);
            $this->addNodesUnderneathExcludingAutoGeneratedChildNodes($childNode, $nodeTypeFilter);
        }
    }

    /**
     * @deprecated use addNodesUnderneathExcludingAutoGeneratedChildNodes since better naming, this gets removed soon!
     */
    public function addNodesUnderneath(NodeInterface $node, $nodeTypeFilter): void
    {
        $this->addNodesUnderneathExcludingAutoGeneratedChildNodes($node, $nodeTypeFilter);
    }

    public function addParents(NodeInterface $node): void
    {
        $parentNode = $node->getParent();
        if ($parentNode !== null) {
            $this->addParents($parentNode);
            $this->addNode($parentNode);
        }
    }

    public function print(): void
    {
        echo 'Given I have the following nodes:';
        echo "\n";
        $this->gherkinTable->print();
    }
}
