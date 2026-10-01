<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Fixture;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureRow;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureYaml;
use Sandstorm\E2ETestTools\Fixture\ReferenceFixtureRow;
use Sandstorm\E2ETestTools\Service\NodeImportService;
use Symfony\Component\Yaml\Yaml;

/**
 * The export (button, CLI) writes what the import reads - this pins that contract.
 */
class NodeFixtureYamlTest extends UnitTestCase
{
    #[Test]
    public function referencesAreOmittedWhenThereAreNone(): void
    {
        $yaml = Yaml::parse(NodeFixtureYaml::dump([new NodeFixtureRow('home', '', 'Vendor.Site:Document.Page', [], [])]));

        self::assertArrayHasKey('nodes', $yaml);
        self::assertArrayNotHasKey('references', $yaml);
    }

    #[Test]
    public function emptyExportIsStillAValidFixture(): void
    {
        self::assertSame(['nodes' => []], NodeImportService::parseYamlFile($this->writeTemporaryFile(NodeFixtureYaml::dump([]))));
    }

    #[Test]
    #[DataProvider('propertyValues')]
    public function exportedNodesImportToTheSameRows(array $properties): void
    {
        $rows = [
            new NodeFixtureRow('home', '', 'Vendor.Site:Document.Page', ['uriPathSegment' => 'site', 'title' => 'Home'], ['language' => 'de']),
            new NodeFixtureRow('section', 'home/main', 'Vendor.Site:Content.Section', [], ['language' => 'de']),
            new NodeFixtureRow('text', 'section', 'Vendor.Site:Content.Text', $properties, ['language' => 'de']),
        ];

        $imported = NodeImportService::createTableNodeFromYamlArray(Yaml::parse(NodeFixtureYaml::dump($rows)));

        // the import table always has the optional Hidden column - visible rows leave it empty
        self::assertSame(
            array_map(fn (NodeFixtureRow $row) => [...$row->toTableCells(), 'Hidden' => ''], $rows),
            $this->normalizeJsonCells($imported->getHash())
        );
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
    public function exportedReferencesImportToTheSameRows(): void
    {
        $references = [
            new ReferenceFixtureRow('teaser', 'targets', ['a', 'b'], ['language' => 'de']),
            new ReferenceFixtureRow('home', 'privacyPage', ['privacy'], []),
        ];

        $imported = NodeImportService::createReferencesTableNodeFromYamlArray(Yaml::parse(NodeFixtureYaml::dump([], $references)));

        self::assertNotNull($imported);
        self::assertSame(
            [['teaser', 'targets', ['a', 'b'], '{"language":"de"}'], ['home', 'privacyPage', ['privacy'], '']],
            array_map(fn (array $row) => [$row['NodeAggregateId'], $row['ReferenceName'], array_map(trim(...), explode(',', $row['Targets'])), $row['DimensionSpacePoint']], $imported->getHash()),
        );
    }

    #[Test]
    public function hiddenStateSurvivesTheRoundTrip(): void
    {
        $rows = [
            new NodeFixtureRow('visible', 'p', 'Vendor.Site:Content.Text', [], []),
            new NodeFixtureRow('hidden', 'p', 'Vendor.Site:Content.Text', [], [], true),
        ];

        $imported = NodeImportService::createTableNodeFromYamlArray(Yaml::parse(NodeFixtureYaml::dump($rows)))->getHash();

        self::assertSame(['', 'true'], array_column($imported, 'Hidden'));
    }

    #[Test]
    public function referencePropertiesSurviveTheRoundTrip(): void
    {
        $references = [new ReferenceFixtureRow('teaser', 'targets', ['a'], [], ['label' => 'Read more', 'weight' => 2])];

        $imported = NodeImportService::createReferencesTableNodeFromYamlArray(Yaml::parse(NodeFixtureYaml::dump([], $references)));

        self::assertNotNull($imported);
        self::assertSame(['label' => 'Read more', 'weight' => 2], json_decode($imported->getHash()[0]['Properties'], true));
    }

    #[Test]
    public function dimensionWithSeveralDimensionsSurvives(): void
    {
        $row = new NodeFixtureRow('n', 'p', 'Vendor.Site:Content.Text', [], ['language' => 'de', 'market' => 'eu']);

        $imported = NodeImportService::createTableNodeFromYamlArray(Yaml::parse(NodeFixtureYaml::dump([$row])))->getHash()[0];

        self::assertSame(['language' => 'de', 'market' => 'eu'], json_decode($imported['DimensionSpacePoint'], true));
    }

    /**
     * Export and import may encode JSON slightly differently (escaped slashes/unicode) - compare decoded values.
     */
    private function normalizeJsonCells(array $rows): array
    {
        return array_map(function (array $row) {
            foreach (['Properties', 'DimensionSpacePoint'] as $column) {
                if ($row[$column] !== '' && $row[$column] !== '{}') {
                    $row[$column] = json_encode(json_decode($row[$column], true, flags: JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }
            return $row;
        }, $rows);
    }

    private function writeTemporaryFile(string $content): string
    {
        $file = sys_get_temp_dir() . '/e2e-fixture-' . bin2hex(random_bytes(6)) . '.yaml';
        file_put_contents($file, $content);
        register_shutdown_function(fn () => @unlink($file));
        return $file;
    }
}
