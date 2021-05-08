<?php

namespace Sandstorm\E2ETestTools\StepGenerator;

/**
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
            $this->maxColumnWidth[] = strlen($row);
        }

    }

    public function addRow(array $row)
    {

        $transformedRow = [];
        foreach ($this->columnTitles as $i => $columnTitle) {
            $value = isset($row[$columnTitle]) ? $row[$columnTitle] : '';
            $transformedRow[] = $value;
            if ($this->maxColumnWidth[$i] < strlen($value)) {
                $this->maxColumnWidth[$i] = strlen($value);
            }
        }

        $unusedRowValues = array_diff(array_keys($row), $this->columnTitles);
        if (count($unusedRowValues) > 0) {
            throw new \RuntimeException('The row values ' . implode(', ', $unusedRowValues) . ' are not defined.');
        }

        $this->rows[] = $transformedRow;
    }

    public function print()
    {
        foreach ($this->rows as $row) {
            echo ' ';
            foreach ($row as $i => $value) {
                echo ' | ';
                echo str_pad($value, $this->maxColumnWidth[$i]);
            }
            echo ' |';
            echo "\n";
        }
    }
}
