<?php

namespace AjayMahato\Esewa\Http\Controllers;

use AjayMahato\Esewa\Exceptions\EsewaException;
use AjayMahato\Esewa\Models\EsewaPayment;
use AjayMahato\Esewa\PaymentManager;
use AjayMahato\Esewa\Support\RedirectGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Where eSewa (or the relay page) delivers the result of a payment.
 *
 * All the decision-making lives in {@see PaymentManager}; this only decides what
 * the browser or API client sees.
 */
class CallbackController extends Controller
{
    public function __construct(protected PaymentManager $payments) {}

    public function __invoke(Request $request): Response|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        try {
            $payment = $this->resolvePayment($request);
        } catch (EsewaException $e) {
            Log::warning("[esewa] Callback rejected: {$e->getMessage()}");

            return $this->failure($request, $e->getMessage());
        }

        $ok = $payment->status->isComplete();

        $meta = [
            'ok' => $ok,
            'status' => $payment->status->value,
            'message' => $payment->status->label(),
            'ref_id' => $payment->ref_id,
            'transaction_uuid' => $payment->transaction_uuid,
        ];

        if ($this->wantsJson($request)) {
            return response()->json([
                'ok' => $ok,
                'status' => $payment->status->value,
                'message' => $payment->status->label(),
                'payment' => $payment->only([
                    'transaction_uuid', 'product_code', 'total_amount', 'status', 'ref_id', 'verified_at',
                ]),
            ], $ok ? 200 : 202);
        }

        return $this->render($request, $meta, $payment, $ok ? 200 : 202);
    }

    /**
     * Prefer the signed payload; fall back to asking eSewa directly.
     *
     * A signed payload proves what happened. When eSewa sends the customer back
     * without one - which happens on cancellation and on some failure paths -
     * the transaction id alone is not proof of anything, so we go and ask.
     *
     * @throws EsewaException
     */
    protected function resolvePayment(Request $request): EsewaPayment
    {
        if (($encoded = $this->payments->extractPayload($request)) !== null) {
            return $this->payments->handleCallback($encoded);
        }

        $uuid = $this->payments->extractTransactionUuid($request);

        if ($uuid === null) {
            throw new EsewaException('eSewa did not send a payment payload or a transaction id.');
        }

        $payment = $this->payments->reconcileTransaction($uuid);

        if ($payment === null) {
            throw new EsewaException("No eSewa payment record for transaction \"{$uuid}\".");
        }

        return $payment;
    }

    protected function failure(Request $request, string $message): Response|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        if ($this->wantsJson($request)) {
            return response()->json(['ok' => false, 'message' => $message], 422);
        }

        return $this->render($request, [
            'ok' => false,
            'status' => null,
            'message' => $message,
        ], null, 422);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function render(
        Request $request,
        array $meta,
        ?EsewaPayment $payment,
        int $status
    ): Response|\Illuminate\Http\RedirectResponse {
        $redirect = $this->redirectTarget($request, $payment, (bool) ($meta['ok'] ?? false));

        if ($redirect !== null) {
            return redirect()->to($redirect)->with('esewa', [
                'meta' => $meta,
                'payment' => $payment,
            ]);
        }

        return response()->view('esewa::callback-status', [
            'meta' => $meta,
            'payment' => $payment,
        ], $status);
    }

    /**
     * Decide where to send the customer, refusing any target that would take
     * them off this application.
     */
    protected function redirectTarget(Request $request, ?EsewaPayment $payment, bool $ok): ?string
    {
        $guard = RedirectGuard::fromConfig();

        $requested = $request->input('redirect', $request->query('redirect'));

        if (is_string($requested) && ($safe = $guard->safe($requested)) !== null) {
            return $safe;
        }

        if ($payment !== null && ($stored = $payment->redirectUrl($ok)) !== null) {
            return $guard->safe($stored);
        }

        return $guard->safe(config('esewa.redirect.'.($ok ? 'success' : 'failure')));
    }

    protected function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->is('api/*');
    }
}
