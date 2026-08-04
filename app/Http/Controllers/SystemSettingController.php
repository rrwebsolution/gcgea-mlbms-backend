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
                'value.acronymLine' => ['required', 'string', 'max:100'],
                'value.addressLine' => ['required', 'string', 'max:500'],
                'value.leftLogo' => ['required', 'string', 'max:3000000'],
                'value.rightLogo' => ['required', 'string', 'max:3000000'],
                'value.showGeneratedDate' => ['required', 'boolean'],
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
                'value.fontFamily' => ['required', Rule::in(['century-gothic', 'arial', 'calibri', 'verdana', 'geist', 'system', 'serif', 'monospace'])],
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
}
