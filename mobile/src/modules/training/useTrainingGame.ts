/**
 * `useTrainingGame` — one training session from "Začni vajo" to the server's result
 * (M5-R03). Phases: pick → starting → running → finishing → result (or failed).
 *
 * - School smooth playback (2026-10-28): when `stageGate(session)` says the session plays on
 *   the school stage, the hook waits in `preparing` (»Your pup is getting ready …«) until the
 *   stage reports every clip mounted and rendered (`begin()`); after `STAGE_READY_TIMEOUT_MS`
 *   it begins by itself with `stagePicture` (this session in picture mode). The server
 *   schedule is relative to the LOCAL start, so starting later shifts nothing (the finish TTL
 *   is 60 s after the scheduled end).
 * - The local start is the moment the start response arrived (or the stage got ready); the game clock
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
 * - Resume after an app restart (PR #53): the child state carries the schedule of the
 *   child's own running session. Still running → the game continues from the server's
 *   `started_at` (no taps recorded before the restart); its time is over but the TTL
 *   isn't → it is finished at once with no taps; past the TTL → the expiry message.
 * - School v2 (GAMES_SPEC §4.1, rules v2): `schoolRules: 2` starts the session with `rules: 2`
 *   (only a binary with the game layer asks for it). `endEarly()` = »Končaj« after round 1 (Š3):
 *   offered only while `canEndEarly()` — the break after round 1, and only while the server will
 *   still take it (its round 2 start + 2 s, judged with `serverAheadMs`, an upper bound of how
 *   far the server's clock of this session runs ahead of ours). The early finish keeps the game
 *   clock running; a refusal that isn't final (422 `training_round_two_started`, offline, …)
 *   returns to `running` — round 2 plays on and the session is finished in full at its end
 *   (`earlyEndDeclined` counts these, for the »keep going« line).
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { useQueryClient } from '@tanstack/react-query';

import { childPetKey } from '@/hooks/queries/useChildPet';
import { TrainingResponseError, useFinishTraining, useStartTraining } from '@/hooks/queries/useTraining';
import { classifyActionError, CHILD_ACTION_STRINGS, lockMessage, serverUpdatingActionText } from '@/modules/childPet/actionMessages';
import {
  addTap,
  canStillFinish,
  DEFAULT_CLOCK_SOURCES,
  SessionClock,
  TouchTimeMapper,
  type ClockSources,
} from '@/modules/training/game';
import { EARLY_END_RESUME_AHEAD_MS, earlyEndOpen } from '@/modules/training/schoolV2/schoolV2';
import {
  isSchoolV2Session,
  TRAINING_STRINGS,
  trainingRefusalMessage,
  type TrainingCommand,
  type TrainingResult,
  type TrainingSession,
} from '@/modules/training/training';

/** Screen refresh while a session runs. */
export const GAME_TICK_MS = 100;

/** School stage: the longest wait for its clips before the session runs in picture mode. */
export const STAGE_READY_TIMEOUT_MS = 12_000;

/** Refusals after which the same session can't be finished any more. */
const FINAL_FINISH_REFUSALS = new Set([
  'training_session_expired',
  'training_session_invalid',
  'training_invalid_taps',
  'training_not_available',
  // A lock began during the session: it never counts (its time is refunded).
  'training_session_interrupted',
]);

export type TrainingPhase =
  | { kind: 'pick' }
  | { kind: 'starting'; command: TrainingCommand }
  /** School smooth playback: the schedule is here, the stage loads its clips (clock not started). */
  | { kind: 'preparing'; session: TrainingSession }
  | {
      kind: 'running';
      session: TrainingSession;
      taps: readonly number[];
      /** ms into the session when it was resumed after an app restart; null = played from the start. */
      resumedAtMs: number | null;
    }
  | {
      kind: 'finishing';
      session: TrainingSession;
      taps: readonly number[];
      resumedAtMs: number | null;
      /** School v2: an early end after round 1 is being saved (the game clock keeps running). */
      early?: true;
    }
  | { kind: 'result'; result: TrainingResult; status: 'accepted' | 'unchanged' }
  | {
      kind: 'failed';
      message: string;
      /** Set when the same session may still be finished ("Poskusi znova"). */
      retry: { session: TrainingSession; taps: readonly number[]; resumedAtMs: number | null } | null;
    };

export interface TrainingGameOptions {
  /** Server clock − device clock (ms) from the child state. */
  clockSkewMs: number;
  /** Family IANA zone (refusal times). */
  timezone: string | null;
  /** Injected in tests; defaults to performance.now / Date.now. */
  clock?: ClockSources;
  /** The child's own running session from the child state (with its schedule) — resumed once. */
  resume?: TrainingSession | null;
  /**
   * School smooth playback: true = this new session waits for the school stage (`begin`)
   * before its clock starts. Not asked for a resumed session (its clock is the server's).
   */
  stageGate?: (session: TrainingSession) => boolean;
  /** GAMES_SPEC §7.2: 2 = start new sessions with `rules: 2` (school v2; game layer present). */
  schoolRules?: 2;
}

export interface TrainingGame {
  phase: TrainingPhase;
  /** ms since the local start (running / finishing), else 0. */
  elapsedMs: number;
  start: (command: TrainingCommand) => void;
  /** The school stage is ready: start the clock of the `preparing` session (no-op otherwise). */
  begin: () => void;
  /** The stage did not get ready in time: this session runs in picture mode. */
  stagePicture: boolean;
  /**
   * Record a "Pohvali" tap. `touchTimestamp` = the touch event's `nativeEvent.timestamp`
   * (mapped onto the game clock by `TouchTimeMapper`); without it, the handler time. Returns the
   * tap's ms when it was recorded (the FIRST tap of its cue — the one the server scores), else null.
   */
  praise: (touchTimestamp?: number | null) => number | null;
  retryFinish: () => void;
  /** Back to the command list (after a result / failure). */
  reset: () => void;
  /** ms since the local start of the session clock (0 before it starts) — read at call time. */
  now: () => number;
  /** School v2: »Končaj« may be offered now (break after round 1, server still accepts it). */
  canEndEarly: () => boolean;
  /** School v2: end after round 1 (Š3). No-op when `canEndEarly()` is false. */
  endEarly: () => void;
  /** Times an early end was not accepted and round 2 played on (for the »keep going« line). */
  earlyEndDeclined: number;
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
    case 'updating':
      return serverUpdatingActionText();
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

export function useTrainingGame({
  clockSkewMs,
  timezone,
  clock = DEFAULT_CLOCK_SOURCES,
  resume = null,
  stageGate,
  schoolRules,
}: TrainingGameOptions): TrainingGame {
  const queryClient = useQueryClient();
  const startMutation = useStartTraining();
  const finishMutation = useFinishTraining();
  const [phase, setPhaseState] = useState<TrainingPhase>({ kind: 'pick' });
  const [elapsedMs, setElapsedMs] = useState(0);
  const [stagePicture, setStagePicture] = useState(false);
  const [earlyEndDeclined, setEarlyEndDeclined] = useState(0);
  const stageGateRef = useRef(stageGate);
  stageGateRef.current = stageGate;
  const rulesRef = useRef(schoolRules);
  rulesRef.current = schoolRules;
  /** Wall ms when the start request left / its answer arrived (for `serverAheadMs`). */
  const startTimes = useRef<{ sent: number; answered: number } | null>(null);
  /**
   * Upper bound (ms) of how far the server's clock of the running session is ahead of ours, plus
   * the travel time of a finish: the server started the session after our request left, and
   * our clock started at `begin` (answer + stage) → (begin − sent) + one more round trip.
   */
  const serverAheadRef = useRef(EARLY_END_RESUME_AHEAD_MS);

  const phaseRef = useRef<TrainingPhase>(phase);
  const clockRef = useRef<SessionClock | null>(null);
  /** Session ids a finish was sent for (one request per session unless retried). */
  const finishedRef = useRef<Set<string>>(new Set());
  /** Session ids this hook played or resumed (never resumed again). */
  const playedRef = useRef<Set<string>>(new Set());
  const touchMapper = useRef(new TouchTimeMapper());
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
    (session: TrainingSession, taps: readonly number[], resumedAtMs: number | null = null, early = false) => {
      // A retry after a lost answer: the server may already have saved the first attempt.
      const isRetry = finishedRef.current.has(session.id);
      const serverNow = clockSourcesRef.current.wall() + skewRef.current;
      if (!canStillFinish(session, serverNow)) {
        // Too late for the server (the app was away): say so instead of a doomed request.
        finishedRef.current.add(session.id);
        setPhase({ kind: 'failed', message: TRAINING_STRINGS.errors.expiredWhileAway, retry: null });
        void queryClient.invalidateQueries({ queryKey: childPetKey });
        return;
      }
      finishedRef.current.add(session.id);
      setPhase(early ? { kind: 'finishing', session, taps, resumedAtMs, early: true } : { kind: 'finishing', session, taps, resumedAtMs });
      finishMutate(
        early ? { sessionId: session.id, taps, endedAfterRound: 1 } : { sessionId: session.id, taps },
        {
          onSuccess: (response) => {
            // `unchanged` after a retry = our own first attempt arrived; show it like a fresh result.
            const status = isRetry && response.status === 'unchanged' ? 'accepted' : response.status;
            setPhase({ kind: 'result', result: response.result, status });
          },
          onError: (error) => {
            const sessionClock = clockRef.current;
            if (early && canRetryFinish(error) && sessionClock !== null && sessionClock.elapsed() < session.duration_ms) {
              // Š3 graceful: round 2 has started (or the request failed) — play on, finish in full later.
              finishedRef.current.delete(session.id);
              if (mounted.current) setEarlyEndDeclined((n) => n + 1);
              setPhase({ kind: 'running', session, taps, resumedAtMs });
              return;
            }
            const retry = canRetryFinish(error) && canStillFinish(session, clockSourcesRef.current.wall() + skewRef.current);
            setPhase({ kind: 'failed', message: failureText(error, tzRef.current), retry: retry ? { session, taps, resumedAtMs } : null });
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
    sendFinish(current.session, current.taps, current.resumedAtMs);
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

  /** The session clock started now: how far the server may be ahead of it (see `serverAheadRef`). */
  const noteClockStart = useCallback(() => {
    const times = startTimes.current;
    const nowWall = clockSourcesRef.current.wall();
    serverAheadRef.current =
      times === null ? EARLY_END_RESUME_AHEAD_MS : Math.max(0, nowWall - times.sent) + Math.max(0, times.answered - times.sent);
  }, []);

  const start = useCallback(
    (command: TrainingCommand) => {
      const current = phaseRef.current;
      if (current.kind !== 'pick' && current.kind !== 'failed' && current.kind !== 'result') return;
      setPhase({ kind: 'starting', command });
      if (mounted.current) setStagePicture(false);
      const rules = rulesRef.current;
      const sent = clockSourcesRef.current.wall();
      startTimes.current = { sent, answered: sent };
      startMutate(rules === undefined ? { command } : { command, rules }, {
        onSuccess: (response) => {
          startTimes.current = { sent, answered: clockSourcesRef.current.wall() };
          playedRef.current.add(response.session.id);
          if (mounted.current) setElapsedMs(0);
          if (stageGateRef.current?.(response.session) === true) {
            // The stage mounts its clips first; the clock starts in `begin`.
            setPhase({ kind: 'preparing', session: response.session });
            return;
          }
          // The local clock starts now — the schedule is relative to this moment.
          clockRef.current = new SessionClock(clockSourcesRef.current);
          noteClockStart();
          setPhase({ kind: 'running', session: response.session, taps: [], resumedAtMs: null });
        },
        onError: (error) => {
          setPhase({ kind: 'failed', message: failureText(error, tzRef.current), retry: null });
        },
      });
    },
    [setPhase, startMutate, noteClockStart],
  );

  const beginWith = useCallback(
    (picture: boolean) => {
      const current = phaseRef.current;
      if (current.kind !== 'preparing') return;
      clockRef.current = new SessionClock(clockSourcesRef.current);
      noteClockStart();
      if (mounted.current) {
        setElapsedMs(0);
        setStagePicture(picture);
      }
      setPhase({ kind: 'running', session: current.session, taps: [], resumedAtMs: null });
    },
    [setPhase, noteClockStart],
  );
  const begin = useCallback(() => beginWith(false), [beginWith]);

  // Bounded wait for the stage: then this session plays in picture mode.
  const preparingId = phase.kind === 'preparing' ? phase.session.id : null;
  useEffect(() => {
    if (preparingId === null) return;
    const timer = setTimeout(() => beginWith(true), STAGE_READY_TIMEOUT_MS);
    return () => clearTimeout(timer);
  }, [preparingId, beginWith]);

  // Resume the child's own session after an app restart (once per session id).
  const resumeRef = useRef(resume);
  resumeRef.current = resume;
  const resumeId = resume?.id ?? null;
  useEffect(() => {
    const session = resumeRef.current;
    if (session === null || session.id !== resumeId) return;
    if (phaseRef.current.kind !== 'pick') return;
    if (playedRef.current.has(session.id) || finishedRef.current.has(session.id)) return;
    playedRef.current.add(session.id);
    const started = Date.parse(session.started_at);
    const serverNow = clockSourcesRef.current.wall() + skewRef.current;
    const elapsed = Number.isNaN(started) ? session.duration_ms : Math.max(0, serverNow - started);
    if (elapsed < session.duration_ms) {
      clockRef.current = new SessionClock(clockSourcesRef.current, elapsed);
      // Our clock follows the server's `started_at` (whole seconds) and the skew estimate.
      serverAheadRef.current = EARLY_END_RESUME_AHEAD_MS;
      if (mounted.current) setElapsedMs(elapsed);
      setPhase({ kind: 'running', session, taps: [], resumedAtMs: elapsed });
      return;
    }
    // The schedule ran out while the app was closed: save it now if the server still takes it.
    sendFinish(session, []);
  }, [resumeId, sendFinish, setPhase]);

  const praise = useCallback((touchTimestamp?: number | null): number | null => {
    const current = phaseRef.current;
    const sessionClock = clockRef.current;
    if (current.kind !== 'running' || sessionClock === null) return null;
    const ms = sessionClock.elapsedAt(touchMapper.current.map(touchTimestamp, sessionClock.monoNow()));
    const taps = addTap(current.session, current.taps, ms);
    if (mounted.current) setElapsedMs(ms);
    if (taps === current.taps) return null;
    setPhase({ ...current, taps });
    return Math.round(ms);
  }, [setPhase]);

  const retryFinish = useCallback(() => {
    const current = phaseRef.current;
    if (current.kind !== 'failed' || current.retry === null) return;
    sendFinish(current.retry.session, current.retry.taps, current.retry.resumedAtMs);
  }, [sendFinish]);

  const reset = useCallback(() => {
    const current = phaseRef.current;
    if (current.kind === 'running' || current.kind === 'finishing' || current.kind === 'starting' || current.kind === 'preparing') return;
    clockRef.current = null;
    if (mounted.current) setElapsedMs(0);
    setPhase({ kind: 'pick' });
  }, [setPhase]);

  const now = useCallback(() => clockRef.current?.elapsed() ?? 0, []);

  const canEndEarly = useCallback(() => {
    const current = phaseRef.current;
    const sessionClock = clockRef.current;
    if (current.kind !== 'running' || sessionClock === null || !isSchoolV2Session(current.session)) return false;
    return earlyEndOpen(current.session, sessionClock.elapsed(), serverAheadRef.current);
  }, []);

  const endEarly = useCallback(() => {
    const current = phaseRef.current;
    if (current.kind !== 'running' || finishedRef.current.has(current.session.id) || !canEndEarly()) return;
    sendFinish(current.session, current.taps, current.resumedAtMs, true);
  }, [canEndEarly, sendFinish]);

  return { phase, elapsedMs, start, begin, stagePicture, praise, retryFinish, reset, now, canEndEarly, endEarly, earlyEndDeclined };
}
