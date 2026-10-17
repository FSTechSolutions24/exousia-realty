<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Lead;
use App\Models\PropertyListing;
use App\Models\WhatsAppMessageTemplate;
use Illuminate\Support\Collection;

class WhatsAppTemplateService
{
    private const DEFAULTS = [
        ['key' => 'property_recommendation', 'name' => 'Property recommendation', 'meta_template_name' => 'property_recommendation', 'body' => "Hello {{lead_name}},\n\nI found a property that may suit you:\n{{property_title}} ({{property_reference}})\n{{property_location}} · {{property_price}}\n\nReply here if you would like more details or a viewing.", 'parameter_keys' => ['lead_name', 'property_title', 'property_reference', 'property_location', 'property_price'], 'requires_property' => true],
        ['key' => 'viewing_follow_up', 'name' => 'Viewing follow-up', 'meta_template_name' => 'viewing_follow_up', 'body' => "Hello {{lead_name}},\n\nThank you for viewing {{property_title}}. I would love to hear your feedback and help with the next step.\n\n{{agent_name}} · {{company_name}}", 'parameter_keys' => ['lead_name', 'property_title', 'agent_name', 'company_name'], 'requires_property' => true],
        ['key' => 'general_follow_up', 'name' => 'General follow-up', 'meta_template_name' => 'lead_follow_up', 'body' => "Hello {{lead_name}},\n\nI am following up on your property search. Let me know a suitable time to speak.\n\n{{agent_name}} · {{company_name}}", 'parameter_keys' => ['lead_name', 'agent_name', 'company_name'], 'requires_property' => false],
    ];

    public function ensureDefaults(int $companyId): Collection
    {
        foreach (self::DEFAULTS as $template) {
            WhatsAppMessageTemplate::firstOrCreate(['company_id' => $companyId, 'key' => $template['key']], $template);
        }

        return WhatsAppMessageTemplate::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
    }

    public function values(Company $company, Lead $lead, ?PropertyListing $property, string $agentName): array
    {
        return [
            'lead_name' => $lead->name,
            'property_title' => $property?->title ?? '',
            'property_reference' => $property?->reference_code ?? '',
            'property_location' => $property?->location ?? '',
            'property_price' => $property ? number_format($property->price_minor_units / 100, 0).' EGP' : '',
            'agent_name' => $agentName,
            'company_name' => $company->name,
        ];
    }

    public function render(WhatsAppMessageTemplate $template, array $values): string
    {
        $replacements = [];
        foreach ($values as $key => $value) $replacements['{{'.$key.'}}'] = (string) $value;
        return strtr($template->body, $replacements);
    }
}
