<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Unit\StepGenerator;

use Behat\Gherkin\GherkinCompatibilityMode;
use Behat\Gherkin\Keywords\CachedArrayKeywords;
use Behat\Gherkin\Lexer;
use Behat\Gherkin\Node\TableNode;
use Behat\Gherkin\Parser;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\E2ETestTools\StepGenerator\GherkinTable;

/**
 * Printed tables are pasted into feature files - so what Behat parses back must be exactly the original values.
 * The round trip uses the real Gherkin parser in Behat's default ("legacy") compatibility mode.
 */
class GherkinTableTest extends UnitTestCase
{
    #[Test]
    public function newTableIsEmptyAndHasOnlyTheHeader(): void
    {
        $table = new GherkinTable(['A', 'B']);

        self::assertTrue($table->isEmpty());
        self::assertSame([['A', 'B']], $this->parse($table)->getRows());
    }

    #[Test]
    public function tableWithRowIsNotEmpty(): void
    {
        $table = new GherkinTable(['A']);
        $table->addRow(['A' => 'x']);

        self::assertFalse($table->isEmpty());
    }

    #[Test]
    #[DataProvider('cellValues')]
    public function valuesSurviveTheGherkinParser(string $value): void
    {
        $table = new GherkinTable(['Id', 'Value']);
        $table->addRow(['Id' => 'row', 'Value' => $value]);

        self::assertSame(['Id' => 'row', 'Value' => $value], $this->parse($table)->getHash()[0]);
    }

    public static function cellValues(): iterable
    {
        yield 'plain text' => ['Hello world'];
        yield 'pipe' => ['a|b'];
        yield 'escaped-looking pipe' => ['a\\|b'];
        yield 'backslash' => ['C:\\path'];
        yield 'trailing backslash' => ['ends with \\'];
        yield 'double backslash' => ['\\\\server'];
        yield 'json with php class name' => [json_encode(['image' => ['__flow_object_type' => 'Neos\\Media\\Domain\\Model\\Image', '__identifier' => 'abc']], JSON_UNESCAPED_SLASHES)];
        yield 'json with escaped newline and quote' => [json_encode(['text' => "line 1\nline \"2\"\ttab"], JSON_UNESCAPED_SLASHES)];
        yield 'json with unicode escape' => [json_encode(['text' => 'Ümläut'])];
        yield 'unicode' => ['Ümläut — 日本語'];
        yield 'html' => ['<h1 class="x">It works</h1>'];
        yield 'hash sign' => ['# not a comment'];
        yield 'empty' => [''];
        yield 'inner spaces' => ['a   b'];
    }

    #[Test]
    #[DataProvider('unrepresentableValues')]
    public function valuesGherkinCantRepresentAreRejected(string $value): void
    {
        // legacy Gherkin trims cells and has no escape for newlines - silently changing the value would make the
        // fixture differ from the content it was generated from
        $table = new GherkinTable(['Value']);

        $this->expectException(\InvalidArgumentException::class);
        $table->addRow(['Value' => $value]);
    }

    public static function unrepresentableValues(): iterable
    {
        yield 'raw newline' => ["two\nlines"];
        yield 'raw carriage return' => ["two\rlines"];
        yield 'leading space' => [' padded'];
        yield 'trailing space' => ['padded '];
        yield 'tab at the end' => ["value\t"];
    }

    #[Test]
    public function missingCellsAreEmpty(): void
    {
        $table = new GherkinTable(['A', 'B']);
        $table->addRow(['B' => 'only b']);

        self::assertSame(['A' => '', 'B' => 'only b'], $this->parse($table)->getHash()[0]);
    }

    #[Test]
    public function cellsAreOrderedByColumnNotByRowKeyOrder(): void
    {
        $table = new GherkinTable(['A', 'B']);
        $table->addRow(['B' => 'b', 'A' => 'a']);

        self::assertSame([['A', 'B'], ['a', 'b']], $this->parse($table)->getRows());
    }

    #[Test]
    public function unknownColumnsAreRejected(): void
    {
        $table = new GherkinTable(['A']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Typo/');
        $table->addRow(['A' => 'a', 'Typo' => 'x']);
    }

    #[Test]
    public function nonStringValuesAreWrittenAsText(): void
    {
        $table = new GherkinTable(['Int', 'Float', 'Null']);
        $table->addRow(['Int' => 2400, 'Float' => 1.5, 'Null' => null]);

        self::assertSame(['Int' => '2400', 'Float' => '1.5', 'Null' => ''], $this->parse($table)->getHash()[0]);
    }

    #[Test]
    public function columnsAreAlignedAlsoForMultibyteAndEscapedValues(): void
    {
        $table = new GherkinTable(['Name', 'X']);
        $table->addRow(['Name' => 'Ümläut', 'X' => '1']);
        $table->addRow(['Name' => 'a|b', 'X' => '2']);
        $table->addRow(['Name' => 'short', 'X' => '3']);

        $lines = array_filter(explode("\n", $table->toString()));
        // every line has the column separator at the same (character) position
        $separatorPositions = array_map(fn (string $line) => mb_strpos($line, '| ', mb_strpos($line, '|') + 1), $lines);
        self::assertCount(1, array_unique($separatorPositions), $table->toString());
    }

    #[Test]
    public function toStringUsesTheGivenIndentation(): void
    {
        $table = new GherkinTable(['A']);

        self::assertSame("    | A |\n", $table->toString('    '));
    }

    private function parse(GherkinTable $table): TableNode
    {
        $feature = "Feature: f\n  Scenario: s\n    Given a table:\n" . $table->toString('      ');
        $parser = new Parser(new Lexer(CachedArrayKeywords::withDefaultKeywords()), GherkinCompatibilityMode::LEGACY);
        $argument = $parser->parse($feature)?->getScenarios()[0]->getSteps()[0]->getArguments()[0] ?? null;
        self::assertInstanceOf(TableNode::class, $argument, $feature);
        return $argument;
    }
}
