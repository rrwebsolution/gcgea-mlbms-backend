<?php

namespace App\Http\Controllers;

use App\Http\Resources\DeductionResource;
use App\Models\Deduction;
use App\Support\ApiPagination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeductionController extends Controller
{
    public function index(Request $request)
    {
        $query = Deduction::with(['member.office', 'deductionType']);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'ilike', "%{$search}%")
                    ->orWhereHas('member', fn ($mq) => $mq->where('surname', 'ilike', "%{$search}%")
                        ->orWhere('first_name', 'ilike', "%{$search}%"));
            });
        }
        if ($period = $request->string('period')->toString()) {
            $query->where('period', $period);
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($deductionTypeCode = $request->string('deductionTypeCode')->toString()) {
            $query->whereHas('deductionType', fn ($typeQuery) => $typeQuery->where('code', $deductionTypeCode));
        }

        $query->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, DeductionResource::class));
    }

    public function all(): AnonymousResourceCollection
    {
        return DeductionResource::collection(
            Deduction::with(['member.office', 'deductionType'])->orderBy('payment_date', 'desc')->get()
        );
    }

    public function void(Request $request, Deduction $deduction): DeductionResource
    {
        if (! $request->user()->hasPermission('deductions.void')) {
            abort(403, "You don't have permission to perform this action.");
        }

        if ($deduction->status === 'Voided') {
            abort(422, 'This deduction has already been voided.');
        }

        $request->validate(['reason' => ['required', 'string']]);

        $deduction->update([
            'status' => 'Voided',
            'void_reason' => $request->string('reason')->toString(),
            'voided_by' => $request->user()->full_name,
            'voided_at' => now(),
        ]);

        return new DeductionResource($deduction->load(['member.office', 'deductionType']));
    }
}
