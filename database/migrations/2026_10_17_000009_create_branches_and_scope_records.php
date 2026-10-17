<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{
  Schema::create('branches',function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->string('name',120);$t->string('code',30);$t->string('phone',30)->nullable();$t->string('address',255)->nullable();$t->boolean('is_main')->default(false);$t->boolean('is_active')->default(true);$t->timestamps();$t->unique(['company_id','code']);});
  Schema::create('branch_memberships',function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->foreignId('branch_id')->constrained()->cascadeOnDelete();$t->foreignId('company_membership_id')->constrained()->cascadeOnDelete();$t->timestamps();$t->unique(['branch_id','company_membership_id']);$t->index(['company_id','company_membership_id']);});
  foreach(['leads','property_listings','deals','invoices'] as $table)Schema::table($table,function(Blueprint $t){$t->foreignId('branch_id')->nullable()->after('company_id')->constrained()->nullOnDelete();});
  foreach(DB::table('companies')->orderBy('id')->get(['id']) as $company){$branchId=DB::table('branches')->insertGetId(['company_id'=>$company->id,'name'=>'Main Branch','code'=>'MAIN','is_main'=>true,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);foreach(['leads','property_listings','deals','invoices'] as $table)DB::table($table)->where('company_id',$company->id)->whereNull('branch_id')->update(['branch_id'=>$branchId]);foreach(DB::table('company_memberships')->where('company_id',$company->id)->pluck('id') as $membershipId)DB::table('branch_memberships')->insert(['company_id'=>$company->id,'branch_id'=>$branchId,'company_membership_id'=>$membershipId,'created_at'=>now(),'updated_at'=>now()]);}
 }
 public function down():void{foreach(['invoices','deals','property_listings','leads'] as $table)Schema::table($table,function(Blueprint $t){$t->dropConstrainedForeignId('branch_id');});Schema::dropIfExists('branch_memberships');Schema::dropIfExists('branches');}
};
