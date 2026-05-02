<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Domain\AI\UseCases\GenerateDailyDigest;
use App\Enums\UserRole;
use App\Models\AiSetting;
use App\Models\AiUserOverride;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * GenerateDailyDigestForAllSellers — fan-out job dispatched at 07:00 ART
 * to precompute the morning brief for every active seller (Phase 13 §11.2).
 *
 * Skipped completely when the AI module is globally disabled. Per-user
 * `AiUserOverride.enabled = false` rows are also skipped.
 *
 * Each per-user digest call is delegated to the GenerateDailyDigest use
 * case which writes through the same Cache::remember() key the runtime
 * GET /ai/digest/me endpoint reads — so users open their dashboard and
 * see the cached version (zero LLM cost in business hours).
 */
class GenerateDailyDigestForAllSellers implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(GenerateDailyDigest $useCase): void
    {
        $settings = AiSetting::current();
        if (! $settings->global_enabled) {
            return;
        }

        $disabledUserIds = AiUserOverride::query()
            ->where('enabled', false)
            ->pluck('user_id')
            ->all();

        $sellers = User::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Seller->value, UserRole::Distributor->value])
            ->whereNotIn('id', $disabledUserIds)
            ->cursor();

        foreach ($sellers as $seller) {
            try {
                $useCase->execute($seller);
            } catch (\Throwable $e) {
                report($e);
                // Continue to next seller — one failure must not block the batch.
            }
        }
    }
}
