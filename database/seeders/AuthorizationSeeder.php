<?php

namespace Database\Seeders;

use App\Support\AuthorizationCatalog;
use Illuminate\Database\Seeder;

class AuthorizationSeeder extends Seeder
{
    public function run(AuthorizationCatalog $catalog): void
    {
        $catalog->install();
    }
}
