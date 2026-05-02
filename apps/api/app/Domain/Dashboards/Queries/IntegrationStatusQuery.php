<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Queries;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Extracts integration health for the Director system dashboard section.
 *
 * Wraps the same snapshot logic used by IntegrationStatus Filament page,
 * but returns a plain array keyed by integration name.
 *
 * Cached for 2 minutes (matches IntegrationStatus page TTL) so that
 * parallel dashboard loads don't hammer xubio_api_log.
 */
final class IntegrationStatusQuery
{
    /** @return array<string, array<string, mixed>> */
    public function get(): array
    {
        return Cache::remember('integration_status_snapshot', 120, function () {
            return [
                'xubio'    => $this->xubio(),
                'bcra'     => $this->bcra(),
                'ai'       => $this->ai(),
                'whatsapp' => $this->whatsapp(),
                'fcm'      => $this->fcm(),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function xubio(): array
    {
        if (! $this->tableExists('xubio_api_log')) {
            return ['healthy' => null, 'detail' => 'xubio_api_log not found'];
        }

        $last = DB::table('xubio_api_log')
            ->where('response_status', '>=', 200)
            ->where('response_status', '<', 300)
            ->orderByDesc('created_at')
            ->first(['created_at', 'endpoint', 'response_status']);

        $errors = (int) DB::table('xubio_api_log')
            ->where('created_at', '>=', now()->subDay())
            ->where(fn ($q) => $q->where('response_status', '<', 200)->orWhere('response_status', '>=', 500))
            ->count();

        $total = (int) DB::table('xubio_api_log')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $errorRate = $total > 0 ? round(($errors / $total) * 100, 1) : 0.0;

        return [
            'healthy'        => $last !== null && $errorRate < 20.0,
            'last_success'   => $last?->created_at,
            'error_rate_pct' => $errorRate,
        ];
    }

    /** @return array<string, mixed> */
    private function bcra(): array
    {
        $last = DB::table('exchange_rates')
            ->where('source', 'api_bna')
            ->orderByDesc('rate_date')
            ->first(['rate_date', 'rate_ars_per_usd']);

        return [
            'healthy'    => $last !== null && $last->rate_date >= now()->toDateString(),
            'last_rate'  => $last?->rate_ars_per_usd,
            'rate_date'  => $last?->rate_date,
        ];
    }

    /** @return array<string, mixed> */
    private function ai(): array
    {
        if (! $this->tableExists('ai_settings')) {
            return ['healthy' => null, 'detail' => 'ai_settings not found'];
        }

        $settings = DB::table('ai_settings')->first(['is_active', 'provider', 'model']);

        return [
            'healthy'  => $settings !== null,
            'active'   => (bool) ($settings?->is_active ?? false),
            'provider' => $settings?->provider,
            'model'    => $settings?->model,
        ];
    }

    /** @return array<string, mixed> */
    private function whatsapp(): array
    {
        if (! $this->tableExists('whatsapp_messages')) {
            return ['healthy' => null, 'detail' => 'whatsapp_messages not found'];
        }

        $last = DB::table('whatsapp_messages')
            ->orderByDesc('created_at')
            ->first(['created_at', 'direction']);

        return [
            'healthy'      => $last !== null,
            'last_message' => $last?->created_at,
        ];
    }

    /** @return array<string, mixed> */
    private function fcm(): array
    {
        if (! $this->tableExists('alerts')) {
            return ['healthy' => null, 'detail' => 'alerts table not found'];
        }

        $last = DB::table('alerts')
            ->where('delivered', true)
            ->orderByDesc('delivered_at')
            ->first(['delivered_at', 'alert_type']);

        return [
            'healthy'      => $last !== null,
            'last_delivery' => $last?->delivered_at,
        ];
    }

    private function tableExists(string $table): bool
    {
        try {
            DB::table($table)->limit(0)->get();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
