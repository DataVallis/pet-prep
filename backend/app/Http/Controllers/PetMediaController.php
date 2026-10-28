<?php

namespace App\Http\Controllers;

use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\PetMediaHistory;
use App\Models\PetMediaQualityCheck;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Services\Media\PetMediaService;
use App\Services\Media\SchoolClipDerivativeService;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/media/{media}?expires=…&v=…&signature=… (M4-05 / M4-05b)
 * GET /api/media/history/{history}?… (growth album, M5-R04)
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
 *
 * Object storage (M4-05, PET_MEDIA_DISK=s3): after the same checks the answer is
 * a `302` to a short-lived presigned URL of the private bucket
 * (MediaStorage::presignedUrl(), ≤ PET_MEDIA_S3_URL_TTL_SECONDS and never past our
 * own URL's expiry; HEAD → a presigned HeadObject; < 5 s left → 403) with `Cache-Control: no-store` — players / downloaders follow
 * it (Range is answered by the bucket), the app's media cache keys on OUR URL
 * (path + v), and the redirect itself is never cached past the presigned lifetime.
 * No HEAD to the bucket per request: a missing object is the bucket's 404.
 */
class PetMediaController extends Controller
{
    /**
     * `{pet_id}/{name}.{ext}` or, for a shared look of the free-pet pool (M4-10),
     * `looks/{look_id}/{name}.{ext}` as PetMediaService writes it (derived school clips
     * `…/{name}-loop.mp4` / `-release.mp4` too, SchoolClipDerivativeService::derivedPath); `D`: `$` must
     * not match before a trailing newline.
     */
    public const SAFE_RELATIVE_PATH = '#^(looks/)?[0-9]+/[A-Za-z0-9_-]+(\.[A-Za-z0-9]+)?$#D';

    public const ACCEL_HEADER = 'X-Accel-Redirect';

    /**
     * The client always receives the file (Caddy replaces the empty X-Accel
     * response), so the documented contract stays "binary file" — Scramble infers
     * it from `response()->file()`; the Caddy branch lives in accelRedirect().
     */
    public function show(PetMedia $media, PetMediaService $service): Response
    {
        return $this->serve($media->pet, $media->isServable(), (string) $media->storage_path, (string) $media->mime, $service);
    }

    /**
     * GET /api/media/history/{history}?expires=…&v=…&signature=… (M5-R04)
     *
     * An archived reference image of an earlier life stage (growth album,
     * `pet_media_history`). Same capability and authorization rules as show().
     */
    public function showHistory(PetMediaHistory $history, PetMediaService $service): Response
    {
        return $this->serve($history->pet, true, $history->storage_path, (string) $history->mime, $service);
    }

    /**
     * GET /api/media/{media}/derived/{key}?expires=…&v=…&signature=… (school smooth playback, David 2026-10-28)
     *
     * An ffmpeg-derived school clip (`school_<command>_attention_loop`, `…_success_loop`,
     * `…_release`) of the pet's school phase slot `{media}` — made once per source file
     * (a shared look's file serves every pet of the look). Same capability and authorization
     * rules as show(); 404 while it is missing or stale (the source file changed).
     */
    public function showDerived(PetMedia $media, string $key, PetMediaService $service, SchoolClipDerivativeService $derivatives): Response
    {
        $row = $derivatives->servable($media, $key);

        return $this->serve($media->pet, $row !== null, (string) $row?->storage_path, 'video/mp4', $service);
    }

    /**
     * GET /api/media/quality-sheets/{check}?expires=…&signature=… (quality check, David 2026-10-23)
     *
     * The contact sheet (frames of an AI clip — the AI pet only) of one quality check: fetched
     * by the vision model through fal, opened by a superadmin from /admin. Same capability
     * rules as show(); excluded from the public API docs (internal).
     */
    #[ExcludeRouteFromDocs]
    public function showQualitySheet(PetMediaQualityCheck $check, PetMediaService $service): Response
    {
        $media = $check->media;

        return $this->serve($media?->pet, $media !== null && filled($check->sheet_path), (string) $check->sheet_path, 'image/jpeg', $service);
    }

    /**
     * GET /api/media/candidates/{media}?expires=…&signature=… (QA #363 M3)
     *
     * The CANDIDATE clip of a slot (downloaded, waiting for or failed in the quality check) —
     * issued only to superadmins in Pet → AI media so »Approve anyway« shows what is approved.
     * Same capability rules as show(); 404 without a candidate. Internal (not in the API docs).
     */
    #[ExcludeRouteFromDocs]
    public function showCandidate(PetMedia $media, PetMediaService $service): Response
    {
        return $this->serve($media->pet, filled($media->candidate_path), (string) $media->candidate_path, (string) ($media->candidate_mime ?? 'video/mp4'), $service);
    }

    private function serve(?Pet $pet, bool $servable, string $path, string $mime, PetMediaService $service): Response
    {
        $user = Auth::guard('sanctum')->user();

        if ($user instanceof User && ($pet === null || ! $user->can('listen', $pet))) {
            abort(403);
        }

        $storage = app(MediaStorage::class);
        $expires = (int) request()->query('expires', 0);

        if ($storage->usesObjectStorage()) {
            abort_unless($servable && $path !== '', 404);

            $notAfter = $expires > 0 ? CarbonImmutable::createFromTimestampUTC($expires) : null;
            // HEAD gets a presigned HeadObject (a presigned GET refuses HEAD); < 5 s left → 403.
            $target = $storage->presignedUrl($path, $mime, $notAfter, request()->getMethod());
            abort_if($target === null, 403);

            return redirect()->away($target, 302, [
                'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer',
            ]);
        }

        $disk = $service->disk();

        abort_unless($servable && $path !== '' && $disk->exists($path), 404);

        $maxAge = max(60, $expires - now()->getTimestamp());
        $headers = [
            'Content-Type' => $mime,
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
