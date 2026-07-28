<?php

namespace App\Services;

use App\Models\DeductionType;

/**
 * Detects which spreadsheet column corresponds to each target field the
 * payroll importer understands, tolerant of extra/historical/blank columns
 * and headers that don't exactly match the canonical wording. Matching is
 * substring-based against normalized (lowercased, non-alphanumerics
 * stripped) text, with longer/more specific aliases claiming a header before
 * shorter/more generic ones — this is what stops "Principal" from stealing
 * the "PRINCIPAL BALANCE as of Previous Month" column.
 */
class PayrollColumnMapper
{
    /**
     * NOTE: "loan_remarks" is deliberately not called "date" — the sheet's
     * DATE column actually holds values like "Fully Paid" or "New Loan
     * 10/2024", not real dates, per the client's own correction.
     */
    public const TARGET_FIELDS = [
        'member_number' => 'Member Number',
        'employee_number' => 'Employee Number',
        'office_name' => 'Office',
        'name' => 'Name',
        'loan_remarks' => 'Loan Remarks / Loan Status',
        'principal' => 'Principal',
        'interest' => 'Interest',
        'principal_balance_previous' => 'Principal Balance as of Previous Month',
        'interest_balance_previous' => 'Interest Balance as of Previous Month',
        'current_month_loan_payment' => 'Current Month Loan Payment',
        'monthly_dues' => 'Monthly Dues',
        'pabaon' => 'Pabaon',
        'total_remit' => 'Total Remit',
        'principal_balance_current' => 'Principal Balance as of Current Month',
        'interest_balance_current' => 'Interest Balance as of Current Month',
        'total_balance_current' => 'Total Balance as of Current Month',
    ];

    public const REQUIRED_FIELDS = ['name', 'principal', 'interest', 'monthly_dues', 'pabaon', 'total_remit'];

    private const ALIASES = [
        'member_number' => ['membernumber', 'memno', 'mbrno'],
        'employee_number' => ['employeenumber', 'empno', 'employeeno', 'idno'],
        'office_name' => ['nameofoffice', 'officename', 'office'],
        'name' => ['nameofemployee', 'nameofmember', 'employeename', 'membername', 'name'],
        // Deliberately no bare "remarks" alias — too generic, it would steal
        // an unrelated "*Remarks*" historical/summary column instead of the
        // sheet's actual "DATE" column when both are present.
        'loan_remarks' => ['loanstatusremarks', 'loanremarks', 'loanstatus', 'date'],
        'principal_balance_previous' => [
            'principalbalanceasofpreviousmonth', 'principalbalancepreviousmonth', 'prevprincipalbalance', 'principalbalanceprevious',
        ],
        'interest_balance_previous' => [
            'interestbalanceasofpreviousmonth', 'interestasofpreviousmonth', 'interestbalancepreviousmonth', 'previnterestbalance', 'interestbalanceprevious',
        ],
        'principal_balance_current' => [
            'principalbalanceasofcurrentmonth', 'principalbalancecurrentmonth', 'currentprincipalbalance', 'principalbalancecurrent',
        ],
        'interest_balance_current' => [
            'interestbalanceasofcurrentmonth', 'interestbalancecurrentmonth', 'currentinterestbalance', 'interestbalancecurrent',
        ],
        'current_month_loan_payment' => ['currentmonthloanpayment', 'currentloanpayment', 'currentmonthpayment'],
        'total_balance_current' => ['totalbalanceasofcurrentmonth', 'totalbalancecurrentmonth', 'totalbalance'],
        'total_remit' => ['totalremittance', 'totalremit'],
        'monthly_dues' => ['monthlydues', 'dues'],
        'principal' => ['principal'],
        'interest' => ['interest'],
        'pabaon' => ['pabaon'],
    ];

    /**
     * @param  array<string, string>  $headers  columnLetter => label
     * @return array{mapping: array<string, string|null>, unmatched: array<int, string>}
     */
    public function detect(array $headers): array
    {
        $pool = [];
        foreach ($headers as $columnLetter => $label) {
            $pool[$columnLetter] = $this->normalize($label);
        }

        $mapping = array_fill_keys(array_keys(self::TARGET_FIELDS), null);

        // Pass 1: specific, unambiguous aliases — member/employee number,
        // name, dues, pabaon, total remit, and the literal "previous/current
        // month" wording some sheets (like the GCGEA template's own
        // generated headers) use verbatim. "principal"/"interest" are
        // deliberately skipped here — bare words that generic, they'd grab
        // the first column mentioning either (usually the loan's original
        // amount, not the payment) before Pass 2/3 below get a chance to
        // reason about which occurrence is actually correct.
        $flatAliases = [];
        foreach ($this->aliasesWithDeductionTypes() as $field => $aliases) {
            if (in_array($field, ['principal', 'interest'], true)) {
                continue;
            }
            foreach ($aliases as $alias) {
                $flatAliases[] = [$field, $alias];
            }
        }
        usort($flatAliases, fn ($a, $b) => strlen($b[1]) <=> strlen($a[1]));

        foreach ($flatAliases as [$field, $alias]) {
            if ($mapping[$field] !== null) {
                continue;
            }
            foreach ($pool as $columnLetter => $normalized) {
                if ($normalized !== '' && str_contains($normalized, $alias)) {
                    $mapping[$field] = $columnLetter;
                    unset($pool[$columnLetter]);
                    break;
                }
            }
        }

        // Pass 2: real-world sheets usually label balance columns "... as of
        // <month> <year>" instead of the literal words "previous"/"current
        // month" — those change every period, so Pass 1's exact aliases
        // never match them. Detect the pair by structure instead (the field
        // name plus an "as of" qualifier — the word "balance" itself isn't
        // required since not every sheet includes it consistently) and use
        // column order to tell them apart: on a left-to-right chronological
        // payroll sheet, the earlier column is always last period's balance
        // and the later one is this period's.
        if ($mapping['principal_balance_previous'] === null || $mapping['principal_balance_current'] === null) {
            $this->claimOrderedPair($pool, $mapping, 'principal', 'principal_balance_previous', 'principal_balance_current');
        }
        if ($mapping['interest_balance_previous'] === null || $mapping['interest_balance_current'] === null) {
            $this->claimOrderedPair($pool, $mapping, 'interest', 'interest_balance_previous', 'interest_balance_current');
        }

        // Pass 3: "Principal"/"Interest" can also appear twice on the same
        // sheet — once for the loan's original amount, again further right
        // for this month's actual payment (the one that actually gets
        // posted). Balance columns are already claimed above, so whatever's
        // left bearing these words is a genuine payment candidate; prefer
        // the rightmost one for the same reason.
        if ($mapping['principal'] === null) {
            $this->claimLast($pool, $mapping, 'principal', 'principal');
        }
        if ($mapping['interest'] === null) {
            $this->claimLast($pool, $mapping, 'interest', 'interest');
        }

        return [
            'mapping' => $mapping,
            'unmatched' => array_keys($pool),
        ];
    }

    /**
     * Claims exactly two unclaimed columns whose normalized text contains
     * both $fieldNeedle and "asof" — the leftmost goes to $previousField,
     * the rightmost to $currentField. Does nothing if there isn't a clear
     * pair (0 or 1 matches) — better to leave it for the admin to place by
     * hand in Map Columns than guess wrong on a single ambiguous column.
     *
     * @param  array<string,string>  $pool
     * @param  array<string,?string>  $mapping
     */
    private function claimOrderedPair(array &$pool, array &$mapping, string $fieldNeedle, string $previousField, string $currentField): void
    {
        $matches = [];
        foreach ($pool as $columnLetter => $normalized) {
            if ($normalized !== '' && str_contains($normalized, $fieldNeedle) && str_contains($normalized, 'asof')) {
                $matches[] = $columnLetter;
            }
        }
        if (count($matches) < 2) {
            return;
        }

        $first = $matches[0];
        $last = end($matches);
        if ($mapping[$previousField] === null) {
            $mapping[$previousField] = $first;
        }
        if ($mapping[$currentField] === null) {
            $mapping[$currentField] = $last;
        }
        unset($pool[$first], $pool[$last]);
    }

    /**
     * @param  array<string,string>  $pool
     * @param  array<string,?string>  $mapping
     */
    private function claimLast(array &$pool, array &$mapping, string $needle, string $field): void
    {
        $matched = null;
        foreach ($pool as $columnLetter => $normalized) {
            if ($normalized !== '' && str_contains($normalized, $needle)) {
                $matched = $columnLetter;
            }
        }
        if ($matched !== null) {
            $mapping[$field] = $matched;
            unset($pool[$matched]);
        }
    }

    public static function looksLikeKnownHeader(string $value): bool
    {
        $normalized = (new self)->normalize($value);
        if ($normalized === '') {
            return false;
        }

        foreach (self::ALIASES as $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($normalized, $alias)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Merges in active DeductionType names/codes as extra aliases for the
     * "pabaon" slot, so an admin-renamed or newly added deduction type is
     * still detectable without a code change.
     *
     * @return array<string, array<int, string>>
     */
    private function aliasesWithDeductionTypes(): array
    {
        $aliases = self::ALIASES;

        try {
            $extra = DeductionType::query()->where('is_active', true)->get(['name', 'code']);
            foreach ($extra as $deductionType) {
                $aliases['pabaon'][] = $this->normalize($deductionType->name);
                $aliases['pabaon'][] = $this->normalize($deductionType->code);
            }
            $aliases['pabaon'] = array_values(array_unique(array_filter($aliases['pabaon'])));
        } catch (\Throwable) {
            // No DB connection available (e.g. running outside a request/test
            // without migrations yet) — fall back to the static alias list.
        }

        return $aliases;
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }
}
