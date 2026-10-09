<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_received_minor_units')->nullable()->after('agreed_price_minor_units');
            $table->string('payment_method', 30)->nullable()->after('amount_received_minor_units');
            $table->string('payment_reference', 100)->nullable()->after('payment_method');
            $table->date('payment_received_on')->nullable()->after('payment_reference');
            $table->text('payment_terms')->nullable()->after('payment_received_on');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn([
                'amount_received_minor_units', 'payment_method', 'payment_reference',
                'payment_received_on', 'payment_terms',
            ]);
        });
    }
};
