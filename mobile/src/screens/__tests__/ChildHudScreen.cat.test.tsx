/**
 * M5-R06-08b — the cat in the child HUD: its own dock (food, water, "Pesek", "Igra", clean —
 * no walk, no step sync, no "Šola"), the meter "Igra", the cat's texts ("muca"), chips
 * "Počeši" / "Menjava peska" / "Crkljanje", "Na praskalnik" on the scratched sofa, the
 * "first aid" note, mini-games opened from the dock, a broadcast updating `view.cat`, and
 * the dog's HUD untouched.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { setTextSpecies } from '@/i18n';
import { useAppStore } from '@/store/appStore';
import {
  makeBehaviourEvent,
  makeBroadcast,
  makeGroomingState,
  makeLitterState,
  makeLiveChildState,
  makePet,
  makePlayState,
  makeScratchingState,
  makeWandSession,
  makeWandState,
} from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import type { PetUpdatedBroadcast } from '@/types';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      getChildPet: jest.fn(),
      syncSteps: jest.fn(),
      logout: jest.fn(),
    },
  };
});

const mockSocket: { handler: ((event: PetUpdatedBroadcast) => void) | null } = { handler: null };
jest.mock('@/hooks/usePetWebSocket', () => ({
  usePetWebSocket: jest.fn((_petId: number | null, handler?: (event: PetUpdatedBroadcast) => void) => {
    mockSocket.handler = handler ?? null;
  }),
}));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(30_000);

const getChildPet = api.getChildPet as jest.Mock;
const syncSteps = api.syncSteps as jest.Mock;

async function flush(ms = 0) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

type Overrides = Parameters<typeof makeLiveChildState>[0];

/** A young domestic cat at 12:00 Ljubljana: one litter use due 15:00, wand 0/2, nothing open. */
function catState(o: Overrides = {}) {
  return makeLiveChildState({
    wand: makeWandState(),
    litter: makeLitterState(),
    grooming: null,
    scratching: makeScratchingState({ active: null, blocked_reason: 'scratching_not_needed', can_start: false }),
    ...o,
    pet: { breed_type: 'domestic_cat', species: 'cat', ...o.pet },
  });
}

async function renderHud(state: ReturnType<typeof makeLiveChildState>) {
  getChildPet.mockResolvedValue(state);
  const utils = renderWithQuery(<ChildHudScreen />);
  await flush();
  await flush();
  expect(screen.getByTestId('action-feed', { includeHiddenElements: true })).toBeTruthy();
  return utils;
}

const isDisabled = (testID: string) => screen.getByTestId(testID).props.accessibilityState?.disabled === true;

describe('ChildHudScreen — cat (M5-R06-08b)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    jest.setSystemTime(new Date('2026-10-04T12:00:00+02:00'));
    mockSocket.handler = null;
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({
      token: 'child-token',
      user: { id: 2, name: 'Maja', email: null, role: 'child' },
      pet: makePet({ id: 7, breed_type: 'domestic_cat', species: 'cat', born_at: '2026-10-01T08:00:00Z' }),
      awaitingContract: false,
    });
    useAppStore.getState().setWsStatus('connected');
  });

  afterEach(() => {
    jest.useRealTimers();
    setTextSpecies(null);
  });

  it('a cat\'s dock: food, water, "Pesek", "Igra", clean — no walk, no take-out, no "Šola"', async () => {
    await renderHud(catState());
    expect(screen.getByTestId('action-scoop')).toBeTruthy();
    expect(screen.getByTestId('action-wand')).toBeTruthy();
    expect(screen.getByTestId('action-clean')).toBeTruthy();
    expect(screen.queryByTestId('action-walk')).toBeNull();
    expect(screen.queryByTestId('action-take-out')).toBeNull();
    expect(screen.queryByTestId('hud-training-open')).toBeNull();
    // Dock labels and the play meter (SL: "muca").
    expect(screen.getByText('Pesek', { includeHiddenElements: true })).toBeTruthy();
    expect(screen.getAllByText('Igra', { includeHiddenElements: true }).length).toBeGreaterThanOrEqual(2);
    expect(screen.getByTestId('metric-energy')).toBeTruthy();
    // The litter use waits (due 15:00): the one solid-mint button is "Pesek", not food.
    expect(screen.getByText('ob 15:00', { includeHiddenElements: true })).toBeTruthy();
    // Wand 0 of 2 today.
    expect(screen.getByText('0/2', { includeHiddenElements: true })).toBeTruthy();
    // Never a step sync for a cat.
    expect(syncSteps).not.toHaveBeenCalled();
  });

  it('opens the mini-games from the dock (wand, scoop)', async () => {
    await renderHud(catState());
    fireEvent.press(screen.getByTestId('action-wand'));
    expect(useAppStore.getState().catOverlay).toBe('wand');
    await flush();
    expect(screen.getByTestId('cat-wand', { includeHiddenElements: true })).toBeTruthy();
    act(() => useAppStore.getState().closeCatGame());
    fireEvent.press(screen.getByTestId('action-scoop'));
    expect(useAppStore.getState().catOverlay).toBe('scoop');
  });

  it('"Pesek" is disabled with "Čist" when the tray is clean; "Igra" names the end of the gap', async () => {
    await renderHud(
      catState({
        litter: makeLitterState({ open_uses: [], next_due_at: null, can_scoop: false }),
        wand: makeWandState({ sessions_today: 1, can_start: false, blocked_reason: 'wand_too_soon', next_allowed_at: '2026-10-04T13:30:00+02:00' }),
      }),
    );
    expect(isDisabled('action-scoop')).toBe(true);
    expect(screen.getByText('Čist', { includeHiddenElements: true })).toBeTruthy();
    expect(screen.getByText('ob 13:30', { includeHiddenElements: true })).toBeTruthy();
    // Enabled: the game screen explains the block.
    expect(isDisabled('action-wand')).toBe(false);
  });

  it('a hungry cat shows the educational first-aid note (C24, "muca")', async () => {
    await renderHud(catState({ pet: { hunger_level: 25 } }));
    expect(screen.getByTestId('hud-cat-first-aid')).toBeTruthy();
    expect(screen.getByText(/Če muca neha jesti/)).toBeTruthy();
  });

  it('no first-aid note while the cat is fed', async () => {
    await renderHud(catState({ pet: { hunger_level: 80 } }));
    expect(screen.queryByTestId('hud-cat-first-aid')).toBeNull();
  });

  it('scratched sofa: "Na praskalnik" on the scene card opens the scratching game; clean says "scratcher first"', async () => {
    await renderHud(
      catState({
        pet: { hygiene_level: 0, needs_cleaning: true },
        behaviour: { active_events: [makeBehaviourEvent('scratching', { id: 21 })], scene: 'scratching' },
        scratching: makeScratchingState(),
      }),
    );
    expect(screen.getByTestId('hud-scene-scratching')).toBeTruthy();
    // Cleaning never resolves it: no forced cleaning overlay, "Očisti" off with its hint.
    expect(isDisabled('action-clean')).toBe(true);
    expect(screen.getAllByText('Najprej praskalnik', { includeHiddenElements: true }).length).toBeGreaterThanOrEqual(1);
    fireEvent.press(screen.getByTestId('action-scratcher'));
    expect(useAppStore.getState().catOverlay).toBe('scratching');
  });

  it('"Na praskalnik" is off while another child carries her (QA n1)', async () => {
    await renderHud(
      catState({
        pet: { hygiene_level: 0, needs_cleaning: true },
        behaviour: { active_events: [makeBehaviourEvent('scratching', { id: 21 })], scene: 'scratching' },
        scratching: makeScratchingState({ can_start: false, blocked_reason: 'scratching_session_active', session_running: true }),
      }),
    );
    expect(isDisabled('action-scratcher')).toBe(true);
  });

  it('a mess next to the tray forces the cleaning game with the litter title', async () => {
    await renderHud(
      catState({
        pet: { hygiene_level: 0, needs_cleaning: true },
        behaviour: { active_events: [makeBehaviourEvent('litter_accident', { id: 22 })], scene: null },
      }),
    );
    expect(screen.getByText('Ups, nered zraven peska! Počisti ga.')).toBeTruthy();
  });

  it('Maine Coon chips: "Počeši" (matted note) and "Menjava peska"; a cat cuddles instead of "Igra"', async () => {
    await renderHud(
      catState({
        pet: { breed_type: 'maine_coon' },
        grooming: makeGroomingState({ matted: true }),
        litter: makeLitterState({}, { overdue: true }),
        play: makePlayState(),
      }),
    );
    expect(screen.getByTestId('hud-cat-chip-grooming')).toBeTruthy();
    expect(screen.getByTestId('hud-cat-chip-grooming-badge')).toBeTruthy();
    expect(screen.getByTestId('hud-cat-chip-litter_change')).toBeTruthy();
    expect(screen.getByTestId('hud-cat-note-grooming').props.children).toBe('Dlaka ima vozel — počeši jo.');
    expect(screen.getByTestId('hud-cat-note-litter_change').props.children).toBe('Pesek smrdi — zamenjaj ves pesek.');
    fireEvent.press(screen.getByTestId('hud-cat-chip-grooming'));
    expect(useAppStore.getState().catOverlay).toBe('grooming');
    act(() => useAppStore.getState().closeCatGame());
    await flush();
    // Play & cuddle for a cat: "Crkljanje" straight to the cuddle (no ball).
    expect(screen.getByText('Crkljanje')).toBeTruthy();
    fireEvent.press(screen.getByTestId('hud-play-open'));
    expect(useAppStore.getState().playOverlay).toBe('cuddle');
  });

  it('resumes the child\'s own running wand game once (app restarted mid-game)', async () => {
    await renderHud(catState({ wand: makeWandState({ session: makeWandSession(), session_running: true }) }));
    expect(useAppStore.getState().catOverlay).toBe('wand');
  });

  it('a broadcast updates the cat blocks at once (a sibling\'s game counts, the tray gets clean)', async () => {
    await renderHud(catState());
    expect(screen.getByText('0/2', { includeHiddenElements: true })).toBeTruthy();
    await act(async () => {
      mockSocket.handler?.(
        makeBroadcast({
          breed_type: 'domestic_cat',
          species: 'cat',
          energy_level: 50,
          event_type: 'metric_changed',
          emitted_at: '2026-10-04T10:00:10.000+00:00',
          wand: makeWandState({ sessions_today: 1, my_sessions_today: null, can_start: false, blocked_reason: 'wand_too_soon', next_allowed_at: '2026-10-04T14:01:00+02:00' }),
          litter: makeLitterState({ open_uses: [], next_due_at: null, can_scoop: false }),
        }),
      );
    });
    await flush();
    expect(screen.getByText('ob 14:01', { includeHiddenElements: true })).toBeTruthy();
    expect(isDisabled('action-scoop')).toBe(true);
  });
});

describe('ChildHudScreen — a dog is unchanged (M5-R06-08b)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    jest.setSystemTime(new Date('2026-10-04T12:00:00+02:00'));
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({
      token: 'child-token',
      user: { id: 2, name: 'Maja', email: null, role: 'child' },
      pet: makePet({ id: 7, born_at: '2026-10-01T08:00:00Z' }),
      awaitingContract: false,
    });
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('dog: walk + clean, no cat buttons or chips, dog texts', async () => {
    await renderHud(makeLiveChildState({ pet: { hunger_level: 20 } }));
    expect(screen.getByTestId('action-walk')).toBeTruthy();
    expect(screen.queryByTestId('action-scoop')).toBeNull();
    expect(screen.queryByTestId('action-wand')).toBeNull();
    expect(screen.queryByTestId('hud-cat-first-aid')).toBeNull();
    expect(screen.getByText('Energija')).toBeTruthy();
  });

  it('dog with only a chewed slipper: food waits with "Najprej pospravi"', async () => {
    await renderHud(
      makeLiveChildState({
        pet: { hygiene_level: 0, needs_cleaning: true },
        behaviour: { active_events: [makeBehaviourEvent('chewing')], scene: 'chewing', can_resolve_chewing: true },
        water: { can_water: false },
      }),
    );
    expect(screen.getAllByText('Najprej pospravi', { includeHiddenElements: true }).length).toBeGreaterThanOrEqual(1);
  });
});
