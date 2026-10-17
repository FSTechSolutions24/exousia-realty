<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            Schema::create('subscription_plans', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 60)->unique();
                $table->string('name', 100);
                $table->text('description')->nullable();
                $table->unsignedBigInteger('monthly_price_minor')->nullable();
                $table->unsignedBigInteger('yearly_price_minor')->nullable();
                $table->unsignedSmallInteger('team_member_limit')->nullable();
                $table->string('currency', 3)->default('EGP');
                $table->json('features');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_featured')->default(false);
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('company_subscriptions')) {
            Schema::create('company_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
                $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 20);
                $table->string('billing_cycle', 20);
                $table->boolean('is_free_trial')->default(false);
                // DATETIME avoids legacy MySQL's implicit CURRENT_TIMESTAMP behavior on multiple columns.
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->dateTime('trial_started_at')->nullable();
                $table->dateTime('trial_ends_at')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'status', 'ends_at']);
                $table->index(['company_id', 'is_free_trial']);
            });
        }

        if (! Schema::hasTable('subscription_payments')) {
            Schema::create('subscription_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
                $table->foreignId('company_subscription_id')->nullable()->constrained('company_subscriptions')->nullOnDelete();
                $table->string('reference', 80)->unique();
                $table->string('gateway', 30)->default('paymob');
                $table->string('status', 20)->default('pending');
                $table->string('billing_cycle', 20);
                $table->unsignedBigInteger('amount_minor');
                $table->string('currency', 3)->default('EGP');
                $table->string('gateway_order_id', 80)->nullable()->unique();
                $table->string('gateway_intention_id', 100)->nullable();
                $table->string('transaction_id', 80)->nullable()->unique();
                $table->timestamp('paid_at')->nullable();
                $table->json('gateway_metadata')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'status']);
            });
        }

        $now = now();
        DB::table('subscription_plans')->insertOrIgnore([
            ['slug' => 'free-trial', 'name' => 'Free Trial', 'description' => 'Explore Exousia with your brokerage for 14 days.', 'monthly_price_minor' => 0, 'yearly_price_minor' => null, 'team_member_limit' => 3, 'currency' => 'EGP', 'features' => json_encode(['14 days of workspace access', 'Up to 3 team members', 'Leads, follow-ups, inventory and deals']), 'is_active' => true, 'is_featured' => false, 'position' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'essential', 'name' => 'Essential', 'description' => 'The essentials for an organized brokerage.', 'monthly_price_minor' => 29900, 'yearly_price_minor' => 299000, 'team_member_limit' => 3, 'currency' => 'EGP', 'features' => json_encode(['Lead pipeline and follow-ups', 'Property inventory and matching', 'Deal tracking, reports and exports', 'Up to 3 team members']), 'is_active' => true, 'is_featured' => false, 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'professional', 'name' => 'Professional', 'description' => 'A complete workspace for a growing real estate team.', 'monthly_price_minor' => 59900, 'yearly_price_minor' => 599000, 'team_member_limit' => 10, 'currency' => 'EGP', 'features' => json_encode(['Everything in Essential', 'Commission tracking and private deal documents', 'Assignment history and team roles', 'Up to 10 team members']), 'is_active' => true, 'is_featured' => true, 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'studio', 'name' => 'Studio', 'description' => 'More visibility for brokerages coordinating a wider operation.', 'monthly_price_minor' => 99900, 'yearly_price_minor' => 999000, 'team_member_limit' => 25, 'currency' => 'EGP', 'features' => json_encode(['Everything in Professional', 'Sales performance and lead import', 'Detailed property matching history', 'Up to 25 team members']), 'is_active' => true, 'is_featured' => false, 'position' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Give existing workspaces a one-time introductory period when billing is first enabled.
        $trialPlanId = DB::table('subscription_plans')->where('slug', 'free-trial')->value('id');
        foreach (DB::table('companies')->select('id')->get() as $company) {
            if (DB::table('company_subscriptions')->where('company_id', $company->id)->exists()) continue;
            DB::table('company_subscriptions')->insert([
                'company_id' => $company->id, 'plan_id' => $trialPlanId, 'status' => 'trialing', 'billing_cycle' => 'trial',
                'is_free_trial' => true, 'starts_at' => $now, 'ends_at' => $now->copy()->addDays(14),
                'trial_started_at' => $now, 'trial_ends_at' => $now->copy()->addDays(14), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('company_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
