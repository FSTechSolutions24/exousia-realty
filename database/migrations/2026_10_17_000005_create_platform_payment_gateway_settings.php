<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_payment_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->text('secret_key');
            $table->text('public_key');
            $table->text('hmac_secret');
            $table->text('payment_methods');
            $table->string('api_base')->default('https://accept.paymob.com');
            $table->string('checkout_base')->default('https://accept.paymob.com/unifiedcheckout');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_payment_gateway_settings');
    }
};
