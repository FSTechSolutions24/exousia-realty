<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\PropertyListing;
use Illuminate\Support\Collection;

class PropertyMatchingService
{
    /**
     * Return available listings that satisfy the lead's explicitly stated hard
     * requirements, ordered by a deterministic weighted score.
     */
    public function match(Lead $lead, int $limit = 10): Collection
    {
        if ($lead->intent === 'sell') return collect();

        $query = PropertyListing::query()
            ->where('company_id', $lead->company_id)
            ->where('status', 'available')
            ->with(['preferredLocation', 'photos']);

        if ($lead->intent === 'buy') $query->where('listing_type', 'sale');
        if ($lead->intent === 'rent') $query->where('listing_type', 'rent');
        if ($lead->property_type) $query->where('property_type', $lead->property_type);
        if ($lead->bedrooms !== null) $query->where('bedrooms', '>=', $lead->bedrooms);
        // Lead budgets are stored as whole EGP while listing prices use piastres.
        if ($lead->budget_min !== null) $query->where('price_minor_units', '>=', $lead->budget_min * 100);
        if ($lead->budget_max !== null) $query->where('price_minor_units', '<=', $lead->budget_max * 100);

        $locations = collect($lead->preferred_locations ?? [])
            ->map(fn ($name) => mb_strtolower(trim((string) $name)))
            ->filter()->unique()->values();

        return $query->get()
            ->map(fn (PropertyListing $listing) => $this->score($listing, $locations, $lead))
            ->sort(fn (array $a, array $b) => ($b['score'] <=> $a['score']) ?: ($a['listing']['id'] <=> $b['listing']['id']))
            ->take(max(1, min($limit, 20)))
            ->values();
    }

    private function score(PropertyListing $listing, Collection $locations, Lead $lead): array
    {
        $reasons = [];
        $earned = 0;
        $possible = 0;
        if ($lead->property_type) {
            $possible += 35;
            $earned += 35;
            $reasons[] = 'Property type matches';
        }
        if ($lead->bedrooms !== null) {
            $possible += 20;
            $earned += 20;
            $reasons[] = 'Bedroom requirement met';
        }
        if ($lead->budget_min !== null || $lead->budget_max !== null) {
            $possible += 25;
            $earned += 25;
            $reasons[] = 'Within budget';
        }
        if ($locations->isNotEmpty()) {
            $possible += 20;
            $listingLocation = mb_strtolower(trim((string) ($listing->preferredLocation?->name ?? $listing->location)));
            if ($locations->contains($listingLocation)) {
                $earned += 20;
                $reasons[] = 'Preferred location';
            }
        }
        if (! $reasons) $reasons[] = 'Available in this workspace';
        $score = $possible > 0 ? (int) round(($earned / $possible) * 100) : 100;

        return [
            'score' => $score,
            'reasons' => $reasons,
            'listing' => [
                'id' => $listing->id,
                'reference_code' => $listing->reference_code,
                'title' => $listing->title,
                'listing_type' => $listing->listing_type,
                'property_type' => $listing->property_type,
                'location' => $listing->preferredLocation?->name ?? $listing->location,
                'price_egp' => intdiv($listing->price_minor_units, 100).'.'.str_pad((string) ($listing->price_minor_units % 100), 2, '0', STR_PAD_LEFT),
                'bedrooms' => $listing->bedrooms,
                'photos' => $listing->photos->map(fn ($photo) => [
                    'id' => $photo->id,
                    'url' => route('inventory.photos.show', ['listing' => $listing->id, 'photo' => $photo->id]),
                ])->values(),
            ],
        ];
    }
}
