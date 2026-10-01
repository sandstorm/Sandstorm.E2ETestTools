<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Service;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Service\NodeImportService;

/**
 * YAML node fixtures -> the tables "I have the following nodes in site ..." / "the following node references:" read.
 */
class NodeImportServiceTest extends UnitTestCase
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

    // ---------------------------------------------------------------- node table

    #[Test]
    public function nodeTableHasTheColumnsOfTheNodeCreationStep(): void
    {
        $table = NodeImportService::createTableNodeFromYamlArray(['nodes' => []]);

        self::assertSame([['NodeAggregateId', 'Parent', 'NodeType', 'Properties', 'DimensionSpacePoint', 'Hidden']], $table->getRows());
    }

    #[Test]
    public function fullNodeEntryIsConvertedToOneRow(): void
    {
        $table = NodeImportService::createTableNodeFromYamlArray(['nodes' => [[
            'nodeAggregateId' => 'headline',
            'parent' => 'homepage/main',
            'nodeType' => 'Vendor.Site:Content.Headline',
            'properties' => ['title' => 'Hello'],
            'dimensionSpacePoint' => ['language' => 'de'],
        ]]]);

        self::assertSame([[
            'NodeAggregateId' => 'headline',
            'Parent' => 'homepage/main',
            'NodeType' => 'Vendor.Site:Content.Headline',
            'Properties' => '{"title":"Hello"}',
            'DimensionSpacePoint' => '{"language":"de"}',
            'Hidden' => '',
        ]], $table->getHash());
    }

    #[Test]
    public function hiddenNodesAreMarkedInTheHiddenColumn(): void
    {
        self::assertSame('true', $this->singleRow(['nodeAggregateId' => 'a', 'nodeType' => 'Vendor.Site:Content.Text', 'hidden' => true])['Hidden']);
        self::assertSame('', $this->singleRow(['nodeAggregateId' => 'a', 'nodeType' => 'Vendor.Site:Content.Text', 'hidden' => false])['Hidden']);
    }

    #[Test]
    public function hiddenMustBeABoolean(): void
    {
        // `hidden: "no"` must not end up hidden just because the string is non-empty
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/#0.*hidden/');

        $this->singleRow(['nodeAggregateId' => 'a', 'nodeType' => 'Vendor.Site:Content.Text', 'hidden' => 'no']);
    }

    #[Test]
    public function optionalFieldsDefaultToSiteParentEmptyPropertiesAndNoDimension(): void
    {
        $row = $this->singleRow(['nodeAggregateId' => 'homepage', 'nodeType' => 'Vendor.Site:Document.Page']);

        self::assertSame('', $row['Parent']);
        self::assertSame('{}', $row['Properties']);
        self::assertSame('', $row['DimensionSpacePoint']);
    }

    #[Test]
    public function nullParentMeansTheSiteNode(): void
    {
        self::assertSame('', $this->singleRow(['nodeAggregateId' => 'homepage', 'parent' => null, 'nodeType' => 'Vendor.Site:Document.Page'])['Parent']);
    }

    #[Test]
    public function emptyPropertiesAndDimensionAreTreatedLikeMissingOnes(): void
    {
        $row = $this->singleRow(['nodeAggregateId' => 'homepage', 'nodeType' => 'Vendor.Site:Document.Page', 'properties' => [], 'dimensionSpacePoint' => []]);

        self::assertSame('{}', $row['Properties']);
        self::assertSame('', $row['DimensionSpacePoint']);
    }

    #[Test]
    public function numericIdsFromYamlBecomeStrings(): void
    {
        // YAML parses unquoted `nodeAggregateId: 123` as int - node aggregate ids are strings
        $row = $this->singleRow(['nodeAggregateId' => 123, 'parent' => 45, 'nodeType' => 'Vendor.Site:Content.Text']);

        self::assertSame('123', $row['NodeAggregateId']);
        self::assertSame('45', $row['Parent']);
    }

    #[Test]
    public function rowsKeepTheOrderOfTheFile(): void
    {
        $table = NodeImportService::createTableNodeFromYamlArray(['nodes' => [
            ['nodeAggregateId' => 'parent', 'nodeType' => 'Vendor.Site:Document.Page'],
            ['nodeAggregateId' => 'child', 'parent' => 'parent', 'nodeType' => 'Vendor.Site:Document.Page'],
        ]]);

        self::assertSame(['parent', 'child'], array_column($table->getHash(), 'NodeAggregateId'));
    }

    #[Test]
    public function propertyValuesKeepTheirTypesAndSpecialCharacters(): void
    {
        $properties = [
            'title' => 'Ümläut — "quoted" <h1>a|b</h1>',
            'className' => 'Neos\\Media\\Domain\\Model\\Image',
            'text' => "two\nlines",
            'enabled' => false,
            'count' => 0,
            'ratio' => 1.5,
            'empty' => null,
            'asset' => ['__flow_object_type' => 'Neos\\Media\\Domain\\Model\\Document', '__identifier' => 'abc'],
            'list' => ['a', 'b'],
        ];
        $row = $this->singleRow(['nodeAggregateId' => 'n', 'nodeType' => 'Vendor.Site:Content.Text', 'properties' => $properties]);

        self::assertSame($properties, json_decode($row['Properties'], true, flags: JSON_THROW_ON_ERROR));
    }

    // ---------------------------------------------------------------- overwrites

    #[Test]
    public function overwritesReplaceAndAddPropertiesOfTheMatchingNodeOnly(): void
    {
        $table = NodeImportService::createTableNodeFromYamlArray(
            ['nodes' => [
                ['nodeAggregateId' => 'a', 'nodeType' => 'Vendor.Site:Content.Text', 'properties' => ['title' => 'A', 'text' => 'keep']],
                ['nodeAggregateId' => 'b', 'nodeType' => 'Vendor.Site:Content.Text', 'properties' => ['title' => 'B']],
            ]],
            ['a' => ['title' => 'overwritten', 'added' => 'new']]
        );

        $rows = $table->getHash();
        self::assertSame(['title' => 'overwritten', 'text' => 'keep', 'added' => 'new'], json_decode($rows[0]['Properties'], true));
        self::assertSame(['title' => 'B'], json_decode($rows[1]['Properties'], true));
    }

    #[Test]
    public function overwritesForNodesWithoutPropertiesWork(): void
    {
        $row = NodeImportService::createTableNodeFromYamlArray(
            ['nodes' => [['nodeAggregateId' => 'a', 'nodeType' => 'Vendor.Site:Content.Text']]],
            ['a' => ['title' => 'set']]
        )->getHash()[0];

        self::assertSame(['title' => 'set'], json_decode($row['Properties'], true));
    }

    #[Test]
    public function overwritesForUnknownNodesFail(): void
    {
        // a typo in the overwrite table's nodeAggregateId would otherwise silently overwrite nothing
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/typo-id/');

        NodeImportService::createTableNodeFromYamlArray(
            ['nodes' => [['nodeAggregateId' => 'a', 'nodeType' => 'Vendor.Site:Content.Text']]],
            ['typo-id' => ['title' => 'x']]
        );
    }

    /**
     * Overwrite values come from a Gherkin table, i.e. always as strings. Valid JSON values are decoded, so boolean,
     * number, null, list and object properties can be overwritten too; everything else stays the literal text.
     */
    #[Test]
    #[DataProvider('overwriteValues')]
    public function overwriteValuesAreDecodedWhenTheyAreJson(string $tableValue, mixed $expectedValue): void
    {
        $row = NodeImportService::createTableNodeFromYamlArray(
            ['nodes' => [['nodeAggregateId' => 'a', 'nodeType' => 'Vendor.Site:Content.Text']]],
            ['a' => ['value' => $tableValue]]
        )->getHash()[0];

        self::assertSame(['value' => $expectedValue], json_decode($row['Properties'], true));
    }

    public static function overwriteValues(): iterable
    {
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
        yield 'integer' => ['42', 42];
        yield 'float' => ['1.5', 1.5];
        yield 'null' => ['null', null];
        yield 'json string keeps a number as text' => ['"42"', '42'];
        yield 'list' => ['["a","b"]', ['a', 'b']];
        yield 'object' => ['{"__flow_object_type":"Neos\\\\Media\\\\Domain\\\\Model\\\\Image","__identifier":"abc"}', ['__flow_object_type' => 'Neos\\Media\\Domain\\Model\\Image', '__identifier' => 'abc']];
        yield 'text' => ['Hello world', 'Hello world'];
        yield 'html' => ['<h1>Overwritten</h1>', '<h1>Overwritten</h1>'];
        yield 'text with colon stays text' => ['Note: important', 'Note: important'];
        yield 'text starting with hash stays text' => ['# not a comment', '# not a comment'];
        yield 'number with leading zero stays text' => ['0123', '0123'];
        yield 'capitalized True stays text' => ['True', 'True'];
        yield 'empty string' => ['', ''];
    }

    // ---------------------------------------------------------------- invalid node entries

    #[Test]
    #[DataProvider('invalidNodeEntries')]
    public function invalidNodeEntriesFailWithTheirPosition(array $node, string $expectedMessagePattern): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        NodeImportService::createTableNodeFromYamlArray(['nodes' => [
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
        yield 'entry is not a map' => [['x'], '/#1/'];
    }

    #[Test]
    public function oldNeos8ExportFormatIsRejected(): void
    {
        // Neos 8 exports: nodes keyed by identifier with path/type/children - must not be half-imported
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/format/i');

        NodeImportService::createTableNodeFromYamlArray(['nodes' => [
            '5cb3a5f7-b501-40b2-b5a8-9de169ef1105' => ['path' => '/sites/site', 'type' => 'Vendor.Site:Document.Page', 'properties' => [], 'children' => []],
        ]]);
    }

    // ---------------------------------------------------------------- references

    #[Test]
    public function missingOrEmptyReferencesResultInNoReferenceTable(): void
    {
        self::assertNull(NodeImportService::createReferencesTableNodeFromYamlArray(['nodes' => []]));
        self::assertNull(NodeImportService::createReferencesTableNodeFromYamlArray(['nodes' => [], 'references' => []]));
        self::assertNull(NodeImportService::createReferencesTableNodeFromYamlArray(['nodes' => [], 'references' => null]));
    }

    #[Test]
    public function referencesAreConvertedToTheReferenceStepTable(): void
    {
        $table = NodeImportService::createReferencesTableNodeFromYamlArray(['nodes' => [], 'references' => [
            ['nodeAggregateId' => 'teaser', 'referenceName' => 'targets', 'targets' => ['a', 'b'], 'dimensionSpacePoint' => ['language' => 'de']],
            ['nodeAggregateId' => 'home', 'referenceName' => 'privacyPage', 'targets' => 'privacy'],
        ]]);

        self::assertNotNull($table);
        self::assertSame([
            ['NodeAggregateId' => 'teaser', 'ReferenceName' => 'targets', 'Targets' => 'a,b', 'DimensionSpacePoint' => '{"language":"de"}', 'Properties' => ''],
            ['NodeAggregateId' => 'home', 'ReferenceName' => 'privacyPage', 'Targets' => 'privacy', 'DimensionSpacePoint' => '', 'Properties' => ''],
        ], $table->getHash());
    }

    #[Test]
    public function referencePropertiesAreWrittenAsJson(): void
    {
        $table = NodeImportService::createReferencesTableNodeFromYamlArray(['nodes' => [], 'references' => [
            ['nodeAggregateId' => 'teaser', 'referenceName' => 'targets', 'targets' => ['a'], 'properties' => ['label' => 'Read more', 'weight' => 2]],
        ]]);

        self::assertNotNull($table);
        self::assertSame(['label' => 'Read more', 'weight' => 2], json_decode($table->getHash()[0]['Properties'], true));
    }

    #[Test]
    #[DataProvider('invalidReferenceEntries')]
    public function invalidReferenceEntriesFailWithTheirPosition(array $reference, string $expectedMessagePattern): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        NodeImportService::createReferencesTableNodeFromYamlArray(['nodes' => [], 'references' => [
            ['nodeAggregateId' => 'ok', 'referenceName' => 'r', 'targets' => ['t']],
            $reference,
        ]]);
    }

    public static function invalidReferenceEntries(): iterable
    {
        yield 'missing nodeAggregateId' => [['referenceName' => 'r', 'targets' => ['t']], '/#1.*nodeAggregateId/'];
        yield 'missing referenceName' => [['nodeAggregateId' => 'n', 'targets' => ['t']], '/#1.*referenceName/'];
        yield 'missing targets' => [['nodeAggregateId' => 'n', 'referenceName' => 'r'], '/#1.*targets/'];
        // an empty Targets cell would later fail with an unrelated "invalid NodeAggregateId ''" error
        yield 'empty targets list' => [['nodeAggregateId' => 'n', 'referenceName' => 'r', 'targets' => []], '/#1.*targets/'];
        yield 'empty target id' => [['nodeAggregateId' => 'n', 'referenceName' => 'r', 'targets' => ['a', '']], '/#1.*targets/'];
        yield 'properties is a list' => [['nodeAggregateId' => 'n', 'referenceName' => 'r', 'targets' => ['t'], 'properties' => ['a']], '/#1.*properties/'];
    }

    // ---------------------------------------------------------------- parseYamlFile

    #[Test]
    public function validFileIsParsed(): void
    {
        $file = $this->temporaryYamlFile("nodes:\n  - nodeAggregateId: homepage\n    nodeType: 'Vendor.Site:Document.Page'\n");

        self::assertSame(['nodes' => [['nodeAggregateId' => 'homepage', 'nodeType' => 'Vendor.Site:Document.Page']]], NodeImportService::parseYamlFile($file));
    }

    #[Test]
    public function fileWithEmptyNodeListIsValid(): void
    {
        self::assertSame(['nodes' => []], NodeImportService::parseYamlFile($this->temporaryYamlFile("nodes: []\n")));
    }

    #[Test]
    public function missingFileFailsWithNotFoundMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found.*does-not-exist\.yaml/s');

        NodeImportService::parseYamlFile(sys_get_temp_dir() . '/does-not-exist.yaml');
    }

    #[Test]
    public function invalidYamlFailsWithParseErrorNotWithNotFound(): void
    {
        $file = $this->temporaryYamlFile("nodes:\n  - nodeAggregateId: [unclosed\n");

        try {
            NodeImportService::parseYamlFile($file);
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

        NodeImportService::parseYamlFile($this->temporaryYamlFile($yaml));
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

    private function singleRow(array $node): array
    {
        return NodeImportService::createTableNodeFromYamlArray(['nodes' => [$node]])->getHash()[0];
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
