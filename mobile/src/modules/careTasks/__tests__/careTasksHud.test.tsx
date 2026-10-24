/**
 * M5-R14 / R15 / R19 / R20 on the child HUD: a chip per open breed care task (opens its
 * mini-game), off while the pet sleeps; the summer midday heat note on the HUD and in the
 * walk tracker — by the clock, the walk button stays enabled (no penalty).
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';

import { api, type ChildPetState } from '@/api/client';
import { normalizeChildState } from '@/modules/childPet/childPetView';
import type { StepSync } from '@/modules/steps/useStepSync';
import WalkTrackerOverlay from '@/modules/walk/WalkTrackerOverlay';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { i18n } from '@/i18n';
import { useAppStore } from '@/store/appStore';
import { makeCareTask, makeLiveChildState, makePet } from '@/test-utils/fixtures';
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
const T0 = Date.parse('2026-10-04T12:00:00+02:00');
const HEAT = { starts_at: '2026-10-04T11:00:00+02:00', ends_at: '2026-10-04T12:30:00+02:00', active: true };

function pug(o: Parameters<typeof makeLiveChildState>[0] = {}): ChildPetState {
  return makeLiveChildState({ ...o, pet: { breed_type: 'pug', species: 'dog', ...o.pet } as Partial<ChildPetState['pet']> });
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

async function renderHud(state: ChildPetState) {
  getChildPet.mockResolvedValue(state);
  const utils = renderWithQuery(<ChildHudScreen />);
  await advance(0);
  await advance(0);
  expect(screen.getByTestId('action-feed', { includeHiddenElements: true })).toBeTruthy();
  return utils;
}

function stepSync(): StepSync {
  return {
    permission: 'granted',
    requestPermission: jest.fn(() => Promise.resolve()),
    syncNow: jest.fn(() => Promise.resolve()),
    isSyncing: false,
    canSync: true,
    adaptedMode: false,
    health: { status: 'unavailable' },
  } as unknown as StepSync;
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
  jest.setSystemTime(T0);
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({ token: 'child-token', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: makePet({ id: 7 }), awaitingContract: false });
  useAppStore.getState().setWsStatus('connected');
});

afterEach(async () => {
  jest.useRealTimers();
  await i18n.changeLanguage('sl');
});

describe('care chips', () => {
  it('shows a chip per open task and opens its mini-game', async () => {
    const { unmount } = await renderHud(pug({ care_tasks: [makeCareTask({ task: 'fold_cleaning' }), makeCareTask({ done_this_period: 7 })] }));
    expect(screen.getByTestId('hud-care-chip-fold_cleaning')).toBeTruthy();
    // A task whose period is done has no chip.
    expect(screen.queryByTestId('hud-care-chip-tooth_brushing')).toBeNull();
    fireEvent.press(screen.getByTestId('hud-care-chip-fold_cleaning'));
    await advance(0);
    expect(useAppStore.getState().careTaskOverlay).toBe('fold_cleaning');
    expect(screen.getByTestId('care-fold_cleaning-intro')).toBeTruthy();
    unmount();
  });

  it('is off while the pet sleeps, with the reason', async () => {
    const { unmount } = await renderHud(
      pug({
        sleep: { asleep: true, until: '2026-10-05T07:00:00+02:00' },
        care_tasks: [makeCareTask({ can_start: false, blocked_reason: 'care_task_quiet_hours', next_allowed_at: '2026-10-05T07:00:00+02:00' })],
      }),
    );
    const chip = screen.getByTestId('hud-care-chip-tooth_brushing', { includeHiddenElements: true });
    expect(chip.props.accessibilityState).toMatchObject({ disabled: true });
    expect(chip.props.accessibilityHint).toBe('💤 Kuža spi do 7:00');
    unmount();
  });
});

describe('summer heat note (no weather data)', () => {
  it('shows on the HUD while the window is on and hides by the clock; the walk stays enabled', async () => {
    const { unmount } = await renderHud(pug({ walk_heat: HEAT }));
    expect(screen.getByTestId('hud-heat')).toHaveTextContent(/Prevroče za sprehod\./);
    expect(screen.getByTestId('action-walk').props.accessibilityState?.disabled).not.toBe(true);
    await advance(30 * 60_000 + 500);
    expect(screen.queryByTestId('hud-heat')).toBeNull();
    unmount();
  });

  it('shows in the walk tracker too', () => {
    const first = render(<WalkTrackerOverlay view={normalizeChildState(pug({ walk_heat: HEAT }), 0, T0)} stepSync={stepSync()} onClose={jest.fn()} />);
    expect(screen.getByTestId('walk-heat')).toBeTruthy();
    first.unmount();
    render(<WalkTrackerOverlay view={normalizeChildState(pug(), 0, T0)} stepSync={stepSync()} onClose={jest.fn()} />);
    expect(screen.queryByTestId('walk-heat')).toBeNull();
  });
});
