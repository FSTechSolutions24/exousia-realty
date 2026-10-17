<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public const TRIAL_DAYS = 14;

    public function current(Company $company): ?CompanySubscription
    {
        return $company->subscriptions()->whereIn('status', ['trialing', 'active'])
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->with('plan')->orderByDesc('starts_at')->first();
    }

    public function snapshot(Company $company): array
    {
        $current = $this->current($company);
        $trialUsed = $company->subscriptions()->where('is_free_trial', true)->exists();
        $hasSubscriptionHistory = $company->subscriptions()->exists();
        $next = $company->subscriptions()->whereIn('status', ['active', 'trialing'])->where('starts_at', '>', now())->with('plan')->orderBy('starts_at')->first();
        return [
            'status' => $current?->status ?? ($hasSubscriptionHistory ? 'expired' : 'not_started'),
            'active' => (bool) $current,
            'plan' => $current?->plan ? ['slug' => $current->plan->slug, 'name' => $current->plan->name] : null,
            'billing_cycle' => $current?->billing_cycle,
            'starts_at' => $current?->starts_at?->toIso8601String(),
            'ends_at' => $current?->ends_at?->toIso8601String(),
            'free_trial_used' => $trialUsed,
            'free_trial_available' => ! $trialUsed && ! $current,
            'scheduled_plan' => $next ? ['name' => $next->plan->name, 'starts_at' => $next->starts_at->toIso8601String()] : null,
        ];
    }

    public function startFreeTrial(Company $company, int $userId): CompanySubscription
    {
        return \DB::transaction(function () use ($company, $userId) {
            $locked = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            if ($locked->subscriptions()->where('is_free_trial', true)->exists()) {
                throw ValidationException::withMessages(['plan' => 'This company has already used its free trial. Free trials cannot be renewed.']);
            }
            if ($this->current($locked)) throw ValidationException::withMessages(['plan' => 'This company already has an active subscription.']);
            $plan = SubscriptionPlan::active()->where('slug', 'free-trial')->firstOrFail();
            $start = now();
            return $locked->subscriptions()->create([
                'plan_id' => $plan->id, 'started_by' => $userId, 'status' => 'trialing', 'billing_cycle' => 'trial',
                'is_free_trial' => true, 'starts_at' => $start, 'ends_at' => $start->copy()->addDays(self::TRIAL_DAYS),
                'trial_started_at' => $start, 'trial_ends_at' => $start->copy()->addDays(self::TRIAL_DAYS),
            ]);
        });
    }

    public function activatePaidPayment($payment, string $transactionId): void
    {
        \DB::transaction(function () use ($payment, $transactionId) {
            $locked = $payment->newQuery()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'paid' || $locked->status !== 'pending') return;
            $company = Company::whereKey($locked->company_id)->lockForUpdate()->firstOrFail();
            $active = $this->current($company);
            $start = $active && ! $active->is_free_trial ? Carbon::instance($active->ends_at) : now();
            if ($active?->is_free_trial) $active->update(['status' => 'converted']);
            $end = $start->copy();
            $locked->billing_cycle === 'year' ? $end->addYearNoOverflow() : $end->addMonthNoOverflow();
            $subscription = $company->subscriptions()->create([
                'plan_id' => $locked->plan_id, 'started_by' => $locked->initiated_by, 'status' => 'active',
                'billing_cycle' => $locked->billing_cycle, 'is_free_trial' => false, 'starts_at' => $start, 'ends_at' => $end,
            ]);
            $locked->update(['company_subscription_id' => $subscription->id, 'status' => 'paid', 'transaction_id' => $transactionId, 'paid_at' => now()]);
        });
    }
}
