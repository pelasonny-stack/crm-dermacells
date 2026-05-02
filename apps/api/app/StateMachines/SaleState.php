<?php

declare(strict_types=1);

namespace App\StateMachines;

use App\Enums\SaleStatus;

/**
 * Defines the allowed state transitions for the Sale state machine (§5.5).
 *
 * Transition table:
 *   draft      → confirmed
 *   confirmed  → delivered
 *   draft      → cancelled
 *   confirmed  → cancelled
 *   delivered  → cancelled     (Director-only via policy)
 *
 * This class is intentionally pure data — it holds no side effects.
 * SaleTransitionGuard is responsible for business-rule validation
 * (caller role, invoice existence, payment presence, etc.).
 *
 * @see SaleTransitionGuard
 */
final class SaleState
{
    /**
     * Map of allowed forward transitions.
     * Key   = current status
     * Value = list of reachable statuses from that state
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        SaleStatus::Draft->value => [
            SaleStatus::Confirmed->value,
            SaleStatus::Cancelled->value,
        ],
        SaleStatus::Confirmed->value => [
            SaleStatus::Delivered->value,
            SaleStatus::Cancelled->value,
        ],
        SaleStatus::Delivered->value => [
            SaleStatus::Cancelled->value,
        ],
        SaleStatus::Cancelled->value => [],
    ];

    /**
     * Returns true when the requested transition is structurally valid
     * (ignoring business rules; those are checked in SaleTransitionGuard).
     */
    public static function canTransition(SaleStatus $from, SaleStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * Returns all statuses that the given status can transition to.
     *
     * @return list<SaleStatus>
     */
    public static function allowedTransitions(SaleStatus $from): array
    {
        return array_map(
            fn (string $v) => SaleStatus::from($v),
            self::TRANSITIONS[$from->value] ?? [],
        );
    }

    /**
     * Whether 'cancelled' is a terminal state (always true — sanity helper).
     */
    public static function isTerminal(SaleStatus $status): bool
    {
        return $status === SaleStatus::Cancelled || $status === SaleStatus::Delivered;
    }
}
