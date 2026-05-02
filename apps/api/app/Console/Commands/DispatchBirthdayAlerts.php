<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CustomerContact;
use App\Models\ScheduledAction;
use App\Notifications\BirthdayReminder;
use App\Notifications\ScheduledActionDue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches daily birthday and scheduled-action alerts.
 *
 * SCHEDULE: daily at 7:55 AM ART (America/Argentina/Buenos_Aires).
 *   - Fires 5 minutes before 8:00 AM to account for scheduler jitter.
 *   - Notifications have a `uniqueId()` for idempotency — safe to re-run.
 *
 * This command runs as worker_role (BYPASSRLS = NO) via the Horizon process.
 * It manually sets the RLS GUC to 'director' scope so it can read all
 * customers/contacts regardless of assigned seller. This is intentional:
 * the command acts on behalf of the system, not a specific user.
 *
 * IDEMPOTENCY
 * ===========
 * The notifications table is checked before sending. If a notification with
 * the same `unique_id` was already sent today, the contact/action is skipped.
 * This prevents double-fire on scheduler restart or manual re-run.
 *
 * BIRTHDAY QUERIES
 * ================
 * The query filters on EXTRACT(MONTH FROM birthday) and EXTRACT(DAY FROM birthday)
 * rather than birthday = today, because birthdays are year-agnostic — a contact
 * born 1990-04-15 should fire every April 15th regardless of year.
 */
class DispatchBirthdayAlerts extends Command
{
    protected $signature = 'customers:dispatch-birthday-alerts
                            {--dry-run : List contacts/actions without sending notifications}';

    protected $description = 'Dispatch birthday reminder and scheduled-action-due notifications (runs daily at 7:55 AM ART)';

    public function handle(): int
    {
        $today   = now()->timezone('America/Argentina/Buenos_Aires');
        $isDryRun = $this->option('dry-run');

        $birthdayCount = $this->dispatchBirthdayReminders($today, $isDryRun);
        $actionCount   = $this->dispatchScheduledActions($today, $isDryRun);

        $mode = $isDryRun ? '[DRY RUN] ' : '';
        $this->info("{$mode}Birthday reminders: {$birthdayCount}. Scheduled actions: {$actionCount}.");

        Log::info('customers:dispatch-birthday-alerts completed', [
            'date'             => $today->toDateString(),
            'birthday_count'   => $birthdayCount,
            'action_count'     => $actionCount,
            'dry_run'          => $isDryRun,
        ]);

        return Command::SUCCESS;
    }

    private function dispatchBirthdayReminders(\Carbon\Carbon $today, bool $isDryRun): int
    {
        // Run under director scope so worker_role can read across all customers
        return DB::transaction(function () use ($today, $isDryRun): int {
            DB::statement("SET LOCAL app.user_role = 'director'");
            DB::statement("SET LOCAL app.user_id = '00000000-0000-0000-0000-000000000000'");

            $contacts = CustomerContact::query()
                ->whereNotNull('birthday')
                ->whereRaw('EXTRACT(MONTH FROM birthday) = ?', [$today->month])
                ->whereRaw('EXTRACT(DAY FROM birthday) = ?', [$today->day])
                ->with(['customer.assignedSeller'])
                ->get();

            $count = 0;
            foreach ($contacts as $contact) {
                $seller = $contact->customer?->assignedSeller;
                if ($seller === null) {
                    continue;
                }

                $uniqueId = "birthday-{$contact->id}-{$today->toDateString()}";

                // Idempotency: skip if already sent today
                if ($this->wasAlreadySent($uniqueId)) {
                    continue;
                }

                if (! $isDryRun) {
                    $seller->notify(new BirthdayReminder($contact));
                } else {
                    $this->line("  [birthday] {$contact->full_name} → seller: {$seller->full_name}");
                }

                $count++;
            }

            return $count;
        });
    }

    private function dispatchScheduledActions(\Carbon\Carbon $today, bool $isDryRun): int
    {
        return DB::transaction(function () use ($today, $isDryRun): int {
            DB::statement("SET LOCAL app.user_role = 'director'");
            DB::statement("SET LOCAL app.user_id = '00000000-0000-0000-0000-000000000000'");

            $actions = ScheduledAction::query()
                ->pending()
                ->dueOn($today)
                ->with(['customer.assignedSeller'])
                ->get();

            $count = 0;
            foreach ($actions as $action) {
                $seller = $action->customer?->assignedSeller;
                if ($seller === null) {
                    continue;
                }

                $uniqueId = "scheduled-action-{$action->id}-{$today->toDateString()}";

                if ($this->wasAlreadySent($uniqueId)) {
                    continue;
                }

                if (! $isDryRun) {
                    $seller->notify(new ScheduledActionDue($action));
                } else {
                    $this->line("  [action] customer: {$action->customer_id} | note: {$action->note} → seller: {$seller->full_name}");
                }

                $count++;
            }

            return $count;
        });
    }

    /**
     * Check the notifications table for a notification with the given unique_id
     * sent today. Uses a JSON path query on the `data` column.
     */
    private function wasAlreadySent(string $uniqueId): bool
    {
        return DB::table('notifications')
            ->whereDate('created_at', now()->timezone('America/Argentina/Buenos_Aires')->toDateString())
            ->whereRaw("data->>'unique_id' = ?", [$uniqueId])
            ->exists();
    }
}
