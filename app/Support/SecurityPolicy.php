<?php

namespace App\Support;

use App\Models\SystemSetting;
class SecurityPolicy
{
    public static function settings(): array
    {
        return SystemSetting::security();
    }

    public static function passwordRules(): array
    {
        $settings = self::settings();
        $rules = ['min:'.$settings['minimumPasswordLength']];
        if ($settings['requireUppercase']) {
            $rules[] = 'regex:/[A-Z]/';
        }
        if ($settings['requireLowercase']) {
            $rules[] = 'regex:/[a-z]/';
        }
        if ($settings['requireNumber']) {
            $rules[] = 'regex:/[0-9]/';
        }
        if ($settings['requireSpecialCharacter']) {
            $rules[] = 'regex:/[^A-Za-z0-9]/';
        }

        return $rules;
    }
}
