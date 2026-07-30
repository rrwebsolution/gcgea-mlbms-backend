<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = ['section', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function benefit(): array
    {
        return array_replace([
            'requireApproval' => true,
            'requireReleaseConfirmation' => true,
            'allowEligibilityOverride' => true,
            'defaultApprovalLimit' => 20000,
            'defaultFrequencyLimit' => 'Once per year',
            'requireSupportingDocuments' => true,
            'allowMultiplePendingApplications' => false,
            'benefitYearResetMonth' => 'January',
            'independentSpousalBenefitRights' => true,
        ], self::query()->where('section', 'benefit')->first()?->value ?? []);
    }

    public static function notification(): array
    {
        return array_replace([
            'inAppNotifications' => true,
            'emailNotifications' => false,
            'smsNotifications' => false,
            'loanApprovalAlerts' => true,
            'loanDueDateAlerts' => true,
            'overdueLoanAlerts' => true,
            'benefitApprovalAlerts' => true,
            'contributionImportAlerts' => true,
            'incompleteProfileAlerts' => true,
            'userAccountAlerts' => true,
        ], self::query()->where('section', 'notification')->first()?->value ?? []);
    }

    public static function security(): array
    {
        return array_replace([
            'minimumPasswordLength' => 8,
            'requireUppercase' => true,
            'requireLowercase' => true,
            'requireNumber' => true,
            'requireSpecialCharacter' => false,
            'sessionTimeoutMinutes' => 30,
            'maximumLoginAttempts' => 5,
            'lockoutDurationMinutes' => 15,
            'requirePasswordChangeOnFirstLogin' => false,
            'enableTwoFactorAuth' => false,
            'auditSensitiveActions' => true,
            'confirmFinancialTransactions' => true,
        ], self::query()->where('section', 'security')->first()?->value ?? []);
    }
}
