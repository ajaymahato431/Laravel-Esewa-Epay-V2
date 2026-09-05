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
            return self::quantize((string) $value);
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

        return self::quantize($cleaned);
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
            $paisa += self::toPaisa(self::normalize($amount));
        }

        return self::fromPaisa($paisa);
    }

    /**
     * True when the amount is greater than zero.
     */
    public static function isPositive(mixed $value): bool
    {
        $normalized = self::tryNormalize($value);

        return $normalized !== null
            && ! str_starts_with($normalized, '-')
            && $normalized !== self::fromPaisa(0);
    }

    /**
     * Round a numeric string to paisa by working on its digits.
     *
     * A PHP float carries about 15 significant digits, so casting a string to
     * one first turns "1234567890123456.78" into "...456.75". Rounding the
     * digits directly keeps every amount exact no matter how large, and rounds
     * halves away from zero the way money is quoted.
     */
    private static function quantize(string $number): string
    {
        [$sign, $integer, $fraction] = self::split($number);

        $paisa = str_pad(substr($fraction, 0, self::SCALE), self::SCALE, '0');

        if (($fraction[self::SCALE] ?? '0') >= '5') {
            $carried = self::increment($integer.$paisa);
            $integer = substr($carried, 0, -self::SCALE);
            $paisa = substr($carried, -self::SCALE);
        }

        $integer = ltrim($integer, '0');

        if ($integer === '') {
            $integer = '0';
        }

        // "-0.00" is not an amount anybody means to send.
        if ($sign === '-' && $integer === '0' && $paisa === str_repeat('0', self::SCALE)) {
            $sign = '';
        }

        return $sign.$integer.'.'.$paisa;
    }

    /**
     * Break a numeric string into sign, integer digits and fraction digits,
     * flattening exponent notation (which is_numeric() also accepts) by moving
     * the decimal point rather than by evaluating it.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function split(string $number): array
    {
        preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $number, $matches);

        $sign = ($matches[1] ?? '') === '-' ? '-' : '';
        $integer = $matches[2] ?? '';
        $fraction = $matches[3] ?? '';
        $exponent = (int) ($matches[4] ?? '0');

        if ($exponent > 0) {
            $fraction = str_pad($fraction, $exponent, '0');
            $integer .= substr($fraction, 0, $exponent);
            $fraction = substr($fraction, $exponent);
        } elseif ($exponent < 0) {
            $integer = str_pad($integer, -$exponent, '0', STR_PAD_LEFT);
            $fraction = substr($integer, $exponent).$fraction;
            $integer = substr($integer, 0, $exponent);
        }

        return [$sign, $integer, $fraction];
    }

    /**
     * Add one to a string of digits, growing it when every digit is a nine.
     */
    private static function increment(string $digits): string
    {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);

                return $digits;
            }

            $digits[$i] = '0';
        }

        return '1'.$digits;
    }

    /**
     * Read a normalised amount as whole paisa.
     */
    private static function toPaisa(string $normalized): int
    {
        $paisa = (int) str_replace(['-', '.'], '', $normalized);

        return str_starts_with($normalized, '-') ? -$paisa : $paisa;
    }

    /**
     * Render whole paisa back as a normalised amount.
     */
    private static function fromPaisa(int $paisa): string
    {
        $digits = str_pad((string) abs($paisa), self::SCALE + 1, '0', STR_PAD_LEFT);

        return ($paisa < 0 ? '-' : '')
            .substr($digits, 0, -self::SCALE)
            .'.'
            .substr($digits, -self::SCALE);
    }
}
