<?php

namespace App\Services;

use App\Models\Member;
use Carbon\CarbonImmutable;

/**
 * Weighted duplicate-member scoring. Employee number carries the most weight
 * since it's the closest thing to a stable external ID, followed by an
 * order-independent name match, then birthdate, then weaker corroborating
 * signals (middle name, office). Candidates are pre-filtered by a cheap SQL
 * OR-match before scoring to avoid comparing against the entire members
 * table on every row.
 */
class MemberDuplicateDetectionService
{
    private const WEIGHT_EMPLOYEE_NUMBER = 40;

    private const WEIGHT_NAME = 30;

    private const WEIGHT_BIRTHDATE = 20;

    private const WEIGHT_MIDDLE_NAME = 5;

    private const WEIGHT_OFFICE = 5;

    private const THRESHOLD_EXACT = 90;

    private const THRESHOLD_PROBABLE = 70;

    private const THRESHOLD_POSSIBLE = 40;

    /**
     * @param  array{last_name?: ?string, first_name?: ?string, middle_name?: ?string, birthdate?: ?CarbonImmutable, employee_number?: ?string}  $mappedRow
     * @return array{category: string, score: float, candidates: array<int, array{memberId: string, score: float, matchedFields: array<int,string>}>}
     */
    public function evaluate(array $mappedRow, ?int $resolvedOfficeId): array
    {
        $surname = trim((string) ($mappedRow['last_name'] ?? ''));
        $firstName = trim((string) ($mappedRow['first_name'] ?? ''));
        $middleName = trim((string) ($mappedRow['middle_name'] ?? ''));
        $employeeNumber = trim((string) ($mappedRow['employee_number'] ?? ''));
        /** @var ?CarbonImmutable $birthdate */
        $birthdate = $mappedRow['birthdate'] ?? null;

        if ($surname === '' && $employeeNumber === '' && ! $birthdate) {
            return ['category' => 'New', 'score' => 0, 'candidates' => []];
        }

        $pool = Member::query()
            ->where(function ($q) use ($surname, $employeeNumber, $birthdate) {
                $any = false;
                if ($surname !== '') {
                    $q->orWhere('surname', 'ilike', $surname);
                    $any = true;
                }
                if ($employeeNumber !== '') {
                    $q->orWhere('employee_number', $employeeNumber);
                    $any = true;
                }
                if ($birthdate) {
                    $q->orWhere('birthdate', $birthdate->toDateString());
                    $any = true;
                }
                if (! $any) {
                    $q->whereRaw('1 = 0');
                }
            })
            ->limit(200)
            ->get();

        $needleName = $this->normalizeNameTokens($surname.' '.$firstName);

        $scored = [];
        foreach ($pool as $member) {
            $score = 0;
            $matched = [];

            if ($employeeNumber !== '' && ! empty($member->employee_number)
                && strcasecmp(trim($member->employee_number), $employeeNumber) === 0) {
                $score += self::WEIGHT_EMPLOYEE_NUMBER;
                $matched[] = 'employee_number';
            }

            $candidateName = $this->normalizeNameTokens($member->surname.' '.$member->first_name);
            if ($needleName !== '' && $candidateName === $needleName) {
                $score += self::WEIGHT_NAME;
                $matched[] = 'name';
            }

            if ($birthdate && $member->birthdate && $birthdate->toDateString() === $member->birthdate->toDateString()) {
                $score += self::WEIGHT_BIRTHDATE;
                $matched[] = 'birthdate';
            }

            if ($middleName !== '' && ! empty($member->middle_name)
                && strcasecmp(trim($member->middle_name), $middleName) === 0) {
                $score += self::WEIGHT_MIDDLE_NAME;
                $matched[] = 'middle_name';
            }

            if ($resolvedOfficeId && $member->office_id === $resolvedOfficeId) {
                $score += self::WEIGHT_OFFICE;
                $matched[] = 'office';
            }

            if ($score > 0) {
                $scored[] = ['memberId' => (string) $member->id, 'score' => (float) $score, 'matchedFields' => $matched];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $topScore = $scored[0]['score'] ?? 0.0;
        $category = match (true) {
            $topScore >= self::THRESHOLD_EXACT => 'Exact',
            $topScore >= self::THRESHOLD_PROBABLE => 'Probable',
            $topScore >= self::THRESHOLD_POSSIBLE => 'Possible',
            default => 'New',
        };

        return ['category' => $category, 'score' => $topScore, 'candidates' => $scored];
    }

    /**
     * Word-order-independent name comparison, same technique as
     * PayrollImportService::normalizeNameTokens() — so "Dela Cruz, Juan" and
     * "Juan Dela Cruz" both match.
     */
    private function normalizeNameTokens(string $name): string
    {
        $letters = preg_replace('/[^A-Za-z\s]/', ' ', strtoupper($name)) ?? '';
        $tokens = array_values(array_filter(preg_split('/\s+/', trim($letters)) ?: []));
        sort($tokens);

        return implode(' ', $tokens);
    }
}
