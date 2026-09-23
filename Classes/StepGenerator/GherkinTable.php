<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\StepGenerator;

/**
 * Pretty-printed Gherkin table.
 *
 * @internal
 */
class GherkinTable
{
    private array $columnTitles;
    private array $rows;
    private array $maxColumnWidth;

    public function __construct(array $columnTitles)
    {
        $this->columnTitles = $columnTitles;
        $this->rows = [$columnTitles];
        $this->maxColumnWidth = [];
        foreach ($this->columnTitles as $row) {
            $this->maxColumnWidth[] = mb_strlen($row);
        }
    }

    public function addRow(array $row)
    {
        $unusedRowValues = array_diff(array_keys($row), $this->columnTitles);
        if (count($unusedRowValues) > 0) {
            throw new \RuntimeException('The row values ' . implode(', ', $unusedRowValues) . ' are not defined.');
        }
        $transformedRow = [];
        foreach ($this->columnTitles as $i => $columnTitle) {
            $value = self::escapeGherkinTableCellValue((string)($row[$columnTitle] ?? ''));
            $transformedRow[] = $value;
            $this->maxColumnWidth[$i] = max($this->maxColumnWidth[$i], mb_strlen($value));
        }
        $this->rows[] = $transformedRow;
    }

    public function print()
    {
        echo $this->toString();
    }

    public function toString(string $indentation = '  '): string
    {
        $output = '';
        foreach ($this->rows as $row) {
            $output .= $indentation . '|';
            foreach ($row as $i => $value) {
                $output .= ' ' . $value . str_repeat(' ', $this->maxColumnWidth[$i] - mb_strlen($value)) . ' |';
            }
            $output .= "\n";
        }
        return $output;
    }

    public function isEmpty(): bool
    {
        // the first row holds the column titles
        return count($this->rows) <= 1;
    }

    /**
     * Gherkin unescapes "\\" to "\" and "\|" to "|" in table cells - so both need escaping, e.g. for backslashes in
     * JSON-encoded PHP class names.
     */
    private static function escapeGherkinTableCellValue(string $value): string
    {
        return str_replace(['\\', '|'], ['\\\\', '\\|'], $value);
    }
}
