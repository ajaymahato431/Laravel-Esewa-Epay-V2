<?php

namespace AjayMahato\Esewa;

use Illuminate\Http\Response;
use Illuminate\Support\Str;
use AjayMahato\Esewa\Models\EsewaPayment;

class PaymentManager
{
    public function __construct(
        protected EsewaClient $client,
        protected array $config
    ) {}

    /** Creates DB row, builds payload, returns auto-submit form Response */
    public function pay(array $params): Response
    {
        $amount = (int)($params['amount'] ?? 0);
        $tax    = (int)($params['tax_amount'] ?? 0);
        $svc    = (int)($params['product_service_charge'] ?? 0);
        $del    = (int)($params['product_delivery_charge'] ?? 0);
        $total  = (int)($params['total_amount'] ?? ($amount + $tax + $svc + $del));
        $uuid   = (string)($params['transaction_uuid'] ?? now()->format('ymd-His').'-'.Str::upper(Str::random(4)));

        $meta = $params['meta'] ?? [];
        $meta['success_redirect'] = $params['success_url'] ?? ($meta['success_redirect'] ?? ($this->config['success_url'] ?? null));
        $meta['failure_redirect'] = $params['failure_url'] ?? ($meta['failure_redirect'] ?? ($this->config['failure_url'] ?? null));

        $payment = EsewaPayment::create([
            'transaction_uuid' => $uuid,
            'product_code'     => $this->config['product_code'],
            'amount'           => $amount,
            'tax_amount'       => $tax,
            'service_charge'   => $svc,
            'delivery_charge'  => $del,
            'total_amount'     => $total,
            'meta'             => $meta,
        ]);

        $relay = $this->client->relayUrl($uuid);

        $payload = $this->client->buildFormPayload([
            'amount' => $amount,
            'tax_amount' => $tax,
            'product_service_charge' => $svc,
            'product_delivery_charge' => $del,
            'total_amount' => $total,
            'transaction_uuid' => $uuid,
        ], [
            'success_url' => $relay,
            'failure_url' => $relay,
        ]);

        $html = view('esewa::form', [
            'endpoint' => $this->client->formEndpoint(),
            'payload'  => $payload,
        ])->render();

        return new Response($html);
    }
}
