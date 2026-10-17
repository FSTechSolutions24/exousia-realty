<?php
namespace Tests\Feature;
use App\Models\Branch;use App\Models\Company;use App\Models\Deal;use App\Models\DealCommission;use App\Models\DealInstallment;use App\Models\FollowUpTask;use App\Models\Lead;use App\Models\PipelineStage;use App\Models\SubscriptionPlan;use App\Models\User;use Illuminate\Foundation\Testing\RefreshDatabase;use Tests\TestCase;
class FinanceAndBranchTest extends TestCase
{
 use RefreshDatabase;
 public function test_invoice_totals_payments_and_tenant_isolation_are_exact():void{
  [$a,$ownerA,$leadA]=$this->workspace('alpha');[$b,$ownerB]=$this->workspace('beta');
  $payload=['lead_id'=>$leadA->id,'issue_date'=>'2026-10-17','due_date'=>'2026-11-17','billing_name'=>'Fictional Buyer','tax_egp'=>'14.25','items'=>[['description'=>'Brokerage service','quantity'=>2,'unit_price_egp'=>'100.10']]];
  $invoiceId=$this->actingAs($ownerA)->withHeader('X-Company-ID',$a->id)->postJson('/api/v1/invoices',$payload)->assertCreated()->assertJsonPath('subtotal_minor_units',20020)->assertJsonPath('total_minor_units',21445)->json('id');
  $this->actingAs($ownerA)->withHeader('X-Company-ID',$a->id)->postJson("/api/v1/invoices/{$invoiceId}/issue")->assertOk()->assertJsonPath('status','issued');
  $this->actingAs($ownerA)->withHeader('X-Company-ID',$a->id)->postJson("/api/v1/invoices/{$invoiceId}/payments",['amount_egp'=>'100.10','paid_on'=>'2026-10-17','method'=>'bank_transfer'])->assertCreated()->assertJsonPath('amount_paid_minor_units',10010)->assertJsonPath('status','part_paid');
  $this->actingAs($ownerA)->withHeader('X-Company-ID',$a->id)->postJson("/api/v1/invoices/{$invoiceId}/payments",['amount_egp'=>'114.35','paid_on'=>'2026-10-17','method'=>'cash'])->assertCreated()->assertJsonPath('status','paid')->assertJsonCount(2,'payments');
  $this->actingAs($ownerB)->withHeader('X-Company-ID',$b->id)->getJson("/api/v1/invoices/{$invoiceId}")->assertNotFound();
 }
 public function test_installment_generation_preserves_every_piastre_and_is_tenant_scoped():void{
  [$a,$ownerA,$leadA]=$this->workspace('alpha');[$b,$ownerB]=$this->workspace('beta');
  $deal=Deal::create(['company_id'=>$a->id,'lead_id'=>$leadA->id,'created_by'=>$ownerA->id,'status'=>'contracted','agreed_price_minor_units'=>10001,'amount_received_minor_units'=>1,'currency'=>'EGP']);
  $response=$this->actingAs($ownerA)->withHeader('X-Company-ID',$a->id)->postJson("/api/v1/deals/{$deal->id}/installments/generate",['count'=>3,'first_due_date'=>'2026-11-01','frequency'=>'monthly'])->assertCreated();
  $this->assertSame(10000,array_sum(array_column($response->json(), 'amount_minor_units')));$this->assertSame([3333,3333,3334],array_column($response->json(),'amount_minor_units'));
  $installment=$response->json('0');
  $this->actingAs($ownerA)->withHeader('X-Company-ID',$a->id)->postJson("/api/v1/installments/{$installment['id']}/payments",['amount_egp'=>'10.00','paid_on'=>'2026-11-01','payment_method'=>'bank_transfer','payment_reference'=>'TX-1'])->assertOk()->assertJsonPath('paid_minor_units',1000)->assertJsonPath('status','part_paid');
  $this->actingAs($ownerA)->withHeader('X-Company-ID',$a->id)->postJson("/api/v1/installments/{$installment['id']}/payments",['amount_egp'=>'100.00','paid_on'=>'2026-11-01','payment_method'=>'cash'])->assertUnprocessable();
  $this->actingAs($ownerA)->withHeader('X-Company-ID',$a->id)->getJson('/api/v1/installments')->assertOk()->assertJsonPath('data.0.payments.0.reference','TX-1');
  $this->actingAs($ownerA)->withHeader('X-Company-ID',$a->id)->putJson("/api/v1/deals/{$deal->id}",['agreed_price_egp'=>'200.00'])->assertUnprocessable();
  $this->actingAs($ownerB)->withHeader('X-Company-ID',$b->id)->postJson("/api/v1/deals/{$deal->id}/installments/generate",['count'=>2,'first_due_date'=>'2026-11-01','frequency'=>'monthly'])->assertNotFound();
 }
 public function test_active_branch_scopes_leads_and_member_access():void{
  [$company,$owner,$mainLead]=$this->workspace('alpha');$main=$company->branches()->where('is_main',true)->firstOrFail();$east=Branch::create(['company_id'=>$company->id,'name'=>'East Cairo','code'=>'EAST','is_active'=>true]);
  $agent=User::factory()->create(['current_company_id'=>$company->id]);$membership=$company->memberships()->create(['user_id'=>$agent->id,'role'=>'agent','status'=>'active']);$east->memberships()->syncWithoutDetaching([$membership->id=>['company_id'=>$company->id]]);
  $stage=PipelineStage::where('company_id',$company->id)->firstOrFail();$eastLead=Lead::withoutGlobalScope('branch')->create(['company_id'=>$company->id,'branch_id'=>$east->id,'pipeline_stage_id'=>$stage->id,'created_by'=>$agent->id,'assigned_to'=>$agent->id,'name'=>'East Fictional Buyer','phone_original'=>'01112345678','phone_normalized'=>'+201112345678']);
  $this->actingAs($agent)->withHeaders(['X-Company-ID'=>$company->id,'X-Branch-ID'=>$east->id])->getJson('/api/v1/leads')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$eastLead->id);
  $foreign=Branch::create(['company_id'=>$company->id,'name'=>'West Cairo','code'=>'WEST','is_active'=>true]);
  $this->actingAs($agent)->withHeaders(['X-Company-ID'=>$company->id,'X-Branch-ID'=>$foreign->id])->getJson('/api/v1/branches')->assertStatus(409)->assertJsonPath('code','invalid_branch');
 }
 public function test_branch_creation_respects_the_company_plan_limit():void{
  [$company,$owner]=$this->workspace('alpha');SubscriptionPlan::where('slug','free-trial')->update(['branch_limit'=>2]);$this->actingAs($owner)->withHeader('X-Company-ID',$company->id);
  $this->postJson('/api/v1/branches',['name'=>'East','code'=>'EAST'])->assertCreated();
  $this->postJson('/api/v1/branches',['name'=>'West','code'=>'WEST'])->assertUnprocessable()->assertJsonValidationErrors('plan');
  $this->assertDatabaseCount('branches',2);
 }
 public function test_tasks_commissions_and_installments_follow_the_selected_branch():void{
  [$company,$owner,$mainLead]=$this->workspace('alpha');$main=$company->branches()->where('is_main',true)->firstOrFail();$east=Branch::create(['company_id'=>$company->id,'name'=>'East','code'=>'EAST','is_active'=>true]);
  $agent=User::factory()->create(['current_company_id'=>$company->id]);$membership=$company->memberships()->create(['user_id'=>$agent->id,'role'=>'agent','status'=>'active']);$east->memberships()->syncWithoutDetaching([$membership->id=>['company_id'=>$company->id]]);
  $stage=PipelineStage::where('company_id',$company->id)->firstOrFail();$eastLead=Lead::withoutGlobalScope('branch')->create(['company_id'=>$company->id,'branch_id'=>$east->id,'pipeline_stage_id'=>$stage->id,'created_by'=>$agent->id,'assigned_to'=>$agent->id,'name'=>'East Buyer','phone_original'=>'01112345678','phone_normalized'=>'+201112345678']);
  $mainTask=FollowUpTask::create(['company_id'=>$company->id,'lead_id'=>$mainLead->id,'assigned_to'=>$agent->id,'created_by'=>$agent->id,'title'=>'Main task','due_at'=>now()->addDay()]);$eastTask=FollowUpTask::withoutGlobalScope('branch')->create(['company_id'=>$company->id,'branch_id'=>$east->id,'lead_id'=>$eastLead->id,'assigned_to'=>$agent->id,'created_by'=>$agent->id,'title'=>'East task','due_at'=>now()->addDay()]);
  $mainDeal=Deal::create(['company_id'=>$company->id,'branch_id'=>$main->id,'lead_id'=>$mainLead->id,'created_by'=>$owner->id,'status'=>'contracted','agreed_price_minor_units'=>10000]);$eastDeal=Deal::withoutGlobalScope('branch')->create(['company_id'=>$company->id,'branch_id'=>$east->id,'lead_id'=>$eastLead->id,'created_by'=>$agent->id,'status'=>'contracted','agreed_price_minor_units'=>10000]);
  DealCommission::create(['company_id'=>$company->id,'branch_id'=>$main->id,'deal_id'=>$mainDeal->id,'company_membership_id'=>$membership->id,'payee_name'=>$agent->name,'amount_minor_units'=>1000,'created_by'=>$owner->id]);DealCommission::create(['company_id'=>$company->id,'branch_id'=>$east->id,'deal_id'=>$eastDeal->id,'company_membership_id'=>$membership->id,'payee_name'=>$agent->name,'amount_minor_units'=>2000,'created_by'=>$agent->id]);
  DealInstallment::create(['company_id'=>$company->id,'branch_id'=>$main->id,'deal_id'=>$mainDeal->id,'sequence'=>1,'due_date'=>now()->addDay(),'amount_minor_units'=>10000]);$eastInstallment=DealInstallment::create(['company_id'=>$company->id,'branch_id'=>$east->id,'deal_id'=>$eastDeal->id,'sequence'=>1,'due_date'=>now()->addDay(),'amount_minor_units'=>10000]);
  $headers=['X-Company-ID'=>$company->id,'X-Branch-ID'=>$east->id];
  $this->actingAs($agent)->withHeaders($headers)->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$eastTask->id);
  $this->actingAs($agent)->withHeaders($headers)->getJson('/api/v1/commissions')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.amount_minor_units',2000);
  $this->actingAs($agent)->withHeaders($headers)->getJson('/api/v1/installments')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$eastInstallment->id);
 }
 private function workspace(string $slug):array{$company=Company::create(['name'=>ucfirst($slug),'slug'=>$slug]);$this->enableCompanyTrial($company);$owner=User::factory()->create(['current_company_id'=>$company->id]);$company->memberships()->create(['user_id'=>$owner->id,'role'=>'owner','status'=>'active']);$stage=PipelineStage::create(['company_id'=>$company->id,'name'=>'New','position'=>0]);$lead=Lead::create(['company_id'=>$company->id,'pipeline_stage_id'=>$stage->id,'created_by'=>$owner->id,'assigned_to'=>$owner->id,'name'=>'Main Fictional Buyer','phone_original'=>'01012345678','phone_normalized'=>'+201012345678']);return[$company,$owner,$lead];}
}
