<?php

declare(strict_types=1);

namespace App\Jobs\Xubio;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily prune of xubio_api_log rows older than 90 days.
 *
 * 90d aligns with the Xubio reconciliation window and AFIP retention buffer.
 */
class PruneXubioApiLogJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'prune-xubio-api-log:' . now('America/Argentina/Buenos_Aires')->toDateString();
    }

    public function uniqueFor(): int
    {
        return 86400;
    }

    public function handle(): void
    {
        $cutoff = now()->subDays(90);

        $deleted = DB::table('xubio_api_log')->where('created_at', '<', $cutoff)->delete();

        Log::info('PruneXubioApiLogJob: pruned', [
            'cutoff'        => $cutoff->toIso8601String(),
            'rows_deleted'  => $deleted,
        ]);
    }
}
