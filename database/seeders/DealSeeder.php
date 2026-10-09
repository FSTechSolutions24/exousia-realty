<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\Lead;
use App\Models\PropertyListing;
use App\Models\User;
use Illuminate\Database\Seeder;

class DealSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('slug', 'nile-gate-demo')->first();
        $agent = User::where('email', 'agent@demo.exousia.test')->first();
        if (! $company || ! $agent) return;

        $examples = [
            ['Youssef Hamdy', 'NG-VIL-208', 'negotiation', 1725000000, 14],
            ['Nour El Din', 'NG-APT-104', 'reserved', 740000000, 9],
            ['Laila Magdy', 'NG-OFF-019', 'contracted', 8250000, 20],
        ];

        foreach ($examples as [$leadName, $reference, $status, $amount, $days]) {
            $lead = Lead::where('company_id', $company->id)->where('name', $leadName)->first();
            $property = PropertyListing::where('company_id', $company->id)->where('reference_code', $reference)->first();
            if (! $lead || ! $property) continue;

            $deal = Deal::firstOrCreate(
                ['company_id' => $company->id, 'lead_id' => $lead->id],
                [
                    'property_listing_id' => $property->id, 'created_by' => $agent->id,
                    'status' => $status, 'expected_close_date' => now()->addDays($days)->toDateString(),
                    'agreed_price_minor_units' => $amount, 'currency' => 'EGP',
                    'notes' => 'Fictional example deal for local development only.',
                ]
            );
            if ($deal->wasRecentlyCreated) {
                DealActivity::create([
                    'company_id' => $company->id, 'deal_id' => $deal->id, 'user_id' => $agent->id,
                    'event' => 'created', 'new_values' => $deal->only(['lead_id', 'property_listing_id', 'status', 'expected_close_date', 'agreed_price_minor_units']),
                    'occurred_at' => now(),
                ]);
            }
        }
    }
}
