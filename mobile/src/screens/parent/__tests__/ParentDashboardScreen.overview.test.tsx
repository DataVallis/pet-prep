/**
 * M2-05: the family overview on real dashboard payloads (M2-06 shape) — traffic
 * light with reasons, Care Score, progress, today's routines, empty / edge states,
 * live updates per pet and the 30 s polling fallback.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import { CHILD_CARD_STRINGS } from '@/components/parent/ChildOverviewCard';
import { usePetChannels } from '@/hooks/usePetChannels';
import ParentDashboardScreen, { DASHBOARD_STRINGS } from '@/screens/parent/ParentDashboardScreen';
import { useAppStore } from '@/store/appStore';
import {
  makeBroadcast,
  makeFamilyChild,
  makeFamilyPet,
  makeMissed,
  makeScoredChild,
  makeScoredDashboard,
} from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import type { PetUpdatedBroadcast } from '@/types';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      getParentDashboard: jest.fn(),
      getChildReport: jest.fn(),
      getPetActivities: jest.fn(),
      getQuietHours: jest.fn(),
    },
  };
});
jest.mock('@/hooks/usePetChannels', () => ({ usePetChannels: jest.fn() }));
jest.mock('@/modules/session/logout', () => ({ logout: jest.fn(() => Promise.resolve()) }));

const getParentDashboard = api.getParentDashboard as jest.Mock;
const channelsHook = usePetChannels as jest.Mock;
/** Pet ids of the latest `usePetChannels(petIds, handler)` call. */
const subscribedIds = (): number[] => (channelsHook.mock.calls.at(-1)?.[0] as number[] | undefined) ?? [];

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

const PET7 = makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] });

describe('ParentDashboardScreen — family overview (M2-05)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  it('hides the simulated "Pasme" paywall tab until payments exist (David 2026-10-07)', async () => {
    getParentDashboard.mockResolvedValue(makeScoredDashboard([makeScoredChild()], [PET7]));
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    expect(screen.getByText(DASHBOARD_STRINGS.tabs.dashboard)).toBeTruthy();
    expect(screen.getByText(DASHBOARD_STRINGS.tabs.controls)).toBeTruthy();
    expect(screen.queryByText(DASHBOARD_STRINGS.tabs.breeds)).toBeNull();
  });

  it('green child: Care Score, "x od y rutin", week of 12, today, 7-day bars, pet metrics — no mock data', async () => {
    getParentDashboard.mockResolvedValue(
      makeScoredDashboard([makeScoredChild()], [{ ...PET7, metrics: { hunger: 80, thirst: 70, energy: 55, hygiene: 100 } }]),
    );
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    expect(screen.getByTestId('child-light-2-green')).toBeTruthy();
    expect(screen.queryByTestId('child-reason-2-missed_routines')).toBeNull();
    expect(screen.getByTestId('child-score-2')).toHaveTextContent(/^86$/);
    expect(screen.getByTestId('child-score-routines-2')).toHaveTextContent('31 od 36 rutin');
    expect(screen.getByTestId('child-progress-2')).toHaveTextContent('Teden 2 od 12');
    expect(screen.getByTestId('child-today-2')).toHaveTextContent(/Opravljeno 3/);
    expect(screen.getByTestId('child-today-2')).toHaveTextContent(/Še odprto 1/);
    expect(screen.getByTestId('week-day-2026-10-04')).toBeTruthy();
    expect(screen.getByTestId('child-pet-hygiene-2')).toHaveTextContent('Čistoča100 %');
    expect(screen.getByText(DASHBOARD_STRINGS.familySubtitle(1, 1))).toBeTruthy();
    // The old demo timeline / chart are gone.
    expect(screen.queryByText('Nalil svežo vodo')).toBeNull();
    expect(screen.queryByText('Tedenska statistika odgovornosti')).toBeNull();
  });

  it('yellow child: friendly reason + missed routines with type and family-local time', async () => {
    const child = makeScoredChild({
      traffic_light: { color: 'yellow', reasons: ['missed_routines'] },
      today: {
        date: '2026-10-04',
        expected: 6,
        done: 2,
        done_by_child: 1,
        pending: 1,
        missed_count: 3,
        missed: [
          makeMissed('feed', '2026-10-04T07:00:00+02:00', '2026-10-04T09:00:00+02:00'),
          makeMissed('clean', '2026-10-04T10:00:00Z', '2026-10-04T12:30:00Z'),
          makeMissed('clean', '2026-10-03T21:50:00+02:00', '2026-10-04T07:50:00+02:00', '2026-10-03'),
        ],
      },
    });
    getParentDashboard.mockResolvedValue(makeScoredDashboard([child], [PET7]));
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    expect(screen.getByTestId('child-light-2-yellow')).toBeTruthy();
    expect(screen.getByTestId('child-reason-2-missed_routines')).toHaveTextContent(/Danes so zamujene že 3 rutine\./);
    expect(screen.getByTestId('child-today-2')).toHaveTextContent(/Opravljeno 2 \(Luka: 1\)/);
    expect(screen.getByTestId('child-today-2')).toHaveTextContent(/Zamujeno 3/);
    expect(screen.getByTestId('child-missed-2-0')).toHaveTextContent(/Hrana/);
    expect(screen.getByTestId('child-missed-2-0')).toHaveTextContent(/okno 07:00–09:00/);
    expect(screen.getByTestId('child-missed-2-1')).toHaveTextContent(/rok 14:30/);
    expect(screen.getByTestId('child-missed-2-2')).toHaveTextContent(/sob 3\. 10\. · rok 07:50/);
  });

  it('red child: game over + fell ill today, illnesses in the score', async () => {
    const child = makeScoredChild({
      traffic_light: { color: 'red', reasons: ['game_over', 'fell_ill_today'] },
      care_score: { score: 40, done: 10, expected: 20, routines: 20, illnesses: 1, since: null },
    });
    getParentDashboard.mockResolvedValue(
      makeScoredDashboard([child], [{ ...PET7, is_game_over: true, is_active: false }]),
    );
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    expect(screen.getByTestId('child-light-2-red')).toBeTruthy();
    expect(screen.getByTestId('child-reason-2-game_over')).toHaveTextContent(/igra je končana/);
    expect(screen.getByTestId('child-reason-2-fell_ill_today')).toHaveTextContent(/danes zbolel/);
    expect(screen.getByTestId('child-illnesses-2')).toHaveTextContent(/1 bolezen \(−10 točk\)/);
    expect(screen.getByTestId('child-pet-status-2')).toHaveTextContent(/Igra končana/);
    // A game-over pet needs no live channel.
    expect(subscribedIds()).not.toContain(7);
  });

  it('red child: phase 3 alarm reason', async () => {
    const child = makeScoredChild({ traffic_light: { color: 'red', reasons: ['phase3_alarm'] } });
    getParentDashboard.mockResolvedValue(makeScoredDashboard([child], [PET7]));
    renderWithQuery(<ParentDashboardScreen />);
    await flush();
    expect(screen.getByTestId('child-reason-2-phase3_alarm')).toHaveTextContent(/več kot uro/);
  });

  it('null score: "Še ni dovolj podatkov"', async () => {
    const child = makeScoredChild({ care_score: { score: null, done: 0, expected: 0, routines: null, illnesses: 0, since: null } });
    getParentDashboard.mockResolvedValue(makeScoredDashboard([child], [PET7]));
    renderWithQuery(<ParentDashboardScreen />);
    await flush();
    expect(screen.getByTestId('child-score-2')).toHaveTextContent(CHILD_CARD_STRINGS.noScore);
  });

  it('unborn pet awaiting the contract: waiting text, no score', async () => {
    const child = makeFamilyChild({ id: 2, name: 'Luka', pet_id: 7, devices: 1, contract_signed: false });
    getParentDashboard.mockResolvedValue(
      makeScoredDashboard([child], [{ ...PET7, born_at: null, awaiting_contract: true }]),
    );
    renderWithQuery(<ParentDashboardScreen />);
    await flush();
    expect(screen.getByTestId('child-awaiting-2')).toHaveTextContent(CHILD_CARD_STRINGS.awaitingContract('Luka'));
    expect(screen.queryByTestId('child-score-2')).toBeNull();
    expect(screen.getByTestId('child-pet-status-2')).toHaveTextContent(/čaka na podpis/);
  });

  it('child without a pet and a family without children', async () => {
    getParentDashboard.mockResolvedValue(makeScoredDashboard([makeFamilyChild({ id: 5, name: 'Maja' })], []));
    const { unmount } = renderWithQuery(<ParentDashboardScreen />);
    await flush();
    expect(screen.getByTestId('child-no-pet-5')).toBeTruthy();
    unmount();

    getParentDashboard.mockResolvedValue(makeScoredDashboard([], []));
    renderWithQuery(<ParentDashboardScreen />);
    await flush();
    expect(screen.getByTestId('add-child-card')).toBeTruthy();
    expect(screen.getByTestId('join-family')).toBeTruthy();
  });

  it('live: one channel per pet (one connection), a broadcast patches that pet at once', async () => {
    const LUKA = makeScoredChild();
    const MAJA = makeScoredChild({ id: 5, name: 'Maja', pet_id: 8 });
    const pet8 = makeFamilyPet({ id: 8, caretakers: [{ child_id: 5, contract_signed: true }] });
    getParentDashboard
      .mockResolvedValueOnce(makeScoredDashboard([LUKA, MAJA], [PET7, pet8]))
      // What the server says after the tick (the throttled refetch agrees with the event).
      .mockResolvedValue(makeScoredDashboard([LUKA, MAJA], [PET7, { ...pet8, metrics: { ...pet8.metrics, hygiene: 0 } }]));
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    expect([...subscribedIds()].sort()).toEqual([7, 8]);
    const handler = channelsHook.mock.calls.at(-1)?.[1] as (e: PetUpdatedBroadcast) => void;
    expect(typeof handler).toBe('function');

    await act(async () => {
      handler(makeBroadcast({ pet_id: 8, hygiene_level: 0, event_type: 'metric_changed', emitted_at: '2026-10-04T10:00:00.000+00:00' }));
    });
    await flush();
    expect(screen.getByTestId('child-pet-hygiene-5')).toHaveTextContent('Čistoča0 %');
    expect(screen.getByTestId('child-pet-hygiene-2')).toHaveTextContent('Čistoča100 %');
    // The first tick refetches once (throttled), the parent store is not touched.
    expect(getParentDashboard).toHaveBeenCalledTimes(2);
    expect(useAppStore.getState().pet).toBeNull();

    // A second tick within a minute: patched at once, no request.
    await act(async () => {
      handler(makeBroadcast({ pet_id: 8, hygiene_level: 50, event_type: 'metric_changed' }));
    });
    await flush();
    expect(screen.getByTestId('child-pet-hygiene-5')).toHaveTextContent('Čistoča50 %');
    expect(getParentDashboard).toHaveBeenCalledTimes(2);

    // A real action always refetches.
    await act(async () => {
      handler(makeBroadcast({ pet_id: 8, event_type: 'cleaned_poop' }));
    });
    await flush();
    expect(getParentDashboard).toHaveBeenCalledTimes(3);
  });

  it('opens the child detail and comes back', async () => {
    (api.getChildReport as jest.Mock).mockReturnValue(new Promise(() => undefined));
    (api.getPetActivities as jest.Mock).mockReturnValue(new Promise(() => undefined));
    getParentDashboard.mockResolvedValue(makeScoredDashboard([makeScoredChild()], [PET7]));
    renderWithQuery(<ParentDashboardScreen />);
    await flush();

    fireEvent.press(screen.getByTestId('child-details-2'));
    expect(screen.getByTestId('report-loading')).toBeTruthy();
    expect(api.getChildReport).toHaveBeenCalledWith(2, 7);
    fireEvent.press(screen.getByTestId('detail-back'));
    expect(screen.getByTestId('child-card-2')).toBeTruthy();
  });

  it('returns to the overview when the child shown in detail leaves the family', async () => {
    (api.getChildReport as jest.Mock).mockReturnValue(new Promise(() => undefined));
    (api.getPetActivities as jest.Mock).mockReturnValue(new Promise(() => undefined));
    const MAJA = makeScoredChild({ id: 5, name: 'Maja', pet_id: null });
    getParentDashboard.mockResolvedValueOnce(makeScoredDashboard([makeScoredChild(), MAJA], [PET7]));
    const { client } = renderWithQuery(<ParentDashboardScreen />);
    await flush();
    fireEvent.press(screen.getByTestId('child-details-5'));
    expect(screen.getByTestId('detail-back')).toBeTruthy();

    getParentDashboard.mockResolvedValue(makeScoredDashboard([makeScoredChild()], [PET7]));
    await act(async () => {
      await client.refetchQueries({ queryKey: ['parent', 'dashboard'] });
    });
    await flush();
    expect(screen.queryByTestId('detail-back')).toBeNull();
    expect(screen.getByTestId('child-card-2')).toBeTruthy();
  });

  it('shared pet: "x od y" uses the shared routines and shows the fair share', async () => {
    const child = makeScoredChild({ care_score: { score: 100, done: 10, expected: 6, routines: 12, illnesses: 0, since: null } });
    getParentDashboard.mockResolvedValue(makeScoredDashboard([child], [PET7]));
    renderWithQuery(<ParentDashboardScreen />);
    await flush();
    expect(screen.getByTestId('child-score-routines-2')).toHaveTextContent('10 od 12 rutin · pošten delež 6');
  });

  it('keeps the last data with an offline banner when a refetch fails', async () => {
    getParentDashboard.mockResolvedValueOnce(makeScoredDashboard([makeScoredChild()], [PET7]));
    const { client } = renderWithQuery(<ParentDashboardScreen />);
    await flush();
    getParentDashboard.mockRejectedValueOnce(new TypeError('Network request failed'));
    await act(async () => {
      await client.refetchQueries({ queryKey: ['parent', 'dashboard'] });
    });
    await flush();
    expect(screen.getByText(DASHBOARD_STRINGS.offline)).toBeTruthy();
    expect(screen.getByTestId('child-card-2')).toBeTruthy();
  });
});

describe('ParentDashboardScreen — polling fallback', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
    jest.useFakeTimers();
  });
  afterEach(() => {
    jest.useRealTimers();
  });

  it('polls every 30 s while not every channel is live; while live only a slow 3-min refresh', async () => {
    getParentDashboard.mockResolvedValue(makeScoredDashboard([makeScoredChild()], [PET7]));
    renderWithQuery(<ParentDashboardScreen />);
    await act(async () => {
      await jest.advanceTimersByTimeAsync(0);
    });
    expect(getParentDashboard).toHaveBeenCalledTimes(1);

    await act(async () => {
      await jest.advanceTimersByTimeAsync(29_000);
    });
    expect(getParentDashboard).toHaveBeenCalledTimes(1);
    await act(async () => {
      await jest.advanceTimersByTimeAsync(1_500);
    });
    expect(getParentDashboard).toHaveBeenCalledTimes(2);

    act(() => useAppStore.getState().setWsStatus('connected'));
    await act(async () => {
      await jest.advanceTimersByTimeAsync(170_000);
    });
    expect(getParentDashboard).toHaveBeenCalledTimes(2);
    await act(async () => {
      await jest.advanceTimersByTimeAsync(11_000);
    });
    expect(getParentDashboard).toHaveBeenCalledTimes(3);

    act(() => useAppStore.getState().setWsStatus('reconnecting'));
    await act(async () => {
      await jest.advanceTimersByTimeAsync(30_500);
    });
    expect(getParentDashboard).toHaveBeenCalledTimes(4);
  });
});
