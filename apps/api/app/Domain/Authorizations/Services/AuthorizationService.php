<?php

declare(strict_types=1);

namespace App\Domain\Authorizations\Services;

use App\Enums\AuthorizationType;
use App\Enums\UserRole;
use App\Events\AuthorizationRequested as AuthorizationRequestedEvent;
use App\Events\AuthorizationResolved as AuthorizationResolvedEvent;
use App\Exceptions\OperationBlockedByPendingAuthorizationException;
use App\Models\AuthorizationRequest;
use App\Models\Sale;
use App\Models\User;
use App\Notifications\AuthorizationRequestedNotification;
use App\Notifications\AuthorizationResolvedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use LogicException;

/**
 * AuthorizationService — domain service for the §13 authorization flow.
 *
 * Three primary operations:
 *   1. request()         — Seller/Distributor submits a price or TC change request.
 *   2. approve()         — Director approves a pending request.
 *   3. reject()          — Director rejects a pending request with a reason.
 *   4. pendingForSale()  — boolean guard used by ConfirmSaleAction.
 *
 * Broadcasting strategy:
 *   - AuthorizationRequestedEvent → private-director channel (all Directors).
 *   - AuthorizationResolvedEvent  → private-user.{requested_by} channel.
 *
 * Both events implement ShouldBroadcastNow (zero-queue latency).
 * Notification fan-out to Directors is queued (ShouldQueue on the Notification).
 */
final class AuthorizationService
{
    /**
     * Create a new pending authorization request, broadcast to Directors, and
     * send the notification fan-out to all active Directors.
     *
     * @param  AuthorizationType  $type
     * @param  string             $currentValue   NUMERIC(18,4) as string
     * @param  string             $proposedValue  NUMERIC(18,4) as string
     * @param  string             $reason         Free-text required by §13.2
     * @param  string|null        $saleId         UUID of the linked sale (nullable)
     * @param  string|null        $valueCurrency  ISO 4217 code; NULL for TC requests
     * @return AuthorizationRequest
     */
    public function request(
        AuthorizationType $type,
        string $currentValue,
        string $proposedValue,
        string $reason,
        User $requester,
        ?string $saleId = null,
        ?string $valueCurrency = null,
    ): AuthorizationRequest {
        $authRequest = DB::transaction(function () use (
            $type, $currentValue, $proposedValue, $reason, $requester, $saleId, $valueCurrency
        ): AuthorizationRequest {
            return AuthorizationRequest::create([
                'type'           => $type,
                'sale_id'        => $saleId,
                'requested_by'   => $requester->id,
                'current_value'  => $currentValue,
                'proposed_value' => $proposedValue,
                'value_currency' => $valueCurrency,
                'reason'         => $reason,
                'status'         => 'pending',
            ]);
        });

        // Broadcast to the private-director Reverb channel (ShouldBroadcastNow)
        event(new AuthorizationRequestedEvent($authRequest));

        // Fan-out notification to all active Directors (queued)
        $directors = User::query()
            ->where('role', UserRole::Director->value)
            ->where('is_active', true)
            ->get();

        Notification::send($directors, new AuthorizationRequestedNotification($authRequest));

        return $authRequest;
    }

    /**
     * Approve a pending authorization request.
     *
     * Only the Director role may call this. Throws LogicException if the
     * request is not in 'pending' state (idempotency guard).
     *
     * @throws LogicException When request is not pending.
     */
    public function approve(AuthorizationRequest $authRequest, User $director): AuthorizationRequest
    {
        $this->assertDirector($director);

        if (! $authRequest->isPending()) {
            throw new LogicException(
                "Authorization request {$authRequest->id} is not pending (status: {$authRequest->status})."
            );
        }

        DB::transaction(function () use ($authRequest, $director): void {
            $authRequest->update([
                'status'      => 'approved',
                'resolved_by' => $director->id,
                'resolved_at' => now(),
            ]);
        });

        $authRequest->refresh();

        // Notify the requester via Reverb (private-user.{requested_by})
        event(new AuthorizationResolvedEvent($authRequest));

        // Persist notification for the requester's bell history (queued)
        $requester = User::find($authRequest->requested_by);
        if ($requester !== null) {
            $requester->notify(new AuthorizationResolvedNotification($authRequest));
        }

        return $authRequest;
    }

    /**
     * Reject a pending authorization request with a mandatory reason.
     *
     * @throws LogicException When request is not pending.
     */
    public function reject(
        AuthorizationRequest $authRequest,
        User $director,
        string $rejectionReason,
    ): AuthorizationRequest {
        $this->assertDirector($director);

        if (! $authRequest->isPending()) {
            throw new LogicException(
                "Authorization request {$authRequest->id} is not pending (status: {$authRequest->status})."
            );
        }

        DB::transaction(function () use ($authRequest, $director, $rejectionReason): void {
            $authRequest->update([
                'status'           => 'rejected',
                'resolved_by'      => $director->id,
                'resolved_at'      => now(),
                'rejection_reason' => $rejectionReason,
            ]);
        });

        $authRequest->refresh();

        // Notify the requester via Reverb (private-user.{requested_by})
        event(new AuthorizationResolvedEvent($authRequest));

        // Persist notification for the requester's bell history (queued)
        $requester = User::find($authRequest->requested_by);
        if ($requester !== null) {
            $requester->notify(new AuthorizationResolvedNotification($authRequest));
        }

        return $authRequest;
    }

    /**
     * Return true when any pending authorization request is linked to the
     * given sale. Used by ConfirmSaleAction to block confirmation per §13.2.
     */
    public function pendingForSale(Sale $sale): bool
    {
        return AuthorizationRequest::query()
            ->where('sale_id', $sale->id)
            ->where('status', 'pending')
            ->exists();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @throws \InvalidArgumentException */
    private function assertDirector(User $user): void
    {
        if (! $user->role->isDirector()) {
            throw new \InvalidArgumentException(
                "User {$user->id} is not a Director and cannot resolve authorization requests."
            );
        }
    }
}
