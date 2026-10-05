/**
 * M2-05: child report (7 / 30 / 84 days) and the pet's paginated timeline with the
 * nicknames of the children who acted.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import { familyFromDashboard, type FamilyOverview } from '@/modules/family/family';
import ChildDetailScreen, { CHILD_DETAIL_STRINGS, formatSteps } from '@/screens/parent/ChildDetailScreen';
import { makeDayRow, makeFamilyPet, makeMedia, makeMissed, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { liveVideoPlayers, mockVideoPlayers, playerUris, resetMockVideoPlayers } from '@/test-utils/videoPlayers';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, getChildReport: jest.fn(), getPetActivities: jest.fn() },
  };
});

const getChildReport = api.getChildReport as jest.Mock;
const getPetActivities = api.getPetActivities as jest.Mock;

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

const LUKA = makeScoredChild();
const MAJA = makeScoredChild({ id: 5, name: 'Maja' });
const FAMILY = familyFromDashboard(
  makeScoredDashboard([LUKA, MAJA], [
    makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }, { child_id: 5, contract_signed: true }] }),
  ]) as never,
) as FamilyOverview;

function report(days: 7 | 30 | 84, score: number | null) {
  return {
    child: { id: 2, name: 'Luka' },
    pet_id: 7,
    timezone: 'Europe/Ljubljana',
    days,
    from: '2026-09-28',
    to: '2026-10-04',
    traffic_light: { color: 'yellow', reasons: ['missed_routines'] },
    care_score: { score: 86, done: 31, expected: 36, routines: 40, illnesses: 0, since: null },
    period_score: { score, done: days, expected: days + 2, routines: days + 2, illnesses: days === 84 ? 2 : 0, since: null },
    progress: { started_at: '2026-09-21T16:00:00+02:00', days_elapsed: 13, week: 2, weeks_total: 12, completed: false },
    by_type: {
      feed: { expected: 14, done: 13, done_by_child: 9, missed: 1, pending: 0 },
      water: { expected: 21, done: 20, done_by_child: 12, missed: 1, pending: 0 },
      clean: { expected: 5, done: 5, done_by_child: 2, missed: 0, pending: 1 },
      walk: { expected: 7, done: 6, done_by_child: 6, missed: 1, pending: 0 },
    },
    daily: [
      makeDayRow('2026-10-03', { done: 5, expected: 6, walk_steps: 4210, walk_goal: 4000, walk_done: true }),
      makeDayRow('2026-10-04', { done: 2, expected: 4, walk_steps: 1250, walk_goal: 4000, walk_done: false }),
    ],
    missed: [makeMissed('feed', '2026-10-03T17:00:00+02:00', '2026-10-03T19:00:00+02:00', '2026-10-03')],
    illnesses: [{ started_at: '2026-10-01T08:00:00Z', ended_at: '2026-10-01T20:00:00Z' }],
  };
}

function page(current: number, last: number, items: Record<string, unknown>[]) {
  return { data: items, meta: { current_page: current, last_page: last, total: 30 } };
}

describe('ChildDetailScreen', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    getChildReport.mockImplementation((_id: number, days: 7 | 30 | 84) =>
      Promise.resolve(report(days, days === 7 ? 71 : days === 30 ? 64 : null)),
    );
    getPetActivities.mockResolvedValue(page(1, 1, []));
  });

  it('shows the 7-day report: score, routines, types, days with steps, missed, illnesses', async () => {
    renderWithQuery(<ChildDetailScreen child={LUKA} family={FAMILY} onBack={jest.fn()} />);
    await flush();

    expect(getChildReport).toHaveBeenCalledWith(2, 7);
    expect(screen.getByTestId('report-period-score')).toHaveTextContent('71');
    expect(screen.getByText('7 od 9 rutin')).toBeTruthy();
    expect(screen.getByTestId('report-type-feed')).toHaveTextContent(/Hrana9 od 14zamujeno 1/);
    expect(screen.getByTestId('report-type-clean')).toHaveTextContent(/odprto 1/);
    expect(screen.getByTestId('report-day-2026-10-03')).toHaveTextContent(/5\/6/);
    expect(screen.getByTestId('report-day-2026-10-03')).toHaveTextContent(/4\.210 \/ 4\.000 korakov/);
    expect(screen.getByTestId('report-day-2026-10-04')).toHaveTextContent(/1\.250 \/ 4\.000 korakov/);
    expect(screen.getByTestId('report-missed-0')).toHaveTextContent(/sob 3\. 10\. · okno 17:00–19:00/);
    // Illness in the family's wall clock (UTC in the payload → +02:00).
    expect(screen.getByTestId('report-illnesses')).toHaveTextContent(/čet 1\. 10\. 10:00 – čet 1\. 10\. 22:00/);
  });

  it('switches the period to 30 and 84 days (null score → "Še ni dovolj podatkov")', async () => {
    renderWithQuery(<ChildDetailScreen child={LUKA} family={FAMILY} onBack={jest.fn()} />);
    await flush();

    fireEvent.press(screen.getByTestId('period-30'));
    await flush();
    expect(getChildReport).toHaveBeenLastCalledWith(2, 30);
    expect(screen.getByTestId('report-30')).toBeTruthy();
    expect(screen.getByTestId('report-period-score')).toHaveTextContent('64');

    fireEvent.press(screen.getByTestId('period-84'));
    await flush();
    expect(getChildReport).toHaveBeenLastCalledWith(2, 84);
    expect(screen.getByTestId('report-period-score')).toHaveTextContent(CHILD_DETAIL_STRINGS.noScore);
    expect(screen.getByText('84 od 86 rutin · 2 bolezni')).toBeTruthy();

    // Back to 7: cached, no new request.
    const calls = getChildReport.mock.calls.length;
    fireEvent.press(screen.getByTestId('period-7'));
    await flush();
    expect(getChildReport.mock.calls.length).toBe(calls);
    expect(screen.getByTestId('report-period-score')).toHaveTextContent('71');
  });

  it('report error without data: retry', async () => {
    getChildReport.mockRejectedValueOnce(new TypeError('Network request failed'));
    renderWithQuery(<ChildDetailScreen child={LUKA} family={FAMILY} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('report-error')).toBeTruthy();
    fireEvent.press(screen.getByText(CHILD_DETAIL_STRINGS.retry));
    await flush();
    expect(screen.queryByTestId('report-error')).toBeNull();
    expect(screen.getByTestId('report-period-score')).toHaveTextContent('71');
  });

  it('timeline: first page, "Naloži več" loads page 2, actor nicknames from the family', async () => {
    getPetActivities
      .mockResolvedValueOnce(
        page(1, 2, [
          { id: 30, activity_type: 'fed_pet', value: 40, actor_user_id: 2, created_at: '2026-10-04T05:15:00Z', is_positive: true },
          { id: 29, activity_type: 'ignored_warning', value: 1, actor_user_id: null, created_at: '2026-10-04T04:00:00Z', is_positive: false },
        ]),
      )
      .mockResolvedValueOnce(
        page(2, 2, [
          { id: 10, activity_type: 'cleaned_poop', value: 0, actor_user_id: 5, created_at: '2026-10-03T16:00:00Z', is_positive: true },
        ]),
      );
    renderWithQuery(<ChildDetailScreen child={LUKA} family={FAMILY} onBack={jest.fn()} />);
    await flush();

    expect(getPetActivities).toHaveBeenCalledWith(7, 1, 20);
    expect(screen.getByTestId('timeline-item-30')).toHaveTextContent(/Luka nahranil\(a\) kužka/);
    expect(screen.getByTestId('timeline-item-29')).toHaveTextContent(/Opozorilo ni bilo upoštevano/);
    expect(screen.queryByTestId('timeline-item-10')).toBeNull();

    fireEvent.press(screen.getByTestId('timeline-more'));
    await flush();
    expect(getPetActivities).toHaveBeenLastCalledWith(7, 2, 20);
    expect(screen.getByTestId('timeline-item-10')).toHaveTextContent(/Maja počistil\(a\)/);
    expect(screen.queryByTestId('timeline-more')).toBeNull();
  });

  it('child without a pet: no report numbers, no timeline', async () => {
    getChildReport.mockResolvedValue({ ...report(7, null), pet_id: null });
    const child = makeScoredChild({ pet_id: null });
    renderWithQuery(<ChildDetailScreen child={child} family={FAMILY} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('report-no-pet')).toBeTruthy();
    expect(screen.queryByTestId('timeline')).toBeNull();
    expect(getPetActivities).not.toHaveBeenCalled();
  });

  it('pet media: the idle video on top (one player, released when the screen closes)', async () => {
    resetMockVideoPlayers();
    const IDLE = 'https://api.petprep.si/api/media/2?expires=1&v=idle&signature=a';
    const family = familyFromDashboard(
      makeScoredDashboard([LUKA], [
        makeFamilyPet({
          id: 7,
          caretakers: [{ child_id: 2, contract_signed: true }],
          media: makeMedia({ status: 'ready', videos: { idle: IDLE, sleeping: 'https://x/s?v=s' }, states: ['idle', 'sleeping'] }),
        }),
      ]) as never,
    ) as FamilyOverview;
    const { unmount } = renderWithQuery(<ChildDetailScreen child={LUKA} family={family} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('detail-pet-media')).toBeTruthy();
    expect(playerUris()).toEqual([IDLE]);
    unmount();
    expect(liveVideoPlayers()).toHaveLength(0);
  });

  it('m7: a locked pet (vet / hard stop / game over) shows the still image, no video', async () => {
    const IMG = 'https://api.petprep.si/api/media/1?expires=1&v=img&signature=a';
    for (const flags of [{ is_ill: true }, { is_hard_stopped: true }, { is_game_over: true }]) {
      resetMockVideoPlayers();
      const family = familyFromDashboard(
        makeScoredDashboard([LUKA], [
          makeFamilyPet({
            id: 7,
            caretakers: [{ child_id: 2, contract_signed: true }],
            media: makeMedia({ status: 'ready', reference_image_url: IMG, videos: { idle: 'https://x/i?v=i' } }),
            ...flags,
          }),
        ]) as never,
      ) as FamilyOverview;
      const { unmount } = renderWithQuery(<ChildDetailScreen child={LUKA} family={family} onBack={jest.fn()} />);
      await flush();
      expect(screen.getByTestId('detail-pet-media-image')).toBeTruthy();
      expect(mockVideoPlayers).toHaveLength(0);
      unmount();
    }
  });

  it('formatSteps', () => {
    expect(formatSteps(12500)).toBe('12.500');
    expect(formatSteps(999)).toBe('999');
    expect(formatSteps(-3)).toBe('0');
  });
});
