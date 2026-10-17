<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        $membership = $request->attributes->get('membership');
        $query = Branch::where('company_id', $tenant->id());
        if (! $membership->can('view_all_branches')) {
            $query->whereHas('memberships', fn ($memberships) => $memberships->where('company_memberships.id', $membership->id));
        }
        if ($membership->can('manage_branches')) $query->with('memberships:id');

        return response()->json([
            'current_branch_id' => $request->attributes->get('branch')?->id,
            'branches' => $query->withCount('memberships')->orderByDesc('is_main')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, TenantContext $tenant, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('manage_branches'), 403);
        $data = $this->validateBranch($request, $tenant);
        $branch = DB::transaction(function () use ($tenant, $data) {
            $company = \App\Models\Company::whereKey($tenant->id())->lockForUpdate()->firstOrFail();
            $this->ensureBranchCapacity($company);
            return Branch::create($data + ['company_id' => $tenant->id()]);
        });
        $audit->log($request, 'branch.created', $branch, [], $branch->only(['name', 'code', 'phone', 'address']));
        return response()->json($branch, 201);
    }

    public function update(Request $request, TenantContext $tenant, int $branch, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('manage_branches'), 403);
        $branch = Branch::where('company_id', $tenant->id())->findOrFail($branch);
        $data = $this->validateBranch($request, $tenant, $branch->id, true);
        $old = $branch->only(array_keys($data));
        $branchId = $branch->id;
        DB::transaction(function () use ($tenant, $branchId, $data, &$branch) {
            // Serialize branch deactivations per company so concurrent requests
            // cannot both observe two active branches and deactivate both.
            Company::whereKey($tenant->id())->lockForUpdate()->firstOrFail();
            $branch = Branch::where('company_id', $tenant->id())->lockForUpdate()->findOrFail($branchId);
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                if ($branch->is_main) throw ValidationException::withMessages(['is_active' => 'The main branch cannot be deactivated.']);
                if (Branch::where('company_id', $tenant->id())->where('is_active', true)->count() <= 1) {
                    throw ValidationException::withMessages(['is_active' => 'A company must keep at least one active branch.']);
                }
            }
            if (array_key_exists('is_active', $data) && $data['is_active'] && ! $branch->is_active) {
                $this->ensureBranchCapacity(Company::findOrFail($tenant->id()));
            }
            $branch->update($data);
        });
        $audit->log($request, 'branch.updated', $branch, $old, $data);
        return response()->json($branch);
    }

    public function members(Request $request, TenantContext $tenant, int $branch, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('manage_branches'), 403);
        $branch = Branch::where('company_id', $tenant->id())->findOrFail($branch);
        $data = $request->validate([
            'membership_ids' => ['array', 'max:500'],
            'membership_ids.*' => ['integer', 'distinct', Rule::exists('company_memberships', 'id')->where('company_id', $tenant->id())->where('status', 'active')],
        ]);
        $ids = $data['membership_ids'] ?? [];
        DB::transaction(function () use ($branch, $ids, $tenant) {
            DB::table('branch_memberships')->where('branch_id', $branch->id)->delete();
            foreach ($ids as $id) DB::table('branch_memberships')->insert(['company_id' => $tenant->id(), 'branch_id' => $branch->id, 'company_membership_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        });
        $audit->log($request, 'branch.members_updated', $branch, [], ['membership_ids' => $ids]);
        return response()->json(['membership_ids' => $ids]);
    }

    private function validateBranch(Request $request, TenantContext $tenant, ?int $id = null, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'code' => [$partial ? 'sometimes' : 'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('branches', 'code')->where('company_id', $tenant->id())->ignore($id)],
            'phone' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function ensureBranchCapacity(Company $company): void
    {
        $limit = app(\App\Services\SubscriptionService::class)->current($company)?->plan?->branch_limit;
        if ($limit === null) return;
        $activeBranches = Branch::where('company_id', $company->id)->where('is_active', true)->count();
        if ($activeBranches >= $limit) {
            throw ValidationException::withMessages(['plan' => "Your current plan allows up to {$limit} active branches. Upgrade your plan to add another."]);
        }
    }
}
