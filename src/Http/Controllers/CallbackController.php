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
        if ($request->isMethod('get')) {
            return $this->handleBrowserCallback($request);
        }

        return $this->handleServerCallback($request);
    }

    protected function handleServerCallback(Request $request)
    {
        $base64 = $request->input('data');

        if (!$base64) {
            return response()->json(['ok' => false, 'error' => 'Missing callback payload.'], 422);
        }

        try {
            $verified = Esewa::verifyCallback($base64);
        } catch (EsewaException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        if (empty($verified['transaction_uuid'])) {
            return response()->json(['ok' => false, 'error' => 'Callback payload missing transaction_uuid.'], 422);
        }

        $payment = EsewaPayment::query()
            ->where('transaction_uuid', $verified['transaction_uuid'])
            ->first();

        if (!$payment) {
            $payment = EsewaPayment::create([
                'transaction_uuid' => $verified['transaction_uuid'],
                'product_code'     => $verified['product_code'] ?? config('esewa.product_code'),
                'amount'           => (int)($verified['total_amount'] ?? 0),
                'tax_amount'       => 0,
                'service_charge'   => 0,
                'delivery_charge'  => 0,
                'total_amount'     => (int)($verified['total_amount'] ?? 0),
                'status'           => PaymentStatus::from($verified['status'] ?? 'PENDING'),
            ]);
        }

        $payment->update([
            'status'       => PaymentStatus::from($verified['status'] ?? 'PENDING'),
            'ref_id'       => $verified['transaction_code'] ?? $verified['ref_id'] ?? null,
            'verified_at'  => Carbon::now(),
            'raw_response' => $verified,
        ]);

        $freshPayment = $payment->fresh();

        event(new EsewaPaymentVerified($freshPayment));

        return response()->json(['ok' => true, 'data' => $verified]);
    }

    protected function handleBrowserCallback(Request $request)
    {
        $uuid = $this->resolveTransactionUuid($request);

        if (!$uuid) {
            return $this->renderBrowserResponse($request, [
                'ok'      => false,
                'message' => 'Missing transaction_uuid query parameter.',
            ], null, null, 422);
        }

        $payment = EsewaPayment::query()
            ->where('transaction_uuid', $uuid)
            ->first();

        if (!$payment) {
            return $this->renderBrowserResponse($request, [
                'ok'      => false,
                'message' => "No payment record found for transaction_uuid {$uuid}.",
            ], null, null, 404);
        }

        try {
            $statusResponse = Esewa::statusCheck(
                $payment->product_code,
                (string)$payment->total_amount,
                $payment->transaction_uuid
            );
        } catch (EsewaException $e) {
            return $this->renderBrowserResponse($request, [
                'ok'      => false,
                'message' => $e->getMessage(),
            ], $payment, null, 502);
        }

        $updates = [
            'raw_response' => $statusResponse,
        ];

        if (!empty($statusResponse['status'])) {
            $updates['status'] = PaymentStatus::from($statusResponse['status']);
        }

        if (!empty($statusResponse['transaction_code']) || !empty($statusResponse['ref_id'])) {
            $updates['ref_id'] = $statusResponse['transaction_code'] ?? $statusResponse['ref_id'];
        }

        if (($updates['status'] ?? $payment->status) === PaymentStatus::COMPLETE) {
            $updates['verified_at'] = $payment->verified_at ?? Carbon::now();
        }

        $previousStatus = $payment->status;
        $payment->update($updates);
        $payment->refresh();

        if ($previousStatus !== PaymentStatus::COMPLETE && $payment->status === PaymentStatus::COMPLETE) {
            event(new EsewaPaymentVerified($payment));
        }

        return $this->renderBrowserResponse($request, [
            'ok'      => true,
            'message' => 'Payment status reconciled.',
            'status'  => $payment->status->value,
            'ref_id'  => $payment->ref_id,
        ], $payment, $statusResponse, 200);
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

    protected function resolveTransactionUuid(Request $request): ?string
    {
        foreach (['transaction_uuid', 'transactionUuid', 'transaction_id', 'transactionId', 'uuid', 'oid'] as $key) {
            $value = $request->query($key);
            if ($value) {
                return (string)$value;
            }
        }

        return null;
    }
}
