/**
 * `useClipQueue` — runs the pure ClipQueue (`clipQueue.ts`) on a game clock: one timer for the
 * clock safety (`nextClipTickAt`), the player's end events and the FeedbackLayer's mask.
 * No native import (Jest drives it with fake timers).
 *
 * `specs` / `initial` are read when `start(now)` is called (the deck is ready and the game
 * clock starts) — never re-timed under a running game.
 */

import { useCallback, useEffect, useRef, useState } from 'react';

import {
  clipEnded,
  createClipQueue,
  nextClipTickAt,
  requestClip,
  setMasked,
  tickClips,
  type ClipQueueState,
  type ClipSpec,
} from '@/modules/gameLayer/clipQueue';

export interface ClipQueueApi {
  /** null until `start`. */
  state: ClipQueueState | null;
  start: (specs: readonly ClipSpec[], initial: string) => void;
  request: (role: string) => void;
  ended: (role: string, instance: number) => void;
  masked: (masked: boolean) => void;
}

/** `now` = the game clock (ms since the game began). */
export function useClipQueue(now: () => number): ClipQueueApi {
  const [state, setState] = useState<ClipQueueState | null>(null);
  const ref = useRef<ClipQueueState | null>(null);
  const nowRef = useRef(now);
  nowRef.current = now;

  const commit = useCallback((next: ClipQueueState | null) => {
    if (next === ref.current) return;
    ref.current = next;
    setState(next);
  }, []);

  const start = useCallback(
    (specs: readonly ClipSpec[], initial: string) => commit(createClipQueue(specs, initial, nowRef.current())),
    [commit],
  );
  const request = useCallback((role: string) => {
    if (ref.current) commit(requestClip(ref.current, role, nowRef.current()));
  }, [commit]);
  const ended = useCallback((role: string, instance: number) => {
    if (ref.current) commit(clipEnded(ref.current, role, instance, nowRef.current()));
  }, [commit]);
  const masked = useCallback((value: boolean) => {
    if (ref.current) commit(setMasked(ref.current, value, nowRef.current()));
  }, [commit]);

  // One timer: the clock safety of the current `once` clip.
  const tickAt = state === null ? null : nextClipTickAt(state);
  useEffect(() => {
    if (tickAt === null) return;
    const timer = setTimeout(() => {
      if (ref.current) commit(tickClips(ref.current, nowRef.current()));
    }, Math.max(0, tickAt - nowRef.current()));
    return () => clearTimeout(timer);
  }, [tickAt, commit]);

  return { state, start, request, ended, masked };
}
