<?php

namespace App\Services\AppErrors;

use App\Services\SystemHealth\ErrorText;

/**
 * Text helpers for mobile app error reports (M5-05): scrub before storing
 * (ErrorText rules + device file paths), and the normalised parts of the
 * fingerprint. A JS stack frame of a release build looks like
 * `at fn (address at index.android.bundle:1:23456)` (Hermes) or
 * `fn@file:///…/main.jsbundle:1:23456` (JSC); bundle offsets change with every
 * build, the error does not.
 */
final class AppErrorText
{
    public const MESSAGE_MAX = 500;

    public const STACK_MAX = 2048;

    public const FRAME_MAX = 300;

    /** Scrubbed message (≤ 500 chars). */
    public static function message(string $message): string
    {
        // JS engines quote the failing expression (`evaluating 'pet.media.idle'`) —
        // source code, not data — keep it readable when it is only code characters.
        $message = (string) preg_replace_callback(
            "/(evaluating )'([A-Za-z0-9_$.\\[\\]()]{1,120})'/",
            fn (array $m): string => $m[1].'`'.$m[2].'`',
            $message,
        );

        return ErrorText::scrub(self::stripPaths($message), self::MESSAGE_MAX);
    }

    /** Scrubbed stack: line by line, device paths reduced to file names, ≤ 2 KB. */
    public static function stack(?string $stack): ?string
    {
        if ($stack === null || trim($stack) === '') {
            return null;
        }

        $lines = [];
        $length = 0;
        foreach (preg_split('/\R/', $stack) ?: [] as $line) {
            $line = ErrorText::scrub(self::stripPaths($line), self::FRAME_MAX);
            if ($line === '') {
                continue;
            }
            if ($length + mb_strlen($line) + 1 > self::STACK_MAX) {
                break;
            }
            $lines[] = $line;
            $length += mb_strlen($line) + 1;
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /** The first frame of a (scrubbed) stack — the line after the message. */
    public static function firstFrame(?string $stack): ?string
    {
        if ($stack === null) {
            return null;
        }
        foreach (explode("\n", $stack) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'at ') || preg_match('/^[^\s(]*@/', $line) === 1) {
                return mb_substr($line, 0, self::FRAME_MAX);
            }
        }

        return null;
    }

    public static function fingerprint(string $name, string $message, ?string $firstFrame): string
    {
        $frame = $firstFrame === null ? '' : (string) preg_replace('/:\d+(:\d+)?/', '', $firstFrame);

        return hash('sha256', implode('|', [$name, ErrorText::normalise($message), ErrorText::normalise($frame)]));
    }

    /**
     * `file:///var/mobile/Containers/…/main.jsbundle` / `/data/user/0/si.petprep/…/x.js`
     * → the file name only (an app container path may carry a device or user id).
     */
    private static function stripPaths(string $text): string
    {
        return (string) preg_replace('#(?:[a-z][a-z0-9+.\-]*://)?(?:/[^\s/():]+)+/([^\s/():]+)#i', '$1', $text);
    }
}
