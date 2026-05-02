<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the four LiveCells product lines — §5.1.
 *
 * Products:
 *   - Dermal     · 5 units/box · USD 750
 *   - Pink       · 5 units/box · USD 750
 *   - Capillary  · 5 units/box · USD 750
 *   - Biomask    · 5 units/box · USD 750
 *
 * Idempotent: uses ON CONFLICT (name) DO NOTHING so re-running this seeder
 * or running db:seed on a database that already has products is a no-op. The
 * unique constraint on products.name is defined in the migration.
 *
 * NOTE: This seeder runs raw SQL to avoid having to SET LOCAL app.user_role
 * inside the seeder — when called from DatabaseSeeder the connection already
 * has the migration_role context (or a direct connection that bypasses RLS in
 * local dev). For test environments the TestCase base sets up director context.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->toDateTimeString();

        $products = [
            ['name' => 'Dermal',    'units_per_box' => 5, 'base_price_amount' => '750.0000', 'base_price_currency' => 'USD'],
            ['name' => 'Pink',      'units_per_box' => 5, 'base_price_amount' => '750.0000', 'base_price_currency' => 'USD'],
            ['name' => 'Capillary', 'units_per_box' => 5, 'base_price_amount' => '750.0000', 'base_price_currency' => 'USD'],
            ['name' => 'Biomask',   'units_per_box' => 5, 'base_price_amount' => '750.0000', 'base_price_currency' => 'USD'],
        ];

        foreach ($products as $product) {
            DB::statement(<<<SQL
                INSERT INTO products (id, name, units_per_box, base_price_amount, base_price_currency, is_active, created_at, updated_at)
                VALUES (gen_random_uuid(), ?, ?, ?, ?, TRUE, ?, ?)
                ON CONFLICT (name) DO NOTHING
            SQL, [
                $product['name'],
                $product['units_per_box'],
                $product['base_price_amount'],
                $product['base_price_currency'],
                $now,
                $now,
            ]);
        }
    }
}
