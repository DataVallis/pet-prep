/**
 * M5-T20 care beat (David 2026-11-24, PRODUCT_SPEC §8): one care action at a time on the child
 * HUD. Pressing Take out → Feed → Water quickly cut each bowls clip after a few frames. While a
 * care clip plays the care buttons are BUSY and taps are IGNORED (not queued — David's choice).
 *
 * Rules (pure, Jest `careBeat.test.ts`):
 * - A beat exists only when the action really plays a clip: `bowlsMoment(action)` is non-null AND
 *   `itemMomentPlayback` yields a `clip` (owned bowls, video mode, water only with `drink_video`).
 *   Picture mode, no bowls, take out / clean / walk → no beat, never a wait without a clip.
 * - It starts at PRESS time (the answer may take a moment) and ends when the clip ends / fails
 *   (`onPhaseEnd`), on an error / refusal, on a lock — and after `CARE_BEAT_MAX_MS` at the latest.
 * - It is UI state only: the buttons get `busy`, never `disabled`, and it never enters
 *   `actionRules.ts` (that table stays server flags only). It is not a media gate — core care is
 *   never locked for missing media (PRODUCT_SPEC §10.1): no clip → no beat.
 */

import { useCallback, useEffect, useRef, useState } from 'react';

import { bowlsMoment, itemMomentPlayback, type ItemMoment } from '@/modules/petMedia/itemMedia';
import type { PetMediaInfo } from '@/modules/petMedia/petMedia';
import type { MediaMode } from '@/modules/petMedia/statePlayer';

/** A beat never lasts longer than this (a stuck player never keeps the buttons busy). */
export const CARE_BEAT_MAX_MS = 5_000;

/** The running beat: busy until `endsAt` (device ms) at the latest; null = no beat. */
export type CareBeat = { endsAt: number } | null;

type BeatMedia = Pick<PetMediaInfo, 'items' | 'referenceImageUrl'>;

/** True when this care action plays a bowls CLIP now (eating after feed, drinking after water). */
export function careBeatFor(action: string, media: BeatMedia, mode: MediaMode): boolean {
  const clip = bowlsMoment(action);
  if (clip === null) return false;
  const moment: ItemMoment = { key: 'bowls', instance: 0, clip };
  return itemMomentPlayback(media.items, moment, mode, media.referenceImageUrl)?.current.kind === 'clip';
}

/** True when a given treat plays a CLIP (the treat item's clip, else the answer's `clipUrl`). */
export function treatBeatFor(media: BeatMedia, mode: MediaMode, fallbackClip: string | null): boolean {
  const moment: ItemMoment = { key: 'treats', instance: 0 };
  return itemMomentPlayback(media.items, moment, mode, media.referenceImageUrl, { fallbackClip })?.current.kind === 'clip';
}

/** A new beat starting now. */
export function startBeat(nowMs: number): { endsAt: number } {
  return { endsAt: nowMs + CARE_BEAT_MAX_MS };
}

/** The beat still holds the buttons at `nowMs`. */
export function beatActive(beat: CareBeat, nowMs: number): boolean {
  return beat !== null && nowMs < beat.endsAt;
}

/** Ms until the beat ends by itself (0 = already over / none). */
export function beatRemainingMs(beat: CareBeat, nowMs: number): number {
  return beat === null ? 0 : Math.max(0, beat.endsAt - nowMs);
}

export interface CareBeatControl {
  /** Care buttons show `busy` (render state). */
  busy: boolean;
  /** Synchronous check for a press handler (two taps in one frame). */
  isBusy: () => boolean;
  start: () => void;
  end: () => void;
}

/** The HUD's beat: one timer ending it at `endsAt` at the latest. */
export function useCareBeat(now: () => number = Date.now): CareBeatControl {
  const [beat, setBeat] = useState<CareBeat>(null);
  const ref = useRef<CareBeat>(null);
  const clock = useRef(now);
  clock.current = now;

  const start = useCallback(() => {
    const next = startBeat(clock.current());
    ref.current = next;
    setBeat(next);
  }, []);
  const end = useCallback(() => {
    ref.current = null;
    setBeat(null);
  }, []);
  const isBusy = useCallback(() => beatActive(ref.current, clock.current()), []);

  const endsAt = beat?.endsAt ?? null;
  useEffect(() => {
    if (endsAt === null) return;
    const timer = setTimeout(
      () => {
        if (ref.current?.endsAt === endsAt) ref.current = null;
        setBeat((b) => (b?.endsAt === endsAt ? null : b));
      },
      beatRemainingMs({ endsAt }, clock.current()),
    );
    return () => clearTimeout(timer);
  }, [endsAt]);

  return { busy: beat !== null, isBusy, start, end };
}
