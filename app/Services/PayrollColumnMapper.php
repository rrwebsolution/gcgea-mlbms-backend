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
     * @param  array<int, string>  $headers
     * @return array{mapping: array<string, string|null>, unmatched: array<int, string>}
     */
    public function detect(array $headers): array
    {
        $pool = [];
        foreach ($headers as $header) {
            $pool[$header] = $this->normalize($header);
        }

        $flatAliases = [];
        foreach ($this->aliasesWithDeductionTypes() as $field => $aliases) {
            foreach ($aliases as $alias) {
                $flatAliases[] = [$field, $alias];
            }
        }
        usort($flatAliases, fn ($a, $b) => strlen($b[1]) <=> strlen($a[1]));

        $mapping = array_fill_keys(array_keys(self::TARGET_FIELDS), null);

        foreach ($flatAliases as [$field, $alias]) {
            if ($mapping[$field] !== null) {
                continue;
            }
            foreach ($pool as $header => $normalized) {
                if ($normalized !== '' && str_contains($normalized, $alias)) {
                    $mapping[$field] = $header;
                    unset($pool[$header]);
                    break;
                }
            }
        }

        return [
            'mapping' => $mapping,
            'unmatched' => array_keys($pool),
        ];
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
