<?php

namespace App\Http\Controllers;

use App\Http\Resources\BenefitTypeResource;
use App\Http\Resources\DeductionTypeResource;
use App\Http\Resources\LoanSettingResource;
use App\Http\Resources\LoanTypeResource;
use App\Http\Resources\OfficeResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\SystemUserResource;
use App\Models\BenefitType;
use App\Models\DeductionType;
use App\Models\LoanSetting;
use App\Models\LoanType;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;

class LookupsController extends Controller
{
    /**
     * Bundles a handful of small, rarely-changing reference lists that used to be fetched
     * independently by 15+ different pages (Roles/Users/Offices pickers, Loan/Benefit
     * application forms, the Approval Workflow approver pickers, ...). None of these carry
     * their own permission gate — same as the individual endpoints they mirror — so bundling
     * changes no access behavior, just collapses up to 7 first-load requests into 1.
     *
     * Queries intentionally mirror RoleController::all(), UserController::all(),
     * OfficeController::all(), LoanTypeController::index(), BenefitTypeController::index(),
     * DeductionTypeController::index(), and LoanSettingsController::show() exactly, so this
     * endpoint never drifts from what those return individually.
     */
    public function index()
    {
        return response()->json([
            'roles' => RoleResource::collection(
                Role::with('permissions')->withCount(['primaryUsers', 'additionalUsers'])->orderBy('name')->get()
            ),
            'users' => SystemUserResource::collection(
                User::with(['role', 'additionalRoles', 'allowedPermissions', 'deniedPermissions'])
                    ->where('status', 'Active')->orderBy('full_name')->get()
            ),
            'offices' => OfficeResource::collection(Office::withCount('members')->orderBy('name')->get()),
            'loanTypes' => LoanTypeResource::collection(
                LoanType::with('incomeBrackets')->orderByDesc('created_at')->get()
            ),
            'benefitTypes' => BenefitTypeResource::collection(
                BenefitType::with(['prorationTiers', 'fyAmounts'])->orderByDesc('created_at')->get()
            ),
            'deductionTypes' => DeductionTypeResource::collection(
                DeductionType::orderBy('sort_order')->orderBy('name')->get()
            ),
            'loanSettings' => new LoanSettingResource(LoanSetting::current()),
        ]);
    }
}
