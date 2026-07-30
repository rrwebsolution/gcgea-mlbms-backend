<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportExportController extends Controller
{
    public function pdf(Request $request)
    {
        $data = $this->validatedReport($request);
        $template = $this->template($data['reportCategory'], 'pdf');
        $orientation = $template['body']['orientation'] === 'auto'
            ? (count($data['headers']) > 7 ? 'landscape' : 'portrait')
            : $template['body']['orientation'];
        $pdf = Pdf::loadView('reports.template', [
            ...$data,
            'template' => $template,
            'leftLogo' => $this->pdfLogo($template['leftLogo']),
            'rightLogo' => $this->pdfLogo($template['rightLogo']),
        ])->setPaper($template['body']['paperSize'], $orientation);

        return $pdf->download($this->filename($data['title']).'.pdf');
    }

    public function excel(Request $request)
    {
        $data = $this->validatedReport($request);
        $template = $this->template($data['reportCategory'], 'excel');
        $body = $template['body'];
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr(preg_replace('/[\\\\\\/?*\\[\\]:]/', '', $data['title']) ?: 'Report', 0, 31));
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(count($data['headers']), 1));
        $pageSetup = $sheet->getPageSetup();
        $pageSetup->setPaperSize(match ($body['paperSize']) {
            'letter' => PageSetup::PAPERSIZE_LETTER,
            'legal' => PageSetup::PAPERSIZE_LEGAL,
            default => PageSetup::PAPERSIZE_A4,
        });
        $pageSetup->setOrientation(
            $body['orientation'] === 'landscape' || ($body['orientation'] === 'auto' && count($data['headers']) > 7)
                ? PageSetup::ORIENTATION_LANDSCAPE
                : PageSetup::ORIENTATION_PORTRAIT
        );
        $pageSetup->setFitToWidth(1)->setFitToHeight(0);
        $sheet->setShowGridlines(true);

        $sheet->mergeCells("A1:{$lastColumn}1")->setCellValue('A1', $template['countryLine']);
        $sheet->mergeCells("A2:{$lastColumn}2")->setCellValue('A2', $template['organizationLine']);
        $sheet->mergeCells("A3:{$lastColumn}3")->setCellValue('A3', $template['acronymLine']);
        $sheet->mergeCells("A4:{$lastColumn}4")->setCellValue('A4', $template['addressLine']);
        if ($template['showGeneratedDate']) {
            $sheet->mergeCells("A5:{$lastColumn}5")->setCellValue('A5', 'Generated: '.now()->format('F j, Y g:i A'));
            $sheet->getStyle("A5:{$lastColumn}5")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("A5:{$lastColumn}5")->getFont()->setSize(8)->setItalic(true)->getColor()->setARGB('FF64748B');
        }
        $sheet->mergeCells("A6:{$lastColumn}6")->setCellValue('A6', $data['title']);
        if ($body['captionText'] !== '') {
            $sheet->mergeCells("A7:{$lastColumn}7")->setCellValue('A7', $body['captionText']);
            $this->applyTextStyle($sheet, "A7:{$lastColumn}7", $body['captionStyle']);
        }
        $sheet->getStyle("A1:{$lastColumn}6")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A2:{$lastColumn}3")->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle("A6:{$lastColumn}6")->getFont()->setBold(true)->setSize(13)->getColor()->setARGB('FF'.ltrim($body['primaryColor'], '#'));
        $sheet->getStyle("A6:{$lastColumn}6")->getAlignment()->setHorizontal(
            $body['titleAlignment'] === 'left' ? Alignment::HORIZONTAL_LEFT : Alignment::HORIZONTAL_CENTER
        );
        $temporaryLogos = [];
        foreach ([
            [$template['leftLogo'], 'A1'],
            [$template['rightLogo'], "{$lastColumn}1"],
        ] as [$logo, $coordinate]) {
            [$logoPath, $temporary] = $this->excelLogo($logo);
            if ($temporary) {
                $temporaryLogos[] = $logoPath;
            }
            if (is_file($logoPath)) {
                $drawing = new Drawing;
                $drawing->setPath($logoPath);
                $drawing->setHeight(52);
                $drawing->setCoordinates($coordinate);
                $drawing->setWorksheet($sheet);
            }
        }
        $sheet->getRowDimension(1)->setRowHeight(42);

        foreach ($data['headers'] as $column => $header) {
            $sheet->setCellValue([$column + 1, 8], $header);
        }
        foreach ($data['rows'] as $rowIndex => $row) {
            foreach ($data['headers'] as $column => $_header) {
                $sheet->setCellValue([$column + 1, $rowIndex + 9], $row[$column] ?? '');
            }
        }

        $lastRow = max(8, count($data['rows']) + 8);
        $sheet->getStyle("A8:{$lastColumn}8")->getFont()
            ->setName($body['bodyFontFamily'])
            ->setSize($body['bodyFontSize'])
            ->setBold(true)
            ->getColor()->setARGB('FF'.ltrim($body['primaryColor'], '#'));
        $sheet->getStyle("A8:{$lastColumn}8")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF'.ltrim($body['headerBackground'], '#'));
        if ($lastRow >= 9) {
            $sheet->getStyle("A9:{$lastColumn}{$lastRow}")->getFont()
            ->setName($body['bodyFontFamily'])
            ->setSize($body['bodyFontSize'])
            ->setBold($body['bodyFontWeight'] === 'bold')
            ->setItalic($body['bodyFontStyle'] === 'italic')
            ->setUnderline($body['bodyTextDecoration'] === 'underline' ? Font::UNDERLINE_SINGLE : Font::UNDERLINE_NONE)
            ->getColor()->setARGB('FF'.ltrim($body['bodyTextColor'], '#'));
        }
        if ($body['showBorders']) {
            $sheet->getStyle("A8:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
        if ($body['stripedRows']) {
            for ($row = 10; $row <= $lastRow; $row += 2) {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFF8FAFC');
            }
        }
        if ($body['noteText'] !== '') {
            $noteRow = $lastRow + 2;
            $sheet->mergeCells("A{$noteRow}:{$lastColumn}{$noteRow}")->setCellValue("A{$noteRow}", 'Note: '.$body['noteText']);
            $this->applyTextStyle($sheet, "A{$noteRow}:{$lastColumn}{$noteRow}", $body['noteStyle']);
            $sheet->getStyle("A{$noteRow}:{$lastColumn}{$noteRow}")->getAlignment()->setWrapText(true);
        }
        $sheet->getStyle("A8:{$lastColumn}{$lastRow}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setHorizontal(match ($body['bodyTextAlignment']) {
                'center' => Alignment::HORIZONTAL_CENTER,
                'right' => Alignment::HORIZONTAL_RIGHT,
                default => Alignment::HORIZONTAL_LEFT,
            })
            ->setWrapText(true);
        $sheet->freezePane('A9');
        $sheet->setAutoFilter("A8:{$lastColumn}8");
        $printLastRow = $body['noteText'] !== '' ? $lastRow + 2 : $lastRow;
        $pageSetup->setPrintArea("A1:{$lastColumn}{$printLastRow}");
        $sheet->getPageMargins()->setTop(0.35)->setRight(0.3)->setBottom(0.35)->setLeft(0.3);
        $sheet->getHeaderFooter()->setOddFooter('&CPage &P of &N');
        foreach (range(1, count($data['headers'])) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $path = storage_path('app/'.Str::uuid().'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        foreach ($temporaryLogos as $temporaryLogo) {
            @unlink($temporaryLogo);
        }

        return response()->download($path, $this->filename($data['title']).'.xlsx')->deleteFileAfterSend(true);
    }

    private function validatedReport(Request $request): array
    {
        if (! $request->user()->hasPermission('reports.export')) {
            abort(403, "You don't have permission to export reports.");
        }

        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'headers' => ['required', 'array', 'min:1', 'max:30'],
            'headers.*' => ['string', 'max:200'],
            'rows' => ['required', 'array', 'max:10000'],
            'rows.*' => ['array', 'max:30'],
            'rows.*.*' => ['nullable', 'string', 'max:5000'],
            'reportCategory' => ['required', 'in:member,contribution,loan,benefit,financial'],
        ]);
    }

    private function template(string $category, string $format): array
    {
        $defaults = [
            'countryLine' => 'Republic of the Philippines',
            'organizationLine' => 'Gingoog City Government Employees Association',
            'acronymLine' => '(GCGEA)',
            'addressLine' => 'City Hall Compound, Gingoog City',
            'leftLogo' => '/logo.png',
            'rightLogo' => '/city-seal-logo.png',
            'showGeneratedDate' => true,
            'categoryTemplates' => [],
        ];
        $bodyDefaults = [
            'preset' => 'classic',
            'primaryColor' => '#1E3A8A',
            'headerBackground' => '#E8EDF3',
            'bodyFontSize' => 9,
            'bodyFontFamily' => 'Arial',
            'bodyFontWeight' => 'normal',
            'bodyFontStyle' => 'normal',
            'bodyTextDecoration' => 'none',
            'bodyTextColor' => '#111111',
            'bodyTextAlignment' => 'left',
            'stripedRows' => false,
            'showBorders' => true,
            'titleAlignment' => 'center',
            'orientation' => 'auto',
            'paperSize' => 'a4',
            'captionText' => '',
            'noteText' => '',
            'captionStyle' => [
                'fontFamily' => 'Arial', 'fontSize' => 9, 'fontWeight' => 'normal',
                'fontStyle' => 'italic', 'textDecoration' => 'none',
                'textColor' => '#475569', 'textAlignment' => 'center',
            ],
            'noteStyle' => [
                'fontFamily' => 'Arial', 'fontSize' => 9, 'fontWeight' => 'normal',
                'fontStyle' => 'italic', 'textDecoration' => 'none',
                'textColor' => '#475569', 'textAlignment' => 'left',
            ],
        ];

        $stored = SystemSetting::where('section', 'reportTemplate')->first()?->value ?? [];
        $categoryTemplate = $stored['categoryTemplates'][$category] ?? [];
        if ($format === 'excel') {
            $categoryTemplate = [
                ...$categoryTemplate,
                ...($categoryTemplate['excelTemplate'] ?? []),
            ];
        }

        $body = [...$bodyDefaults, ...$categoryTemplate];
        $body['captionStyle'] = [...$bodyDefaults['captionStyle'], ...($categoryTemplate['captionStyle'] ?? [])];
        $body['noteStyle'] = [...$bodyDefaults['noteStyle'], ...($categoryTemplate['noteStyle'] ?? [])];

        return [
            ...$defaults,
            ...$stored,
            'body' => $body,
        ];
    }

    private function applyTextStyle($sheet, string $range, array $style): void
    {
        $sheet->getStyle($range)->getFont()
            ->setName($style['fontFamily'])
            ->setSize($style['fontSize'])
            ->setBold($style['fontWeight'] === 'bold')
            ->setItalic($style['fontStyle'] === 'italic')
            ->setUnderline($style['textDecoration'] === 'underline' ? Font::UNDERLINE_SINGLE : Font::UNDERLINE_NONE)
            ->getColor()->setARGB('FF'.ltrim($style['textColor'], '#'));
        $sheet->getStyle($range)->getAlignment()->setHorizontal(match ($style['textAlignment']) {
            'center' => Alignment::HORIZONTAL_CENTER,
            'right' => Alignment::HORIZONTAL_RIGHT,
            default => Alignment::HORIZONTAL_LEFT,
        });
    }

    private function pdfLogo(string $logo): string
    {
        return str_starts_with($logo, 'data:') ? $logo : public_path(ltrim($logo, '/'));
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function excelLogo(string $logo): array
    {
        if (! str_starts_with($logo, 'data:')) {
            return [public_path(ltrim($logo, '/')), false];
        }

        if (! preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/s', $logo, $matches)) {
            return ['', false];
        }

        $extension = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
        $path = storage_path('app/'.Str::uuid().'.'.$extension);
        file_put_contents($path, base64_decode($matches[2], true) ?: '');

        return [$path, true];
    }

    private function filename(string $title): string
    {
        return Str::slug($title).'-'.now()->format('Y-m-d');
    }
}
