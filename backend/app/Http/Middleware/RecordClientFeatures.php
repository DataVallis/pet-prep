<?php

namespace App\Http\Middleware;

use App\Enums\ClientFeature;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * API group (M5-R14 – R20 follow-up, David 2026-10-24, answer (e)): an app build
 * sends its UI features (ClientFeature values, comma separated) in the header
 * `X-PetPrep-Features` on every request. For an authenticated request the known
 * values are stored on the user (`users.client_features`, only when they changed
 * — no write per request), so the server knows what the app on the child's and
 * the parent's phone can show NOW, not only at pin-login / generate-pin.
 * CareTaskActivationService switches an existing dog's care tasks on only when
 * every caretaker child's and a parent's app declare `care_tasks`.
 *
 * A request without the header (an older build) leaves the stored value alone.
 * Unknown values are dropped (forward compatible). Never fails the request.
 */
class RecordClientFeatures
{
    public const HEADER = 'X-PetPrep-Features';

    /** Longest header we read (10 features of ≤ 40 characters). */
    private const MAX_LENGTH = 500;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $header = $request->headers->get(self::HEADER);
        if (! is_string($header) || strlen($header) > self::MAX_LENGTH) {
            return $response;
        }
        // Resolved by the route's auth:sanctum (shouldUse) — no extra lookup for guests.
        $user = $request->user();
        if (! $user instanceof User) {
            return $response;
        }

        $features = ClientFeature::known(array_map('trim', explode(',', $header)));
        if ($features === (array) ($user->client_features ?? [])) {
            return $response;
        }

        try {
            DB::table('users')->where('id', $user->id)->update([
                'client_features' => json_encode($features, JSON_THROW_ON_ERROR),
                'client_features_at' => now(),
            ]);
            $user->forceFill(['client_features' => $features, 'client_features_at' => now()])->syncOriginal();
        } catch (Throwable $e) {
            Log::warning('RecordClientFeatures: could not store the app features', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return $response;
    }
}
