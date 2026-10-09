<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->index();
            $table->foreignId('salesperson_membership_id')->nullable()->after('created_by')->constrained('company_memberships')->nullOnDelete();
            $table->string('salesperson_name')->nullable();
            $table->index(['company_id', 'status', 'closed_at']);
        });

        DB::table('deals')->where('status', 'closed_won')->orderBy('id')->chunkById(100, function ($deals) {
            foreach ($deals as $deal) {
                $lead = DB::table('leads')->where('company_id', $deal->company_id)->where('id', $deal->lead_id)->first();
                $userId = $lead?->assigned_to ?: $lead?->created_by ?: $deal->created_by;
                $membership = $userId ? DB::table('company_memberships')->where('company_id', $deal->company_id)->where('user_id', $userId)->first() : null;
                $name = $userId ? DB::table('users')->where('id', $userId)->value('name') : null;
                DB::table('deals')->where('id', $deal->id)->update([
                    'closed_at' => $deal->updated_at ?: $deal->created_at,
                    'salesperson_membership_id' => $membership?->id,
                    'salesperson_name' => $name,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status', 'closed_at']);
            $table->dropConstrainedForeignId('salesperson_membership_id');
            $table->dropColumn(['closed_at', 'salesperson_name']);
        });
    }
};
