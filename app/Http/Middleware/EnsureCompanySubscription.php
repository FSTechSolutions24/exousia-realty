<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class EnsureCompanySubscription
{
    public function __construct(private SubscriptionService $subscriptions, private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next)
    {
        $company = $this->tenant->company();
        if ($company->is_suspended) {
            return response()->json(['code' => 'company_suspended', 'message' => 'This company workspace has been suspended. Contact Exousia support for assistance.'], 403);
        }
        $subscription = $this->subscriptions->current($company);
        if (! $subscription) {
            return response()->json([
                'message' => 'This company needs an active subscription to use the workspace.',
                'code' => 'subscription_required',
                'subscription' => $this->subscriptions->snapshot($company),
            ], 402);
        }

        return $next($request);
    }
}
