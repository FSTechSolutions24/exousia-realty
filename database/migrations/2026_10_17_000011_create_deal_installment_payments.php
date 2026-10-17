<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_installment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deal_id')->constrained()->restrictOnDelete();
            $table->foreignId('deal_installment_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount_minor_units');
            $table->date('paid_on');
            $table->string('method', 30);
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'deal_installment_id', 'paid_on'], 'deal_installment_payment_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_installment_payments');
    }
};
