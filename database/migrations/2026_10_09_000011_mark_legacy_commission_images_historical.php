<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('deal_commission_documents')
            ->where('is_current', true)
            ->where('mime_type', '<>', 'application/pdf')
            ->update(['is_current' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Signed images remain historical; rolling this migration back does not reactivate them as payment proof.
    }
};
