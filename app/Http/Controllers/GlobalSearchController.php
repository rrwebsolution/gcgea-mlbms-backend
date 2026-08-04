<?php

namespace App\Http\Controllers;

use App\Models\BenefitApplication;
use App\Models\Contribution;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($data['q'])).'%';
        $like = fn ($query, string $column) => $query->where($column, 'ilike', $term);

        $members = Member::query()
            ->where('is_archived', false)->where('is_draft', false)
            ->where(fn ($q) => $like($q, 'member_number')->orWhere('first_name', 'ilike', $term)->orWhere('surname', 'ilike', $term)->orWhere('employee_number', 'ilike', $term))
            ->limit(8)->get()->map(fn ($m) => ['id' => (string) $m->id, 'fullName' => $m->full_name, 'memberNumber' => $m->member_number]);

        $loans = Loan::query()->with('member:id,first_name,middle_name,surname,suffix')
            ->where(fn ($q) => $like($q, 'application_number')->orWhereHas('member', fn ($m) => $m->where('first_name', 'ilike', $term)->orWhere('surname', 'ilike', $term)))
            ->limit(8)->get()->map(fn ($l) => ['id' => (string) $l->id, 'applicationNumber' => $l->application_number, 'memberName' => $l->member?->full_name]);

        $benefits = BenefitApplication::query()->with('member:id,first_name,middle_name,surname,suffix')
            ->where(fn ($q) => $like($q, 'application_number')->orWhereHas('member', fn ($m) => $m->where('first_name', 'ilike', $term)->orWhere('surname', 'ilike', $term)))
            ->limit(8)->get()->map(fn ($b) => ['id' => (string) $b->id, 'applicationNumber' => $b->application_number, 'memberName' => $b->member?->full_name]);

        $contributions = Contribution::query()->with('member:id,member_number,first_name,middle_name,surname,suffix')
            ->where(fn ($q) => $like($q, 'reference_number')->orWhere('contribution_period', 'ilike', $term)->orWhereHas('member', fn ($m) => $m->where('member_number', 'ilike', $term)->orWhere('first_name', 'ilike', $term)->orWhere('surname', 'ilike', $term)))
            ->limit(8)->get()->map(fn ($c) => ['id' => (string) $c->id, 'referenceNumber' => $c->reference_number, 'contributionPeriod' => $c->contribution_period, 'memberName' => $c->member?->full_name, 'memberNumber' => $c->member?->member_number]);

        $users = User::query()->where(fn ($q) => $like($q, 'full_name')->orWhere('username', 'ilike', $term))->limit(8)->get(['id', 'full_name', 'username'])->map(fn ($u) => ['id' => (string) $u->id, 'fullName' => $u->full_name, 'username' => $u->username]);
        $roles = Role::query()->where(fn ($q) => $like($q, 'name')->orWhere('code', 'ilike', $term))->limit(8)->get(['id', 'name', 'code']);
        $offices = Office::query()->where(fn ($q) => $like($q, 'name')->orWhere('code', 'ilike', $term))->limit(8)->get(['id', 'name', 'code']);

        return response()->json(compact('members', 'loans', 'benefits', 'contributions', 'users', 'roles', 'offices'));
    }
}
