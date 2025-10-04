<?php

namespace AjayMahato\Esewa\Http\Controllers;

use AjayMahato\Esewa\Enums\PaymentStatus;
use AjayMahato\Esewa\Events\EsewaPaymentVerified;
use AjayMahato\Esewa\Exceptions\EsewaException;
use AjayMahato\Esewa\Facades\Esewa;
use AjayMahato\Esewa\Models\EsewaPayment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

class CallbackController extends Controller
{
    public function handle(Request $request)
    {
        return $request->isMethod('post')
            ? $this->handleServerCallback($request)
            : $this->handleBrowserCallback($request);
    }

    protected function handleServerCallback(Request $request)
    {
        $base64 = $this->resolvePayload($request);

        if (!$base64) {
            return response()->json(['ok' => false, 'error' => 'Missing callback payload.'], 422);
        }

        try {
            $verified = Esewa::verifyCallback($base64);
            $payment  = $this->persistPayment($verified);
        } catch (EsewaException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok'     => true,
            'data'   => $verified,
            'status' => $payment->status->value,
        ]);
    }

    protected function handleBrowserCallback(Request $request)
    {
        $base64 = $this->resolvePayload($request);

        if (!$base64) {
            return $this->renderBrowserResponse($request, [
                'ok'      => false,
                'message' => 'Missing callback payload. Ensure your success_url/failure_url forwards the signed Base64 response from eSewa.',
            ], null, null, 422);
        }

        try {
            $verified = Esewa::verifyCallback($base64);
            $payment  = $this->persistPayment($verified);
        } catch (EsewaException $e) {
            return $this->renderBrowserResponse($request, [
                'ok'      => false,
                'message' => $e->getMessage(),
            ], null, null, 422);
        }

        $ok = $payment->status === PaymentStatus::COMPLETE;

        return $this->renderBrowserResponse($request, [
            'ok'      => $ok,
            'message' => $this->browserMessageFromStatus($payment->status, $payment->transaction_uuid),
            'status'  => $payment->status->value,
            'ref_id'  => $payment->ref_id,
        ], $payment, $verified, $ok ? 200 : 202);
    }

    protected function resolvePayload(Request $request): ?string
    {
        foreach (['data', 'payload', 'response'] as $key) {
            $value = $request->input($key, $request->query($key));
            if ($value) {
                return (string) $value;
            }
        }

        return null;
    }

    protected function persistPayment(array $verified): EsewaPayment
    {
        if (empty($verified['transaction_uuid'])) {
            throw new EsewaException('Callback payload missing transaction_uuid.');
        }

        $uuid        = (string) $verified['transaction_uuid'];
        $status      = $this->resolveStatus($verified['status'] ?? null);
        $totalAmount = $this->normalizeAmount($verified['total_amount'] ?? null);

        $payment = EsewaPayment::query()->where('transaction_uuid', $uuid)->first();

        if ($payment) {
            $this->guardPaymentConsistency($payment, $verified, $totalAmount);
        } else {
            if ($totalAmount <= 0) {
                throw new EsewaException('Callback payload missing total_amount for new transaction.');
            }

            $payment = EsewaPayment::create([
                'transaction_uuid' => $uuid,
                'product_code'     => (string) ($verified['product_code'] ?? config('esewa.product_code')),
                'amount'           => $this->normalizeAmount($verified['amount'] ?? $totalAmount),
                'tax_amount'       => $this->normalizeAmount($verified['tax_amount'] ?? 0),
                'service_charge'   => $this->normalizeAmount($verified['product_service_charge'] ?? 0),
                'delivery_charge'  => $this->normalizeAmount($verified['product_delivery_charge'] ?? 0),
                'total_amount'     => $totalAmount,
                'status'           => $status,
                'ref_id'           => $verified['transaction_code'] ?? $verified['ref_id'] ?? null,
                'raw_response'     => $verified,
                'verified_at'      => $status === PaymentStatus::COMPLETE ? Carbon::now() : null,
            ]);

            if ($status === PaymentStatus::COMPLETE) {
                event(new EsewaPaymentVerified($payment));
            }

            return $payment;
        }

        $previousStatus = $payment->status;

        $updates = [
            'status'       => $status,
            'ref_id'       => $verified['transaction_code'] ?? $verified['ref_id'] ?? $payment->ref_id,
            'raw_response' => $verified,
        ];

        if ($status === PaymentStatus::COMPLETE && !$payment->verified_at) {
            $updates['verified_at'] = Carbon::now();
        }

        $payment->update($updates);
        $payment->refresh();

        if ($previousStatus !== PaymentStatus::COMPLETE && $payment->status === PaymentStatus::COMPLETE) {
            event(new EsewaPaymentVerified($payment));
        }

        return $payment;
    }

    protected function guardPaymentConsistency(EsewaPayment $payment, array $verified, int $totalAmount): void
    {
        $productCode = $verified['product_code'] ?? null;

        if ($productCode && $payment->product_code !== $productCode) {
            throw new EsewaException('Product code mismatch for transaction ' . $payment->transaction_uuid . '.');
        }

        if ($totalAmount > 0 && (int) $payment->total_amount !== $totalAmount) {
            throw new EsewaException('Total amount mismatch for transaction ' . $payment->transaction_uuid . '.');
        }
    }

    protected function resolveStatus($status): PaymentStatus
    {
        if ($status === null) {
            return PaymentStatus::PENDING;
        }

        if (is_string($status)) {
            $status = strtoupper($status);
        }

        return PaymentStatus::tryFrom((string) $status) ?? PaymentStatus::PENDING;
    }

    protected function normalizeAmount($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (!is_numeric($value)) {
            $value = preg_replace('/[^0-9\.]/', '', (string) $value);
        }

        return (int) round((float) $value);
    }

    protected function browserMessageFromStatus(PaymentStatus $status, string $uuid): string
    {
        return match ($status) {
            PaymentStatus::COMPLETE       => 'Payment verified successfully.',
            PaymentStatus::PENDING        => "Payment for {$uuid} is still processing.",
            PaymentStatus::AMBIGUOUS      => "Payment for {$uuid} is in an ambiguous state. Please verify later.",
            PaymentStatus::CANCELED       => "Payment for {$uuid} was canceled. Please try again.",
            PaymentStatus::NOT_FOUND      => "We could not locate transaction {$uuid}.",
            PaymentStatus::FULL_REFUND    => "Payment for {$uuid} has been fully refunded.",
            PaymentStatus::PARTIAL_REFUND => "Payment for {$uuid} has been partially refunded.",
        };
    }

    protected function renderBrowserResponse(Request $request, array $meta, ?EsewaPayment $payment, ?array $raw, int $status)
    {
        $redirect = $this->resolveRedirectUrl($request);

        $payload = [
            'meta'        => $meta,
            'payment'     => $payment,
            'raw'         => $raw,
            'statusCode'  => $status,
            'redirectUrl' => $redirect,
        ];

        if ($redirect) {
            return redirect()->to($redirect)->with('esewa', $payload);
        }

        return response()->view('esewa::callback-status', $payload, $status);
    }

    protected function resolveRedirectUrl(Request $request): ?string
    {
        $redirect = $request->query('redirect');

        return $redirect ?: null;
    }
}
