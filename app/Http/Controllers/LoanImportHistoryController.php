<?php

namespace App\Http\Controllers;

use App\Http\Resources\LoanImportBatchResource;
use App\Http\Resources\LoanImportBatchRowResource;
use App\Models\LoanImportBatch;
use App\Support\ApiPagination;
use Illuminate\Http\Request;

class LoanImportHistoryController extends Controller
{
    public function index(Request $request)
    {
        $query = LoanImportBatch::query()->with('uploadedBy')->orderByDesc('committed_at');

        if ($period = $request->string('period')->toString()) {
            $query->where('balance_period', $period);
        }
        if ($search = $request->string('search')->toString()) {
            $query->where('original_filename', 'ilike', "%{$search}%");
        }
        if ($dateFrom = $request->string('dateFrom')->toString()) {
            $query->whereDate('committed_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->string('dateTo')->toString()) {
            $query->whereDate('committed_at', '<=', $dateTo);
        }

        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, LoanImportBatchResource::class));
    }

    public function show(LoanImportBatch $batch)
    {
        $batch->load(['uploadedBy', 'rows.member', 'rows.loan']);

        return response()->json([
            'batch' => new LoanImportBatchResource($batch),
            'rows' => LoanImportBatchRowResource::collection($batch->rows),
        ]);
    }
}
