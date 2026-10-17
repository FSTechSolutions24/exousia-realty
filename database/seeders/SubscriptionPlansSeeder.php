<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlansSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['slug' => 'free-trial', 'name' => 'Free Trial', 'description' => 'Explore Exousia with your brokerage for 14 days.', 'monthly_price_minor' => 0, 'yearly_price_minor' => null, 'team_member_limit' => 3, 'is_featured' => false, 'position' => 0, 'features' => ['14 days of workspace access', 'Up to 3 team members', 'Leads, follow-ups, inventory and deals']],
            ['slug' => 'essential', 'name' => 'Essential', 'description' => 'The essentials for an organized brokerage.', 'monthly_price_minor' => 29900, 'yearly_price_minor' => 299000, 'team_member_limit' => 3, 'is_featured' => false, 'position' => 1, 'features' => ['Lead pipeline and follow-ups', 'Property inventory and matching', 'Deal tracking, reports and exports', 'Up to 3 team members']],
            ['slug' => 'professional', 'name' => 'Professional', 'description' => 'A complete workspace for a growing real estate team.', 'monthly_price_minor' => 59900, 'yearly_price_minor' => 599000, 'team_member_limit' => 10, 'is_featured' => true, 'position' => 2, 'features' => ['Everything in Essential', 'Commission tracking and private deal documents', 'Assignment history and team roles', 'Up to 10 team members']],
            ['slug' => 'studio', 'name' => 'Studio', 'description' => 'More visibility for brokerages coordinating a wider operation.', 'monthly_price_minor' => 99900, 'yearly_price_minor' => 999000, 'team_member_limit' => 25, 'is_featured' => false, 'position' => 3, 'features' => ['Everything in Professional', 'Sales performance and lead import', 'Detailed property matching history', 'Up to 25 team members']],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(['slug' => $plan['slug']], $plan + ['currency' => 'EGP', 'is_active' => true]);
        }
    }
}
