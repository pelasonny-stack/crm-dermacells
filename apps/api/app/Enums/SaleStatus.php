<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sale lifecycle states (§5.5).
 *
 * State machine:
 *   draft → confirmed → delivered
 *   draft | confirmed | delivered → cancelled
 *
 * Allowed transitions are enforced by SaleTransitionGuard, not this enum.
 * The enum itself just provides type safety and string mapping for Eloquent.
 */
enum SaleStatus: string
{
    case Draft     = 'draft';
    case Confirmed = 'confirmed';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * Human-readable label for Filament badges and API responses.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Borrador',
            self::Confirmed => 'Confirmada',
            self::Delivered => 'Entregada',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * Filament badge color for the state visualizer.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft     => 'gray',
            self::Confirmed => 'warning',
            self::Delivered => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Returns true when this status represents an active (not terminal) state.
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Draft, self::Confirmed => true,
            self::Delivered, self::Cancelled => false,
        };
    }
}
