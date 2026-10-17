<?php

namespace Tests;

use App\Models\Company;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function enableCompanyTrial(Company $company): void
    {
        $startsAt = now();
        $company->subscriptions()->create([
            'plan_id' => SubscriptionPlan::where('slug', 'free-trial')->value('id'),
            'status' => 'trialing', 'billing_cycle' => 'trial', 'is_free_trial' => true,
            'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addDays(14),
            'trial_started_at' => $startsAt, 'trial_ends_at' => $startsAt->copy()->addDays(14),
        ]);
    }
}
