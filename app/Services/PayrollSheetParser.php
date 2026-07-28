<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;

/**
 * Reads a payroll deduction workbook into a plain grid, tolerant of merged
 * header cells, blank columns, and trailing/leading blank rows. Deliberately
 * knows nothing about target field mapping — that's PayrollColumnMapper's job
 * one layer up — this class only turns a spreadsheet into rows of strings.
 */
class PayrollSheetParser
{
    /**
     * @return array{headers: array<string, string>, headerRowIndex: int, dataRows: array<int, array<string, mixed>>, sheetNames: array<int, string>, selectedSheet: string}
     */
    public function parse(string $absolutePath, string $ext, ?string $sheetName = null): array
    {
        $spreadsheet = $this->readerFor($ext)->load($absolutePath);
        $sheetNames = $spreadsheet->getSheetNames();
        // Falls back to the active sheet when no name is given, or when a
        // stale/invalid name is passed — never a hard error over a sheet pick.
        $sheet = ($sheetName !== null ? $spreadsheet->getSheetByName($sheetName) : null) ?? $spreadsheet->getActiveSheet();

        $highestRow = $sheet->getHighestDataRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        // Merged cells only carry their value in the top-left cell — build a
        // lookup so every cell in a merged range resolves to that value,
        // otherwise a header merged across sub-columns reads as blank.
        $mergeMap = [];
        foreach ($sheet->getMergeCells() as $range) {
            [$topLeft] = explode(':', $range);
            $topLeftValue = $sheet->getCell($topLeft)->getCalculatedValue();
            foreach (Coordinate::extractAllCellReferencesInRange($range) as $cellRef) {
                $mergeMap[$cellRef] = $topLeftValue;
            }
        }

        $grid = [];
        for ($row = 1; $row <= $highestRow; $row++) {
            $rowValues = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cellRef = Coordinate::stringFromColumnIndex($col).$row;
                $value = $sheet->getCell($cellRef)->getCalculatedValue();
                if (($value === null || $value === '') && isset($mergeMap[$cellRef])) {
                    $value = $mergeMap[$cellRef];
                }
                $rowValues[$col] = is_string($value) ? trim($value) : $value;
            }
            $grid[$row] = $rowValues;
        }

        $headerRowIndex = $this->detectHeaderRow($grid, $highestRow, $highestColumnIndex);
        $headerRow = $grid[$headerRowIndex] ?? [];

        // Keyed by spreadsheet column letter, not label text. Real payroll
        // sheets repeat header text (e.g. an original "Principal" loan amount
        // column and a separate "Principal" column further right holding this
        // month's actual payment) — keying by label would let the second
        // silently overwrite the first in every data row, discarding one of
        // the two values entirely. Column letters are always unique.
        $headers = [];
        foreach ($headerRow as $col => $value) {
            $label = is_string($value) ? trim($value) : (string) ($value ?? '');
            if ($label !== '') {
                $headers[Coordinate::stringFromColumnIndex($col)] = $label;
            }
        }

        $dataRows = [];
        for ($row = $headerRowIndex + 1; $row <= $highestRow; $row++) {
            $rowValues = $grid[$row] ?? [];
            $isBlank = true;
            $keyed = [];
            foreach ($headerRow as $col => $__) {
                $columnLetter = Coordinate::stringFromColumnIndex($col);
                if (! isset($headers[$columnLetter])) {
                    continue;
                }
                $value = $rowValues[$col] ?? null;
                if ($value !== null && $value !== '') {
                    $isBlank = false;
                }
                $keyed[$columnLetter] = $value;
            }
            if ($isBlank) {
                continue;
            }
            $dataRows[$row] = $keyed;
        }

        return [
            'headers' => $headers,
            'headerRowIndex' => $headerRowIndex,
            'dataRows' => $dataRows,
            'sheetNames' => $sheetNames,
            'selectedSheet' => $sheet->getTitle(),
        ];
    }

    private function readerFor(string $ext): IReader
    {
        return match (strtolower($ext)) {
            'csv' => IOFactory::createReader('Csv'),
            'xls' => IOFactory::createReader('Xls'),
            default => IOFactory::createReader('Xlsx'),
        };
    }

    /**
     * The header row is the one among the first few rows with the most cells
     * that look like a known column name — tolerant of a title/logo row above
     * the real header, and of the header not being row 1.
     *
     * @param  array<int, array<int, mixed>>  $grid
     */
    private function detectHeaderRow(array $grid, int $highestRow, int $highestColumnIndex): int
    {
        $candidateRows = range(1, min(5, $highestRow));
        $bestRow = $candidateRows[0] ?? 1;
        $bestScore = -1;

        foreach ($candidateRows as $row) {
            $score = 0;
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $value = $grid[$row][$col] ?? null;
                if (is_string($value) && $value !== '' && PayrollColumnMapper::looksLikeKnownHeader($value)) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = $row;
            }
        }

        return $bestRow;
    }
}
