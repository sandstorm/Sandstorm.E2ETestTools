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

    public function addNodesUnderneath(NodeInterface $node, $nodeTypeFilter): void
    {
        foreach ($node->getChildNodes($nodeTypeFilter) as $childNode) {
            $this->addNode($childNode);
            $this->addNodesUnderneath($childNode, $nodeTypeFilter);
        }
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
