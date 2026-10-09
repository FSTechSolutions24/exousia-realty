<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_listings', function (Blueprint $table) {
            $table->foreignId('preferred_location_id')->nullable()->after('location')
                ->constrained('preferred_locations')->nullOnDelete();
            $table->index(['company_id', 'preferred_location_id'], 'listings_company_master_location_idx');
        });

        DB::table('property_listings')->orderBy('id')->chunkById(200, function ($listings) {
            foreach ($listings as $listing) {
                $locationId = DB::table('preferred_locations')
                    ->where('company_id', $listing->company_id)
                    ->where('name', $listing->location)
                    ->value('id');
                if ($locationId) {
                    DB::table('property_listings')->where('id', $listing->id)
                        ->update(['preferred_location_id' => $locationId]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('property_listings', function (Blueprint $table) {
            $table->dropIndex('listings_company_master_location_idx');
            $table->dropConstrainedForeignId('preferred_location_id');
        });
    }
};
