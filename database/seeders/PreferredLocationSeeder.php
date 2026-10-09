<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\PreferredLocation;
use Illuminate\Database\Seeder;

class PreferredLocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            ['New Cairo', 'القاهرة الجديدة'],
            ['New Capital', 'العاصمة الإدارية الجديدة'],
            ['Sheikh Zayed', 'الشيخ زايد'],
            ['6th of October', 'السادس من أكتوبر'],
            ['Mostakbal City', 'مدينة المستقبل'],
            ['Maadi', 'المعادي'],
            ['North Coast', 'الساحل الشمالي'],
        ];

        Company::query()->each(function (Company $company) use ($locations) {
            foreach ($locations as $position => [$name, $nameAr]) {
                PreferredLocation::updateOrCreate(
                    ['company_id' => $company->id, 'name' => $name],
                    ['name_ar' => $nameAr, 'position' => $position, 'is_active' => true]
                );
            }
        });
    }
}
