<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use Throwable;

/**
 * Reads a member-profile workbook into a plain grid, tolerant of a title row
 * above the real header, merged cells, blank rows, multiple worksheets, and
 * formula-error cells (#NAME? etc). Generalizes PayrollSheetParser to add
 * worksheet selection and per-cell number-format/error capture (needed for
 * accurate Excel-serial-date conversion and to avoid importing literal error
 * text) — the grid-build/merge-cell/header-detection approach itself follows
 * the same shape, duplicated rather than shared since the two parsers now
 * diverge enough (worksheet awareness, cell metadata) to not share a base.
 */
class MemberSheetParser
{
    private const KNOWN_ERROR_CODES = ['#NULL!', '#DIV/0!', '#VALUE!', '#REF!', '#NAME?', '#NUM!', '#N/A', '#GETTING_DATA', '#SPILL!', '#CALC!', '#ERROR!'];

    /**
     * Cheap — does not parse cell data, just the workbook's sheet list.
     *
     * @return array<int, array{name: string, index: int, totalRows: int, totalColumns: int}>
     */
    public function listWorksheets(string $absolutePath, string $ext): array
    {
        $info = $this->readerFor($ext)->listWorksheetInfo($absolutePath);

        return array_values(array_map(fn ($sheet, $index) => [
            'name' => $sheet['worksheetName'],
            'index' => $index,
            'totalRows' => $sheet['totalRows'],
            'totalColumns' => $sheet['totalColumns'],
        ], $info, array_keys($info)));
    }

    /**
     * @return array{headers: array<int,string>, headerRowIndex: int, dataRows: array<int, array<string, array{value: mixed, format: ?string, isError: bool}>>}
     */
    public function parse(string $absolutePath, string $ext, ?string $sheetName = null): array
    {
        $spreadsheet = $this->readerFor($ext)->load($absolutePath);

        $sheet = $sheetName !== null
            ? ($spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet())
            : $spreadsheet->getActiveSheet();

        $highestRow = $sheet->getHighestDataRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

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
            $rowCells = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cellRef = Coordinate::stringFromColumnIndex($col).$row;
                $cell = $sheet->getCell($cellRef);

                try {
                    $value = $cell->getCalculatedValue();
                } catch (Throwable) {
                    $value = '#ERROR!';
                }

                if (($value === null || $value === '') && isset($mergeMap[$cellRef])) {
                    $value = $mergeMap[$cellRef];
                }

                $isError = is_string($value) && in_array($value, self::KNOWN_ERROR_CODES, true);

                $rowCells[$col] = [
                    'value' => $isError ? null : (is_string($value) ? trim($value) : $value),
                    'format' => $cell->getStyle()->getNumberFormat()->getFormatCode(),
                    'isError' => $isError,
                ];
            }
            $grid[$row] = $rowCells;
        }

        $headerRowIndex = $this->detectHeaderRow($grid, $highestRow, $highestColumnIndex);
        $headerRow = $grid[$headerRowIndex] ?? [];

        $headers = [];
        foreach ($headerRow as $col => $cellInfo) {
            $label = is_string($cellInfo['value']) ? trim($cellInfo['value']) : (string) ($cellInfo['value'] ?? '');
            if ($label !== '') {
                $headers[$col] = $label;
            }
        }

        $dataRows = [];
        for ($row = $headerRowIndex + 1; $row <= $highestRow; $row++) {
            $rowCells = $grid[$row] ?? [];
            $isBlank = true;
            $keyed = [];
            foreach ($headers as $col => $label) {
                $cellInfo = $rowCells[$col] ?? ['value' => null, 'format' => null, 'isError' => false];
                if (($cellInfo['value'] !== null && $cellInfo['value'] !== '') || $cellInfo['isError']) {
                    $isBlank = false;
                }
                $keyed[$label] = $cellInfo;
            }
            if ($isBlank) {
                continue;
            }
            $dataRows[$row] = $keyed;
        }

        return [
            'headers' => array_values($headers),
            'headerRowIndex' => $headerRowIndex,
            'dataRows' => $dataRows,
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
     * that look like a known column name — tolerant of a title/office-label
     * row above the real header (e.g. "OFFICE | GENERAL SERVICES OFFICE" on
     * row 1, real headers on row 2).
     *
     * @param  array<int, array<int, array{value: mixed, format: ?string, isError: bool}>>  $grid
     */
    private function detectHeaderRow(array $grid, int $highestRow, int $highestColumnIndex): int
    {
        $candidateRows = range(1, min(5, $highestRow));
        $bestRow = $candidateRows[0] ?? 1;
        $bestScore = -1;

        foreach ($candidateRows as $row) {
            $score = 0;
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $value = $grid[$row][$col]['value'] ?? null;
                if (is_string($value) && $value !== '' && MemberColumnMapper::looksLikeKnownHeader($value)) {
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
