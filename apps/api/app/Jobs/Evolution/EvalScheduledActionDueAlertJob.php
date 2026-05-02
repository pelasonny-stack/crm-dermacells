<?php

declare(strict_types=1);

namespace App\Jobs\Evolution;

use App\Domain\Alerts\Services\AlertDispatcher;
use App\Models\ScheduledAction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * EvalScheduledActionDueAlertJob — Phase 10 extension of Phase 3 (§3.8, §10.3).
 *
 * Phase 3 handled scheduled_action alerts via the `customers:dispatch-birthday-alerts`
 * Artisan command. This job replaces/extends that path by routing through the
 * centralised AlertDispatcher — giving scheduled-action alerts the same
 * persistence, idempotency, RLS visibility, and Filament viewer as all other
 * Phase 10 alert types.
 *
 * Trigger: scheduled_actions rows where scheduled_date = today AND is_resolved = false.
 *
 * Recipient: the Seller assigned to the customer (customer.assigned_seller_id).
 *
 * Note: The existing DispatchBirthdayAlerts command continues to run for
 * birthday notifications (§3.6). This job handles ONLY scheduled_actions
 * (§3.8). Both can co-exist without duplication because they use different
 * alert_type values ('scheduled_action_due' vs birthday push).
 */
class EvalScheduledActionDueAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 600];

    public function handle(AlertDispatcher $dispatcher): void
    {
        $today = now()->toDateString();

        $actions = ScheduledAction::with(['customer.assignedSeller'])
            ->where('scheduled_date', $today)
            ->where('is_resolved', false)
            ->get();

        $fired = 0;

        foreach ($actions as $action) {
            $customer = $action->customer;

            if (! $customer || ! $customer->is_active) {
                continue;
            }

            $seller = $customer->assignedSeller;

            if (! $seller instanceof User) {
                continue;
            }

            $dispatcher->dispatch(
                type: 'scheduled_action_due',
                targets: $seller,
                reference: $action,
                payload: [
                    'customer_id'   => $customer->id,
                    'customer_name' => $customer->fullName(),
                    'note'          => $action->note,
                    'scheduled_date' => $action->scheduled_date->toDateString(),
                ],
                severity: 'info',
            );

            $fired++;
        }

        Log::info("EvalScheduledActionDueAlertJob: {$fired} alerts dispatched for {$today}.");
    }
}
