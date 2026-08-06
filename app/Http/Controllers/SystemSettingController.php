<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SystemSettingController extends Controller
{
    private const SECTIONS = [
        'general', 'organization', 'numbering', 'loan', 'contribution',
        'benefit', 'notification', 'security', 'reportTemplate', 'backup', 'appearance',
    ];

    public function index(Request $request)
    {
        return response()->json(
            SystemSetting::query()->get()->mapWithKeys(
                fn (SystemSetting $setting) => [$setting->section => $setting->value]
            )
        );
    }

    public function appearance()
    {
        return response()->json(
            SystemSetting::where('section', 'appearance')->first()?->value ?? []
        );
    }

    /** Database size (data + indexes) against the hosting plan's fixed storage cap, for the sidebar's usage indicator. */
    public function storageUsage()
    {
        $totalBytes = 320 * 1024 * 1024 * 1024;

        $usedBytes = (int) DB::selectOne('select pg_database_size(current_database()) as bytes')->bytes;

        return response()->json([
            'usedBytes' => $usedBytes,
            'totalBytes' => $totalBytes,
        ]);
    }

    public function update(Request $request, string $section)
    {
        if (! in_array($section, self::SECTIONS, true)) {
            abort(404);
        }
        $permissionSection = $section === 'reportTemplate' ? 'organization' : $section;
        if (! $request->user()->hasPermission("settings.{$permissionSection}")
            && ! $request->user()->hasPermission('settings.update')) {
            abort(403, "You don't have permission to update {$section} settings.");
        }

        $data = $request->validate([
            'value' => ['required', 'array'],
            'section' => ['sometimes', Rule::in(self::SECTIONS)],
        ]);

        if ($section === 'reportTemplate') {
            $data['value'] = $this->mergeReportTemplateDefaults($data['value']);
            $request->merge(['value' => $data['value']]);
        }

        if ($section === 'general') {
            $request->validate([
                'value.systemName' => ['required', 'string', 'max:150'],
                'value.systemShortName' => ['required', 'string', 'max:50'],
                'value.defaultLanguage' => ['required', Rule::in(['English', 'Bisaya', 'Tagalog'])],
                'value.timeZone' => ['required', Rule::in(['Asia/Manila', 'Asia/Singapore', 'UTC'])],
                'value.dateFormat' => ['required', Rule::in(['MMMM d, yyyy', 'MMM d, yyyy', 'MM/dd/yyyy', 'dd/MM/yyyy'])],
                'value.currency' => ['required', Rule::in(['PHP', 'USD'])],
                'value.fiscalYearStart' => ['required', Rule::in([
                    'January', 'February', 'March', 'April', 'May', 'June',
                    'July', 'August', 'September', 'October', 'November', 'December',
                ])],
                'value.recordsPerPage' => ['required', 'integer', Rule::in([10, 25, 50, 100])],
                'value.membershipRegistrationFee' => ['required', 'numeric', 'min:0', 'max:100000'],
                'value.maintenanceMode' => ['required', 'boolean'],
                'value.enableAlertTranslations' => ['required', 'boolean'],
            ]);
        }

        if ($section === 'organization') {
            $request->validate([
                'value.organizationName' => ['required', 'string', 'max:200'],
                'value.acronym' => ['required', 'string', 'max:30'],
                'value.address' => ['required', 'string', 'max:500'],
                'value.contactNumber' => ['required', 'string', 'max:50'],
                'value.email' => ['required', 'email', 'max:150'],
                'value.website' => ['nullable', 'string', 'max:200'],
                'value.logoFileName' => ['nullable', 'string', 'max:255'],
                'value.citySealFileName' => ['nullable', 'string', 'max:255'],
                'value.authorizedSignatoryName' => ['required', 'string', 'max:150'],
                'value.authorizedSignatoryPosition' => ['required', 'string', 'max:150'],
                'value.treasurerName' => ['required', 'string', 'max:150'],
                'value.presidentName' => ['required', 'string', 'max:150'],
            ]);
        }

        if ($section === 'numbering') {
            foreach (['member', 'loan', 'loanPayment', 'officialReceipt', 'contribution', 'benefit', 'benefitRelease'] as $type) {
                $request->validate([
                    "value.{$type}" => ['required', 'array'],
                    "value.{$type}.prefix" => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9]+(?:[._-][A-Za-z0-9]+)*$/'],
                    "value.{$type}.includeYear" => ['required', 'boolean'],
                    "value.{$type}.yearFormat" => ['required', Rule::in(['YYYY', 'YY'])],
                    "value.{$type}.separator" => ['required', 'string', 'max:3', 'regex:/^[._\/-]+$/'],
                    "value.{$type}.sequenceLength" => ['required', 'integer', 'min:3', 'max:12'],
                    "value.{$type}.startingNumber" => ['required', 'integer', 'min:0', 'max:999999999'],
                ]);
            }
        }

        if ($section === 'benefit') {
            $request->validate([
                'value.requireApproval' => ['required', 'boolean'],
                'value.requireReleaseConfirmation' => ['required', 'boolean'],
                'value.allowEligibilityOverride' => ['required', 'boolean'],
                'value.defaultApprovalLimit' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
                'value.defaultFrequencyLimit' => ['required', 'string', 'max:100'],
                'value.requireSupportingDocuments' => ['required', 'boolean'],
                'value.allowMultiplePendingApplications' => ['required', 'boolean'],
                'value.requireRetiredStatusForRetirementBenefit' => ['required', 'boolean'],
                'value.benefitYearResetMonth' => ['required', Rule::in([
                    'January', 'February', 'March', 'April', 'May', 'June',
                    'July', 'August', 'September', 'October', 'November', 'December',
                ])],
                'value.independentSpousalBenefitRights' => ['required', 'boolean'],
            ]);
        }

        if ($section === 'notification') {
            $request->validate([
                'value.inAppNotifications' => ['required', 'boolean'],
                'value.emailNotifications' => ['required', 'boolean'],
                'value.smsNotifications' => ['required', 'boolean'],
                'value.loanApprovalAlerts' => ['required', 'boolean'],
                'value.loanDueDateAlerts' => ['required', 'boolean'],
                'value.overdueLoanAlerts' => ['required', 'boolean'],
                'value.benefitApprovalAlerts' => ['required', 'boolean'],
                'value.contributionImportAlerts' => ['required', 'boolean'],
                'value.incompleteProfileAlerts' => ['required', 'boolean'],
                'value.userAccountAlerts' => ['required', 'boolean'],
            ]);
        }

        if ($section === 'security') {
            $request->validate([
                'value.minimumPasswordLength' => ['required', 'integer', 'min:8', 'max:128'],
                'value.requireUppercase' => ['required', 'boolean'],
                'value.requireLowercase' => ['required', 'boolean'],
                'value.requireNumber' => ['required', 'boolean'],
                'value.requireSpecialCharacter' => ['required', 'boolean'],
                'value.sessionTimeoutMinutes' => ['required', 'integer', 'min:5', 'max:1440'],
                'value.maximumLoginAttempts' => ['required', 'integer', 'min:1', 'max:20'],
                'value.lockoutDurationMinutes' => ['required', 'integer', 'min:1', 'max:1440'],
                'value.requirePasswordChangeOnFirstLogin' => ['required', 'boolean'],
                'value.enableTwoFactorAuth' => ['required', 'boolean'],
                'value.auditSensitiveActions' => ['required', 'boolean'],
                'value.confirmFinancialTransactions' => ['required', 'accepted'],
            ]);
        }

        if ($section === 'reportTemplate') {
            $request->validate([
                'value.countryLine' => ['required', 'string', 'max:200'],
                'value.organizationLine' => ['required', 'string', 'max:250'],
                'value.acronymLine' => ['nullable', 'string', 'max:100'],
                'value.addressLine' => ['required', 'string', 'max:500'],
                'value.leftLogo' => ['nullable', 'string', 'max:3000000'],
                'value.rightLogo' => ['nullable', 'string', 'max:3000000'],
                'value.showGeneratedDate' => ['required', 'boolean'],
                'value.checkTemplate' => ['required', 'array'],
                'value.checkTemplate.backgroundColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'value.checkTemplate.heading' => ['nullable', 'string', 'max:100'],
                'value.checkTemplate.subheading' => ['nullable', 'string', 'max:150'],
                'value.checkTemplate.payeeLabel' => ['required', 'string', 'max:100'],
                'value.checkTemplate.currencyLabel' => ['required', 'string', 'max:50'],
                'value.checkTemplate.memoPrefix' => ['required', 'string', 'max:50'],
                'value.checkTemplate.primarySignatoryName' => ['required', 'string', 'max:150'],
                'value.checkTemplate.primarySignatoryTitle' => ['required', 'string', 'max:100'],
                'value.checkTemplate.secondarySignatoryName' => ['required', 'string', 'max:150'],
                'value.checkTemplate.secondarySignatoryTitle' => ['required', 'string', 'max:100'],
                'value.checkTemplate.horizontalMargin' => ['required', 'numeric', 'min:0', 'max:1.5'],
                'value.checkTemplate.headerTop' => ['required', 'numeric', 'min:0', 'max:2'],
                'value.checkTemplate.payeeTop' => ['required', 'numeric', 'min:0', 'max:3'],
                'value.checkTemplate.wordsTop' => ['required', 'numeric', 'min:0', 'max:3'],
                'value.checkTemplate.footerBottom' => ['required', 'numeric', 'min:0', 'max:2'],
                'value.categoryTemplates' => ['required', 'array', 'size:5'],
            ]);

            foreach (['member', 'contribution', 'loan', 'benefit', 'financial'] as $category) {
                $this->validateReportDesign($request, "value.categoryTemplates.{$category}");
                $request->validate(["value.categoryTemplates.{$category}.excelTemplate" => ['required', 'array']]);
                $this->validateReportDesign($request, "value.categoryTemplates.{$category}.excelTemplate");
            }
        }

        if ($section === 'backup') {
            $request->validate([
                'value.automaticBackup' => ['required', 'boolean'],
                'value.backupFrequency' => ['required', Rule::in(['Hourly', 'Daily', 'Weekly', 'Monthly'])],
                'value.retentionDays' => ['required', 'integer', 'min:1', 'max:3650'],
                'value.includeAttachments' => ['required', 'boolean'],
            ]);
        }

        if ($section === 'appearance') {
            $color = 'regex:/^#[0-9A-Fa-f]{6}$/';
            $request->validate([
                'value.sidebarLogoUrl' => ['required', 'string', 'max:3000000'],
                'value.sidebarFooterLeftLogoUrl' => ['required', 'string', 'max:3000000'],
                'value.sidebarFooterLeftLogoLabel' => ['required', 'string', 'max:40'],
                'value.sidebarFooterRightLogoUrl' => ['required', 'string', 'max:3000000'],
                'value.sidebarFooterRightLogoLabel' => ['required', 'string', 'max:40'],
                'value.primaryColor' => ['required', 'string', $color],
                'value.secondaryColor' => ['required', 'string', $color],
                'value.accentColor' => ['required', 'string', $color],
                'value.sidebarColor' => ['required', 'string', $color],
                'value.backgroundColor' => ['required', 'string', $color],
                'value.progressColorStart' => ['required', 'string', $color],
                'value.progressColorMiddle' => ['required', 'string', $color],
                'value.progressColorEnd' => ['required', 'string', $color],
                'value.baseFontSize' => ['required', 'integer', 'min:12', 'max:20'],
                'value.fontWeight' => ['required', Rule::in([400, 500, 600, 700])],
                'value.fontFamily' => ['required', Rule::in(['century-gothic', 'arial', 'calibri', 'verdana', 'geist', 'poppins', 'roboto', 'inter', 'times-new-roman', 'system', 'serif', 'monospace'])],
                'value.fontStyle' => ['required', Rule::in(['normal', 'italic'])],
                'value.borderRadius' => ['required', 'integer', 'min:0', 'max:32'],
                'value.compactMode' => ['required', 'boolean'],
                'value.sidebarStyle' => ['required', Rule::in(['expanded', 'collapsed'])],
                'value.logoSize' => ['required', Rule::in(['small', 'medium', 'large'])],
                'value.loginBackground' => ['required', Rule::in(['default', 'solid'])],
            ]);
        }

        $setting = SystemSetting::query()->updateOrCreate(
            ['section' => $section],
            ['value' => $data['value'], 'updated_by' => $request->user()->full_name]
        );

        return response()->json($setting->value);
    }

    private function validateReportDesign(Request $request, string $path): void
    {
        $color = 'regex:/^#[0-9A-Fa-f]{6}$/';
        $request->validate([
            $path => ['required', 'array'],
            "{$path}.preset" => ['required', Rule::in(['classic', 'modern', 'compact'])],
            "{$path}.primaryColor" => ['required', 'string', $color],
            "{$path}.headerBackground" => ['required', 'string', $color],
            "{$path}.bodyFontSize" => ['required', 'integer', 'min:7', 'max:14'],
            "{$path}.bodyFontFamily" => ['required', Rule::in(['Arial', 'Georgia', 'Times New Roman', 'Courier New'])],
            "{$path}.bodyFontWeight" => ['required', Rule::in(['normal', 'bold'])],
            "{$path}.bodyFontStyle" => ['required', Rule::in(['normal', 'italic'])],
            "{$path}.bodyTextDecoration" => ['required', Rule::in(['none', 'underline'])],
            "{$path}.bodyTextColor" => ['required', 'string', $color],
            "{$path}.bodyTextAlignment" => ['required', Rule::in(['left', 'center', 'right'])],
            "{$path}.stripedRows" => ['required', 'boolean'],
            "{$path}.showBorders" => ['required', 'boolean'],
            "{$path}.titleAlignment" => ['required', Rule::in(['left', 'center'])],
            "{$path}.orientation" => ['required', Rule::in(['auto', 'portrait', 'landscape'])],
            "{$path}.paperSize" => ['required', Rule::in(['a4', 'letter', 'legal'])],
            "{$path}.captionText" => ['nullable', 'string', 'max:1000'],
            "{$path}.noteText" => ['nullable', 'string', 'max:2000'],
        ]);

        foreach (['captionStyle', 'noteStyle'] as $style) {
            $stylePath = "{$path}.{$style}";
            $request->validate([
                $stylePath => ['required', 'array'],
                "{$stylePath}.fontFamily" => ['required', Rule::in(['Arial', 'Georgia', 'Times New Roman', 'Courier New'])],
                "{$stylePath}.fontSize" => ['required', 'integer', 'min:7', 'max:24'],
                "{$stylePath}.fontWeight" => ['required', Rule::in(['normal', 'bold'])],
                "{$stylePath}.fontStyle" => ['required', Rule::in(['normal', 'italic'])],
                "{$stylePath}.textDecoration" => ['required', Rule::in(['none', 'underline'])],
                "{$stylePath}.textColor" => ['required', 'string', $color],
                "{$stylePath}.textAlignment" => ['required', Rule::in(['left', 'center', 'right'])],
            ]);
        }
    }

    private function mergeReportTemplateDefaults(array $value): array
    {
        return array_replace_recursive($this->defaultReportTemplate(), $value);
    }

    private function defaultReportTemplate(): array
    {
        return [
            'countryLine' => 'Republic of the Philippines',
            'organizationLine' => 'Gingoog City Government Employees Association',
            'acronymLine' => '(GCGEA)',
            'addressLine' => 'City Hall Compound, Gingoog City',
            'leftLogo' => '/logo.png',
            'rightLogo' => '/city-seal-logo.png',
            'showGeneratedDate' => true,
            'checkTemplate' => [
                'backgroundColor' => '#E8F3E8',
                'heading' => '',
                'subheading' => '',
                'payeeLabel' => 'Pay to the order of',
                'currencyLabel' => 'Pesos',
                'memoPrefix' => 'Memo:',
                'primarySignatoryName' => 'Joy Fatima C. Baguiz',
                'primarySignatoryTitle' => 'Treasurer',
                'secondarySignatoryName' => 'Casimiro T. Guno',
                'secondarySignatoryTitle' => 'President',
                'horizontalMargin' => 0.45,
                'headerTop' => 0.25,
                'payeeTop' => 1.05,
                'wordsTop' => 1.65,
                'footerBottom' => 0.42,
            ],
            'categoryTemplates' => [
                'member' => $this->defaultReportDesign('classic', '#1E3A8A', '#E8EDF3', 9, 'center', 'auto', 'a4'),
                'contribution' => $this->defaultReportDesign('modern', '#166534', '#DCFCE7', 9, 'left', 'auto', 'a4', true),
                'loan' => $this->defaultReportDesign('modern', '#9A3412', '#FFEDD5', 9, 'left', 'landscape', 'legal', true),
                'benefit' => $this->defaultReportDesign('classic', '#7E22CE', '#F3E8FF', 9, 'center', 'auto', 'a4', true),
                'financial' => $this->defaultReportDesign('compact', '#0F172A', '#E2E8F0', 8, 'left', 'landscape', 'letter', true),
            ],
        ];
    }

    private function defaultReportDesign(
        string $preset,
        string $primaryColor,
        string $headerBackground,
        int $bodyFontSize,
        string $titleAlignment,
        string $orientation,
        string $paperSize,
        bool $stripedRows = false,
    ): array {
        $textStyle = [
            'fontFamily' => 'Arial',
            'fontSize' => 9,
            'fontWeight' => 'normal',
            'fontStyle' => 'normal',
            'textDecoration' => 'none',
            'textColor' => '#111111',
            'textAlignment' => 'left',
        ];

        $design = [
            'preset' => $preset,
            'primaryColor' => $primaryColor,
            'headerBackground' => $headerBackground,
            'bodyFontSize' => $bodyFontSize,
            'bodyFontFamily' => 'Arial',
            'bodyFontWeight' => 'normal',
            'bodyFontStyle' => 'normal',
            'bodyTextDecoration' => 'none',
            'bodyTextColor' => '#111111',
            'bodyTextAlignment' => 'left',
            'stripedRows' => $stripedRows,
            'showBorders' => true,
            'titleAlignment' => $titleAlignment,
            'orientation' => $orientation,
            'paperSize' => $paperSize,
            'captionText' => '',
            'noteText' => '',
            'captionStyle' => $textStyle,
            'noteStyle' => $textStyle,
        ];

        return [
            ...$design,
            'excelTemplate' => $design,
        ];
    }
}
