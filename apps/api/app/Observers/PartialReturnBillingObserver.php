<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\PartialReturnStatus;
use App\Events\PartialReturnAwaitingCreditNote;
use App\Models\PartialReturn;

/**
 * Phase 6 observer that watches PartialReturn updates and fires the
 * PartialReturnAwaitingCreditNote event whenever a return transitions
 * INTO the AwaitingCreditNote state.
 *
 * This keeps Phase 5's ConfirmPartialReturnAction unchanged: we react to
 * the persistence-layer transition rather than modifying the action.
 *
 * Wired in AppServiceProvider::boot().
 */
class PartialReturnBillingObserver
{
    public function updated(PartialReturn $return): void
    {
        if (! $return->wasChanged('status')) {
            return;
        }

        $newStatus = $return->status;
        $oldStatus = $return->getOriginal('status');

        // Cast original (it can be the enum or the raw string depending on cast lifecycle)
        if (is_string($oldStatus)) {
            $oldStatus = PartialReturnStatus::tryFrom($oldStatus);
        }

        if ($newStatus === PartialReturnStatus::AwaitingCreditNote
            && $oldStatus !== PartialReturnStatus::AwaitingCreditNote
        ) {
            PartialReturnAwaitingCreditNote::dispatch($return->fresh());
        }
    }
}
