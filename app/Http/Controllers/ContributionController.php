<?php

namespace App\Http\Controllers;

use App\Http\Requests\Contribution\ContributionRequest;
use App\Http\Resources\ContributionResource;
use App\Models\Contribution;
use App\Models\Deduction;
use App\Models\DeductionType;
use App\Models\Member;
use App\Models\SystemSetting;
use App\Services\ContributionFundAllocator;
use App\Services\DocumentNumberService;
use App\Support\ApiPagination;
use App\Support\MembershipFeePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContributionController extends Controller
{
    public function __construct(private readonly ContributionFundAllocator $fundAllocator) {}

    public function index(Request $request)
    {
        $query = Contribution::with(['member.office', 'fundAllocations']);

        $this->applyFilters($query, $request);

        $query->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, ContributionResource::class));
    }

    public function all(Request $request)
    {
        $query = Contribution::with(['member.office', 'fundAllocations']);
        $this->applyFilters($query, $request);

        return ContributionResource::collection($query->orderBy('payment_date', 'desc')->get());
    }

    public function show(Contribution $contribution)
    {
        $cashPabaonAmount = $contribution->contribution_type === 'Monthly Dues'
            ? Contribution::query()
                ->where('member_id', $contribution->member_id)
                ->where('contribution_period', $contribution->contribution_period)
                ->where('contribution_type', 'Cash Pabaon')
                ->where('status', 'Posted')
                ->value('amount')
            : $contribution->amount;

        $contribution->setAttribute('cash_pabaon_amount', $cashPabaonAmount);

        return new ContributionResource($contribution->load(['member.office', 'fundAllocations']));
    }

    public function periods()
    {
        return Contribution::query()
            ->select('contribution_period')
            ->distinct()
            ->orderBy('contribution_period', 'desc')
            ->pluck('contribution_period');
    }

    public function summary()
    {
        $currentPeriod = now()->format('Y-m');
        $activeMembers = Member::query()
            ->where('membership_status', 'Active')
            ->where('is_archived', false)
            ->where('is_draft', false)
            ->count();
        $paidMembers = Contribution::query()
            ->where('status', 'Posted')
            ->where('contribution_period', $currentPeriod)
            ->distinct('member_id')
            ->count('member_id');

        return response()->json([
            'totalContributions' => Contribution::count(),
            'totalAmount' => (float) Contribution::where('status', 'Posted')->sum('amount'),
            'paidMembers' => $paidMembers,
            'unpaidMembers' => max(0, $activeMembers - $paidMembers),
            'contributionsThisMonth' => Contribution::where('status', 'Posted')
                ->whereBetween('payment_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->count(),
        ]);
    }

    public function checkDuplicate(Request $request)
    {
        $request->validate(['memberId' => ['required'], 'period' => ['required', 'string']]);

        $exists = Contribution::where('member_id', $request->input('memberId'))
            ->where('contribution_period', $request->string('period')->toString())
            ->where('contribution_type', $request->input('contributionType', 'Monthly Dues'))
            ->where('status', 'Posted')
            ->exists();

        return response()->json(['exists' => $exists]);
    }

    public function checkDuplicates(Request $request)
    {
        $data = $request->validate([
            'memberIds' => ['required', 'array'],
            'memberIds.*' => ['required', 'exists:members,id'],
            'contributionPeriod' => ['required', 'string'],
            'contributionType' => ['nullable', Rule::in(['Monthly Dues', 'Cash Pabaon', 'Savings'])],
        ]);

        $memberIds = Contribution::query()
            ->whereIn('member_id', $data['memberIds'])
            ->where('contribution_period', $data['contributionPeriod'])
            ->where('contribution_type', $data['contributionType'] ?? 'Monthly Dues')
            ->where('status', 'Posted')
            ->pluck('member_id')
            ->map(fn ($id) => (string) $id)
            ->values();

        return response()->json(['memberIds' => $memberIds]);
    }

    public function store(ContributionRequest $request)
    {
        if (! $request->user()->hasPermission('contributions.create')) {
            abort(403, "You don't have permission to perform this action.");
        }

        if ($this->requireResolvedVoidedMonths()) {
            $blockingPeriod = $this->unresolvedVoidedPeriodBefore(
                $request->input('memberId'),
                $request->input('contributionType', 'Monthly Dues'),
                $request->input('contributionPeriod')
            );
            if ($blockingPeriod) {
                abort(422, "A voided {$request->input('contributionType', 'Monthly Dues')} contribution for {$blockingPeriod} has not been re-contributed yet. Post {$blockingPeriod} before proceeding to {$request->input('contributionPeriod')}.");
            }
        }

        $member = Member::find($request->input('memberId'));
        if ($member && ! MembershipFeePolicy::isSatisfied($member)) {
            abort(422, 'This member cannot contribute until their membership registration fee has been paid. Post it under Treasury > Payments first.');
        }

        $contribution = DB::transaction(function () use ($request) {
            $contribution = Contribution::create([
                'member_id' => $request->input('memberId'),
                'contribution_period' => $request->input('contributionPeriod'),
                'contribution_type' => $request->input('contributionType', 'Monthly Dues'),
                'amount' => $request->input('amount'),
                'payment_date' => $request->input('paymentDate'),
                'payment_method' => $request->input('paymentMethod'),
                'official_receipt_number' => $request->input('officialReceiptNumber'),
                'payroll_reference' => $request->input('payrollReference'),
                'remarks' => $request->input('remarks'),
                'encoded_by' => $request->user()->full_name,
                'status' => 'Posted',
            ]);
            $contribution->update(['reference_number' => app(DocumentNumberService::class)->generate('contribution', $contribution->id, $contribution->payment_date)]);
            $this->fundAllocator->allocate($contribution, $request->user());
            $this->postCashPabaonDeduction($contribution, $request->user()->full_name);

            return $contribution;
        });

        return new ContributionResource($contribution->load(['member.office', 'fundAllocations']));
    }

    /**
     * Cash Pabaon is a separate ₱200/month obligation paid alongside Monthly
     * Dues — when a Monthly Dues contribution is posted via payroll, this
     * automatically posts the matching Cash Pabaon deduction too, using the
     * "Cash Pabaon" DeductionType's configured default_amount. Mirrors the
     * identical logic already in bulkStore()'s postCashPabaon().
     */
    private function postCashPabaonDeduction(Contribution $contribution, string $encodedBy): void
    {
        if ($contribution->contribution_type !== 'Monthly Dues' || $contribution->payment_method !== 'Payroll Deduction') {
            return;
        }

        $pabaonType = DeductionType::query()->where('code', 'pabaon')->where('is_active', true)->first();
        if (! $pabaonType || (float) $pabaonType->default_amount <= 0) {
            return;
        }

        $existingDeduction = Deduction::query()
            ->where('member_id', $contribution->member_id)
            ->where('deduction_type_id', $pabaonType->id)
            ->where('period', $contribution->contribution_period)
            ->where('status', 'Posted')
            ->exists();

        if ($existingDeduction) {
            return;
        }

        $deduction = Deduction::create([
            'member_id' => $contribution->member_id,
            'deduction_type_id' => $pabaonType->id,
            'period' => $contribution->contribution_period,
            'amount' => $pabaonType->default_amount,
            'payment_date' => $contribution->payment_date,
            'payroll_reference' => $contribution->payroll_reference,
            'remarks' => 'Automatically posted with Monthly Dues contribution.',
            'encoded_by' => $encodedBy,
            'status' => 'Posted',
        ]);
        $deduction->update(['reference_number' => 'GCGEA-DED-'.now()->year.'-'.str_pad((string) $deduction->id, 6, '0', STR_PAD_LEFT)]);

        $this->postCashPabaonContribution(
            (string) $contribution->member_id, $contribution->contribution_period, (float) $pabaonType->default_amount,
            $contribution->payment_date, $contribution->payroll_reference, $encodedBy
        );
    }

    /**
     * Cash Pabaon is real money contributed alongside Monthly Dues, so besides the
     * payroll Deduction record it also gets its own Contribution record (type "Cash
     * Pabaon") — otherwise it never shows up in Contribution Records/reports. Guarded
     * by the same per-period idempotency check as the deduction so replays/edits don't
     * double-post.
     */
    private function postCashPabaonContribution(
        string $memberId, string $period, float $amount, string $paymentDate, ?string $payrollReference, string $encodedBy
    ): void {
        $existing = Contribution::query()
            ->where('member_id', $memberId)
            ->where('contribution_type', 'Cash Pabaon')
            ->where('contribution_period', $period)
            ->where('status', 'Posted')
            ->exists();

        if ($existing || $amount <= 0) {
            return;
        }

        $contribution = Contribution::create([
            'member_id' => $memberId,
            'contribution_period' => $period,
            'contribution_type' => 'Cash Pabaon',
            'amount' => $amount,
            'payment_date' => $paymentDate,
            'payment_method' => 'Payroll Deduction',
            'payroll_reference' => $payrollReference,
            'remarks' => 'Automatically posted alongside Monthly Dues payroll deduction.',
            'encoded_by' => $encodedBy,
            'status' => 'Posted',
        ]);
        $contribution->update(['reference_number' => app(DocumentNumberService::class)->generate('contribution', $contribution->id, $contribution->payment_date)]);
        $this->fundAllocator->allocate($contribution);
    }

    private function contributionSettings(): array
    {
        return SystemSetting::query()->where('section', 'contribution')->value('value') ?? [];
    }

    private function requireResolvedVoidedMonths(): bool
    {
        return (bool) ($this->contributionSettings()['requireResolvedVoidedMonths'] ?? true);
    }

    /**
     * Finds the earliest period before $period, for this member/type, that was
     * voided and has no Posted re-contribution recorded for that same period.
     */
    private function unresolvedVoidedPeriodBefore(string $memberId, string $contributionType, string $period): ?string
    {
        $voidedPeriods = Contribution::query()
            ->where('member_id', $memberId)
            ->where('contribution_type', $contributionType)
            ->where('status', 'Voided')
            ->where('contribution_period', '<', $period)
            ->orderBy('contribution_period')
            ->pluck('contribution_period')
            ->unique();

        foreach ($voidedPeriods as $voidedPeriod) {
            $resolved = Contribution::query()
                ->where('member_id', $memberId)
                ->where('contribution_type', $contributionType)
                ->where('contribution_period', $voidedPeriod)
                ->where('status', 'Posted')
                ->exists();

            if (! $resolved) {
                return $voidedPeriod;
            }
        }

        return null;
    }

    public function update(ContributionRequest $request, Contribution $contribution)
    {
        if (! $request->user()->hasPermission('contributions.update')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $contribution->update([
            'contribution_period' => $request->input('contributionPeriod', $contribution->contribution_period),
            'contribution_type' => $request->input('contributionType', $contribution->contribution_type),
            'amount' => $request->input('amount', $contribution->amount),
            'payment_date' => $request->input('paymentDate', $contribution->payment_date),
            'payment_method' => $request->input('paymentMethod', $contribution->payment_method),
            'official_receipt_number' => $request->input('officialReceiptNumber'),
            'payroll_reference' => $request->input('payrollReference'),
            'remarks' => $request->input('remarks'),
        ]);
        $this->fundAllocator->allocate($contribution, $request->user());

        return new ContributionResource($contribution->load(['member.office', 'fundAllocations']));
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

        DB::transaction(function () use ($request, $contribution) {
            $voidedAt = now();
            $reason = $request->string('reason')->toString();
            $voidedBy = $request->user()->full_name;

            $contribution->update([
                'status' => 'Voided',
                'void_reason' => $reason,
                'voided_by' => $voidedBy,
                'voided_at' => $voidedAt,
            ]);

            // Monthly Dues and its auto-posted Cash Pabaon form one grouped
            // payment — postCashPabaonDeduction() creates BOTH a Deduction
            // (payroll ledger) and a Contribution (contribution_type Cash
            // Pabaon, so it shows in Contribution Records/reports) for the
            // same real-world payment. Voiding the parent Monthly Dues must
            // atomically void both, not just the Deduction — otherwise the
            // Cash Pabaon Contribution is left Posted while its Deduction and
            // parent Monthly Dues both show Voided.
            if ($contribution->contribution_type === 'Monthly Dues') {
                $pabaonTypeId = DeductionType::query()->where('code', 'pabaon')->value('id');

                if ($pabaonTypeId) {
                    Deduction::query()
                        ->where('member_id', $contribution->member_id)
                        ->where('deduction_type_id', $pabaonTypeId)
                        ->where('period', $contribution->contribution_period)
                        ->where('status', 'Posted')
                        ->update([
                            'status' => 'Voided',
                            'void_reason' => $reason,
                            'voided_by' => $voidedBy,
                            'voided_at' => $voidedAt,
                        ]);
                }

                Contribution::query()
                    ->where('member_id', $contribution->member_id)
                    ->where('contribution_type', 'Cash Pabaon')
                    ->where('contribution_period', $contribution->contribution_period)
                    ->where('status', 'Posted')
                    ->update([
                        'status' => 'Voided',
                        'void_reason' => $reason,
                        'voided_by' => $voidedBy,
                        'voided_at' => $voidedAt,
                    ]);
            }
        });

        return new ContributionResource($contribution->load(['member.office', 'fundAllocations']));
    }

    public function bulkStore(Request $request)
    {
        if (! $request->user()->hasPermission('contributions.bulk_create')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $data = $request->validate([
            'contributionPeriod' => ['required', 'string'],
            'contributionType' => ['nullable', Rule::in(['Monthly Dues', 'Cash Pabaon', 'Savings'])],
            'paymentDate' => ['required', 'date'],
            'paymentMethod' => ['required', 'string'],
            'cashPabaonAmount' => ['nullable', 'numeric', 'min:0'],
            'payrollReference' => ['nullable', 'string'],
            'rows' => ['required', 'array'],
            'rows.*.memberId' => ['required', 'exists:members,id'],
            'rows.*.amount' => ['required', 'numeric'],
            'skipDuplicates' => ['boolean'],
            'replaceDuplicates' => ['boolean'],
        ]);

        $canReplace = $request->boolean('replaceDuplicates') && $request->user()->hasPermission('contributions.replace_duplicate');
        $encodedBy = $request->user()->full_name;
        // Bulk Contribution Entry is intentionally Monthly Dues-only. Cash
        // Pabaon is posted separately to the deductions ledger.
        $contributionType = 'Monthly Dues';
        $pabaonType = $data['paymentMethod'] === 'Payroll Deduction'
            ? DeductionType::query()->where('code', 'pabaon')->where('is_active', true)->first()
            : null;
        $requireResolvedVoidedMonths = $this->requireResolvedVoidedMonths();
        $result = [
            'saved' => 0,
            'skippedDuplicates' => 0,
            'skippedUnresolvedVoided' => 0,
            'replaced' => 0,
            'failed' => 0,
            'cashPabaonSaved' => 0,
            'cashPabaonSkipped' => 0,
        ];

        DB::transaction(function () use ($data, $canReplace, $encodedBy, $contributionType, $pabaonType, $requireResolvedVoidedMonths, &$result) {
            $postCashPabaon = function (string $memberId) use ($data, $encodedBy, $pabaonType, &$result): void {
                $cashPabaonAmount = (float) ($data['cashPabaonAmount'] ?? $pabaonType?->default_amount ?? 0);
                if (! $pabaonType || $cashPabaonAmount <= 0) {
                    return;
                }

                $existingDeduction = Deduction::query()
                    ->where('member_id', $memberId)
                    ->where('deduction_type_id', $pabaonType->id)
                    ->where('period', $data['contributionPeriod'])
                    ->where('status', 'Posted')
                    ->exists();

                if ($existingDeduction) {
                    $result['cashPabaonSkipped']++;

                    return;
                }

                $deduction = Deduction::create([
                    'member_id' => $memberId,
                    'deduction_type_id' => $pabaonType->id,
                    'period' => $data['contributionPeriod'],
                    'amount' => $cashPabaonAmount,
                    'payment_date' => $data['paymentDate'],
                    'payroll_reference' => $data['payrollReference'] ?? null,
                    'remarks' => 'Automatically posted with bulk Monthly Dues.',
                    'encoded_by' => $encodedBy,
                    'status' => 'Posted',
                ]);
                $deduction->update([
                    'reference_number' => 'GCGEA-DED-'.now()->year.'-'.str_pad((string) $deduction->id, 6, '0', STR_PAD_LEFT),
                ]);
                $result['cashPabaonSaved']++;

                $this->postCashPabaonContribution(
                    $memberId, $data['contributionPeriod'], $cashPabaonAmount,
                    $data['paymentDate'], $data['payrollReference'] ?? null, $encodedBy
                );
            };

            foreach ($data['rows'] as $row) {
                if ($row['amount'] <= 0) {
                    $result['failed']++;

                    continue;
                }

                if ($requireResolvedVoidedMonths && $this->unresolvedVoidedPeriodBefore(
                    (string) $row['memberId'], $contributionType, $data['contributionPeriod']
                )) {
                    $result['skippedUnresolvedVoided']++;

                    continue;
                }

                $existing = Contribution::where('member_id', $row['memberId'])
                    ->where('contribution_period', $data['contributionPeriod'])
                    ->where('contribution_type', $contributionType)
                    ->where('status', 'Posted')
                    ->first();

                if ($existing && $canReplace) {
                    $existing->update([
                        'amount' => $row['amount'],
                        'payment_date' => $data['paymentDate'],
                        'payment_method' => $data['paymentMethod'],
                        'payroll_reference' => $data['payrollReference'] ?? null,
                    ]);
                    $this->fundAllocator->allocate($existing);
                    $result['replaced']++;
                    $postCashPabaon((string) $row['memberId']);

                    continue;
                }

                if ($existing && ($data['skipDuplicates'] ?? false)) {
                    $result['skippedDuplicates']++;

                    continue;
                }

                $contribution = Contribution::create([
                    'member_id' => $row['memberId'],
                    'contribution_period' => $data['contributionPeriod'],
                    'contribution_type' => $contributionType,
                    'amount' => $row['amount'],
                    'payment_date' => $data['paymentDate'],
                    'payment_method' => $data['paymentMethod'],
                    'payroll_reference' => $data['payrollReference'] ?? null,
                    'encoded_by' => $encodedBy,
                    'status' => 'Posted',
                ]);
                $contribution->update(['reference_number' => app(DocumentNumberService::class)->generate('contribution', $contribution->id, $contribution->payment_date)]);
                $this->fundAllocator->allocate($contribution);
                $result['saved']++;
                $postCashPabaon((string) $row['memberId']);
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
        if ($type = $request->string('contributionType')->toString()) {
            $query->where('contribution_type', $type);
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
