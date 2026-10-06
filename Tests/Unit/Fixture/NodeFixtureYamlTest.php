<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Fixture;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Fixture\NodeFixture;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureRow;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureYaml;
use Sandstorm\E2ETestTools\Fixture\ReferenceFixtureRow;
use Symfony\Component\Yaml\Yaml;

/**
 * The YAML fixture format: written by the export (button, CLI), read by "I have the following nodes from file ...".
 */
class NodeFixtureYamlTest extends UnitTestCase
{
    /**
     * @var list<string>
     */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        array_map(unlink(...), array_filter($this->temporaryFiles, file_exists(...)));
        parent::tearDown();
    }

    // ---------------------------------------------------------------- dump

    #[Test]
    public function nodesAreDumpedWithTheImportFields(): void
    {
        $yaml = Yaml::parse(NodeFixtureYaml::dump(new NodeFixture([new NodeFixtureRow('headline', 'section', 'Vendor.Site:Content.Headline', ['title' => 'Hi'], ['language' => 'de'])])));

        self::assertSame(['nodes' => [[
            'nodeAggregateId' => 'headline',
            'parent' => 'section',
            'nodeType' => 'Vendor.Site:Content.Headline',
            'properties' => ['title' => 'Hi'],
            'dimensionSpacePoint' => ['language' => 'de'],
        ]]], $yaml);
    }

    #[Test]
    public function hiddenAndReferencePropertiesAreOnlyDumpedWhenSet(): void
    {
        $yaml = Yaml::parse(NodeFixtureYaml::dump(new NodeFixture(
            [new NodeFixtureRow('visible', '', 'Vendor.Site:Document.Page', [], []), new NodeFixtureRow('hidden', 'visible', 'Vendor.Site:Document.Page', [], [], true)],
            [new ReferenceFixtureRow('visible', 'r', ['hidden'], []), new ReferenceFixtureRow('visible', 'r', ['visible'], [], ['label' => 'x'])]
        )));

        self::assertArrayNotHasKey('hidden', $yaml['nodes'][0]);
        self::assertTrue($yaml['nodes'][1]['hidden']);
        self::assertArrayNotHasKey('properties', $yaml['references'][0]);
        self::assertSame(['label' => 'x'], $yaml['references'][1]['properties']);
        self::assertSame(['hidden'], $yaml['references'][0]['targets'], 'targets stay a list');
    }

    #[Test]
    public function dimensionSpacePointIsAlwaysDumped(): void
    {
        // the export stays verbose: an exported fixture must not depend on a default dimension space point
        $yaml = Yaml::parse(NodeFixtureYaml::dump(new NodeFixture(
            [new NodeFixtureRow('a', '', 'Vendor.Site:Content.Text', [], [])],
            [new ReferenceFixtureRow('a', 'r', ['a'], [])]
        )));

        self::assertArrayHasKey('dimensionSpacePoint', $yaml['nodes'][0]);
        self::assertArrayHasKey('dimensionSpacePoint', $yaml['references'][0]);
    }

    #[Test]
    public function referencesAreOmittedWhenThereAreNone(): void
    {
        self::assertArrayNotHasKey('references', Yaml::parse(NodeFixtureYaml::dump(new NodeFixture([new NodeFixtureRow('home', '', 'Vendor.Site:Document.Page', [], [])]))));
    }

    // ---------------------------------------------------------------- dump -> parse (the export/import contract)

    #[Test]
    #[DataProvider('propertyValues')]
    public function exportedFixtureImportsUnchanged(array $properties): void
    {
        $fixture = new NodeFixture(
            [
                new NodeFixtureRow('home', '', 'Vendor.Site:Document.Page', ['uriPathSegment' => 'site', 'title' => 'Home'], ['language' => 'de', 'market' => 'eu']),
                new NodeFixtureRow('section', 'home/main', 'Vendor.Site:Content.Section', [], ['language' => 'de', 'market' => 'eu'], true),
                new NodeFixtureRow('text', 'section', 'Vendor.Site:Content.Text', $properties, ['language' => 'de', 'market' => 'eu']),
            ],
            [
                new ReferenceFixtureRow('home', 'teasers', ['text', 'section'], ['language' => 'de', 'market' => 'eu']),
                new ReferenceFixtureRow('text', 'link', ['home'], [], ['label' => 'Read more', 'weight' => 2]),
            ]
        );

        self::assertEquals($fixture, NodeFixtureYaml::parseFile($this->temporaryYamlFile(NodeFixtureYaml::dump($fixture))));
    }

    /**
     * Values YAML could misread when not quoted correctly.
     */
    public static function propertyValues(): iterable
    {
        yield 'yaml booleans as strings' => [['a' => 'yes', 'b' => 'no', 'c' => 'on', 'd' => 'true']];
        yield 'yaml null as string' => [['a' => 'null', 'b' => '~']];
        yield 'numeric strings' => [['a' => '0123', 'b' => '1e3', 'c' => '42']];
        yield 'real scalars' => [['a' => true, 'b' => 0, 'c' => 1.5, 'd' => null]];
        yield 'multiline html' => [['text' => "<p>first</p>\n<p>second: with colon</p>\n"]];
        yield 'special characters' => [['text' => "Ümläut — #hash 'single' \"double\" a|b \\backslash\\ {braces} [brackets] &amp; *star"]];
        yield 'leading and trailing whitespace' => [['text' => '  padded  ']];
        yield 'asset reference' => [['image' => ['__flow_object_type' => 'Neos\\Media\\Domain\\Model\\Image', '__identifier' => '3a28c97c-58f1-45c5-b1ad-2f491c904467']]];
        yield 'nested arrays' => [['list' => ['a', 'b'], 'map' => ['x' => ['y' => 'z']], 'empty' => []]];
        yield 'empty string' => [['text' => '']];
    }

    #[Test]
    public function emptyExportIsStillAValidFixture(): void
    {
        self::assertEquals(new NodeFixture([]), NodeFixtureYaml::parseFile($this->temporaryYamlFile(NodeFixtureYaml::dump(new NodeFixture([])))));
    }

    // ---------------------------------------------------------------- reading nodes

    #[Test]
    public function optionalFieldsDefaultToSiteParentNoPropertiesNoDimensionVisible(): void
    {
        self::assertEquals(
            new NodeFixtureRow('homepage', '', 'Vendor.Site:Document.Page', [], [], false),
            $this->singleNode(['nodeAggregateId' => 'homepage', 'nodeType' => 'Vendor.Site:Document.Page'])
        );
    }

    #[Test]
    public function nullParentMeansTheSiteNode(): void
    {
        self::assertSame('', $this->singleNode(['nodeAggregateId' => 'homepage', 'parent' => null, 'nodeType' => 'Vendor.Site:Document.Page'])->parent);
    }

    #[Test]
    public function numericIdsFromYamlBecomeStrings(): void
    {
        // YAML parses unquoted `nodeAggregateId: 123` as int - node aggregate ids are strings
        $node = $this->singleNode(['nodeAggregateId' => 123, 'parent' => 45, 'nodeType' => 'Vendor.Site:Content.Text']);

        self::assertSame(['123', '45'], [$node->nodeAggregateId, $node->parent]);
    }

    #[Test]
    public function nodesKeepTheOrderOfTheFile(): void
    {
        $fixture = NodeFixtureYaml::fromArray(['nodes' => [
            ['nodeAggregateId' => 'parent', 'nodeType' => 'Vendor.Site:Document.Page'],
            ['nodeAggregateId' => 'child', 'parent' => 'parent', 'nodeType' => 'Vendor.Site:Document.Page'],
        ]]);

        self::assertSame(['parent', 'child'], array_map(fn (NodeFixtureRow $node) => $node->nodeAggregateId, $fixture->nodes));
    }

    #[Test]
    #[DataProvider('invalidNodeEntries')]
    public function invalidNodeEntriesFailWithTheirPosition(mixed $node, string $expectedMessagePattern): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        NodeFixtureYaml::fromArray(['nodes' => [
            ['nodeAggregateId' => 'ok', 'nodeType' => 'Vendor.Site:Document.Page'],
            $node,
        ]]);
    }

    public static function invalidNodeEntries(): iterable
    {
        yield 'missing nodeAggregateId' => [['nodeType' => 'Vendor.Site:Content.Text'], '/#1.*nodeAggregateId/'];
        yield 'missing nodeType' => [['nodeAggregateId' => 'x'], '/#1.*nodeType/'];
        yield 'empty nodeAggregateId' => [['nodeAggregateId' => '', 'nodeType' => 'Vendor.Site:Content.Text'], '/#1.*nodeAggregateId/'];
        yield 'properties is a list' => [['nodeAggregateId' => 'x', 'nodeType' => 'Vendor.Site:Content.Text', 'properties' => ['a', 'b']], '/#1.*properties/'];
        yield 'properties is a scalar' => [['nodeAggregateId' => 'x', 'nodeType' => 'Vendor.Site:Content.Text', 'properties' => 'title'], '/#1.*properties/'];
        yield 'dimensionSpacePoint is a scalar' => [['nodeAggregateId' => 'x', 'nodeType' => 'Vendor.Site:Content.Text', 'dimensionSpacePoint' => 'de'], '/#1.*dimensionSpacePoint/'];
        // `hidden: "no"` must not end up hidden just because the string is non-empty
        yield 'hidden is a string' => [['nodeAggregateId' => 'x', 'nodeType' => 'Vendor.Site:Content.Text', 'hidden' => 'no'], '/#1.*hidden/'];
        yield 'entry is a list' => [['x'], '/#1/'];
        yield 'entry is a scalar' => ['x', '/#1/'];
    }

    #[Test]
    public function oldNeos8ExportFormatIsRejected(): void
    {
        // Neos 8 exports: nodes keyed by identifier with path/type/children - must not be half-imported
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/format/i');

        NodeFixtureYaml::fromArray(['nodes' => [
            '5cb3a5f7-b501-40b2-b5a8-9de169ef1105' => ['path' => '/sites/site', 'type' => 'Vendor.Site:Document.Page', 'properties' => [], 'children' => []],
        ]]);
    }

    // ---------------------------------------------------------------- reading references

    #[Test]
    public function missingOrEmptyReferencesAreNoReferences(): void
    {
        self::assertSame([], NodeFixtureYaml::fromArray(['nodes' => []])->references);
        self::assertSame([], NodeFixtureYaml::fromArray(['nodes' => [], 'references' => []])->references);
        self::assertSame([], NodeFixtureYaml::fromArray(['nodes' => [], 'references' => null])->references);
    }

    #[Test]
    public function referenceTargetsCanBeAListOrASingleId(): void
    {
        $fixture = NodeFixtureYaml::fromArray(['nodes' => [], 'references' => [
            ['nodeAggregateId' => 'teaser', 'referenceName' => 'targets', 'targets' => ['a', 'b'], 'dimensionSpacePoint' => ['language' => 'de']],
            ['nodeAggregateId' => 'home', 'referenceName' => 'privacyPage', 'targets' => 'privacy'],
        ]]);

        self::assertEquals([
            new ReferenceFixtureRow('teaser', 'targets', ['a', 'b'], ['language' => 'de']),
            new ReferenceFixtureRow('home', 'privacyPage', ['privacy'], []),
        ], $fixture->references);
    }

    #[Test]
    #[DataProvider('invalidReferenceEntries')]
    public function invalidReferenceEntriesFailWithTheirPosition(array $reference, string $expectedMessagePattern): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        NodeFixtureYaml::fromArray(['nodes' => [], 'references' => [
            ['nodeAggregateId' => 'ok', 'referenceName' => 'r', 'targets' => ['t']],
            $reference,
        ]]);
    }

    public static function invalidReferenceEntries(): iterable
    {
        yield 'missing nodeAggregateId' => [['referenceName' => 'r', 'targets' => ['t']], '/#1.*nodeAggregateId/'];
        yield 'missing referenceName' => [['nodeAggregateId' => 'n', 'targets' => ['t']], '/#1.*referenceName/'];
        yield 'missing targets' => [['nodeAggregateId' => 'n', 'referenceName' => 'r'], '/#1.*targets/'];
        // an empty target would later fail with an unrelated "invalid NodeAggregateId ''" error
        yield 'empty targets list' => [['nodeAggregateId' => 'n', 'referenceName' => 'r', 'targets' => []], '/#1.*targets/'];
        yield 'empty target id' => [['nodeAggregateId' => 'n', 'referenceName' => 'r', 'targets' => ['a', '']], '/#1.*targets/'];
        yield 'properties is a list' => [['nodeAggregateId' => 'n', 'referenceName' => 'r', 'targets' => ['t'], 'properties' => ['a']], '/#1.*properties/'];
    }

    // ---------------------------------------------------------------- parseFile

    #[Test]
    public function validFileIsParsed(): void
    {
        $file = $this->temporaryYamlFile("nodes:\n  - nodeAggregateId: homepage\n    nodeType: 'Vendor.Site:Document.Page'\n");

        self::assertEquals(new NodeFixture([new NodeFixtureRow('homepage', '', 'Vendor.Site:Document.Page', [], [])]), NodeFixtureYaml::parseFile($file));
    }

    #[Test]
    public function missingFileFailsWithNotFoundMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found.*does-not-exist\.yaml/s');

        NodeFixtureYaml::parseFile(sys_get_temp_dir() . '/does-not-exist.yaml');
    }

    #[Test]
    public function invalidYamlFailsWithParseErrorNotWithNotFound(): void
    {
        $file = $this->temporaryYamlFile("nodes:\n  - nodeAggregateId: [unclosed\n");

        try {
            NodeFixtureYaml::parseFile($file);
            self::fail('Expected an exception');
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsStringIgnoringCase('not found', $exception->getMessage());
            self::assertStringContainsString($file, $exception->getMessage());
        }
    }

    #[Test]
    #[DataProvider('invalidStructures')]
    public function invalidStructureFails(string $yaml): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/nodes/');

        NodeFixtureYaml::parseFile($this->temporaryYamlFile($yaml));
    }

    public static function invalidStructures(): iterable
    {
        yield 'empty file' => [''];
        yield 'scalar document' => ["just text\n"];
        yield 'no nodes key' => ["references: []\n"];
        yield 'nodes is a scalar' => ["nodes: homepage\n"];
        yield 'nodes is null' => ["nodes: ~\n"];
    }

    // ---------------------------------------------------------------- helpers

    private function singleNode(array $node): NodeFixtureRow
    {
        return NodeFixtureYaml::fromArray(['nodes' => [$node]])->nodes[0];
    }

    private function temporaryYamlFile(string $content): string
    {
        $base = tempnam(sys_get_temp_dir(), 'e2e-fixture-');
        $file = $base . '.yaml';
        file_put_contents($file, $content);
        array_push($this->temporaryFiles, $base, $file);
        return $file;
    }
}
