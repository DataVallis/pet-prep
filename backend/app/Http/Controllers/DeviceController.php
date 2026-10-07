<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterDeviceRequest;
use App\Http\Requests\UnregisterDeviceRequest;
use App\Models\User;
use App\Services\Push\PushDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Push devices (M3-02). Shared route for parent and child tokens.
 */
class DeviceController extends Controller
{
    public function __construct(private readonly PushDeviceService $devices) {}

    /**
     * Register (or refresh) this app install for escalation pushes. Called
     * on login and on every app start; the same token moves to the account
     * that registered it last. The install's push language comes from the
     * body field `locale` (en | sl) and is refreshed whenever it is sent;
     * without it the stored language is kept, a new install gets 'sl' (M1-18).
     *
     * POST /api/devices
     */
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $accessToken = $user->currentAccessToken();

        $device = $this->devices->register(
            $user,
            (string) $request->validated('expo_push_token'),
            $request->platform(),
            $request->validated('app_version'),
            $accessToken instanceof PersonalAccessToken ? $accessToken : null,
            $request->pushLocale(),
        );

        return response()->json([
            'device' => [
                'id' => $device->id,
                'platform' => $device->platform->value,
                'app_version' => $device->app_version,
                /** Language of this install's pushes (M1-18): the `locale` field; new installs without it get 'sl'. */
                'locale' => $device->locale,
                'enabled' => $device->disabled_at === null,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            ],
        ], 200);
    }

    /**
     * Stop pushes to this install (logout). Idempotent: an unknown token or
     * one registered by another account also answers 204.
     *
     * POST /api/devices/unregister
     */
    public function unregister(UnregisterDeviceRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->devices->unregister($user, (string) $request->validated('expo_push_token'));

        return response()->noContent();
    }
}
