<?php

use App\Http\Controllers\FalAiWebhookController;
use App\Http\Controllers\PairingController;
use App\Http\Controllers\ParentDashboardController;
use App\Http\Controllers\QuietHoursController;
use App\Http\Controllers\RevenueCatWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| Webhook Endpoints (unauthenticated — validated via webhook secret)
|--------------------------------------------------------------------------
*/
Route::post('webhooks/fal-ai', [FalAiWebhookController::class, 'handle'])
    ->middleware('throttle:api');

Route::post('webhooks/revenuecat', [RevenueCatWebhookController::class, 'handle'])
    ->middleware('throttle:api');

/*
|--------------------------------------------------------------------------
| Parent Endpoints
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'throttle:api'])
    ->prefix('parent')
    ->group(function () {
        // Generate a 6-digit pairing PIN (throttled more aggressively)
        Route::post('generate-pin', [PairingController::class, 'generatePin'])
            ->middleware('throttle:pairing');

        // Dashboard & metrics
        Route::get('dashboard', [ParentDashboardController::class, 'dashboard']);
        Route::get('activities', [ParentDashboardController::class, 'activities']);

        // Controls & interventions
        Route::post('hard-stop', [ParentDashboardController::class, 'toggleHardStop']);

        // Quiet Hours management
        Route::get('quiet-hours', [QuietHoursController::class, 'show']);
        Route::put('quiet-hours', [QuietHoursController::class, 'update']);
    });

/*
|--------------------------------------------------------------------------
| Child Endpoints
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'throttle:api'])
    ->prefix('child')
    ->group(function () {
        // Pair child to parent via PIN (throttled more aggressively for anti-abuse)
        Route::post('pair', [PairingController::class, 'pairChild'])
            ->middleware('throttle:pairing');
    });
