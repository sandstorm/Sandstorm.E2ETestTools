<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Fixture;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureRow;
use Sandstorm\E2ETestTools\Fixture\ReferenceFixtureRow;

class NodeFixtureRowTest extends UnitTestCase
{
    #[Test]
    public function tableCellsMatchTheNodeCreationStepColumns(): void
    {
        $row = new NodeFixtureRow('headline', 'homepage/main', 'Vendor.Site:Content.Headline', ['title' => 'Hi'], ['language' => 'de']);

        self::assertSame([
            'NodeAggregateId' => 'headline',
            'Parent' => 'homepage/main',
            'NodeType' => 'Vendor.Site:Content.Headline',
            'Properties' => '{"title":"Hi"}',
            'DimensionSpacePoint' => '{"language":"de"}',
        ], $row->toTableCells());
    }

    #[Test]
    public function emptyPropertiesAreAnEmptyJsonObjectAndEmptyDimensionAnEmptyCell(): void
    {
        $cells = (new NodeFixtureRow('home', '', 'Vendor.Site:Document.Page', [], []))->toTableCells();

        self::assertSame('{}', $cells['Properties']);
        self::assertSame('', $cells['DimensionSpacePoint']);
        self::assertSame('', $cells['Parent']);
    }

    #[Test]
    public function propertiesJsonIsReadableAndLossless(): void
    {
        $properties = [
            'title' => 'Ümläut <a href="/x/y">link</a>',
            'asset' => ['__flow_object_type' => 'Neos\\Media\\Domain\\Model\\Image', '__identifier' => 'abc'],
            'text' => "line 1\nline 2",
            'flag' => true,
            'number' => 0,
            'nothing' => null,
            'emptyList' => [],
        ];
        $json = (new NodeFixtureRow('n', 'p', 'Vendor.Site:Content.Text', $properties, []))->toTableCells()['Properties'];

        // readable: no escaped slashes/unicode
        self::assertStringContainsString('Ümläut', $json);
        self::assertStringContainsString('/x/y', $json);
        // never a raw newline - a table cell can't contain one
        self::assertStringNotContainsString("\n", $json);
        self::assertSame($properties, json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function yamlArrayHasTheImportFields(): void
    {
        $row = new NodeFixtureRow('headline', 'section', 'Vendor.Site:Content.Headline', ['title' => 'Hi'], ['language' => 'de']);

        self::assertSame([
            'nodeAggregateId' => 'headline',
            'parent' => 'section',
            'nodeType' => 'Vendor.Site:Content.Headline',
            'properties' => ['title' => 'Hi'],
            'dimensionSpacePoint' => ['language' => 'de'],
        ], $row->toYamlArray());
    }

    #[Test]
    public function visibleNodeHasNoHiddenCellOrField(): void
    {
        $row = new NodeFixtureRow('n', 'p', 'Vendor.Site:Content.Text', [], []);

        self::assertArrayNotHasKey('Hidden', $row->toTableCells());
        self::assertArrayNotHasKey('hidden', $row->toYamlArray());
    }

    #[Test]
    public function hiddenNodeIsMarkedInCellsAndYaml(): void
    {
        $row = new NodeFixtureRow('n', 'p', 'Vendor.Site:Content.Text', [], [], true);

        self::assertSame('true', $row->toTableCells()['Hidden']);
        self::assertTrue($row->toYamlArray()['hidden']);
    }

    #[Test]
    public function referenceTableCellsJoinTargets(): void
    {
        $row = new ReferenceFixtureRow('teaser', 'targets', ['a', 'b', 'c'], ['language' => 'de']);

        self::assertSame([
            'NodeAggregateId' => 'teaser',
            'ReferenceName' => 'targets',
            'Targets' => 'a, b, c',
            'DimensionSpacePoint' => '{"language":"de"}',
        ], $row->toTableCells());
    }

    #[Test]
    public function referenceWithoutDimensionHasEmptyDimensionCell(): void
    {
        self::assertSame('', (new ReferenceFixtureRow('a', 'r', ['b'], []))->toTableCells()['DimensionSpacePoint']);
    }

    #[Test]
    public function referenceWithoutPropertiesHasNoPropertiesCellOrField(): void
    {
        $row = new ReferenceFixtureRow('a', 'r', ['b'], []);

        self::assertArrayNotHasKey('Properties', $row->toTableCells());
        self::assertArrayNotHasKey('properties', $row->toYamlArray());
    }

    #[Test]
    public function referencePropertiesAreInCellsAndYaml(): void
    {
        $row = new ReferenceFixtureRow('a', 'r', ['b'], [], ['label' => 'Ümläut/x']);

        self::assertSame('{"label":"Ümläut/x"}', $row->toTableCells()['Properties']);
        self::assertSame(['label' => 'Ümläut/x'], $row->toYamlArray()['properties']);
    }

    #[Test]
    public function referenceTargetsCanBeLimitedToExportedNodesKeepingEverythingElse(): void
    {
        $row = new ReferenceFixtureRow('a', 'r', ['b', 'outside', 'c'], ['language' => 'de'], ['label' => 'x']);

        $limited = $row->withTargetsLimitedTo(['a', 'b', 'c']);

        self::assertNotNull($limited);
        self::assertSame(['b', 'c'], $limited->targets);
        self::assertSame(['a', 'r', ['language' => 'de'], ['label' => 'x']], [$limited->nodeAggregateId, $limited->referenceName, $limited->dimensionSpacePoint, $limited->properties]);
    }

    #[Test]
    public function referenceWithoutRemainingTargetsIsDropped(): void
    {
        self::assertNull((new ReferenceFixtureRow('a', 'r', ['outside'], []))->withTargetsLimitedTo(['a']));
    }

    #[Test]
    public function referenceYamlArrayKeepsTargetsAsList(): void
    {
        self::assertSame(
            ['nodeAggregateId' => 'a', 'referenceName' => 'r', 'targets' => ['b'], 'dimensionSpacePoint' => []],
            (new ReferenceFixtureRow('a', 'r', ['b'], []))->toYamlArray()
        );
    }
}
