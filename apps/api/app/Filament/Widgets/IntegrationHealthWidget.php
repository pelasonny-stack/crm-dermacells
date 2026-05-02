<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * IntegrationHealthWidget — compact OK/FAIL status per integration.
 *
 * Displayed on the Director dashboard as a quick visual summary.
 * Full details (last call timestamp, error rate) are on IntegrationStatus page.
 *
 * Statuses are cached for 2 minutes to avoid DB pressure on dashboard loads.
 * The cache is shared with IntegrationStatus page (same key).
 */
class IntegrationHealthWidget extends Widget
{
    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '120s';

    protected static string $view = 'filament.widgets.integration-health';

    /** @var array<string, mixed> */
    public array $statuses = [];

    public static function canView(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public function mount(): void
    {
        $this->statuses = Cache::remember(
            'integration_health_widget',
            120,
            fn () => $this->buildStatuses(),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildStatuses(): array
    {
        return [
            'Xubio'     => $this->checkXubio(),
            'BCRA'      => $this->checkBcra(),
            'IA'        => $this->checkAi(),
            'WhatsApp'  => $this->checkWhatsapp(),
            'FCM'       => $this->checkFcm(),
        ];
    }

    /** @return array<string, mixed> */
    private function checkXubio(): array
    {
        try {
            $ok = DB::table('xubio_api_log')
                ->where('status_code', '>=', 200)
                ->where('status_code', '<', 300)
                ->where('created_at', '>=', now()->subHours(24))
                ->exists();

            return ['ok' => $ok, 'label' => $ok ? 'OK' : 'SIN EXITO 24h'];
        } catch (\Throwable) {
            return ['ok' => null, 'label' => 'N/A'];
        }
    }

    /** @return array<string, mixed> */
    private function checkBcra(): array
    {
        try {
            $ok = DB::table('exchange_rates')
                ->where('source', 'api_bna')
                ->where('rate_date', '>=', now()->subDays(2)->toDateString())
                ->exists();

            return ['ok' => $ok, 'label' => $ok ? 'OK' : 'SIN TC RECIENTE'];
        } catch (\Throwable) {
            return ['ok' => null, 'label' => 'N/A'];
        }
    }

    /** @return array<string, mixed> */
    private function checkAi(): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('ai_settings')) {
                return ['ok' => null, 'label' => 'N/A (Phase 13)'];
            }

            $active = (bool) DB::table('ai_settings')->value('is_active');

            return ['ok' => $active, 'label' => $active ? 'Activo' : 'Desactivado'];
        } catch (\Throwable) {
            return ['ok' => null, 'label' => 'N/A'];
        }
    }

    /** @return array<string, mixed> */
    private function checkWhatsapp(): array
    {
        try {
            $ok = DB::table('whatsapp_messages')
                ->where('created_at', '>=', now()->subDays(7))
                ->exists();

            return ['ok' => $ok, 'label' => $ok ? 'OK' : 'SIN MENSAJES 7d'];
        } catch (\Throwable) {
            return ['ok' => null, 'label' => 'N/A (Phase 12)'];
        }
    }

    /** @return array<string, mixed> */
    private function checkFcm(): array
    {
        try {
            $ok = DB::table('alerts')
                ->where('delivered', true)
                ->where('delivered_at', '>=', now()->subDays(7))
                ->exists();

            return ['ok' => $ok, 'label' => $ok ? 'OK' : 'SIN PUSH 7d'];
        } catch (\Throwable) {
            return ['ok' => null, 'label' => 'N/A'];
        }
    }
}
