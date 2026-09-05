<?php

namespace AjayMahato\Esewa\Facades;

use AjayMahato\Esewa\Esewa as EsewaService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Illuminate\Http\Response pay(array $params)
 * @method static array{payment: \AjayMahato\Esewa\Models\EsewaPayment, endpoint: string, payload: array<string, string>} prepare(array $params)
 * @method static \AjayMahato\Esewa\Models\EsewaPayment handleCallback(\Illuminate\Http\Request|string $data)
 * @method static \AjayMahato\Esewa\Models\EsewaPayment reconcile(\AjayMahato\Esewa\Models\EsewaPayment $payment)
 * @method static \AjayMahato\Esewa\Models\EsewaPayment|null reconcileTransaction(string $transactionUuid)
 * @method static \AjayMahato\Esewa\Models\EsewaPayment|null find(string $transactionUuid)
 * @method static array<string, mixed> verifyCallback(string $base64Json)
 * @method static array<string, mixed> statusCheck(string $productCode, mixed $totalAmount, string $transactionUuid)
 * @method static string buildRequestSignature(mixed $totalAmount, string $transactionUuid)
 * @method static string buildSignatureForFields(array $fields, string $signedFieldNamesCsv)
 * @method static string signedCallbackPayload(array $fields = [])
 * @method static string formEndpoint()
 * @method static string statusEndpoint()
 * @method static string relayUrl(?string $transactionUuid = null)
 * @method static string mode()
 * @method static bool isProduction()
 * @method static string productCode()
 * @method static \AjayMahato\Esewa\EsewaClient client()
 * @method static \AjayMahato\Esewa\PaymentManager payments()
 *
 * @see EsewaService
 */
class Esewa extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EsewaService::class;
    }
}
