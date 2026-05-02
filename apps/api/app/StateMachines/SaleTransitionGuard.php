<?php

declare(strict_types=1);

namespace App\StateMachines;

use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Exceptions\InvoiceNcRequiredException;
use App\Exceptions\InvalidSaleTransitionException;
use App\Models\Sale;
use App\Models\User;

/**
 * Validates business rules for Sale state transitions (§5.5, §5.6).
 *
 * This guard is called by Action classes BEFORE any DB writes occur.
 * It throws typed exceptions that the controller maps to HTTP responses.
 *
 * Rules encoded here:
 *   - Structural: is the transition defined in SaleState::TRANSITIONS?
 *   - Cancel (§5.6):
 *       * Seller/Distributor: only own sale + no payments registered
 *       * Director: any sale
 *       * Any role: if invoice exists → throw InvoiceNcRequiredException (409)
 *   - Deliver (§5.7 delegated delivery):
 *       * For delegated_delivery=true: deliverer must be the Distributor of that zone
 *       * For delegated_delivery=false: deliverer must be the original Seller or Director
 *
 * NOTE: "has payments" check uses a placeholder until Phase 7 adds the payments
 * relationship. The method is structured so Phase 7 just needs to swap the stub.
 */
final class SaleTransitionGuard
{
    public function __construct(
        private readonly Sale $sale,
        private readonly User $actor,
    ) {}

    /**
     * Assert that transitioning to $target is legal for the current actor.
     *
     * @throws InvalidSaleTransitionException
     * @throws InvoiceNcRequiredException
     */
    public function assertCanTransitionTo(SaleStatus $target): void
    {
        // 1. Structural validity
        if (! SaleState::canTransition($this->sale->status, $target)) {
            throw new InvalidSaleTransitionException(
                "Cannot transition sale from [{$this->sale->status->value}] to [{$target->value}]."
            );
        }

        // 2. Transition-specific business rules
        match ($target) {
            SaleStatus::Confirmed  => $this->assertCanConfirm(),
            SaleStatus::Delivered  => $this->assertCanDeliver(),
            SaleStatus::Cancelled  => $this->assertCanCancel(),
            default                => null,
        };
    }

    // -------------------------------------------------------------------------
    // Private rule methods
    // -------------------------------------------------------------------------

    private function assertCanConfirm(): void
    {
        // Anyone who can reach this action is allowed to confirm structurally;
        // controller policy gates confirm to Seller/Director/Distributor (own zone).
    }

    private function assertCanDeliver(): void
    {
        if ($this->sale->delegated_delivery) {
            // Delegated: deliverer must be the Distributor of the sale's zone
            if ($this->actor->role === UserRole::Distributor) {
                // Distributor is the correct actor — allow
                return;
            }
            if ($this->actor->role === UserRole::Director) {
                // Director can always force delivery
                return;
            }

            throw new InvalidSaleTransitionException(
                'Delegated delivery: only the zone Distributor or a Director may mark this sale as delivered.'
            );
        }

        // Non-delegated: Seller or Director
        if (
            $this->actor->role === UserRole::Director
            || $this->actor->id === $this->sale->seller_id
        ) {
            return;
        }

        throw new InvalidSaleTransitionException(
            'Only the selling Seller or a Director may deliver a non-delegated sale.'
        );
    }

    private function assertCanCancel(): void
    {
        // If an invoice exists, block cancellation until NC is issued (Phase 6)
        if ($this->sale->hasInvoice()) {
            throw new InvoiceNcRequiredException(
                'This sale has an invoice. Issue a credit note in Xubio before cancelling.'
            );
        }

        if ($this->actor->role === UserRole::Director) {
            // Director can cancel any sale
            return;
        }

        // Seller / Distributor: can only cancel own sale without payments
        $isOwn = $this->actor->id === $this->sale->seller_id
            || ($this->actor->role === UserRole::Distributor && $this->sale->zone
                && $this->actorIsDistributorOfZone());

        if (! $isOwn) {
            throw new InvalidSaleTransitionException(
                'You may only cancel your own sales.'
            );
        }

        if ($this->saleHasPayments()) {
            throw new InvalidSaleTransitionException(
                'Sales with registered payments can only be cancelled by a Director.',
                403
            );
        }
    }

    /**
     * Phase 7 placeholder: returns false until payments relationship exists.
     */
    private function saleHasPayments(): bool
    {
        if (method_exists($this->sale, 'payments')) {
            return $this->sale->payments()->exists();
        }

        return false;
    }

    private function actorIsDistributorOfZone(): bool
    {
        if (! $this->sale->zone_id) {
            return false;
        }

        return $this->sale->zone?->distributor_id === $this->actor->id;
    }
}
