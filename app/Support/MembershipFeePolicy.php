<?php

namespace App\Support;

use App\Models\Member;
use App\Models\SystemSetting;

class MembershipFeePolicy
{
    public static function isRequired(): bool
    {
        $settings = SystemSetting::query()->where('section', 'general')->value('value') ?? [];

        return (bool) ($settings['requireMembershipFeeForActivation'] ?? true);
    }

    public static function isSatisfied(Member $member): bool
    {
        return ! self::isRequired() || $member->membershipFeePayment?->status === 'Posted';
    }
}
