<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * IVA condition for billing entities (razones sociales) — §3.5.
 *
 * Determines the AFIP comprobante type when issuing via Xubio:
 *   responsable_inscripto → Factura A
 *   monotributista        → Factura C
 *   consumidor_final      → Factura B
 *   exento                → Factura B or C depending on Xubio context
 *
 * Values mirror the Postgres iva_condition_enum type defined in migration
 * 2026_05_04_000002_create_customer_billing_entities_table.php.
 */
enum IvaCondition: string
{
    case ResponsableInscripto = 'responsable_inscripto';
    case Monotributista       = 'monotributista';
    case ConsumidorFinal      = 'consumidor_final';
    case Exento               = 'exento';

    /** Human-readable label for display in Filament selects. */
    public function label(): string
    {
        return match ($this) {
            self::ResponsableInscripto => 'Responsable Inscripto',
            self::Monotributista       => 'Monotributista',
            self::ConsumidorFinal      => 'Consumidor Final',
            self::Exento               => 'Exento',
        };
    }
}
