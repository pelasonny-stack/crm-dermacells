<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stock movement event types matching the Postgres ENUM stock_movement_type.
 *
 * Each value maps exactly to the DB enum string so Eloquent's native enum
 * cast round-trips cleanly via the StockMovement model.
 */
enum StockMovementType: string
{
    case Import            = 'import';
    case DispatchToDist    = 'dispatch_to_dist';
    case DispatchToSeller  = 'dispatch_to_seller';
    case Redistribution    = 'redistribution';
    case SaleReserve       = 'sale_reserve';
    case SaleDeliver       = 'sale_deliver';
    case SaleCancel        = 'sale_cancel';
    case ReturnPartial     = 'return_partial';

    /**
     * Human-readable Spanish label for Filament display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Import           => 'Importación',
            self::DispatchToDist   => 'Despacho a Distribuidor',
            self::DispatchToSeller => 'Despacho directo a Vendedor',
            self::Redistribution   => 'Redistribución',
            self::SaleReserve      => 'Reserva de venta',
            self::SaleDeliver      => 'Entrega de venta',
            self::SaleCancel       => 'Cancelación de venta',
            self::ReturnPartial    => 'Devolución parcial',
        };
    }

    /**
     * Returns true for movements that increase stock at a destination entity.
     */
    public function isInbound(): bool
    {
        return in_array($this, [
            self::Import,
            self::Redistribution,
            self::SaleCancel,
            self::ReturnPartial,
        ], true);
    }

    /**
     * Returns true for movements triggered by Phase 5 sale state transitions.
     */
    public function isSaleRelated(): bool
    {
        return in_array($this, [
            self::SaleReserve,
            self::SaleDeliver,
            self::SaleCancel,
        ], true);
    }
}
