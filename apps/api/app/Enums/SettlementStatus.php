<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rendición status — §9.4
 *
 * pending   → Submitted by Distributor, awaiting Director confirmation.
 * confirmed → Director confirmed receipt; balance reduces.
 * rejected  → Director rejected; balance unchanged, reason in notes.
 */
enum SettlementStatus: string
{
    case Pending   = 'pending';
    case Confirmed = 'confirmed';
    case Rejected  = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pendiente',
            self::Confirmed => 'Confirmada',
            self::Rejected  => 'Rechazada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending   => 'warning',
            self::Confirmed => 'success',
            self::Rejected  => 'danger',
        };
    }
}
