/**
 * M5-T14 — the grooming overlay as a scratch card (dog and cat): holding "Počeši" brushes on
 * its own and a clean coat finishes (same strokes to the same endpoint), the VoiceOver /
 * TalkBack "activate" action brushes once, the bar is an accessible progressbar, reduce motion
 * drops the sparkles, the litter change keeps its plain stage.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { api, type ChildPetState } from '@/api/client';
import ChoreOverlay from '@/modules/catCare/ChoreOverlay';
import { brushCleanFromStrokes, EARLY_FINISH_LEAD_MS, HOLD_START_MS } from '@/modules/catCare/brushing';
import { scoreStrokes } from '@/modules/catCare/catGames';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { i18n } from '@/i18n';
import { useAppStore } from '@/store/appStore';
import { makeChoreSession, makeGroomingState, makeLitterState, makeLiveChildState, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, startCareChore: jest.fn(), finishCareChore: jest.fn(), getChildPet: jest.fn() },
  };
});

const mocked = api as unknown as Record<string, jest.Mock>;
const T0 = Date.parse('2026-10-04T12:00:00+02:00');
const clock = { mono: () => Date.now(), wall: () => Date.now() };
const RULES = { duration_ms: 30_000, min_strokes: 10, segments: 3, min_stroke_interval_ms: 150 };

function state(species: 'dog' | 'cat', extra: Record<string, unknown> = {}): ChildPetState {
  return makeLiveChildState({
    pet: { breed_type: species === 'dog' ? 'rottweiler' : 'maine_coon', species } as Partial<ChildPetState['pet']>,
    grooming: makeGroomingState(),
    ...extra,
  });
}

function viewOf(s: ChildPetState): ChildPetView {
  return normalizeChildState(s, 0, T0);
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

function sentStrokes(): number[] {
  const call = mocked.finishCareChore.mock.calls[0] as [string, string, number[]];
  return call[2];
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
  jest.setSystemTime(T0);
  const session = makeChoreSession('grooming');
  mocked.startCareChore.mockResolvedValue({ status: 'accepted', session, state: state('dog') });
  mocked.finishCareChore.mockResolvedValue({
    status: 'accepted',
    result: { session_id: session.id, success: true, reason: null, strokes: 12, min_strokes: 10, segments_hit: 3, segments: 3, matted: false },
    state: state('dog'),
  });
  mocked.getChildPet.mockResolvedValue(state('dog'));
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({ token: 't', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: makePet(), awaitingContract: false });
});

afterEach(() => {
  jest.useRealTimers();
  jest.restoreAllMocks();
});

async function startGame(species: 'dog' | 'cat', reduceMotion = true) {
  renderWithQuery(<ChoreOverlay kind="grooming" view={viewOf(state(species))} onClose={jest.fn()} reduceMotion={reduceMotion} clock={clock} />);
  fireEvent.press(screen.getByTestId('cat-grooming-start'));
  await advance(0);
}

describe('grooming as a scratch card (M5-T14)', () => {
  it('holding "Počeši" brushes on its own; the clean coat finishes with strokes the server counts', async () => {
    await startGame('dog');
    expect(screen.getByTestId('cat-grooming-bar').props.accessibilityValue).toEqual({ min: 0, max: 100, now: 0 });
    expect(screen.getByTestId('cat-grooming-brush-hint').props.children).toBe('Ne moreš potegniti s prstom? Pridrži prst na kužku ali gumb spodaj.');

    fireEvent(screen.getByTestId('cat-grooming-stroke'), 'pressIn');
    await advance(HOLD_START_MS + 2_000);
    expect(screen.getByTestId('cat-grooming-bar').props.accessibilityValue.now).toBeGreaterThan(0);
    // Keep holding through all three steps (to 24.45 s).
    await advance(22_000);
    expect(mocked.finishCareChore).not.toHaveBeenCalled();
    expect(screen.getByTestId('cat-grooming-brush-hint').props.children).toBe('Vsa dlaka je počesana! Še trenutek …');
    expect(screen.getByTestId('cat-grooming-bar').props.accessibilityLabel).toBe('Počesano: 100 %');
    fireEvent(screen.getByTestId('cat-grooming-stroke'), 'pressOut');
    await advance(4_000);

    // The clean coat finished up to 2 s early (within the server's tolerance).
    expect(mocked.finishCareChore).toHaveBeenCalledTimes(1);
    expect(Date.now() - T0).toBeLessThanOrEqual(30_000);
    expect(Date.now() - T0).toBeGreaterThanOrEqual(30_000 - EARLY_FINISH_LEAD_MS);
    const strokes = sentStrokes();
    expect(brushCleanFromStrokes(RULES, strokes)).toBe(true);
    expect(scoreStrokes(strokes, RULES)).toMatchObject({ success: true });
    await advance(0);
    expect(screen.getByTestId('cat-grooming-result-title').props.children).toBe('Mehak in svetleč!');
  }, 30_000);

  it('a short tap on the button is still one stroke; a dirty coat finishes at the end as before', async () => {
    await startGame('cat');
    for (const wait of [1_000, 9_500, 10_400]) {
      await advance(wait);
      fireEvent(screen.getByTestId('cat-grooming-stroke'), 'pressIn');
      fireEvent(screen.getByTestId('cat-grooming-stroke'), 'pressOut');
      fireEvent.press(screen.getByTestId('cat-grooming-stroke'));
    }
    await advance(10_000);
    expect(mocked.finishCareChore).toHaveBeenCalledWith('grooming', makeChoreSession('grooming').id, [1_000, 10_500, 20_900]);
  });

  it('VoiceOver / TalkBack: the pet has the "activate" action (= one stroke) and the cat words', async () => {
    await startGame('cat');
    const stage = screen.getByTestId('cat-grooming-stage-touch');
    expect(stage.props.accessibilityLabel).toBe('Tvoja muca. Pobožaj jo s prstom, da ji počešeš dlako.');
    expect(stage.props.accessibilityActions).toEqual([{ name: 'activate', label: 'Počeši' }]);
    expect(screen.getByTestId('cat-grooming-brush-hint').props.children).toBe('Ne moreš potegniti s prstom? Pridrži prst na muci ali gumb spodaj.');
    await advance(1_000);
    fireEvent(stage, 'accessibilityAction', { nativeEvent: { actionName: 'activate' } });
    expect(screen.getByTestId('cat-grooming-progress').props.children).toBe('Poteze: 1 od 10');
    expect(screen.getByTestId('cat-grooming-bar').props.accessibilityValue.now).toBe(8);
    await advance(29_000);
    expect(sentStrokes()).toEqual([1_000]);
  });

  it('English kitty / pup words', async () => {
    await act(async () => {
      await i18n.changeLanguage('en');
    });
    try {
      await startGame('cat');
      expect(screen.getByTestId('cat-grooming-brush-hint').props.children).toBe("Can't swipe? Hold your finger on your kitty, or hold the button below.");
      expect(screen.getByTestId('cat-grooming-bar').props.accessibilityLabel).toBe('Brushed: 0 %');
    } finally {
      await act(async () => {
        await i18n.changeLanguage('sl');
      });
    }
  });

  it('sparkles only without reduce motion; patches are drawn after layout', async () => {
    await startGame('dog', false);
    fireEvent(screen.getByTestId('cat-grooming-stage-touch'), 'layout', { nativeEvent: { layout: { x: 0, y: 0, width: 300, height: 400 } } });
    // The layers are hidden from VoiceOver / TalkBack (decoration only).
    const hidden = { includeHiddenElements: true };
    expect(screen.getByTestId('cat-grooming-patch-0', hidden)).toBeTruthy();
    expect(screen.getByTestId('cat-grooming-patch-5', hidden)).toBeTruthy();
    expect(screen.getByTestId('cat-grooming-brush', hidden)).toBeTruthy();
    expect(screen.queryByTestId('cat-grooming-patch-0')).toBeNull();
  });

  it('the litter change keeps its plain stroke stage (no bar)', async () => {
    const view = viewOf(state('cat', { litter: makeLitterState() }));
    const session = makeChoreSession('litter_change');
    mocked.startCareChore.mockResolvedValue({ status: 'accepted', session, state: state('cat') });
    renderWithQuery(<ChoreOverlay kind="litter_change" view={view} onClose={jest.fn()} reduceMotion clock={clock} />);
    fireEvent.press(screen.getByTestId('cat-litter_change-start'));
    await advance(0);
    expect(screen.getByTestId('cat-litter_change-stage-touch')).toBeTruthy();
    expect(screen.queryByTestId('cat-litter_change-bar')).toBeNull();
    expect(screen.getByTestId('cat-litter_change-stroke')).toBeTruthy();
  });
});
