/**
 * Hotfix 2026-10-06 (TestFlight 1.24.4): a throttled / failing `GET /api/child/pet` must
 * never strand the child on the dead "Kužka ni bilo mogoče naložiti" screen.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';
import { Text } from 'react-native';

import { ApiError, api } from '@/api/client';
import { childPetKey } from '@/hooks/queries/useChildPet';
import HudErrorBoundary, { HUD_ERROR_STRINGS } from '@/components/HudErrorBoundary';
import ChildHudScreen, { HUD_STRINGS } from '@/screens/ChildHudScreen';
import { useAppStore } from '@/store/appStore';
import { makeLiveChildState, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getChildPet: jest.fn(), syncSteps: jest.fn(), logout: jest.fn() } };
});
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(20_000);

const getChildPet = api.getChildPet as jest.Mock;
const throttled = () => new ApiError('Too Many Attempts.', 429, undefined, 20);

async function flush(ms = 0) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

describe('ChildHudScreen — 429 / errors (hotfix 2026-10-06)', () => {
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

  it('a 429 on a refetch keeps the last view (stale banner at most), then recovers by itself', async () => {
    getChildPet.mockResolvedValue(makeLiveChildState());
    const { client } = renderWithQuery(<ChildHudScreen />);
    await flush();
    expect(screen.getByTestId('action-feed')).toBeTruthy();

    getChildPet.mockRejectedValue(throttled());
    await act(async () => {
      void client.invalidateQueries({ queryKey: childPetKey });
      await jest.advanceTimersByTimeAsync(0);
    });
    // Retries wait for Retry-After (20 s) — the HUD stays, no error yet.
    expect(screen.getByTestId('action-feed')).toBeTruthy();
    expect(screen.queryByTestId('hud-stale')).toBeNull();

    await flush(45_000); // both retries used up → error state, still the last view
    expect(screen.queryByTestId('hud-load-error')).toBeNull();
    expect(screen.getByTestId('action-feed')).toBeTruthy();
    expect(screen.getByTestId('hud-stale')).toBeTruthy();
    const calls = getChildPet.mock.calls.length;
    expect(calls).toBe(4);

    getChildPet.mockResolvedValue(makeLiveChildState());
    await flush(25_000); // the hook's own retry (≥ Retry-After)
    expect(getChildPet.mock.calls.length).toBe(calls + 1);
    expect(screen.queryByTestId('hud-stale')).toBeNull();
  });

  it('no state yet and the server refuses: error screen says it retries, and it does — no tap needed', async () => {
    getChildPet.mockRejectedValue(throttled());
    renderWithQuery(<ChildHudScreen />);
    await flush(41_000);
    expect(screen.getByTestId('hud-load-error')).toBeTruthy();
    expect(screen.getByText(HUD_STRINGS.autoRetry)).toBeTruthy();

    getChildPet.mockResolvedValue(makeLiveChildState());
    await flush(25_000);
    expect(screen.queryByTestId('hud-load-error')).toBeNull();
    expect(screen.getByTestId('action-feed')).toBeTruthy();
  });
});

describe('HudErrorBoundary', () => {
  it('a render error shows the retry card instead of crashing; retry remounts the HUD', () => {
    let fail = true;
    const onError = jest.fn();
    function Flaky() {
      if (fail) throw new Error('Maximum update depth exceeded');
      return <Text>HUD ok</Text>;
    }
    const spy = jest.spyOn(console, 'error').mockImplementation(() => undefined);
    render(
      <HudErrorBoundary onError={onError}>
        <Flaky />
      </HudErrorBoundary>,
    );
    expect(screen.getByTestId('hud-error-boundary')).toBeTruthy();
    expect(screen.getByText(HUD_ERROR_STRINGS.title)).toBeTruthy();
    expect(onError).toHaveBeenCalledTimes(1);

    fail = false;
    fireEvent.press(screen.getByTestId('hud-error-retry'));
    expect(screen.getByText('HUD ok')).toBeTruthy();
    spy.mockRestore();
  });
});
