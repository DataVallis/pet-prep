/**
 * `useCatSessionGame` — one server-led cat mini-game from "Začni" to the server's verdict
 * (M5-R06-08a): wand play, grooming, the weekly litter change, the scratching carry.
 * Phases: intro → starting → running → finishing → result (or failed). The same rules as
 * the training game (`useTrainingGame`, M5-R03):
 *
 * - The local start is the moment the start response arrived; the game clock
 *   (`SessionClock`) is monotonic and re-anchored to the wall clock when the app comes back
 *   from the background. Inputs (moves / strokes / the praise) are whole ms on it, touches
 *   mapped from their event timestamp (`TouchTimeMapper`).
 * - A 100 ms tick drives the screen; once `finishAtMs(session, input)` is reached the hook
 *   finishes by itself — exactly once per session (a ref guards a double finish; the server
 *   also answers a repeat with `unchanged`). The scratching game finishes right after the
 *   praise (`setInput` checks at once).
 * - Back from the background after the time ran out: finish if the server still takes it
 *   (`expires_at`, compared in server time via the state's clock skew), else say it expired
 *   (no request, the state is refetched).
 * - Resume after an app restart: the child state carries the child's own running session.
 *   Still running → the game continues from the server's `started_at` (inputs before the
 *   restart are lost); its time is over but the TTL isn't → finished at once with an empty
 *   input (no penalty — the server only says "didn't count"); past the TTL → the expiry text.
 *   A session the child stopped (`stop`, listed in `abandoned`) is never resumed.
 * - "Stop" while running (wand / stroke games): nothing is sent, nothing counts and there is
 *   no penalty (CAT_SPEC §5.2 "prekinjena igra ne šteje in nima kazni"); the server lets
 *   the session expire. A new start by the same child replaces it on the server.
 * - Refusals / locks / offline become one calm text; a finish that failed for a network
 *   reason may be retried while the session hasn't expired.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { useQueryClient } from '@tanstack/react-query';

import { childPetKey } from '@/hooks/queries/useChildPet';
import { CatResponseError } from '@/hooks/queries/useCatCare';
import {
  CAT_COMMON_STRINGS,
  CAT_LOCK_STRINGS,
  catRefusalMessage,
  type CatFinishResponse,
  type CatFinishStatus,
  type CatSessionBase,
  type CatStartResponse,
  type RefusalContext,
} from '@/modules/catCare/catCare';
import { classifyActionError, CHILD_ACTION_STRINGS, lockMessage } from '@/modules/childPet/actionMessages';
import type { LockReason } from '@/modules/childPet/childPetView';
import { familyClock } from '@/modules/childPet/familyTime';
import { canStillFinish, DEFAULT_CLOCK_SOURCES, SessionClock, TouchTimeMapper, type ClockSources } from '@/modules/training/game';

/** Screen refresh while a session runs. */
export const CAT_GAME_TICK_MS = 100;

/** Refusals after which the same session can't be finished any more (no retry). */
export const FINAL_FINISH_REFUSALS: ReadonlySet<string> = new Set([
  'wand_not_available',
  'wand_session_invalid',
  'wand_session_expired',
  'wand_invalid_moves',
  'wand_session_interrupted',
  'litter_not_available',
  'grooming_not_available',
  'scratching_not_needed',
  'care_session_invalid',
  'care_session_expired',
  'care_session_invalid_input',
  'care_session_interrupted',
]);

export type CatGamePhase<S, I, R> =
  | { kind: 'intro' }
  | { kind: 'starting' }
  | {
      kind: 'running';
      session: S;
      input: I;
      /** ms into the session when it was resumed after an app restart; null = played from the start. */
      resumedAtMs: number | null;
    }
  | { kind: 'finishing'; session: S; input: I; resumedAtMs: number | null }
  | { kind: 'result'; result: R; status: CatFinishStatus }
  | {
      kind: 'failed';
      message: string;
      /** Set when the same session may still be finished ("Poskusi znova"). */
      retry: { session: S; input: I; resumedAtMs: number | null } | null;
    };

interface MutateCallbacks<T> {
  onSuccess: (response: T) => void;
  onError: (error: unknown) => void;
}

export interface CatSessionGameOptions<S extends CatSessionBase, I, R extends { success: boolean }> {
  /** Server clock − device clock (ms) from the child state. */
  clockSkewMs: number;
  /** Family zone + server time for refusal texts; `scratchingOnly` for `needs_cleaning`. */
  refusal: RefusalContext;
  /** Injected in tests; defaults to performance.now / Date.now. */
  clock?: ClockSources;
  /** The child's own running session from the child state — resumed once. */
  resume?: S | null;
  /** Session ids the child stopped (never resumed). */
  abandoned?: readonly string[];
  onAbandon?: (sessionId: string) => void;
  emptyInput: I;
  /** ms on the game clock at which the game is over and finishes by itself. */
  finishAtMs: (session: S, input: I) => number;
  startMutate: (variables: void, callbacks: MutateCallbacks<CatStartResponse<S>>) => void;
  finishMutate: (variables: { session: S; input: I }, callbacks: MutateCallbacks<CatFinishResponse<R>>) => void;
}

export interface CatSessionGame<S, I, R> {
  phase: CatGamePhase<S, I, R>;
  /** ms since the local start (running / finishing), else 0. */
  elapsedMs: number;
  start: () => void;
  /** The game ms of a touch (`nativeEvent.timestamp`), or of now. */
  msAt: (touchTimestamp?: number | null) => number;
  /** Change the recorded input of the running game (finishes at once when that makes it due). */
  setInput: (update: (input: I) => I) => void;
  retryFinish: () => void;
  /** Back to the intro (after a result / failure). */
  reset: () => void;
  /** Stop the running game: nothing is sent, nothing counts, no penalty. */
  stop: () => void;
}

/** Lock text for a cat (the vet / the shelter name "muca"); other locks are species-neutral. */
export function catLockMessage(reason: LockReason | null, until: string | null, timezone: string | null): string {
  if (reason === 'ill') {
    const clock = familyClock(until, timezone);
    return clock ? CAT_LOCK_STRINGS.ill(clock) : CAT_LOCK_STRINGS.illNoTime;
  }
  if (reason === 'game_over') return CAT_LOCK_STRINGS.game_over;
  return lockMessage(reason, until, timezone) ?? CHILD_ACTION_STRINGS.failed;
}

/** One calm text for any failed start / finish. */
export function catFailureText(error: unknown, ctx: RefusalContext): string {
  if (error instanceof CatResponseError) return CAT_COMMON_STRINGS.badResponse;
  const failure = classifyActionError(error);
  switch (failure.kind) {
    case 'refused':
      return catRefusalMessage(failure.reason, failure.nextAllowedAt, ctx);
    case 'locked':
      return catLockMessage(failure.reason, failure.lockedUntil, ctx.timezone);
    case 'offline':
      return CHILD_ACTION_STRINGS.offline;
    case 'throttled':
      return CHILD_ACTION_STRINGS.tooFast;
    case 'failed':
      return CHILD_ACTION_STRINGS.failed;
  }
}

/** Whether a failed finish may be retried with the same session. */
export function canRetryCatFinish(error: unknown): boolean {
  if (error instanceof CatResponseError) return false;
  const failure = classifyActionError(error);
  if (failure.kind === 'refused') return failure.reason === null || !FINAL_FINISH_REFUSALS.has(failure.reason);
  return failure.kind !== 'locked';
}

export function useCatSessionGame<S extends CatSessionBase, I, R extends { success: boolean }>({
  clockSkewMs,
  refusal,
  clock = DEFAULT_CLOCK_SOURCES,
  resume = null,
  abandoned = [],
  onAbandon,
  emptyInput,
  finishAtMs,
  startMutate,
  finishMutate,
}: CatSessionGameOptions<S, I, R>): CatSessionGame<S, I, R> {
  const queryClient = useQueryClient();
  const [phase, setPhaseState] = useState<CatGamePhase<S, I, R>>({ kind: 'intro' });
  const [elapsedMs, setElapsedMs] = useState(0);

  const phaseRef = useRef<CatGamePhase<S, I, R>>(phase);
  const clockRef = useRef<SessionClock | null>(null);
  /** Session ids a finish was sent for (one request per session unless retried). */
  const finishedRef = useRef<Set<string>>(new Set());
  /** Session ids this hook played, resumed or stopped (never resumed again). */
  const playedRef = useRef<Set<string>>(new Set());
  const touchMapper = useRef(new TouchTimeMapper());
  const mounted = useRef(true);
  // Latest props in refs: the callbacks below stay stable.
  const skewRef = useRef(clockSkewMs);
  skewRef.current = clockSkewMs;
  const refusalRef = useRef(refusal);
  refusalRef.current = refusal;
  const clockSourcesRef = useRef(clock);
  clockSourcesRef.current = clock;
  const finishAtRef = useRef(finishAtMs);
  finishAtRef.current = finishAtMs;
  const startRef = useRef(startMutate);
  startRef.current = startMutate;
  const finishRef = useRef(finishMutate);
  finishRef.current = finishMutate;
  const emptyRef = useRef(emptyInput);
  emptyRef.current = emptyInput;
  const abandonRef = useRef(onAbandon);
  abandonRef.current = onAbandon;

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  const setPhase = useCallback((next: CatGamePhase<S, I, R>) => {
    phaseRef.current = next;
    if (mounted.current) setPhaseState(next);
  }, []);

  const sendFinish = useCallback(
    (session: S, input: I, resumedAtMs: number | null = null) => {
      // A retry after a lost answer: the server may already have saved the first attempt.
      const isRetry = finishedRef.current.has(session.id);
      const serverNow = clockSourcesRef.current.wall() + skewRef.current;
      if (!canStillFinish(session, serverNow)) {
        // Too late for the server (the app was away): say so instead of a doomed request.
        finishedRef.current.add(session.id);
        setPhase({ kind: 'failed', message: CAT_COMMON_STRINGS.expiredWhileAway, retry: null });
        void queryClient.invalidateQueries({ queryKey: childPetKey });
        return;
      }
      finishedRef.current.add(session.id);
      setPhase({ kind: 'finishing', session, input, resumedAtMs });
      finishRef.current(
        { session, input },
        {
          onSuccess: (response) => {
            // `unchanged` after a retry = our own first attempt arrived: show it like a fresh verdict.
            let status: CatFinishStatus = response.status;
            if (isRetry && status === 'unchanged') status = response.result.success ? 'accepted' : 'rejected';
            setPhase({ kind: 'result', result: response.result, status });
          },
          onError: (error) => {
            const retry = canRetryCatFinish(error) && canStillFinish(session, clockSourcesRef.current.wall() + skewRef.current);
            setPhase({ kind: 'failed', message: catFailureText(error, refusalRef.current), retry: retry ? { session, input, resumedAtMs } : null });
          },
        },
      );
    },
    [queryClient, setPhase],
  );

  /** Finish the running session once it is due (idempotent per session). */
  const finishIfDue = useCallback(() => {
    const current = phaseRef.current;
    const sessionClock = clockRef.current;
    if (current.kind !== 'running' || sessionClock === null) return;
    const elapsed = sessionClock.elapsed();
    if (mounted.current) setElapsedMs(elapsed);
    if (elapsed < finishAtRef.current(current.session, current.input) || finishedRef.current.has(current.session.id)) return;
    sendFinish(current.session, current.input, current.resumedAtMs);
  }, [sendFinish]);

  // The game loop: tick while running.
  const running = phase.kind === 'running';
  useEffect(() => {
    if (!running) return;
    const id = setInterval(finishIfDue, CAT_GAME_TICK_MS);
    return () => clearInterval(id);
  }, [running, finishIfDue]);

  // Back from the background: follow the wall clock, then finish / expire if the time is up.
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state !== 'active') return;
      clockRef.current?.resync();
      finishIfDue();
    });
    return () => sub.remove();
  }, [finishIfDue]);

  const start = useCallback(() => {
    const current = phaseRef.current;
    if (current.kind !== 'intro' && current.kind !== 'failed' && current.kind !== 'result') return;
    setPhase({ kind: 'starting' });
    startRef.current(undefined, {
      onSuccess: (response) => {
        // The local clock starts now — the schedule is relative to this moment.
        clockRef.current = new SessionClock(clockSourcesRef.current);
        touchMapper.current = new TouchTimeMapper();
        playedRef.current.add(response.session.id);
        if (mounted.current) setElapsedMs(0);
        setPhase({ kind: 'running', session: response.session, input: emptyRef.current, resumedAtMs: null });
      },
      onError: (error) => {
        setPhase({ kind: 'failed', message: catFailureText(error, refusalRef.current), retry: null });
      },
    });
  }, [setPhase]);

  // Resume the child's own session after an app restart (once per session id).
  const resumeRef = useRef(resume);
  resumeRef.current = resume;
  const abandonedRef = useRef(abandoned);
  abandonedRef.current = abandoned;
  const resumeId = resume?.id ?? null;
  useEffect(() => {
    const session = resumeRef.current;
    if (session === null || session.id !== resumeId) return;
    if (phaseRef.current.kind !== 'intro') return;
    if (playedRef.current.has(session.id) || finishedRef.current.has(session.id) || abandonedRef.current.includes(session.id)) return;
    playedRef.current.add(session.id);
    const started = Date.parse(session.started_at);
    const serverNow = clockSourcesRef.current.wall() + skewRef.current;
    const elapsed = Number.isNaN(started) ? session.duration_ms : Math.max(0, serverNow - started);
    if (elapsed < finishAtRef.current(session, emptyRef.current)) {
      clockRef.current = new SessionClock(clockSourcesRef.current, elapsed);
      touchMapper.current = new TouchTimeMapper();
      if (mounted.current) setElapsedMs(elapsed);
      setPhase({ kind: 'running', session, input: emptyRef.current, resumedAtMs: elapsed });
      return;
    }
    // Its time ran out while the app was closed: close it now if the server still takes it.
    sendFinish(session, emptyRef.current);
  }, [resumeId, sendFinish, setPhase]);

  const msAt = useCallback((touchTimestamp?: number | null) => {
    const sessionClock = clockRef.current;
    if (sessionClock === null) return 0;
    return sessionClock.elapsedAt(touchMapper.current.map(touchTimestamp, sessionClock.monoNow()));
  }, []);

  const setInput = useCallback(
    (update: (input: I) => I) => {
      const current = phaseRef.current;
      if (current.kind !== 'running') return;
      const input = update(current.input);
      if (input === current.input) return;
      setPhase({ ...current, input });
      finishIfDue();
    },
    [finishIfDue, setPhase],
  );

  const retryFinish = useCallback(() => {
    const current = phaseRef.current;
    if (current.kind !== 'failed' || current.retry === null) return;
    sendFinish(current.retry.session, current.retry.input, current.retry.resumedAtMs);
  }, [sendFinish]);

  const reset = useCallback(() => {
    const current = phaseRef.current;
    if (current.kind === 'running' || current.kind === 'finishing' || current.kind === 'starting') return;
    clockRef.current = null;
    if (mounted.current) setElapsedMs(0);
    setPhase({ kind: 'intro' });
  }, [setPhase]);

  const stop = useCallback(() => {
    const current = phaseRef.current;
    if (current.kind !== 'running') return;
    playedRef.current.add(current.session.id);
    abandonRef.current?.(current.session.id);
    clockRef.current = null;
    if (mounted.current) setElapsedMs(0);
    setPhase({ kind: 'intro' });
  }, [setPhase]);

  return { phase, elapsedMs, start, msAt, setInput, retryFinish, reset, stop };
}
