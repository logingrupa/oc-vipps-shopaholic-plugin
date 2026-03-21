<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use LoginGrupa\VippsShopaholic\Classes\Helper\VippsCallbackHandler;

/**
 * Vipps Return URL — Customer redirected here after Vipps payment.
 */
Route::get('/vipps/return/{secret_key}', function (Request $obRequest, $sSecretKey) {
    return app(VippsCallbackHandler::class)->handleReturn($obRequest, $sSecretKey);
})->middleware('web');

/**
 * Vipps Webhook — Asynchronous payment event notifications from Vipps.
 * CSRF is excluded because Vipps cannot send a CSRF token.
 */
Route::post('/vipps/webhook', function (Request $obRequest) {
    return app(VippsCallbackHandler::class)->handleWebhook($obRequest);
})->middleware(['web'])
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
