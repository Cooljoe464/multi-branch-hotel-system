<?php

namespace Database\Seeders;

use App\Models\Branding;
use Illuminate\Database\Seeder;

class BrandingSeeder extends Seeder
{
    public function run(): void
    {
        Branding::create([
            'app_name' => config('app.name', 'Laravel'),
            'logo_path' => null,
        ]);
    }
}
