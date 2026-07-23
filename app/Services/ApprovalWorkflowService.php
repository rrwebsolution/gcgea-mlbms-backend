<?php

namespace App\Services;

use App\Exceptions\ApprovalActionConflictException;
use App\Models\ApprovalAction;
use App\Models\ApprovalInstance;
use App\Models\BenefitApplication;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStage;
use App\Notifications\ApprovalPendingNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * The single, subject-agnostic entry point for the approval workflow engine.
 * Nothing in here knows about "loans" or "members" beyond the small mapping
 * helpers at the bottom — approver assignment, stage progression, and
 * authorization all read from workflow_definitions/workflow_stages config,
 * never from a hardcoded name or role.
 */
class ApprovalWorkflowService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * Called the moment a subject moves from Draft to Submitted. Returns null
     * when the module's workflow is disabled — in that case the subject is
     * auto-approved immediately and no instance is created.
     */
    public function startInstance(Model $subject, string $moduleKey, User $submittedBy): ?ApprovalInstance
    {
        return DB::transaction(function () use ($subject, $moduleKey, $submittedBy) {
            $definition = WorkflowDefinition::where('module_key', $moduleKey)->firstOrFail();

            if (! $definition->is_enabled) {
                if ($subject instanceof Loan) {
                    $subject->update([
                        'status' => 'Approved',
                        'approved_amount' => $subject->approved_amount ?? $subject->requested_amount,
                    ]);
                } elseif (! $subject instanceof Member) {
                    $subject->update(['status' => 'Approved']);
                }
                $this->recordStandaloneAction($subject, $submittedBy, 'auto_approved');

                return null;
            }

            $firstStage = $definition->stages()->orderBy('sequence')->firstOrFail();

            $instance = ApprovalInstance::updateOrCreate(
                ['subject_type' => $subject::class, 'subject_id' => $subject->getKey()],
                [
                    'workflow_definition_id' => $definition->id,
                    'current_stage_id' => $firstStage->id,
                    'status' => 'pending',
                    'started_at' => now(),
                    'completed_at' => null,
                ]
            );

            if (! $subject instanceof Member) {
                $subject->update(['status' => $this->legacyStatusFor($firstStage)]);
            }

            ApprovalAction::create([
                'approval_instance_id' => $instance->id,
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
                'workflow_stage_id' => $firstStage->id,
                'action' => 'submitted',
                'acted_by_user_id' => $submittedBy->id,
                'acted_at' => now(),
            ]);

            $this->notifyEligibleApprovers($subject, $firstStage);

            return $instance;
        });
    }

    /**
     * The one mutating entry point for review/approve/reject/return/release.
     * $extra carries module-specific columns to merge into the subject (e.g.
     * release fields for Loan), already validated by the calling FormRequest.
     */
    public function act(Model $subject, User $actor, string $action, array $extra = [], ?string $remarks = null): ApprovalInstance
    {
        return DB::transaction(function () use ($subject, $actor, $action, $extra, $remarks) {
            $instance = ApprovalInstance::where('subject_type', $subject::class)
                ->where('subject_id', $subject->getKey())
                ->lockForUpdate()
                ->first();

            if (! $instance || $instance->status !== 'pending' || ! $instance->current_stage_id) {
                throw new ApprovalActionConflictException('This item is no longer awaiting action.');
            }

            $stage = WorkflowStage::findOrFail($instance->current_stage_id);

            if (! $this->isAuthorizedForStage($actor, $stage)) {
                throw new AuthorizationException("You don't have permission to perform this action.");
            }

            if (! in_array($action, $this->allowedActionsForStage($stage), true)) {
                throw new InvalidArgumentException("Action [{$action}] is not allowed at this stage.");
            }

            ApprovalAction::create([
                'approval_instance_id' => $instance->id,
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
                'workflow_stage_id' => $stage->id,
                'action' => $action,
                'acted_by_user_id' => $actor->id,
                'remarks' => $remarks,
                'acted_at' => now(),
            ]);

            match ($action) {
                'reject' => $this->applyReject($subject, $instance, $remarks),
                'return' => $this->applyReturn($subject, $instance),
                'release' => $this->applyRelease($subject, $instance, $extra),
                default => $this->applyAdvance($subject, $instance, $stage, $extra),
            };

            $this->auditLog->record($actor, $subject, $action, $remarks);

            $instance = $instance->fresh();
            if ($instance->status === 'pending' && $instance->currentStage) {
                $this->notifyEligibleApprovers($subject, $instance->currentStage);
            }

            return $instance;
        });
    }

    /**
     * Re-opens a "returned" subject back to stage 1 after the owner edits and
     * resubmits it.
     */
    public function resubmit(Model $subject, User $actor): ApprovalInstance
    {
        return DB::transaction(function () use ($subject, $actor) {
            $instance = ApprovalInstance::where('subject_type', $subject::class)
                ->where('subject_id', $subject->getKey())
                ->lockForUpdate()
                ->first();

            if (! $instance || $instance->status !== 'returned') {
                throw new ApprovalActionConflictException('This item is not awaiting resubmission.');
            }

            $firstStage = WorkflowStage::where('workflow_definition_id', $instance->workflow_definition_id)
                ->orderBy('sequence')
                ->firstOrFail();

            $instance->update([
                'current_stage_id' => $firstStage->id,
                'status' => 'pending',
                'completed_at' => null,
            ]);

            if (! $subject instanceof Member) {
                $subject->update(['status' => $this->legacyStatusFor($firstStage)]);
            } else {
                $subject->update(['is_draft' => false]);
            }

            ApprovalAction::create([
                'approval_instance_id' => $instance->id,
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
                'workflow_stage_id' => $firstStage->id,
                'action' => 'resubmitted',
                'acted_by_user_id' => $actor->id,
                'acted_at' => now(),
            ]);

            $this->auditLog->record($actor, $subject, 'resubmitted');
            $this->notifyEligibleApprovers($subject, $firstStage);

            return $instance->fresh();
        });
    }

    /**
     * Cross-module "my approvals" queue. `$filters` accepts `tab` (pending|
     * approved|rejected|returned|released), `subjectType` (members|loans|
     * benefits), `page`, `perPage`.
     */
    public function myApprovals(User $user, array $filters = []): LengthAwarePaginator
    {
        $tab = $filters['tab'] ?? 'pending';
        $perPage = (int) ($filters['perPage'] ?? 10);
        $page = (int) ($filters['page'] ?? 1);
        $subjectClass = isset($filters['subjectType']) ? self::subjectClassForSlug($filters['subjectType']) : null;

        if ($tab === 'pending') {
            $eligibleStageIds = $this->eligibleStageIdsFor($user);

            $query = ApprovalInstance::query()
                ->where('status', 'pending')
                ->whereIn('current_stage_id', $eligibleStageIds)
                ->with(['definition', 'currentStage', 'subject' => fn ($morphTo) => $morphTo->morphWith([
                    Loan::class => ['member'],
                    BenefitApplication::class => ['member'],
                ])]);

            if ($subjectClass) {
                $query->where('subject_type', $subjectClass);
            }

            return $query->orderBy('started_at')->paginate($perPage, page: $page);
        }

        $actionMap = [
            'approved' => ['review', 'approve'],
            'rejected' => ['reject'],
            'returned' => ['return'],
            'released' => ['release'],
        ];

        $query = ApprovalAction::query()
            ->where('acted_by_user_id', $user->id)
            ->whereIn('action', $actionMap[$tab] ?? [])
            ->with(['stage', 'subject' => fn ($morphTo) => $morphTo->morphWith([
                Loan::class => ['member'],
                BenefitApplication::class => ['member'],
            ])]);

        if ($subjectClass) {
            $query->where('subject_type', $subjectClass);
        }

        return $query->orderByDesc('acted_at')->paginate($perPage, page: $page);
    }

    /**
     * Unified history read for any of the 3 pilot subjects.
     *
     * @return Collection<int, ApprovalAction>
     */
    public function historyFor(Model $subject): Collection
    {
        return $subject->approvalActions()
            ->whereNotIn('action', ['draft_created', 'draft_updated'])
            ->with('actor')
            ->get();
    }

    /**
     * The one place approver-matching logic lives, always AND-ed with the
     * permission gate — even a matching role/user/office assignment doesn't
     * authorize someone who's had the permission revoked directly.
     */
    public function isAuthorizedForStage(User $user, WorkflowStage $stage): bool
    {
        $hasPermission = $stage->required_permission_code === null || $user->hasPermission($stage->required_permission_code);

        if (! $hasPermission) {
            return false;
        }

        return match ($stage->approver_type) {
            'role' => $user->allRoles()->contains('id', $stage->approver_role_id),
            'user' => $user->id === $stage->approver_user_id,
            'office' => $user->office_id !== null && $user->office_id === $stage->approver_office_id,
            default => false,
        };
    }

    /**
     * @return array<int, string>
     */
    public function allowedActionsForStage(WorkflowStage $stage): array
    {
        return match ($stage->stage_type) {
            'release' => ['release', 'return'],
            default => [$stage->stage_type, 'reject', 'return'],
        };
    }

    /**
     * `Member::membership_status` is an encoder-owned field describing the
     * member's real-world standing (Active/Inactive/Suspended/...), set on
     * the registration form itself — it is NOT a workflow-progress field, so
     * the engine never writes to it. A member's approval progress lives
     * entirely on its `approval_instances` row instead; reject/return simply
     * kicks the record back into the draft wizard (`is_draft = true`) so the
     * encoder can revise and resubmit it. Loan/BenefitApplication, by
     * contrast, have a `status` column purpose-built for exactly this
     * workflow vocabulary (Submitted/Under Review/For Approval/Approved/...).
     */
    private function applyReject(Model $subject, ApprovalInstance $instance, ?string $remarks): void
    {
        $instance->update(['status' => 'rejected', 'current_stage_id' => null, 'completed_at' => now()]);

        if ($subject instanceof Member) {
            $subject->update(['is_draft' => true]);

            return;
        }

        $subject->update(['status' => 'Rejected', 'rejection_reason' => $remarks]);
    }

    private function applyReturn(Model $subject, ApprovalInstance $instance): void
    {
        $instance->update(['status' => 'returned', 'current_stage_id' => null]);

        if ($subject instanceof Member) {
            $subject->update(['is_draft' => true]);

            return;
        }

        $subject->update(['status' => 'Draft']);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function applyRelease(Model $subject, ApprovalInstance $instance, array $extra): void
    {
        // Never reached for Member (single-stage, no release stage configured).
        $instance->update(['status' => 'released', 'current_stage_id' => null, 'completed_at' => now()]);
        $subject->update(['status' => 'Released', ...$extra]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function applyAdvance(Model $subject, ApprovalInstance $instance, WorkflowStage $stage, array $extra): void
    {
        $next = $this->findNextStage($stage);

        if ($next) {
            $instance->update(['current_stage_id' => $next->id]);
            $subject instanceof Member
                ? ($extra !== [] && $subject->update($extra))
                : $subject->update(['status' => $this->legacyStatusFor($next), ...$extra]);

            return;
        }

        $instance->update(['status' => 'approved', 'current_stage_id' => null, 'completed_at' => now()]);
        $subject instanceof Member
            ? ($extra !== [] && $subject->update($extra))
            : $subject->update(['status' => 'Approved', ...$extra]);
    }

    private function findNextStage(WorkflowStage $stage): ?WorkflowStage
    {
        return WorkflowStage::where('workflow_definition_id', $stage->workflow_definition_id)
            ->where('sequence', $stage->sequence + 1)
            ->first();
    }

    private function legacyStatusFor(WorkflowStage $stage): string
    {
        return match ($stage->stage_type) {
            'review' => 'Under Review',
            'approve' => 'For Approval',
            'release' => 'Approved',
            default => 'Submitted',
        };
    }

    /**
     * Standalone bookkeeping row for lifecycle events that happen outside the
     * formal instance (e.g. saving/updating a Draft, before submission ever
     * starts an approval instance).
     */
    public function recordDraftAction(Model $subject, User $actor, string $action): void
    {
        $this->recordStandaloneAction($subject, $actor, $action);
    }

    private function recordStandaloneAction(Model $subject, User $actor, string $action): void
    {
        ApprovalAction::create([
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'acted_by_user_id' => $actor->id,
            'acted_at' => now(),
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function eligibleStageIdsFor(User $user): array
    {
        return WorkflowStage::query()
            ->whereHas('definition', fn ($q) => $q->where('is_enabled', true))
            ->get()
            ->filter(fn (WorkflowStage $stage) => $this->isAuthorizedForStage($user, $stage))
            ->pluck('id')
            ->all();
    }

    /**
     * @return Collection<int, User>
     */
    private function usersEligibleForStage(WorkflowStage $stage): Collection
    {
        $candidates = match ($stage->approver_type) {
            'user' => $stage->approver_user_id ? User::where('id', $stage->approver_user_id)->get() : collect(),
            'office' => $stage->approver_office_id ? User::where('office_id', $stage->approver_office_id)->get() : collect(),
            'role' => User::where(function ($q) use ($stage) {
                $q->where('role_id', $stage->approver_role_id)
                    ->orWhereHas('additionalRoles', fn ($rq) => $rq->where('roles.id', $stage->approver_role_id));
            })->get(),
            default => collect(),
        };

        return $candidates->filter(fn (User $user) => $this->isAuthorizedForStage($user, $stage))->values();
    }

    private function notifyEligibleApprovers(Model $subject, WorkflowStage $stage): void
    {
        $users = $this->usersEligibleForStage($stage);

        if ($users->isEmpty()) {
            return;
        }

        Notification::send(
            $users,
            new ApprovalPendingNotification(
                self::slugForSubject($subject),
                (int) $subject->getKey(),
                $this->titleFor($subject),
                $stage->label,
            )
        );
    }

    private function titleFor(Model $subject): string
    {
        return match (true) {
            $subject instanceof Member => "Member registration for {$subject->full_name}",
            $subject instanceof Loan => "Loan application {$subject->application_number}",
            $subject instanceof BenefitApplication => "Benefit application {$subject->application_number}",
            default => class_basename($subject),
        };
    }

    public static function subjectClassForSlug(string $slug): string
    {
        return match ($slug) {
            'members' => Member::class,
            'loans' => Loan::class,
            'benefits' => BenefitApplication::class,
            default => throw new InvalidArgumentException("Unknown approval subject type [{$slug}]."),
        };
    }

    public static function slugForSubject(Model $subject): string
    {
        return match (true) {
            $subject instanceof Member => 'members',
            $subject instanceof Loan => 'loans',
            $subject instanceof BenefitApplication => 'benefits',
            default => throw new InvalidArgumentException('Unknown approval subject.'),
        };
    }
}
