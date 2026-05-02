<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates the audit_log partition for the next calendar month if it does not
 * already exist. Designed to be scheduled monthly (see routes/console.php).
 *
 * Partition naming convention: audit_log_YYYY_MM
 * Example: audit_log_2026_06
 *
 * The command is idempotent — running it multiple times for the same month is
 * safe because it uses CREATE TABLE IF NOT EXISTS.
 *
 * Indexes are also created with IF NOT EXISTS so re-runs do not error out.
 *
 * Usage:
 *   php artisan audit:create-next-partition
 *
 * Scheduling (already configured in routes/console.php):
 *   Schedule::command('audit:create-next-partition')->monthlyOn(1, '00:05');
 *
 * IMPORTANT: This command must run under migration_role (or a superuser with
 * DDL privileges) because it executes CREATE TABLE. The default app_role does
 * not have CREATE privilege. In production, ensure the artisan scheduler runs
 * as migration_role or that you grant CREATE ON SCHEMA public to app_role
 * (not recommended). The safest approach is a dedicated cron entry that sets
 * the PGUSER environment variable to migration_role before running artisan.
 *
 * Alternatively, connect via the pgsql_migration connection:
 *   DB::connection('pgsql_migration')->statement(...)
 * which is what this command does.
 */
class CreateMonthlyAuditPartition extends Command
{
    protected $signature = 'audit:create-next-partition
                            {--month= : Target month in YYYY-MM format (defaults to next calendar month)}';

    protected $description = 'Creates the audit_log partition for the next month (idempotent)';

    public function handle(): int
    {
        $targetMonth = $this->option('month');

        if ($targetMonth) {
            $start = \DateTimeImmutable::createFromFormat('Y-m', $targetMonth);

            if ($start === false) {
                $this->error("Invalid --month value '{$targetMonth}'. Expected format: YYYY-MM");

                return self::FAILURE;
            }

            $start = $start->modify('first day of this month midnight UTC');
        } else {
            // Default: next calendar month
            $start = new \DateTimeImmutable('first day of next month midnight UTC');
        }

        $end           = $start->modify('+1 month');
        $partitionName = 'audit_log_' . $start->format('Y_m');
        $startStr      = $start->format('Y-m-d');
        $endStr        = $end->format('Y-m-d');

        $this->info("Creating partition {$partitionName} ({$startStr} — {$endStr}) ...");

        // Use the migration connection which has DDL privileges.
        $conn = DB::connection('pgsql_migration');

        $conn->statement(<<<SQL
            CREATE TABLE IF NOT EXISTS {$partitionName}
            PARTITION OF audit_log
            FOR VALUES FROM ('{$startStr}') TO ('{$endStr}')
        SQL);

        $conn->statement(<<<SQL
            CREATE INDEX IF NOT EXISTS {$partitionName}_actor_occurred_idx
            ON {$partitionName} (actor_user_id, occurred_at)
        SQL);

        $conn->statement(<<<SQL
            CREATE INDEX IF NOT EXISTS {$partitionName}_entity_idx
            ON {$partitionName} (entity_type, entity_id)
        SQL);

        $conn->statement(<<<SQL
            CREATE INDEX IF NOT EXISTS {$partitionName}_section_idx
            ON {$partitionName} (section)
        SQL);

        $this->info("Partition {$partitionName} ready.");

        return self::SUCCESS;
    }
}
