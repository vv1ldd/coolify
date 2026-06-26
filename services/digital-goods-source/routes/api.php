<?php

use App\Http\Controllers\Api\KernelController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/status', [KernelController::class, 'status']);
Route::get('/v1/ledger/head', [KernelController::class, 'ledgerHead']);
Route::get('/v1/ledger/events', [KernelController::class, 'ledgerEvents']);

Route::prefix('v1')
    ->middleware(['kernel.auth'])
    ->group(function () {
        Route::get('providers/{provider}/unified-catalog', [KernelController::class, 'unifiedCatalog']);
        Route::get('providers/{provider}/exchange-rates', [KernelController::class, 'exchangeRates']);
        Route::get('providers/{provider}/check-availability/{sku}', [KernelController::class, 'checkAvailability']);
        Route::post('providers/{provider}/check-availability', [KernelController::class, 'checkAvailabilityFromPayload']);
        Route::get('providers/{provider}/orders/{reference}/normalized-cards', [KernelController::class, 'normalizedCards']);

        Route::middleware(['kernel.financial.signature'])->group(function () {
            Route::post('providers/{provider}/order', [KernelController::class, 'placeOrder']);
            Route::get('partners', [KernelController::class, 'listPartners']);
            Route::post('partners/sync', [KernelController::class, 'syncPartner']);
            Route::post('partners/grant-credit', [KernelController::class, 'grantCredit']);
            Route::post('partners/top-up', [KernelController::class, 'topUp']);
            Route::get('partners/{externalId}', [KernelController::class, 'showPartner']);
        });
    });
