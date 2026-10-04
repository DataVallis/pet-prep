<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChildContractController;
use App\Http\Controllers\ChildPetController;
use App\Http\Controllers\FalAiWebhookController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\PairingController;
use App\Http\Controllers\ParentDashboardController;
use App\Http\Controllers\ParentSettingsController;
use App\Http\Controllers\QuietHoursController;
use App\Http\Controllers\RevenueCatWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication Endpoints (public — no Sanctum token required)
|--------------------------------------------------------------------------
*/
Route::post('login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Authenticated Endpoints
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('user', [AuthController::class, 'user']);
    Route::post('logout', [AuthController::class, 'logout']);
});

/*
|--------------------------------------------------------------------------
| Webhook Endpoints (no user auth — fal.ai: ED25519 signature; RevenueCat: bearer secret)
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
        // Generate a 6-digit child pairing PIN (throttled more aggressively).
        // Optional pet_id = the child joins that pet (shared pet, M2-01).
        Route::post('generate-pin', [PairingController::class, 'generatePin'])
            ->middleware('throttle:pairing');

        // Second parent (M2-01): invite code → join the family.
        Route::post('invite-parent', [FamilyController::class, 'invite'])
            ->middleware('throttle:family-invites');
        Route::post('join-family', [FamilyController::class, 'join'])
            ->middleware('throttle:pairing');

        // Dashboard & metrics
        Route::get('dashboard', [ParentDashboardController::class, 'dashboard']);
        Route::get('activities', [ParentDashboardController::class, 'activities']);

        // Controls & interventions
        Route::post('hard-stop', [ParentDashboardController::class, 'toggleHardStop']);

        // Quiet Hours management
        Route::get('quiet-hours', [QuietHoursController::class, 'show']);
        Route::put('quiet-hours', [QuietHoursController::class, 'update']);

        // Family settings (timezone, M1-03)
        Route::put('settings', [ParentSettingsController::class, 'update']);
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

        // Child API (M1-07): state + care actions. Children only (PetPolicy);
        // 423 while hard-stopped / ill / game over, 422 outside game rules.
        Route::get('pet', [ChildPetController::class, 'show']);

        Route::middleware('throttle:child-actions')->group(function () {
            Route::post('pet/feed', [ChildPetController::class, 'feed']);
            Route::post('pet/water', [ChildPetController::class, 'water']);
            Route::post('pet/clean', [ChildPetController::class, 'clean']);
            Route::post('pet/steps', [ChildPetController::class, 'steps']);
            Route::post('contract', [ChildContractController::class, 'store']);
        });
    });
