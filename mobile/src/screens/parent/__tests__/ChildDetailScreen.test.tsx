/**
 * M2-05: child report (7 / 30 / 84 days) and the pet's paginated timeline with the
 * nicknames of the children who acted.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';
import { BackHandler } from 'react-native';

import { api } from '@/api/client';
import { familyFromDashboard, type FamilyOverview } from '@/modules/family/family';
import ChildDetailScreen, { CHILD_DETAIL_STRINGS, formatSteps } from '@/screens/parent/ChildDetailScreen';
import {
  makeDayRow,
  makeFamilyPet,
  makeGrowth,
  makeLegacyPetProfile,
  makeMedia,
  makeMissed,
  makeScoredChild,
  makeScoredDashboard,
  makeTwoStageGrowth,
} from '@/test-utils/fixtures';
import { GROWTH_STRINGS } from '@/modules/petMedia/growth';
import { liveVideoPlayers, mockVideoPlayers, playerUris, resetMockVideoPlayers } from '@/test-utils/videoPlayers';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, getChildReport: jest.fn(), getPetActivities: jest.fn(), getParentPetGrowth: jest.fn() },
  };
});

type BackPressHandler = Parameters<typeof BackHandler.addEventListener>[1];
/** Simulates the Android hardware back button on a captured handler. */
const pressBack = (h: BackPressHandler | null | undefined) => h?.({} as Parameters<BackPressHandler>[0]);
const getChildReport = api.getChildReport as jest.Mock;
const getPetActivities = api.getPetActivities as jest.Mock;
const getParentPetGrowth = api.getParentPetGrowth as jest.Mock;

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
    getParentPetGrowth.mockResolvedValue(makeGrowth([]));
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

  it('M5-R04: the dog card shows stage · age, origin, next stage and today\'s meals', async () => {
    renderWithQuery(<ChildDetailScreen child={LUKA} family={FAMILY} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('detail-pet-stage')).toHaveTextContent('Mladiček · 2 meseca');
    const card = screen.getByTestId('detail-pet-profile');
    expect(card).toHaveTextContent(/Kupljen pri vzreditelju/);
    expect(card).toHaveTextContent(/Od 24\. 11\. 2026 mlad pes/);
    expect(card).toHaveTextContent(/Danes 4 obroki — 1 v tihih urah nahrani starš/);
  });

  it('M5-R04: a legacy pet shows no dog card (and an old server without profile does not crash)', async () => {
    const legacy = familyFromDashboard(
      makeScoredDashboard([LUKA], [makeFamilyPet({ id: 7, profile: makeLegacyPetProfile(), caretakers: [{ child_id: 2, contract_signed: true }] })]) as never,
    ) as FamilyOverview;
    const { unmount } = renderWithQuery(<ChildDetailScreen child={LUKA} family={legacy} onBack={jest.fn()} />);
    await flush();
    expect(screen.queryByTestId('detail-pet-profile')).toBeNull();
    expect(screen.getByTestId('report-period-score')).toHaveTextContent('71');
    unmount();

    const noProfile = { ...FAMILY, pets: FAMILY.pets.map((p) => ({ ...p, profile: undefined as never })) };
    renderWithQuery(<ChildDetailScreen child={LUKA} family={noProfile} onBack={jest.fn()} />);
    await flush();
    expect(screen.queryByTestId('detail-pet-profile')).toBeNull();
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

  it('read-only album: opens over the detail, pauses the card video, closes back', async () => {
    resetMockVideoPlayers();
    const IDLE = 'https://api.petprep.si/api/media/2?expires=1&v=idle&signature=a';
    const SLEEP = 'https://api.petprep.si/api/media/3?expires=1&v=sleep&signature=a';
    const family = familyFromDashboard(
      makeScoredDashboard([LUKA], [
        makeFamilyPet({
          id: 7,
          caretakers: [{ child_id: 2, contract_signed: true }],
          media: makeMedia({ status: 'ready', videos: { idle: IDLE, sleeping: SLEEP }, states: ['idle', 'sleeping'] }),
        }),
      ]) as never,
    ) as FamilyOverview;
    renderWithQuery(<ChildDetailScreen child={LUKA} family={family} onBack={jest.fn()} />);
    await flush();
    const card = liveVideoPlayers()[0];
    expect(card.playing).toBe(true);

    fireEvent.press(screen.getByText(CHILD_DETAIL_STRINGS.album));
    expect(screen.getByTestId('detail-album')).toBeTruthy();
    expect(screen.getByText('Posnetki kužka')).toBeTruthy();
    expect(screen.getByTestId('detail-content', { includeHiddenElements: true }).props.importantForAccessibility).toBe(
      'no-hide-descendants',
    );
    expect(card.playing).toBe(false);
    fireEvent.press(screen.getByTestId('album-item-sleeping'));
    expect(liveVideoPlayers().filter((p) => p.playing)).toHaveLength(1);

    fireEvent.press(screen.getByTestId('album-close'));
    expect(screen.queryByTestId('detail-album')).toBeNull();
    expect(card.playing).toBe(true);
  });

  it('"Album rasti" (view-only): fetched for the pet only when the album opens; ≥ 2 pictures → section', async () => {
    resetMockVideoPlayers();
    getParentPetGrowth.mockResolvedValue(makeTwoStageGrowth(new Date(Date.now() + 30 * 60_000).toISOString()));
    const family = familyFromDashboard(
      makeScoredDashboard([LUKA], [
        makeFamilyPet({
          id: 7,
          caretakers: [{ child_id: 2, contract_signed: true }],
          media: makeMedia({ status: 'ready', reference_image_url: 'https://api.petprep.si/api/media/1?v=img', states: ['idle'] }),
        }),
      ]) as never,
    ) as FamilyOverview;
    renderWithQuery(<ChildDetailScreen child={LUKA} family={family} onBack={jest.fn()} />);
    await flush();
    expect(getParentPetGrowth).not.toHaveBeenCalled();

    fireEvent.press(screen.getByTestId('detail-album-open'));
    expect(await screen.findByText(GROWTH_STRINGS.section)).toBeTruthy();
    expect(getParentPetGrowth).toHaveBeenCalledTimes(1);
    expect(getParentPetGrowth).toHaveBeenCalledWith(7);
    // Family-local date of the second picture (Europe/Ljubljana).
    expect(screen.getByText('24. 11. 2026')).toBeTruthy();
    expect(screen.getByTestId('album-growth-2-current')).toBeTruthy();

    // View-only: tapping opens the same viewer, nothing else (no care actions in the album).
    fireEvent.press(screen.getByTestId('album-growth-1'));
    expect(screen.getByTestId('album-viewer')).toBeTruthy();
    expect(screen.getByText('Mladiček · 2 meseca')).toBeTruthy();
    expect(screen.queryByTestId(/^action-/)).toBeNull();
  });

  it('"Album rasti": an error → no section, album unchanged', async () => {
    getParentPetGrowth.mockRejectedValueOnce(new Error('offline'));
    const family = familyFromDashboard(
      makeScoredDashboard([LUKA], [
        makeFamilyPet({
          id: 7,
          caretakers: [{ child_id: 2, contract_signed: true }],
          media: makeMedia({ status: 'ready', reference_image_url: 'https://api.petprep.si/api/media/1?v=img', states: ['idle'] }),
        }),
      ]) as never,
    ) as FamilyOverview;
    renderWithQuery(<ChildDetailScreen child={LUKA} family={family} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('detail-album-open'));
    await flush();
    expect(getParentPetGrowth).toHaveBeenCalledTimes(1);
    expect(screen.queryByTestId('album-growth')).toBeNull();
    expect(screen.getByTestId('album-item-photo')).toBeTruthy();
  });

  it('Android back closes the parent album', async () => {
    resetMockVideoPlayers();
    const handlers: BackPressHandler[] = [];
    const spy = jest.spyOn(BackHandler, 'addEventListener').mockImplementation((_event, h) => {
      handlers.push(h);
      return { remove: jest.fn() };
    });
    const family = familyFromDashboard(
      makeScoredDashboard([LUKA], [
        makeFamilyPet({
          id: 7,
          caretakers: [{ child_id: 2, contract_signed: true }],
          media: makeMedia({ status: 'ready', reference_image_url: 'https://api.petprep.si/api/media/1?v=img', states: ['idle'] }),
        }),
      ]) as never,
    ) as FamilyOverview;
    renderWithQuery(<ChildDetailScreen child={LUKA} family={family} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('detail-album-open'));
    act(() => {
      pressBack(handlers[handlers.length - 1]);
    });
    expect(screen.queryByTestId('detail-album')).toBeNull();
    spy.mockRestore();
  });

  it('no album link while the pet has no media', async () => {
    renderWithQuery(<ChildDetailScreen child={LUKA} family={FAMILY} onBack={jest.fn()} />);
    await flush();
    expect(screen.queryByTestId('detail-album-open')).toBeNull();
  });

  it('formatSteps', () => {
    expect(formatSteps(12500)).toBe('12.500');
    expect(formatSteps(999)).toBe('999');
    expect(formatSteps(-3)).toBe('0');
  });
});
