<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invoice_number', 60); $table->string('status', 24)->default('draft');
            $table->date('issue_date'); $table->date('due_date')->nullable();
            $table->string('billing_name', 180); $table->string('billing_phone', 30)->nullable();
            $table->unsignedBigInteger('subtotal_minor_units'); $table->unsignedBigInteger('tax_minor_units')->default(0);
            $table->unsignedBigInteger('total_minor_units'); $table->unsignedBigInteger('amount_paid_minor_units')->default(0);
            $table->string('currency', 3)->default('EGP'); $table->text('notes')->nullable();
            $table->timestamp('issued_at')->nullable(); $table->timestamp('paid_at')->nullable(); $table->timestamps();
            $table->unique(['company_id', 'invoice_number']); $table->index(['company_id', 'status', 'due_date']);
        });
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete(); $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1); $table->unsignedBigInteger('unit_price_minor_units');
            $table->unsignedBigInteger('line_total_minor_units'); $table->unsignedSmallInteger('position')->default(0); $table->timestamps();
            $table->index(['company_id', 'invoice_id']);
        });
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete(); $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount_minor_units'); $table->date('paid_on'); $table->string('method', 30);
            $table->string('reference', 120)->nullable(); $table->text('notes')->nullable(); $table->timestamps();
            $table->index(['company_id', 'invoice_id', 'paid_on']);
        });
        Schema::create('deal_installments', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained()->restrictOnDelete(); $table->unsignedSmallInteger('sequence');
            $table->date('due_date'); $table->unsignedBigInteger('amount_minor_units'); $table->unsignedBigInteger('paid_minor_units')->default(0);
            $table->string('status', 24)->default('scheduled'); $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 30)->nullable(); $table->string('payment_reference', 120)->nullable(); $table->text('notes')->nullable();
            $table->timestamps(); $table->unique(['company_id', 'deal_id', 'sequence']); $table->index(['company_id', 'status', 'due_date']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('deal_installments'); Schema::dropIfExists('invoice_payments'); Schema::dropIfExists('invoice_items'); Schema::dropIfExists('invoices');
    }
};
