<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChildAuthController;
use App\Http\Controllers\ChildContractController;
use App\Http\Controllers\ChildPetController;
use App\Http\Controllers\ChildProfileController;
use App\Http\Controllers\ChildReportController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\FalAiWebhookController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\PairingController;
use App\Http\Controllers\ParentDashboardController;
use App\Http\Controllers\ParentSettingsController;
use App\Http\Controllers\PetMediaController;
use App\Http\Controllers\QuietHoursController;
use App\Http\Controllers\RevenueCatWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication Endpoints (public — no Sanctum token required)
|--------------------------------------------------------------------------
*/
Route::post('login', [AuthController::class, 'login']);

// M2-10a: parent self-registration (e-mail + password) → parent + own family
// + parent token. Per-IP throttle (5/min, 20/h).
Route::post('register', [AuthController::class, 'register'])
    ->middleware('throttle:register');

// M2-02: a child signs in with the one-time PIN their parent generated for
// their profile (no e-mail / password). Hard per-IP throttle on the route +
// failed-attempt lockouts (per IP and global) in ChildPinLoginService.
Route::post('child/pin-login', [ChildAuthController::class, 'pinLogin'])
    ->middleware('throttle:pin-login');

/*
|--------------------------------------------------------------------------
| Authenticated Endpoints
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('user', [AuthController::class, 'user']);
    Route::post('logout', [AuthController::class, 'logout']);

    // M3-02: push devices (parent or child token). Unregister takes the Expo
    // token in the body (PR #35 review: never in a URL / access log).
    // Own bucket (hotfix 2026-10-06): a looping client (iOS token echo, app
    // 1.24.4) must never exhaust the shared `api` budget for the pet state.
    Route::middleware('throttle:devices')->group(function () {
        Route::post('devices', [DeviceController::class, 'store']);
        Route::post('devices/unregister', [DeviceController::class, 'unregister']);
    });
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
| Pet media (M4-05) — signed, expiring URLs from the pet state; no bearer needed
|--------------------------------------------------------------------------
| Players (expo-video / expo-image) fetch these without headers. The signature
| is relative (survives the reverse proxy); a request with a Sanctum token is
| additionally checked against PetPolicy::listen in the controller.
*/
Route::get('media/{media}', [PetMediaController::class, 'show'])
    ->middleware(['signed:relative', 'throttle:media'])
    ->name('media.show');

/*
|--------------------------------------------------------------------------
| Parent Endpoints — token ability `parent` (M2-03; legacy '*' tokens pass)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'ability:parent', 'throttle:api'])
    ->prefix('parent')
    ->group(function () {
        // Child profiles without e-mail (M2-02): create, sign out devices.
        Route::post('children', [ChildProfileController::class, 'store'])
            ->middleware('throttle:pairing');
        Route::delete('children/{child}/tokens', [ChildProfileController::class, 'revokeTokens']);
        // M2-08: delete a child profile (password + confirm), irreversible.
        // Wrong passwords are limited in AccountDeletionService (5 / 15 min per
        // user and scope; only failures count).
        Route::delete('children/{child}', [ChildProfileController::class, 'destroy']);

        // M2-08: the parent's own account — delete (last parent → whole
        // family) and GDPR data export (JSON download).
        Route::post('account/delete', [AccountController::class, 'destroy']);
        Route::get('account/export', [AccountController::class, 'export'])
            ->middleware('throttle:account-export');
        // Care Score / routine report of one child (M2-05 / M2-06).
        Route::get('children/{child}/report', [ChildReportController::class, 'show']);

        // Generate a 6-digit one-time child PIN (throttled more aggressively).
        // child_id (M2-02) = PIN login for that child profile; without it the
        // deprecated flow (child signed in with e-mail → POST /api/child/pair).
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
| Child Endpoints — token ability `child` (M2-03; legacy '*' tokens pass)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'ability:child', 'throttle:api'])
    ->prefix('child')
    ->group(function () {
        // Deprecated (M2-02): pair a child signed in with e-mail via PIN.
        // New child profiles use POST /api/child/pin-login instead.
        Route::post('pair', [PairingController::class, 'pairChild'])
            ->middleware('throttle:pairing');

        // Child API (M1-07): state + care actions. Children only (PetPolicy);
        // 423 while hard-stopped / ill / game over, 422 outside game rules.
        Route::get('pet', [ChildPetController::class, 'show']);

        Route::middleware('throttle:child-actions')->group(function () {
            Route::post('pet/feed', [ChildPetController::class, 'feed']);
            Route::post('pet/water', [ChildPetController::class, 'water']);
            Route::post('pet/clean', [ChildPetController::class, 'clean']);
            // M5-R02 behaviour events: puppy take-out, tidy up after chewing.
            Route::post('pet/take-out', [ChildPetController::class, 'takeOut']);
            Route::post('pet/resolve-chewing', [ChildPetController::class, 'resolveChewing']);
            Route::post('pet/steps', [ChildPetController::class, 'steps']);
            Route::post('contract', [ChildContractController::class, 'store']);
        });
    });
