<?php

namespace App\Services\Media;

use Aws\Exception\AwsException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
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

    /** Below this many seconds of our own URL's lifetime no presigned URL is issued (403). */
    public const MIN_PRESIGN_SECONDS = 5;

    /**
     * Presigned URL of a file on the active OBJECT disk: valid for
     * `media.storage.presigned_ttl_seconds` (default 5 min) but NEVER past $notAfter
     * (our own signed URL's expiry — QA #374 m1); null when less than
     * MIN_PRESIGN_SECONDS of it are left (the caller answers 403). `GET` signs a
     * GetObject (our content type / private caching / inline; Range works), `HEAD`
     * signs a HeadObject — a presigned GET does not accept HEAD (QA #374 m2).
     */
    public function presignedUrl(string $path, string $mime, ?CarbonImmutable $notAfter = null, string $method = 'GET'): ?string
    {
        $now = CarbonImmutable::now();
        $ttl = (int) config('media.storage.presigned_ttl_seconds', 300);
        if ($notAfter !== null) {
            $remaining = $notAfter->getTimestamp() - $now->getTimestamp();
            if ($remaining < self::MIN_PRESIGN_SECONDS) {
                return null;
            }
            $ttl = min($ttl, $remaining);
        }
        $ttl = max(1, $ttl);
        $expires = $now->addSeconds($ttl);
        $disk = $this->activeDisk();

        if (strtoupper($method) === 'HEAD' && $disk instanceof AwsS3V3Adapter) {
            $root = trim((string) config('filesystems.disks.'.$this->activeDiskName().'.root', ''), '/');
            $command = $disk->getClient()->getCommand('HeadObject', [
                'Bucket' => (string) config('filesystems.disks.'.$this->activeDiskName().'.bucket'),
                'Key' => ltrim($root.'/'.ltrim($path, '/'), '/'),
            ]);

            return (string) $disk->getClient()->createPresignedRequest($command, $expires)->getUri();
        }

        return $disk->temporaryUrl($path, $expires, [
            'ResponseContentType' => $mime,
            'ResponseCacheControl' => 'private, max-age='.$ttl,
            'ResponseContentDisposition' => 'inline',
        ]);
    }

    /**
     * For /admin → System health: the active driver and — when a bucket is configured
     * (also before the switch, so David can test the keys) — whether it answers.
     * Cached 60 s (the widget polls); `fresh` skips the cache, `write` (media:move-to-object-storage)
     * also proves the keys may write: put + read + delete of PROBE_KEY.
     *
     * @return array{driver: string, disk: string, bucket: string|null, endpoint: string|null, configured: bool, reachable: bool|null, error: string|null}
     */
    public function status(bool $fresh = false, bool $write = false): array
    {
        if (! $fresh && ! $write) {
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
            [$reachable, $error] = $this->probe($write);
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

    /**
     * QA #374 M1: a real answer from the bucket. On an S3 disk: HeadBucket (403 / 404 /
     * connection errors all mean NOT reachable — Flysystem's fileExists() swallows them),
     * with `write` also put / get / delete of PROBE_KEY. Any other disk (tests): exists().
     *
     * @return array{0: bool, 1: string|null}
     */
    private function probe(bool $write): array
    {
        $name = $this->objectDiskName();

        try {
            $disk = Storage::disk($name);
            if ($disk instanceof AwsS3V3Adapter) {
                $client = $disk->getClient();
                $bucket = (string) config("filesystems.disks.{$name}.bucket");
                $client->headBucket(['Bucket' => $bucket]);
                if ($write) {
                    $root = trim((string) config("filesystems.disks.{$name}.root", ''), '/');
                    $key = ltrim($root.'/'.self::PROBE_KEY, '/');
                    $body = 'petprep-probe-'.bin2hex(random_bytes(8));
                    $client->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => $body]);
                    $read = (string) $client->getObject(['Bucket' => $bucket, 'Key' => $key])['Body'];
                    $client->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
                    if ($read !== $body) {
                        return [false, 'probe object read back different bytes'];
                    }
                }
            } else {
                $disk->exists(self::PROBE_KEY);
                if ($write) {
                    $disk->put(self::PROBE_KEY, 'probe');
                    $disk->delete(self::PROBE_KEY);
                }
            }

            return [true, null];
        } catch (AwsException $e) {
            // Status + AWS error code only: never the request (it carries the key id).
            return [false, trim(sprintf('S3 %s %s', $e->getStatusCode() ?? 'no-response', $e->getAwsErrorCode() ?? class_basename($e->getPrevious() ?? $e)))];
        } catch (Throwable $e) {
            return [false, class_basename($e).': '.mb_substr(strtok($e->getMessage(), "\n") ?: '', 0, 160)];
        }
    }
}
