<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\WorkflowDefinition\UpdateStagesRequest;
use App\Http\Resources\WorkflowDefinitionResource;
use App\Models\WorkflowDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkflowDefinitionController extends Controller
{
    private const WITH = ['stages.approverRole', 'stages.approverUser', 'stages.approverOffice'];

    public function index()
    {
        return WorkflowDefinitionResource::collection(
            WorkflowDefinition::with(self::WITH)->orderBy('label')->get()
        );
    }

    public function show(WorkflowDefinition $workflowDefinition)
    {
        return new WorkflowDefinitionResource($workflowDefinition->load(self::WITH));
    }

    public function update(Request $request, WorkflowDefinition $workflowDefinition)
    {
        $request->validate(['isEnabled' => ['required', 'boolean']]);

        $workflowDefinition->update(['is_enabled' => $request->boolean('isEnabled')]);

        return new WorkflowDefinitionResource($workflowDefinition->load(self::WITH));
    }

    /**
     * Full ordered replace-sync of a definition's stage list, mirroring the
     * existing MemberController::syncBeneficiaries() convention.
     */
    public function updateStages(UpdateStagesRequest $request, WorkflowDefinition $workflowDefinition)
    {
        DB::transaction(function () use ($request, $workflowDefinition) {
            $keepIds = [];

            foreach ($request->input('stages') as $stageData) {
                $stage = $workflowDefinition->stages()->updateOrCreate(
                    ['sequence' => $stageData['sequence']],
                    [
                        'code' => $stageData['code'],
                        'label' => $stageData['label'],
                        'stage_type' => $stageData['stageType'],
                        'approver_type' => $stageData['approverType'],
                        'approver_role_id' => $stageData['approverType'] === 'role' ? $stageData['approverRoleId'] : null,
                        'approver_user_id' => $stageData['approverType'] === 'user' ? $stageData['approverUserId'] : null,
                        'approver_office_id' => $stageData['approverType'] === 'office' ? $stageData['approverOfficeId'] : null,
                        'required_permission_code' => $stageData['requiredPermissionCode'] ?? null,
                        'is_final' => $stageData['isFinal'] ?? false,
                    ]
                );
                $keepIds[] = $stage->id;
            }

            $workflowDefinition->stages()->whereNotIn('id', $keepIds)->delete();
        });

        return new WorkflowDefinitionResource($workflowDefinition->load(self::WITH));
    }
}
