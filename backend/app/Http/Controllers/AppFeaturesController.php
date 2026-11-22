<?php

namespace App\Http\Controllers;

use App\Services\Validation\ValidationSignals;
use Illuminate\Http\JsonResponse;

/**
 * Public product switches the app needs BEFORE anyone is signed in (David 2026-11-22). Holds
 * booleans only — nothing about a person, family or device.
 */
class AppFeaturesController extends Controller
{
    /**
     * Switches for the signed-out app. `validation_signals_signup`: may the
     * sign-up screen (e-mail and Google / Apple) show »Kje ste izvedeli za
     * PetPrep?« — true only while the admin switch »Validation signals« is
     * »Everyone« (the server ignores the answer otherwise). Signed-in parents
     * read `validation_signals` from `GET /api/user` instead. Cached ≈ 60 s on
     * the server. Throttled 60 / min per IP.
     *
     * GET /api/app-features
     */
    public function show(ValidationSignals $signals): JsonResponse
    {
        return response()->json([
            'validation_signals_signup' => $signals->signupEnabled(),
        ], 200);
    }
}
