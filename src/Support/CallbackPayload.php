<?php

namespace AjayMahato\Esewa\Support;

use AjayMahato\Esewa\Exceptions\SignatureVerificationException;
use JsonException;

/**
 * A decoded eSewa callback payload that remembers how the gateway wrote it.
 *
 * eSewa signs the *textual* form of each field. Its own documented payload
 * carries `"total_amount": 1000.0` as a JSON number, and the signature is taken
 * over `total_amount=1000.0`. Decoding that to a PHP float and interpolating it
 * back yields `1000`, which produces a completely different HMAC and rejects a
 * genuine payment.
 *
 * So this class keeps the raw JSON text alongside the decoded array, and rebuilds
 * the signed string from the original literals. Strings already round-trip
 * exactly (which is why the widely reported `"1,000.0"` form has always worked);
 * numbers, booleans and null are the cases that need the raw text.
 */
final class CallbackPayload
{
    /**
     * Fields whose values are re-read from the raw JSON rather than from the
     * decoded array. Everything else is a string and round-trips as-is.
     */
    private const LITERAL_PATTERN = '-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null';

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private readonly string $json,
        private readonly array $data,
    ) {}

    /**
     * @throws SignatureVerificationException
     */
    public static function fromBase64(string $encoded): self
    {
        $json = base64_decode(trim($encoded), true);

        if ($json === false || $json === '') {
            throw SignatureVerificationException::invalidBase64();
        }

        return self::fromJson($json);
    }

    /**
     * @throws SignatureVerificationException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw SignatureVerificationException::invalidJson($e->getMessage());
        }

        // json_decode(..., true) hands back a plain list for `[1,2,3]` just as
        // it does for an object, so the shape has to be checked as well as the
        // type. An empty object is caught here too - it carries no signature
        // and could never be verified.
        if (! is_array($data) || array_is_list($data)) {
            throw SignatureVerificationException::invalidJson('expected a JSON object of fields');
        }

        /** @var array<string, mixed> $data */
        return new self($json, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function get(string $field, mixed $default = null): mixed
    {
        return $this->data[$field] ?? $default;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    public function signature(): ?string
    {
        $signature = $this->data['signature'] ?? null;

        return is_string($signature) && $signature !== '' ? $signature : null;
    }

    /**
     * The field names eSewa says it signed, in the order it signed them.
     *
     * @return array<int, string>
     */
    public function signedFieldNames(): array
    {
        $names = $this->data['signed_field_names'] ?? null;

        if (! is_string($names) || trim($names) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $names)),
            static fn (string $name): bool => $name !== ''
        ));
    }

    /**
     * Rebuild the exact string eSewa ran through HMAC-SHA256.
     *
     * @throws SignatureVerificationException
     */
    public function signatureSource(): string
    {
        $pairs = [];

        foreach ($this->signedFieldNames() as $field) {
            $pairs[] = $field.'='.$this->rawValue($field);
        }

        return implode(',', $pairs);
    }

    /**
     * The value of a field exactly as it appears in the gateway's JSON.
     *
     * @throws SignatureVerificationException
     */
    public function rawValue(string $field): string
    {
        if (! array_key_exists($field, $this->data)) {
            throw SignatureVerificationException::missingSignedField($field);
        }

        $value = $this->data[$field];

        // Strings decode back to precisely the characters eSewa sent.
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return (string) json_encode($value);
        }

        return $this->literalFor($field, $value);
    }

    /**
     * Recover a number/boolean/null token verbatim from the raw JSON.
     *
     * A top-level key is always preceded by `{` or `,`, and these literal forms
     * can never contain a quote or an escape, so this match is exact rather than
     * a heuristic.
     */
    private function literalFor(string $field, mixed $decoded): string
    {
        $pattern = '/[{,]\s*"'.preg_quote($this->escapeJsonKey($field), '/').'"\s*:\s*('.self::LITERAL_PATTERN.')/';

        if (preg_match($pattern, $this->json, $matches) === 1) {
            return $matches[1];
        }

        // Unreachable for well-formed eSewa payloads. json_encode still renders
        // floats with their fractional part (1000.0 stays "1000.0"), unlike a
        // plain string cast, so it degrades safely rather than silently
        // truncating the value the way the previous implementation did.
        return match (true) {
            $decoded === null => 'null',
            $decoded === true => 'true',
            $decoded === false => 'false',
            default => (string) json_encode($decoded),
        };
    }

    /**
     * Field names appear in the JSON in their escaped form.
     */
    private function escapeJsonKey(string $field): string
    {
        $encoded = json_encode($field);

        return $encoded === false ? $field : trim($encoded, '"');
    }

    /**
     * Build a signature source from a plain array, for signing outbound requests
     * and for generating test payloads.
     *
     * Values are stringified by the caller; anything numeric should already have
     * been normalised through {@see Amount}.
     *
     * @param array<string, mixed> $fields
     * @param array<int, string> $names
     *
     * @throws SignatureVerificationException
     */
    public static function sourceFrom(array $fields, array $names): string
    {
        $pairs = [];

        foreach ($names as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            if (! array_key_exists($name, $fields)) {
                throw SignatureVerificationException::missingSignedField($name);
            }

            $value = $fields[$name];

            $pairs[] = $name.'='.match (true) {
                $value === null => 'null',
                $value === true => 'true',
                $value === false => 'false',
                is_float($value) => (string) json_encode($value),
                default => (string) $value,
            };
        }

        return implode(',', $pairs);
    }
}
