<?php

use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $setting = SystemSetting::query()->where('section', 'general')->first();
        if (! $setting) {
            return;
        }
        $value = $setting->value ?? [];
        $value['requireMembershipFeeForActivation'] ??= true;
        $setting->update(['value' => $value]);
    }

    public function down(): void
    {
        $setting = SystemSetting::query()->where('section', 'general')->first();
        if (! $setting) {
            return;
        }
        $value = $setting->value ?? [];
        unset($value['requireMembershipFeeForActivation']);
        $setting->update(['value' => $value]);
    }
};
