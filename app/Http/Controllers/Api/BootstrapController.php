<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PipelineStage;
use App\Support\TenantContext;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant, SubscriptionService $subscriptions)
    {
        $user = $request->user();
        $membership = $request->attributes->get('membership');
        $company = $tenant->company();
        $companyData = $company->toArray();
        unset($companyData['logo_path']);
        $companyData['logo_url'] = $company->logo_path ? route('company.logo') : null;
        $companies = $user->companies()->wherePivot('status', 'active')->get()->map(fn ($company) => [
            'id' => $company->id, 'name' => $company->name, 'brand_color' => $company->brand_color,
            'role' => $company->pivot->role,
        ]);

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'locale' => $user->locale],
            'is_platform_admin' => (bool) $user->is_platform_admin,
            'company' => $companyData,
            'subscription' => $subscriptions->snapshot($company),
            'companies' => $companies,
            'membership' => ['role' => $membership->role, 'permissions' => $membership->permissions ?? []],
            'stages' => PipelineStage::where('company_id', $tenant->id())->orderBy('position')->get(),
            'members' => $tenant->company()->users()->wherePivot('status', 'active')->get(['users.id', 'users.name', 'users.email']),
        ]);
    }

    public function switchCompany(Request $request, int $company)
    {
        abort_unless($request->user()->membershipFor($company), 403);
        $request->user()->update(['current_company_id' => $company]);
        return response()->json(['message' => 'Workspace changed.']);
    }
}
