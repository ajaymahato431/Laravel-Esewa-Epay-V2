<?php

namespace AjayMahato\Esewa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RelayController extends Controller
{
    public function __invoke(Request $request)
    {
        $payload = $request->query('data');

        if (!$payload) {
            abort(422, 'Missing data query parameter.');
        }

        return response()->view('esewa::relay', [
            'data'     => $payload,
            'redirect' => $this->resolveRedirect($request),
            'method'   => 'POST',
            'action'   => route('esewa.callback'),
        ]);
    }

    protected function resolveRedirect(Request $request): ?string
    {
        $redirect = $request->query('redirect');

        return $redirect ?: null;
    }
}
