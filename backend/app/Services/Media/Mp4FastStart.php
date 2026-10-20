<?php

namespace App\Services\Media;

/**
 * Moves an MP4's `moov` box (the index) in front of `mdat` ("faststart", like
 * `qt-faststart` / `ffmpeg -movflags +faststart -c copy`) — pure PHP, the
 * production image has no ffmpeg.
 *
 * Why (incident 2026-10-20): fal started returning Kling videos with `moov` at
 * the END of the file. Browsers (admin) cope, but the app's player (expo-video
 * with caching on iOS) could not open them — "This video can't be played right
 * now" for every state video of a new pet. Older videos had `moov` first.
 *
 * Only top-level boxes are reordered; every chunk offset (`stco` / `co64`) in
 * the moved `moov` grows by the moov size, because all media data now sits that
 * many bytes later. Anything unusual (compressed `cmov`, a 32-bit offset that
 * would overflow, broken box sizes) leaves the file untouched — never a worse
 * file than fal's.
 */
class Mp4FastStart
{
    public const RESULT_FIXED = 'fixed';

    public const RESULT_ALREADY = 'already';

    public const RESULT_SKIPPED = 'skipped';

    /** Containers on the path moov → trak → mdia → minf → stbl (where the offset tables live). */
    private const CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl'];

    /**
     * Rewrite $path in place if its moov follows its mdat.
     *
     * @return self::RESULT_*
     */
    public function process(string $path): string
    {
        $layout = $this->fileLayout($path);
        if ($layout === null) {
            return self::RESULT_SKIPPED;
        }
        if ($layout === false) {
            return self::RESULT_ALREADY;
        }

        $data = @file_get_contents($path);
        if ($data === false) {
            return self::RESULT_SKIPPED;
        }

        $result = $this->convert($data);
        if ($result === null) {
            return self::RESULT_SKIPPED;
        }
        if ($result === $data) {
            return self::RESULT_ALREADY;
        }

        $tmp = $path.'.faststart';
        if (@file_put_contents($tmp, $result) !== strlen($result)) {
            @unlink($tmp);

            return self::RESULT_SKIPPED;
        }
        @chmod($tmp, @fileperms($path) & 0777 ?: 0600);

        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            return self::RESULT_SKIPPED;
        }

        return self::RESULT_FIXED;
    }

    /**
     * Whether the file's moov comes after its first mdat, read from the top-level
     * box headers only (a few seeks, no full read — cheap enough for a scheduled
     * scan of every stored video). Null: not a readable MP4.
     */
    public function fileLayout(string $path): ?bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $end = (int) @filesize($path);
            $offset = 0;
            $sawMdat = false;
            while ($offset + 8 <= $end) {
                if (fseek($handle, $offset) !== 0) {
                    return null;
                }
                $head = fread($handle, 16);
                if ($head === false || strlen($head) < 8) {
                    return null;
                }
                $size = unpack('N', substr($head, 0, 4))[1];
                $type = substr($head, 4, 4);
                if ($size === 1) {
                    if (strlen($head) < 16) {
                        return null;
                    }
                    $size = unpack('J', substr($head, 8, 8))[1];
                } elseif ($size === 0) {
                    $size = $end - $offset;
                }
                if ($size < 8 || $offset + $size > $end) {
                    return null;
                }
                if ($type === 'moov') {
                    return $sawMdat;
                }
                if ($type === 'mdat') {
                    $sawMdat = true;
                }
                $offset += $size;
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /** Whether the file's moov comes after its mdat (null: not a readable MP4). */
    public function needsFastStart(string $data): ?bool
    {
        $boxes = $this->topLevel($data);
        if ($boxes === null) {
            return null;
        }
        $order = array_column($boxes, 'type');
        $moov = array_search('moov', $order, true);
        $mdat = array_search('mdat', $order, true);
        if ($moov === false || $mdat === false) {
            return null;
        }

        return $moov > $mdat;
    }

    /**
     * The faststart bytes; the same string when moov is already first; null
     * when the file cannot be converted safely.
     */
    public function convert(string $data): ?string
    {
        $boxes = $this->topLevel($data);
        if ($boxes === null) {
            return null;
        }

        $moovIndex = null;
        $firstMdat = null;
        foreach ($boxes as $i => $box) {
            if ($box['type'] === 'mdat' && $firstMdat === null) {
                $firstMdat = $i;
            }
            if ($box['type'] === 'moov') {
                if ($moovIndex !== null) {
                    return null; // two moov boxes — not ours to judge
                }
                $moovIndex = $i;
            }
        }

        if ($moovIndex === null || $firstMdat === null) {
            return null;
        }
        if ($moovIndex < $firstMdat) {
            return $data;
        }

        $moovBox = $boxes[$moovIndex];
        $moov = substr($data, $moovBox['offset'], $moovBox['size']);
        $patched = $this->shiftOffsets($moov, $moovBox['size']);
        if ($patched === null) {
            return null;
        }

        // ftyp (and any other boxes before the media data) stay first, then moov, then the rest.
        $out = '';
        foreach ($boxes as $i => $box) {
            if ($i === $firstMdat) {
                $out .= $patched;
            }
            if ($i === $moovIndex) {
                continue;
            }
            $out .= substr($data, $box['offset'], $box['size']);
        }

        return strlen($out) === strlen($data) ? $out : null;
    }

    /**
     * @return list<array{type: string, offset: int, size: int, header: int}>|null
     */
    private function topLevel(string $data): ?array
    {
        return $this->children($data, 0, strlen($data));
    }

    /**
     * @return list<array{type: string, offset: int, size: int, header: int}>|null
     */
    private function children(string $data, int $start, int $end): ?array
    {
        $boxes = [];
        $i = $start;
        while ($i < $end) {
            if ($i + 8 > $end) {
                return null;
            }
            $size = unpack('N', substr($data, $i, 4))[1];
            $type = substr($data, $i + 4, 4);
            $header = 8;
            if ($size === 1) {
                if ($i + 16 > $end) {
                    return null;
                }
                $size = unpack('J', substr($data, $i + 8, 8))[1];
                $header = 16;
            } elseif ($size === 0) {
                $size = $end - $i;
            }
            if ($size < $header || $i + $size > $end) {
                return null;
            }
            $boxes[] = ['type' => $type, 'offset' => $i, 'size' => $size, 'header' => $header];
            $i += $size;
        }

        return $boxes;
    }

    /** Add $delta to every stco / co64 entry inside the moov bytes. */
    private function shiftOffsets(string $moov, int $delta): ?string
    {
        $ok = true;
        $walk = function (int $start, int $end) use (&$walk, &$moov, $delta, &$ok): void {
            $boxes = $this->children($moov, $start, $end);
            if ($boxes === null) {
                $ok = false;

                return;
            }
            foreach ($boxes as $box) {
                $body = $box['offset'] + $box['header'];
                $boxEnd = $box['offset'] + $box['size'];
                if ($box['type'] === 'cmov') {
                    $ok = false;

                    return;
                }
                if ($box['type'] === 'stco' || $box['type'] === 'co64') {
                    $wide = $box['type'] === 'co64';
                    $count = unpack('N', substr($moov, $body + 4, 4))[1];
                    $width = $wide ? 8 : 4;
                    if ($body + 8 + $count * $width > $boxEnd) {
                        $ok = false;

                        return;
                    }
                    for ($n = 0; $n < $count; $n++) {
                        $at = $body + 8 + $n * $width;
                        $value = unpack($wide ? 'J' : 'N', substr($moov, $at, $width))[1] + $delta;
                        if (! $wide && $value > 0xFFFFFFFF) {
                            $ok = false;

                            return;
                        }
                        $moov = substr_replace($moov, pack($wide ? 'J' : 'N', $value), $at, $width);
                    }
                }
                if (in_array($box['type'], self::CONTAINERS, true)) {
                    $walk($body, $boxEnd);
                    if (! $ok) {
                        return;
                    }
                }
            }
        };

        $first = $this->children($moov, 0, strlen($moov));
        if ($first === null || count($first) !== 1 || $first[0]['type'] !== 'moov') {
            return null;
        }
        $walk($first[0]['header'], strlen($moov));

        return $ok ? $moov : null;
    }
}
