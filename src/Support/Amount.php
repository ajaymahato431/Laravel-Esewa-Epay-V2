<?php

namespace AjayMahato\Esewa\Support;

use AjayMahato\Esewa\Exceptions\EsewaException;

/**
 * Money handling for eSewa amounts.
 *
 * eSewa amounts are rupees with optional paisa. Every amount that crosses the
 * wire or reaches the database goes through here so it is always a plain
 * two-decimal string - never a float, which cannot represent 0.1 exactly and
 * cannot be compared with ===.
 */
final class Amount
{
    /** Rupees are quoted to paisa - two decimal places. */
    public const SCALE = 2;

    /**
     * Normalise any developer-supplied or gateway-supplied amount to "1234.50".
     *
     * Accepts integers, floats and strings, and tolerates the formatting eSewa
     * and humans actually produce: thousands separators, a currency prefix and
     * surrounding whitespace.
     *
     * @throws EsewaException when the value is not a usable amount
     */
    public static function normalize(mixed $value): string
    {
        if ($value === null || $value === '') {
            throw new EsewaException('Amount is required but was empty.');
        }

        if (is_bool($value)) {
            throw new EsewaException('Amount must be numeric, boolean given.');
        }

        if (is_int($value)) {
            return number_format($value, self::SCALE, '.', '');
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new EsewaException('Amount must be a finite number.');
            }

            return number_format($value, self::SCALE, '.', '');
        }

        if (! is_string($value)) {
            throw new EsewaException('Amount must be an int, float or string, '.get_debug_type($value).' given.');
        }

        // "Rs. 1,234.50" and " 1 234.50 " both mean the same thing.
        $cleaned = preg_replace('/[\s,_]|(?:rs\.?|npr|रू)/iu', '', trim($value)) ?? '';

        if ($cleaned === '' || ! is_numeric($cleaned)) {
            throw new EsewaException("Amount \"{$value}\" is not numeric.");
        }

        return number_format((float) $cleaned, self::SCALE, '.', '');
    }

    /**
     * Compare two amounts for equality regardless of how each was formatted.
     *
     * "1000", 1000, 1000.0 and "1,000.00" are all the same amount.
     */
    public static function equals(mixed $a, mixed $b): bool
    {
        try {
            return self::normalize($a) === self::normalize($b);
        } catch (EsewaException) {
            return false;
        }
    }

    /**
     * Normalise without throwing. Returns null when the value is unusable.
     */
    public static function tryNormalize(mixed $value): ?string
    {
        try {
            return self::normalize($value);
        } catch (EsewaException) {
            return null;
        }
    }

    /**
     * Sum a list of amounts, returning a normalised total.
     *
     * Uses integer paisa internally so repeated addition cannot drift.
     */
    public static function sum(mixed ...$amounts): string
    {
        $paisa = 0;

        foreach ($amounts as $amount) {
            $paisa += (int) round(((float) self::normalize($amount)) * 100);
        }

        return number_format($paisa / 100, self::SCALE, '.', '');
    }

    /**
     * True when the amount is greater than zero.
     */
    public static function isPositive(mixed $value): bool
    {
        $normalized = self::tryNormalize($value);

        return $normalized !== null && (float) $normalized > 0;
    }
}
