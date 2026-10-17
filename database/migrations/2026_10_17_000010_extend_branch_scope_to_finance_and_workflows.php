<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['follow_up_tasks', 'deal_commissions', 'deal_installments', 'lead_imports'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('branch_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            });
        }

        DB::table('follow_up_tasks')->orderBy('id')->each(function ($task) {
            $branchId = $task->lead_id ? DB::table('leads')->where('id', $task->lead_id)->value('branch_id') : null;
            DB::table('follow_up_tasks')->where('id', $task->id)->update(['branch_id' => $branchId ?: $this->mainBranch($task->company_id)]);
        });
        foreach (['deal_commissions', 'deal_installments'] as $table) {
            DB::table($table)->orderBy('id')->each(function ($record) use ($table) {
                $branchId = DB::table('deals')->where('id', $record->deal_id)->value('branch_id');
                DB::table($table)->where('id', $record->id)->update(['branch_id' => $branchId ?: $this->mainBranch($record->company_id)]);
            });
        }
        DB::table('lead_imports')->orderBy('id')->each(fn ($import) => DB::table('lead_imports')->where('id', $import->id)->update(['branch_id' => $this->mainBranch($import->company_id)]));

        Schema::table('lead_imports', function (Blueprint $table) {
            $table->unique(['company_id', 'branch_id', 'file_hash'], 'lead_import_company_branch_hash_unique');
            $table->dropUnique(['company_id', 'file_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('lead_imports', function (Blueprint $table) {
            $table->dropUnique('lead_import_company_branch_hash_unique');
            $table->unique(['company_id', 'file_hash']);
        });
        foreach (['lead_imports', 'deal_installments', 'deal_commissions', 'follow_up_tasks'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropConstrainedForeignId('branch_id'));
        }
    }

    private function mainBranch(int $companyId): ?int
    {
        return DB::table('branches')->where('company_id', $companyId)->orderByDesc('is_main')->value('id');
    }
};
