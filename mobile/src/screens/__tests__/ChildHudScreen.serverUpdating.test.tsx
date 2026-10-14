/**
 * M5-F13: a backend deploy never looks broken in the child HUD — the last view stays,
 * the calm "Posodabljamo PetPrep" banner replaces the amber "no connection" one, a load
 * without any state waits instead of the error screen, and the banner goes away by itself.
 *
 * `api.getChildPet` is mocked, so the test reports the deploy to the store the way the
 * real `apiRequest` does (`noteOutageFailure` / `noteServerAnswered`).
 */
import { act, screen } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import { childPetKey } from '@/hooks/queries/useChildPet';
import ChildHudScreen, { HUD_STRINGS } from '@/screens/ChildHudScreen';
import { useAppStore } from '@/store/appStore';
import { useServerStatusStore } from '@/store/serverStatusStore';
import { makeLiveChildState, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getChildPet: jest.fn(), getChildPetGrowth: jest.fn(), syncSteps: jest.fn(), logout: jest.fn() } };
});
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(20_000);

const getChildPet = api.getChildPet as jest.Mock;
const SL_BANNER = 'Posodabljamo PetPrep — takoj smo nazaj.';

/** What `apiRequest` does with a maintenance answer: report it, then throw. */
function maintenanceAnswer() {
  return async () => {
    useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000);
    throw new ApiError('Service Unavailable', 503, { message: 'Service Unavailable' }, 15);
  };
}

function healthyAnswer() {
  return async () => {
    useServerStatusStore.getState().noteServerAnswered();
    return makeLiveChildState();
  };
}

async function flush(ms = 0) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

describe('ChildHudScreen during a backend deploy (M5-F13)', () => {
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
    useAppStore.getState().setWsStatus('connected');
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('keeps the last view with the calm banner (never the amber one), and clears it when the server is back', async () => {
    getChildPet.mockImplementation(healthyAnswer());
    const { client } = renderWithQuery(<ChildHudScreen />);
    await flush();
    expect(screen.getByTestId('action-feed')).toBeTruthy();

    getChildPet.mockImplementation(maintenanceAnswer());
    await act(async () => {
      void client.invalidateQueries({ queryKey: childPetKey });
      await jest.advanceTimersByTimeAsync(0);
    });
    expect(screen.getByText(SL_BANNER)).toBeTruthy();
    expect(screen.getByTestId('action-feed')).toBeTruthy();

    // Many maintenance retries later (Retry-After 15 s each) — still the last view, no error UI.
    await flush(120_000);
    expect(screen.getByTestId('action-feed')).toBeTruthy();
    expect(screen.queryByTestId('hud-stale')).toBeNull();
    expect(screen.queryByTestId('hud-load-error')).toBeNull();
    expect(screen.getByText(SL_BANNER)).toBeTruthy();

    getChildPet.mockImplementation(healthyAnswer());
    await flush(30_000);
    expect(screen.queryByText(SL_BANNER)).toBeNull();
    expect(screen.getByTestId('action-feed')).toBeTruthy();
  });

  it('no state yet: waits with the updating text instead of the error screen, then loads by itself', async () => {
    getChildPet.mockImplementation(maintenanceAnswer());
    renderWithQuery(<ChildHudScreen />);
    await flush(0);
    expect(screen.getByTestId('hud-updating')).toBeTruthy();
    expect(screen.queryByText(HUD_STRINGS.loadFailed)).toBeNull();

    await flush(200_000);
    expect(screen.queryByText(HUD_STRINGS.loadFailed)).toBeNull();
    expect(screen.getByTestId('hud-updating')).toBeTruthy();

    getChildPet.mockImplementation(healthyAnswer());
    await flush(30_000);
    expect(screen.getByTestId('action-feed')).toBeTruthy();
  });

  it('a plain network failure (phone offline) keeps the old behaviour: amber stale banner, no updating banner', async () => {
    getChildPet.mockResolvedValue(makeLiveChildState());
    const { client } = renderWithQuery(<ChildHudScreen />);
    await flush();
    getChildPet.mockRejectedValue(new TypeError('Network request failed'));
    await act(async () => {
      void client.invalidateQueries({ queryKey: childPetKey });
      await jest.advanceTimersByTimeAsync(0);
    });
    await flush(10_000);
    expect(screen.getByTestId('hud-stale')).toBeTruthy();
    expect(screen.queryByText(SL_BANNER)).toBeNull();
  });
});
