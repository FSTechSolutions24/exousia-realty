<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $company = Company::create(['name' => 'Nile Gate Realty (Demo)', 'slug' => 'nile-gate-demo', 'phone' => '01000000000']);
        $other = Company::create(['name' => 'Palm Key Properties (Demo)', 'slug' => 'palm-key-demo', 'brand_color' => '#6f8054']);

        $this->call(PreferredLocationSeeder::class);

        $owner = User::create(['name' => 'Mariam Nassar', 'email' => 'owner@demo.exousia.test', 'password' => Hash::make('password'), 'phone' => '01010000001', 'current_company_id' => $company->id, 'email_verified_at' => now()]);
        $agent = User::create(['name' => 'Omar Adel', 'email' => 'agent@demo.exousia.test', 'password' => Hash::make('password'), 'phone' => '01110000002', 'current_company_id' => $company->id, 'email_verified_at' => now()]);
        $otherOwner = User::create(['name' => 'Salma Fathy', 'email' => 'other@demo.exousia.test', 'password' => Hash::make('password'), 'current_company_id' => $other->id, 'email_verified_at' => now()]);
        $company->memberships()->createMany([
            ['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()],
            ['user_id' => $agent->id, 'role' => 'agent', 'status' => 'active', 'joined_at' => now()],
        ]);
        $other->memberships()->create(['user_id' => $otherOwner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);

        $stages = collect([
            ['New lead', 'عميل جديد', '#64748b', false, false], ['Contacted', 'تم التواصل', '#2563eb', false, false],
            ['Qualified', 'مؤهل', '#7c3aed', false, false], ['Viewing', 'معاينة', '#d97706', false, false],
            ['Negotiation', 'تفاوض', '#db6b32', false, false], ['Won', 'مكتمل', '#16836f', true, false], ['Lost', 'مفقود', '#dc3545', false, true],
        ])->map(fn ($s, $position) => PipelineStage::create(['company_id' => $company->id, 'name' => $s[0], 'name_ar' => $s[1], 'color' => $s[2], 'position' => $position, 'is_won' => $s[3], 'is_lost' => $s[4]]));
        PipelineStage::create(['company_id' => $other->id, 'name' => 'New lead', 'name_ar' => 'عميل جديد', 'position' => 0]);

        $people = [
            ['Youssef Hamdy', '01010001001', 'referral', 'buy', 8500000, ['New Cairo'], 'villa', 4, 0],
            ['Nour El Din', '01110001002', 'facebook', 'buy', 4200000, ['New Capital'], 'apartment', 3, 1],
            ['Laila Magdy', '01210001003', 'website', 'rent', 65000, ['Sheikh Zayed'], 'apartment', 2, 2],
            ['Ahmed Tarek', '01510001004', 'property portal', 'buy', 12000000, ['North Coast'], 'chalet', 3, 3],
            ['Dina Sherif', '01010001005', 'referral', 'buy', 6800000, ['6th of October'], 'townhouse', 3, 4],
            ['Karim Sameh', '01110001006', 'facebook', 'buy', 9500000, ['New Cairo', 'Mostakbal City'], 'villa', 4, 5],
            ['Hana Emad', '01210001007', 'manual', 'buy', 3300000, ['New Capital'], 'apartment', 2, 0],
            ['Mostafa Ali', '01510001008', 'website', 'rent', 45000, ['Maadi'], 'office', null, 2],
        ];
        foreach ($people as $index => $person) {
            $lead = Lead::create([
                'company_id' => $company->id, 'pipeline_stage_id' => $stages[$person[8]]->id,
                'assigned_to' => $agent->id, 'created_by' => $owner->id, 'name' => $person[0],
                'phone_original' => $person[1], 'phone_normalized' => '+20'.substr($person[1], 1), 'source' => $person[2],
                'intent' => $person[3], 'budget_max' => $person[4], 'preferred_locations' => $person[5],
                'property_type' => $person[6], 'bedrooms' => $person[7], 'next_follow_up_at' => now()->addDays($index - 2)->setHour(11),
                'notes' => 'Fictional demo customer for local development only.', 'created_at' => now()->subDays($index * 2),
            ]);
            LeadActivity::create(['company_id' => $company->id, 'lead_id' => $lead->id, 'user_id' => $owner->id, 'type' => 'created', 'title' => 'Lead added to workspace', 'occurred_at' => $lead->created_at]);
            if ($index < 5) FollowUpTask::create(['company_id' => $company->id, 'lead_id' => $lead->id, 'assigned_to' => $agent->id, 'created_by' => $owner->id, 'title' => $index % 2 ? 'Share matching options' : 'Follow up by phone', 'priority' => $index < 2 ? 'high' : 'normal', 'due_at' => now()->addDays($index - 2)->setHour(10)]);
        }

        $this->call(PropertyListingSeeder::class);
        $this->call(DealSeeder::class);
    }
}
