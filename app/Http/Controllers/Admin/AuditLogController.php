<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ApiPagination;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::query()
            ->whereNotIn('action', ['draft_created', 'draft_updated']);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('user_name', 'ilike', "%{$search}%")
                    ->orWhere('record_reference', 'ilike', "%{$search}%")
                    ->orWhere('action', 'ilike', "%{$search}%");
            });
        }
        if ($module = $request->string('module')->toString()) {
            $query->where('module', $module);
        }

        $query->orderByDesc('date_time');

        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, AuditLogResource::class));
    }

    public function export()
    {
        return AuditLogResource::collection(
            AuditLog::whereNotIn('action', ['draft_created', 'draft_updated'])
                ->orderByDesc('date_time')
                ->limit(5000)
                ->get()
        );
    }
}
