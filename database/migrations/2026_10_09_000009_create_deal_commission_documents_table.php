<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_commission_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_commission_id')->constrained('deal_commissions')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name', 255);
            $table->string('storage_path', 500);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->boolean('is_current')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'deal_commission_id', 'is_current'], 'commission_doc_current_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_commission_documents');
    }
};
