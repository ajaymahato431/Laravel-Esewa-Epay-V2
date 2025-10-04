<?php

namespace AjayMahato\Esewa\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Illuminate\Http\Response pay(array $params)
 * @method static array statusCheck(string $productCode, string $totalAmount, string $transactionUuid)
 * @method static array verifyCallback(string $base64Json)
 * @method static array buildFormPayload(array $params, array $override = [])
 * @method static string formEndpoint()
 */
class Esewa extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ajaymahato.esewa.proxy';
    }
}
