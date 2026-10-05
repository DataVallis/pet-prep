<?php

namespace App\Services\Media;

use App\Services\FalAiService;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Downloads a fal.ai result to a temporary file (M4-05) — defence in depth
 * for media shown full-screen to a child:
 *  - only https URLs on the fal media allowlist (also after every redirect, max 3);
 *  - size limit from Content-Length, while streaming and on the final file;
 *  - content type sniffed from the bytes (finfo) must be on the kind's list,
 *    and a declared Content-Type must not contradict it;
 *  - timeout; never inside a DB transaction (no external HTTP in transactions).
 *
 * The caller moves the temp file to the pet-media disk and deletes it.
 */
class MediaDownloader
{
    public function __construct(private readonly FalAiService $fal) {}

    /**
     * @param  list<string>  $allowedMimes
     * @return array{path: string, bytes: int, mime: string}
     *
     * @throws MediaDownloadException
     */
    public function download(string $url, int $maxBytes, array $allowedMimes): array
    {
        if (DB::transactionLevel() > (int) config('media.ambient_transaction_level', 0)) {
            throw new LogicException('MediaDownloader must not run inside a database transaction.');
        }

        if (! $this->fal->isAllowedMediaUrl($url)) {
            throw new MediaDownloadException('Source URL is not on the fal media allowlist.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'petmedia-');

        if ($tmp === false) {
            throw new MediaDownloadException('Could not create a temporary file.', permanent: false);
        }

        try {
            $response = Http::withOptions([
                'sink' => $tmp,
                'allow_redirects' => [
                    'max' => 3,
                    'protocols' => ['https'],
                    'on_redirect' => function ($request, $response, UriInterface $uri): void {
                        if (! $this->fal->isAllowedMediaUrl((string) $uri)) {
                            throw new MediaDownloadException('Redirect to a host outside the fal media allowlist.');
                        }
                    },
                ],
                'on_headers' => function (ResponseInterface $response) use ($maxBytes): void {
                    $length = $response->getHeaderLine('Content-Length');

                    if ($length !== '' && (int) $length > $maxBytes) {
                        throw new MediaDownloadException("File too large ({$length} bytes, limit {$maxBytes}).");
                    }
                },
                'progress' => function ($downloadTotal, $downloaded) use ($maxBytes): void {
                    if ($downloaded > $maxBytes) {
                        throw new MediaDownloadException("File too large (over {$maxBytes} bytes).");
                    }
                },
            ])
                ->connectTimeout(10)
                ->timeout((int) config('media.storage.download_timeout_seconds', 60))
                ->get((string) new Uri($url));
        } catch (Throwable $e) {
            @unlink($tmp);

            throw $this->unwrap($e) ?? new MediaDownloadException('Download failed: '.$e->getMessage(), permanent: false);
        }

        try {
            if (! $response->successful()) {
                // 4xx: the file is gone / never existed — retrying cannot help.
                throw new MediaDownloadException('Download answered HTTP '.$response->status(), permanent: $response->clientError());
            }

            clearstatcache(true, $tmp);
            $bytes = (int) filesize($tmp);

            if ($bytes <= 0) {
                throw new MediaDownloadException('Downloaded file is empty.');
            }

            if ($bytes > $maxBytes) {
                throw new MediaDownloadException("File too large ({$bytes} bytes, limit {$maxBytes}).");
            }

            $sniffed = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);

            if (! in_array($sniffed, $allowedMimes, true)) {
                throw new MediaDownloadException("Unexpected content type {$sniffed}.");
            }

            $declared = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

            if ($declared !== '' && $declared !== $sniffed && $declared !== 'application/octet-stream') {
                throw new MediaDownloadException("Declared content type {$declared} does not match the file ({$sniffed}).");
            }
        } catch (MediaDownloadException $e) {
            @unlink($tmp);

            throw $e;
        }

        return ['path' => $tmp, 'bytes' => $bytes, 'mime' => $sniffed];
    }

    /**
     * Guzzle wraps exceptions thrown in on_headers / progress / on_redirect.
     */
    private function unwrap(Throwable $e): ?MediaDownloadException
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof MediaDownloadException) {
                return $current;
            }
        }

        return null;
    }

    public static function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            default => 'bin',
        };
    }
}
