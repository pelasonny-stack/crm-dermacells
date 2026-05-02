<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that sale item currencies match the sale's header currency.
 *
 * Per §5.3, all line items in a sale must be priced in the sale's currency
 * (ARS or USD). Mixing currencies within a single sale is not permitted unless
 * explicitly overridden by an authorized Director action.
 *
 * This rule is applied to the 'items' array field:
 *   'items' => ['required', 'array', new CurrencyConsistency($saleCurrency)]
 *
 * It validates the full items array, not individual elements, to produce a
 * single validation error message rather than per-item ones.
 *
 * @param string $saleCurrency  The currency declared in the sale header ('ARS'|'USD')
 */
final class CurrencyConsistency implements ValidationRule
{
    public function __construct(private readonly string $saleCurrency) {}

    /**
     * @param list<array<string,mixed>> $value  The items array
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('Sale items must be an array.');
            return;
        }

        foreach ($value as $index => $item) {
            $itemCurrency = $item['unit_price_currency'] ?? null;

            if ($itemCurrency === null) {
                // Item has no explicit currency — defaults to sale currency, valid.
                continue;
            }

            if ($itemCurrency !== $this->saleCurrency) {
                $fail(
                    "Item at index {$index} has currency [{$itemCurrency}] which does not match "
                    . "the sale currency [{$this->saleCurrency}]. "
                    . 'All line items must use the same currency as the sale header.'
                );
                return;
            }
        }
    }
}
