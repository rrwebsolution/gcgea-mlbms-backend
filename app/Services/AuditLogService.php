<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\AnnualBudget;
use App\Models\Disbursement;
use App\Models\BenefitApplication;
use App\Models\Loan;
use App\Models\Member;
use App\Models\MemberImportBatch;
use App\Models\PayrollDeductionHeader;
use App\Models\PayrollImportBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Single write path for audit_logs — shared by the approval workflow engine
 * and available for any other module to call directly.
 */
class AuditLogService
{
    public function record(User $actor, Model $subject, string $action, ?string $remarks = null, ?string $status = null): AuditLog
    {
        return AuditLog::create([
            'date_time' => now(),
            'user_id' => $actor->id,
            'user_name' => $actor->full_name,
            'role_name' => $actor->role?->name,
            'module' => $this->moduleLabelFor($subject),
            'action' => Str::title(str_replace('_', ' ', $action)),
            'record_reference' => $this->referenceFor($subject),
            'new_values' => $remarks ? ['remarks' => $remarks] : null,
            'status' => $status ?? 'Success',
        ]);
    }

    private function moduleLabelFor(Model $subject): string
    {
        return match (true) {
            $subject instanceof Member => 'Member Registration',
            $subject instanceof Loan => 'Loan Application',
            $subject instanceof BenefitApplication => 'Benefit Application',
            $subject instanceof PayrollImportBatch => 'Payroll Import',
            $subject instanceof PayrollDeductionHeader => 'Payroll Deductions',
            $subject instanceof MemberImportBatch => 'Member Import',
            $subject instanceof AnnualBudget => 'Annual Budget',
            $subject instanceof Disbursement => 'Disbursement',
            default => class_basename($subject),
        };
    }

    private function referenceFor(Model $subject): ?string
    {
        return match (true) {
            $subject instanceof Member => $subject->member_number ?? $subject->draft_reference_no,
            $subject instanceof Loan, $subject instanceof BenefitApplication => $subject->application_number,
            $subject instanceof PayrollImportBatch => $subject->payroll_reference ?? $subject->token,
            $subject instanceof PayrollDeductionHeader => $subject->payroll_reference,
            $subject instanceof MemberImportBatch => $subject->original_filename ?? $subject->token,
            $subject instanceof AnnualBudget => (string) $subject->fiscal_year,
            $subject instanceof Disbursement => $subject->reference_number,
            default => null,
        };
    }
}
