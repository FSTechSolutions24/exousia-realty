<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_commissions', function (Blueprint $table) {
            $table->foreignId('property_listing_id')->nullable()->after('deal_id')->constrained()->nullOnDelete();
            $table->string('unit_reference', 255)->nullable();
            $table->string('calculation_type', 16)->default('fixed');
            $table->unsignedInteger('rate_basis_points')->nullable();
            $table->unsignedBigInteger('base_amount_minor_units')->nullable();
            $table->index(['company_id', 'company_membership_id', 'status', 'created_at'], 'commission_employee_period_idx');
        });

        DB::table('deal_commissions')->orderBy('id')->chunkById(100, function ($entries) {
            foreach ($entries as $entry) {
                $deal = DB::table('deals')->where('company_id', $entry->company_id)->where('id', $entry->deal_id)->first();
                $listing = $deal?->property_listing_id
                    ? DB::table('property_listings')->where('company_id', $entry->company_id)->where('id', $deal->property_listing_id)->first()
                    : null;
                DB::table('deal_commissions')->where('id', $entry->id)->update([
                    'property_listing_id' => $listing?->id,
                    'unit_reference' => $listing ? trim(($listing->reference_code ?? '').' · '.($listing->title ?? ''), ' ·') : null,
                    'base_amount_minor_units' => $deal?->agreed_price_minor_units,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('deal_commissions', function (Blueprint $table) {
            $table->dropIndex('commission_employee_period_idx');
            $table->dropConstrainedForeignId('property_listing_id');
            $table->dropColumn(['unit_reference', 'calculation_type', 'rate_basis_points', 'base_amount_minor_units']);
        });
    }
};
