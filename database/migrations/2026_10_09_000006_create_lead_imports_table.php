<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('file_hash', 64);
            $table->unsignedSmallInteger('row_count')->default(0);
            $table->unsignedSmallInteger('created_count')->default(0);
            $table->unsignedSmallInteger('skipped_count')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'file_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_imports');
    }
};
