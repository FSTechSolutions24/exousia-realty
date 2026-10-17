<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PlatformAdminAction;
use App\Models\PropertyListing;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlatformAdminController extends Controller
{
    public function bootstrap(Request $request)
    {
        return response()->json(['user' => [
            'id' => $request->user()->id, 'name' => $request->user()->name, 'email' => $request->user()->email,
        ], 'is_platform_admin' => true]);
    }

    public function overview(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        $currentSubscriptions = CompanySubscription::query()->whereIn('status', ['active', 'trialing'])
            ->where('starts_at', '<=', now())->where('ends_at', '>', now());
        $payments = SubscriptionPayment::query()->where('status', 'paid')->whereBetween('paid_at', [$from, $to]);

        $metrics = [
            'companies' => Company::count(),
            'active_companies' => (clone $currentSubscriptions)->where('is_free_trial', false)->whereHas('company', fn ($q) => $q->where('is_suspended', false))->distinct('company_id')->count('company_id'),
            'trial_companies' => (clone $currentSubscriptions)->where('is_free_trial', true)->distinct('company_id')->count('company_id'),
            'suspended_companies' => Company::where('is_suspended', true)->count(),
            'users' => User::whereHas('memberships', fn ($q) => $q->where('status', 'active'))->distinct()->count(),
            'leads' => Lead::count(),
            'listings' => PropertyListing::count(),
            'deals' => Deal::count(),
            'closed_deal_value_minor' => (int) Deal::whereIn('status', ['won', 'closed'])->sum('agreed_price_minor_units'),
            'revenue_minor' => (int) (clone $payments)->sum('amount_minor'),
            'paid_payments' => (clone $payments)->count(),
            'pending_payments' => SubscriptionPayment::where('status', 'pending')->count(),
        ];

        $revenue = collect(range(11, 0))->map(function ($offset) {
            $month = now()->startOfMonth()->subMonths($offset);
            return [
                'label' => $month->format('M y'),
                'amount_minor' => (int) SubscriptionPayment::where('status', 'paid')
                    ->whereBetween('paid_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])->sum('amount_minor'),
            ];
        })->values();

        $plans = SubscriptionPlan::active()->orderBy('position')->get()->map(function ($plan) {
            $count = CompanySubscription::where('plan_id', $plan->id)->whereIn('status', ['active', 'trialing'])
                ->where('starts_at', '<=', now())->where('ends_at', '>', now())->distinct('company_id')->count('company_id');
            return ['name' => $plan->name, 'slug' => $plan->slug, 'companies' => $count];
        });

        $companies = Company::with(['latestSubscription.plan', 'memberships' => fn ($q) => $q->where('role', 'owner')->where('status', 'active')->with('user:id,name,email')])
            ->withCount(['memberships as active_members_count' => fn ($q) => $q->where('status', 'active'), 'leads', 'propertyListings', 'deals'])
            ->latest()->limit(8)->get()->map(fn ($company) => $this->serializeCompany($company));

        $recentPayments = SubscriptionPayment::with(['company:id,name', 'plan:id,name'])->whereBetween('created_at', [$from, $to])->latest()->limit(30)->get()->map(fn ($payment) => [
            'id' => $payment->id, 'company' => $payment->company?->name ?? 'Deleted company', 'plan' => $payment->plan->name,
            'cycle' => $payment->billing_cycle, 'amount_minor' => $payment->amount_minor, 'currency' => $payment->currency,
            'status' => $payment->status, 'paid_at' => $payment->paid_at?->toIso8601String(), 'created_at' => $payment->created_at->toIso8601String(),
        ]);

        $actions = PlatformAdminAction::with(['admin:id,name', 'company:id,name'])->latest('created_at')->limit(10)->get()->map(fn ($action) => [
            'id' => $action->id, 'admin' => $action->admin?->name ?? 'Deleted admin', 'company' => $action->company?->name ?? 'Platform',
            'action' => $action->action, 'reason' => $action->reason, 'created_at' => $action->created_at->toIso8601String(),
        ]);

        return response()->json(['metrics' => $metrics, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'revenue_series' => $revenue, 'plan_mix' => $plans, 'companies' => $companies, 'payments' => $recentPayments, 'actions' => $actions]);
    }

    public function companies(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', 'active', 'trial', 'expired', 'scheduled', 'suspended'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = Company::query()->with(['latestSubscription.plan', 'memberships' => fn ($q) => $q->where('role', 'owner')->where('status', 'active')->with('user:id,name,email')])
            ->withCount(['memberships as active_members_count' => fn ($q) => $q->where('status', 'active'), 'leads', 'propertyListings', 'deals'])->latest();
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")
                ->orWhereHas('memberships', fn ($members) => $members->where('role', 'owner')->whereHas('user', fn ($users) => $users->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))));
        }
        match ($filters['status'] ?? 'all') {
            'active' => $query->where('is_suspended', false)->whereHas('subscriptions', fn ($q) => $q->whereIn('status', ['active', 'trialing'])->where('starts_at', '<=', now())->where('ends_at', '>', now())->where('is_free_trial', false)),
            'trial' => $query->where('is_suspended', false)->whereHas('subscriptions', fn ($q) => $q->where('status', 'trialing')->where('is_free_trial', true)->where('starts_at', '<=', now())->where('ends_at', '>', now())),
            'expired' => $query->where('is_suspended', false)->whereDoesntHave('subscriptions', fn ($q) => $q->whereIn('status', ['active', 'trialing'])->where('starts_at', '<=', now())->where('ends_at', '>', now())),
            'scheduled' => $query->where('is_suspended', false)->whereHas('subscriptions', fn ($q) => $q->whereIn('status', ['active', 'trialing'])->where('starts_at', '>', now())),
            'suspended' => $query->where('is_suspended', true),
            default => null,
        };
        $companies = $query->paginate(20)->withQueryString();
        return response()->json(['data' => $companies->getCollection()->map(fn ($company) => $this->serializeCompany($company)), 'meta' => [
            'current_page' => $companies->currentPage(), 'last_page' => $companies->lastPage(), 'total' => $companies->total(),
        ]]);
    }

    public function suspend(Request $request, Company $company)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $company, $data) {
            $locked = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $before = ['is_suspended' => $locked->is_suspended, 'suspension_reason' => $locked->suspension_reason];
            $locked->update(['is_suspended' => true, 'suspended_at' => now(), 'suspension_reason' => $data['reason']]);
            $this->audit($request, $locked, 'company_suspended', $data['reason'], ['before' => $before, 'after' => ['is_suspended' => true]]);
        });
        return response()->json(['message' => 'Company access has been suspended.']);
    }

    public function activate(Request $request, Company $company)
    {
        DB::transaction(function () use ($request, $company) {
            $locked = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $reason = $locked->suspension_reason;
            $locked->update(['is_suspended' => false, 'suspended_at' => null, 'suspension_reason' => null]);
            $this->audit($request, $locked, 'company_reactivated', $request->input('reason', 'Reactivated by platform administrator.'), ['previous_reason' => $reason]);
        });
        return response()->json(['message' => 'Company access has been reactivated.']);
    }

    public function renew(Request $request, Company $company)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('subscription_plans', 'id')->where(fn ($q) => $q->where('is_active', true)->where('slug', '!=', 'free-trial'))],
            'billing_cycle' => ['required', Rule::in(['month', 'year'])], 'periods' => ['required', 'integer', 'min:1', 'max:24'],
            'renewal_mode' => ['required', Rule::in(['today', 'extend'])], 'reason' => ['required', 'string', 'max:1000'],
        ]);
        $plan = SubscriptionPlan::active()->where('slug', '!=', 'free-trial')->findOrFail($data['plan_id']);
        if (! $plan->priceFor($data['billing_cycle'])) return response()->json(['message' => 'The selected billing period is unavailable for this plan.'], 422);

        $subscription = DB::transaction(function () use ($request, $company, $plan, $data) {
            $locked = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $latestEnd = $locked->subscriptions()->max('ends_at');
            $start = $data['renewal_mode'] === 'extend' && $latestEnd && Carbon::parse($latestEnd)->isFuture() ? Carbon::parse($latestEnd) : now();
            $end = $start->copy();
            for ($i = 0; $i < $data['periods']; $i++) $data['billing_cycle'] === 'year' ? $end->addYearNoOverflow() : $end->addMonthNoOverflow();
            $subscription = $locked->subscriptions()->create([
                'plan_id' => $plan->id, 'started_by' => $request->user()->id, 'status' => 'active',
                'billing_cycle' => $data['billing_cycle'], 'is_free_trial' => false, 'starts_at' => $start, 'ends_at' => $end,
            ]);
            if ($locked->is_suspended) $locked->update(['is_suspended' => false, 'suspended_at' => null, 'suspension_reason' => null]);
            $this->audit($request, $locked, 'subscription_granted', $data['reason'], [
                'subscription_id' => $subscription->id, 'plan' => $plan->name, 'plan_id' => $plan->id,
                'billing_cycle' => $data['billing_cycle'], 'periods' => $data['periods'],
                'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(), 'payment_collected' => false,
            ]);
            return $subscription;
        });
        return response()->json(['message' => 'Subscription access has been granted.', 'subscription' => [
            'plan' => $plan->name, 'starts_at' => $subscription->starts_at->toIso8601String(), 'ends_at' => $subscription->ends_at->toIso8601String(),
        ]], 201);
    }

    private function serializeCompany(Company $company): array
    {
        $owner = $company->memberships->first()?->user;
        $sub = $company->subscriptions()->with('plan')->whereIn('status', ['active', 'trialing'])->where('starts_at', '<=', now())->where('ends_at', '>', now())->orderByDesc('starts_at')->first() ?? $company->latestSubscription;
        $current = $sub && in_array($sub->status, ['active', 'trialing'], true) && $sub->starts_at->isPast() && $sub->ends_at->isFuture();
        $scheduled = $sub && $sub->starts_at->isFuture();
        $state = $company->is_suspended ? 'suspended' : ($current ? ($sub->is_free_trial ? 'trial' : 'active') : ($scheduled ? 'scheduled' : ($sub ? 'expired' : 'no_plan')));
        return [
            'id' => $company->id, 'name' => $company->name, 'slug' => $company->slug, 'created_at' => $company->created_at->toIso8601String(),
            'owner' => $owner ? ['name' => $owner->name, 'email' => $owner->email] : null,
            'status' => $state, 'is_suspended' => $company->is_suspended, 'suspension_reason' => $company->suspension_reason,
            'plan' => $sub?->plan?->name, 'subscription_ends_at' => $sub?->ends_at?->toIso8601String(),
            'active_members' => (int) $company->active_members_count, 'leads' => (int) $company->leads_count,
            'listings' => (int) $company->property_listings_count, 'deals' => (int) $company->deals_count,
        ];
    }

    private function dateRange(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        return [
            isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->subDays(29)->startOfDay(),
            isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay(),
        ];
    }

    private function audit(Request $request, Company $company, string $action, ?string $reason, array $details = []): void
    {
        PlatformAdminAction::create([
            'admin_user_id' => $request->user()->id, 'company_id' => $company->id, 'action' => $action,
            'reason' => $reason, 'details' => $details, 'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000), 'created_at' => now(),
        ]);
    }
}
