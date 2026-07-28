<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanImportBatch;
use App\Models\LoanPayment;
use App\Models\LoanType;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

class LegacyLoanWorkbookImportService
{
    public function preview(string $path, string $period): array
    {
        $rows = $this->readRows($path, $period);
        $alreadyImportedFingerprints = Loan::query()
            ->whereIn('legacy_import_fingerprint', array_column($rows, 'fingerprint'))
            ->pluck('legacy_import_fingerprint')
            ->flip();
        $recordedPeriods = LoanPayment::query()
            ->whereIn('payroll_reference', array_values(array_unique(array_map(
                fn (array $row) => 'LEGACY-'.$row['lastPaymentMonth'],
                $rows,
            ))))
            ->whereHas('loan', fn ($query) => $query->whereIn('legacy_loan_identity', array_column($rows, 'identity')))
            ->with('loan:id,legacy_loan_identity')
            ->get()
            ->mapWithKeys(fn (LoanPayment $payment) => [
                $payment->loan->legacy_loan_identity.'|'.str_replace('LEGACY-', '', (string) $payment->payroll_reference) => true,
            ]);
        $alreadyImported = 0;
        $rows = array_values(array_filter($rows, function (array $row) use ($alreadyImportedFingerprints, $recordedPeriods, &$alreadyImported): bool {
            if ($alreadyImportedFingerprints->has($row['fingerprint'])
                || $recordedPeriods->has($row['identity'].'|'.$row['lastPaymentMonth'])) {
                $alreadyImported++;

                return false;
            }

            return true;
        }));
        $members = Member::query()->get()->groupBy(fn (Member $member) => $this->normalizeName($member->surname.', '.$member->first_name.' '.$member->middle_name.' '.$member->suffix));

        foreach ($rows as &$row) {
            $matches = $members->get($this->normalizeName($row['name']), collect());
            $row['memberId'] = $matches->count() === 1 ? (string) $matches->first()->id : null;
            $row['memberName'] = $matches->count() === 1 ? $matches->first()->full_name : null;
            $row['candidates'] = $this->candidateMembers($row['name'], $members->flatten());
            $row['category'] = $matches->count() === 1 ? 'Ready' : ($matches->isEmpty() ? 'No member match' : 'Multiple member matches');
            $row['reasons'] = $matches->count() === 1 ? [] : [$matches->isEmpty()
                ? 'No system member has this exact normalized name.'
                : 'More than one system member has this name.'];

            if ($row['memberId']) {
                $existingLoan = Loan::query()
                    ->where('member_id', $row['memberId'])
                    ->whereIn('status', ['Released', 'Active', 'Overdue', 'Restructured'])
                    ->first();
                if ($existingLoan && $existingLoan->legacy_loan_identity !== $row['identity']) {
                    $row['category'] = 'Loan already recorded';
                    $row['reasons'] = ['This member already has a different active loan in the system.'];
                } elseif ($existingLoan) {
                    $row['category'] = 'Ready';
                    $row['reasons'] = ['Monthly continuation of the existing imported loan.'];
                }
            }
        }

        return [
            'rows' => $rows,
            'summary' => [
                'total' => count($rows),
                'ready' => count(array_filter($rows, fn ($row) => $row['category'] === 'Ready')),
                'invalid' => count(array_filter($rows, fn ($row) => $row['category'] !== 'Ready')),
                'alreadyImported' => $alreadyImported,
            ],
        ];
    }

    public function commit(string $path, string $period, array $excludedRows, array $resolvedMatches, $user, ?string $originalFilename = null): array
    {
        $preview = $this->preview($path, $period);
        $loanType = LoanType::query()->whereRaw('LOWER(name) LIKE ?', ['%solidarity%cash%assistance%'])->firstOrFail();
        $created = 0;
        $skipped = 0;
        $errors = [];
        $batchRows = [];

        foreach ($preview['rows'] as $row) {
            if (isset($resolvedMatches[$row['key']])) {
                $resolvedMember = Member::query()->find($resolvedMatches[$row['key']]);
                if ($resolvedMember) {
                    $row['memberId'] = (string) $resolvedMember->id;
                    $row['memberName'] = $resolvedMember->full_name;
                    $existingLoan = Loan::query()
                        ->where('member_id', $resolvedMember->id)
                        ->whereIn('status', ['Released', 'Active', 'Overdue', 'Restructured'])
                        ->first();
                    $row['category'] = $existingLoan && $existingLoan->legacy_loan_identity !== $row['identity']
                        ? 'Loan already recorded'
                        : 'Ready';
                }
            }
            if ($row['category'] !== 'Ready' || in_array($row['key'], $excludedRows, true)) {
                $skipped++;
                if ($row['category'] === 'Loan already recorded') {
                    $errors[] = "{$row['sheet']} row {$row['rowNumber']} ({$row['name']}): loan was not recorded because this member already has an active loan in the system.";
                }
                $batchRows[] = [
                    'sheet' => $row['sheet'],
                    'row_number' => $row['rowNumber'],
                    'source_name' => $row['name'],
                    'member_id' => $row['memberId'] ?? null,
                    'loan_id' => null,
                    'principal' => $row['principal'],
                    'interest' => $row['interest'],
                    'principal_balance' => $row['principalBalance'],
                    'interest_balance' => $row['interestBalance'],
                    'status' => 'Skipped',
                    'reason' => $row['category'] === 'Loan already recorded'
                        ? 'This member already has a different active loan in the system.'
                        : (in_array($row['key'], $excludedRows, true) ? 'Excluded by the encoder.' : implode(' ', $row['reasons'])),
                ];

                continue;
            }

            $loanId = null;
            try {
                DB::transaction(function () use ($row, $period, $loanType, $user, &$loanId): void {
                    $start = Carbon::createFromFormat('Y-m', $row['loanStart'])->startOfMonth();
                    $importPeriod = $row['lastPaymentMonth'] ?: $period;
                    $asOf = Carbon::createFromFormat('Y-m', $importPeriod)->endOfMonth();
                    $existingLoan = Loan::query()
                        ->where('member_id', $row['memberId'])
                        ->where('legacy_loan_identity', $row['identity'])
                        ->lockForUpdate()
                        ->first();
                    if ($existingLoan) {
                        $this->postMonthlyContinuation($existingLoan, $row, $importPeriod, $asOf, $user);
                        $loanId = $existingLoan->id;

                        return;
                    }
                    $term = 36;
                    $total = round($row['principal'] + $row['interest'], 2);
                    $monthly = round($total / $term, 2);
                    $currentBalance = round($row['principalBalance'] + $row['interestBalance'], 2);

                    $loan = Loan::create([
                        'application_date' => $start,
                        'member_id' => $row['memberId'],
                        'loan_type_id' => $loanType->id,
                        'requested_amount' => $row['principal'],
                        'approved_amount' => $row['principal'],
                        'term_months' => $term,
                        'interest_rate' => $row['principal'] > 0 ? round(($row['interest'] / $row['principal'] / $term) * 100, 3) : 0,
                        'processing_fee' => 0,
                        'purpose' => 'Imported legacy Solidarity Cash Assistance Loan',
                        'payment_method' => 'Payroll Deduction',
                        'first_due_date' => $start->copy()->addMonth(),
                        'maturity_date' => $start->copy()->addMonths($term),
                        'principal' => $row['principal'],
                        'total_interest' => $row['interest'],
                        'net_proceeds' => $row['principal'],
                        'total_amount_payable' => $total,
                        'monthly_amortization' => $monthly,
                        'outstanding_balance' => $currentBalance,
                        'principal_balance' => $row['principalBalance'],
                        'interest_balance' => $row['interestBalance'],
                        'status' => $currentBalance <= 0 ? 'Fully Paid' : 'Active',
                        'release_date' => $start,
                        'actual_released_amount' => $row['principal'],
                        'release_method' => 'Legacy Import',
                        'release_remarks' => "Imported from {$row['sheet']} row {$row['rowNumber']}; balance as of {$importPeriod}.",
                        'created_by' => $user?->full_name,
                        'assigned_officer' => 'Pre-System Loan Officer',
                        'legacy_import_fingerprint' => $row['fingerprint'],
                        'legacy_loan_identity' => $row['identity'],
                        'legacy_source_name' => $row['name'],
                    ]);
                    $loan->update(['application_number' => 'GCGEA-LN-LEGACY-'.str_pad((string) $loan->id, 6, '0', STR_PAD_LEFT)]);
                    $loanId = $loan->id;

                    $historicalPrincipal = max(0, round($row['principal'] - $row['previousPrincipalBalance'], 2));
                    $historicalInterest = max(0, round($row['interest'] - $row['previousInterestBalance'], 2));
                    $currentPrincipal = max(0, round($row['previousPrincipalBalance'] - $row['principalBalance'], 2));
                    $currentInterest = max(0, round($row['previousInterestBalance'] - $row['interestBalance'], 2));
                    $paymentParts = [
                        [$asOf->copy()->startOfMonth()->subDay(), $historicalPrincipal, $historicalInterest, 'Cumulative payments before '.$asOf->format('F Y').'; prior months treated as paid.'],
                        [$asOf, $currentPrincipal, $currentInterest, 'Imported payment for '.$asOf->format('F Y').'.'],
                    ];

                    foreach ($paymentParts as [$paymentDate, $paidPrincipal, $paidInterest, $remarks]) {
                        if ($paidPrincipal + $paidInterest <= 0) {
                            continue;
                        }
                        $payment = LoanPayment::create([
                            'member_id' => $row['memberId'],
                            'loan_application_id' => $loan->id,
                            'payment_date' => $paymentDate,
                            'amount_paid' => $paidPrincipal + $paidInterest,
                            'principal_portion' => $paidPrincipal,
                            'interest_portion' => $paidInterest,
                            'penalty' => 0,
                            'payment_method' => 'Legacy Import',
                            'payroll_reference' => 'LEGACY-'.$importPeriod,
                            'official_receipt_number' => 'LEGACY-'.$importPeriod.'-'.$row['sheet'].'-'.$row['rowNumber'].'-'.$paymentDate->format('Ym'),
                            'received_by' => $user?->full_name,
                            'remarks' => $remarks,
                            'status' => 'Posted',
                        ]);
                        $payment->update(['payment_reference_number' => 'GCGEA-PAY-LEGACY-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)]);
                    }

                    for ($installment = 1; $installment <= $term; $installment++) {
                        $due = $start->copy()->addMonths($installment);
                        $isPast = $due->lte($asOf);
                        $principalPart = round($row['principal'] / $term, 2);
                        $interestPart = round($row['interest'] / $term, 2);
                        $loan->schedule()->create([
                            'installment_number' => $installment,
                            'due_date' => $due,
                            'beginning_balance' => max(0, round($total - (($installment - 1) * $monthly), 2)),
                            'principal' => $principalPart,
                            'interest' => $interestPart,
                            'penalty' => 0,
                            'amount_due' => $principalPart + $interestPart,
                            'amount_paid' => $isPast ? $principalPart + $interestPart : 0,
                            'remaining_balance' => max(0, round($total - ($installment * $monthly), 2)),
                            'status' => $isPast ? 'Paid' : 'Upcoming',
                        ]);
                    }
                });
                $created++;
                $batchRows[] = [
                    'sheet' => $row['sheet'],
                    'row_number' => $row['rowNumber'],
                    'source_name' => $row['name'],
                    'member_id' => $row['memberId'],
                    'loan_id' => $loanId,
                    'principal' => $row['principal'],
                    'interest' => $row['interest'],
                    'principal_balance' => $row['principalBalance'],
                    'interest_balance' => $row['interestBalance'],
                    'status' => 'Imported',
                    'reason' => null,
                ];
            } catch (\Throwable $exception) {
                $skipped++;
                $errors[] = "{$row['sheet']} row {$row['rowNumber']}: {$exception->getMessage()}";
                $batchRows[] = [
                    'sheet' => $row['sheet'],
                    'row_number' => $row['rowNumber'],
                    'source_name' => $row['name'],
                    'member_id' => $row['memberId'] ?? null,
                    'loan_id' => null,
                    'principal' => $row['principal'],
                    'interest' => $row['interest'],
                    'principal_balance' => $row['principalBalance'],
                    'interest_balance' => $row['interestBalance'],
                    'status' => 'Skipped',
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        $batch = LoanImportBatch::create([
            'token' => (string) Str::uuid(),
            'original_filename' => $originalFilename ?? basename($path),
            'balance_period' => $period,
            'total_rows' => count($preview['rows']),
            'created_count' => $created,
            'skipped_count' => $skipped,
            'errors' => $errors,
            'uploaded_by_user_id' => $user?->id,
            'committed_at' => now(),
        ]);
        $batch->rows()->createMany($batchRows);

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors, 'batchToken' => $batch->token];
    }

    private function readRows(string $path, string $period): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            $reader = new Csv;
            $reader->setDelimiter(',');
            $reader->setEnclosure('"');
            $reader->setInputEncoding('UTF-8');
            $reader->setSheetIndex(0);
        } else {
            $reader = IOFactory::createReaderForFile($path);
        }
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($path);
        $rows = [];

        foreach ($workbook->getWorksheetIterator() as $sheet) {
            $headers = array_map(fn ($cell) => trim((string) $cell), $sheet->rangeToArray('A1:O1', null, true, true, true)[1]);
            if (($headers['A'] ?? '') === 'member_name' || (($headers['A'] ?? '') === 'surname' && ($headers['D'] ?? '') === 'member_name')) {
                $compactLayout = ($headers['A'] ?? '') === 'member_name';
                $columns = $compactLayout
                    ? ['name' => 'A', 'start' => 'B', 'principal' => 'C', 'interest' => 'D', 'previousPrincipal' => 'E', 'previousInterest' => 'F', 'paymentPrincipal' => 'G', 'paymentInterest' => 'H', 'balancePrincipal' => 'I', 'balanceInterest' => 'J', 'period' => 'K']
                    : ['name' => 'D', 'start' => 'E', 'principal' => 'F', 'interest' => 'G', 'previousPrincipal' => 'H', 'previousInterest' => 'I', 'paymentPrincipal' => 'J', 'paymentInterest' => 'K', 'balancePrincipal' => 'L', 'balanceInterest' => 'M', 'period' => 'N'];

                for ($number = 2; $number <= $sheet->getHighestDataRow(); $number++) {
                    $values = $sheet->rangeToArray("A{$number}:N{$number}", null, true, true, true)[$number];
                    $principal = $this->money($values[$columns['principal']] ?? 0);
                    $interest = $this->money($values[$columns['interest']] ?? 0);
                    $name = trim((string) ($values[$columns['name']] ?? ''));
                    $loanStart = trim((string) ($values[$columns['start']] ?? ''));
                    if ($name === '' || $principal <= 0 || ! preg_match('/^(\d{4})-(\d{2})$/', $loanStart)) {
                        continue;
                    }

                    $previousPrincipal = max(0, $this->money($values[$columns['previousPrincipal']] ?? 0));
                    $previousInterest = max(0, $this->money($values[$columns['previousInterest']] ?? 0));
                    $recentPrincipalPayment = max(0, $this->money($values[$columns['paymentPrincipal']] ?? 0));
                    $recentInterestPayment = max(0, $this->money($values[$columns['paymentInterest']] ?? 0));
                    $principalBalance = $this->money($values[$columns['balancePrincipal']] ?? '');
                    $interestBalance = $this->money($values[$columns['balanceInterest']] ?? '');
                    if (($values[$columns['balancePrincipal']] ?? '') === '') {
                        $principalBalance = max(0, $previousPrincipal - $recentPrincipalPayment);
                    }
                    if (($values[$columns['balanceInterest']] ?? '') === '') {
                        $interestBalance = max(0, $previousInterest - $recentInterestPayment);
                    }

                    $balancePeriod = preg_match('/^\d{4}-\d{2}$/', trim((string) ($values[$columns['period']] ?? '')))
                        ? trim((string) $values[$columns['period']])
                        : $period;
                    $identity = $this->loanIdentity($name, $loanStart, $principal, $interest);
                    $rows[] = [
                        'key' => $sheet->getTitle().':'.$number,
                        'sheet' => $sheet->getTitle(),
                        'rowNumber' => $number,
                        'name' => $name,
                        'remarks' => 'Member Profile linked loan import',
                        'loanStart' => $loanStart,
                        'principal' => $principal,
                        'interest' => $interest,
                        'previousPrincipalBalance' => $previousPrincipal,
                        'previousInterestBalance' => $previousInterest,
                        'principalBalance' => max(0, $principalBalance),
                        'interestBalance' => max(0, $interestBalance),
                        'lastPaymentMonth' => $balancePeriod,
                        'currentPayment' => round($recentPrincipalPayment + $recentInterestPayment, 2),
                        'identity' => $identity,
                        'fingerprint' => $this->periodFingerprint($identity, $balancePeriod),
                    ];
                }

                continue;
            }

            if (! str_contains(strtolower($headers['D'] ?? ''), 'principal') || ! str_contains(strtolower($headers['E'] ?? ''), 'interest')) {
                continue;
            }

            for ($number = 2; $number <= $sheet->getHighestDataRow(); $number++) {
                $values = $sheet->rangeToArray("A{$number}:O{$number}", null, true, true, true)[$number];
                $principal = $this->money($values['D'] ?? null);
                $interest = $this->money($values['E'] ?? null);
                $name = trim((string) ($values['B'] ?? ''));
                if ($name === '' || $principal <= 0 || $interest < 0) {
                    continue;
                }
                if (! preg_match('/(\d{1,2})\s*\/\s*(\d{4})/', (string) ($values['C'] ?? ''), $match)) {
                    continue;
                }

                $loanStart = sprintf('%04d-%02d', (int) $match[2], (int) $match[1]);
                $identity = $this->loanIdentity($name, $loanStart, $principal, $interest);
                $rows[] = [
                    'key' => $sheet->getTitle().':'.$number,
                    'sheet' => $sheet->getTitle(),
                    'rowNumber' => $number,
                    'name' => $name,
                    'remarks' => trim((string) ($values['C'] ?? '')),
                    'loanStart' => $loanStart,
                    'principal' => $principal,
                    'interest' => $interest,
                    'previousPrincipalBalance' => max(0, $this->money($values['F'] ?? 0)),
                    'previousInterestBalance' => max(0, $this->money($values['G'] ?? 0)),
                    'principalBalance' => max(0, $this->money($values['M'] ?? $values['F'] ?? 0)),
                    'interestBalance' => max(0, $this->money($values['N'] ?? $values['G'] ?? 0)),
                    'lastPaymentMonth' => $period,
                    'currentPayment' => max(0, round($this->money($values['H'] ?? 0) + $this->money($values['I'] ?? 0), 2)),
                    'identity' => $identity,
                    'fingerprint' => $this->periodFingerprint($identity, $period),
                ];
            }
        }

        return $rows;
    }

    private function money(mixed $value): float
    {
        return round((float) preg_replace('/[^0-9.\-]/', '', (string) ($value ?? 0)), 2);
    }

    private function postMonthlyContinuation(Loan $loan, array $row, string $importPeriod, Carbon $asOf, $user): void
    {
        $latestPeriod = $loan->payments()
            ->where('payroll_reference', 'like', 'LEGACY-%')
            ->pluck('payroll_reference')
            ->map(fn ($reference) => str_replace('LEGACY-', '', (string) $reference))
            ->filter(fn ($period) => preg_match('/^\d{4}-\d{2}$/', $period))
            ->max();

        if ($latestPeriod && $importPeriod <= $latestPeriod) {
            throw new \RuntimeException("Balance month {$importPeriod} is not later than the last recorded month {$latestPeriod}.");
        }

        $principalBefore = round((float) $loan->principal_balance, 2);
        $interestBefore = round((float) $loan->interest_balance, 2);
        if (abs($principalBefore - $row['previousPrincipalBalance']) > 0.05
            || abs($interestBefore - $row['previousInterestBalance']) > 0.05) {
            throw new \RuntimeException(
                "The {$importPeriod} opening balances do not match the system's latest balances. ".
                'Expected principal '.number_format($principalBefore, 2).' and interest '.number_format($interestBefore, 2).'.'
            );
        }

        $principalPaid = max(0, round($row['previousPrincipalBalance'] - $row['principalBalance'], 2));
        $interestPaid = max(0, round($row['previousInterestBalance'] - $row['interestBalance'], 2));
        $payment = LoanPayment::create([
            'member_id' => $loan->member_id,
            'loan_application_id' => $loan->id,
            'payment_date' => $asOf,
            'amount_paid' => $principalPaid + $interestPaid,
            'principal_portion' => $principalPaid,
            'interest_portion' => $interestPaid,
            'penalty' => 0,
            'payment_method' => 'Legacy Import',
            'payroll_reference' => 'LEGACY-'.$importPeriod,
            'official_receipt_number' => 'LEGACY-'.$importPeriod.'-'.$row['sheet'].'-'.$row['rowNumber'],
            'received_by' => $user?->full_name,
            'remarks' => 'Imported monthly continuation for '.$asOf->format('F Y').'.',
            'status' => 'Posted',
        ]);
        $payment->update(['payment_reference_number' => 'GCGEA-PAY-LEGACY-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)]);

        $outstanding = round($row['principalBalance'] + $row['interestBalance'], 2);
        $loan->update([
            'principal_balance' => $row['principalBalance'],
            'interest_balance' => $row['interestBalance'],
            'outstanding_balance' => $outstanding,
            'status' => $outstanding <= 0 ? 'Fully Paid' : 'Active',
            'legacy_import_fingerprint' => $row['fingerprint'],
        ]);
        $loan->schedule()
            ->whereDate('due_date', '<=', $asOf)
            ->where('status', '!=', 'Paid')
            ->update(['status' => 'Paid', 'amount_paid' => DB::raw('amount_due')]);
    }

    private function normalizeName(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))));
    }

    private function candidateMembers(string $sourceName, $members): array
    {
        $source = $this->normalizeName($sourceName);

        return $members->map(function (Member $member) use ($source) {
            $candidate = $this->normalizeName($member->full_name);
            similar_text($source, $candidate, $score);

            return [
                'id' => (string) $member->id,
                'name' => $member->full_name,
                'memberNumber' => $member->member_number,
                'score' => round($score, 1),
            ];
        })->sortByDesc('score')
            ->take(8)
            ->values()
            ->all();
    }

    private function loanIdentity(string $name, string $loanStart, float $principal, float $interest): string
    {
        return hash('sha256', implode('|', [
            $this->normalizeName($name),
            $loanStart,
            number_format($principal, 2, '.', ''),
            number_format($interest, 2, '.', ''),
        ]));
    }

    private function periodFingerprint(string $identity, string $period): string
    {
        return hash('sha256', $identity.'|'.$period);
    }
}
