<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_business_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('display_name', 120);
            $table->string('phone_number_id', 80);
            $table->string('business_account_id', 80)->nullable();
            $table->text('access_token')->nullable();
            $table->string('graph_version', 16)->default('v25.0');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'user_id', 'is_active'], 'wa_account_scope_idx');
        });

        Schema::create('whatsapp_message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 120);
            $table->string('meta_template_name', 512);
            $table->string('language_code', 16)->default('en_US');
            $table->text('body');
            $table->json('parameter_keys');
            $table->boolean('requires_property')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'key']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_business_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_message_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_phone', 30);
            $table->text('rendered_body');
            $table->string('provider_message_id', 160)->nullable();
            $table->string('status', 24);
            $table->json('provider_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'lead_id', 'created_at'], 'wa_message_lead_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_message_templates');
        Schema::dropIfExists('whatsapp_business_accounts');
    }
};
