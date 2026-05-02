<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalises phone numbers to a consistent E.164-without-plus format.
 *
 * RATIONALE
 * =========
 * Meta Cloud API delivers the sender phone in the `from` field of the
 * webhook payload already in E.164 format (e.g. "5491112345678") but
 * without the leading "+". Customer.phone values entered by Sellers may
 * contain "+", spaces, or hyphens. This utility normalises both sides to
 * the same representation so `===` comparison is safe.
 *
 * NORMALISATION RULES
 * ===================
 * 1. Strip all whitespace characters.
 * 2. Strip all hyphens and parentheses.
 * 3. Strip a leading "+" if present.
 * 4. The result is a digit-only string: "5491112345678".
 *
 * NOTE: Full libphonenumber validation (country code correctness, length
 * per region) is out of scope for MVP. The normalisation is intentionally
 * simple and reversible.
 *
 * USAGE
 * =====
 * ```php
 * $normalised = PhoneNormalizer::normalize('+54 9 11 1234-5678');
 * // => "5491112345678"
 *
 * $areEqual = PhoneNormalizer::equal(
 *     '+54 9 11 1234-5678',
 *     '5491112345678'
 * );
 * // => true
 * ```
 */
final class PhoneNormalizer
{
    /**
     * Normalise a phone number string to a digit-only E.164 representation
     * (no leading "+").
     *
     * Returns an empty string if the input contains no digits at all.
     */
    public static function normalize(string $phone): string
    {
        // Remove whitespace, hyphens, parentheses, dots.
        $stripped = preg_replace('/[\s\-().]+/', '', $phone) ?? '';

        // Remove a single leading "+" if present.
        if (str_starts_with($stripped, '+')) {
            $stripped = substr($stripped, 1);
        }

        // Keep only digits to handle any remaining non-numeric characters.
        return preg_replace('/\D/', '', $stripped) ?? '';
    }

    /**
     * Return true if two phone strings normalise to the same digit sequence.
     */
    public static function equal(string $a, string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }
}
