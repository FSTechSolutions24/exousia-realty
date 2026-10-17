<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unsignedInteger('lead_limit')->nullable()->after('team_member_limit');
            $table->unsignedInteger('listing_limit')->nullable()->after('lead_limit');
            $table->unsignedInteger('developer_limit')->nullable()->after('listing_limit');
            $table->unsignedSmallInteger('branch_limit')->nullable()->after('listing_limit');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['lead_limit', 'listing_limit', 'developer_limit', 'branch_limit']);
        });
    }
};
