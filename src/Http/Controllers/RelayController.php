<?php

namespace AjayMahato\Esewa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RelayController extends Controller
{
    public function __invoke(Request $request)
    {
        [$payload, $redirect] = $this->extractPayloadAndRedirect($request);

        if (!$payload) {
            abort(422, 'Missing data query parameter.');
        }

        return response()->view('esewa::relay', [
            'data'     => $payload,
            'redirect' => $redirect,
            'method'   => 'POST',
            'action'   => route('esewa.callback'),
        ]);
    }

    protected function extractPayloadAndRedirect(Request $request): array
    {
        $payload  = $request->query('data');
        $redirect = $request->query('redirect');

        if (!$payload && $redirect && str_contains($redirect, '?data=')) {
            [$cleanRedirect, $fromRedirect] = explode('?data=', $redirect, 2);
            $redirect = $cleanRedirect ?: null;
            $payload  = $fromRedirect ?: null;
        }

        return [$payload ?: null, $redirect ?: null];
    }
}
