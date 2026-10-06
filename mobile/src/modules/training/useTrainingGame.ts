/**
 * `useTrainingGame` — one training session from "Začni vajo" to the server's result
 * (M5-R03). Phases: pick → starting → running → finishing → result (or failed).
 *
 * - The local start is the moment the start response arrived; the game clock
 *   (`SessionClock`) is monotonic and re-anchored to the wall clock when the app comes
 *   back from the background (the phone may have slept). Taps are whole ms on it.
 * - A 100 ms tick drives the screen; when the schedule is over the hook finishes by
 *   itself — exactly once per session (a ref guards against a double finish; the server
 *   also answers a repeat with `unchanged`).
 * - Back from the background after the session ended: finish if the server will still
 *   take it (`expires_at`, compared in server time via the state's clock skew), else
 *   explain that the session expired — no request, the state is refetched.
 * - Refusals / locks / offline become one calm message; a finish that failed for a
 *   network reason may be retried while the session hasn't expired.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { useQueryClient } from '@tanstack/react-query';

import { childPetKey } from '@/hooks/queries/useChildPet';
import { TrainingResponseError, useFinishTraining, useStartTraining } from '@/hooks/queries/useTraining';
import { classifyActionError, CHILD_ACTION_STRINGS, lockMessage } from '@/modules/childPet/actionMessages';
import {
  addTap,
  canStillFinish,
  DEFAULT_CLOCK_SOURCES,
  SessionClock,
  type ClockSources,
} from '@/modules/training/game';
import {
  TRAINING_STRINGS,
  trainingRefusalMessage,
  type TrainingCommand,
  type TrainingResult,
  type TrainingSession,
} from '@/modules/training/training';

/** Screen refresh while a session runs. */
export const GAME_TICK_MS = 100;

/** Refusals after which the same session can't be finished any more. */
const FINAL_FINISH_REFUSALS = new Set([
  'training_session_expired',
  'training_session_invalid',
  'training_invalid_taps',
  'training_not_available',
]);

export type TrainingPhase =
  | { kind: 'pick' }
  | { kind: 'starting'; command: TrainingCommand }
  | { kind: 'running'; session: TrainingSession; taps: readonly number[] }
  | { kind: 'finishing'; session: TrainingSession; taps: readonly number[] }
  | { kind: 'result'; result: TrainingResult; status: 'accepted' | 'unchanged' }
  | {
      kind: 'failed';
      message: string;
      /** Set when the same session may still be finished ("Poskusi znova"). */
      retry: { session: TrainingSession; taps: readonly number[] } | null;
    };

export interface TrainingGameOptions {
  /** Server clock − device clock (ms) from the child state. */
  clockSkewMs: number;
  /** Family IANA zone (refusal times). */
  timezone: string | null;
  /** Injected in tests; defaults to performance.now / Date.now. */
  clock?: ClockSources;
}

export interface TrainingGame {
  phase: TrainingPhase;
  /** ms since the local start (running / finishing), else 0. */
  elapsedMs: number;
  start: (command: TrainingCommand) => void;
  praise: () => void;
  retryFinish: () => void;
  /** Back to the command list (after a result / failure). */
  reset: () => void;
}

export function failureText(error: unknown, timezone: string | null): string {
  if (error instanceof TrainingResponseError) return TRAINING_STRINGS.errors.badResponse;
  const failure = classifyActionError(error);
  switch (failure.kind) {
    case 'refused':
      return trainingRefusalMessage(failure.reason, failure.nextAllowedAt, timezone);
    case 'locked':
      return lockMessage(failure.reason, failure.lockedUntil, timezone) ?? CHILD_ACTION_STRINGS.failed;
    case 'offline':
      return CHILD_ACTION_STRINGS.offline;
    case 'throttled':
      return CHILD_ACTION_STRINGS.tooFast;
    case 'failed':
      return CHILD_ACTION_STRINGS.failed;
  }
}

/** Whether a failed finish may be retried with the same session. */
export function canRetryFinish(error: unknown): boolean {
  if (error instanceof TrainingResponseError) return false;
  const failure = classifyActionError(error);
  if (failure.kind === 'refused') return failure.reason === null || !FINAL_FINISH_REFUSALS.has(failure.reason);
  return failure.kind !== 'locked';
}

export function useTrainingGame({ clockSkewMs, timezone, clock = DEFAULT_CLOCK_SOURCES }: TrainingGameOptions): TrainingGame {
  const queryClient = useQueryClient();
  const startMutation = useStartTraining();
  const finishMutation = useFinishTraining();
  const [phase, setPhaseState] = useState<TrainingPhase>({ kind: 'pick' });
  const [elapsedMs, setElapsedMs] = useState(0);

  const phaseRef = useRef<TrainingPhase>(phase);
  const clockRef = useRef<SessionClock | null>(null);
  /** Session ids a finish was sent for (one request per session unless retried). */
  const finishedRef = useRef<Set<string>>(new Set());
  const mounted = useRef(true);
  const skewRef = useRef(clockSkewMs);
  skewRef.current = clockSkewMs;
  const tzRef = useRef(timezone);
  tzRef.current = timezone;
  const clockSourcesRef = useRef(clock);
  clockSourcesRef.current = clock;

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  const setPhase = useCallback((next: TrainingPhase) => {
    phaseRef.current = next;
    if (mounted.current) setPhaseState(next);
  }, []);

  const { mutate: startMutate } = startMutation;
  const { mutate: finishMutate } = finishMutation;

  const sendFinish = useCallback(
    (session: TrainingSession, taps: readonly number[]) => {
      const serverNow = clockSourcesRef.current.wall() + skewRef.current;
      if (!canStillFinish(session, serverNow)) {
        // Too late for the server (the app was away): say so instead of a doomed request.
        finishedRef.current.add(session.id);
        setPhase({ kind: 'failed', message: TRAINING_STRINGS.errors.expiredWhileAway, retry: null });
        void queryClient.invalidateQueries({ queryKey: childPetKey });
        return;
      }
      finishedRef.current.add(session.id);
      setPhase({ kind: 'finishing', session, taps });
      finishMutate(
        { sessionId: session.id, taps },
        {
          onSuccess: (response) => {
            setPhase({ kind: 'result', result: response.result, status: response.status });
          },
          onError: (error) => {
            const retry = canRetryFinish(error) && canStillFinish(session, clockSourcesRef.current.wall() + skewRef.current);
            setPhase({ kind: 'failed', message: failureText(error, tzRef.current), retry: retry ? { session, taps } : null });
          },
        },
      );
    },
    [finishMutate, queryClient, setPhase],
  );

  /** Finish the running session once its schedule is over (idempotent per session). */
  const finishIfOver = useCallback(() => {
    const current = phaseRef.current;
    const sessionClock = clockRef.current;
    if (current.kind !== 'running' || sessionClock === null) return;
    const elapsed = sessionClock.elapsed();
    if (mounted.current) setElapsedMs(elapsed);
    if (elapsed < current.session.duration_ms || finishedRef.current.has(current.session.id)) return;
    sendFinish(current.session, current.taps);
  }, [sendFinish]);

  // The game loop: tick while running.
  const running = phase.kind === 'running';
  useEffect(() => {
    if (!running) return;
    const id = setInterval(finishIfOver, GAME_TICK_MS);
    return () => clearInterval(id);
  }, [running, finishIfOver]);

  // Back from the background: follow the wall clock, then finish / expire if the time is up.
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state !== 'active') return;
      clockRef.current?.resync();
      finishIfOver();
    });
    return () => sub.remove();
  }, [finishIfOver]);

  const start = useCallback(
    (command: TrainingCommand) => {
      const current = phaseRef.current;
      if (current.kind !== 'pick' && current.kind !== 'failed' && current.kind !== 'result') return;
      setPhase({ kind: 'starting', command });
      startMutate(command, {
        onSuccess: (response) => {
          // The local clock starts now — the schedule is relative to this moment.
          clockRef.current = new SessionClock(clockSourcesRef.current);
          if (mounted.current) setElapsedMs(0);
          setPhase({ kind: 'running', session: response.session, taps: [] });
        },
        onError: (error) => {
          setPhase({ kind: 'failed', message: failureText(error, tzRef.current), retry: null });
        },
      });
    },
    [setPhase, startMutate],
  );

  const praise = useCallback(() => {
    const current = phaseRef.current;
    const sessionClock = clockRef.current;
    if (current.kind !== 'running' || sessionClock === null) return;
    const ms = sessionClock.elapsed();
    const taps = addTap(current.session, current.taps, ms);
    if (mounted.current) setElapsedMs(ms);
    if (taps !== current.taps) setPhase({ ...current, taps });
  }, [setPhase]);

  const retryFinish = useCallback(() => {
    const current = phaseRef.current;
    if (current.kind !== 'failed' || current.retry === null) return;
    sendFinish(current.retry.session, current.retry.taps);
  }, [sendFinish]);

  const reset = useCallback(() => {
    const current = phaseRef.current;
    if (current.kind === 'running' || current.kind === 'finishing' || current.kind === 'starting') return;
    clockRef.current = null;
    if (mounted.current) setElapsedMs(0);
    setPhase({ kind: 'pick' });
  }, [setPhase]);

  return { phase, elapsedMs, start, praise, retryFinish, reset };
}
