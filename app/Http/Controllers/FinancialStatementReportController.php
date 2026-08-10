<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class FinancialStatementReportController extends Controller
{
    public function show(Request $request)
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);

        return response()->json($this->document());
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403);
        $document = $this->validatedDocument($request);

        SystemSetting::query()->updateOrCreate(
            ['section' => 'financialStatement'],
            ['value' => $document, 'updated_by' => $request->user()->id]
        );

        return response()->json($this->document());
    }

    public function pdf(Request $request)
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403);
        $document = $this->validatedDocument($request);
        $branding = $this->branding();
        $pdf = Pdf::loadView('reports.financial-statement', [
            'document' => $document,
            'organization' => $this->organization(),
            'leftLogo' => $this->pdfLogo($branding['leftLogo']),
            'rightLogo' => $this->pdfLogo($branding['rightLogo']),
        ])->setPaper('letter', 'portrait');

        return $pdf->download("unaudited-financial-statement-{$document['year']}.pdf");
    }

    public function excel(Request $request)
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403);
        $document = $this->validatedDocument($request);
        $organization = $this->organization();
        $branding = $this->branding();
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Financial Statement');
        $sheet->setShowGridlines(false);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER)
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)->setFitToHeight(1);
        $sheet->getPageMargins()->setTop(.35)->setRight(.45)->setBottom(.45)->setLeft(.45);

        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setWidth(12);
        }
        foreach ([1, 2, 3, 4, 5, 7, 8, 10, 12, 18, 24, 28, 34] as $row) {
            $sheet->mergeCells("A{$row}:H{$row}");
        }

        $sheet->setCellValue('A1', mb_strtoupper($organization['organizationName']));
        $sheet->setCellValue('A2', '('.$organization['acronym'].')');
        $sheet->setCellValue('A3', $document['registrationLine']);
        $sheet->setCellValue('A4', $document['accreditationLine']);
        $sheet->setCellValue('A5', implode("\n", $document['affiliationLines']));
        $sheet->setCellValue('A7', 'Unaudited Financial Statement Disclaimer');
        $sheet->setCellValue('A8', "For the Year Ended December 31, {$document['year']}");
        foreach ($document['paragraphs'] as $index => $paragraph) {
            $row = [12, 18, 24, 28][$index];
            $sheet->setCellValue("A{$row}", $paragraph);
            $sheet->getRowDimension($row)->setRowHeight([75, 75, 48, 60][$index]);
        }

        $signatories = [
            [$organization['bookkeeperName'], 'Bookkeeper', 'A31:C31'],
            [$organization['auditorName'], 'Auditor', 'D31:F31'],
            [$organization['presidentName'], 'President', 'G31:H31'],
        ];
        foreach ($signatories as [$name, $role, $range]) {
            [$start] = explode(':', $range);
            $sheet->mergeCells($range)->setCellValue($start, mb_strtoupper($name)."\n{$role}");
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
            $sheet->getStyle($range)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle($range)->getFont()->setBold(true);
        }

        $sheet->getStyle('A1:H34')->getFont()->setName('Times New Roman')->setSize(11);
        $sheet->getStyle('A1:H8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        $sheet->getStyle('A1:H2')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A5:H5')->getFont()->setItalic(true);
        $sheet->getStyle('A7:H7')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A12:H28')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getPageSetup()->setPrintArea('A1:H34');

        $temporaryLogos = [];
        foreach ([[$branding['leftLogo'], 'A1'], [$branding['rightLogo'], 'H1']] as [$logo, $cell]) {
            [$path, $temporary] = $this->excelLogo($logo);
            if ($temporary) $temporaryLogos[] = $path;
            if (is_file($path)) {
                $drawing = new Drawing;
                $drawing->setPath($path)->setHeight(75)->setCoordinates($cell)->setWorksheet($sheet);
            }
        }
        $sheet->getRowDimension(1)->setRowHeight(58);

        $path = storage_path('app/'.Str::uuid().'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        foreach ($temporaryLogos as $logo) @unlink($logo);

        return response()->download($path, "unaudited-financial-statement-{$document['year']}.xlsx")->deleteFileAfterSend(true);
    }

    private function validatedDocument(Request $request): array
    {
        return $request->validate([
            'year' => ['required', 'integer', 'min:1997', 'max:9999'],
            'registrationLine' => ['required', 'string', 'max:250'],
            'accreditationLine' => ['required', 'string', 'max:250'],
            'affiliationLines' => ['required', 'array', 'size:3'],
            'affiliationLines.*' => ['required', 'string', 'max:250'],
            'paragraphs' => ['required', 'array', 'size:4'],
            'paragraphs.*' => ['required', 'string', 'max:3000'],
        ]);
    }

    private function document(): array
    {
        $defaults = $this->defaults();
        $stored = SystemSetting::query()->where('section', 'financialStatement')->first()?->value ?? [];
        return [...$defaults, ...$stored, 'organization' => $this->organization()];
    }

    private function defaults(): array
    {
        $year = now()->year - 1;
        return [
            'year' => $year,
            'registrationLine' => 'DOLE Registration No. 528, dated, October 2, 1997',
            'accreditationLine' => 'CSC Accreditation No. 166, dated, October 7, 1998',
            'affiliationLines' => [
                'Affiliated to Public Services Labor Independent Confederation',
                'An accredited training Institution on Public Sector Unionism',
                'Prescribed under CSC, MC. No.9, s. 1994',
            ],
            'paragraphs' => [
                "The financial statements for the year ended December 31, {$year}, have been prepared by the management of the Gingoog City Government Employees’ Association using internal accounting records. These statements reflect management’s best estimates and judgments. Please be advised that these financial statements are unaudited and have not been reviewed or verified by an independent external auditor. Accordingly, the figures presented are preliminary and may be subject to change.",
                'We are currently in the process of engaging an independent external auditor to conduct a full audit of these financial statements. The audit will include a review of financial records, internal controls, and compliance with applicable accounting standards, with the aim of providing an independent opinion on the financial statements.',
                'Once the audit is completed, a final audited financial report will be issued. This may include necessary adjustments based on the auditor’s findings.',
                'We thank all stakeholders for their understanding and advise that these unaudited statements be considered provisional until the official audited report becomes available.',
            ],
        ];
    }

    private function organization(): array
    {
        $stored = SystemSetting::query()->where('section', 'organization')->first()?->value ?? [];
        return [
            'organizationName' => $stored['organizationName'] ?? 'Gingoog City Government Employees’ Association',
            'acronym' => $stored['acronym'] ?? 'GCGEA',
            'bookkeeperName' => $stored['bookkeeperName'] ?? 'Arnel G. Ocampo',
            'auditorName' => $stored['auditorName'] ?? 'Richael E. Bilictao, CPA, MGM',
            'presidentName' => $stored['presidentName'] ?? 'Casimiro T. Guno',
        ];
    }

    private function branding(): array
    {
        $stored = SystemSetting::query()->where('section', 'reportTemplate')->first()?->value ?? [];
        return ['leftLogo' => $stored['leftLogo'] ?? '/logo.png', 'rightLogo' => $stored['rightLogo'] ?? '/city-seal-logo.png'];
    }

    private function pdfLogo(string $logo): string
    {
        return str_starts_with($logo, 'data:') ? $logo : public_path(ltrim($logo, '/'));
    }

    private function excelLogo(string $logo): array
    {
        if (! str_starts_with($logo, 'data:')) return [public_path(ltrim($logo, '/')), false];
        if (! preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/s', $logo, $matches)) return ['', false];
        $extension = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
        $path = storage_path('app/'.Str::uuid().'.'.$extension);
        file_put_contents($path, base64_decode($matches[2], true) ?: '');
        return [$path, true];
    }
}
