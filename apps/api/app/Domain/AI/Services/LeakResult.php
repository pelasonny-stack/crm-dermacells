<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

/**
 * Result object returned by LeakGuard::validate() (Phase 13 — §11.5).
 *
 * `leaked` is the only field client code needs to branch on; `details`
 * captures the offending UUIDs found so the audit row has actionable
 * forensic data.
 */
final class LeakResult
{
    /**
     * @param array<int, string> $foreignCustomerIds
     */
    public function __construct(
        public readonly bool $leaked,
        public readonly array $foreignCustomerIds = [],
        public readonly ?string $details = null,
    ) {}

    public static function clean(): self
    {
        return new self(false, [], null);
    }
}
