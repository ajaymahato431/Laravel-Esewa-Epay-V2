<?php

use Illuminate\Support\Facades\Route;
use AjayMahato\Esewa\Http\Controllers\StartController;
use AjayMahato\Esewa\Http\Controllers\CallbackController;

Route::group([
    'prefix' => config('esewa.route_prefix', ''),
    'middleware' => config('esewa.middleware', ['web']),
], function () {
    Route::post('/esewa/pay', [StartController::class, 'start'])->name('esewa.pay');
    Route::post('/esewa/callback', [CallbackController::class, 'handle'])->name('esewa.callback');
});
