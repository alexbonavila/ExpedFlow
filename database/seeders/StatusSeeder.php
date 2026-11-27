<?php

namespace Database\Seeders;

use App\Models\Status;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

class StatusSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $initialized = DB::table('seeded_catalogs')->insertOrIgnore(['catalog' => 'initial_statuses']);

            if ($initialized === 0) {
                return;
            }

            $labels = ['waiting', 'pending', 'in_progress', 'paused', 'to_verify', 'completed'];

            foreach ($labels as $offset => $label) {
                $translations = [
                    'es' => Lang::get("statuses.$label", locale: 'es'),
                    'ca' => Lang::get("statuses.$label", locale: 'ca'),
                    'en' => Lang::get("statuses.$label", locale: 'en'),
                ];

                Status::query()->forceCreate([
                    'name' => $translations['es'],
                    'name_translations' => $translations,
                    'position' => $offset + 1,
                ]);
            }
        });
    }
}
