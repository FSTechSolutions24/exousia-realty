<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference_code', 40);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('listing_type', 20);
            $table->string('property_type', 40);
            $table->string('status', 24)->default('available');
            $table->string('location', 120);
            $table->string('address', 255)->nullable();
            $table->unsignedBigInteger('price_minor_units');
            $table->string('currency', 3)->default('EGP');
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->decimal('area_sqm', 10, 2)->nullable();
            $table->foreignId('listed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['company_id', 'reference_code']);
            $table->index(['company_id', 'status', 'listing_type']);
            $table->index(['company_id', 'location', 'property_type']);
            $table->index(['company_id', 'price_minor_units']);
        });

        Schema::create('property_listing_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 50);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['company_id', 'property_listing_id', 'occurred_at'], 'listing_activity_tenant_time_idx');
        });

        Schema::create('property_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_listing_id')->constrained()->cascadeOnDelete();
            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedSmallInteger('position')->default(0);
            $table->softDeletes();
            $table->timestamps();
            $table->index(['company_id', 'property_listing_id', 'position'], 'property_photos_listing_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_photos');
        Schema::dropIfExists('property_listing_activities');
        Schema::dropIfExists('property_listings');
    }
};
