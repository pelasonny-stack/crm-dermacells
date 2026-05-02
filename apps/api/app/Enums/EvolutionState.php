<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Purchase-evolution states for a (customer, product) pair (§10.2).
 *
 * The label() helper returns the human-readable Spanish label used in
 * Filament resource badges and notification copy.
 */
enum EvolutionState: string
{
    case FirstPurchase = 'first_purchase';
    case Increasing    = 'increasing';
    case Stable        = 'stable';
    case Decreasing    = 'decreasing';
    case Scheduled     = 'scheduled';
    case Inactive      = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::FirstPurchase => 'Primera compra',
            self::Increasing    => 'Frecuencia creciente',
            self::Stable        => 'Frecuencia estable',
            self::Decreasing    => 'Frecuencia decreciente',
            self::Scheduled     => 'En seguimiento programado',
            self::Inactive      => 'Inactivo',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FirstPurchase => 'info',
            self::Increasing    => 'success',
            self::Stable        => 'success',
            self::Decreasing    => 'warning',
            self::Scheduled     => 'gray',
            self::Inactive      => 'danger',
        };
    }
}
