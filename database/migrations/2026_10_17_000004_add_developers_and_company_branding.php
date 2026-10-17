<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('real_estate_developers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('contact_name', 160)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('website', 255)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_active', 'name']);
        });

        Schema::table('property_listings', function (Blueprint $table) {
            $table->foreignId('developer_id')->nullable()->after('preferred_location_id')
                ->constrained('real_estate_developers')->nullOnDelete();
            $table->index(['company_id', 'developer_id'], 'listings_company_developer_idx');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo_path', 500)->nullable();
            $table->string('logo_mime_type', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'logo_mime_type']);
        });
        Schema::table('property_listings', function (Blueprint $table) {
            $table->dropIndex('listings_company_developer_idx');
            $table->dropConstrainedForeignId('developer_id');
        });
        Schema::dropIfExists('real_estate_developers');
    }
};
