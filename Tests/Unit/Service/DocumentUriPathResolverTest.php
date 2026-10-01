<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Service;

use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Service\DocumentUriPathResolver;
use Sandstorm\E2ETestTools\Service\NodeNotFoundException;
use Sandstorm\E2ETestTools\Tests\Unit\Fixture\InMemorySubgraphTrait;

/**
 *   sites (root)
 *   ├── site (site node "site", uriPathSegment "home" - ignored for the homepage)
 *   │   ├── main (tethered)
 *   │   │   └── text (content with a uriPathSegment property)
 *   │   ├── about (page "about")
 *   │   │   └── team (page "team")
 *   │   └── contact (page "contact")
 *   └── no-site (not a Neos.Neos:Site)
 */
class DocumentUriPathResolverTest extends UnitTestCase
{
    use InMemorySubgraphTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nodeTypeManager = NodeTypeManager::createFromArrayConfiguration([
            'Neos.Neos:Document' => ['abstract' => true, 'properties' => ['uriPathSegment' => ['type' => 'string']]],
            'Neos.Neos:Site' => ['abstract' => true, 'superTypes' => ['Neos.Neos:Document' => true]],
            'Test:Site' => ['superTypes' => ['Neos.Neos:Site' => true]],
            'Test:Page' => ['superTypes' => ['Neos.Neos:Document' => true]],
            'Test:Collection' => [],
            'Test:Text' => ['properties' => ['uriPathSegment' => ['type' => 'string']]],
        ]);
        $this->addNode('sites', null, 'Neos.Neos:Sites', NodeAggregateClassification::CLASSIFICATION_ROOT);
        $this->addNode('site', 'sites', 'Test:Site', name: 'site', properties: ['uriPathSegment' => 'home']);
        $this->addNode('main', 'site', 'Test:Collection', NodeAggregateClassification::CLASSIFICATION_TETHERED, 'main');
        $this->addNode('text', 'main', 'Test:Text', properties: ['uriPathSegment' => 'text']);
        $this->addNode('about', 'site', 'Test:Page', properties: ['uriPathSegment' => 'about']);
        $this->addNode('team', 'about', 'Test:Page', properties: ['uriPathSegment' => 'team']);
        $this->addNode('contact', 'site', 'Test:Page', properties: ['uriPathSegment' => 'contact']);
        $this->addNode('no-site', 'sites', 'Test:Page', name: 'no-site', properties: ['uriPathSegment' => 'no-site']);
    }

    #[Test]
    #[DataProvider('uriPaths')]
    public function documentIsFoundByItsUriPath(string $uriPath, string $expectedNodeAggregateId): void
    {
        self::assertSame($expectedNodeAggregateId, $this->resolver()->resolve($uriPath)->aggregateId->value);
    }

    public static function uriPaths(): iterable
    {
        yield 'homepage as slash' => ['/', 'site'];
        yield 'homepage as empty path' => ['', 'site'];
        yield 'first level' => ['about', 'about'];
        yield 'nested' => ['about/team', 'team'];
        // as copied from a browser URL bar
        yield 'leading and trailing slashes' => ['/about/team/', 'team'];
        yield 'double slashes' => ['about//team', 'team'];
    }

    #[Test]
    public function contentNodesAreNotMatchedEvenWithAUriPathSegment(): void
    {
        $this->addNode('loose-text', 'site', 'Test:Text', properties: ['uriPathSegment' => 'loose']);

        $this->expectException(NodeNotFoundException::class);

        $this->resolver()->resolve('loose');
    }

    #[Test]
    public function siteNodeSegmentIsNotPartOfThePath(): void
    {
        $this->expectException(NodeNotFoundException::class);

        $this->resolver()->resolve('home/about');
    }

    #[Test]
    public function unknownSegmentFailsListingTheExistingOnes(): void
    {
        // lets the caller (e.g. an AI agent) correct the path without looking into the database
        $this->expectException(NodeNotFoundException::class);
        $this->expectExceptionMessageMatches('#"teams" below "/about".*team#');

        $this->resolver()->resolve('about/teams');
    }

    #[Test]
    public function onlySiteIsUsedWithoutSiteName(): void
    {
        // "no-site" below the sites root is no Neos.Neos:Site, so "site" is the only one
        self::assertSame('contact', $this->resolver()->resolve('contact')->aggregateId->value);
    }

    #[Test]
    public function siteCanBeChosenByNodeName(): void
    {
        $this->addNode('other', 'sites', 'Test:Site', name: 'other');
        $this->addNode('other-contact', 'other', 'Test:Page', properties: ['uriPathSegment' => 'contact']);

        self::assertSame('other-contact', $this->resolver()->resolve('contact', 'other')->aggregateId->value);
        self::assertSame('contact', $this->resolver()->resolve('contact', 'site')->aggregateId->value);
    }

    #[Test]
    public function severalSitesNeedASiteName(): void
    {
        $this->addNode('other', 'sites', 'Test:Site', name: 'other');

        $this->expectException(NodeNotFoundException::class);
        $this->expectExceptionMessageMatches('/Several sites.*site, other/');

        $this->resolver()->resolve('/');
    }

    #[Test]
    public function unknownSiteNameFailsListingTheSites(): void
    {
        $this->expectException(NodeNotFoundException::class);
        $this->expectExceptionMessageMatches('/"nope".*Sites: site/');

        $this->resolver()->resolve('/', 'nope');
    }

    private function resolver(): DocumentUriPathResolver
    {
        return new DocumentUriPathResolver($this->subgraph(), $this->nodeTypeManager);
    }
}
