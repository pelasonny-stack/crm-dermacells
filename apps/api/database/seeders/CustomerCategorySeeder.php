<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the four initial customer categories defined in §3.3 of the spec.
 *
 * Cat  Name                           Freq  director_only
 * ---  ---------------------------    ----  -------------
 *  A   Clínica                         30d  false
 *  B   Profesional con clínica grande  45d  false
 *  C   Profesional independiente       60d  false
 *  D   Distribuidor-cliente            30d  true   ← only Directors see/manage cat D clients
 *
 * Uses INSERT ... ON CONFLICT DO NOTHING so the seeder is safe to re-run
 * (idempotent). Timestamps are set explicitly so they are not NULL in test
 * environments that disable model events via WithoutModelEvents.
 */
class CustomerCategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $categories = [
            [
                'id'                     => (string) Str::uuid(),
                'code'                   => 'A',
                'name'                   => 'Clínica',
                'default_frequency_days' => 30,
                'director_only'          => false,
                'is_active'              => true,
                'created_at'             => $now,
                'updated_at'             => $now,
            ],
            [
                'id'                     => (string) Str::uuid(),
                'code'                   => 'B',
                'name'                   => 'Profesional con clínica grande',
                'default_frequency_days' => 45,
                'director_only'          => false,
                'is_active'              => true,
                'created_at'             => $now,
                'updated_at'             => $now,
            ],
            [
                'id'                     => (string) Str::uuid(),
                'code'                   => 'C',
                'name'                   => 'Profesional independiente',
                'default_frequency_days' => 60,
                'director_only'          => false,
                'is_active'              => true,
                'created_at'             => $now,
                'updated_at'             => $now,
            ],
            [
                'id'                     => (string) Str::uuid(),
                'code'                   => 'D',
                'name'                   => 'Distribuidor-cliente',
                'default_frequency_days' => 30,
                'director_only'          => true,
                'is_active'              => true,
                'created_at'             => $now,
                'updated_at'             => $now,
            ],
        ];

        foreach ($categories as $category) {
            DB::statement(<<<SQL
                INSERT INTO customer_categories
                    (id, code, name, default_frequency_days, director_only, is_active, created_at, updated_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (code) DO NOTHING
            SQL, [
                $category['id'],
                $category['code'],
                $category['name'],
                $category['default_frequency_days'],
                $category['director_only'] ? 'true' : 'false',
                $category['is_active']     ? 'true' : 'false',
                $category['created_at'],
                $category['updated_at'],
            ]);
        }
    }
}
