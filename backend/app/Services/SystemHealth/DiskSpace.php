<?php

namespace App\Services\SystemHealth;

/**
 * Free / total bytes of the file system that holds the app's storage (DEPLOYMENT.md D31,
 * incident 2026-11-04). In production storage/ is the `app_storage` Docker volume, which
 * lives under /var/lib/docker on the server's root disk — the same disk as the images,
 * the database volume and the logs — so its numbers are the root disk's numbers.
 * Overridable in tests (freeBytes / totalBytes).
 */
class DiskSpace
{
    /**
     * @return array{free: int, total: int, used_percent: float}|null null when the size is unknown
     */
    public function usage(?string $path = null): ?array
    {
        $path ??= (string) (config('system_health.disk_path') ?: storage_path());
        $free = $this->freeBytes($path);
        $total = $this->totalBytes($path);
        if ($free === null || $total === null || $total <= 0) {
            return null;
        }

        return [
            'free' => (int) $free,
            'total' => (int) $total,
            'used_percent' => round(100 * (1 - $free / $total), 1),
        ];
    }

    protected function freeBytes(string $path): ?float
    {
        $free = @disk_free_space($path);

        return $free === false ? null : (float) $free;
    }

    protected function totalBytes(string $path): ?float
    {
        $total = @disk_total_space($path);

        return $total === false ? null : (float) $total;
    }
}
