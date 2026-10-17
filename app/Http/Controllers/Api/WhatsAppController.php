<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\PropertyListing;
use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppMessageTemplate;
use App\Services\AuditLogger;
use App\Services\WhatsAppCloudApi;
use App\Services\WhatsAppTemplateService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WhatsAppController extends Controller
{
    public function settings(Request $request, TenantContext $tenant, WhatsAppTemplateService $templates)
    {
        $accounts = WhatsAppBusinessAccount::where('company_id', $tenant->id())->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $request->user()->id))->get();

        return response()->json([
            'driver' => config('services.whatsapp.driver', 'log'),
            'company_account' => $this->accountData($accounts->firstWhere('user_id', null)),
            'personal_account' => $this->accountData($accounts->firstWhere('user_id', $request->user()->id)),
            'can_manage_company_account' => $request->attributes->get('membership')->can('manage_integrations'),
            'templates' => $templates->ensureDefaults($tenant->id())->map(fn ($template) => $this->templateData($template))->values(),
        ]);
    }

    public function saveAccount(Request $request, TenantContext $tenant, string $scope, AuditLogger $audit)
    {
        abort_unless(in_array($scope, ['company', 'personal'], true), 404);
        if ($scope === 'company') abort_unless($request->attributes->get('membership')->can('manage_integrations'), 403);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'phone_number_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'business_account_id' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'access_token' => ['sometimes', 'required', 'string', 'max:4000'],
            'graph_version' => ['nullable', 'string', 'max:16', 'regex:/^v[0-9]{1,2}\\.[0-9]{1,2}$/'],
        ]);
        $userId = $scope === 'personal' ? $request->user()->id : null;
        $account = WhatsAppBusinessAccount::where('company_id', $tenant->id())
            ->where('user_id', $userId)->latest('id')->first();
        if (! $account && empty($data['access_token'])) throw ValidationException::withMessages(['access_token' => 'An access token is required when connecting an account.']);
        if (! $account) $account = new WhatsAppBusinessAccount(['company_id' => $tenant->id(), 'user_id' => $userId]);
        $old = $account->exists ? Arr::except($account->getAttributes(), ['access_token']) : [];
        $account->fill(Arr::except($data, ['access_token']));
        if (array_key_exists('access_token', $data)) $account->access_token = $data['access_token'];
        $account->is_active = true;
        $account->graph_version = $data['graph_version'] ?? config('services.whatsapp.graph_version', 'v25.0');
        $account->save();
        $audit->log($request, 'whatsapp.account_saved', $account, $old, ['scope' => $scope, 'display_name' => $account->display_name, 'phone_number_id' => $account->phone_number_id]);
        return response()->json($this->accountData($account));
    }

    public function disconnectAccount(Request $request, TenantContext $tenant, string $scope, AuditLogger $audit)
    {
        abort_unless(in_array($scope, ['company', 'personal'], true), 404);
        if ($scope === 'company') abort_unless($request->attributes->get('membership')->can('manage_integrations'), 403);
        $userId = $scope === 'personal' ? $request->user()->id : null;
        $account = WhatsAppBusinessAccount::where('company_id', $tenant->id())->where('user_id', $userId)->where('is_active', true)->latest('id')->firstOrFail();
        $account->update(['is_active' => false, 'access_token' => null]);
        $audit->log($request, 'whatsapp.account_disconnected', $account, ['is_active' => true], ['is_active' => false, 'scope' => $scope]);
        return response()->json(['message' => 'WhatsApp Business account disconnected.']);
    }

    public function updateTemplate(Request $request, TenantContext $tenant, int $template, WhatsAppTemplateService $templates, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('manage_integrations'), 403);
        $templates->ensureDefaults($tenant->id());
        $model = WhatsAppMessageTemplate::where('company_id', $tenant->id())->findOrFail($template);
        $data = $request->validate([
            'meta_template_name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language_code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z]{2,3}(?:_[A-Z]{2})?$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $old = $model->only(array_keys($data));
        $model->update($data);
        $audit->log($request, 'whatsapp.template_updated', $model, $old, $data);
        return response()->json($this->templateData($model));
    }

    public function options(Request $request, TenantContext $tenant, int $lead, WhatsAppTemplateService $templates)
    {
        $lead = $this->visibleLead($request, $tenant, $lead);
        $properties = collect();
        if ($request->attributes->get('membership')->can('view_inventory')) {
            $properties = PropertyListing::where('company_id', $tenant->id())->where('status', 'available')
                ->orderBy('title')->limit(100)->get(['id', 'reference_code', 'title', 'location', 'price_minor_units', 'currency']);
        }
        $account = $this->resolveAccount($tenant->id(), $request->user()->id);
        return response()->json([
            'lead' => ['id' => $lead->id, 'name' => $lead->name, 'phone' => $lead->phone_original],
            'account' => $this->accountData($account),
            'using_company_fallback' => $account && $account->user_id === null,
            'templates' => $templates->ensureDefaults($tenant->id())->map(fn ($template) => $this->templateData($template))->values(),
            'properties' => $properties,
        ]);
    }

    public function history(Request $request, TenantContext $tenant, int $lead)
    {
        $this->visibleLead($request, $tenant, $lead);
        return response()->json(WhatsAppMessage::where('company_id', $tenant->id())->where('lead_id', $lead)
            ->with(['template:id,name', 'sender:id,name', 'propertyListing:id,title,reference_code'])->latest()->paginate(30));
    }

    public function send(Request $request, TenantContext $tenant, int $lead, WhatsAppTemplateService $templates, WhatsAppCloudApi $cloud, AuditLogger $audit)
    {
        $lead = $this->visibleLead($request, $tenant, $lead);
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('update_leads') || ($membership->can('update_own_leads') && ($lead->assigned_to === $request->user()->id || $lead->created_by === $request->user()->id)), 403);
        $templates->ensureDefaults($tenant->id());
        $data = $request->validate([
            'template_id' => ['required', 'integer', Rule::exists('whatsapp_message_templates', 'id')->where('company_id', $tenant->id())->where('is_active', true)],
            'property_listing_id' => ['nullable', 'integer', Rule::exists('property_listings', 'id')->where('company_id', $tenant->id())->whereNull('deleted_at')],
        ]);
        $template = WhatsAppMessageTemplate::where('company_id', $tenant->id())->where('is_active', true)->findOrFail($data['template_id']);
        if ($template->requires_property && empty($data['property_listing_id'])) throw ValidationException::withMessages(['property_listing_id' => 'Select a property for this template.']);
        $property = empty($data['property_listing_id']) ? null : PropertyListing::where('company_id', $tenant->id())->findOrFail($data['property_listing_id']);
        if ($property) abort_unless($membership->can('view_inventory'), 403);
        $account = $this->resolveAccount($tenant->id(), $request->user()->id);
        if (! $account) throw ValidationException::withMessages(['account' => 'Connect a personal or company WhatsApp Business account before sending messages.']);
        $values = $templates->values($tenant->company(), $lead, $property, $request->user()->name);
        $parameters = array_map(fn ($key) => $values[$key] ?? '', $template->parameter_keys);
        $message = WhatsAppMessage::create([
            'company_id' => $tenant->id(), 'lead_id' => $lead->id, 'property_listing_id' => $property?->id,
            'whatsapp_business_account_id' => $account->id, 'whatsapp_message_template_id' => $template->id,
            'sent_by' => $request->user()->id, 'recipient_phone' => $lead->phone_normalized,
            'rendered_body' => $templates->render($template, $values), 'status' => 'sending',
        ]);
        try {
            $result = $cloud->sendTemplate($account, $template, $lead->phone_normalized, $parameters);
        } catch (\Throwable $exception) {
            $message->update(['status' => 'failed', 'error_message' => $exception->getMessage(), 'failed_at' => now()]);
            $audit->log($request, 'whatsapp.message_failed', $message, [], ['lead_id' => $lead->id, 'template_id' => $template->id, 'error' => $exception->getMessage()]);
            throw ValidationException::withMessages(['message' => $exception->getMessage()]);
        }

        // A failure after Meta accepts the message must not relabel delivery as failed;
        // doing so would invite a duplicate send when the user retries.
        $message->update(['status' => $result['driver'] === 'log' ? 'logged' : 'sent', 'provider_message_id' => $result['message_id'], 'provider_response' => $result['response'], 'sent_at' => now()]);
        DB::transaction(function () use ($tenant, $lead, $request, $message, $template) {
            LeadActivity::create(['company_id' => $tenant->id(), 'lead_id' => $lead->id, 'user_id' => $request->user()->id, 'type' => 'whatsapp', 'title' => 'WhatsApp: '.$template->name, 'description' => $message->rendered_body, 'metadata' => ['whatsapp_message_id' => $message->id, 'status' => $message->status], 'occurred_at' => now()]);
            $lead->update(['last_contacted_at' => now()]);
        });
        $audit->log($request, 'whatsapp.message_sent', $message, [], ['lead_id' => $lead->id, 'template_id' => $template->id, 'account_scope' => $account->user_id ? 'personal' : 'company', 'status' => $message->status]);
        return response()->json($message->fresh()->load(['template:id,name', 'sender:id,name', 'propertyListing:id,title,reference_code']), 201);
    }

    private function visibleLead(Request $request, TenantContext $tenant, int $id): Lead
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('view_all_leads') || $membership->can('view_own_leads'), 403);
        $query = Lead::where('company_id', $tenant->id());
        if (! $membership->can('view_all_leads')) $query->where(fn ($q) => $q->where('assigned_to', $request->user()->id)->orWhere('created_by', $request->user()->id));
        return $query->findOrFail($id);
    }

    private function resolveAccount(int $companyId, int $userId): ?WhatsAppBusinessAccount
    {
        return WhatsAppBusinessAccount::where('company_id', $companyId)->where('is_active', true)->where('user_id', $userId)->latest('id')->first()
            ?? WhatsAppBusinessAccount::where('company_id', $companyId)->where('is_active', true)->whereNull('user_id')->latest('id')->first();
    }

    private function accountData(?WhatsAppBusinessAccount $account): ?array
    {
        return $account ? ['id' => $account->id, 'display_name' => $account->display_name, 'phone_number_id' => $account->phone_number_id, 'business_account_id' => $account->business_account_id, 'graph_version' => $account->graph_version, 'scope' => $account->user_id ? 'personal' : 'company', 'has_token' => (bool) $account->access_token] : null;
    }

    private function templateData(WhatsAppMessageTemplate $template): array
    {
        return $template->only(['id', 'key', 'name', 'meta_template_name', 'language_code', 'body', 'parameter_keys', 'requires_property', 'is_active']);
    }
}
