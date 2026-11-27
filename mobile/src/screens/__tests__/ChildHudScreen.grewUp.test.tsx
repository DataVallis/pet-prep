/**
 * David 2026-11-27 (»Pravilo ostane + sporočilo«): on the last puppy day the evening take-out ends
 * the potty clock (`take_out` null, young from tomorrow) — the HUD shows »Bafo je zrasel! …« in the
 * top stack instead of silently dropping »Pelji ven«; × closes it for good on this device.
 */
import { fireEvent, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { useAppStore } from '@/store/appStore';
import { makeLiveChildState, makePet, makePetProfile, makeTakeOut } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, getChildPet: jest.fn(), syncSteps: jest.fn(), logout: jest.fn() },
  };
});
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(20_000);

const getChildPet = api.getChildPet as jest.Mock;
const TEXT = 'Bafo je zrasel! Od jutri ga ni treba več peljati ven na potrebo — zdaj štejejo sprehodi.';

function lastPuppyEvening(takeOut: ReturnType<typeof makeTakeOut> | null, species: 'dog' | 'cat' = 'dog') {
  return makeLiveChildState({
    server_time: new Date().toISOString(),
    behaviour: { take_out: takeOut, can_take_out: takeOut !== null },
    pet: {
      name: 'Bafo',
      species,
      breed_type: species === 'dog' ? 'mutt' : 'domestic_cat',
      profile: makePetProfile({
        age_months: 8,
        life_stage: 'puppy',
        behaviour_enabled: true,
        next_stage: { life_stage: 'young', from_date: '2026-11-28' },
      }),
    },
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  // 20:30 in Ljubljana on Bafo's last puppy day.
  jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
  jest.setSystemTime(new Date('2026-11-27T19:30:00Z'));
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({
    token: 'child-token',
    user: { id: 2, name: 'Maja', email: null, role: 'child' },
    pet: makePet({ id: 7 }),
    awaitingContract: false,
  });
  useAppStore.getState().setWsStatus('connected');
});

afterEach(() => {
  jest.useRealTimers();
});

describe('ChildHudScreen — »… je zrasel!« at the puppy → young boundary', () => {
  it('shows the card after the last take-out (state already null on open) and × closes it', async () => {
    getChildPet.mockResolvedValue(lastPuppyEvening(null));
    renderWithQuery(<ChildHudScreen />);
    expect(await screen.findByText(TEXT)).toBeTruthy();
    expect(screen.queryByTestId('action-take_out')).toBeNull();

    fireEvent.press(screen.getByTestId('hud-puppy-grew-up-dismiss'));
    expect(screen.queryByTestId('hud-puppy-grew-up')).toBeNull();
  });

  it('nothing while the evening take-out is still open', async () => {
    getChildPet.mockResolvedValue(lastPuppyEvening(makeTakeOut({ kind: 'evening', next_due_at: '2026-11-27T21:00:00+01:00' })));
    renderWithQuery(<ChildHudScreen />);
    await screen.findByTestId('action-feed');
    await jest.advanceTimersByTimeAsync(0);
    expect(screen.queryByTestId('hud-puppy-grew-up')).toBeNull();
  });

  it('never for a cat', async () => {
    getChildPet.mockResolvedValue(lastPuppyEvening(null, 'cat'));
    renderWithQuery(<ChildHudScreen />);
    await screen.findByTestId('hud-top-stack');
    await jest.advanceTimersByTimeAsync(0);
    expect(screen.queryByTestId('hud-puppy-grew-up')).toBeNull();
  });
});
