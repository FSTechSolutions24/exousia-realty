<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompaniesAndMemberships extends Migration
{
    public function up()
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('phone')->nullable();
            $table->string('timezone')->default('Africa/Cairo');
            $table->string('currency', 3)->default('EGP');
            $table->string('locale', 5)->default('en');
            $table->string('brand_color', 20)->default('#b78a48');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('locale', 5)->default('en')->after('password');
            $table->boolean('is_platform_admin')->default(false)->after('locale');
            $table->unsignedBigInteger('current_company_id')->nullable()->after('is_platform_admin');
            $table->foreign('current_company_id')->references('id')->on('companies')->nullOnDelete();
        });

        Schema::create('company_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30)->default('agent');
            $table->string('status', 20)->default('active');
            $table->json('permissions')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
            $table->index(['company_id', 'role', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('company_memberships');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_company_id']);
            $table->dropColumn(['phone', 'locale', 'is_platform_admin', 'current_company_id']);
        });
        Schema::dropIfExists('companies');
    }
}
