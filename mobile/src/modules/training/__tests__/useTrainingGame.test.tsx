/**
 * M5-R03 game loop with fake timers: tap offsets are ms since the LOCAL start (the start
 * response's arrival, so latency never shifts them), the session finishes by itself
 * exactly once, background → finish within the TTL or explain the expiry, refusals and
 * locks become calm messages with the server state in the cache, offline finish retries.
 */
import type { ReactNode } from 'react';
import { act, renderHook } from '@testing-library/react-native';
import { AppState, type AppStateStatus } from 'react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { ApiError, api } from '@/api/client';
import { childPetKey, writeChildState } from '@/hooks/queries/useChildPet';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { GAME_TICK_MS, useTrainingGame } from '@/modules/training/useTrainingGame';
import { TRAINING_STRINGS } from '@/modules/training/training';
import { useAppStore } from '@/store/appStore';
import {
  makeEnabledTraining,
  makeLiveChildState,
  makePet,
  makeTrainingResult,
  makeTrainingSessionPayload,
} from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, startTraining: jest.fn(), finishTraining: jest.fn(), getChildPet: jest.fn() },
  };
});

const startTraining = api.startTraining as jest.Mock;
const finishTraining = api.finishTraining as jest.Mock;
const getChildPet = api.getChildPet as jest.Mock;

type Listener = (state: AppStateStatus) => void;
let listeners: Listener[] = [];
/** ms the monotonic clock "lost" while the phone slept. */
let slept = 0;
const clock = { mono: () => Date.now() - slept, wall: () => Date.now() };

const T0 = Date.parse('2026-10-04T12:00:00+02:00');
const SESSION = makeTrainingSessionPayload();

const stateWith = (training = makeEnabledTraining()) => makeLiveChildState({ training });
const startBody = () => ({ status: 'accepted', session: SESSION, state: stateWith(makeEnabledTraining({ can_start: false })) });
const finishBody = (status = 'accepted') => ({
  status,
  result: makeTrainingResult(),
  state: stateWith(makeEnabledTraining({ today_done: true, daily_budget_left_seconds: 250 })),
});

function setup() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false, gcTime: Infinity } },
  });
  writeChildState(client, stateWith());
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  const hook = renderHook(() => useTrainingGame({ clockSkewMs: 0, timezone: 'Europe/Ljubljana', clock }), { wrapper });
  return { client, ...hook, cached: () => client.getQueryData<ChildPetView>(childPetKey) };
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

/** Advance to `ms` since the local start (the start answer arrived at `startedAt`). */
async function advanceTo(startedAt: number, ms: number) {
  await advance(startedAt + ms - Date.now());
}

function sleepInBackground(ms: number) {
  slept += ms;
  jest.setSystemTime(Date.now() + ms);
}

async function backToForeground() {
  await act(async () => {
    listeners.forEach((l) => l('active'));
    await jest.advanceTimersByTimeAsync(0);
  });
}

describe('useTrainingGame', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    listeners = [];
    slept = 0;
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    jest.setSystemTime(T0);
    jest.spyOn(AppState, 'addEventListener').mockImplementation((_type, listener) => {
      listeners.push(listener as Listener);
      return { remove: () => (listeners = listeners.filter((l) => l !== listener)) } as ReturnType<typeof AppState.addEventListener>;
    });
    getChildPet.mockResolvedValue(stateWith());
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({ token: 't', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: makePet(), awaitingContract: false });
  });

  afterEach(() => {
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('taps are ms since the local start (after a 400 ms start request), one per cue; finishes once', async () => {
    let resolveStart: (v: unknown) => void = () => undefined;
    startTraining.mockReturnValueOnce(new Promise((r) => (resolveStart = r)));
    finishTraining.mockResolvedValue(finishBody());
    const { result, cached } = setup();

    act(() => result.current.start('sit'));
    expect(result.current.phase.kind).toBe('starting');
    await advance(400);
    expect(startTraining).toHaveBeenCalledWith('sit');
    await act(async () => {
      resolveStart(startBody());
      await jest.advanceTimersByTimeAsync(0);
    });
    const startedAt = Date.now();
    expect(result.current.phase.kind).toBe('running');
    expect(cached()?.training.can_start).toBe(false);

    await advanceTo(startedAt, 1_000);
    act(() => result.current.praise()); // lead-in: ignored
    await advanceTo(startedAt, 3_200);
    act(() => result.current.praise()); // cue 0 obeyed at 3000 → in time
    await advanceTo(startedAt, 3_700);
    act(() => result.current.praise()); // same cue: ignored
    await advanceTo(startedAt, 9_000);
    act(() => result.current.praise()); // cue 1 (no obey)
    const phase = result.current.phase;
    expect(phase.kind === 'running' && phase.taps).toEqual([3_200, 9_000]);

    // The game loop finishes by itself at 50 s — one request.
    await advanceTo(startedAt, 50_000 + GAME_TICK_MS);
    expect(finishTraining).toHaveBeenCalledTimes(1);
    expect(finishTraining).toHaveBeenCalledWith(SESSION.id, [3_200, 9_000]);
    expect(result.current.phase.kind).toBe('result');
    expect(cached()?.training.today_done).toBe(true);

    // Nothing sends it again: more ticks, back from the background.
    await advance(5_000);
    await backToForeground();
    expect(finishTraining).toHaveBeenCalledTimes(1);
  });

  it('no double finish while the first one is in flight', async () => {
    startTraining.mockResolvedValueOnce(startBody());
    let resolveFinish: (v: unknown) => void = () => undefined;
    finishTraining.mockReturnValueOnce(new Promise((r) => (resolveFinish = r)));
    const { result } = setup();
    act(() => result.current.start('come'));
    await advance(0);
    await advance(50_000 + GAME_TICK_MS);
    expect(result.current.phase.kind).toBe('finishing');
    await advance(2_000);
    await backToForeground();
    expect(finishTraining).toHaveBeenCalledTimes(1);
    await act(async () => {
      resolveFinish(finishBody('unchanged'));
      await jest.advanceTimersByTimeAsync(0);
    });
    const phase = result.current.phase;
    expect(phase.kind === 'result' && phase.status).toBe('unchanged');
  });

  it('background within the TTL: the clock follows the wall clock and the session is finished on return', async () => {
    startTraining.mockResolvedValueOnce(startBody());
    finishTraining.mockResolvedValue(finishBody());
    const { result } = setup();
    act(() => result.current.start('sit'));
    await advance(0);
    const startedAt = Date.now();
    await advanceTo(startedAt, 3_100);
    act(() => result.current.praise());

    // The phone slept 50 s (monotonic clock stood still; no timers ran).
    sleepInBackground(50_000);
    await backToForeground();
    expect(finishTraining).toHaveBeenCalledTimes(1);
    expect(finishTraining).toHaveBeenCalledWith(SESSION.id, [3_100]);
    expect(result.current.phase.kind).toBe('result');
  });

  it('background past the TTL: explains the expiry, sends nothing, refetches the state', async () => {
    startTraining.mockResolvedValueOnce(startBody());
    const { result, client } = setup();
    act(() => result.current.start('sit'));
    await advance(0);
    sleepInBackground(3 * 60_000);
    await backToForeground();
    expect(finishTraining).not.toHaveBeenCalled();
    const phase = result.current.phase;
    expect(phase.kind).toBe('failed');
    expect(phase.kind === 'failed' && phase.message).toBe(TRAINING_STRINGS.errors.expiredWhileAway);
    expect(phase.kind === 'failed' && phase.retry).toBeNull();
    expect(client.getQueryState(childPetKey)?.isInvalidated).toBe(true);
  });

  it('start refused (422 budget used): calm message, server state in the cache', async () => {
    startTraining.mockRejectedValueOnce(
      new ApiError('x', 422, { reason: 'training_daily_budget_used', next_allowed_at: '2026-10-05T00:00:00+02:00', state: stateWith(makeEnabledTraining({ can_start: false, daily_budget_left_seconds: 0 })) }),
    );
    const { result, cached } = setup();
    act(() => result.current.start('place'));
    await advance(0);
    const phase = result.current.phase;
    expect(phase.kind === 'failed' && phase.message).toBe(TRAINING_STRINGS.errors.training_daily_budget_used);
    expect(cached()?.training.daily_budget_left_seconds).toBe(0);
    act(() => result.current.reset());
    expect(result.current.phase.kind).toBe('pick');
  });

  it('start locked (423 hard stop) → lock text', async () => {
    startTraining.mockRejectedValueOnce(new ApiError('x', 423, { reason: 'hard_stopped', state: stateWith() }));
    const { result } = setup();
    act(() => result.current.start('sit'));
    await advance(0);
    const phase = result.current.phase;
    expect(phase.kind === 'failed' && phase.message).toBe('Starš je ustavil igro.');
  });

  it('a 200 without a playable schedule is a failure, never a guessed game', async () => {
    startTraining.mockResolvedValueOnce({ status: 'accepted', session: { id: 'x' }, state: stateWith() });
    const { result } = setup();
    act(() => result.current.start('sit'));
    await advance(0);
    const phase = result.current.phase;
    expect(phase.kind === 'failed' && phase.message).toBe(TRAINING_STRINGS.errors.badResponse);
  });

  it('finish offline → retry with the same taps succeeds', async () => {
    startTraining.mockResolvedValueOnce(startBody());
    finishTraining.mockRejectedValueOnce(new TypeError('Network request failed')).mockResolvedValueOnce(finishBody());
    const { result } = setup();
    act(() => result.current.start('sit'));
    await advance(0);
    const startedAt = Date.now();
    await advanceTo(startedAt, 15_300);
    act(() => result.current.praise());
    await advanceTo(startedAt, 50_000 + GAME_TICK_MS);
    let phase = result.current.phase;
    expect(phase.kind === 'failed' && phase.retry !== null).toBe(true);
    act(() => result.current.retryFinish());
    await advance(0);
    expect(finishTraining).toHaveBeenCalledTimes(2);
    expect(finishTraining).toHaveBeenLastCalledWith(SESSION.id, [15_300]);
    phase = result.current.phase;
    expect(phase.kind).toBe('result');
  });

  it('finish 422 expired → message, no retry', async () => {
    startTraining.mockResolvedValueOnce(startBody());
    finishTraining.mockRejectedValueOnce(new ApiError('x', 422, { reason: 'training_session_expired', state: stateWith() }));
    const { result } = setup();
    act(() => result.current.start('sit'));
    await advance(0);
    await advance(50_000 + GAME_TICK_MS);
    const phase = result.current.phase;
    expect(phase.kind === 'failed' && phase.message).toBe(TRAINING_STRINGS.errors.training_session_expired);
    expect(phase.kind === 'failed' && phase.retry).toBeNull();
  });

  it('praise / start are ignored outside their phase', async () => {
    const { result } = setup();
    act(() => result.current.praise());
    expect(result.current.phase.kind).toBe('pick');
    startTraining.mockReturnValue(new Promise(() => undefined));
    act(() => result.current.start('sit'));
    act(() => result.current.start('come'));
    await advance(0);
    expect(startTraining).toHaveBeenCalledTimes(1);
    expect(startTraining).toHaveBeenCalledWith('sit');
  });
});
