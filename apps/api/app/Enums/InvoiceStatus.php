<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Invoice lifecycle status (Phase 6 — §6).
 *
 * Transitions:
 *   pending           → reconciling | success | failed_manual_review
 *   reconciling       → success | failed_manual_review
 *   success           → (terminal)
 *   failed_manual_review → success (Director can manually link a Xubio id)
 */
enum InvoiceStatus: string
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

    public function isTerminal(): bool
    {
        return $this === self::Success;
    }
}
