<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\StepGenerator;

use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Utility\ObjectAccess;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureCollector;
use Sandstorm\E2ETestTools\Fixture\ReferenceFixtureRow;

/**
 * Collects nodes of one subgraph and prints them as fixture steps:
 * "I have the following images:", "I have the following nodes in site ...:" and "the following node references:".
 *
 * Create it via {@see NodeTableBuilderService}.
 */
class NodeTable
{
    private NodeFixtureCollector $collector;
    private GherkinTable $nodeTable;
    private ImageTable $imageTable;
    private PersistentResourceFixtures $persistentResourceFixtures;

    /**
     * @var array<string,true> NodeAggregateIds already added
     */
    private array $addedNodes = [];

    /**
     * @var list<ReferenceFixtureRow>
     */
    private array $references = [];

    public function __construct(
        private readonly ContentSubgraphInterface $subgraph,
        NodeTypeManager $nodeTypeManager,
        ?string $fixtureBasePath = null,
        array $defaultPersistentResourceProperties = [],
        array $defaultImageProperties = []
    ) {
        $this->collector = new NodeFixtureCollector($subgraph, $nodeTypeManager);
        $this->nodeTable = new GherkinTable(['NodeAggregateId', 'Parent', 'NodeType', 'Properties', 'DimensionSpacePoint']);
        $this->persistentResourceFixtures = new PersistentResourceFixtures($fixtureBasePath, $defaultPersistentResourceProperties);
        $this->imageTable = new ImageTable($this->persistentResourceFixtures, $defaultImageProperties);
    }

    /**
     * Adds the node, its references and the images it uses. The root and tethered nodes are skipped - they are
     * created automatically on import - unless tethered nodes are explicitly wanted (they can't be created by the
     * node creation step, though).
     */
    public function addNode(Node $node, bool $includeTetheredNode = false): void
    {
        if (array_key_exists($node->aggregateId->value, $this->addedNodes) || $node->classification->isRoot() || !$this->collector->hasKnownNodeType($node)) {
            return;
        }
        if (!$includeTetheredNode && $node->classification->isTethered()) {
            return;
        }
        $this->addedNodes[$node->aggregateId->value] = true;

        $this->nodeTable->addRow($this->collector->rowFor($node)->toTableCells());
        array_push($this->references, ...$this->collector->referencesFor($node));
        foreach ($node->properties as $propertyValue) {
            if ($propertyValue instanceof ImageInterface) {
                $this->imageTable->addImage(ObjectAccess::getProperty($propertyValue, 'Persistence_Object_Identifier', true), $propertyValue);
            }
        }
    }

    /**
     * Adds all ancestors of the node (site node first), not the node itself.
     */
    public function addParents(Node $node): void
    {
        foreach ($this->collector->ancestors($node) as $ancestor) {
            $this->addNode($ancestor);
        }
    }

    /**
     * Adds all descendants matching the filter (tethered nodes are traversed, but not added by default).
     *
     * @param string $nodeTypeFilter e.g. "Neos.Neos:Content" or "!Neos.Neos:Document"
     * @param int|null $maxSiblings max. child nodes per parent
     * @param int|null $maxDepth max. levels below $node (null: unlimited)
     */
    public function addChildNodesRecursively(
        Node $node,
        string $nodeTypeFilter,
        ?int $maxSiblings = null,
        ?int $maxDepth = null,
        bool $includeTetheredNodes = false,
    ): void {
        $this->addChildNodesRecursivelyInternal($node, $nodeTypeFilter, $maxSiblings, $maxDepth, $includeTetheredNodes, 0);
    }

    private function addChildNodesRecursivelyInternal(
        Node $node,
        string $nodeTypeFilter,
        ?int $maxSiblings,
        ?int $maxDepth,
        bool $includeTetheredNodes,
        int $currentDepth,
    ): void {
        if ($maxDepth !== null && $currentDepth >= $maxDepth) {
            return;
        }
        foreach ($this->findChildNodes($node, $nodeTypeFilter, $maxSiblings) as $childNode) {
            if (!$this->collector->hasKnownNodeType($childNode)) {
                // can't be created - and neither can its subtree
                continue;
            }
            $this->addNode($childNode, $includeTetheredNodes);
            $this->addChildNodesRecursivelyInternal($childNode, $nodeTypeFilter, $maxSiblings, $maxDepth, $includeTetheredNodes, $currentDepth + 1);
        }
    }

    /**
     * Adds the direct children matching the filter; $childNodeHandler is called for each of them.
     */
    public function addChildNodes(
        Node $baseNode,
        string $nodeTypeFilter,
        ?int $limit = null,
        ?\Closure $childNodeHandler = null
    ): void {
        foreach ($this->findChildNodes($baseNode, $nodeTypeFilter, $limit) as $childNode) {
            $this->addNode($childNode);
            if ($childNodeHandler !== null) {
                $childNodeHandler($childNode);
            }
        }
    }

    /**
     * Stores image fixture files (see {@see NodeTableBuilder::withFixturesBaseDirectory()}) and prints the steps.
     *
     * @param string $siteName the name used in "I have the following nodes in site ...", i.e. the site node name
     */
    public function print(string $siteName): void
    {
        $this->persistentResourceFixtures->storeFixtures();

        $this->imageTable->print();

        echo sprintf('Given I have the following nodes in site "%s":', $siteName) . "\n";
        $this->nodeTable->print();

        // references to nodes that aren't part of the table couldn't be set
        $referenceTable = new GherkinTable(['NodeAggregateId', 'ReferenceName', 'Targets', 'DimensionSpacePoint']);
        foreach ($this->references as $reference) {
            $targets = array_values(array_filter($reference->targets, fn (string $target) => array_key_exists($target, $this->addedNodes)));
            if ($targets !== []) {
                $referenceTable->addRow((new ReferenceFixtureRow($reference->nodeAggregateId, $reference->referenceName, $targets, $reference->dimensionSpacePoint))->toTableCells());
            }
        }
        if (!$referenceTable->isEmpty()) {
            echo 'And the following node references:' . "\n";
            $referenceTable->print();
        }
    }

    private function findChildNodes(Node $node, string $nodeTypeFilter, ?int $limit): iterable
    {
        return $this->subgraph->findChildNodes(
            $node->aggregateId,
            FindChildNodesFilter::create($nodeTypeFilter, null, null, null, $limit !== null ? ['limit' => $limit] : null)
        );
    }
}
