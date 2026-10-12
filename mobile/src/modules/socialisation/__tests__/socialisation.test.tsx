/**
 * M5-R17 — puppy socialisation (David 2026-10-12, "tedenska naloga"): the server's
 * `socialisation` block decides; the HUD shows "Nova izkušnja" while this puppy week's task is
 * open, the card records the picked experience with one tap, the parent sees a weekly row
 * (never scored) and a timeline entry. A pet without the block sees nothing new.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';
import { View } from 'react-native';

import { ApiError, api, clientFeatures, type ChildPetState } from '@/api/client';
import { applyBroadcast, nextRefreshDelay, normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { activityText, readChildReport } from '@/modules/family/scoring';
import SocialisationSheet from '@/modules/socialisation/SocialisationSheet';
import {
  broadcastSocialisation,
  experienceFromCode,
  readChildSocialisation,
  readReportSocialisation,
  reportSocialisationLine,
  showSocialisationChip,
  socialisationRefusalText,
  socialisationTimelineText,
} from '@/modules/socialisation/socialisation';
import { i18n } from '@/i18n';
import { childPetKey } from '@/hooks/queries/useChildPet';
import { useAppStore } from '@/store/appStore';
import { makeBroadcast, makeLiveChildState, makePet, makeSocialisation } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, socialisePet: jest.fn(), getChildPet: jest.fn() } };
});

const mocked = api as unknown as Record<string, jest.Mock>;
const T0 = Date.parse('2026-10-04T12:00:00+02:00');

function puppyState(socialisation: unknown = makeSocialisation(), o: Parameters<typeof makeLiveChildState>[0] = {}): ChildPetState {
  return makeLiveChildState({ ...o, pet: { breed_type: 'rottweiler', species: 'dog', ...o.pet } as Partial<ChildPetState['pet']>, socialisation });
}

function viewOf(state: ChildPetState): ChildPetView {
  return normalizeChildState(state, 0, T0);
}

async function flush() {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(0);
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
  jest.setSystemTime(T0);
  mocked.getChildPet.mockResolvedValue(puppyState());
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({ token: 't', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: makePet(), awaitingContract: false });
});

afterEach(async () => {
  jest.useRealTimers();
  jest.restoreAllMocks();
  await i18n.changeLanguage('sl');
});

describe('the build declares the feature', () => {
  it('sends `socialisation` with generate-pin / pin-login (both apps must declare it)', () => {
    expect(clientFeatures(true)).toContain('socialisation');
    expect(clientFeatures(false)).toContain('socialisation');
  });
});

describe('reading the server block', () => {
  it('reads an open week and drops a missing / malformed one (older server, no task)', () => {
    expect(readChildSocialisation(makeSocialisation())).toEqual({
      goal_per_week: 1,
      done_this_week: 0,
      done: false,
      week: 2,
      week_started_at: '2026-09-29T08:00:00+02:00',
      week_ends_at: '2026-10-06T08:00:00+02:00',
      experience: null,
      experiences: ['person', 'dog', 'sound', 'place'],
      next_allowed_at: null,
      blocked_reason: null,
      can_record: true,
    });
    expect(readChildSocialisation(null)).toBeNull();
    expect(readChildSocialisation(undefined)).toBeNull();
    expect(readChildSocialisation({ goal_per_week: 0 })).toBeNull();
    expect(readChildSocialisation(makeSocialisation({ experience: 'cat', blocked_reason: 'nope', experiences: ['cat'] }))).toMatchObject({
      experience: null,
      blocked_reason: null,
      experiences: ['person', 'dog', 'sound', 'place'],
    });
    expect(viewOf(makeLiveChildState()).socialisation).toBeNull();
    expect(viewOf(puppyState()).socialisation?.can_record).toBe(true);
  });

  it('shows the chip only while the week is open and the pet is not locked', () => {
    const open = readChildSocialisation(makeSocialisation());
    expect(showSocialisationChip(open, false)).toBe(true);
    expect(showSocialisationChip(open, true)).toBe(false);
    expect(showSocialisationChip(readChildSocialisation(makeSocialisation({ done: true, done_this_week: 1 })), false)).toBe(false);
    expect(showSocialisationChip(null, false)).toBe(false);
    // Quiet hours: still shown (the card explains when), no amber dot.
    const sleeping = readChildSocialisation(makeSocialisation({ can_record: false, blocked_reason: 'socialisation_quiet_hours' }));
    expect(showSocialisationChip(sleeping, false)).toBe(true);
    expect(sleeping?.can_record).toBe(false);
  });

  it('takes the broadcast block (pet level); a lock turns can_record off; a missing key keeps the view', () => {
    const current = readChildSocialisation(makeSocialisation());
    expect(broadcastSocialisation(current, undefined, false)).toBe(current);
    expect(broadcastSocialisation(current, undefined, true)?.can_record).toBe(false);
    expect(broadcastSocialisation(current, null, false)).toBeNull();
    expect(broadcastSocialisation(current, makeSocialisation({ done: true, done_this_week: 1, experience: 'dog' }), false)).toMatchObject({ done: true, experience: 'dog' });

    const view = viewOf(puppyState());
    const result = applyBroadcast(view, makeBroadcast({ pet_id: view.pet.id, socialisation: makeSocialisation({ done: true, done_this_week: 1, experience: 'sound', can_record: false }) }));
    expect(result?.view.socialisation).toMatchObject({ done: true, experience: 'sound' });
  });

  it('refreshes the state when the puppy week ends (a new task, or none once grown)', () => {
    const view = viewOf(puppyState(makeSocialisation({ week_ends_at: '2026-10-04T13:00:00+02:00' })));
    expect(nextRefreshDelay(view, T0)).toBeLessThanOrEqual(60 * 60 * 1000);
  });
});

describe('texts (SL child, EN)', () => {
  it('explains a refusal with its family-local time', () => {
    const now = '2026-10-04T22:30:00+02:00';
    expect(socialisationRefusalText('socialisation_quiet_hours', '2026-10-05T07:00:00+02:00', now, 'Europe/Ljubljana')).toBe(
      'Kuža zdaj spi. Novo izkušnjo mu pokaži jutri ob 07:00.',
    );
    expect(socialisationRefusalText('socialisation_week_done', null, now, 'Europe/Ljubljana')).toBe('Nova izkušnja za ta teden je že opravljena. Bravo!');
    expect(socialisationRefusalText('socialisation_not_available', null, now, 'Europe/Ljubljana')).toBe('Ta teden kuža nima nove izkušnje.');
    expect(socialisationRefusalText('something_else', null, now, 'Europe/Ljubljana')).toBe('Ni uspelo. Poskusi znova.');
  });

  it('names the experience in the parent timeline (value = the server code)', async () => {
    expect(experienceFromCode(3)).toBe('sound');
    expect(experienceFromCode(9)).toBeNull();
    expect(socialisationTimelineText('socialised_pet', 'Mia', 2)).toBe('Nova izkušnja: drug pes · Mia');
    expect(socialisationTimelineText('fed_pet', 'Mia', null)).toBeNull();
    expect(activityText({ activity_type: 'socialised_pet', actor_nickname: 'Mia', value: 4 }, 'dog')).toBe('Nova izkušnja: novo mesto · Mia');
    expect(activityText({ activity_type: 'socialised_pet', actor_nickname: null, value: null }, 'dog')).toBe('Nova izkušnja');
    await i18n.changeLanguage('en');
    expect(activityText({ activity_type: 'socialised_pet', actor_nickname: 'Mia', value: 1 }, 'dog')).toBe('New experience: a new person · Mia');
  });
});

describe('the parent report row (never scored)', () => {
  const raw = {
    goal_per_week: 1,
    expected: 3,
    done: 1,
    done_by_child: 1,
    missed: 1,
    open: 1,
    scored: false,
    weeks: [
      { week: 1, started_at: '2026-10-20T08:00:00+02:00', ends_at: '2026-10-27T08:00:00+01:00', goal: 1, done: 1, status: 'done', experiences: ['dog'], by_child: true },
      { week: 2, started_at: '2026-10-27T08:00:00+01:00', ends_at: '2026-11-03T08:00:00+01:00', goal: 1, done: 0, status: 'missed', experiences: [], by_child: false },
      { week: 3, started_at: '2026-11-03T08:00:00+01:00', ends_at: '2026-11-10T08:00:00+01:00', goal: 1, done: 0, status: 'open', experiences: [], by_child: false },
    ],
  };

  it('reads the weeks and words the row (SL plurals, EN)', async () => {
    const report = readReportSocialisation(raw);
    expect(report?.weeks.map((w) => [w.week, w.status, w.experiences])).toEqual([
      [1, 'done', ['dog']],
      [2, 'missed', []],
      [3, 'open', []],
    ]);
    expect(reportSocialisationLine(report!)).toEqual({ count: '1 od 3 tednov', detail: '1 zamujen · ta teden še čaka' });
    expect(reportSocialisationLine({ ...report!, expected: 1, done: 1, missed: 0, open: 0 })).toEqual({ count: '1 od 1 tedna', detail: '' });
    expect(reportSocialisationLine({ ...report!, missed: 2, open: 0 }).detail).toBe('2 zamujena');
    await i18n.changeLanguage('en');
    expect(reportSocialisationLine(report!)).toEqual({ count: '1 of 3 weeks', detail: '1 missed · this week still open' });
  });

  it('reads excused weeks (David 2026-10-12: illness / pause → »opravičen«) and words them, older servers read as 0', async () => {
    const withExcused = {
      ...raw,
      expected: 2,
      missed: 0,
      excused: 1,
      weeks: [raw.weeks[0], { ...raw.weeks[1], status: 'excused' }, raw.weeks[2]],
    };
    const report = readReportSocialisation(withExcused);
    expect(report?.weeks.map((w) => w.status)).toEqual(['done', 'excused', 'open']);
    expect(report?.excused).toBe(1);
    expect(reportSocialisationLine(report!)).toEqual({ count: '1 od 2 tednov', detail: '1 opravičen · ta teden še čaka' });
    expect(reportSocialisationLine({ ...report!, excused: 2, open: 0 }).detail).toBe('2 opravičena');
    expect(reportSocialisationLine({ ...report!, excused: 3, missed: 1, open: 0 }).detail).toBe('1 zamujen · 3 opravičeni');
    // An unknown status is dropped; a missing `excused` (older server) reads as 0.
    expect(readReportSocialisation({ ...raw, weeks: [{ ...raw.weeks[0], status: 'skipped' }] })).toBeNull();
    expect(readReportSocialisation(raw)?.excused).toBe(0);
    await i18n.changeLanguage('en');
    expect(reportSocialisationLine(report!)).toEqual({ count: '1 of 2 weeks', detail: '1 excused · this week still open' });
  });

  it('is null without weeks / from an older server, and part of the child report reader', () => {
    expect(readReportSocialisation(null)).toBeNull();
    expect(readReportSocialisation({ weeks: [] })).toBeNull();
    const base = { child: { id: 1, name: 'Mia' }, days: 7, by_type: {}, daily: [], missed: [], illnesses: [] };
    expect(readChildReport(base)?.socialisation).toBeNull();
    expect(readChildReport({ ...base, socialisation: raw })?.socialisation?.missed).toBe(1);
  });
});

describe('the card (one tap records the experience)', () => {
  function renderSheet(state: ChildPetState = puppyState()) {
    const view = viewOf(state);
    const utils = renderWithQuery(
      <View>
        <SocialisationSheet visible socialisation={view.socialisation} nowIso={view.server_time} timezone={view.timezone} onClose={jest.fn()} />
      </View>,
    );
    utils.client.setQueryData(childPetKey, view);
    return utils;
  }

  it('shows the four experiences and records the tapped one; the server state replaces the cache', async () => {
    const after = puppyState(makeSocialisation({ done: true, done_this_week: 1, experience: 'sound', can_record: false, blocked_reason: 'socialisation_week_done' }));
    mocked.socialisePet.mockResolvedValue({ status: 'accepted', state: after });
    const { client: queryClient } = renderSheet();

    expect(screen.getByText('Nova izkušnja')).toBeTruthy();
    expect(screen.getByText('2. teden mladička')).toBeTruthy();
    for (const label of ['Nov človek', 'Drug kuža', 'Nov zvok', 'Novo mesto']) expect(screen.getByText(label)).toBeTruthy();

    fireEvent.press(screen.getByTestId('hud-socialisation-sheet-option-sound'));
    await flush();
    expect(mocked.socialisePet).toHaveBeenCalledTimes(1);
    expect(mocked.socialisePet).toHaveBeenCalledWith('sound');
    expect(screen.getByText('Bravo! Kuža je spoznal nekaj novega.')).toBeTruthy();
    expect(queryClient.getQueryData<ChildPetView>(childPetKey)?.socialisation).toMatchObject({ done: true, experience: 'sound' });
  });

  it('explains a refusal with its time and keeps the server state', async () => {
    const state = puppyState(makeSocialisation({ can_record: false, blocked_reason: 'socialisation_quiet_hours', next_allowed_at: '2026-10-05T07:00:00+02:00' }));
    mocked.socialisePet.mockRejectedValue(
      new ApiError('refused', 422, { reason: 'socialisation_quiet_hours', next_allowed_at: '2026-10-05T07:00:00+02:00', state }),
    );
    const { client: queryClient } = renderSheet();
    fireEvent.press(screen.getByTestId('hud-socialisation-sheet-option-dog'));
    await flush();
    expect(screen.getByText('Kuža zdaj spi. Novo izkušnjo mu pokaži jutri ob 07:00.')).toBeTruthy();
    expect(queryClient.getQueryData<ChildPetView>(childPetKey)?.socialisation).toMatchObject({ done: false, blocked_reason: 'socialisation_quiet_hours' });
  });

  it('a done week shows what was done and cannot be tapped again', () => {
    renderSheet(puppyState(makeSocialisation({ done: true, done_this_week: 1, experience: 'person', can_record: false, blocked_reason: 'socialisation_week_done' })));
    expect(screen.getByText('Ta teden opravljeno ✓ (Nov človek)')).toBeTruthy();
    fireEvent.press(screen.getByTestId('hud-socialisation-sheet-option-dog'));
    expect(mocked.socialisePet).not.toHaveBeenCalled();
  });

  it('renders nothing without a task', () => {
    renderWithQuery(
      <View>
        <SocialisationSheet visible socialisation={null} nowIso={null} timezone={null} onClose={jest.fn()} />
      </View>,
    );
    expect(screen.queryByTestId('hud-socialisation-sheet')).toBeNull();
  });
});
