/**
 * M5-F13: during a deploy the parent dashboard keeps the last data with the calm banner
 * (no red error banner, no logout); a re-subscribed pet channel refetches the dashboard.
 */
import { act, screen } from '@testing-library/react-native';
import { useQueryClient } from '@tanstack/react-query';

import { ApiError, api } from '@/api/client';
import { usePetChannels } from '@/hooks/usePetChannels';
import { parentDashboardKey } from '@/modules/family/live';
import { logout } from '@/modules/session/logout';
import ParentDashboardScreen from '@/screens/parent/ParentDashboardScreen';
import { useAppStore } from '@/store/appStore';
import { useServerStatusStore } from '@/store/serverStatusStore';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getParentDashboard: jest.fn(), getChildReport: jest.fn(), getPetActivities: jest.fn() } };
});
jest.mock('@/hooks/usePetChannels', () => ({ usePetChannels: jest.fn() }));
jest.mock('@/modules/session/logout', () => ({ logout: jest.fn(() => Promise.resolve()) }));

const getParentDashboard = api.getParentDashboard as jest.Mock;
const channelsHook = usePetChannels as jest.Mock;
const SL_BANNER = 'Posodabljamo PetPrep — takoj smo nazaj.';
const PET7 = makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] });

async function flush(ms = 0) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

describe('ParentDashboardScreen during a backend deploy (M5-F13)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    useAppStore.setState(useAppStore.getInitialState(), true);
  });
  afterEach(() => jest.useRealTimers());

  it('keeps the children cards, shows the calm banner, no error banner, no logout; banner leaves on recovery', async () => {
    getParentDashboard.mockResolvedValue(makeScoredDashboard([makeScoredChild()], [PET7]));
    let client: ReturnType<typeof useQueryClient> | null = null;
    function Probe() {
      client = useQueryClient();
      return null;
    }
    renderWithQuery(
      <>
        <Probe />
        <ParentDashboardScreen />
      </>,
    );
    await flush();
    expect(screen.getByTestId('child-score-2')).toBeTruthy();

    getParentDashboard.mockImplementation(async () => {
      useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000);
      throw new ApiError('Service Unavailable', 503, { message: 'Service Unavailable' }, 15);
    });
    await act(async () => {
      void client?.invalidateQueries({ queryKey: parentDashboardKey });
      await jest.advanceTimersByTimeAsync(0);
    });
    await flush(200_000); // all retries spent → the query is in the error state
    expect(screen.getByText(SL_BANNER)).toBeTruthy();
    expect(screen.getByTestId('child-score-2')).toBeTruthy();
    expect(screen.queryByTestId('dashboard-error')).toBeNull();
    expect(logout).not.toHaveBeenCalled();

    getParentDashboard.mockImplementation(async () => {
      useServerStatusStore.getState().noteServerAnswered();
      return makeScoredDashboard([makeScoredChild()], [PET7]);
    });
    await act(async () => {
      useServerStatusStore.getState().noteServerAnswered();
      await jest.advanceTimersByTimeAsync(1_000);
    });
    expect(screen.queryByText(SL_BANNER)).toBeNull();
  });

  it('passes an onResubscribed handler that refetches the dashboard', async () => {
    getParentDashboard.mockResolvedValue(makeScoredDashboard([makeScoredChild()], [PET7]));
    renderWithQuery(<ParentDashboardScreen />);
    await flush();
    const calls = getParentDashboard.mock.calls.length;
    const onResubscribed = channelsHook.mock.calls.at(-1)?.[2] as ((petId: number) => void) | undefined;
    expect(onResubscribed).toBeInstanceOf(Function);
    await act(async () => {
      onResubscribed?.(7);
      await jest.advanceTimersByTimeAsync(0);
    });
    expect(getParentDashboard.mock.calls.length).toBe(calls + 1);
  });
});
