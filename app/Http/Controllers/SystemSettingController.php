<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SystemSettingController extends Controller
{
    private const SECTIONS = [
        'general', 'organization', 'numbering', 'loan', 'contribution',
        'benefit', 'notification', 'security', 'reportTemplate', 'backup', 'appearance',
    ];

    public function index(Request $request)
    {
        if (! $request->user()->hasPermission('settings.view')) {
            abort(403, "You don't have permission to view system settings.");
        }

        return response()->json(
            SystemSetting::query()->get()->mapWithKeys(
                fn (SystemSetting $setting) => [$setting->section => $setting->value]
            )
        );
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
            foreach (['member', 'loan', 'loanPayment', 'contribution', 'benefit', 'benefitRelease'] as $type) {
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

        $setting = SystemSetting::query()->updateOrCreate(
            ['section' => $section],
            ['value' => $data['value'], 'updated_by' => $request->user()->full_name]
        );

        return response()->json($setting->value);
    }
}
