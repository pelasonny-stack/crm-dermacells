<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an Argentine CUIT using the modulo-11 checksum algorithm.
 *
 * CUIT format: XX-XXXXXXXX-X (11 digits, with or without hyphens)
 *
 * ALGORITHM (§3.9 requirement)
 * =============================
 * Multiply each of the first 10 digits by the sequence [5, 4, 3, 2, 7, 6, 5, 4, 3, 2]
 * Sum the products. Subtract the remainder of (sum % 11) from 11.
 * If the result is 11 → digit is 0; if result is 10 → CUIT is invalid; otherwise
 * the result must equal the 11th (verifier) digit.
 *
 * CUIL/CDI numbers follow the same modulo-11 algorithm; this rule accepts any
 * valid 11-digit Argentine tax identifier.
 *
 * The rule normalises the input by stripping hyphens before validation, so both
 * "20-12345678-9" and "20123456789" are accepted when the checksum is valid.
 */
class CuitFormat implements ValidationRule
{
    /** @var array<int, int> Multiplier sequence for positions 0–9. */
    private const MULTIPLIERS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail('CUIT inválido (debe ser 11 dígitos con checksum válido).');
            return;
        }

        // Strip hyphens to normalise "20-12345678-9" → "20123456789"
        $digits = str_replace('-', '', (string) $value);

        if (! ctype_digit($digits) || strlen($digits) !== 11) {
            $fail('CUIT inválido (debe ser 11 dígitos con checksum válido).');
            return;
        }

        if (! $this->isValidChecksum($digits)) {
            $fail('CUIT inválido (debe ser 11 dígitos con checksum válido).');
        }
    }

    private function isValidChecksum(string $digits): bool
    {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $digits[$i] * self::MULTIPLIERS[$i];
        }

        $remainder = $sum % 11;
        $check     = 11 - $remainder;

        // 11 → verifier digit is 0
        if ($check === 11) {
            $check = 0;
        }

        // 10 → invalid CUIT (no valid verifier digit exists)
        if ($check === 10) {
            return false;
        }

        return $check === (int) $digits[10];
    }
}
