<?php

namespace Database\Seeders;

use App\Models\TemplateType;
use Illuminate\Database\Seeder;

class TemplateTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['slug' => 'operation', 'name' => 'Operation Manager'],
            ['slug' => 'store_manager', 'name' => 'Store Manager'],
            ['slug' => 'vm', 'name' => 'VM'],
            ['slug' => 'area_manager', 'name' => 'Area Manager'],
            ['slug' => 'maintenance', 'name' => 'Maintenance'],
            ['slug' => 'custom', 'name' => 'Custom'],
        ];

        foreach ($types as $t) {
            TemplateType::updateOrCreate(['slug' => $t['slug']], $t);
        }
    }
}
