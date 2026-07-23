<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowStageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'sequence' => $this->sequence,
            'code' => $this->code,
            'label' => $this->label,
            'stageType' => $this->stage_type,
            'approverType' => $this->approver_type,
            'approverRoleId' => $this->approver_role_id !== null ? (string) $this->approver_role_id : null,
            'approverRoleName' => $this->approverRole?->name,
            'approverUserId' => $this->approver_user_id !== null ? (string) $this->approver_user_id : null,
            'approverUserName' => $this->approverUser?->full_name,
            'approverOfficeId' => $this->approver_office_id !== null ? (string) $this->approver_office_id : null,
            'approverOfficeName' => $this->approverOffice?->name,
            'requiredPermissionCode' => $this->required_permission_code,
            'isFinal' => $this->is_final,
        ];
    }
}
