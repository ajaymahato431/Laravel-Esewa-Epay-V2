<?php

namespace AjayMahato\Esewa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RelayController extends Controller
{
    public function __invoke(Request $request)
    {
        [$payload, $redirect] = $this->extractPayloadAndRedirect($request);
        $transaction = $request->route('transaction');

        if (!$payload && !$transaction) {
            return response()->view('esewa::callback-status', [
                'meta' => [
                    'ok'      => false,
                    'message' => 'We could not verify your payment because eSewa has not sent the signed payload yet.',
                ],
                'payment'     => null,
                'raw'         => null,
                'statusCode'  => 422,
                'redirectUrl' => $redirect,
            ], 422);
        }

        return response()->view('esewa::relay', [
            'data'             => $payload,
            'redirect'         => $redirect,
            'transactionUuid'  => $transaction,
            'method'           => 'POST',
            'action'           => route('esewa.callback'),
        ]);
    }

    protected function extractPayloadAndRedirect(Request $request): array
    {
        $payload  = $request->input('data', $request->query('data'));
        $redirect = $request->input('redirect', $request->query('redirect'));

        if (!$payload && $redirect && str_contains($redirect, '?data=')) {
            [$cleanRedirect, $fromRedirect] = explode('?data=', $redirect, 2);
            $redirect = $cleanRedirect ?: null;
            $payload  = $fromRedirect ?: null;
        }

        return [$payload ?: null, $redirect ?: null];
    }
}
