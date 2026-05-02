<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\ScheduledAction;
use App\Models\User;

/**
 * Creates a scheduled action for a customer (§3.8).
 *
 * Any role with visibility over the customer can create a scheduled action.
 * The RLS policy on scheduled_actions inherits from customers, so the DB
 * layer enforces that only visible customers can receive actions.
 *
 * No transaction is needed here — this is a single-row insert. The Eloquent
 * create call is atomic at the DB level.
 */
class CreateScheduledActionAction
{
    public function execute(Customer $customer, User $createdBy, string $scheduledDate, string $note): ScheduledAction
    {
        return ScheduledAction::create([
            'customer_id'    => $customer->id,
            'created_by'     => $createdBy->id,
            'scheduled_date' => $scheduledDate,
            'note'           => $note,
            'is_resolved'    => false,
        ]);
    }
}
