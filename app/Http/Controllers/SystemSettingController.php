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

        $setting = SystemSetting::query()->updateOrCreate(
            ['section' => $section],
            ['value' => $data['value'], 'updated_by' => $request->user()->full_name]
        );

        return response()->json($setting->value);
    }
}
