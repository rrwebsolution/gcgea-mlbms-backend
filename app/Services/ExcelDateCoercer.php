<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Converts a raw cell value (Excel serial number, formatted date, or free
 * text) into a real date, or null if it can't be trusted. Kept separate from
 * MemberSheetParser so its edge cases (serial numbers, text dates, garbage)
 * can be reasoned about and tested on their own.
 */
class ExcelDateCoercer
{
    private const MIN_YEAR = 1900;

    public function coerce(mixed $rawValue, ?string $numberFormatCode = null): ?CarbonImmutable
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        if (is_numeric($rawValue)) {
            // A bare number with no date-ish format code is not a date —
            // e.g. "No. of Months" is numeric too. Don't guess.
            if ($numberFormatCode === null || ! ExcelDate::isDateTimeFormatCode($numberFormatCode)) {
                return null;
            }

            try {
                $dateTime = ExcelDate::excelToDateTimeObject((float) $rawValue);
            } catch (Throwable) {
                return null;
            }

            return $this->plausible(CarbonImmutable::instance($dateTime));
        }

        if (is_string($rawValue)) {
            $trimmed = trim($rawValue);
            if ($trimmed === '') {
                return null;
            }

            try {
                $parsed = CarbonImmutable::parse($trimmed);
            } catch (Throwable) {
                return null;
            }

            return $this->plausible($parsed);
        }

        return null;
    }

    /**
     * Rejects obviously-garbage results (e.g. a two-digit-year typo parsed
     * as the year 0025, or a stray formula/number Carbon happened to accept)
     * rather than silently importing a nonsensical date.
     */
    private function plausible(CarbonImmutable $date): ?CarbonImmutable
    {
        $currentYear = (int) CarbonImmutable::now()->format('Y');
        if ($date->year < self::MIN_YEAR || $date->year > $currentYear + 1) {
            return null;
        }

        return $date;
    }
}
