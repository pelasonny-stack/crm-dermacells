<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Local-dev only: creates a Director user with a known email so /dev-login
 * can authenticate as them. NEVER run in production.
 */
class DevDirectorSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DevDirectorSeeder refuses to run in production');

            return;
        }

        // RLS-bypass via migration_role connection in seeders is already
        // configured (BYPASSRLS attribute). Direct insert via raw SQL keeps
        // CHECK constraints honoured.
        DB::statement(<<<'SQL'
            INSERT INTO users (id, email, full_name, role, can_sell, is_active, ai_enabled, created_at, updated_at)
            VALUES (
                gen_random_uuid(),
                'dev@dermacells.local',
                'Director Local Dev',
                'director',
                FALSE,
                TRUE,
                TRUE,
                now(),
                now()
            )
            ON CONFLICT (email) DO NOTHING
        SQL);

        $this->command?->info('Dev director seeded: dev@dermacells.local');
    }
}
