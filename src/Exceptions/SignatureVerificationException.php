<?php

namespace AjayMahato\Esewa\Exceptions;

/**
 * Thrown when a callback payload cannot be trusted.
 *
 * Every instance means the payload was rejected and no payment state was
 * changed. Never fulfil an order in response to one of these.
 */
class SignatureVerificationException extends EsewaException
{
    public static function invalidBase64(): self
    {
        return new self('eSewa callback payload is not valid Base64.');
    }

    public static function invalidJson(string $reason): self
    {
        return new self("eSewa callback payload is not valid JSON: {$reason}.");
    }

    public static function missingSignatureMetadata(): self
    {
        return new self('eSewa callback payload is missing "signature" or "signed_field_names".');
    }

    public static function missingSignedField(string $field): self
    {
        return new self("eSewa callback payload lists \"{$field}\" in signed_field_names but does not contain it.");
    }

    /**
     * @param array<int, string> $missing
     */
    public static function insufficientSignedFields(array $missing): self
    {
        return new self(sprintf(
            'eSewa callback payload does not sign the required field(s): %s. '
            .'Refusing to trust a partially signed payload, because a signature covering only '
            .'incidental fields could be replayed against a different transaction.',
            implode(', ', $missing)
        ));
    }

    public static function mismatch(): self
    {
        return new self('eSewa callback signature does not match the payload.');
    }

    public static function productCodeMismatch(string $expected, string $received): self
    {
        return new self("eSewa callback is for product code \"{$received}\" but this application is configured for \"{$expected}\".");
    }
}
