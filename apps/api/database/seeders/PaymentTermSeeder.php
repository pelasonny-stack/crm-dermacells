<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the five standard payment terms defined in §3.4 of the spec.
 *
 * Name       days_to_due
 * ---------  -----------
 * Contado         0
 * 15 días        15
 * 30 días        30
 * 45 días        45
 * 60 días        60
 *
 * Uses INSERT ... ON CONFLICT DO NOTHING so re-running the seeder is safe.
 * Conflicts are matched on (name, days_to_due) to avoid creating duplicates
 * if the seeder is run again.
 *
 * NOTE: There is no unique constraint on (name) or (days_to_due) in the schema
 * because Directors are allowed to create custom terms (e.g. "30 días especial",
 * 30d). We use application-level deduplication here and rely on the database
 * uniqueness only on the primary key. The ON CONFLICT target below uses a
 * partial approach: if a row with the same (name) exists already, skip.
 * A unique index on name would be cleaner but was not specified — adding one
 * here would conflict with future custom terms having the same day count but
 * different names.
 *
 * To handle idempotency without a unique constraint on name, we check
 * existence before inserting.
 */
class PaymentTermSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $terms = [
            ['name' => 'Contado',  'days_to_due' => 0],
            ['name' => '15 días',  'days_to_due' => 15],
            ['name' => '30 días',  'days_to_due' => 30],
            ['name' => '45 días',  'days_to_due' => 45],
            ['name' => '60 días',  'days_to_due' => 60],
        ];

        foreach ($terms as $term) {
            $exists = DB::selectOne(
                'SELECT id FROM payment_terms WHERE name = ? LIMIT 1',
                [$term['name']]
            );

            if ($exists) {
                continue;
            }

            DB::statement(
                'INSERT INTO payment_terms (id, name, days_to_due, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, TRUE, ?, ?)',
                [
                    (string) Str::uuid(),
                    $term['name'],
                    $term['days_to_due'],
                    $now,
                    $now,
                ]
            );
        }
    }
}
