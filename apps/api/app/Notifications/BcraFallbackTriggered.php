<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification sent to all Directors when the BCRA API is unavailable and
 * the system falls back to the most recently available exchange rate.
 *
 * Per §16.7: "Si la API no responde y no hay TC manual para el día, el sistema
 * usa el último TC disponible en el historial e informa explícitamente al
 * usuario."
 *
 * Delivery channels:
 *   - mail : Director receives an email describing the fallback rate used.
 *   - broadcast: Reverb real-time alert on the private-director channel
 *                (Phase 14 dashboard will render this as a system alert).
 *
 * The notification is queued (ShouldQueue) so it does not block the job
 * handle() method if mail delivery is slow.
 *
 * Phase note: in Phase 14 the broadcast channel will be wired to push a
 * live alert to the Director's dashboard. For now the 'broadcast' channel
 * is listed but the BroadcastMessage is a minimal placeholder.
 */
class BcraFallbackTriggered extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $fallbackDate     The date (YYYY-MM-DD) for which the fallback rate was applied.
     * @param  string  $fallbackRate     The ARS/USD rate that was copied (string to preserve precision).
     * @param  string  $originalRateDate The date of the rate that was copied as fallback.
     * @param  string  $apiError         Human-readable description of the BCRA API failure.
     */
    public function __construct(
        public readonly string $fallbackDate,
        public readonly string $fallbackRate,
        public readonly string $originalRateDate,
        public readonly string $apiError,
    ) {
        $this->onQueue('default');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->error()
            ->subject('[CRM Dermacells] Alerta: TC de fallback aplicado para ' . $this->fallbackDate)
            ->greeting('Alerta de tipo de cambio')
            ->line(
                'La API del BCRA no estuvo disponible al intentar obtener el tipo de cambio dólar vendedor ' .
                'para el día **' . $this->fallbackDate . '**.'
            )
            ->line('**Detalle del error:** ' . $this->apiError)
            ->line(
                'El sistema aplicó automáticamente el último TC disponible en el historial: ' .
                '**ARS ' . $this->fallbackRate . ' / USD** (correspondiente al ' . $this->originalRateDate . ').'
            )
            ->line(
                'Si necesitás corregir el TC para el día de hoy, podés ingresarlo manualmente desde ' .
                'el panel de Configuración → Tipo de cambio.'
            )
            ->action('Ir al panel de configuración', url('/admin'))
            ->line('Este mensaje fue generado automáticamente por el scheduler del CRM.');
    }
}
