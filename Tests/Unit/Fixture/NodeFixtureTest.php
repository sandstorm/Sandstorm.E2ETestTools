<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\Fixture;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Fixture\NodeFixture;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureRow;
use Sandstorm\E2ETestTools\Fixture\ReferenceFixtureRow;

class NodeFixtureTest extends UnitTestCase
{
    // ---------------------------------------------------------------- references limited to the fixture's nodes

    #[Test]
    public function referenceTargetsOutsideTheFixtureAreDroppedKeepingEverythingElse(): void
    {
        $fixture = new NodeFixture(
            [$this->node('a'), $this->node('b'), $this->node('c')],
            [new ReferenceFixtureRow('a', 'r', ['b', 'outside', 'c'], ['language' => 'de'], ['label' => 'x'])]
        );

        self::assertEquals(
            [new ReferenceFixtureRow('a', 'r', ['b', 'c'], ['language' => 'de'], ['label' => 'x'])],
            $fixture->withReferencesLimitedToNodes()->references
        );
    }

    #[Test]
    public function referencesWithoutRemainingTargetsAreDropped(): void
    {
        $fixture = new NodeFixture(
            [$this->node('a')],
            [new ReferenceFixtureRow('a', 'r', ['outside'], []), new ReferenceFixtureRow('a', 'self', ['a'], [])]
        );

        self::assertEquals([new ReferenceFixtureRow('a', 'self', ['a'], [])], $fixture->withReferencesLimitedToNodes()->references);
    }

    // ---------------------------------------------------------------- property overwrites

    #[Test]
    public function overwritesReplaceAndAddPropertiesOfTheMatchingNodeOnly(): void
    {
        $fixture = (new NodeFixture([
            $this->node('a', ['title' => 'A', 'text' => 'keep']),
            $this->node('b', ['title' => 'B']),
        ]))->withPropertyOverwrites(['a' => ['title' => 'overwritten', 'added' => 'new']]);

        self::assertSame(['title' => 'overwritten', 'text' => 'keep', 'added' => 'new'], $fixture->nodes[0]->properties);
        self::assertSame(['title' => 'B'], $fixture->nodes[1]->properties);
    }

    #[Test]
    public function overwritesForNodesWithoutPropertiesWork(): void
    {
        $fixture = (new NodeFixture([$this->node('a')]))->withPropertyOverwrites(['a' => ['title' => 'set']]);

        self::assertSame(['title' => 'set'], $fixture->nodes[0]->properties);
    }

    #[Test]
    public function overwritesKeepReferencesAndTheRestOfTheNode(): void
    {
        $references = [new ReferenceFixtureRow('a', 'r', ['a'], [])];
        $fixture = (new NodeFixture([new NodeFixtureRow('a', 'p', 'Vendor.Site:Content.Text', [], ['language' => 'de'], true)], $references))
            ->withPropertyOverwrites(['a' => ['title' => 'set']]);

        self::assertEquals(new NodeFixtureRow('a', 'p', 'Vendor.Site:Content.Text', ['title' => 'set'], ['language' => 'de'], true), $fixture->nodes[0]);
        self::assertSame($references, $fixture->references);
    }

    #[Test]
    public function overwritesForUnknownNodesFail(): void
    {
        // a typo in the overwrite table's nodeAggregateId would otherwise silently overwrite nothing
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/typo-id/');

        (new NodeFixture([$this->node('a')]))->withPropertyOverwrites(['typo-id' => ['title' => 'x']]);
    }

    /**
     * Overwrite values come from a Gherkin table, i.e. always as strings. Valid JSON values are decoded, so boolean,
     * number, null, list and object properties can be overwritten too; everything else stays the literal text.
     */
    #[Test]
    #[DataProvider('overwriteValues')]
    public function overwriteValuesAreDecodedWhenTheyAreJson(string $tableValue, mixed $expectedValue): void
    {
        $fixture = (new NodeFixture([$this->node('a')]))->withPropertyOverwrites(['a' => ['value' => $tableValue]]);

        self::assertSame(['value' => $expectedValue], $fixture->nodes[0]->properties);
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

    /**
     * @param array<string,mixed> $properties
     */
    // ---------------------------------------------------------------- default dimension space point

    #[Test]
    public function rowsWithoutDimensionGetTheDefault(): void
    {
        $fixture = (new NodeFixture(
            [$this->node('a'), $this->node('b')],
            [new ReferenceFixtureRow('a', 'r', ['b'], [])]
        ))->withDefaultDimensionSpacePoint(['language' => 'de']);

        self::assertSame([['language' => 'de'], ['language' => 'de']], array_map(fn (NodeFixtureRow $node) => $node->dimensionSpacePoint, $fixture->nodes));
        self::assertSame(['language' => 'de'], $fixture->references[0]->dimensionSpacePoint);
    }

    #[Test]
    public function rowsWithTheirOwnDimensionKeepIt(): void
    {
        $fixture = (new NodeFixture(
            [$this->node('a'), $this->node('b')->withDimensionSpacePoint(['language' => 'ch'])],
            [new ReferenceFixtureRow('a', 'r', ['b'], []), new ReferenceFixtureRow('b', 'r', ['a'], ['language' => 'ch'])]
        ))->withDefaultDimensionSpacePoint(['language' => 'de']);

        self::assertSame([['language' => 'de'], ['language' => 'ch']], array_map(fn (NodeFixtureRow $node) => $node->dimensionSpacePoint, $fixture->nodes));
        self::assertSame([['language' => 'de'], ['language' => 'ch']], array_map(fn (ReferenceFixtureRow $reference) => $reference->dimensionSpacePoint, $fixture->references));
    }

    #[Test]
    public function withoutDefaultTheFixtureStaysAsItIs(): void
    {
        $fixture = new NodeFixture([$this->node('a')], [new ReferenceFixtureRow('a', 'r', ['a'], [])]);

        self::assertSame($fixture, $fixture->withDefaultDimensionSpacePoint([]));
    }

    private function node(string $nodeAggregateId, array $properties = []): NodeFixtureRow
    {
        return new NodeFixtureRow($nodeAggregateId, '', 'Vendor.Site:Content.Text', $properties, []);
    }
}
