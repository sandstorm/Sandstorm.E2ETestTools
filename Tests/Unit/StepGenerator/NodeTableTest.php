<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\StepGenerator;

use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\StepGenerator\NodeTable;
use Sandstorm\E2ETestTools\Tests\Unit\Fixture\InMemorySubgraphTrait;

/**
 * Which nodes the step generator collects - against an in-memory node tree:
 *
 *   sites (root)
 *   └── site (Test:Site)
 *       └── main (tethered)
 *           ├── section (Test:Section)
 *           │   ├── first (Test:Text)
 *           │   └── second (Test:Text)
 *           └── other (Test:Section)
 *               └── third (Test:Text)
 */
class NodeTableTest extends UnitTestCase
{
    use InMemorySubgraphTrait;

    private NodeTable $nodeTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nodeTypeManager = NodeTypeManager::createFromArrayConfiguration([
            'Neos.Neos:Document' => ['abstract' => true],
            'Test:Content' => ['abstract' => true],
            'Test:Site' => ['superTypes' => ['Neos.Neos:Document' => true]],
            'Test:Collection' => ['superTypes' => ['Test:Content' => true]],
            'Test:Section' => ['superTypes' => ['Test:Content' => true]],
            'Test:Text' => ['superTypes' => ['Test:Content' => true], 'properties' => ['text' => ['type' => 'string']]],
        ]);
        $this->addNode('sites', null, 'Neos.Neos:Sites', NodeAggregateClassification::CLASSIFICATION_ROOT);
        $this->addNode('site', 'sites', 'Test:Site', name: 'site');
        $this->addNode('main', 'site', 'Test:Collection', NodeAggregateClassification::CLASSIFICATION_TETHERED, 'main');
        $this->addNode('section', 'main', 'Test:Section');
        $this->addNode('first', 'section', 'Test:Text', properties: ['text' => 'First']);
        $this->addNode('second', 'section', 'Test:Text', properties: ['text' => 'Second']);
        $this->addNode('other', 'main', 'Test:Section');
        $this->addNode('third', 'other', 'Test:Text', properties: ['text' => 'Third']);

        $this->nodeTable = new NodeTable($this->subgraph(), $this->nodeTypeManager);
    }

    #[Test]
    public function parentsAreAddedSiteFirstWithoutRootAndTetheredNodes(): void
    {
        $this->nodeTable->addParents($this->node('first'));

        self::assertSame(['site', 'section'], $this->printedNodeIds());
    }

    #[Test]
    public function aNodeIsAddedOnce(): void
    {
        $this->nodeTable->addNode($this->node('first'));
        $this->nodeTable->addNode($this->node('first'));

        self::assertSame(['first'], $this->printedNodeIds());
    }

    #[Test]
    public function tetheredNodesAreTraversedButNotAdded(): void
    {
        $this->nodeTable->addChildNodesRecursively($this->node('site'), 'Test:Content');

        self::assertSame(['section', 'first', 'second', 'other', 'third'], $this->printedNodeIds());
    }

    #[Test]
    public function tetheredNodesAreAddedOnRequest(): void
    {
        $this->nodeTable->addChildNodesRecursively($this->node('site'), 'Test:Content', maxDepth: 2, includeTetheredNodes: true);

        self::assertSame(['main', 'section', 'other'], $this->printedNodeIds());
    }

    #[Test]
    public function maxDepthCountsTheLevelsBelowTheNode(): void
    {
        // level 1: main (tethered), level 2: the sections
        $this->nodeTable->addChildNodesRecursively($this->node('site'), 'Test:Content', maxDepth: 2);

        self::assertSame(['section', 'other'], $this->printedNodeIds());
    }

    #[Test]
    public function maxSiblingsLimitsTheChildNodesOfEachParent(): void
    {
        $this->nodeTable->addChildNodesRecursively($this->node('main'), 'Test:Content', maxSiblings: 1);

        self::assertSame(['section', 'first'], $this->printedNodeIds());
    }

    #[Test]
    public function addChildNodesAddsTheDirectChildrenAndCallsTheHandler(): void
    {
        $handled = [];
        $this->nodeTable->addChildNodes($this->node('section'), 'Test:Text', null, function (Node $child) use (&$handled) {
            $handled[] = $child->aggregateId->value;
        });

        self::assertSame(['first', 'second'], $this->printedNodeIds());
        self::assertSame(['first', 'second'], $handled);
    }

    #[Test]
    public function printPrintsTheFixtureSteps(): void
    {
        $this->nodeTable->addNode($this->node('first'));

        $output = $this->print();

        self::assertStringStartsWith("# I have no images\nGiven I have the following nodes in site \"site\":\n", $output);
        self::assertStringContainsString('{"text":"First"}', $output);
    }

    private function node(string $id): Node
    {
        return $this->nodes[$id];
    }

    private function print(): string
    {
        ob_start();
        try {
            $this->nodeTable->print('site');
        } finally {
            $output = (string)ob_get_clean();
        }
        return $output;
    }

    /**
     * @return list<string> the NodeAggregateId column of the printed node table, in order
     */
    private function printedNodeIds(): array
    {
        preg_match_all('/^\s*\|\s*([\w-]+)\s*\|/m', $this->print(), $matches);
        return array_values(array_filter($matches[1], static fn (string $id) => $id !== 'NodeAggregateId'));
    }
}
