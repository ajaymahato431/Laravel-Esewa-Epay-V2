<?php

namespace AjayMahato\Esewa\Exceptions;

/**
 * Thrown when the package is misconfigured in a way that would produce wrong or
 * unsafe results if allowed to proceed - a missing secret key, an unknown mode,
 * or the public UAT key left in place while running in production.
 */
class EsewaConfigurationException extends EsewaException
{
    public static function missingSecretKey(): self
    {
        return new self(
            'eSewa secret key is not configured. Set ESEWA_SECRET_KEY in your .env file. '
            .'The UAT sandbox key is "8gBm/:&EnhH.1/q"; production keys are issued by eSewa.'
        );
    }

    public static function uatSecretInProduction(): self
    {
        return new self(
            'The public eSewa UAT secret key is configured while ESEWA_MODE=production. '
            .'Set ESEWA_SECRET_KEY to the merchant key issued by eSewa before going live.'
        );
    }

    public static function missingProductCode(): self
    {
        return new self('eSewa product code is not configured. Set ESEWA_PRODUCT_CODE in your .env file.');
    }

    /**
     * @param  array<int, string>  $supported
     */
    public static function unknownMode(string $mode, array $supported): self
    {
        return new self(sprintf(
            'Unknown eSewa mode [%s]. Set ESEWA_MODE to one of: %s.',
            $mode,
            implode(', ', $supported)
        ));
    }
}
