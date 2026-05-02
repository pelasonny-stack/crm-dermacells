<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a dispatch or redistribution action is attempted but the source
 * entity does not have enough available stock.
 *
 * The StockController maps this to an RFC 7807 422 response with business code
 * STOCK_INSUFFICIENT so the client can surface an actionable message to the user.
 */
final class StockInsufficientException extends RuntimeException
{
    public function __construct(
        public readonly string $productId,
        public readonly int $requested,
        public readonly int $available,
        public readonly string $sourceType = 'central',
        public readonly ?string $sourceId = null,
    ) {
        parent::__construct(
            sprintf(
                'Insufficient stock for product %s: requested %d boxes but only %d available at %s.',
                $productId,
                $requested,
                $available,
                $sourceType . ($sourceId !== null ? ":{$sourceId}" : ''),
            )
        );
    }

    public function getApiCode(): string
    {
        return 'STOCK_INSUFFICIENT';
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getRequested(): int
    {
        return $this->requested;
    }

    public function getAvailable(): int
    {
        return $this->available;
    }
}
