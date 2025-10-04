<?php

namespace AjayMahato\Esewa;

use AjayMahato\Esewa\Exceptions\EsewaException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use function route;
use function url;

class EsewaClient
{
    public function __construct(protected array $config) {}

    public function formEndpoint(): string
    {
        return $this->config['endpoints'][$this->config['mode'] ?? 'uat']['form'];
    }

    public function statusEndpoint(): string
    {
        return $this->config['endpoints'][$this->config['mode'] ?? 'uat']['status_check'];
    }

    public function buildRequestSignature(string $totalAmount, string $uuid): string
    {
        $productCode = $this->config['product_code'];
        $data = "total_amount={$totalAmount},transaction_uuid={$uuid},product_code={$productCode}";
        return $this->hmacBase64($data, $this->config['secret_key']);
    }

    public function buildSignatureForFields(array $fields, string $signedFieldNamesCsv): string
    {
        $names = array_map('trim', explode(',', $signedFieldNamesCsv));
        $pairs = [];
        foreach ($names as $name) {
            if (!array_key_exists($name, $fields)) {
                throw new EsewaException("Missing field '{$name}' required by signed_field_names.");
            }
            $pairs[] = "{$name}={$fields[$name]}";
        }
        return $this->hmacBase64(implode(',', $pairs), $this->config['secret_key']);
    }

    protected function hmacBase64(string $data, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $data, $secret, true));
    }

    public function buildFormPayload(array $params, array $override = []): array
    {
        $productCode = $this->config['product_code'];

        $successUrl = $this->resolveUrl(
            $params['success_url']
            ?? ($override['success_url'] ?? null)
            ?? ($this->config['success_url'] ?? null)
        );

        $failureUrl = $this->resolveUrl(
            $params['failure_url']
            ?? ($override['failure_url'] ?? null)
            ?? ($this->config['failure_url'] ?? null)
        );

        $relayUrl = $this->relayUrl($params['transaction_uuid'] ?? null);

        $payload = [
            'amount'                  => $params['amount'],
            'tax_amount'              => $params['tax_amount'] ?? 0,
            'product_service_charge'  => $params['product_service_charge'] ?? 0,
            'product_delivery_charge' => $params['product_delivery_charge'] ?? 0,
            'total_amount'            => $params['total_amount'],
            'transaction_uuid'        => $params['transaction_uuid'] ?? Str::uuid()->toString(),
            'product_code'            => $productCode,
            'success_url'             => $successUrl ?? $relayUrl,
            'failure_url'             => $failureUrl ?? $relayUrl,
            'signed_field_names'      => 'total_amount,transaction_uuid,product_code',
        ];

        $payload['signature'] = $this->buildRequestSignature(
            (string) $payload['total_amount'],
            (string) $payload['transaction_uuid'],
        );

        return $payload;
    }

    protected function resolveUrl(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $value)) {
            return $value;
        }

        return url($value);
    }

    public function relayUrl(?string $transactionUuid = null): string
    {
        try {
            return route('esewa.relay', ['transaction' => $transactionUuid]);
        } catch (\Throwable $e) {
            $path = '/esewa/relay';
            if ($transactionUuid) {
                $path .= '/' . $transactionUuid;
            }

            return url($path);
        }
    }

    public function verifyCallback(string $base64Json): array
    {
        $json = base64_decode($base64Json, true);
        if ($json === false) {
            throw new EsewaException('Invalid Base64 payload.');
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new EsewaException('Invalid JSON payload.');
        }

        if (empty($data['signed_field_names']) || empty($data['signature'])) {
            throw new EsewaException('Missing signature metadata.');
        }

        $computed = $this->buildSignatureForFields($data, $data['signed_field_names']);

        if (!hash_equals($data['signature'], $computed)) {
            throw new EsewaException('Signature mismatch.');
        }

        return $data;
    }

    public function statusCheck(string $productCode, string $totalAmount, string $transactionUuid): array
    {
        $res = Http::timeout($this->config['http']['timeout'] ?? 10)
            ->acceptJson()
            ->get($this->statusEndpoint(), [
                'product_code'     => $productCode,
                'total_amount'     => $totalAmount,
                'transaction_uuid' => $transactionUuid,
            ]);

        if (!$res->ok()) {
            throw new EsewaException("Status check failed with HTTP {$res->status()}");
        }

        return $res->json();
    }
}
