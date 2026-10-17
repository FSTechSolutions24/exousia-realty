<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('property_viewings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_listing_id')->constrained('property_listings')->restrictOnDelete();
            $table->string('listing_title', 180);
            $table->string('listing_reference_code', 40);
            $table->string('listing_location', 120);
            $table->unsignedBigInteger('listing_price_minor_units');
            $table->foreignId('scheduled_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('scheduled_at');
            $table->string('status', 20)->default('scheduled');
            $table->unsignedTinyInteger('buyer_rating')->nullable();
            $table->text('buyer_feedback')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'scheduled_at', 'status']);
            $table->index(['company_id', 'lead_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_viewings');
    }
};
