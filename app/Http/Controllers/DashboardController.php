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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary()
    {
        $currentPeriod = now()->format('Y-m');

        return response()->json([
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
                'balance' => (float) $fund->allocations()
                    ->whereHas('contribution', fn ($q) => $q->where('status', 'Posted'))
                    ->sum('allocated_amount'),
            ])->values(),

            'pendingReloanApplications' => Loan::where('application_type', 'reloan')->where('status', 'Submitted')->count(),
            'reloansAwaitingReview' => Loan::where('application_type', 'reloan')->where('status', 'Under Review')->count(),
            'approvedReloans' => Loan::where('application_type', 'reloan')->where('status', 'Approved')->count(),
            'reloansAwaitingRelease' => Loan::where('application_type', 'reloan')->where('status', 'Approved')->count(),
            'membersBecomingLoanEligibleThisMonth' => $this->membersBecomingEligibleThisMonth(),
        ]);
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
        $rows = Loan::whereNotNull('release_date')
            ->select(DB::raw("to_char(release_date, 'YYYY-MM') as month"), DB::raw('sum(actual_released_amount) as amount'))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json($rows->map(fn ($r) => ['month' => $r->month, 'amount' => (float) $r->amount]));
    }

    public function monthlyCollections()
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

        return response()->json($months->map(fn ($month) => [
            'month' => $month,
            'contributions' => (float) ($contributionsByMonth[$month] ?? 0),
            'loanPayments' => (float) ($paymentsByMonth[$month] ?? 0),
        ])->values());
    }

    public function loanStatusDistribution()
    {
        $rows = Loan::select('status', DB::raw('count(*) as count'))->groupBy('status')->get();

        return response()->json($rows->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->count]));
    }

    public function benefitDistributionByType()
    {
        $rows = BenefitApplication::join('benefit_types', 'benefit_types.id', '=', 'benefit_applications.benefit_type_id')
            ->select('benefit_types.name as type', DB::raw('count(*) as count'))
            ->groupBy('benefit_types.name')
            ->get();

        return response()->json($rows->map(fn ($r) => ['type' => $r->type, 'count' => (int) $r->count]));
    }

    public function membersPerOffice()
    {
        $rows = Office::where('status', 'Active')
            ->withCount(['members' => fn ($q) => $q->where('is_archived', false)])
            ->get()
            ->filter(fn ($o) => $o->members_count > 0)
            ->sortByDesc('members_count')
            ->take(8)
            ->values();

        return response()->json($rows->map(fn ($o) => ['office' => $o->name, 'count' => $o->members_count]));
    }

    public function membershipGrowth()
    {
        $rows = Member::where('is_archived', false)
            ->select(DB::raw('extract(year from membership_date) as year'), DB::raw('count(*) as count'))
            ->groupBy('year')
            ->orderBy('year')
            ->get();

        $running = 0;

        return response()->json($rows->map(function ($r) use (&$running) {
            $running += (int) $r->count;

            return ['year' => (string) (int) $r->year, 'count' => $running];
        }));
    }

    public function recentLoanApplications(Request $request)
    {
        return LoanResource::collection(
            Loan::with(['member.office', 'loanType'])->orderByDesc('application_date')->limit($request->integer('limit', 5))->get()
        );
    }

    public function recentPayments(Request $request)
    {
        return LoanPaymentResource::collection(
            LoanPayment::with(['member', 'loan'])->orderByDesc('payment_date')->limit($request->integer('limit', 5))->get()
        );
    }

    public function upcomingDueLoans(Request $request)
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
            ->take($request->integer('limit', 5))
            ->values();

        return response()->json($results->map(fn ($pair) => [
            'loan' => (new LoanResource($pair['loan']))->resolve(),
            'entry' => (new AmortizationEntryResource($pair['entry']))->resolve(),
        ]));
    }

    public function overdueLoans(Request $request)
    {
        return LoanResource::collection(
            Loan::with(['member.office', 'loanType'])->where('status', 'Overdue')->limit($request->integer('limit', 5))->get()
        );
    }

    public function recentBenefitApplications(Request $request)
    {
        return BenefitApplicationResource::collection(
            BenefitApplication::with(['member.office', 'benefitType'])->orderByDesc('application_date')->limit($request->integer('limit', 5))->get()
        );
    }

    public function recentlyAddedMembers(Request $request)
    {
        return MemberResource::collection(
            Member::with(['office', 'beneficiaries', 'documents'])->where('is_archived', false)->orderByDesc('created_at')->limit($request->integer('limit', 5))->get()
        );
    }

    public function incompleteProfiles(Request $request)
    {
        $members = Member::with(['office', 'beneficiaries', 'documents'])
            ->where('is_archived', false)
            ->where(function ($q) {
                $q->whereNull('email')->orWhere('email', '')
                    ->orWhereNull('cellphone_number')->orWhere('cellphone_number', '')
                    ->orWhereNull('permanent_address')->orWhere('permanent_address', '')
                    ->orWhereDoesntHave('beneficiaries')
                    ->orWhereDoesntHave('documents');
            })
            ->limit($request->integer('limit', 5))
            ->get();

        return MemberResource::collection($members);
    }
}
