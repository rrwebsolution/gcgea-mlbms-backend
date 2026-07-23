<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeductionTypeRequest;
use App\Http\Resources\DeductionTypeResource;
use App\Models\DeductionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeductionTypeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DeductionTypeResource::collection(
            DeductionType::orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function store(DeductionTypeRequest $request): DeductionTypeResource
    {
        $this->authorizeAction($request, 'deduction_types.create');

        $deductionType = DeductionType::create($request->modelAttributes());

        return new DeductionTypeResource($deductionType);
    }

    public function update(DeductionTypeRequest $request, DeductionType $deductionType): DeductionTypeResource
    {
        $this->authorizeAction($request, 'deduction_types.update');

        $deductionType->update($request->modelAttributes());

        return new DeductionTypeResource($deductionType);
    }

    public function toggleStatus(Request $request, DeductionType $deductionType): DeductionTypeResource
    {
        $this->authorizeAction($request, 'deduction_types.deactivate');

        $deductionType->update(['is_active' => ! $deductionType->is_active]);

        return new DeductionTypeResource($deductionType);
    }

    private function authorizeAction(Request $request, string $permission): void
    {
        if (! $request->user()->hasPermission($permission)) {
            abort(403, "You don't have permission to perform this action.");
        }
    }
}
