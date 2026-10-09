<?php

namespace App\Services;

use App\Models\PetCareSession;

/**
 * Shapes of the M5-R06-05 care sessions for the apps (start response, the
 * `litter.change.session` / `grooming.session` / `scratching.session`
 * payload fields, the finish `result`). Instants in the family timezone.
 * Typed so Scramble documents them.
 */
final class CareSessionPayload
{
    /**
     * A grooming / litter change session (the stroke mini-game).
     *
     * @return array{id: string, kind: 'grooming'|'litter_change', started_at: string, ends_at: string, expires_at: string, duration_ms: int, min_strokes: int, segments: int, min_stroke_interval_ms: int, matted: bool}
     */
    public static function chore(PetCareSession $session, string $tz): array
    {
        $schedule = $session->schedule;

        return [
            'id' => $session->public_id,
            /** @var 'grooming'|'litter_change' */
            'kind' => $session->kind->value,
            'started_at' => $session->started_at->copy()->setTimezone($tz)->toIso8601String(),
            // The game has run its course; finish from here on.
            'ends_at' => $session->ends_at->copy()->setTimezone($tz)->toIso8601String(),
            // Last moment a finish is accepted (TTL).
            'expires_at' => $session->expires_at->copy()->setTimezone($tz)->toIso8601String(),
            'duration_ms' => $session->duration_ms,
            // What the server checks at finish (the app may show progress).
            'min_strokes' => (int) ($schedule['min_strokes'] ?? 0),
            'segments' => (int) ($schedule['segments'] ?? 1),
            'min_stroke_interval_ms' => (int) ($schedule['min_stroke_interval_ms'] ?? 0),
            // Grooming: the longer session that resolves a matted coat.
            'matted' => (bool) ($schedule['matted'] ?? false),
        ];
    }

    /**
     * The verdict of a grooming / litter change finish.
     *
     * @return array{session_id: string, success: bool, reason: 'too_few_strokes'|'not_spread'|'too_uniform'|null, strokes: int, min_strokes: int, segments_hit: int, segments: int, matted: bool}
     */
    public static function choreResult(PetCareSession $session): array
    {
        $r = $session->result ?? [];

        return [
            'session_id' => $session->public_id,
            'success' => (bool) ($r['success'] ?? false),
            'reason' => $r['reason'] ?? null,
            'strokes' => (int) ($r['strokes'] ?? 0),
            'min_strokes' => (int) ($r['min_strokes'] ?? 0),
            'segments_hit' => (int) ($r['segments_hit'] ?? 0),
            'segments' => (int) ($r['segments'] ?? 0),
            // Grooming: this session resolved a matted coat.
            'matted' => (bool) ($r['matted'] ?? false),
        ];
    }

    /**
     * A scratching redirect session ("carry to the scratcher + praise").
     *
     * @return array{id: string, started_at: string, ends_at: string, expires_at: string, land_at_ms: int, praise_window_ms: int, min_reaction_ms: int}
     */
    public static function scratching(PetCareSession $session, string $tz): array
    {
        $schedule = $session->schedule;

        return [
            'id' => $session->public_id,
            'started_at' => $session->started_at->copy()->setTimezone($tz)->toIso8601String(),
            // End of the praise window (landing + 3 s).
            'ends_at' => $session->ends_at->copy()->setTimezone($tz)->toIso8601String(),
            // Last moment a finish is accepted (TTL).
            'expires_at' => $session->expires_at->copy()->setTimezone($tz)->toIso8601String(),
            // The cat lands on the scratcher (ms since start); praise from here on …
            'land_at_ms' => (int) ($schedule['land_at_ms'] ?? 0),
            // … within this window (3 s, CAT_SPEC Q10) …
            'praise_window_ms' => (int) ($schedule['praise_window_ms'] ?? 0),
            // … but not sooner than this after the landing (human reaction floor).
            'min_reaction_ms' => (int) ($schedule['min_reaction_ms'] ?? 0),
        ];
    }

    /**
     * The verdict of a scratching finish.
     *
     * @return array{session_id: string, success: bool, reason: 'no_praise'|'too_early'|'too_late'|null, praise_ms: int|null, land_at_ms: int, delay_ms: int|null, praise_window_ms: int}
     */
    public static function scratchingResult(PetCareSession $session): array
    {
        $r = $session->result ?? [];

        return [
            'session_id' => $session->public_id,
            'success' => (bool) ($r['success'] ?? false),
            'reason' => $r['reason'] ?? null,
            'praise_ms' => isset($r['praise_ms']) ? (int) $r['praise_ms'] : null,
            'land_at_ms' => (int) ($r['land_at_ms'] ?? 0),
            // Praise − landing (ms); null without a praise.
            'delay_ms' => isset($r['delay_ms']) ? (int) $r['delay_ms'] : null,
            'praise_window_ms' => (int) ($r['praise_window_ms'] ?? 0),
        ];
    }
}
