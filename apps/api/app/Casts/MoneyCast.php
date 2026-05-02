<?php

declare(strict_types=1);

namespace App\Casts;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Currency;
use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Compound Eloquent cast: two DB columns (amount NUMERIC(18,4) + currency CHAR(3))
 * are hydrated into an immutable {@see \Brick\Money\Money} value object.
 *
 * Usage on a model:
 *
 * ```php
 * protected function casts(): array
 * {
 *     return [
 *         'base_price' => MoneyCast::class . ':base_price_amount,base_price_currency',
 *     ];
 * }
 * ```
 *
 * When you read `$product->base_price` you get a `Money` instance.
 * When you set `$product->base_price = Money::of('750.00', 'USD')` Eloquent
 * receives an array with both column values and persists them individually.
 *
 * IMPORTANT: brick/money never converts between currencies automatically.
 * Adding `Money::of(100, 'ARS')` to `Money::of(100, 'USD')` throws
 * `MoneyMismatchException`. This is intentional — per §7.4 dual balances
 * must stay in their original currency until explicitly queried for
 * USD-equivalent commission calculations (done at query time, not stored).
 *
 * @see https://github.com/brick/money
 * @see https://laravel.com/docs/12.x/eloquent-mutators#custom-casts
 *
 * @implements CastsAttributes<Money|null, Money|null>
 */
class MoneyCast implements CastsAttributes
{
    /**
     * Column holding the amount (NUMERIC 18,4).
     */
    private readonly string $amountColumn;

    /**
     * Column holding the ISO 4217 currency code (CHAR 3).
     */
    private readonly string $currencyColumn;

    /**
     * The cast key is the logical attribute name (e.g. 'base_price').
     * The two physical columns are derived by convention: {key}_amount and
     * {key}_currency, or supplied explicitly as constructor parameters.
     *
     * @param string $amountColumn   e.g. 'base_price_amount'
     * @param string $currencyColumn e.g. 'base_price_currency'
     */
    public function __construct(string $amountColumn = '', string $currencyColumn = '')
    {
        $this->amountColumn   = $amountColumn;
        $this->currencyColumn = $currencyColumn;
    }

    /**
     * Hydrate the Money value object from the model's raw attribute array.
     *
     * Returns null when either column is null (avoids constructing a Money
     * object with incomplete data).
     *
     * @param  array<string, mixed>  $attributes  All raw DB column values.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $amount   = $attributes[$this->amountColumn]   ?? null;
        $currency = $attributes[$this->currencyColumn] ?? null;

        if ($amount === null || $currency === null) {
            return null;
        }

        return Money::of(
            BigDecimal::of((string) $amount),
            Currency::of((string) $currency),
            null,
            RoundingMode::UNNECESSARY,
        );
    }

    /**
     * Decompose a Money value object into the two physical columns.
     *
     * Returns an array keyed by the amount and currency column names so
     * Eloquent can persist both columns in a single statement.
     *
     * @param  Money|array<string, mixed>|null  $value
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [
                $this->amountColumn   => null,
                $this->currencyColumn => null,
            ];
        }

        if (is_array($value)) {
            // Allow setting via array with 'amount' and 'currency' keys,
            // useful in factory definitions and test helpers.
            $value = Money::of(
                (string) ($value['amount'] ?? throw new InvalidArgumentException('MoneyCast array value must have an "amount" key')),
                (string) ($value['currency'] ?? throw new InvalidArgumentException('MoneyCast array value must have a "currency" key')),
            );
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(
                sprintf('MoneyCast expects a Brick\\Money\\Money instance, got %s.', get_debug_type($value))
            );
        }

        return [
            $this->amountColumn   => (string) $value->getAmount(),
            $this->currencyColumn => $value->getCurrency()->getCurrencyCode(),
        ];
    }
}
