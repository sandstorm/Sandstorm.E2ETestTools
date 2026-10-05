<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Fixture;

use Behat\Gherkin\GherkinCompatibilityMode;
use Behat\Gherkin\Keywords\CachedArrayKeywords;
use Behat\Gherkin\Lexer;
use Behat\Gherkin\Node\TableNode;
use Behat\Gherkin\Parser;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Fixture\NodeFixture;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureGherkin;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureRow;
use Sandstorm\E2ETestTools\Fixture\ReferenceFixtureRow;

/**
 * The Gherkin tables printed by the CLI export / StepGenerator and read by the node and reference steps.
 */
class NodeFixtureGherkinTest extends UnitTestCase
{
    // ---------------------------------------------------------------- printing

    #[Test]
    public function nodeTableHasTheColumnsOfTheNodeCreationStep(): void
    {
        $table = NodeFixtureGherkin::nodeTable([new NodeFixtureRow('headline', 'homepage/main', 'Vendor.Site:Content.Headline', ['title' => 'Hi'], ['language' => 'de'])]);

        self::assertSame(
            [['NodeAggregateId', 'Parent', 'NodeType', 'Properties', 'DimensionSpacePoint'], ['headline', 'homepage/main', 'Vendor.Site:Content.Headline', '{"title":"Hi"}', '{"language":"de"}']],
            $this->parseTable($table->toString())->getRows()
        );
    }

    #[Test]
    public function emptyPropertiesAreAnEmptyJsonObjectAndEmptyDimensionAnEmptyCell(): void
    {
        $row = $this->parseTable(NodeFixtureGherkin::nodeTable([new NodeFixtureRow('home', '', 'Vendor.Site:Document.Page', [], [])])->toString())->getHash()[0];

        self::assertSame(['NodeAggregateId' => 'home', 'Parent' => '', 'NodeType' => 'Vendor.Site:Document.Page', 'Properties' => '{}', 'DimensionSpacePoint' => ''], $row);
    }

    #[Test]
    public function propertiesJsonIsReadable(): void
    {
        $table = NodeFixtureGherkin::nodeTable([new NodeFixtureRow('n', 'p', 'Vendor.Site:Content.Text', ['title' => 'Ümläut <a href="/x/y">link</a>'], [])]);

        // no JSON-escaped slashes/unicode (the double quotes are JSON-escaped, and the backslash again by Gherkin)
        self::assertStringContainsString('Ümläut <a href=\\\\"/x/y\\\\">', $table->toString());
    }

    #[Test]
    public function nodeTableWithoutHiddenNodesHasNoHiddenColumn(): void
    {
        $table = NodeFixtureGherkin::nodeTable([new NodeFixtureRow('home', '', 'Vendor.Site:Document.Page', [], [])]);

        self::assertStringNotContainsString('Hidden', $table->toString());
    }

    #[Test]
    public function nodeTableGetsTheHiddenColumnWhenOneNodeIsHidden(): void
    {
        $table = NodeFixtureGherkin::nodeTable([
            new NodeFixtureRow('visible', '', 'Vendor.Site:Document.Page', [], []),
            new NodeFixtureRow('hidden', 'visible', 'Vendor.Site:Document.Page', [], [], true),
        ]);

        self::assertSame(['', 'true'], array_column($this->parseTable($table->toString())->getHash(), 'Hidden'));
    }

    #[Test]
    public function referenceTableJoinsTargets(): void
    {
        $table = NodeFixtureGherkin::referenceTable([new ReferenceFixtureRow('teaser', 'targets', ['a', 'b', 'c'], ['language' => 'de'])]);

        self::assertSame(
            [['NodeAggregateId' => 'teaser', 'ReferenceName' => 'targets', 'Targets' => 'a, b, c', 'DimensionSpacePoint' => '{"language":"de"}']],
            $this->parseTable($table->toString())->getHash()
        );
    }

    #[Test]
    public function referenceTableWithoutPropertiesHasNoPropertiesColumn(): void
    {
        self::assertStringNotContainsString('Properties', NodeFixtureGherkin::referenceTable([new ReferenceFixtureRow('a', 'r', ['b'], [])])->toString());
    }

    #[Test]
    public function referenceTableGetsThePropertiesColumnWhenOneReferenceHasProperties(): void
    {
        $table = NodeFixtureGherkin::referenceTable([
            new ReferenceFixtureRow('a', 'r', ['b'], []),
            new ReferenceFixtureRow('a', 'r', ['c'], [], ['label' => 'Ümläut/x']),
        ]);

        self::assertSame(['', '{"label":"Ümläut/x"}'], array_column($this->parseTable($table->toString())->getHash(), 'Properties'));
    }

    #[Test]
    public function stepsOmitTheReferenceStepWithoutReferences(): void
    {
        $steps = NodeFixtureGherkin::steps(new NodeFixture([new NodeFixtureRow('home', '', 'Vendor.Site:Document.Page', [], [])]), 'site');

        self::assertStringStartsWith('Given I have the following nodes in site "site":' . "\n", $steps);
        self::assertStringNotContainsString('references', $steps);
    }

    // ---------------------------------------------------------------- printed steps -> rows (the export/import contract)

    /**
     * What the CLI export / StepGenerator print must create exactly the exported nodes when pasted into a feature.
     */
    #[Test]
    #[DataProvider('propertyValues')]
    public function printedStepsParseBackToTheSameFixture(array $properties): void
    {
        $fixture = new NodeFixture(
            [
                new NodeFixtureRow('home', '', 'Vendor.Site:Document.Page', ['uriPathSegment' => 'site'], ['language' => 'de', 'market' => 'eu']),
                new NodeFixtureRow('text', 'home/main', 'Vendor.Site:Content.Text', $properties, ['language' => 'de', 'market' => 'eu'], true),
            ],
            [
                new ReferenceFixtureRow('home', 'teasers', ['text', 'home'], ['language' => 'de', 'market' => 'eu']),
                new ReferenceFixtureRow('text', 'link', ['home'], [], ['label' => 'Read more', 'weight' => 2]),
            ]
        );

        $steps = $this->parseSteps(NodeFixtureGherkin::steps($fixture, 'site'));

        self::assertEquals($fixture->nodes, NodeFixtureGherkin::nodesFromTable($steps[0]));
        self::assertEquals($fixture->references, NodeFixtureGherkin::referencesFromTable($steps[1]));
    }

    public static function propertyValues(): iterable
    {
        yield 'scalars' => [['enabled' => false, 'count' => 0, 'ratio' => 1.5, 'empty' => null]];
        yield 'special characters' => [['title' => 'Ümläut — "quoted" <h1>a|b</h1> #hash \'single\' {braces}']];
        yield 'backslashes (php class names)' => [['image' => ['__flow_object_type' => 'Neos\\Media\\Domain\\Model\\Image', '__identifier' => 'abc'], 'path' => 'C:\\dir\\']];
        yield 'nested arrays' => [['list' => ['a', 'b'], 'map' => ['x' => ['y' => 'z']], 'emptyList' => []]];
        yield 'json-encoded newline' => [['text' => "line 1\nline 2"]];
        yield 'padded string inside json' => [['text' => '  padded  ']];
    }

    // ---------------------------------------------------------------- reading tables

    #[Test]
    public function missingOptionalCellsDefaultToSiteParentNoPropertiesNoDimensionVisible(): void
    {
        $nodes = NodeFixtureGherkin::nodesFromTable(new TableNode([['NodeAggregateId', 'NodeType'], ['home', 'Vendor.Site:Document.Page']]));

        self::assertEquals([new NodeFixtureRow('home', '', 'Vendor.Site:Document.Page', [], [], false)], $nodes);
    }

    #[Test]
    #[DataProvider('hiddenCells')]
    public function hiddenCellIsTrueFalseOrEmpty(string $cell, bool $expected): void
    {
        self::assertSame($expected, $this->singleNode(['Hidden' => $cell])->hidden);
    }

    public static function hiddenCells(): iterable
    {
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
        yield 'empty' => ['', false];
    }

    #[Test]
    #[DataProvider('invalidNodeCells')]
    public function invalidNodeCellsFailNamingTheNode(array $cells, string $expectedMessagePattern): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        $this->singleNode($cells);
    }

    public static function invalidNodeCells(): iterable
    {
        // "yes"/"1" must not silently mean visible - nor hidden
        yield 'hidden yes' => [['Hidden' => 'yes'], '/Hidden.*"n"/'];
        // invalid JSON must not silently drop all properties
        yield 'invalid properties json' => [['Properties' => '{"title": "unclosed}'], '/JSON.*Properties.*"n"/'];
        // a single backslash (forgotten Gherkin escaping) is invalid JSON
        yield 'unescaped backslash' => [['Properties' => '{"class":"Neos\Media"}'], '/JSON.*Properties/'];
        yield 'properties not an object' => [['Properties' => '["a"]'], '/Properties.*object/'];
        yield 'properties scalar' => [['Properties' => '"title"'], '/Properties.*object/'];
        yield 'invalid dimension json' => [['DimensionSpacePoint' => 'de'], '/JSON.*DimensionSpacePoint/'];
        yield 'missing node type' => [['NodeType' => ''], '/NodeType.*"n"/'];
        yield 'missing node aggregate id' => [['NodeAggregateId' => ''], '/NodeAggregateId/'];
    }

    #[Test]
    public function dimensionSpacePointParameterIsAJsonObject(): void
    {
        self::assertSame(['language' => 'de'], NodeFixtureGherkin::dimensionSpacePoint('{"language":"de"}', 'the default'));
        self::assertSame([], NodeFixtureGherkin::dimensionSpacePoint('{}', 'the default'));
    }

    #[Test]
    #[DataProvider('invalidDimensionSpacePointParameters')]
    public function invalidDimensionSpacePointParameterFails(string $json, string $expectedMessagePattern): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        NodeFixtureGherkin::dimensionSpacePoint($json, 'the default dimension space point');
    }

    public static function invalidDimensionSpacePointParameters(): iterable
    {
        yield 'empty' => ['', '/Empty DimensionSpacePoint.*the default dimension space point/'];
        yield 'invalid json' => ['{language: de}', '/Invalid JSON.*the default dimension space point/'];
        yield 'list' => ['["de"]', '/must be a JSON object/'];
    }

    #[Test]
    public function referenceTargetsAreTrimmed(): void
    {
        $references = NodeFixtureGherkin::referencesFromTable(new TableNode([['NodeAggregateId', 'ReferenceName', 'Targets'], ['a', 'r', 'b ,c,  d']]));

        self::assertSame(['b', 'c', 'd'], $references[0]->targets);
    }

    #[Test]
    #[DataProvider('invalidReferenceCells')]
    public function invalidReferenceCellsFail(array $cells, string $expectedMessagePattern): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        $row = [...['NodeAggregateId' => 'a', 'ReferenceName' => 'r', 'Targets' => 'b', 'DimensionSpacePoint' => '', 'Properties' => ''], ...$cells];
        NodeFixtureGherkin::referencesFromTable(new TableNode([array_keys($row), array_values($row)]));
    }

    public static function invalidReferenceCells(): iterable
    {
        // would later fail with an unrelated "invalid NodeAggregateId ''" error
        yield 'empty targets' => [['Targets' => ''], '/Targets.*"r".*"a"/'];
        yield 'empty target in list' => [['Targets' => 'b,,c'], '/Targets/'];
        yield 'invalid properties json' => [['Properties' => '{'], '/JSON.*Properties/'];
        yield 'missing reference name' => [['ReferenceName' => ''], '/ReferenceName/'];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string,string> $cells overrides of a valid row
     */
    private function singleNode(array $cells): NodeFixtureRow
    {
        $row = [...['NodeAggregateId' => 'n', 'Parent' => 'p', 'NodeType' => 'Vendor.Site:Content.Text', 'Properties' => '{}', 'DimensionSpacePoint' => '', 'Hidden' => ''], ...$cells];
        return NodeFixtureGherkin::nodesFromTable(new TableNode([array_keys($row), array_values($row)]))[0];
    }

    private function parseTable(string $table): TableNode
    {
        return $this->parseSteps("Given a table:\n" . $table)[0];
    }

    /**
     * Parses steps with Behat's real Gherkin parser, as a feature file would be - the only reliable check that
     * escaping/whitespace survive.
     *
     * @return list<TableNode> the table of each step
     */
    private function parseSteps(string $steps): array
    {
        $feature = "Feature: f\n  Scenario: s\n" . preg_replace('/^/m', '    ', $steps);
        $parser = new Parser(new Lexer(CachedArrayKeywords::withDefaultKeywords()), GherkinCompatibilityMode::LEGACY);
        $tables = array_map(fn ($step) => $step->getArguments()[0] ?? null, $parser->parse($feature)?->getScenarios()[0]->getSteps() ?? []);
        foreach ($tables as $table) {
            self::assertInstanceOf(TableNode::class, $table, $feature);
        }
        return $tables;
    }
}
