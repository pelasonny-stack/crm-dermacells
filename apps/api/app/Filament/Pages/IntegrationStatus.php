<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\UserRole;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * IntegrationStatus — health check panel for external integrations (§14.3 Sistema).
 *
 * Shows real-time status for:
 *   - Xubio (facturacion): last successful API call + error rate (from xubio_api_log)
 *   - BCRA (tipo de cambio): last exchange_rate row with source='api_bna'
 *   - AI (OpenAI/Anthropic): ai_settings active flag + last ai_usage record
 *   - WhatsApp (Meta Cloud API): last whatsapp_messages row
 *   - FCM (Firebase): health based on last unmatched/matched webhook
 *
 * Data is read from existing tables (no new schema). Cache TTL 2min to avoid
 * hammering the DB on every page refresh.
 *
 * This page is deliberately read-only; remediation links direct to the relevant
 * Filament Resource (ExchangeRates, etc.) when appropriate.
 */
class IntegrationStatus extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationLabel = 'Estado Integraciones';

    protected static ?string $title = 'Estado de Integraciones Externas';

    protected static ?string $slug = 'integration-status';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.integration-status';

    /** @var array<string, array<string, mixed>> */
    public array $integrations = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public function mount(): void
    {
        $this->integrations = Cache::remember(
            'integration_status_snapshot',
            120, // 2 minutes
            fn () => $this->buildSnapshot(),
        );
    }

    public function refresh(): void
    {
        Cache::forget('integration_status_snapshot');
        $this->integrations = $this->buildSnapshot();
        Cache::put('integration_status_snapshot', $this->integrations, 120);
    }

    // -------------------------------------------------------------------------
    // Snapshot builders
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildSnapshot(): array
    {
        return [
            'xubio'     => $this->xubioStatus(),
            'bcra'      => $this->bcraStatus(),
            'ai'        => $this->aiStatus(),
            'whatsapp'  => $this->whatsappStatus(),
            'fcm'       => $this->fcmStatus(),
        ];
    }

    /** @return array<string, mixed> */
    private function xubioStatus(): array
    {
        $hasTable = $this->tableExists('xubio_api_log');

        if (! $hasTable) {
            return $this->unknown('Xubio', 'Tabla xubio_api_log no encontrada');
        }

        $last = DB::table('xubio_api_log')
            ->where('status_code', '>=', 200)
            ->where('status_code', '<', 300)
            ->orderByDesc('created_at')
            ->first(['created_at', 'endpoint', 'status_code']);

        $errorRate = $this->xubioErrorRate();

        return [
            'name'           => 'Xubio (Facturacion)',
            'icon'           => 'heroicon-o-document-text',
            'healthy'        => $last !== null && $errorRate < 20.0,
            'last_success'   => $last?->created_at,
            'last_endpoint'  => $last?->endpoint,
            'error_rate_pct' => $errorRate,
            'detail'         => $last
                ? "Ultimo exito: {$last->created_at} — Tasa error 24h: {$errorRate}%"
                : 'Sin llamadas exitosas registradas',
        ];
    }

    /** @return array<string, mixed> */
    private function bcraStatus(): array
    {
        $last = DB::table('exchange_rates')
            ->where('source', 'api_bna')
            ->orderByDesc('rate_date')
            ->first(['rate_date', 'rate_ars_per_usd', 'created_at']);

        $today = now()->toDateString();

        return [
            'name'         => 'BCRA / BNA (Tipo de Cambio)',
            'icon'         => 'heroicon-o-currency-dollar',
            'healthy'      => $last !== null && $last->rate_date >= $today,
            'last_success' => $last?->created_at,
            'detail'       => $last
                ? "TC {$last->rate_date}: ARS {$last->rate_ars_per_usd}"
                : 'Sin TC de API registrado — verificar FetchBCRAExchangeRateJob',
        ];
    }

    /** @return array<string, mixed> */
    private function aiStatus(): array
    {
        $hasAiSettings = $this->tableExists('ai_settings');

        if (! $hasAiSettings) {
            return $this->unknown('Asistente IA', 'Tabla ai_settings no encontrada (Phase 13)');
        }

        $settings = DB::table('ai_settings')->first(['global_enabled', 'provider', 'model', 'updated_at']);
        $lastUsage = null;

        if ($this->tableExists('ai_usage')) {
            $lastUsage = DB::table('ai_usage')
                ->orderByDesc('created_at')
                ->first(['created_at', 'total_tokens', 'cost_estimate_usd as cost_usd']);
        }

        $isActive = (bool) ($settings?->global_enabled ?? false);

        return [
            'name'         => 'Asistente IA',
            'icon'         => 'heroicon-o-cpu-chip',
            'healthy'      => $settings !== null,
            'last_success' => $lastUsage?->created_at,
            'detail'       => $settings
                ? sprintf(
                    '%s — %s — %s',
                    $isActive ? 'Activo' : 'Desactivado',
                    $settings->provider ?? 'N/A',
                    $settings->model ?? 'N/A',
                )
                : 'No configurado',
        ];
    }

    /** @return array<string, mixed> */
    private function whatsappStatus(): array
    {
        if (! $this->tableExists('whatsapp_messages')) {
            return $this->unknown('WhatsApp Business', 'Tabla whatsapp_messages no encontrada (Phase 12)');
        }

        $last = DB::table('whatsapp_messages')
            ->orderByDesc('sent_at')
            ->first(['sent_at as created_at', 'direction']);

        return [
            'name'         => 'WhatsApp Business (Meta)',
            'icon'         => 'heroicon-o-chat-bubble-left-ellipsis',
            'healthy'      => $last !== null,
            'last_success' => $last?->created_at,
            'detail'       => $last
                ? "Ultimo mensaje ({$last->direction}): {$last->created_at}"
                : 'Sin mensajes registrados — verificar webhook Meta',
        ];
    }

    /** @return array<string, mixed> */
    private function fcmStatus(): array
    {
        // FCM health is inferred from whether alerts have been delivered recently.
        $hasAlerts = $this->tableExists('alerts');

        if (! $hasAlerts) {
            return $this->unknown('FCM (Firebase)', 'Tabla alerts no encontrada');
        }

        $last = DB::table('alerts')
            ->where('delivered', true)
            ->orderByDesc('delivered_at')
            ->first(['delivered_at', 'alert_type']);

        return [
            'name'         => 'FCM (Firebase Push)',
            'icon'         => 'heroicon-o-bell',
            'healthy'      => $last !== null,
            'last_success' => $last?->delivered_at,
            'detail'       => $last
                ? "Ultima notificacion entregada: {$last->delivered_at} ({$last->alert_type})"
                : 'Sin notificaciones push entregadas registradas',
        ];
    }

    /** @return array<string, mixed> */
    private function unknown(string $name, string $reason): array
    {
        return [
            'name'         => $name,
            'icon'         => 'heroicon-o-question-mark-circle',
            'healthy'      => null,
            'last_success' => null,
            'detail'       => $reason,
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

    private function xubioErrorRate(): float
    {
        $total = (int) DB::table('xubio_api_log')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($total === 0) {
            return 0.0;
        }

        $errors = (int) DB::table('xubio_api_log')
            ->where('created_at', '>=', now()->subDay())
            ->where(fn ($q) => $q->where('status_code', '<', 200)->orWhere('status_code', '>=', 500))
            ->count();

        return round(($errors / $total) * 100, 1);
    }
}
