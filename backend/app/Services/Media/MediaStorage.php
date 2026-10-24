<?php

namespace App\Services\Media;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Where the pet media files live (M4-05 object storage, Claude's proposal 2026-10-24 —
 * David switches): the private local disk `pet_media` (default) or the private
 * S3-compatible bucket of disk `pet_media_s3` (Hetzner Object Storage), chosen by
 * `media.storage.disk` (env PET_MEDIA_DISK=local|s3).
 *
 * The apps never see the bucket: they keep getting our signed GET /api/media/{media}
 * URLs (authz unchanged); on an object disk the controller answers with a 302 to a
 * short-lived presigned URL (presignedUrl()). Read the driver only through here.
 */
class MediaStorage
{
    /** Key the reachability probe asks for (it never exists — a HEAD that must not error). */
    public const PROBE_KEY = '.petprep-health-probe';

    public const STATUS_CACHE_KEY = 'media_storage:status';

    public function activeDiskName(): string
    {
        return (string) config('media.storage.disk', 'pet_media');
    }

    public function activeDisk(): Filesystem
    {
        return Storage::disk($this->activeDiskName());
    }

    /** `local`, `s3` … of the active disk (config, never a network call). */
    public function driver(?string $disk = null): string
    {
        return (string) config('filesystems.disks.'.($disk ?? $this->activeDiskName()).'.driver', 'local');
    }

    public function usesObjectStorage(): bool
    {
        return $this->driver() === 's3';
    }

    public function objectDiskName(): string
    {
        return (string) config('media.storage.object_disk', 'pet_media_s3');
    }

    public function localDiskName(): string
    {
        return (string) config('media.storage.local_disk', 'pet_media');
    }

    /** Bucket + credentials of the object disk are set (it can be probed / copied to). */
    public function objectStorageConfigured(): bool
    {
        $config = (array) config('filesystems.disks.'.$this->objectDiskName(), []);

        return filled($config['bucket'] ?? null) && filled($config['key'] ?? null) && filled($config['secret'] ?? null);
    }

    /**
     * Presigned GET URL of a file on the active OBJECT disk: valid for
     * `media.storage.presigned_ttl_seconds` (default 5 min), never past $notAfter
     * (our own signed URL's expiry), at least 60 s. The bucket answers with our
     * content type / private caching, Range requests included (AVPlayer).
     */
    public function presignedUrl(string $path, string $mime, ?CarbonImmutable $notAfter = null): string
    {
        $now = CarbonImmutable::now();
        $ttl = (int) config('media.storage.presigned_ttl_seconds', 300);
        if ($notAfter !== null) {
            $ttl = min($ttl, $notAfter->getTimestamp() - $now->getTimestamp());
        }
        $ttl = max(60, $ttl);

        return $this->activeDisk()->temporaryUrl($path, $now->addSeconds($ttl), [
            'ResponseContentType' => $mime,
            'ResponseCacheControl' => 'private, max-age='.$ttl,
            'ResponseContentDisposition' => 'inline',
        ]);
    }

    /**
     * For /admin → System health: the active driver and — when a bucket is configured
     * (also before the switch, so David can test the keys) — whether it answers.
     * Cached 60 s (the widget polls); `fresh` skips the cache.
     *
     * @return array{driver: string, disk: string, bucket: string|null, endpoint: string|null, configured: bool, reachable: bool|null, error: string|null}
     */
    public function status(bool $fresh = false): array
    {
        if (! $fresh) {
            $cached = Cache::get(self::STATUS_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $object = (array) config('filesystems.disks.'.$this->objectDiskName(), []);
        $configured = $this->objectStorageConfigured();
        $reachable = null;
        $error = null;

        if ($configured) {
            try {
                Storage::disk($this->objectDiskName())->exists(self::PROBE_KEY);
                $reachable = true;
            } catch (Throwable $e) {
                $reachable = false;
                // Class + short reason only: never the request (it carries the key id).
                $error = class_basename($e).': '.mb_substr(strtok($e->getMessage(), "\n") ?: '', 0, 160);
            }
        }

        $status = [
            'driver' => $this->driver(),
            'disk' => $this->activeDiskName(),
            'bucket' => filled($object['bucket'] ?? null) ? (string) $object['bucket'] : null,
            'endpoint' => filled($object['endpoint'] ?? null) ? (string) $object['endpoint'] : null,
            'configured' => $configured,
            'reachable' => $reachable,
            'error' => $error,
        ];

        Cache::put(self::STATUS_CACHE_KEY, $status, 60);

        return $status;
    }
}
