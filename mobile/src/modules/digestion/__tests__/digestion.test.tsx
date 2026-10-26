/**
 * M5-R16 — gap between a meal and exercise (bloat / GDV), David 2026-10-12 "1 ura, samo
 * opozorilo": while the server's `feeding.digesting_until` is in the future the HUD and the
 * walk screen show "Počakaj s sprehodom — kuža prebavlja." It is only a note: the walk
 * button stays enabled, steps still sync, and the note goes away by the clock.
 */
import { act, render, screen } from '@testing-library/react-native';

import { api, type ChildPetState } from '@/api/client';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { digestionEndsInMs, digestionNote, isDigesting } from '@/modules/digestion/digestion';
import type { StepSync } from '@/modules/steps/useStepSync';
import WalkTrackerOverlay from '@/modules/walk/WalkTrackerOverlay';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { i18n } from '@/i18n';
import { useAppStore } from '@/store/appStore';
import { makeLiveChildState, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, getChildPet: jest.fn(), getChildPetGrowth: jest.fn(), syncSteps: jest.fn(), logout: jest.fn() },
  };
});
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(20_000);

const getChildPet = api.getChildPet as jest.Mock;
/** = the fixture's server_time, so the clock skew is 0. */
const T0 = Date.parse('2026-10-04T12:00:00+02:00');
const UNTIL = '2026-10-04T12:40:00+02:00';

/** A fed Cane Corso (the server sends the gap's end). */
function corso(digestingUntil: unknown = UNTIL, o: Parameters<typeof makeLiveChildState>[0] = {}): ChildPetState {
  return makeLiveChildState({
    ...o,
    pet: { breed_type: 'cane_corso', species: 'dog', ...o.pet } as Partial<ChildPetState['pet']>,
    feeding: { last_fed_at: '2026-10-04T11:40:00+02:00', digesting_until: digestingUntil, ...o.feeding },
  });
}

function viewOf(state: ChildPetState): ChildPetView {
  return normalizeChildState(state, 0, T0);
}

function stepSync(): StepSync {
  return {
    permission: 'granted',
    requestPermission: jest.fn(() => Promise.resolve()),
    syncNow: jest.fn(() => Promise.resolve()),
    isSyncing: false,
    canSync: true,
    adaptedMode: false,
    health: {
      status: 'unavailable',
      source: 'healthkit',
      measure: 'steps',
      connect: jest.fn(() => Promise.resolve()),
      openSettings: jest.fn(() => Promise.resolve()),
      openStore: jest.fn(() => Promise.resolve()),
    },
  };
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
  jest.setSystemTime(T0);
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({
    token: 'child-token',
    user: { id: 2, name: 'Maja', email: null, role: 'child' },
    pet: makePet({ id: 7 }),
    awaitingContract: false,
  });
  useAppStore.getState().setWsStatus('connected');
});

afterEach(async () => {
  jest.useRealTimers();
  await i18n.changeLanguage('sl');
});

describe('reading the server state', () => {
  it('reads `feeding.digesting_until`; an older server (no key) or junk → no warning', () => {
    expect(viewOf(corso()).feeding.digesting_until).toBe(UNTIL);
    expect(viewOf(corso(null)).feeding.digesting_until).toBeNull();
    expect(viewOf(corso('')).feeding.digesting_until).toBeNull();
    const older = corso() as unknown as { feeding: Record<string, unknown> };
    delete older.feeding.digesting_until;
    expect(viewOf(older as unknown as ChildPetState).feeding.digesting_until).toBeNull();
  });

  it('shows the note until the instant (server time), never for a cat or a locked pet', () => {
    const view = viewOf(corso());
    expect(isDigesting(view, T0)).toBe(true);
    expect(digestionEndsInMs(view, T0)).toBe(40 * 60_000);
    expect(isDigesting(view, Date.parse(UNTIL) - 1)).toBe(true);
    expect(isDigesting(view, Date.parse(UNTIL))).toBe(false);
    expect(digestionEndsInMs(view, Date.parse(UNTIL))).toBeNull();

    expect(isDigesting(viewOf(corso(UNTIL, { pet: { breed_type: 'maine_coon', species: 'cat' } as Partial<ChildPetState['pet']> })), T0)).toBe(false);
    expect(isDigesting(viewOf(corso(UNTIL, { lock: { is_locked: true, reason: 'ill', until: null } })), T0)).toBe(false);
    expect(isDigesting(viewOf(corso(null)), T0)).toBe(false);
  });

  it('words it calmly in Slovenian and English, with the family-local time and no pet name', async () => {
    expect(digestionNote(viewOf(corso()), T0)).toEqual({
      title: 'Počakaj s sprehodom',
      body: 'Kuža prebavlja. S sprehodom še malo počakaj.',
      until: 'Na sprehod lahko greš ob 12:40.',
      a11y: 'Počakaj s sprehodom. Kuža prebavlja. S sprehodom še malo počakaj. Na sprehod lahko greš ob 12:40.',
      short: 'Počakaj s sprehodom do 12:40',
    });
    await i18n.changeLanguage('en');
    expect(digestionNote(viewOf(corso()), T0)).toMatchObject({
      title: 'Wait with the walk',
      body: 'Your pup is digesting. Wait a little before the walk.',
      until: 'You can go for a walk at 12:40.',
      short: 'Wait with the walk until 12:40',
    });
    expect(digestionNote(viewOf(corso()), Date.parse(UNTIL))).toBeNull();
  });
});

describe('the walk screen', () => {
  it('shows the note above the steps and drops it at the end of the gap; steps stay visible', async () => {
    render(<WalkTrackerOverlay view={viewOf(corso())} stepSync={stepSync()} onClose={jest.fn()} />);
    expect(screen.getByTestId('walk-digesting')).toHaveTextContent(/Počakaj s sprehodom\..*Na sprehod lahko greš ob 12:40\./);
    expect(screen.getByTestId('walk-progress')).toBeTruthy();

    await advance(40 * 60_000 + 500);
    expect(screen.queryByTestId('walk-digesting')).toBeNull();
  });

  it('shows nothing for a dog without the gap', () => {
    render(<WalkTrackerOverlay view={viewOf(corso(null))} stepSync={stepSync()} onClose={jest.fn()} />);
    expect(screen.queryByTestId('walk-digesting')).toBeNull();
  });
});

async function renderHud(state: ChildPetState) {
  getChildPet.mockResolvedValue(state);
  const utils = renderWithQuery(<ChildHudScreen />);
  await advance(0);
  await advance(0);
  expect(screen.getByTestId('action-feed', { includeHiddenElements: true })).toBeTruthy();
  return utils;
}

describe('the HUD', () => {
  it('shows the note while the walk button stays enabled, then hides it by the clock', async () => {
    const { unmount } = await renderHud(corso());

    expect(screen.getByTestId('hud-digesting')).toHaveTextContent(/Kuža prebavlja\./);
    expect(screen.getByTestId('action-walk').props.accessibilityState?.disabled).not.toBe(true);

    await advance(40 * 60_000 + 500);
    expect(screen.queryByTestId('hud-digesting')).toBeNull();
    unmount();
  });

  it('shows nothing without the gap (every other breed / an older server)', async () => {
    const { unmount } = await renderHud(corso(null));
    expect(screen.queryByTestId('hud-digesting')).toBeNull();
    unmount();
  });
});
