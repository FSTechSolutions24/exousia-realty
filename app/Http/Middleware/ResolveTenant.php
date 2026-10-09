<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $requestedId = $request->header('X-Company-ID');
        $membership = $requestedId ? $user->membershipFor((int) $requestedId) : null;

        if (! $membership && $user->current_company_id) {
            $membership = $user->membershipFor((int) $user->current_company_id);
        }

        if (! $membership) {
            $membership = $user->companies()
                ->wherePivot('status', 'active')
                ->first()?->pivot;
        }

        if (! $membership) {
            return response()->json(['message' => 'No active company membership was found.'], 403);
        }

        $company = $user->companies()->whereKey($membership->company_id)->firstOrFail();
        app(TenantContext::class)->set($company);
        $request->attributes->set('membership', $user->membershipFor($company->id));

        return $next($request);
    }
}
