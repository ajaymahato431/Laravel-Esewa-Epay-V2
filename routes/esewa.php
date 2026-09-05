<?php

use AjayMahato\Esewa\Http\Controllers\CallbackController;
use AjayMahato\Esewa\Http\Controllers\RelayController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| eSewa routes
|--------------------------------------------------------------------------
|
| Registered only when `esewa.routes.enabled` is true, so an application that
| wants to own its own URLs can switch them off and call the facade directly.
|
| There is deliberately no route that starts a payment. Creating a payment means
| choosing an amount, and only your application knows which amounts a given user
| is allowed to be charged - so `Esewa::pay()` is called from your own
| authenticated controller.
|
*/

Route::group([
    'prefix' => trim((string) config('esewa.routes.prefix', 'esewa'), '/'),
    'middleware' => config('esewa.routes.middleware', ['web']),
], function () {
    // GET is accepted as well as POST so that pointing ESEWA_SUCCESS_URL
    // straight at this route works instead of returning 405.
    Route::match(['GET', 'POST'], '/callback', CallbackController::class)
        ->name('esewa.callback');

    Route::match(['GET', 'POST'], '/relay/{transaction?}', RelayController::class)
        ->name('esewa.relay');
});
