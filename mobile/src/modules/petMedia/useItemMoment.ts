/**
 * M6-W03: the one-shot item moment of a stage (bowls after a feed / water, treats after
 * »Priboljšek») — UI state only. WHAT shows comes from `itemMomentPlayback` (pure); the host
 * passes `phase` + `onPhaseEnd` to its `PetMediaView`. The moment ends when its clip ends or
 * fails (`onPhaseEnd`), after `maxMs` at the latest, or when the host calls `end()`.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { itemMomentPlayback, type ItemMoment, type MomentItemKey } from '@/modules/petMedia/itemMedia';
import type { PetMediaInfo } from '@/modules/petMedia/petMedia';
import type { PhasePlayback } from '@/modules/petMedia/phasePlayer';
import type { MediaMode } from '@/modules/petMedia/statePlayer';

interface Running extends ItemMoment {
  fallbackClip: string | null;
}

export interface ItemMomentControl {
  /** The `PetMediaView phase` while a moment shows, else null. */
  phase: PhasePlayback | null;
  /**
   * Start a moment; false = the pet has nothing to show for it (the host keeps its plain
   * feedback). `fallbackClip` = a clip from an older answer contract (the treat's `media`).
   */
  play: (key: MomentItemKey, fallbackClip?: string | null) => boolean;
  /** Back to the normal stage (also the `onPhaseEnd` handler). */
  end: () => void;
}

export function useItemMoment(media: PetMediaInfo, mode: MediaMode): ItemMomentControl {
  const [moment, setMoment] = useState<Running | null>(null);
  const seq = useRef(0);
  const latest = useRef({ media, mode });
  latest.current = { media, mode };

  const play = useCallback((key: MomentItemKey, fallbackClip: string | null = null) => {
    const { media: m, mode: md } = latest.current;
    const next: Running = { key, instance: seq.current + 1, fallbackClip };
    if (itemMomentPlayback(m.items, next, md, m.referenceImageUrl, { fallbackClip }) === null) return false;
    seq.current = next.instance;
    setMoment(next);
    return true;
  }, []);
  const end = useCallback(() => setMoment(null), []);

  const playback = useMemo(
    () => (moment === null ? null : itemMomentPlayback(media.items, moment, mode, media.referenceImageUrl, { fallbackClip: moment.fallbackClip })),
    [moment, media.items, media.referenceImageUrl, mode],
  );

  const instance = moment?.instance ?? null;
  const maxMs = playback?.maxMs ?? null;
  useEffect(() => {
    if (instance === null) return;
    // The item went away mid-moment (refund, new stage): nothing left to show.
    if (maxMs === null) {
      setMoment(null);
      return;
    }
    const timer = setTimeout(() => setMoment((m) => (m?.instance === instance ? null : m)), maxMs);
    return () => clearTimeout(timer);
  }, [instance, maxMs]);

  const phase = useMemo<PhasePlayback | null>(() => (playback === null ? null : { current: playback.current, still: playback.still }), [playback]);
  return { phase, play, end };
}
