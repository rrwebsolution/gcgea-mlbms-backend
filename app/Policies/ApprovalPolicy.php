<?php

namespace App\Policies;

use App\Models\User;
use App\Services\ApprovalWorkflowService;
use Illuminate\Database\Eloquent\Model;

/**
 * One shared policy for every approval-workflow subject (Member, Loan,
 * BenefitApplication) — authorization always defers to the subject's
 * currently-configured workflow stage, never to a hardcoded role/permission.
 */
class ApprovalPolicy
{
    public function __construct(private readonly ApprovalWorkflowService $workflow) {}

    public function act(User $user, Model $subject, string $action): bool
    {
        $instance = $subject->approvalInstance;

        if (! $instance || $instance->status !== 'pending' || ! $instance->currentStage) {
            return false;
        }

        return $this->workflow->isAuthorizedForStage($user, $instance->currentStage)
            && in_array($action, $this->workflow->allowedActionsForStage($instance->currentStage), true);
    }
}
