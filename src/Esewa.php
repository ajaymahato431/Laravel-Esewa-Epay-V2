<?php

namespace AjayMahato\Esewa;

use AjayMahato\Esewa\Models\EsewaPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The single entry point behind the `Esewa` facade.
 *
 * Real methods rather than a `__call` proxy, so editors autocomplete them and
 * static analysis can check them.
 */
class Esewa
{
    public function __construct(
        protected EsewaClient $client,
        protected PaymentManager $payments,
    ) {}

    // ------------------------------------------------------------- starting

    /**
     * Record a payment and return the auto-submitting eSewa form.
     *
     * ```php
     * return Esewa::pay(['amount' => $order->total, 'payable' => $order]);
     * ```
     *
     * @param array<string, mixed> $params
     */
    public function pay(array $params): Response
    {
        return $this->payments->pay($params);
    }

    /**
     * The signed form fields, without rendering a page.
     *
     * For SPA and mobile clients that post to eSewa themselves.
     *
     * @param array<string, mixed> $params
     * @return array{payment: EsewaPayment, endpoint: string, payload: array<string, string>}
     */
    public function prepare(array $params): array
    {
        return $this->payments->prepare($params);
    }

    // ------------------------------------------------------------ finishing

    /**
     * Verify an eSewa callback and apply it to the payment record.
     *
     * Use this in your own success route when `ESEWA_SUCCESS_URL` points at your
     * application rather than at the package relay.
     *
     * ```php
     * $payment = Esewa::handleCallback($request);
     * ```
     */
    public function handleCallback(Request|string $data): EsewaPayment
    {
        return $this->payments->handleCallback($data);
    }

    /**
     * Ask eSewa what happened and update the record accordingly.
     */
    public function reconcile(EsewaPayment $payment): EsewaPayment
    {
        return $this->payments->reconcile($payment);
    }

    /**
     * Reconcile by transaction id. Returns null when no such payment exists.
     */
    public function reconcileTransaction(string $transactionUuid): ?EsewaPayment
    {
        return $this->payments->reconcileTransaction($transactionUuid);
    }

    /**
     * Look a payment up by its eSewa transaction id.
     */
    public function find(string $transactionUuid): ?EsewaPayment
    {
        return EsewaPayment::query()->forTransaction($transactionUuid)->first();
    }

    // -------------------------------------------------------------- protocol

    /**
     * Verify a Base64 callback payload without touching the database.
     *
     * @return array<string, mixed>
     */
    public function verifyCallback(string $base64Json): array
    {
        return $this->client->verifyCallback($base64Json);
    }

    /**
     * Query eSewa's transaction status endpoint directly.
     *
     * @return array<string, mixed>
     */
    public function statusCheck(string $productCode, mixed $totalAmount, string $transactionUuid): array
    {
        return $this->client->statusCheck($productCode, $totalAmount, $transactionUuid);
    }

    public function buildRequestSignature(mixed $totalAmount, string $transactionUuid): string
    {
        return $this->client->buildRequestSignature($totalAmount, $transactionUuid);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function buildSignatureForFields(array $fields, string $signedFieldNamesCsv): string
    {
        return $this->client->buildSignatureForFields($fields, $signedFieldNamesCsv);
    }

    /**
     * Generate a correctly signed callback payload for use in your own tests.
     *
     * ```php
     * $this->get('/esewa/callback?data='.Esewa::signedCallbackPayload([
     *     'transaction_uuid' => $payment->transaction_uuid,
     *     'total_amount'     => $payment->total_amount,
     * ]));
     * ```
     *
     * @param array<string, mixed> $fields
     */
    public function signedCallbackPayload(array $fields = []): string
    {
        return $this->client->signedCallbackPayload($fields);
    }

    public function formEndpoint(): string
    {
        return $this->client->formEndpoint();
    }

    public function statusEndpoint(): string
    {
        return $this->client->statusEndpoint();
    }

    public function relayUrl(?string $transactionUuid = null): string
    {
        return $this->payments->relayUrl($transactionUuid);
    }

    public function mode(): string
    {
        return $this->client->mode();
    }

    public function isProduction(): bool
    {
        return $this->client->isProduction();
    }

    public function productCode(): string
    {
        return $this->client->productCode();
    }

    // ------------------------------------------------------------- internals

    public function client(): EsewaClient
    {
        return $this->client;
    }

    public function payments(): PaymentManager
    {
        return $this->payments;
    }
}
