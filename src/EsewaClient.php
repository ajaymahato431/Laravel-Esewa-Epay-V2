<?php

namespace AjayMahato\Esewa;

use AjayMahato\Esewa\Exceptions\EsewaConfigurationException;
use AjayMahato\Esewa\Exceptions\EsewaException;
use AjayMahato\Esewa\Exceptions\SignatureVerificationException;
use AjayMahato\Esewa\Support\Amount;
use AjayMahato\Esewa\Support\CallbackPayload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The eSewa ePay v2 protocol, and nothing else.
 *
 * Endpoints, signing, verification and the status-check call live here. Anything
 * touching the database, events or HTTP responses belongs in
 * {@see PaymentManager}.
 *
 * @see https://developer.esewa.com.np/pages/Epay
 */
class EsewaClient
{
    /**
     * The sandbox key eSewa publishes in its documentation.
     *
     * Signing production traffic with this would be signing with a key the whole
     * internet knows, so it is refused outright.
     */
    public const UAT_SECRET_KEY = '8gBm/:&EnhH.1/q';

    /** eSewa fixes the request signature to these fields, in this order. */
    public const REQUEST_SIGNED_FIELDS = 'total_amount,transaction_uuid,product_code';

    /**
     * A callback must sign at least these fields for us to trust it.
     *
     * A signature covering only incidental fields could otherwise be captured
     * once and replayed against a different transaction or amount.
     *
     * @var array<int, string>
     */
    public const REQUIRED_SIGNED_FIELDS = [
        'transaction_uuid',
        'total_amount',
        'status',
        'product_code',
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config) {}

    // ---------------------------------------------------------------- config

    public function mode(): string
    {
        $mode = $this->config['mode'] ?? 'uat';

        return is_string($mode) && $mode !== '' ? $mode : 'uat';
    }

    public function isProduction(): bool
    {
        return $this->mode() === 'production';
    }

    public function productCode(): string
    {
        $code = $this->config['product_code'] ?? null;

        if (! is_string($code) || trim($code) === '') {
            throw EsewaConfigurationException::missingProductCode();
        }

        return trim($code);
    }

    /**
     * The signing key, refusing to hand back one that would produce bad or
     * unsafe signatures.
     *
     * @throws EsewaConfigurationException
     */
    public function secret(): string
    {
        $secret = $this->config['secret_key'] ?? null;

        if (! is_string($secret) || trim($secret) === '') {
            throw EsewaConfigurationException::missingSecretKey();
        }

        if ($this->isProduction() && $secret === self::UAT_SECRET_KEY) {
            throw EsewaConfigurationException::uatSecretInProduction();
        }

        return $secret;
    }

    public function formEndpoint(): string
    {
        return $this->endpoint('form');
    }

    public function statusEndpoint(): string
    {
        return $this->endpoint('status_check');
    }

    /**
     * @throws EsewaConfigurationException
     */
    protected function endpoint(string $key): string
    {
        $endpoints = $this->config['endpoints'] ?? [];
        $mode = $this->mode();

        if (! is_array($endpoints) || ! isset($endpoints[$mode]) || ! is_array($endpoints[$mode])) {
            throw EsewaConfigurationException::unknownMode(
                $mode,
                is_array($endpoints) ? array_map('strval', array_keys($endpoints)) : []
            );
        }

        $url = $endpoints[$mode][$key] ?? null;

        if (! is_string($url) || $url === '') {
            throw new EsewaConfigurationException("Missing eSewa \"{$key}\" endpoint for mode \"{$mode}\".");
        }

        return $url;
    }

    // ------------------------------------------------------------- signing

    /**
     * Sign an outbound payment request.
     *
     * The amount is normalised first, so `1000`, `1000.5` and `"1,000.50"` all
     * sign the same way they will be submitted.
     */
    public function buildRequestSignature(mixed $totalAmount, string $transactionUuid): string
    {
        $source = sprintf(
            'total_amount=%s,transaction_uuid=%s,product_code=%s',
            Amount::normalize($totalAmount),
            $transactionUuid,
            $this->productCode()
        );

        return $this->sign($source);
    }

    /**
     * Sign an arbitrary set of fields in the order named.
     *
     * @param  array<string, mixed>  $fields
     */
    public function buildSignatureForFields(array $fields, string $signedFieldNamesCsv): string
    {
        $names = array_map('trim', explode(',', $signedFieldNamesCsv));

        return $this->sign(CallbackPayload::sourceFrom($fields, $names));
    }

    public function sign(string $source): string
    {
        return base64_encode(hash_hmac('sha256', $source, $this->secret(), true));
    }

    // -------------------------------------------------------- form payload

    /**
     * Build the field set posted to the eSewa payment form.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     *
     * @throws EsewaException
     */
    public function buildFormPayload(array $params): array
    {
        foreach (['transaction_uuid', 'success_url', 'failure_url'] as $required) {
            if (! isset($params[$required]) || ! is_string($params[$required]) || $params[$required] === '') {
                throw new EsewaException("Missing required eSewa form field \"{$required}\".");
            }
        }

        $uuid = $params['transaction_uuid'];

        $this->assertValidTransactionUuid($uuid);

        $amount = Amount::normalize($params['amount'] ?? 0);
        $tax = Amount::normalize($params['tax_amount'] ?? 0);
        $service = Amount::normalize($params['product_service_charge'] ?? 0);
        $delivery = Amount::normalize($params['product_delivery_charge'] ?? 0);
        $total = Amount::normalize($params['total_amount'] ?? Amount::sum($amount, $tax, $service, $delivery));

        $expectedTotal = Amount::sum($amount, $tax, $service, $delivery);

        if (! Amount::equals($total, $expectedTotal)) {
            throw new EsewaException(
                "eSewa requires total_amount to equal amount + tax_amount + product_service_charge + product_delivery_charge. "
                ."Got total_amount={$total} but the parts sum to {$expectedTotal}."
            );
        }

        if (! Amount::isPositive($total)) {
            throw new EsewaException("eSewa will not accept a total_amount of {$total}. The amount must be greater than zero.");
        }

        $payload = [
            'amount' => $amount,
            'tax_amount' => $tax,
            'product_service_charge' => $service,
            'product_delivery_charge' => $delivery,
            'total_amount' => $total,
            'transaction_uuid' => $uuid,
            'product_code' => $this->productCode(),
            'success_url' => $params['success_url'],
            'failure_url' => $params['failure_url'],
            'signed_field_names' => self::REQUEST_SIGNED_FIELDS,
        ];

        $payload['signature'] = $this->buildRequestSignature($total, $uuid);

        return $payload;
    }

    /**
     * eSewa restricts the transaction id to alphanumerics and hyphens, and
     * silently fails the payment if you send anything else.
     *
     * @throws EsewaException
     */
    public function assertValidTransactionUuid(string $uuid): void
    {
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $uuid) !== 1) {
            throw new EsewaException(
                "Invalid transaction_uuid \"{$uuid}\". eSewa allows only letters, digits and hyphens (max 64 characters)."
            );
        }
    }

    // -------------------------------------------------------- verification

    /**
     * Verify a Base64 callback payload and return its decoded fields.
     *
     * @return array<string, mixed>
     *
     * @throws SignatureVerificationException
     */
    public function verifyCallback(string $base64Json): array
    {
        return $this->verifyPayload(CallbackPayload::fromBase64($base64Json));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SignatureVerificationException
     */
    public function verifyPayload(CallbackPayload $payload): array
    {
        $signature = $payload->signature();
        $signedFields = $payload->signedFieldNames();

        if ($signature === null || $signedFields === []) {
            throw SignatureVerificationException::missingSignatureMetadata();
        }

        $missing = array_values(array_diff(self::REQUIRED_SIGNED_FIELDS, $signedFields));

        if ($missing !== []) {
            throw SignatureVerificationException::insufficientSignedFields($missing);
        }

        $computed = $this->sign($payload->signatureSource());

        if (! hash_equals($computed, $signature)) {
            throw SignatureVerificationException::mismatch();
        }

        $productCode = $payload->get('product_code');

        if (is_string($productCode) && $productCode !== $this->productCode()) {
            throw SignatureVerificationException::productCodeMismatch($this->productCode(), $productCode);
        }

        return $payload->all();
    }

    /**
     * Produce a correctly signed callback payload.
     *
     * Intended for integration tests, so applications can exercise their own
     * listeners without hand-building an HMAC.
     *
     * @param  array<string, mixed>  $fields
     */
    public function signedCallbackPayload(array $fields = []): string
    {
        $fields = array_merge([
            'transaction_code' => 'TEST-'.strtoupper(bin2hex(random_bytes(3))),
            'status' => 'COMPLETE',
            'total_amount' => '100.00',
            'transaction_uuid' => 'TEST-'.strtoupper(bin2hex(random_bytes(4))),
            'product_code' => $this->productCode(),
        ], $fields);

        $fields['signed_field_names'] ??= 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names';
        $fields['signature'] = $this->buildSignatureForFields($fields, $fields['signed_field_names']);

        return base64_encode((string) json_encode($fields, JSON_UNESCAPED_SLASHES));
    }

    // ------------------------------------------------------- status check

    /**
     * Ask eSewa what actually happened to a transaction.
     *
     * This is the authoritative source. A browser callback can be lost, delayed
     * or never fired at all, so reconciliation always ends up here.
     *
     * @return array<string, mixed>
     *
     * @throws EsewaException
     */
    public function statusCheck(string $productCode, mixed $totalAmount, string $transactionUuid): array
    {
        $http = $this->config['http'] ?? [];
        $retry = is_array($http) && is_array($http['retry'] ?? null) ? $http['retry'] : [];

        $request = Http::asJson()
            ->acceptJson()
            ->timeout((int) (is_array($http) ? ($http['timeout'] ?? 30) : 30));

        $times = (int) ($retry['times'] ?? 0);

        if ($times > 0) {
            $request = $request->retry($times, (int) ($retry['sleep'] ?? 250), throw: false);
        }

        try {
            $response = $request->get($this->statusEndpoint(), [
                'product_code' => $productCode,
                'total_amount' => Amount::normalize($totalAmount),
                'transaction_uuid' => $transactionUuid,
            ]);
        } catch (ConnectionException $e) {
            throw new EsewaException("Could not reach eSewa to check transaction {$transactionUuid}: {$e->getMessage()}", 0, $e);
        }

        if (! $response->successful()) {
            throw new EsewaException(
                "eSewa status check for {$transactionUuid} failed with HTTP {$response->status()}."
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new EsewaException("eSewa status check for {$transactionUuid} returned a non-JSON body.");
        }

        // Documented error shape: {"code": 0, "error_message": "..."}
        if (isset($body['error_message']) && ! isset($body['status'])) {
            throw new EsewaException("eSewa status check for {$transactionUuid} failed: {$body['error_message']}");
        }

        /** @var array<string, mixed> $body */
        return $body;
    }
}
