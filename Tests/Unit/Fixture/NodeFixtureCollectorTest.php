<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Fixture;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTags;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeTags;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Reference;
use Neos\ContentRepository\Core\Projection\ContentGraph\References;
use Neos\ContentRepository\Core\Projection\ContentGraph\Timestamps;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Node\ReferenceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureCollector;
use Sandstorm\E2ETestTools\Fixture\ReferenceFixtureRow;
use Symfony\Component\Serializer\Serializer;

/**
 * Runs the collector against an in-memory node tree (mocked subgraph, real Node/NodeTypeManager):
 *
 *   sites (root)
 *   └── site (Test:Site, a document)
 *       ├── main (tethered)
 *       │   └── section (Test:Section)
 *       │       ├── text (Test:Text)
 *       │       ├── ghost (Unknown:Type)
 *       │       │   └── ghost-child (Test:Text)
 *       │       └── inner (tethered, inside a tethered-only wrapper chain: section/inner)
 *       │           └── deep-text (Test:Text)
 *       └── page (Test:Page)
 *           └── page-main (tethered "main")
 *               └── page-text (Test:Text)
 *   folder (Test:Folder, not a document, directly below the root)
 *   └── loose (Test:Text)
 */
class NodeFixtureCollectorTest extends UnitTestCase
{
    private NodeTypeManager $nodeTypeManager;

    /**
     * @var array<string,Node>
     */
    private array $nodes = [];

    /**
     * @var array<string,string> child id => parent id
     */
    private array $parents = [];

    /**
     * @var list<Reference>[] node id => references
     */
    private array $references = [];

    private NodeFixtureCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nodeTypeManager = NodeTypeManager::createFromArrayConfiguration([
            'Neos.Neos:Document' => ['abstract' => true],
            'Test:Site' => ['superTypes' => ['Neos.Neos:Document' => true], 'properties' => ['title' => ['type' => 'string']]],
            'Test:Page' => ['superTypes' => ['Neos.Neos:Document' => true], 'properties' => ['title' => ['type' => 'string'], 'uriPathSegment' => ['type' => 'string']]],
            'Test:Folder' => [],
            'Test:Collection' => [],
            'Test:Section' => [],
            'Test:Text' => ['properties' => ['text' => ['type' => 'string'], 'flag' => ['type' => 'boolean']]],
        ]);

        $this->addNode('sites', null, 'Neos.Neos:Sites', NodeAggregateClassification::CLASSIFICATION_ROOT);
        $this->addNode('site', 'sites', 'Test:Site', name: 'site', properties: ['title' => 'Home', 'removedProperty' => 'old']);
        $this->addNode('main', 'site', 'Test:Collection', NodeAggregateClassification::CLASSIFICATION_TETHERED, 'main');
        $this->addNode('section', 'main', 'Test:Section');
        $this->addNode('text', 'section', 'Test:Text', properties: ['text' => 'Hello', 'flag' => true]);
        $this->addNode('ghost', 'section', 'Unknown:Type', properties: ['text' => 'from a removed package']);
        $this->addNode('ghost-child', 'ghost', 'Test:Text');
        $this->addNode('inner', 'section', 'Test:Collection', NodeAggregateClassification::CLASSIFICATION_TETHERED, 'inner');
        $this->addNode('deep-text', 'inner', 'Test:Text');
        $this->addNode('page', 'site', 'Test:Page', properties: ['title' => 'Page', 'uriPathSegment' => 'page']);
        $this->addNode('page-main', 'page', 'Test:Collection', NodeAggregateClassification::CLASSIFICATION_TETHERED, 'main');
        $this->addNode('page-text', 'page-main', 'Test:Text');
        $this->addNode('folder', 'sites', 'Test:Folder');
        $this->addNode('loose', 'folder', 'Test:Text');

        $this->collector = new NodeFixtureCollector($this->subgraph(), $this->nodeTypeManager);
    }

    // ---------------------------------------------------------------- parent references

    #[Test]
    public function siteNodeHasEmptyParent(): void
    {
        self::assertSame('', $this->collector->rowFor($this->nodes['site'])->parent);
    }

    #[Test]
    public function regularParentIsReferencedById(): void
    {
        self::assertSame('section', $this->collector->rowFor($this->nodes['text'])->parent);
        self::assertSame('site', $this->collector->rowFor($this->nodes['page'])->parent);
    }

    #[Test]
    public function tetheredParentIsReferencedViaItsOwner(): void
    {
        self::assertSame('site/main', $this->collector->rowFor($this->nodes['section'])->parent);
        self::assertSame('page/main', $this->collector->rowFor($this->nodes['page-text'])->parent);
    }

    #[Test]
    public function tetheredParentBelowARegularNodeIsReferencedViaThatNode(): void
    {
        self::assertSame('section/inner', $this->collector->rowFor($this->nodes['deep-text'])->parent);
    }

    #[Test]
    public function nestedTetheredParentsAreReferencedAsPath(): void
    {
        $this->addNode('outer', 'page', 'Test:Collection', NodeAggregateClassification::CLASSIFICATION_TETHERED, 'outer');
        $this->addNode('nested', 'outer', 'Test:Collection', NodeAggregateClassification::CLASSIFICATION_TETHERED, 'nested');
        $this->addNode('nested-text', 'nested', 'Test:Text');

        self::assertSame('page/outer/nested', $this->collector->rowFor($this->nodes['nested-text'])->parent);
    }

    // ---------------------------------------------------------------- row content

    #[Test]
    public function rowContainsIdTypeAndDimension(): void
    {
        $row = $this->collector->rowFor($this->nodes['text']);

        self::assertSame('text', $row->nodeAggregateId);
        self::assertSame('Test:Text', $row->nodeType);
        self::assertSame(['language' => 'de'], $row->dimensionSpacePoint);
    }

    #[Test]
    public function declaredPropertiesAreExportedWithTheirSerializedValues(): void
    {
        self::assertSame(['text' => 'Hello', 'flag' => true], $this->collector->rowFor($this->nodes['text'])->properties);
    }

    #[Test]
    public function propertiesTheNodeTypeNoLongerDeclaresAreLeftOut(): void
    {
        self::assertSame(['title' => 'Home'], $this->collector->rowFor($this->nodes['site'])->properties);
    }

    #[Test]
    public function nodeOfUnknownTypeHasNoProperties(): void
    {
        self::assertSame([], $this->collector->rowFor($this->nodes['ghost'])->properties);
    }

    #[Test]
    public function hiddenStateIsPartOfTheRow(): void
    {
        // a hidden node imported as visible would show content the exported page didn't show
        $this->addNode('hidden-text', 'section', 'Test:Text', tags: SubtreeTags::create(NeosSubtreeTag::disabled()));

        self::assertTrue($this->collector->rowFor($this->nodes['hidden-text'])->hidden);
        self::assertFalse($this->collector->rowFor($this->nodes['text'])->hidden);
    }

    #[Test]
    public function onlyExplicitlyHiddenNodesAreMarkedNotTheirDescendants(): void
    {
        // descendants inherit the hidden state on import anyway - marking them too would keep them hidden when the
        // parent gets shown again in a test
        $this->addNode('hidden-section', 'main', 'Test:Section', tags: SubtreeTags::create(NeosSubtreeTag::disabled()));
        $this->addNode('inherits-hidden', 'hidden-section', 'Test:Text', tags: SubtreeTags::createEmpty(), inheritedTags: SubtreeTags::create(NeosSubtreeTag::disabled()));

        self::assertTrue($this->collector->rowFor($this->nodes['hidden-section'])->hidden);
        self::assertFalse($this->collector->rowFor($this->nodes['inherits-hidden'])->hidden);
    }

    #[Test]
    public function otherSubtreeTagsDontMarkANodeHidden(): void
    {
        $this->addNode('tagged', 'section', 'Test:Text', tags: SubtreeTags::create(SubtreeTag::fromString('custom')));

        self::assertFalse($this->collector->rowFor($this->nodes['tagged'])->hidden);
    }

    // ---------------------------------------------------------------- which nodes need rows

    #[Test]
    public function rootAndTetheredNodesNeedNoRow(): void
    {
        self::assertFalse($this->collector->needsRow($this->nodes['sites']));
        self::assertFalse($this->collector->needsRow($this->nodes['main']));
        self::assertTrue($this->collector->needsRow($this->nodes['site']));
        self::assertTrue($this->collector->needsRow($this->nodes['text']));
    }

    #[Test]
    public function knownNodeTypesAreDetected(): void
    {
        self::assertTrue($this->collector->hasKnownNodeType($this->nodes['text']));
        self::assertFalse($this->collector->hasKnownNodeType($this->nodes['ghost']));
    }

    // ---------------------------------------------------------------- references

    #[Test]
    public function nodeWithoutReferencesHasNoReferenceRows(): void
    {
        self::assertSame([], $this->collector->referencesFor($this->nodes['text']));
    }

    #[Test]
    public function referencesAreGroupedByNameKeepingTargetOrder(): void
    {
        $this->addReference('site', 'teasers', 'page-text');
        $this->addReference('site', 'privacyPage', 'page');
        $this->addReference('site', 'teasers', 'text');

        $rows = $this->collector->referencesFor($this->nodes['site']);

        self::assertEquals([
            new ReferenceFixtureRow('site', 'teasers', ['page-text', 'text'], ['language' => 'de']),
            new ReferenceFixtureRow('site', 'privacyPage', ['page'], ['language' => 'de']),
        ], $rows);
    }

    #[Test]
    public function referencePropertiesAreKeptPerTarget(): void
    {
        // Neos 9 references can carry properties - per target, so references with properties get a row each
        $this->addReference('site', 'teasers', 'page', ['label' => 'Read more']);
        $this->addReference('site', 'teasers', 'text');
        $this->addReference('site', 'teasers', 'page-text', ['label' => 'Details']);

        $rows = array_map(fn ($row) => [$row->targets, $row->properties], $this->collector->referencesFor($this->nodes['site']));

        self::assertSame([
            [['page'], ['label' => 'Read more']],
            [['text'], []],
            [['page-text'], ['label' => 'Details']],
        ], $rows);
    }

    // ---------------------------------------------------------------- export tree

    #[Test]
    public function exportTreeIsTheClosestDocumentWithAncestorsAndDescendantsParentsFirst(): void
    {
        self::assertSame(
            ['site', 'section', 'text', 'deep-text', 'page', 'page-text'],
            $this->ids($this->collector->collectExportTree($this->nodes['text']))
        );
    }

    #[Test]
    public function exportTreeOfASubpageContainsItsAncestorsButNotTheirOtherContent(): void
    {
        self::assertSame(['site', 'page', 'page-text'], $this->ids($this->collector->collectExportTree($this->nodes['page-text'])));
    }

    #[Test]
    public function exportTreeOfATetheredNodeStartsAtItsDocument(): void
    {
        self::assertSame(['site', 'page', 'page-text'], $this->ids($this->collector->collectExportTree($this->nodes['page-main'])));
    }

    #[Test]
    public function exportTreeSkipsNodesOfUnknownTypeTogetherWithTheirSubtree(): void
    {
        $ids = $this->ids($this->collector->collectExportTree($this->nodes['site']));

        self::assertNotContains('ghost', $ids);
        self::assertNotContains('ghost-child', $ids);
    }

    #[Test]
    public function exportTreeOfANodeOutsideAnyDocumentStartsAtTheNodeItself(): void
    {
        self::assertSame(['folder', 'loose'], $this->ids($this->collector->collectExportTree($this->nodes['loose'])));
    }

    #[Test]
    public function exportedRowsCanBeCreatedInOrder(): void
    {
        // every row's parent must be the site marker, a row created before, or "<row created before>/<tethered name>"
        $created = [];
        foreach ($this->collector->collectExportTree($this->nodes['site']) as $node) {
            $parent = $this->collector->rowFor($node)->parent;
            if ($parent !== '') {
                self::assertContains(explode('/', $parent)[0], $created, sprintf('Parent of "%s" is created later', $node->aggregateId->value));
            }
            $created[] = $node->aggregateId->value;
        }
    }

    #[Test]
    public function ancestorsAreListedFromTheTopDown(): void
    {
        self::assertSame(['sites', 'site', 'main', 'section'], $this->ids($this->collector->ancestors($this->nodes['text'])));
        self::assertSame([], $this->ids($this->collector->ancestors($this->nodes['sites'])));
    }

    // ---------------------------------------------------------------- in-memory tree

    private function addNode(
        string $id,
        ?string $parentId,
        string $nodeType,
        NodeAggregateClassification $classification = NodeAggregateClassification::CLASSIFICATION_REGULAR,
        ?string $name = null,
        array $properties = [],
        ?SubtreeTags $tags = null,
        ?SubtreeTags $inheritedTags = null,
    ): void {
        $this->nodes[$id] = Node::create(
            ContentRepositoryId::fromString('default'),
            WorkspaceName::forLive(),
            DimensionSpacePoint::fromArray(['language' => 'de']),
            NodeAggregateId::fromString($id),
            OriginDimensionSpacePoint::fromArray(['language' => 'de']),
            $classification,
            NodeTypeName::fromString($nodeType),
            $this->propertyCollection($properties),
            $name !== null ? NodeName::fromString($name) : null,
            NodeTags::create($tags ?? SubtreeTags::createEmpty(), $inheritedTags ?? SubtreeTags::createEmpty()),
            Timestamps::create(new \DateTimeImmutable(), new \DateTimeImmutable(), null, null),
            VisibilityConstraints::createEmpty(),
        );
        if ($parentId !== null) {
            $this->parents[$id] = $parentId;
        }
    }

    private function addReference(string $sourceId, string $name, string $targetId, array $properties = []): void
    {
        $this->references[$sourceId][] = new Reference(
            $this->nodes[$targetId],
            ReferenceName::fromString($name),
            $properties === [] ? null : $this->propertyCollection($properties)
        );
    }

    private function propertyCollection(array $plainValues): PropertyCollection
    {
        return new PropertyCollection(
            SerializedPropertyValues::fromArray(array_map(fn (mixed $value) => ['value' => $value, 'type' => get_debug_type($value)], $plainValues)),
            new PropertyConverter(new Serializer())
        );
    }

    private function subgraph(): ContentSubgraphInterface
    {
        $subgraph = $this->createMock(ContentSubgraphInterface::class);
        $subgraph->method('findParentNode')->willReturnCallback(
            fn (NodeAggregateId $id) => isset($this->parents[$id->value]) ? $this->nodes[$this->parents[$id->value]] : null
        );
        $subgraph->method('findChildNodes')->willReturnCallback(
            fn (NodeAggregateId $id) => Nodes::fromArray(array_values(array_map(
                fn (string $childId) => $this->nodes[$childId],
                array_keys($this->parents, $id->value, true)
            )))
        );
        $subgraph->method('findClosestNode')->willReturnCallback(function (NodeAggregateId $id, FindClosestNodeFilter $filter) {
            $nodeTypeNames = iterator_to_array($filter->nodeTypes->explicitlyAllowedNodeTypeNames);
            for ($current = $id->value; $current !== null; $current = $this->parents[$current] ?? null) {
                $nodeType = $this->nodeTypeManager->getNodeType($this->nodes[$current]->nodeTypeName);
                foreach ($nodeTypeNames as $nodeTypeName) {
                    if ($nodeType?->isOfType($nodeTypeName)) {
                        return $this->nodes[$current];
                    }
                }
            }
            return null;
        });
        $subgraph->method('findReferences')->willReturnCallback(
            fn (NodeAggregateId $id) => References::fromArray($this->references[$id->value] ?? [])
        );
        return $subgraph;
    }

    /**
     * @param iterable<Node> $nodes
     * @return list<string>
     */
    private function ids(iterable $nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            $ids[] = $node->aggregateId->value;
        }
        return $ids;
    }
}
