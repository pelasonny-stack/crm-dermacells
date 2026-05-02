<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PartialReturnAwaitingCreditNote;
use App\Jobs\Xubio\IssueXubioCreditNoteJob;

/**
 * Listener: when a PartialReturn enters AwaitingCreditNote status,
 * dispatch the Xubio NC emission job.
 *
 * Wired in EventServiceProvider::$listen.
 */
class OnPartialReturnAwaitingCreditNote
{
    public function handle(PartialReturnAwaitingCreditNote $event): void
    {
        IssueXubioCreditNoteJob::dispatch($event->partialReturn->id);
    }
}
