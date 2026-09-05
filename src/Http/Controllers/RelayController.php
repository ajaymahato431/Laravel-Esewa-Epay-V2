<?php

namespace AjayMahato\Esewa\Http\Controllers;

use AjayMahato\Esewa\PaymentManager;
use AjayMahato\Esewa\Support\RedirectGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * The page eSewa returns the customer to.
 *
 * eSewa redirects with a GET, but the callback needs a CSRF-protected POST to
 * change state. This shows a brief "finishing up" page that immediately posts
 * the signed payload to the callback route.
 *
 * The customer is never sent straight to a merchant success page: the payload is
 * verified first, so nobody sees a thank-you screen for a payment that did not
 * complete.
 */
class RelayController extends Controller
{
    public function __construct(protected PaymentManager $payments) {}

    public function __invoke(Request $request): Response
    {
        [$payload, $redirect] = $this->extract($request);

        $transaction = $this->payments->extractTransactionUuid($request);

        if ($payload === null && $transaction === null) {
            return response()->view('esewa::callback-status', [
                'meta' => [
                    'ok' => false,
                    'status' => null,
                    'message' => 'eSewa did not send a payment result, so this payment cannot be confirmed.',
                ],
                'payment' => null,
            ], 422);
        }

        return response()->view('esewa::relay', [
            'action' => route('esewa.callback'),
            'data' => $payload,
            'redirect' => $redirect,
            'transactionUuid' => $transaction,
        ]);
    }

    /**
     * Pull the payload and redirect target out of the query string.
     *
     * eSewa appends `?data=...` to whatever URL it was given, so when a redirect
     * target already carried a query string the result is a single mangled
     * parameter such as `redirect=/orders/9?data=eyJ0...`. Unpick that here.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function extract(Request $request): array
    {
        $payload = $this->payments->extractPayload($request);
        $redirect = $request->input('redirect', $request->query('redirect'));
        $redirect = is_string($redirect) ? $redirect : null;

        if ($payload === null && $redirect !== null && str_contains($redirect, '?data=')) {
            [$cleanRedirect, $embedded] = explode('?data=', $redirect, 2);

            $redirect = $cleanRedirect !== '' ? $cleanRedirect : null;
            $payload = $embedded !== '' ? $embedded : null;
        }

        return [$payload, RedirectGuard::fromConfig()->safe($redirect)];
    }
}
