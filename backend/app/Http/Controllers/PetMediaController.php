<?php

namespace App\Http\Controllers;

use App\Models\PetMedia;
use App\Models\User;
use App\Services\Media\PetMediaService;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /api/media/{media}?expires=…&v=…&signature=… (M4-05)
 *
 * Serves a stored pet image / state video from the private pet-media disk.
 * The URL is a short-lived capability (relative signed route, `signed:relative`
 * → 403 when expired or tampered), issued only inside authorized responses
 * (child state, parent dashboard, pairing, the private pet channel) — players
 * such as expo-video / expo-image need no Authorization header. A request that
 * DOES carry a Sanctum token must belong to someone who may watch the pet
 * (PetPolicy::listen), otherwise 403. Range requests are supported
 * (BinaryFileResponse), which iOS AVPlayer needs for mp4.
 */
class PetMediaController extends Controller
{
    public function show(PetMedia $media, PetMediaService $service): BinaryFileResponse
    {
        $user = Auth::guard('sanctum')->user();

        if ($user instanceof User && ($media->pet === null || ! $user->can('listen', $media->pet))) {
            abort(403);
        }

        $disk = $service->disk();

        abort_unless($media->isServable() && $disk->exists((string) $media->storage_path), 404);

        $maxAge = max(60, (int) request()->query('expires', 0) - now()->getTimestamp());

        return response()->file($disk->path((string) $media->storage_path), [
            'Content-Type' => (string) $media->mime,
            'Cache-Control' => 'private, max-age='.min($maxAge, 5400),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
