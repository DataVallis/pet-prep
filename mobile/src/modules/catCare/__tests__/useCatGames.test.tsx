/**
 * M5-R06-08a game loops with fake timers (the training rules, M5-R03): inputs are ms since
 * the LOCAL start, each session finishes by itself exactly once with exactly what the server
 * scores, refusals / locks become calm texts with the server state in the cache, an offline
 * finish may be retried, background → finish within the TTL or explain the expiry, the own
 * running session resumes after a restart, "Ustavi" sends nothing and is never resumed.
 */
import { type ReactNode } from 'react';
import { act, renderHook } from '@testing-library/react-native';
import { AppState, type AppStateStatus } from 'react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { ApiError, api, type ChildPetState } from '@/api/client';
import { childPetKey, writeChildState } from '@/hooks/queries/useChildPet';
import { CAT_GAME_TICK_MS } from '@/modules/catCare/useCatSessionGame';
import { useChoreGame, useScratchingGame, useWandGame } from '@/modules/catCare/useCatGames';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { useAppStore } from '@/store/appStore';
import {
  makeChoreSession,
  makeGroomingState,
  makeLiveChildState,
  makeLitterState,
  makePet,
  makeScratchingSession,
  makeScratchingState,
  makeWandSession,
  makeWandState,
} from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      startWand: jest.fn(),
      finishWand: jest.fn(),
      startCareChore: jest.fn(),
      finishCareChore: jest.fn(),
      startScratching: jest.fn(),
      finishScratching: jest.fn(),
      getChildPet: jest.fn(),
    },
  };
});

const startWand = api.startWand as jest.Mock;
const finishWand = api.finishWand as jest.Mock;
const startCareChore = api.startCareChore as jest.Mock;
const finishCareChore = api.finishCareChore as jest.Mock;
const startScratching = api.startScratching as jest.Mock;
const finishScratching = api.finishScratching as jest.Mock;
const getChildPet = api.getChildPet as jest.Mock;

type Listener = (state: AppStateStatus) => void;
let listeners: Listener[] = [];
let slept = 0;
const clock = { mono: () => Date.now() - slept, wall: () => Date.now() };

const T0 = Date.parse('2026-10-04T12:00:00+02:00');

function catState(o: { wand?: unknown; litter?: unknown; grooming?: unknown; scratching?: unknown } = {}): ChildPetState {
  return makeLiveChildState({
    pet: { breed_type: 'maine_coon', species: 'cat' } as Partial<ChildPetState['pet']>,
    wand: o.wand ?? makeWandState(),
    litter: o.litter ?? makeLitterState(),
    grooming: o.grooming ?? makeGroomingState(),
    scratching: o.scratching ?? makeScratchingState(),
  });
}

function refusal(status: number, body: Record<string, unknown>) {
  return new ApiError('refused', status, body);
}

function wrapperFor(client: QueryClient) {
  return ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

function newClient(state: ChildPetState = catState()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false, gcTime: Infinity } } });
  writeChildState(client, state);
  return client;
}

function viewOf(client: QueryClient): ChildPetView {
  return client.getQueryData<ChildPetView>(childPetKey) as ChildPetView;
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

async function flush() {
  await advance(0);
}

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
  getChildPet.mockResolvedValue(catState());
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({ token: 't', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: makePet(), awaitingContract: false });
});

afterEach(() => {
  jest.useRealTimers();
  jest.restoreAllMocks();
});

const wandStart = () => ({ status: 'accepted', session: makeWandSession(), state: catState({ wand: makeWandState({ can_start: false, session: makeWandSession(), session_running: true }) }) });
const wandFinish = (status = 'accepted', success = true) => ({
  status,
  result: { session_id: makeWandSession().id, success, reason: success ? null : 'not_spread', away_moves: 9, toward_moves: 1, segments_hit: success ? 4 : 2, segments: 4, pounces_hit: 3, pounces: 3 },
  state: catState({ wand: makeWandState({ sessions_today: success ? 1 : 0, can_start: !success }) }),
});

describe('useWandGame', () => {
  it('moves are ms since the local start (after a 300 ms start request); finishes once at 60 s with {t, away}', async () => {
    let resolveStart: (v: unknown) => void = () => undefined;
    startWand.mockReturnValueOnce(new Promise((r) => (resolveStart = r)));
    finishWand.mockResolvedValue(wandFinish());
    const client = newClient();
    const { result } = renderHook(() => useWandGame(viewOf(client), { clock }), { wrapper: wrapperFor(client) });

    act(() => result.current.start());
    expect(result.current.phase.kind).toBe('starting');
    await advance(300);
    await act(async () => {
      resolveStart(wandStart());
      await jest.advanceTimersByTimeAsync(0);
    });
    expect(result.current.phase.kind).toBe('running');
    // The server's cache now says a game is running (start answer's state).
    expect(viewOf(client).cat.wand?.session_running).toBe(true);

    await advance(2_000);
    act(() => result.current.setInput((moves) => [...moves, { t: result.current.msAt(null), away: true }]));
    await advance(13_500);
    act(() => result.current.setInput((moves) => [...moves, { t: result.current.msAt(null), away: false }]));
    expect(finishWand).not.toHaveBeenCalled();

    await advance(60_000 - 15_500 - CAT_GAME_TICK_MS);
    expect(finishWand).not.toHaveBeenCalled();
    await advance(CAT_GAME_TICK_MS * 2);
    expect(finishWand).toHaveBeenCalledTimes(1);
    expect(finishWand).toHaveBeenCalledWith(makeWandSession().id, [
      { t: 2_000, away: true },
      { t: 15_500, away: false },
    ]);
    await flush();
    expect(result.current.phase).toMatchObject({ kind: 'result', status: 'accepted', result: { success: true } });
    expect(viewOf(client).cat.wand?.sessions_today).toBe(1);
    await advance(5_000);
    expect(finishWand).toHaveBeenCalledTimes(1);
  });

  it('a "didn\'t count" verdict is a result (rejected), not an error', async () => {
    startWand.mockResolvedValue(wandStart());
    finishWand.mockResolvedValue(wandFinish('rejected', false));
    const client = newClient();
    const { result } = renderHook(() => useWandGame(viewOf(client), { clock }), { wrapper: wrapperFor(client) });
    act(() => result.current.start());
    await flush();
    await advance(60_200);
    expect(result.current.phase).toMatchObject({ kind: 'result', status: 'rejected', result: { success: false, reason: 'not_spread' } });
    act(() => result.current.reset());
    expect(result.current.phase.kind).toBe('intro');
  });

  it('a refused start: the family-local time in the text, the server state in the cache', async () => {
    const after = catState({ wand: makeWandState({ can_start: false, blocked_reason: 'wand_too_soon', next_allowed_at: '2026-10-04T14:05:00+02:00' }) });
    startWand.mockRejectedValue(refusal(422, { reason: 'wand_too_soon', next_allowed_at: '2026-10-04T14:05:00+02:00', state: after }));
    const client = newClient();
    const { result } = renderHook(() => useWandGame(viewOf(client), { clock }), { wrapper: wrapperFor(client) });
    act(() => result.current.start());
    await flush();
    expect(result.current.phase).toEqual({ kind: 'failed', message: 'Muca po zadnji igri počiva. Igrata se lahko spet ob 14:05.', retry: null });
    expect(viewOf(client).cat.wand?.blocked_reason).toBe('wand_too_soon');
  });

  it('a lock (423) at the vet names the cat and the end', async () => {
    startWand.mockRejectedValue(refusal(423, { reason: 'ill', locked_until: '2026-10-04T18:30:00+02:00', state: catState() }));
    const client = newClient();
    const { result } = renderHook(() => useWandGame(viewOf(client), { clock }), { wrapper: wrapperFor(client) });
    act(() => result.current.start());
    await flush();
    expect(result.current.phase).toMatchObject({ kind: 'failed', message: 'Muca je pri veterinarju do 18:30.' });
  });

  it('a malformed 200 start is a failure (never guessed), its state still lands', async () => {
    startWand.mockResolvedValue({ status: 'accepted', session: { id: 'x' }, state: catState({ wand: makeWandState({ goal: 3 }) }) });
    const client = newClient();
    const { result } = renderHook(() => useWandGame(viewOf(client), { clock }), { wrapper: wrapperFor(client) });
    act(() => result.current.start());
    await flush();
    expect(result.current.phase).toMatchObject({ kind: 'failed', retry: null });
    expect(viewOf(client).cat.wand?.goal).toBe(3);
  });

  it('offline finish → "Poskusi znova" with the same moves; a final refusal → no retry', async () => {
    startWand.mockResolvedValue(wandStart());
    finishWand.mockRejectedValueOnce(new TypeError('Network request failed')).mockResolvedValueOnce(wandFinish('unchanged'));
    const client = newClient();
    const { result } = renderHook(() => useWandGame(viewOf(client), { clock }), { wrapper: wrapperFor(client) });
    act(() => result.current.start());
    await flush();
    act(() => result.current.setInput(() => [{ t: 1_000, away: true }]));
    await advance(60_200);
    expect(result.current.phase).toMatchObject({ kind: 'failed', message: 'Ni povezave. Preveri internet in poskusi znova.' });
    act(() => result.current.retryFinish());
    await flush();
    expect(finishWand).toHaveBeenLastCalledWith(makeWandSession().id, [{ t: 1_000, away: true }]);
    // `unchanged` after our own lost first attempt is shown as the verdict it was.
    expect(result.current.phase).toMatchObject({ kind: 'result', status: 'accepted' });

    finishWand.mockRejectedValueOnce(refusal(422, { reason: 'wand_session_interrupted', state: catState() }));
    // Every start gets a new session id from the server.
    startWand.mockResolvedValue({ ...wandStart(), session: makeWandSession({ id: 'second-session' }) });
    act(() => result.current.reset());
    act(() => result.current.start());
    await flush();
    await advance(60_200);
    expect(result.current.phase).toMatchObject({ kind: 'failed', retry: null, message: 'Igra je bila ustavljena, zato ni štela. Začni novo malo kasneje.' });
  });

  it('back from the background within the TTL finishes; after it explains the expiry (no request)', async () => {
    startWand.mockResolvedValue(wandStart());
    finishWand.mockResolvedValue(wandFinish());
    const client = newClient();
    const { result } = renderHook(() => useWandGame(viewOf(client), { clock }), { wrapper: wrapperFor(client) });
    act(() => result.current.start());
    await flush();
    // Phone sleeps 90 s (TTL = 60 s game + 60 s grace).
    slept += 90_000;
    jest.setSystemTime(Date.now() + 90_000);
    await act(async () => {
      listeners.forEach((l) => l('active'));
      await jest.advanceTimersByTimeAsync(0);
    });
    expect(finishWand).toHaveBeenCalledTimes(1);

    finishWand.mockClear();
    startWand.mockResolvedValue({ ...wandStart(), session: makeWandSession({ id: 'second-session' }) });
    act(() => result.current.reset());
    act(() => result.current.start());
    await flush();
    slept += 200_000;
    jest.setSystemTime(Date.now() + 200_000);
    await act(async () => {
      listeners.forEach((l) => l('active'));
      await jest.advanceTimersByTimeAsync(0);
    });
    expect(finishWand).not.toHaveBeenCalled();
    expect(result.current.phase).toMatchObject({ kind: 'failed', retry: null, message: expect.stringContaining('aplikacija zaprta') });
  });

  /** The child's own wand session as the state carries it, started `agoMs` before now. */
  function ownRunning(agoMs: number, id = 'resume-1') {
    const start = T0 - agoMs;
    const iso = (ms: number) => new Date(ms).toISOString();
    return makeWandSession({ id, started_at: iso(start), ends_at: iso(start + 60_000), expires_at: iso(start + 120_000) });
  }

  it('resumes the own running game while it can still count (first quarter, no pounce missed); a stopped one never', async () => {
    const running = ownRunning(5_000);
    const client = newClient(catState({ wand: makeWandState({ can_start: false, session: running, session_running: true }) }));
    finishWand.mockResolvedValue(wandFinish('rejected', false));
    const { result } = renderHook(() => useWandGame(viewOf(client), { clock }), { wrapper: wrapperFor(client) });
    expect(result.current.phase).toMatchObject({ kind: 'running', resumedAtMs: 5_000 });
    await advance(55_200);
    expect(finishWand).toHaveBeenCalledWith(running.id, []);

    // Another restart: the child had stopped this session → stays in the intro.
    act(() => useAppStore.getState().abandonCatSession('stopped-1'));
    const stopped = ownRunning(5_000, 'stopped-1');
    const client2 = newClient(catState({ wand: makeWandState({ session: stopped, session_running: true }) }));
    const second = renderHook(() => useWandGame(viewOf(client2), { clock }), { wrapper: wrapperFor(client2) });
    expect(second.result.current.phase.kind).toBe('intro');
  });

  it('QA M1: a game that can no longer count (past the first quarter / a pounce missed) is not resumed', async () => {
    for (const ago of [16_000, 12_000]) {
      // 16 s: past the first quarter; 12 s with a pounce at 8 s: its 2 s window closed at 10 s.
      const running = { ...ownRunning(ago, `late-${ago}`), pounces_ms: ago === 12_000 ? [8_000, 30_000] : [20_000, 40_000] };
      const client = newClient(catState({ wand: makeWandState({ can_start: true, session: running, session_running: true }) }));
      const { result, unmount } = renderHook(() => useWandGame(viewOf(client), { clock }), { wrapper: wrapperFor(client) });
      expect(result.current.phase.kind).toBe('intro');
      await advance(120_000);
      expect(finishWand).not.toHaveBeenCalled();
      unmount();
      jest.setSystemTime(T0);
    }
  });

  it('QA M1: time over but TTL left → the empty finish is sent (frees siblings), the text says it ran out', async () => {
    finishWand.mockResolvedValue(wandFinish('rejected', false));
    const running = ownRunning(70_000);
    const client = newClient(catState({ wand: makeWandState({ session: running, session_running: true }) }));
    const { result } = renderHook(() => useWandGame(viewOf(client), { clock }), { wrapper: wrapperFor(client) });
    await flush();
    expect(finishWand).toHaveBeenCalledWith(running.id, []);
    expect(result.current.phase).toMatchObject({ kind: 'failed', retry: null, message: expect.stringContaining('aplikacija zaprta') });
  });

  it('"Ustavi" sends nothing, marks the session stopped and goes back to the intro', async () => {
    startWand.mockResolvedValue(wandStart());
    const client = newClient();
    const { result } = renderHook(() => useWandGame(viewOf(client), { clock }), { wrapper: wrapperFor(client) });
    act(() => result.current.start());
    await flush();
    act(() => result.current.stop());
    expect(result.current.phase.kind).toBe('intro');
    expect(useAppStore.getState().abandonedCatSessions).toEqual([makeWandSession().id]);
    await advance(120_000);
    expect(finishWand).not.toHaveBeenCalled();
  });

  it('a logout mid-request: the answer is not written for the next session', async () => {
    let resolveStart: (v: unknown) => void = () => undefined;
    startWand.mockReturnValueOnce(new Promise((r) => (resolveStart = r)));
    const client = newClient();
    const { result } = renderHook(() => useWandGame(viewOf(client), { clock }), { wrapper: wrapperFor(client) });
    act(() => result.current.start());
    useAppStore.getState().signIn({ token: 'u', user: { id: 3, name: 'Tim', email: null, role: 'child' }, pet: makePet(), awaitingContract: false });
    await act(async () => {
      resolveStart(wandStart());
      await jest.advanceTimersByTimeAsync(0);
    });
    expect(viewOf(client).cat.wand?.session_running).toBe(false);
  });
});

describe('useChoreGame', () => {
  it('grooming: strokes {t} sent once at the end of the (matted, 60 s) session', async () => {
    const session = makeChoreSession('grooming', { matted: true, duration_ms: 60_000, min_strokes: 20 });
    startCareChore.mockResolvedValue({ status: 'accepted', session, state: catState() });
    finishCareChore.mockResolvedValue({
      status: 'accepted',
      result: { session_id: session.id, success: true, reason: null, strokes: 2, min_strokes: 20, segments_hit: 2, segments: 3, matted: true },
      state: catState({ grooming: makeGroomingState({ done_this_week: 2, matted: false }) }),
    });
    const client = newClient(catState({ grooming: makeGroomingState({ matted: true, session_seconds: 60 }) }));
    const { result } = renderHook(() => useChoreGame('grooming', viewOf(client), { clock }), { wrapper: wrapperFor(client) });
    act(() => result.current.start());
    await flush();
    expect(startCareChore).toHaveBeenCalledWith('grooming');
    await advance(1_000);
    act(() => result.current.setInput((s) => [...s, result.current.msAt(null)]));
    await advance(29_000);
    act(() => result.current.setInput((s) => [...s, result.current.msAt(null)]));
    await advance(29_000);
    expect(finishCareChore).not.toHaveBeenCalled(); // 60 s, not 30 s
    await advance(1_200);
    expect(finishCareChore).toHaveBeenCalledWith('grooming', session.id, [1_000, 30_000]);
    expect(result.current.phase).toMatchObject({ kind: 'result', result: { matted: true } });
    expect(viewOf(client).cat.grooming?.matted).toBe(false);
  });

  it('litter change refused this week → the next week in words', async () => {
    startCareChore.mockRejectedValue(refusal(422, { reason: 'litter_change_done', next_allowed_at: '2026-10-08T09:30:00+02:00', state: catState() }));
    const client = newClient(catState({ litter: makeLitterState({}, { done: true, can_start: false, blocked_reason: 'litter_change_done' }) }));
    const { result } = renderHook(() => useChoreGame('litter_change', viewOf(client), { clock }), { wrapper: wrapperFor(client) });
    act(() => result.current.start());
    await flush();
    expect(result.current.phase).toMatchObject({ kind: 'failed', message: 'Pesek je ta teden že zamenjan. Naslednja menjava je v četrtek ob 09:30.' });
  });
});

describe('useScratchingGame', () => {
  const session = makeScratchingSession();
  const verdict = (success: boolean, reason: string | null, praise: number | null) => ({
    status: success ? 'accepted' : 'rejected',
    result: { session_id: session.id, success, reason, praise_ms: praise, land_at_ms: 1_200, delay_ms: praise === null ? null : praise - 1_200, praise_window_ms: 3_000 },
    state: catState({ scratching: makeScratchingState({ active: success ? null : makeScratchingState().active }) }),
  });

  async function started(client: QueryClient) {
    startScratching.mockResolvedValue({ status: 'accepted', session, state: catState() });
    const hook = renderHook(() => useScratchingGame(viewOf(client), { clock }), { wrapper: wrapperFor(client) });
    act(() => hook.result.current.start());
    await flush();
    return hook;
  }

  it('a praise after the landing is sent at once', async () => {
    finishScratching.mockResolvedValue(verdict(true, null, 1_900));
    const client = newClient();
    const { result } = await started(client);
    await advance(1_900);
    act(() => result.current.setInput((p) => (p === null ? result.current.msAt(null) : p)));
    await flush();
    expect(finishScratching).toHaveBeenCalledWith(session.id, 1_900);
    expect(result.current.phase).toMatchObject({ kind: 'result', result: { success: true } });
    expect(viewOf(client).cat.scratching?.active).toBeNull();
  });

  it('a praise before the landing decides ("too early") but is sent only once the cat landed', async () => {
    finishScratching.mockResolvedValue(verdict(false, 'too_early', 500));
    const client = newClient();
    const { result } = await started(client);
    await advance(500);
    act(() => result.current.setInput((p) => (p === null ? result.current.msAt(null) : p)));
    await advance(300);
    // A later tap doesn't replace the first one.
    act(() => result.current.setInput((p) => (p === null ? result.current.msAt(null) : p)));
    expect(finishScratching).not.toHaveBeenCalled();
    await advance(500);
    expect(finishScratching).toHaveBeenCalledWith(session.id, 500);
    expect(result.current.phase).toMatchObject({ kind: 'result', status: 'rejected', result: { reason: 'too_early' } });
  });

  it('no praise: finished with null when the 3 s window closed', async () => {
    finishScratching.mockResolvedValue(verdict(false, 'no_praise', null));
    const client = newClient();
    await started(client);
    await advance(4_100);
    expect(finishScratching).not.toHaveBeenCalled();
    await advance(200);
    expect(finishScratching).toHaveBeenCalledWith(session.id, null);
  });

  it('nothing to redirect: a calm text', async () => {
    startScratching.mockRejectedValue(refusal(422, { reason: 'scratching_not_needed', state: catState({ scratching: makeScratchingState({ active: null }) }) }));
    const client = newClient();
    const { result } = renderHook(() => useScratchingGame(viewOf(client), { clock }), { wrapper: wrapperFor(client) });
    act(() => result.current.start());
    await flush();
    expect(result.current.phase).toMatchObject({ kind: 'failed', message: 'Muca ni ničesar opraskala. Vse je v redu!' });
  });
});

describe('dogs are unaffected', () => {
  it('a dog state has no cat blocks', () => {
    expect(normalizeChildState(makeLiveChildState()).cat).toEqual({ wand: null, litter: null, grooming: null, scratching: null });
  });
});
