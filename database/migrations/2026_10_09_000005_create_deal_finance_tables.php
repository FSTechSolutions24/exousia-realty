<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_membership_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payee_name', 160);
            $table->unsignedBigInteger('amount_minor_units');
            $table->string('currency', 3)->default('EGP');
            $table->string('status', 16)->default('pending');
            $table->date('due_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status', 'due_on']);
            $table->index(['company_id', 'deal_id']);
        });

        Schema::create('deal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 30)->default('contract');
            $table->string('original_name', 255);
            $table->string('storage_path', 500);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
            $table->index(['company_id', 'deal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_documents');
        Schema::dropIfExists('deal_commissions');
    }
};
