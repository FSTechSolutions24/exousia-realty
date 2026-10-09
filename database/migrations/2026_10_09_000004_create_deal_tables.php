<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('negotiation');
            $table->date('expected_close_date')->nullable();
            $table->unsignedBigInteger('agreed_price_minor_units')->nullable();
            $table->string('currency', 3)->default('EGP');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'expected_close_date']);
            $table->index(['company_id', 'lead_id']);
            $table->index(['company_id', 'property_listing_id']);
        });

        Schema::create('deal_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 50);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['company_id', 'deal_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_activities');
        Schema::dropIfExists('deals');
    }
};
