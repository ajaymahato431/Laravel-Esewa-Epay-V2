<?php

namespace AjayMahato\Esewa;

use AjayMahato\Esewa\Enums\PaymentStatus;
use AjayMahato\Esewa\Events\EsewaPaymentFailed;
use AjayMahato\Esewa\Events\EsewaPaymentInitiated;
use AjayMahato\Esewa\Events\EsewaPaymentStatusUpdated;
use AjayMahato\Esewa\Events\EsewaPaymentVerified;
use AjayMahato\Esewa\Exceptions\EsewaException;
use AjayMahato\Esewa\Jobs\ReconcileEsewaPayment;
use AjayMahato\Esewa\Models\EsewaPayment;
use AjayMahato\Esewa\Support\Amount;
use AjayMahato\Esewa\Support\RedirectGuard;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything that happens around a payment: creating it, believing eSewa about
 * it, and telling the application when it changed.
 *
 * {@see EsewaClient} owns the protocol; this class owns the database rows, the
 * events and the ordering guarantees.
 */
class PaymentManager
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected EsewaClient $client,
        protected array $config = [],
    ) {}

    // ------------------------------------------------------------- starting

    /**
     * Record a payment attempt and return the auto-submitting eSewa form.
     *
     * ```php
     * return Esewa::pay([
     *     'amount'  => $order->total,
     *     'payable' => $order,
     * ]);
     * ```
     *
     * @param array<string, mixed> $params
     *
     * @throws EsewaException
     */
    public function pay(array $params): Response
    {
        $payment = $this->createPayment($params);

        $relay = $this->relayUrl($payment->transaction_uuid);

        // eSewa is always sent the package relay, never the merchant's own page.
        // The relay verifies the signed payload first and only then forwards the
        // customer, so a shopper can never land on a "thank you" page for a
        // payment that did not actually complete.
        $payload = $this->client->buildFormPayload([
            'amount' => $payment->amount,
            'tax_amount' => $payment->tax_amount,
            'product_service_charge' => $payment->service_charge,
            'product_delivery_charge' => $payment->delivery_charge,
            'total_amount' => $payment->total_amount,
            'transaction_uuid' => $payment->transaction_uuid,
            'success_url' => $relay,
            'failure_url' => $relay,
        ]);

        EsewaPaymentInitiated::dispatch($payment);

        $this->scheduleReconciliation($payment);

        return new Response(
            app(ViewFactory::class)->make('esewa::form', [
                'endpoint' => $this->client->formEndpoint(),
                'payload' => $payload,
            ])->render()
        );
    }

    /**
     * Build the payment payload without rendering anything.
     *
     * For applications that render their own form or drive eSewa from a SPA or
     * mobile client.
     *
     * @param array<string, mixed> $params
     * @return array{payment: EsewaPayment, endpoint: string, payload: array<string, string>}
     *
     * @throws EsewaException
     */
    public function prepare(array $params): array
    {
        $payment = $this->createPayment($params);
        $relay = $this->relayUrl($payment->transaction_uuid);

        $payload = $this->client->buildFormPayload([
            'amount' => $payment->amount,
            'tax_amount' => $payment->tax_amount,
            'product_service_charge' => $payment->service_charge,
            'product_delivery_charge' => $payment->delivery_charge,
            'total_amount' => $payment->total_amount,
            'transaction_uuid' => $payment->transaction_uuid,
            'success_url' => $relay,
            'failure_url' => $relay,
        ]);

        EsewaPaymentInitiated::dispatch($payment);

        $this->scheduleReconciliation($payment);

        return [
            'payment' => $payment,
            'endpoint' => $this->client->formEndpoint(),
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws EsewaException
     */
    protected function createPayment(array $params): EsewaPayment
    {
        $amount = Amount::normalize($params['amount'] ?? 0);
        $tax = Amount::normalize($params['tax_amount'] ?? 0);
        $service = Amount::normalize($params['product_service_charge'] ?? 0);
        $delivery = Amount::normalize($params['product_delivery_charge'] ?? 0);
        $total = isset($params['total_amount'])
            ? Amount::normalize($params['total_amount'])
            : Amount::sum($amount, $tax, $service, $delivery);

        $uuid = isset($params['transaction_uuid'])
            ? (string) $params['transaction_uuid']
            : $this->generateTransactionUuid();

        $this->client->assertValidTransactionUuid($uuid);

        if (EsewaPayment::query()->forTransaction($uuid)->exists()) {
            throw new EsewaException(
                "A payment with transaction_uuid \"{$uuid}\" already exists. Each eSewa attempt needs a unique id."
            );
        }

        $guard = RedirectGuard::fromConfig();

        $meta = is_array($params['meta'] ?? null) ? $params['meta'] : [];
        $meta['success_redirect'] = $guard->safe(
            $params['success_url'] ?? ($meta['success_redirect'] ?? null) ?? $this->configuredRedirect('success')
        );
        $meta['failure_redirect'] = $guard->safe(
            $params['failure_url'] ?? ($meta['failure_redirect'] ?? null) ?? $this->configuredRedirect('failure')
        );

        $payable = $params['payable'] ?? null;

        return EsewaPayment::query()->create([
            'transaction_uuid' => $uuid,
            'product_code' => $this->client->productCode(),
            'amount' => $amount,
            'tax_amount' => $tax,
            'service_charge' => $service,
            'delivery_charge' => $delivery,
            'total_amount' => $total,
            'status' => PaymentStatus::PENDING,
            'payable_type' => $payable instanceof Model ? $payable->getMorphClass() : null,
            'payable_id' => $payable instanceof Model ? (string) $payable->getKey() : null,
            'meta' => array_filter($meta, static fn ($value) => $value !== null),
        ]);
    }

    /**
     * eSewa allows only letters, digits and hyphens, so this stays within that
     * alphabet while remaining sortable and unique.
     */
    public function generateTransactionUuid(): string
    {
        return Carbon::now()->format('ymd-His').'-'.Str::upper(Str::random(6));
    }

    // ------------------------------------------------------------ callbacks

    /**
     * Verify a callback from eSewa and apply it to the payment record.
     *
     * Accepts the request itself, or the raw Base64 `data` parameter.
     *
     * @throws EsewaException
     */
    public function handleCallback(Request|string $data): EsewaPayment
    {
        $encoded = $data instanceof Request ? $this->extractPayload($data) : $data;

        if (! is_string($encoded) || $encoded === '') {
            throw new EsewaException('No eSewa callback payload was present in the request.');
        }

        $verified = $this->client->verifyCallback($encoded);

        return $this->applyGatewayState(
            (string) ($verified['transaction_uuid'] ?? ''),
            $verified,
            $verified
        );
    }

    /**
     * Ask eSewa for the current state of a payment and apply the answer.
     *
     * @throws EsewaException
     */
    public function reconcile(EsewaPayment $payment): EsewaPayment
    {
        $response = $this->client->statusCheck(
            $payment->product_code,
            $payment->total_amount,
            $payment->transaction_uuid
        );

        return $this->applyGatewayState($payment->transaction_uuid, $response, $response);
    }

    /**
     * Reconcile by transaction id, returning null when there is no such record.
     *
     * @throws EsewaException
     */
    public function reconcileTransaction(string $transactionUuid): ?EsewaPayment
    {
        $payment = EsewaPayment::query()->forTransaction($transactionUuid)->first();

        return $payment ? $this->reconcile($payment) : null;
    }

    /**
     * Apply an authoritative gateway response to a payment row.
     *
     * The row is locked for the duration, so a browser callback and a queued
     * reconciliation arriving at the same instant cannot both observe "not yet
     * complete" and both fire EsewaPaymentVerified - which would fulfil the
     * order twice.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $rawResponse
     *
     * @throws EsewaException
     */
    protected function applyGatewayState(string $transactionUuid, array $state, array $rawResponse): EsewaPayment
    {
        if ($transactionUuid === '') {
            throw new EsewaException('eSewa response did not identify a transaction_uuid.');
        }

        /** @var array{0: EsewaPayment, 1: PaymentStatus} $result */
        $result = DB::connection($this->connectionName())->transaction(function () use ($transactionUuid, $state, $rawResponse) {
            $payment = EsewaPayment::query()
                ->forTransaction($transactionUuid)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                throw new EsewaException(
                    "No eSewa payment record for transaction \"{$transactionUuid}\". "
                    .'Payments must be started with Esewa::pay() so the amount can be verified against the callback.'
                );
            }

            $this->assertConsistent($payment, $state);

            $previous = $payment->status;
            $status = PaymentStatus::fromResponse($state['status'] ?? null);

            $attributes = [
                'status' => $status,
                'raw_response' => $rawResponse,
            ];

            $refId = $state['transaction_code'] ?? $state['ref_id'] ?? null;

            if (is_scalar($refId) && (string) $refId !== '') {
                $attributes['ref_id'] = (string) $refId;
            }

            if ($status->isComplete() && $payment->verified_at === null) {
                $attributes['verified_at'] = Carbon::now();
            }

            $payment->fill($attributes)->save();

            return [$payment->refresh(), $previous];
        });

        [$payment, $previous] = $result;

        $this->dispatchTransitionEvents($payment, $previous);

        return $payment;
    }

    /**
     * Refuse a response that does not describe the payment we started.
     *
     * The signature proves eSewa sent it; this proves it is about the right
     * transaction and the right amount, which is what stops a valid callback for
     * a 10 rupee order being replayed against a 10,000 rupee one.
     *
     * @param array<string, mixed> $state
     *
     * @throws EsewaException
     */
    protected function assertConsistent(EsewaPayment $payment, array $state): void
    {
        $productCode = $state['product_code'] ?? null;

        if (is_string($productCode) && $productCode !== '' && $productCode !== $payment->product_code) {
            throw new EsewaException(
                "eSewa response for {$payment->transaction_uuid} is for product code \"{$productCode}\" "
                ."but the payment was created with \"{$payment->product_code}\"."
            );
        }

        $total = $state['total_amount'] ?? null;

        // The status-check endpoint echoes no amount, so only verify when present.
        if ($total !== null && $total !== '' && ! Amount::equals($total, $payment->total_amount)) {
            throw new EsewaException(
                "eSewa response for {$payment->transaction_uuid} reports a total of "
                .Amount::tryNormalize($total)." but the payment was created for {$payment->total_amount}."
            );
        }
    }

    /**
     * Announce what changed. Each specific event fires only on the transition
     * into that state, never on a repeat of it.
     */
    protected function dispatchTransitionEvents(EsewaPayment $payment, PaymentStatus $previous): void
    {
        if ($payment->status === $previous) {
            return;
        }

        EsewaPaymentStatusUpdated::dispatch($payment, $previous);

        if ($payment->status->isComplete()) {
            EsewaPaymentVerified::dispatch($payment);

            return;
        }

        if ($payment->status->isFailed()) {
            EsewaPaymentFailed::dispatch($payment);
        }
    }

    // -------------------------------------------------------------- helpers

    /**
     * Pull the Base64 payload out of whichever parameter eSewa used.
     */
    public function extractPayload(Request $request): ?string
    {
        foreach (['data', 'payload', 'response'] as $key) {
            $value = $request->input($key, $request->query($key));

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Find the transaction id when eSewa sent us back without a signed payload.
     */
    public function extractTransactionUuid(Request $request): ?string
    {
        foreach (['transaction_uuid', 'transactionUuid', 'transaction_id', 'transactionId', 'uuid', 'oid'] as $key) {
            $value = $request->input($key, $request->query($key));

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $routeValue = $request->route('transaction');

        return is_string($routeValue) && $routeValue !== '' ? $routeValue : null;
    }

    public function relayUrl(?string $transactionUuid = null): string
    {
        $parameters = $transactionUuid !== null ? ['transaction' => $transactionUuid] : [];

        if (app('router')->has('esewa.relay')) {
            return route('esewa.relay', $parameters);
        }

        // Package routes are switched off, so build the conventional path.
        $prefix = trim((string) $this->configValue('routes.prefix', 'esewa'), '/');
        $path = ($prefix !== '' ? '/'.$prefix : '').'/relay';

        return url($path.($transactionUuid !== null ? '/'.rawurlencode($transactionUuid) : ''));
    }

    protected function scheduleReconciliation(EsewaPayment $payment): void
    {
        if (! (bool) $this->configValue('reconciliation.auto_dispatch', false)) {
            return;
        }

        $delay = (int) $this->configValue('reconciliation.delay', 10);

        ReconcileEsewaPayment::dispatch($payment->transaction_uuid)
            ->delay(Carbon::now()->addMinutes(max($delay, 1)));
    }

    protected function configuredRedirect(string $key): ?string
    {
        $value = $this->configValue("redirect.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function connectionName(): ?string
    {
        $connection = $this->configValue('database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    protected function configValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, config('esewa.'.$key, $default));
    }
}
