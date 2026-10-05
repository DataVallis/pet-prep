<?php

namespace App\Http\Controllers;

use App\Models\PetMedia;
use App\Models\User;
use App\Services\Media\PetMediaService;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/media/{media}?expires=…&v=…&signature=… (M4-05 / M4-05b)
 *
 * Serves a stored pet image / state video from the private pet-media disk.
 * The URL is a short-lived capability (relative signed route, `signed:relative`
 * → 403 when expired or tampered), issued only inside authorized responses
 * (child state, parent dashboard, pairing, the private pet channel) — players
 * such as expo-video / expo-image need no Authorization header. A request that
 * DOES carry a Sanctum token must belong to someone who may watch the pet
 * (PetPolicy::listen), otherwise 403. Range requests are supported, which iOS
 * AVPlayer needs for mp4.
 *
 * Who sends the bytes (`media.storage.serve_via`, env PET_MEDIA_SERVE_VIA):
 *  - `php`   (default, local dev / tests): BinaryFileResponse from PHP.
 *  - `caddy` (production): after the same checks PHP answers with an empty body
 *    and the internal header `X-Accel-Redirect: /{relative path}`. Caddy
 *    (deployment/Caddyfile, `handle_response`) intercepts it and serves the file
 *    from its read-only mount of the storage volume — Range, ETag, the headers
 *    below — so a long video download never occupies a PHP-FPM worker. The
 *    header value is built only from the DB path, which must match
 *    SAFE_RELATIVE_PATH; anything else falls back to PHP streaming.
 */
class PetMediaController extends Controller
{
    /** `{pet_id}/{name}.{ext}` as PetMediaService writes it; `D`: `$` must not match before a trailing newline. */
    public const SAFE_RELATIVE_PATH = '#^[0-9]+/[A-Za-z0-9_-]+(\.[A-Za-z0-9]+)?$#D';

    public const ACCEL_HEADER = 'X-Accel-Redirect';

    /**
     * The client always receives the file (Caddy replaces the empty X-Accel
     * response), so the documented contract stays "binary file" — Scramble infers
     * it from `response()->file()`; the Caddy branch lives in accelRedirect().
     */
    public function show(PetMedia $media, PetMediaService $service): Response
    {
        $user = Auth::guard('sanctum')->user();

        if ($user instanceof User && ($media->pet === null || ! $user->can('listen', $media->pet))) {
            abort(403);
        }

        $disk = $service->disk();
        $path = (string) $media->storage_path;

        abort_unless($media->isServable() && $disk->exists($path), 404);

        $maxAge = max(60, (int) request()->query('expires', 0) - now()->getTimestamp());
        $headers = [
            'Content-Type' => (string) $media->mime,
            'Cache-Control' => 'private, max-age='.min($maxAge, 5400),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ];

        if ($this->viaCaddy($path)) {
            return $this->accelRedirect($path, $headers);
        }

        $file = response()->file($disk->path($path), $headers);
        // response()->file() marks the response public; the URL is a capability → private.
        $file->setPrivate();

        return $file;
    }

    /**
     * Empty 200 + internal header; Caddy's handle_response serves the file.
     *
     * @param  array<string, string>  $headers
     */
    private function accelRedirect(string $path, array $headers): Response
    {
        return response('', 200, $headers + [self::ACCEL_HEADER => '/'.$path]);
    }

    private function viaCaddy(string $path): bool
    {
        if (config('media.storage.serve_via') !== 'caddy') {
            return false;
        }

        // Caddy's media root is the pet_media disk root (storage/app/pet-media on the
        // app_storage volume). Another disk (S3 later, a different local root) → PHP.
        $disk = (array) config('filesystems.disks.'.config('media.storage.disk', 'pet_media'), []);
        if (($disk['driver'] ?? null) !== 'local' || ($disk['root'] ?? null) !== storage_path('app/pet-media')) {
            return false;
        }

        if (preg_match(self::SAFE_RELATIVE_PATH, $path) !== 1) {
            logger()->warning('Pet media path not eligible for X-Accel-Redirect, streaming via PHP.', ['path' => $path]);

            return false;
        }

        return true;
    }
}
