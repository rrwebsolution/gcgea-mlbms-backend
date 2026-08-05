<?php

namespace App\Http\Controllers;

use App\Http\Resources\AmortizationEntryResource;
use App\Http\Resources\BenefitApplicationResource;
use App\Http\Resources\LoanPaymentResource;
use App\Http\Resources\LoanResource;
use App\Http\Resources\MemberResource;
use App\Models\BenefitApplication;
use App\Models\Contribution;
use App\Models\ContributionFund;
use App\Models\Loan;
use App\Models\LoanAmortizationEntry;
use App\Models\LoanPayment;
use App\Models\LoanSetting;
use App\Models\Member;
use App\Models\Office;
use App\Services\FundLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Everything the Dashboard page needs, in one round trip. The page used to fire 13
     * separate requests on every load (one per widget) — each cheap on its own, but their
     * sum was the single biggest source of request volume in the app, and the built-in dev
     * server processes them one at a time, so the page visibly loaded in a slow drip.
     * The individual endpoints below are kept as-is for any other/future callers; this
     * action just calls the same private helpers they do, so the two never drift apart.
     */
    public function overview(Request $request)
    {
        $limit = $request->integer('limit', 5);

        return response()->json([
            'summary' => $this->summaryData(),
            'monthlyReleases' => $this->monthlyLoanReleasesData(),
            'monthlyCollections' => $this->monthlyCollectionsData(),
            'loanStatus' => $this->loanStatusDistributionData(),
            'benefitDistribution' => $this->benefitDistributionByTypeData(),
            'membersPerOffice' => $this->membersPerOfficeData(),
            'membershipGrowth' => $this->membershipGrowthData(),
            'recentLoans' => LoanResource::collection($this->recentLoanApplicationsQuery($limit)->get()),
            'recentPayments' => LoanPaymentResource::collection($this->recentPaymentsQuery($limit)->get()),
            'upcomingDue' => $this->upcomingDueLoansData($limit),
            'overdueLoans' => LoanResource::collection($this->overdueLoansQuery($limit)->get()),
            'recentBenefits' => BenefitApplicationResource::collection($this->recentBenefitApplicationsQuery($limit)->get()),
            'recentMembers' => MemberResource::collection($this->recentlyAddedMembersQuery($limit)->get()),
            'incompleteProfiles' => MemberResource::collection($this->incompleteProfilesQuery($limit)->get()),
        ]);
    }

    public function summary()
    {
        return response()->json($this->summaryData());
    }

    private function summaryData(): array
    {
        $currentPeriod = now()->format('Y-m');

        return [
            'totalMembers' => Member::where('is_archived', false)->count(),
            'activeMembers' => Member::where('is_archived', false)->where('membership_status', 'Active')->count(),
            'retiredMembers' => Member::where('is_archived', false)->where('retiree_status', 'Retired')->count(),

            'pendingLoanApplications' => Loan::whereIn('status', ['Submitted', 'Under Review', 'For Approval'])->count(),
            'activeLoans' => Loan::whereIn('status', ['Active', 'Overdue'])->count(),
            'outstandingLoanBalance' => (float) Loan::sum('outstanding_balance'),
            'totalLoanCollections' => (float) LoanPayment::where('status', 'Posted')->sum('amount_paid'),

            'pendingBenefitApplications' => BenefitApplication::whereIn('status', ['Submitted', 'Under Review', 'For Approval'])->count(),
            'benefitsReleased' => BenefitApplication::whereIn('status', ['Released', 'Completed'])->count(),

            'monthlyContributionsCollected' => (float) Contribution::where('status', 'Posted')
                ->where('contribution_period', $currentPeriod)
                ->sum('amount'),
            'fundBalances' => ContributionFund::query()->where('is_enabled', true)->orderBy('display_order')->get()->map(fn ($fund) => [
                'fundId' => (string) $fund->id,
                'fundName' => $fund->fund_name,
                'balance' => app(FundLedgerService::class)->balance($fund),
            ])->values(),

            'pendingReloanApplications' => Loan::where('application_type', 'reloan')->where('status', 'Submitted')->count(),
            'reloansAwaitingReview' => Loan::where('application_type', 'reloan')->where('status', 'Under Review')->count(),
            'approvedReloans' => Loan::where('application_type', 'reloan')->where('status', 'Approved')->count(),
            'reloansAwaitingRelease' => Loan::where('application_type', 'reloan')->where('status', 'Approved')->count(),
            'membersBecomingLoanEligibleThisMonth' => $this->membersBecomingEligibleThisMonth(),
        ];
    }

    private function membersBecomingEligibleThisMonth(): int
    {
        $requiredMonths = LoanSetting::current()->minimum_membership_months;
        $windowStart = now()->subMonths($requiredMonths)->startOfMonth();
        $windowEnd = now()->subMonths($requiredMonths)->endOfMonth();

        return Member::where('is_archived', false)
            ->where('membership_status', 'Active')
            ->whereBetween('membership_date', [$windowStart, $windowEnd])
            ->count();
    }

    public function monthlyLoanReleases()
    {
        return response()->json($this->monthlyLoanReleasesData());
    }

    private function monthlyLoanReleasesData(): array
    {
        $rows = Loan::whereNotNull('release_date')
            ->select(DB::raw("to_char(release_date, 'YYYY-MM') as month"), DB::raw('sum(actual_released_amount) as amount'))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $rows->map(fn ($r) => ['month' => $r->month, 'amount' => (float) $r->amount])->all();
    }

    public function monthlyCollections()
    {
        return response()->json($this->monthlyCollectionsData());
    }

    private function monthlyCollectionsData(): array
    {
        $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i)->format('Y-m'));

        $contributionsByMonth = Contribution::where('status', 'Posted')
            ->whereIn('contribution_period', $months)
            ->select('contribution_period', DB::raw('sum(amount) as total'))
            ->groupBy('contribution_period')
            ->pluck('total', 'contribution_period');

        $paymentsByMonth = LoanPayment::where('status', 'Posted')
            ->select(DB::raw("to_char(payment_date, 'YYYY-MM') as month"), DB::raw('sum(amount_paid) as total'))
            ->groupBy('month')
            ->pluck('total', 'month');

        return $months->map(fn ($month) => [
            'month' => $month,
            'contributions' => (float) ($contributionsByMonth[$month] ?? 0),
            'loanPayments' => (float) ($paymentsByMonth[$month] ?? 0),
        ])->values()->all();
    }

    public function loanStatusDistribution()
    {
        return response()->json($this->loanStatusDistributionData());
    }

    private function loanStatusDistributionData(): array
    {
        $rows = Loan::select('status', DB::raw('count(*) as count'))->groupBy('status')->get();

        return $rows->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->count])->all();
    }

    public function benefitDistributionByType()
    {
        return response()->json($this->benefitDistributionByTypeData());
    }

    private function benefitDistributionByTypeData(): array
    {
        $rows = BenefitApplication::join('benefit_types', 'benefit_types.id', '=', 'benefit_applications.benefit_type_id')
            ->select('benefit_types.name as type', DB::raw('count(*) as count'))
            ->groupBy('benefit_types.name')
            ->get();

        return $rows->map(fn ($r) => ['type' => $r->type, 'count' => (int) $r->count])->all();
    }

    public function membersPerOffice()
    {
        return response()->json($this->membersPerOfficeData());
    }

    private function membersPerOfficeData(): array
    {
        $rows = Office::where('status', 'Active')
            ->withCount(['members' => fn ($q) => $q->where('is_archived', false)])
            ->get()
            ->filter(fn ($o) => $o->members_count > 0)
            ->sortByDesc('members_count')
            ->take(8)
            ->values();

        return $rows->map(fn ($o) => ['office' => $o->name, 'count' => $o->members_count])->all();
    }

    public function membershipGrowth()
    {
        return response()->json($this->membershipGrowthData());
    }

    private function membershipGrowthData(): array
    {
        $rows = Member::where('is_archived', false)
            ->select(DB::raw('extract(year from membership_date) as year'), DB::raw('count(*) as count'))
            ->groupBy('year')
            ->orderBy('year')
            ->get();

        $running = 0;

        return $rows->map(function ($r) use (&$running) {
            $running += (int) $r->count;

            return ['year' => (string) (int) $r->year, 'count' => $running];
        })->all();
    }

    public function recentLoanApplications(Request $request)
    {
        return LoanResource::collection($this->recentLoanApplicationsQuery($request->integer('limit', 5))->get());
    }

    private function recentLoanApplicationsQuery(int $limit)
    {
        return Loan::with(['member.office', 'loanType'])->orderByDesc('application_date')->limit($limit);
    }

    public function recentPayments(Request $request)
    {
        return LoanPaymentResource::collection($this->recentPaymentsQuery($request->integer('limit', 5))->get());
    }

    private function recentPaymentsQuery(int $limit)
    {
        return LoanPayment::with(['member', 'loan'])->orderByDesc('payment_date')->limit($limit);
    }

    public function upcomingDueLoans(Request $request)
    {
        return response()->json($this->upcomingDueLoansData($request->integer('limit', 5)));
    }

    private function upcomingDueLoansData(int $limit): array
    {
        $loans = Loan::with(['member.office', 'loanType'])->where('status', 'Active')->get();
        $cutoff = now()->addDays(14);

        $results = $loans
            ->map(function (Loan $loan) use ($cutoff) {
                $entry = LoanAmortizationEntry::where('loan_id', $loan->id)
                    ->where('status', 'Upcoming')
                    ->where('due_date', '<=', $cutoff)
                    ->orderBy('due_date')
                    ->first();

                return $entry ? ['loan' => $loan, 'entry' => $entry] : null;
            })
            ->filter()
            ->sortBy(fn ($pair) => $pair['entry']->due_date)
            ->take($limit)
            ->values();

        return $results->map(fn ($pair) => [
            'loan' => (new LoanResource($pair['loan']))->resolve(),
            'entry' => (new AmortizationEntryResource($pair['entry']))->resolve(),
        ])->all();
    }

    public function overdueLoans(Request $request)
    {
        return LoanResource::collection($this->overdueLoansQuery($request->integer('limit', 5))->get());
    }

    private function overdueLoansQuery(int $limit)
    {
        return Loan::with(['member.office', 'loanType'])->where('status', 'Overdue')->limit($limit);
    }

    public function recentBenefitApplications(Request $request)
    {
        return BenefitApplicationResource::collection($this->recentBenefitApplicationsQuery($request->integer('limit', 5))->get());
    }

    private function recentBenefitApplicationsQuery(int $limit)
    {
        return BenefitApplication::with(['member.office', 'benefitType'])->orderByDesc('application_date')->limit($limit);
    }

    public function recentlyAddedMembers(Request $request)
    {
        return MemberResource::collection($this->recentlyAddedMembersQuery($request->integer('limit', 5))->get());
    }

    private function recentlyAddedMembersQuery(int $limit)
    {
        return Member::with(['office', 'beneficiaries', 'documents'])->where('is_archived', false)->orderByDesc('created_at')->limit($limit);
    }

    public function incompleteProfiles(Request $request)
    {
        return MemberResource::collection($this->incompleteProfilesQuery($request->integer('limit', 5))->get());
    }

    private function incompleteProfilesQuery(int $limit)
    {
        return Member::with(['office', 'beneficiaries', 'documents'])
            ->where('is_archived', false)
            ->where(function ($q) {
                $q->whereNull('email')->orWhere('email', '')
                    ->orWhereNull('cellphone_number')->orWhere('cellphone_number', '')
                    ->orWhereNull('permanent_address')->orWhere('permanent_address', '')
                    ->orWhereDoesntHave('beneficiaries')
                    ->orWhereDoesntHave('documents');
            })
            ->limit($limit);
    }
}
