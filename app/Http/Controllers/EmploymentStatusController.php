<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmploymentStatusResource;
use App\Models\EmploymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmploymentStatusController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return EmploymentStatusResource::collection(
            EmploymentStatus::orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function store(Request $request): EmploymentStatusResource
    {
        $this->authorizeUpdate($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:employment_statuses,name'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ]);

        return new EmploymentStatusResource(EmploymentStatus::create([
            'name' => $data['name'],
            'sort_order' => $data['sortOrder'] ?? 0,
            'is_active' => true,
        ]));
    }

    public function update(Request $request, EmploymentStatus $employmentStatus): EmploymentStatusResource
    {
        $this->authorizeUpdate($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('employment_statuses', 'name')->ignore($employmentStatus->id)],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ]);
        DB::transaction(function () use ($employmentStatus, $data) {
            $oldName = $employmentStatus->name;
            $employmentStatus->update([
                'name' => $data['name'],
                'sort_order' => $data['sortOrder'] ?? $employmentStatus->sort_order,
            ]);
            if ($oldName !== $data['name']) {
                DB::table('members')
                    ->where('employment_status', $oldName)
                    ->update(['employment_status' => $data['name'], 'updated_at' => now()]);
            }
        });

        return new EmploymentStatusResource($employmentStatus);
    }

    public function toggleStatus(Request $request, EmploymentStatus $employmentStatus): EmploymentStatusResource
    {
        $this->authorizeUpdate($request);
        $employmentStatus->update(['is_active' => ! $employmentStatus->is_active]);

        return new EmploymentStatusResource($employmentStatus);
    }

    private function authorizeUpdate(Request $request): void
    {
        if (! $request->user()->hasPermission('settings.update')) {
            abort(403, "You don't have permission to perform this action.");
        }
    }
}
