<?php

namespace App\Http\Controllers;

use App\Http\Requests\Contribution\ContributionRequest;
use App\Http\Resources\ContributionResource;
use App\Models\Contribution;
use App\Support\ApiPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContributionController extends Controller
{
    public function index(Request $request)
    {
        $query = Contribution::with(['member.office']);

        $this->applyFilters($query, $request);

        $query->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, ContributionResource::class));
    }

    public function all(Request $request)
    {
        $query = Contribution::with(['member.office']);
        $this->applyFilters($query, $request);

        return ContributionResource::collection($query->orderBy('payment_date', 'desc')->get());
    }

    public function show(Contribution $contribution)
    {
        return new ContributionResource($contribution->load(['member.office']));
    }

    public function periods()
    {
        return Contribution::query()
            ->select('contribution_period')
            ->distinct()
            ->orderBy('contribution_period', 'desc')
            ->pluck('contribution_period');
    }

    public function checkDuplicate(Request $request)
    {
        $request->validate(['memberId' => ['required'], 'period' => ['required', 'string']]);

        $exists = Contribution::where('member_id', $request->input('memberId'))
            ->where('contribution_period', $request->string('period')->toString())
            ->where('status', 'Posted')
            ->exists();

        return response()->json(['exists' => $exists]);
    }

    public function store(ContributionRequest $request)
    {
        if (! $request->user()->hasPermission('contributions.create')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $contribution = DB::transaction(function () use ($request) {
            $contribution = Contribution::create([
                'member_id' => $request->input('memberId'),
                'contribution_period' => $request->input('contributionPeriod'),
                'amount' => $request->input('amount'),
                'payment_date' => $request->input('paymentDate'),
                'payment_method' => $request->input('paymentMethod'),
                'official_receipt_number' => $request->input('officialReceiptNumber'),
                'payroll_reference' => $request->input('payrollReference'),
                'remarks' => $request->input('remarks'),
                'encoded_by' => $request->user()->full_name,
                'status' => 'Posted',
            ]);
            $contribution->update(['reference_number' => 'GCGEA-CON-'.now()->year.'-'.str_pad((string) $contribution->id, 6, '0', STR_PAD_LEFT)]);

            return $contribution;
        });

        return new ContributionResource($contribution->load(['member.office']));
    }

    public function update(ContributionRequest $request, Contribution $contribution)
    {
        if (! $request->user()->hasPermission('contributions.update')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $contribution->update([
            'contribution_period' => $request->input('contributionPeriod', $contribution->contribution_period),
            'amount' => $request->input('amount', $contribution->amount),
            'payment_date' => $request->input('paymentDate', $contribution->payment_date),
            'payment_method' => $request->input('paymentMethod', $contribution->payment_method),
            'official_receipt_number' => $request->input('officialReceiptNumber'),
            'payroll_reference' => $request->input('payrollReference'),
            'remarks' => $request->input('remarks'),
        ]);

        return new ContributionResource($contribution->load(['member.office']));
    }

    public function void(Request $request, Contribution $contribution)
    {
        if (! $request->user()->hasPermission('contributions.void')) {
            abort(403, "You don't have permission to perform this action.");
        }

        if ($contribution->status === 'Voided') {
            return response()->json(['message' => 'This contribution has already been voided.'], 422);
        }

        $request->validate(['reason' => ['required', 'string']]);

        $contribution->update([
            'status' => 'Voided',
            'void_reason' => $request->string('reason')->toString(),
            'voided_by' => $request->user()->full_name,
            'voided_at' => now(),
        ]);

        return new ContributionResource($contribution->load(['member.office']));
    }

    public function bulkStore(Request $request)
    {
        if (! $request->user()->hasPermission('contributions.bulk_create')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $data = $request->validate([
            'contributionPeriod' => ['required', 'string'],
            'paymentDate' => ['required', 'date'],
            'paymentMethod' => ['required', 'string'],
            'payrollReference' => ['nullable', 'string'],
            'rows' => ['required', 'array'],
            'rows.*.memberId' => ['required', 'exists:members,id'],
            'rows.*.amount' => ['required', 'numeric'],
            'skipDuplicates' => ['boolean'],
            'replaceDuplicates' => ['boolean'],
        ]);

        $canReplace = $request->boolean('replaceDuplicates') && $request->user()->hasPermission('contributions.replace_duplicate');
        $encodedBy = $request->user()->full_name;
        $result = ['saved' => 0, 'skippedDuplicates' => 0, 'replaced' => 0, 'failed' => 0];

        DB::transaction(function () use ($data, $canReplace, $encodedBy, &$result) {
            foreach ($data['rows'] as $row) {
                if ($row['amount'] <= 0) {
                    $result['failed']++;

                    continue;
                }

                $existing = Contribution::where('member_id', $row['memberId'])
                    ->where('contribution_period', $data['contributionPeriod'])
                    ->where('status', 'Posted')
                    ->first();

                if ($existing && $canReplace) {
                    $existing->update([
                        'amount' => $row['amount'],
                        'payment_date' => $data['paymentDate'],
                        'payment_method' => $data['paymentMethod'],
                        'payroll_reference' => $data['payrollReference'] ?? null,
                    ]);
                    $result['replaced']++;

                    continue;
                }

                if ($existing && ($data['skipDuplicates'] ?? false)) {
                    $result['skippedDuplicates']++;

                    continue;
                }

                $contribution = Contribution::create([
                    'member_id' => $row['memberId'],
                    'contribution_period' => $data['contributionPeriod'],
                    'amount' => $row['amount'],
                    'payment_date' => $data['paymentDate'],
                    'payment_method' => $data['paymentMethod'],
                    'payroll_reference' => $data['payrollReference'] ?? null,
                    'encoded_by' => $encodedBy,
                    'status' => 'Posted',
                ]);
                $contribution->update(['reference_number' => 'GCGEA-CON-'.now()->year.'-'.str_pad((string) $contribution->id, 6, '0', STR_PAD_LEFT)]);
                $result['saved']++;
            }
        });

        return response()->json($result);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'ilike', "%{$search}%")
                    ->orWhereHas('member', fn ($mq) => $mq->where('surname', 'ilike', "%{$search}%")
                        ->orWhere('first_name', 'ilike', "%{$search}%")
                        ->orWhere('member_number', 'ilike', "%{$search}%"));
            });
        }
        if ($period = $request->string('period')->toString()) {
            $query->where('contribution_period', $period);
        }
        if ($office = $request->string('office')->toString()) {
            $query->whereHas('member.office', fn ($q) => $q->where('name', $office));
        }
        if ($method = $request->string('paymentMethod')->toString()) {
            $query->where('payment_method', $method);
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($from = $request->string('dateFrom')->toString()) {
            $query->where('payment_date', '>=', $from);
        }
        if ($to = $request->string('dateTo')->toString()) {
            $query->where('payment_date', '<=', $to);
        }
    }
}
