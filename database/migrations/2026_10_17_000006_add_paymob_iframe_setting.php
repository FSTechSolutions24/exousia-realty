<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('platform_payment_gateway_settings', function (Blueprint $table) {
            $table->string('iframe_id')->nullable()->after('payment_methods');
        });
    }

    public function down(): void
    {
        Schema::table('platform_payment_gateway_settings', function (Blueprint $table) {
            $table->dropColumn('iframe_id');
        });
    }
};
