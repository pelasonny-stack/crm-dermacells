<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Partial return lifecycle states (§5.8).
 *
 * Transitions:
 *   pending_director_confirmation → awaiting_credit_note (if invoice exists)
 *   pending_director_confirmation → applied               (if no invoice)
 *   pending_director_confirmation → rejected
 *   awaiting_credit_note          → nc_issued             (Phase 6 job)
 *   nc_issued                     → applied               (Phase 6 job)
 */
enum PartialReturnStatus: string
{
    case PendingDirectorConfirmation = 'pending_director_confirmation';
    case AwaitingCreditNote          = 'awaiting_credit_note';
    case NcIssued                    = 'nc_issued';
    case Applied                     = 'applied';
    case Rejected                    = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingDirectorConfirmation => 'Pendiente confirmación Director',
            self::AwaitingCreditNote          => 'Esperando NC en Xubio',
            self::NcIssued                    => 'NC emitida',
            self::Applied                     => 'Aplicada',
            self::Rejected                    => 'Rechazada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingDirectorConfirmation => 'warning',
            self::AwaitingCreditNote          => 'info',
            self::NcIssued                    => 'primary',
            self::Applied                     => 'success',
            self::Rejected                    => 'danger',
        };
    }
}
