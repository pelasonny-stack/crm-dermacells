<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PartialReturn;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a PartialReturn transitions to AwaitingCreditNote status,
 * meaning the parent sale already has an invoice and a Xubio NC must
 * be emitted to complete the return.
 *
 * Listener: OnPartialReturnAwaitingCreditNote dispatches IssueXubioCreditNoteJob.
 */
class PartialReturnAwaitingCreditNote
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PartialReturn $partialReturn,
    ) {
    }
}
