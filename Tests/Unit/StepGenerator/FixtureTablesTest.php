<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\StepGenerator;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureRow;
use Sandstorm\E2ETestTools\Fixture\ReferenceFixtureRow;
use Sandstorm\E2ETestTools\StepGenerator\FixtureTables;

/**
 * Gherkin tables printed by the CLI export and the StepGenerator - optional columns only when a row needs them, so
 * the usual table stays as small as the hand-written ones.
 */
class FixtureTablesTest extends UnitTestCase
{
    #[Test]
    public function nodeTableWithoutHiddenNodesHasNoHiddenColumn(): void
    {
        $table = FixtureTables::nodes([new NodeFixtureRow('home', '', 'Vendor.Site:Document.Page', [], [])]);

        self::assertStringStartsWith('  | NodeAggregateId | Parent | NodeType ', $table->toString());
        self::assertStringNotContainsString('Hidden', $table->toString());
    }

    #[Test]
    public function nodeTableGetsTheHiddenColumnWhenOneNodeIsHidden(): void
    {
        $table = FixtureTables::nodes([
            new NodeFixtureRow('visible', '', 'Vendor.Site:Document.Page', [], []),
            new NodeFixtureRow('hidden', 'visible', 'Vendor.Site:Document.Page', [], [], true),
        ]);

        $lines = explode("\n", trim($table->toString()));
        self::assertMatchesRegularExpression('/\| Hidden +\|$/', $lines[0]);
        self::assertMatchesRegularExpression('/\| visible .*\| +\|$/', $lines[1], 'visible node leaves the Hidden cell empty');
        self::assertMatchesRegularExpression('/\| hidden .*\| true +\|$/', $lines[2]);
    }

    #[Test]
    public function referenceTableWithoutPropertiesHasNoPropertiesColumn(): void
    {
        $table = FixtureTables::references([new ReferenceFixtureRow('a', 'r', ['b'], [])]);

        self::assertStringNotContainsString('Properties', $table->toString());
    }

    #[Test]
    public function referenceTableGetsThePropertiesColumnWhenOneReferenceHasProperties(): void
    {
        $table = FixtureTables::references([
            new ReferenceFixtureRow('a', 'r', ['b'], []),
            new ReferenceFixtureRow('a', 'r', ['c'], [], ['label' => 'x']),
        ]);

        $lines = explode("\n", trim($table->toString()));
        self::assertMatchesRegularExpression('/\| Properties +\|$/', $lines[0]);
        self::assertMatchesRegularExpression('/\| +\|$/', $lines[1]);
        self::assertStringContainsString('{"label":"x"}', $lines[2]);
    }

    #[Test]
    public function emptyInputGivesEmptyTables(): void
    {
        self::assertTrue(FixtureTables::nodes([])->isEmpty());
        self::assertTrue(FixtureTables::references([])->isEmpty());
    }
}
