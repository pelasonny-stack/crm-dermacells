<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

/**
 * Value object carrying the result of CashDestinationResolver::resolveFor().
 *
 * @property-read string      $destination   'dermacells' | 'distributor'
 * @property-read string|null $distributorId UUID of the Distributor user, or null
 */
final class CashDestinationDecision
{
    private function __construct(
        public readonly string $destination,
        public readonly ?string $distributorId,
    ) {}

    public static function dermacells(): self
    {
        return new self('dermacells', null);
    }

    public static function distributor(string $distributorId): self
    {
        return new self('distributor', $distributorId);
    }

    public function isForDistributor(): bool
    {
        return $this->destination === 'distributor';
    }

    public function isForDermacells(): bool
    {
        return $this->destination === 'dermacells';
    }
}
