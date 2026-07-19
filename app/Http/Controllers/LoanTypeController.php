<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoanTypeRequest;
use App\Http\Resources\LoanTypeResource;
use App\Models\LoanType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LoanTypeController extends Controller
{
    /**
     * Viewing loan types requires no permission — visible to any authenticated
     * user, matching today's frontend which shows them to anyone who can reach
     * the page. Returns a plain array (not paginated) since the frontend
     * consumes this as LoanType[] directly.
     */
    public function index(): AnonymousResourceCollection
    {
        return LoanTypeResource::collection(LoanType::orderByDesc('created_at')->get());
    }

    public function store(LoanTypeRequest $request): LoanTypeResource
    {
        $this->authorizeLoanSettings($request);

        $loanType = LoanType::create($request->modelAttributes());

        return new LoanTypeResource($loanType);
    }

    public function update(LoanTypeRequest $request, LoanType $loanType): LoanTypeResource
    {
        $this->authorizeLoanSettings($request);

        $loanType->update($request->modelAttributes());

        return new LoanTypeResource($loanType);
    }

    public function destroy(Request $request, LoanType $loanType): JsonResponse
    {
        $this->authorizeLoanSettings($request);

        $loanType->delete();

        return response()->json(['message' => 'Loan type deleted.']);
    }

    private function authorizeLoanSettings(Request $request): void
    {
        if (! $request->user()->hasPermission('settings.loan')) {
            abort(403, "You don't have permission to perform this action.");
        }
    }
}
