<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\PropertyListing;
use App\Models\PreferredLocation;
use App\Models\User;
use Illuminate\Database\Seeder;

class PropertyListingSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('slug', 'nile-gate-demo')->first();
        $agent = User::where('email', 'agent@demo.exousia.test')->first();
        if (! $company || ! $agent) return;

        $properties = [
            ['NG-APT-104', 'Bright apartment near the business district', 'sale', 'apartment', 'New Cairo', 725000000, 3, 2, '168.00'],
            ['NG-VIL-208', 'Family villa with private garden', 'sale', 'villa', 'Sheikh Zayed', 1680000000, 5, 4, '340.00'],
            ['NG-CHL-031', 'Coastal chalet with sea view', 'sale', 'chalet', 'North Coast', 940000000, 3, 2, '142.50'],
            ['NG-OFF-019', 'Furnished office suite', 'rent', 'office', 'New Cairo', 8500000, null, 2, '112.00'],
        ];

        foreach ($properties as [$code, $title, $type, $propertyType, $location, $priceMinor, $bedrooms, $bathrooms, $area]) {
            $masterLocation = PreferredLocation::where('company_id', $company->id)->where('name', $location)->first();
            PropertyListing::firstOrCreate(
                ['company_id' => $company->id, 'reference_code' => $code],
                [
                    'title' => $title, 'description' => 'Fictional property listing for local development only.',
                    'listing_type' => $type, 'property_type' => $propertyType, 'status' => 'available',
                    'location' => $location, 'preferred_location_id' => $masterLocation?->id,
                    'address' => null, 'price_minor_units' => $priceMinor,
                    'currency' => 'EGP', 'bedrooms' => $bedrooms, 'bathrooms' => $bathrooms,
                    'area_sqm' => $area, 'listed_by' => $agent->id,
                ]
            );
        }
    }
}
