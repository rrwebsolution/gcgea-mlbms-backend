<?php

namespace App\Http\Controllers;

use App\Http\Requests\Office\OfficeRequest;
use App\Http\Resources\OfficeResource;
use App\Models\Office;
use App\Support\ApiPagination;
use Illuminate\Http\Request;

class OfficeController extends Controller
{
    public function index(Request $request)
    {
        $query = Office::withCount('members');

        if ($search = $request->string('search')->toString()) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }

        $sortBy = $request->string('sortBy')->toString();
        $query->orderBy($sortBy ?: 'created_at', $sortBy ? ($request->string('sortDir')->toString() ?: 'asc') : 'desc');

        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, OfficeResource::class));
    }

    public function all()
    {
        return OfficeResource::collection(Office::withCount('members')->orderBy('name')->get());
    }

    public function store(OfficeRequest $request)
    {
        if (! $request->user()->hasPermission('offices.create')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $office = Office::create($request->validated());

        return new OfficeResource($office->loadCount('members'));
    }

    public function update(OfficeRequest $request, Office $office)
    {
        if (! $request->user()->hasPermission('offices.update')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $office->update($request->validated());

        return new OfficeResource($office->loadCount('members'));
    }

    public function toggleStatus(Request $request, Office $office)
    {
        $code = $office->status === 'Active' ? 'offices.deactivate' : 'offices.activate';
        if (! $request->user()->hasPermission($code)) {
            abort(403, "You don't have permission to perform this action.");
        }

        $office->update(['status' => $office->status === 'Active' ? 'Inactive' : 'Active']);

        return new OfficeResource($office->loadCount('members'));
    }
}
