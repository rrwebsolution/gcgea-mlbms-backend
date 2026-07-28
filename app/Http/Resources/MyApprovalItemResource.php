<?php

namespace App\Http\Resources;

use App\Models\ApprovalAction;
use App\Models\AnnualBudget;
use App\Models\Disbursement;
use App\Models\ApprovalInstance;
use App\Models\BenefitApplication;
use App\Models\Loan;
use App\Models\Member;
use App\Services\ApprovalWorkflowService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes either an ApprovalInstance (the "pending" tab) or an ApprovalAction
 * (the "approved/rejected/returned/released" tabs — "things I acted on") into
 * one common row shape for the My Approvals list.
 */
class MyApprovalItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $subject = $row->subject;
        $stage = $row instanceof ApprovalInstance ? $row->currentStage : $row->stage;

        return [
            'id' => (string) $row->id,
            'subjectType' => ApprovalWorkflowService::slugForSubject($subject),
            'subjectId' => (string) $subject->getKey(),
            'reference' => $this->referenceFor($subject),
            'title' => $this->titleFor($subject),
            'memberName' => $this->memberNameFor($subject),
            'module' => $row instanceof ApprovalInstance ? $row->definition?->label : $this->titleFor($subject),
            'currentStageLabel' => $stage?->label,
            'currentStageType' => $stage?->stage_type,
            'submittedAt' => $row instanceof ApprovalInstance ? $row->started_at?->toIso8601String() : null,
            'actedAt' => $row instanceof ApprovalAction ? $row->acted_at?->toIso8601String() : null,
            'status' => $row instanceof ApprovalInstance ? $row->status : $row->action,
        ];
    }

    private function referenceFor(Model $subject): ?string
    {
        return match (true) {
            $subject instanceof Member => $subject->member_number ?? $subject->draft_reference_no,
            $subject instanceof AnnualBudget => (string) $subject->fiscal_year,
            $subject instanceof Disbursement => $subject->reference_number,
            default => $subject->application_number ?? null,
        };
    }

    private function titleFor(Model $subject): string
    {
        return match (true) {
            $subject instanceof Member => 'Member Registration',
            $subject instanceof Loan => 'Loan Application',
            $subject instanceof BenefitApplication => 'Benefit Application',
            $subject instanceof AnnualBudget => 'Annual Budget',
            $subject instanceof Disbursement => 'Disbursement',
            default => class_basename($subject),
        };
    }

    private function memberNameFor(Model $subject): ?string
    {
        return match (true) {
            $subject instanceof Member => $subject->full_name,
            $subject instanceof AnnualBudget => $subject->prepared_by,
            $subject instanceof Disbursement => $subject->payee,
            default => $subject->member?->full_name,
        };
    }
}
