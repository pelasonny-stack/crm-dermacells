<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Credit note lifecycle status (Phase 6 — §6.5).
 *
 * Same lifecycle as invoices but stored as TEXT (not enum) at the DB layer
 * because the NC variants are fewer and the spec may grow.
 */
enum CreditNoteStatus: string
{
    case Pending             = 'pending';
    case Reconciling         = 'reconciling';
    case Success             = 'success';
    case FailedManualReview  = 'failed_manual_review';

    public function label(): string
    {
        return match ($this) {
            self::Pending             => 'Pendiente',
            self::Reconciling         => 'Reconciliando',
            self::Success             => 'Emitida',
            self::FailedManualReview  => 'Revisión manual',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending             => 'gray',
            self::Reconciling         => 'warning',
            self::Success             => 'success',
            self::FailedManualReview  => 'danger',
        };
    }
}
