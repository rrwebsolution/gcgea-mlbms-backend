<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class FinancialConditionStatementController extends Controller
{
    public function show(Request $request)
    {
        abort_unless($request->user()->hasPermission('reports.financial'), 403);

        $years = $this->yearsWithPostedTransactions();

        return response()->json([
            'reports' => $years->map(fn (int $year) => $this->statement($year)),
        ]);
    }

    /** Financial statements are system-generated; manual balance updates are not accepted. */
    public function update(Request $request)
    {
        abort(405, 'Financial statements are generated from posted system transactions.');
    }

    public function pdf(Request $request, int $year)
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403);
        $report = $this->reportForExport($year);
        $report['organization']['leftLogo'] = $this->pdfLogo($report['organization']['leftLogo']);
        $report['organization']['rightLogo'] = $this->pdfLogo($report['organization']['rightLogo']);

        return Pdf::loadView('reports.financial-condition', ['report' => $report, 'rows' => $this->statementRows($report['accounts'])])
            ->setPaper('letter', 'portrait')->download("statement-of-financial-condition-{$year}.pdf");
    }

    public function excel(Request $request, int $year)
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403);
        $report = $this->reportForExport($year);
        $rows = $this->statementRows($report['accounts']);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Financial Condition {$year}")->setShowGridlines(false);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER)->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setFitToWidth(1)->setFitToHeight(1);
        $sheet->getPageMargins()->setTop(.35)->setRight(.45)->setBottom(.45)->setLeft(.45);
        $sheet->getColumnDimension('A')->setWidth(4);
        $sheet->getColumnDimension('B')->setWidth(62);
        $sheet->getColumnDimension('C')->setWidth(20);
        foreach (range(1, 8) as $row) {
            $sheet->mergeCells("A{$row}:C{$row}");
        }
        $organization = $report['organization'];
        $sheet->setCellValue('A1', mb_strtoupper($organization['name']))->setCellValue('A2', '('.$organization['acronym'].')')
            ->setCellValue('A3', 'DOLE Registration No. 528, dated, October 2, 1997')->setCellValue('A4', 'CSC Accreditation No. 166, dated, October 7, 1998')
            ->setCellValue('A5', 'Affiliated to Public Services Labor Independent Confederation')->setCellValue('A6', 'An accredited training Institution on Public Sector Unionism')
            ->setCellValue('A7', 'Prescribed under CSC, MC. No.9, s. 1994')->setCellValue('A8', 'STATEMENT OF FINANCIAL CONDITION')
            ->mergeCells('A9:C9')->setCellValue('A9', "as of December 31, {$year}");
        $sheet->getStyle('A1:C9')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A1:C2')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A5:C7')->getFont()->setItalic(true);
        $sheet->getStyle('A8:C9')->getFont()->setBold(true);
        $excelRow = 11;
        foreach ($rows as $row) {
            $sheet->setCellValue("B{$excelRow}", $row['label']);
            if ($row['amount'] !== null) {
                $sheet->setCellValue("C{$excelRow}", $row['amount']);
            }
            $sheet->getStyle("B{$excelRow}")->getAlignment()->setIndent($row['level']);
            $sheet->getStyle("C{$excelRow}")->getNumberFormat()->setFormatCode('₱#,##0.00;[Red](₱#,##0.00)');
            if ($row['strong']) {
                $sheet->getStyle("B{$excelRow}:C{$excelRow}")->getFont()->setBold(true);
            }
            if ($row['total']) {
                $sheet->getStyle("B{$excelRow}:C{$excelRow}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
            }
            $excelRow++;
        }
        $sheet->getStyle("A1:C{$excelRow}")->getFont()->setName('Arial')->setSize(10);
        $sheet->getStyle('A1:C2')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A8:C9')->getFont()->setBold(true);
        $sheet->getStyle("C11:C{$excelRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getPageSetup()->setPrintArea("A1:C{$excelRow}");
        $sheet->getHeaderFooter()->setOddFooter('&CPage &P of &N');
        $temporaryLogos = [];
        foreach ([[$organization['leftLogo'], 'A1'], [$organization['rightLogo'], 'C1']] as [$logo, $cell]) {
            [$path, $temporary] = $this->excelLogo($logo);
            if ($temporary) {
                $temporaryLogos[] = $path;
            }
            if (is_file($path)) {
                (new Drawing)->setPath($path)->setHeight(65)->setCoordinates($cell)->setWorksheet($sheet);
            }
        }
        $sheet->getRowDimension(1)->setRowHeight(50);
        $path = storage_path('app/'.Str::uuid().'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        foreach ($temporaryLogos as $logo) {
            @unlink($logo);
        }

        return response()->download($path, "statement-of-financial-condition-{$year}.xlsx")->deleteFileAfterSend(true);
    }

    private function statement(int $year): array
    {
        $end = sprintf('%d-12-31', $year);
        $postedContributions = DB::table('contributions')->where('status', 'Posted')->whereDate('payment_date', '<=', $end);
        $postedPayments = DB::table('loan_payments')->where('status', 'Posted')->whereDate('payment_date', '<=', $end);

        $contributions = (float) (clone $postedContributions)->sum('amount');
        $principalCollected = (float) (clone $postedPayments)->sum('principal_portion');
        $interestCollected = (float) (clone $postedPayments)->sum('interest_portion');
        $releasedLoans = DB::table('loans')->whereNotNull('release_date')->whereDate('release_date', '<=', $end)
            ->whereNotIn('status', ['Draft', 'Submitted', 'Rejected', 'Cancelled']);
        $loanPrincipal = (float) (clone $releasedLoans)->sum('principal');
        $loanCashReleased = (float) (clone $releasedLoans)->sum('actual_released_amount');
        $benefitsReleased = (float) DB::table('benefit_applications')->whereIn('status', ['Released', 'Completed'])
            ->whereDate('release_date', '<=', $end)->sum('actual_released_amount');
        $disbursements = (float) DB::table('disbursements')->where('status', 'Paid')->whereDate('disbursement_date', '<=', $end)->sum('amount');

        $pabaonDeductions = (float) DB::table('deductions as d')->join('deduction_types as dt', 'dt.id', '=', 'd.deduction_type_id')
            ->where('d.status', 'Posted')->where('dt.code', 'pabaon')->whereDate('d.payment_date', '<=', $end)->sum('d.amount');
        $principalReceivable = max(0, $loanPrincipal - $principalCollected);
        $cash = max(0, $contributions + $pabaonDeductions + $principalCollected + $interestCollected - $loanCashReleased - $benefitsReleased - $disbursements);
        $fundBalances = DB::table('contribution_funds as f')->leftJoin('contribution_fund_allocations as a', 'a.fund_id', '=', 'f.id')
            ->leftJoin('contributions as c', function ($join) use ($end) {
                $join->on('c.id', '=', 'a.contribution_id')->where('c.status', 'Posted')->whereDate('c.payment_date', '<=', $end);
            })
            ->select('f.id', 'f.fund_name', DB::raw('COALESCE(SUM(CASE WHEN c.id IS NOT NULL THEN a.allocated_amount ELSE 0 END),0) as credits'))
            ->groupBy('f.id', 'f.fund_name')->get()->mapWithKeys(function ($fund) use ($end) {
                $debits = (float) DB::table('fund_transactions')->where('fund_id', $fund->id)->where('transaction_type', 'Debit')->whereDate('created_at', '<=', $end.' 23:59:59')->sum('amount');

                return [$fund->fund_name => max(0, (float) $fund->credits - $debits)];
            });

        $pabaonCollected = (float) (clone $postedContributions)->where('contribution_type', 'Cash Pabaon')->sum('amount') + $pabaonDeductions;
        $pabaonDebits = (float) DB::table('fund_transactions')->where('fund_name_snapshot', 'Cash Pabaon Fund')->where('transaction_type', 'Debit')->whereDate('created_at', '<=', $end.' 23:59:59')->sum('amount');
        $funds = [
            'loanInvestmentFund' => (float) ($fundBalances['Loan Investment Fund'] ?? 0),
            'membershipCoreServicesFund' => (float) ($fundBalances['Membership Core Services Fund'] ?? 0),
            'operationalFund' => (float) ($fundBalances['Operational Fund'] ?? 0),
            'pabaonMortuaryFund' => max(0, $pabaonCollected - $pabaonDebits),
            'membershipFeeFund' => (float) ($fundBalances['Membership Fee Fund'] ?? 0),
        ];
        $liabilities = array_sum($funds);
        $totalAssets = $cash + $principalReceivable;

        return [
            'id' => 'financial-condition-'.$year,
            'fiscalYear' => $year,
            'asOfDate' => $end,
            'generatedAt' => now()->toIso8601String(),
            'status' => 'System Generated',
            'accounts' => [
                'cashInBank' => round($cash, 2), 'solidarityReceivables' => round($principalReceivable, 2),
                'doubtfulAccountsAllowance' => 0, 'officeEquipment' => 0, 'accumulatedDepreciation' => 0,
                ...array_map(fn ($value) => round($value, 2), $funds),
                'dueToPsLink' => 0, 'insurancePremiumPayables' => 0,
                'equity' => round($totalAssets - $liabilities, 2),
            ],
            'organization' => $this->organization(),
        ];
    }

    private function yearsWithPostedTransactions(): Collection
    {
        $sources = [
            DB::table('contributions')->where('status', 'Posted')->pluck('payment_date'),
            DB::table('deductions')->where('status', 'Posted')->pluck('payment_date'),
            DB::table('loan_payments')->where('status', 'Posted')->pluck('payment_date'),
            DB::table('loans')->whereNotNull('release_date')->whereNotIn('status', ['Draft', 'Submitted', 'Rejected', 'Cancelled'])->pluck('release_date'),
            DB::table('benefit_applications')->whereIn('status', ['Released', 'Completed'])->whereNotNull('release_date')->pluck('release_date'),
            DB::table('disbursements')->where('status', 'Paid')->pluck('disbursement_date'),
        ];

        return collect($sources)->flatten()->filter()->map(fn ($date) => (int) substr((string) $date, 0, 4))
            ->filter()->unique()->sortDesc()->values();
    }

    private function organization(): array
    {
        $organization = SystemSetting::query()->where('section', 'organization')->value('value') ?? [];
        $branding = SystemSetting::query()->where('section', 'reportTemplate')->value('value') ?? [];

        return ['name' => $organization['organizationName'] ?? 'Gingoog City Government Employees’ Association', 'acronym' => $organization['acronym'] ?? 'GCGEA', 'leftLogo' => $branding['leftLogo'] ?? '/logo.png', 'rightLogo' => $branding['rightLogo'] ?? '/city-seal-logo.png'];
    }

    private function reportForExport(int $year): array
    {
        abort_unless($this->yearsWithPostedTransactions()->contains($year), 404, 'No posted financial transactions exist for this fiscal year.');

        return $this->statement($year);
    }

    private function statementRows(array $a): array
    {
        $receivables = $a['solidarityReceivables'] - $a['doubtfulAccountsAllowance'];
        $currentAssets = $a['cashInBank'] + $receivables;
        $netEquipment = $a['officeEquipment'] - $a['accumulatedDepreciation'];
        $totalAssets = $currentAssets + $netEquipment;
        $funds = $a['loanInvestmentFund'] + $a['membershipCoreServicesFund'] + $a['operationalFund'] + $a['pabaonMortuaryFund'] + $a['membershipFeeFund'];
        $payables = $a['dueToPsLink'] + $a['insurancePremiumPayables'];
        $liabilities = $funds + $payables;

        $row = fn (string $label, ?float $amount = null, int $level = 0, bool $strong = false, bool $total = false) => compact('label', 'amount', 'level', 'strong', 'total');

        return [
            $row('ASSETS', null, 0, true), $row('Current Assets', null, 1, true), $row('Cash', null, 2, true), $row('Cash in Bank - Current', $a['cashInBank'], 3),
            $row('Accounts and Other Receivables', null, 2, true), $row('Solidarity Cash Assistance Receivables', $a['solidarityReceivables'], 3),
            $row('Allowance for Doubtful Accounts', -$a['doubtfulAccountsAllowance'], 3), $row('Net Receivables', $receivables, 2, true),
            $row('Total Current Assets', $currentAssets, 1, true), $row('Non-current Assets', null, 1, true), $row('Office Equipment', $a['officeEquipment'], 3),
            $row('Less: Accumulated Depreciation', -$a['accumulatedDepreciation'], 3), $row('Net Book Value', $netEquipment, 2, true), $row('TOTAL ASSETS', $totalAssets, 0, true, true),
            $row('LIABILITIES AND MEMBERS’ EQUITY', null, 0, true), $row('LIABILITIES', null, 1, true), $row('Current Liabilities', null, 2, true),
            $row('Loan Investment Fund', $a['loanInvestmentFund'], 3), $row('Membership Core Services Fund', $a['membershipCoreServicesFund'], 3),
            $row('Operational Fund', $a['operationalFund'], 3), $row('Pabaon and Mortuary Assistance Fund', $a['pabaonMortuaryFund'], 3),
            $row('Membership Fee Fund', $a['membershipFeeFund'], 3), $row('Total Funds', $funds, 2, true), $row('Accounts and Other Payables', null, 2, true),
            $row('Due to PS Link', $a['dueToPsLink'], 3), $row('Insurance Premium Payables', $a['insurancePremiumPayables'], 3), $row('Total Payables', $payables, 2, true),
            $row('TOTAL LIABILITIES', $liabilities, 1, true), $row('EQUITY', $a['equity'], 1, true), $row('TOTAL LIABILITIES AND EQUITY', $liabilities + $a['equity'], 0, true, true),
        ];
    }

    private function pdfLogo(string $logo): string
    {
        return str_starts_with($logo, 'data:') ? $logo : public_path(ltrim($logo, '/'));
    }

    private function excelLogo(string $logo): array
    {
        if (! str_starts_with($logo, 'data:')) {
            return [public_path(ltrim($logo, '/')), false];
        }
        if (! preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/s', $logo, $matches)) {
            return ['', false];
        }
        $path = storage_path('app/'.Str::uuid().'.'.($matches[1] === 'jpeg' ? 'jpg' : $matches[1]));
        file_put_contents($path, base64_decode($matches[2], true) ?: '');

        return [$path, true];
    }
}
