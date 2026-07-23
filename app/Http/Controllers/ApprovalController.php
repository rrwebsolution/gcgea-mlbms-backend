<?php

namespace App\Http\Controllers;

use App\Http\Requests\Approval\ActOnApprovalRequest;
use App\Http\Resources\ApprovalActionResource;
use App\Http\Resources\MyApprovalItemResource;
use App\Services\ApprovalWorkflowService;
use App\Support\ApiPagination;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalWorkflowService $workflow) {}

    public function index(Request $request)
    {
        $paginator = $this->workflow->myApprovals($request->user(), [
            'tab' => $request->string('tab')->toString() ?: 'pending',
            'subjectType' => $request->string('subjectType')->toString() ?: null,
            'perPage' => $request->integer('perPage', 10),
            'page' => $request->integer('page', 1),
        ]);

        return response()->json(ApiPagination::make($paginator, MyApprovalItemResource::class));
    }

    public function history(string $subjectType, string $subjectId)
    {
        $subject = ApprovalWorkflowService::subjectClassForSlug($subjectType)::findOrFail($subjectId);

        return ApprovalActionResource::collection($this->workflow->historyFor($subject));
    }

    public function act(ActOnApprovalRequest $request, string $subjectType, string $subjectId)
    {
        $subject = ApprovalWorkflowService::subjectClassForSlug($subjectType)::findOrFail($subjectId);
        $action = $request->string('action')->toString();

        $this->authorize('act', [$subject, $action]);

        $this->workflow->act(
            $subject,
            $request->user(),
            $action,
            $request->input('extra', []),
            $request->input('remarks'),
        );

        return response()->json(['message' => 'Action recorded.']);
    }
}
