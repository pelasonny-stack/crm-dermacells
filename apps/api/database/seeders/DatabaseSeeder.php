<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Root seeder — calls all application seeders in dependency order.
 *
 * Run with:
 *   php artisan db:seed
 *
 * Or a specific seeder:
 *   php artisan db:seed --class=CustomerCategorySeeder
 *
 * All seeders are idempotent (safe to re-run).
 *
 * NOTE: The default Laravel User::factory() call that was here has been
 * removed because the users table schema is now OAuth-only (no password field)
 * and uses UUID primary keys. User factories should be defined in
 * database/factories/UserFactory.php using Str::uuid() for the id and
 * omitting the password field entirely.
 *
 * For local development / testing, create a test director user via:
 *   php artisan tinker
 *   DB::table('users')->insert([...])
 * or build a proper UserFactory in Phase 1.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CustomerCategorySeeder::class,
            PaymentTermSeeder::class,
            ProductSeeder::class,
            CommissionScaleSeeder::class,
            // Phase 7 — Cobranzas
            PaymentMethodSeeder::class,
        ]);

        // Demo data (dev only — NOT auto-run; call explicitly):
        // php artisan db:seed --class=Database\\Seeders\\DemoDataSeeder --database=pgsql_migration --force
        // $this->call(DemoDataSeeder::class);
    }
}
