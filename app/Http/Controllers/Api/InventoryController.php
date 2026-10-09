<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyListing;
use App\Models\PropertyListingActivity;
use App\Models\PropertyPhoto;
use App\Models\PreferredLocation;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    private const PROPERTY_TYPES = ['apartment', 'villa', 'townhouse', 'twin_house', 'duplex', 'office', 'retail', 'chalet', 'land', 'other'];
    private const STATUSES = ['available', 'reserved', 'sold', 'rented', 'withdrawn'];

    public function index(Request $request, TenantContext $tenant)
    {
        $this->authorizeView($request);
        $query = PropertyListing::where('company_id', $tenant->id())->with(['photos', 'preferredLocation']);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('reference_code', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhereHas('preferredLocation', fn ($locations) => $locations->where('name', 'like', "%{$search}%"));
            });
        }
        foreach (['status', 'listing_type', 'property_type'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }
        if ($location = $request->query('location')) {
            $query->where(function ($builder) use ($location) {
                $builder->where('location', 'like', "%{$location}%")
                    ->orWhereHas('preferredLocation', fn ($locations) => $locations->where('name', 'like', "%{$location}%"));
            });
        }

        $sort = in_array($request->query('sort'), ['created_at', 'price_minor_units', 'title'], true) ? $request->query('sort') : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $page = $query->orderBy($sort, $direction)->paginate(min(max((int) $request->query('per_page', 15), 1), 50));
        $page->getCollection()->transform(fn (PropertyListing $listing) => $this->serialize($listing));

        return response()->json($page);
    }

    public function show(Request $request, TenantContext $tenant, int $listing)
    {
        $this->authorizeView($request);
        $listing = $this->findListing($tenant, $listing)->load(['photos', 'activities.user:id,name']);
        return response()->json($this->serialize($listing, true));
    }

    public function store(Request $request, TenantContext $tenant, AuditLogger $audit)
    {
        $this->authorizeManage($request);
        $data = $this->validateListing($request, $tenant);
        $this->applyMasterLocation($data, $tenant);
        $priceMinor = $this->toMinorUnits($data['price_egp']);
        unset($data['price_egp']);
        $data['reference_code'] = ($data['reference_code'] ?? null) ?: $this->generateReference($tenant);

        $listing = PropertyListing::create($data + [
            'company_id' => $tenant->id(), 'listed_by' => $request->user()->id,
            'price_minor_units' => $priceMinor, 'currency' => 'EGP',
        ]);
        $this->recordActivity($request, $listing, 'created', [], $listing->only(['reference_code', 'title', 'listing_type', 'status', 'price_minor_units']));
        $audit->log($request, 'inventory.listing_created', $listing, [], $listing->only(['reference_code', 'title', 'listing_type', 'status', 'price_minor_units']));

        return response()->json($this->serialize($listing->load('photos')), 201);
    }

    public function update(Request $request, TenantContext $tenant, int $listing, AuditLogger $audit)
    {
        $this->authorizeManage($request);
        $listing = $this->findListing($tenant, $listing);
        $data = $this->validateListing($request, $tenant, $listing, true);
        $this->applyMasterLocation($data, $tenant, $listing);
        if (array_key_exists('price_egp', $data)) {
            $data['price_minor_units'] = $this->toMinorUnits($data['price_egp']);
            unset($data['price_egp']);
        }

        $old = $listing->getAttributes();
        $listing->fill($data)->save();
        $changes = $listing->getChanges();
        if ($changes) {
            $oldValues = array_intersect_key($old, $changes);
            $event = array_key_exists('status', $changes) ? 'status_changed' : (array_key_exists('price_minor_units', $changes) ? 'price_changed' : 'updated');
            $this->recordActivity($request, $listing, $event, $oldValues, $changes);
            $audit->log($request, 'inventory.listing_updated', $listing, $oldValues, $changes);
        }

        return response()->json($this->serialize($listing->fresh()->load('photos')));
    }

    public function uploadPhoto(Request $request, TenantContext $tenant, int $listing, AuditLogger $audit)
    {
        $this->authorizeManage($request);
        $listing = $this->findListing($tenant, $listing);
        if ($listing->photos()->count() >= 20) {
            throw ValidationException::withMessages(['photo' => 'A listing can have up to 20 photos.']);
        }
        $data = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,avif', 'max:10240'],
        ]);
        $file = $data['photo'];
        $filename = Str::uuid().'.'.$file->extension();
        $path = $file->storeAs("companies/{$tenant->id()}/inventory/{$listing->id}", $filename, 'private');
        $photo = $listing->photos()->create([
            'company_id' => $tenant->id(), 'storage_path' => $path,
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(),
            'position' => ((int) $listing->photos()->max('position')) + 1,
        ]);

        $this->recordActivity($request, $listing, 'photo_uploaded', [], ['photo_id' => $photo->id, 'original_name' => $photo->original_name]);
        $audit->log($request, 'inventory.photo_uploaded', $photo, [], ['property_listing_id' => $listing->id, 'mime_type' => $photo->mime_type, 'size_bytes' => $photo->size_bytes]);

        return response()->json($this->serializePhoto($photo), 201);
    }

    public function showPhoto(Request $request, TenantContext $tenant, int $listing, int $photo)
    {
        $this->authorizeView($request);
        $listing = $this->findListing($tenant, $listing);
        $photo = PropertyPhoto::where('company_id', $tenant->id())->where('property_listing_id', $listing->id)->findOrFail($photo);

        return Storage::disk('private')->response($photo->storage_path, $photo->original_name, [
            'Content-Type' => $photo->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function deletePhoto(Request $request, TenantContext $tenant, int $listing, int $photo, AuditLogger $audit)
    {
        $this->authorizeManage($request);
        $listing = $this->findListing($tenant, $listing);
        $photo = PropertyPhoto::where('company_id', $tenant->id())->where('property_listing_id', $listing->id)->findOrFail($photo);
        $photo->delete();
        $this->recordActivity($request, $listing, 'photo_removed', [], ['photo_id' => $photo->id, 'original_name' => $photo->original_name]);
        $audit->log($request, 'inventory.photo_removed', $photo, ['original_name' => $photo->original_name], []);

        return response()->noContent();
    }

    private function validateListing(Request $request, TenantContext $tenant, ?PropertyListing $listing = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $referenceRule = Rule::unique('property_listings', 'reference_code')->where('company_id', $tenant->id());
        if ($listing) $referenceRule->ignore($listing->id);

        return $request->validate([
            'reference_code' => ['nullable', 'string', 'max:40', $referenceRule],
            'title' => [$required, 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:10000'],
            'listing_type' => [$required, Rule::in(['sale', 'rent'])],
            'property_type' => [$required, Rule::in(self::PROPERTY_TYPES)],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'preferred_location_id' => [$required, 'integer', Rule::exists('preferred_locations', 'id')->where('company_id', $tenant->id())],
            'address' => ['nullable', 'string', 'max:255'],
            'price_egp' => [$required, 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'area_sqm' => ['nullable', 'numeric', 'gt:0', 'max:99999999.99'],
        ]);
    }

    private function applyMasterLocation(array &$data, TenantContext $tenant, ?PropertyListing $listing = null): void
    {
        if (! array_key_exists('preferred_location_id', $data)) return;

        $location = PreferredLocation::where('company_id', $tenant->id())->findOrFail($data['preferred_location_id']);
        if (! $location->is_active && $location->id !== $listing?->preferred_location_id) {
            throw ValidationException::withMessages(['preferred_location_id' => 'Select an active workspace location.']);
        }

        $data['location'] = $location->name;
    }

    private function toMinorUnits(string $amount): int
    {
        [$pounds, $piastres] = array_pad(explode('.', $amount, 2), 2, '');
        $minor = ((int) $pounds * 100) + (int) str_pad($piastres, 2, '0');
        if ($minor < 1) throw ValidationException::withMessages(['price_egp' => 'The price must be greater than zero.']);
        return $minor;
    }

    private function generateReference(TenantContext $tenant): string
    {
        do { $reference = 'INV-'.$tenant->id().'-'.Str::upper(Str::random(6)); }
        while (PropertyListing::where('company_id', $tenant->id())->where('reference_code', $reference)->exists());
        return $reference;
    }

    private function findListing(TenantContext $tenant, int $id): PropertyListing
    {
        return PropertyListing::where('company_id', $tenant->id())->findOrFail($id);
    }

    private function serialize(PropertyListing $listing, bool $includeHistory = false): array
    {
        $serialized = [
            'id' => $listing->id, 'company_id' => $listing->company_id, 'reference_code' => $listing->reference_code,
            'title' => $listing->title, 'description' => $listing->description, 'listing_type' => $listing->listing_type,
            'property_type' => $listing->property_type, 'status' => $listing->status,
            'location' => $listing->preferredLocation?->name ?? $listing->location,
            'preferred_location_id' => $listing->preferred_location_id,
            'address' => $listing->address, 'price_egp' => $this->fromMinorUnits($listing->price_minor_units),
            'price_minor_units' => $listing->price_minor_units, 'currency' => $listing->currency,
            'bedrooms' => $listing->bedrooms, 'bathrooms' => $listing->bathrooms, 'area_sqm' => $listing->area_sqm,
            'listed_by' => $listing->listed_by, 'created_at' => $listing->created_at,
            'photos' => $listing->photos->map(fn (PropertyPhoto $photo) => $this->serializePhoto($photo))->values(),
        ];
        if ($includeHistory) {
            $serialized['activities'] = $listing->activities->map(fn (PropertyListingActivity $activity) => [
                'id' => $activity->id, 'event' => $activity->event, 'old_values' => $activity->old_values,
                'new_values' => $activity->new_values, 'occurred_at' => $activity->occurred_at,
                'user' => $activity->user,
            ])->values();
        }
        return $serialized;
    }

    private function serializePhoto(PropertyPhoto $photo): array
    {
        return [
            'id' => $photo->id, 'original_name' => $photo->original_name, 'mime_type' => $photo->mime_type,
            'size_bytes' => $photo->size_bytes, 'position' => $photo->position,
            'url' => route('inventory.photos.show', ['listing' => $photo->property_listing_id, 'photo' => $photo->id]),
        ];
    }

    private function fromMinorUnits(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function recordActivity(Request $request, PropertyListing $listing, string $event, array $old, array $new): void
    {
        PropertyListingActivity::create([
            'company_id' => $listing->company_id, 'property_listing_id' => $listing->id,
            'user_id' => $request->user()->id, 'event' => $event,
            'old_values' => $old ?: null, 'new_values' => $new ?: null, 'occurred_at' => now(),
        ]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->can('view_inventory'), 403);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->can('manage_inventory'), 403);
    }
}
