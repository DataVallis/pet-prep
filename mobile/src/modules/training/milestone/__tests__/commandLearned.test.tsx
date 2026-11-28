/**
 * »Kuža zna ukaz!« (David 2026-11-28): the milestone result screen only with the finish answer's
 * `command_learned`, reduce motion (static badge), picture mode / no clip / no school group, the
 * nameless headline, sound + haptic only on the game layer and only with `game_feedback`, the
 * badges line, the parent push and the timeline row.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';
import { AccessibilityInfo } from 'react-native';
import * as Haptics from 'expo-haptics';

import { api } from '@/api/client';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { activityText, readTimeline } from '@/modules/family/scoring';
import { setGameLayerAvailableForTests } from '@/modules/gameLayer/capability';
import { normalizePetMedia, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import { isUrgentPush, parsePushData } from '@/modules/push/pushData';
import { profileHeaderA11y, profileSheetRows } from '@/modules/petProfile/profileSheet';
import { commandLearnedTimelineText, commandsLine, milestoneFor, milestoneHeadline, milestoneMedia, orderedCommands } from '@/modules/training/commandLearned';
import CommandLearnedHero from '@/modules/training/milestone/CommandLearnedHero';
import { resetMilestoneFxForTests } from '@/modules/training/milestone/loadMilestoneFx';
import SchoolResultV2 from '@/modules/training/schoolV2/SchoolResultV2';
import TrainingOverlay from '@/modules/training/TrainingOverlay';
import { readChildTraining, readFinishResponse, readTrainingResult, type TrainingResult } from '@/modules/training/training';
import { i18n } from '@/i18n';
import {
  makeEnabledTraining,
  makeLiveChildState,
  makeMedia,
  makeTrainingCommands,
  makeTrainingResult,
  makeTrainingSessionPayload,
} from '@/test-utils/fixtures';
import { fakeAudioPlayers, resetFakeAudioPlayers } from '@/test-utils/gameLayer';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, startTraining: jest.fn(), finishTraining: jest.fn(), getChildPet: jest.fn() } };
});

jest.setTimeout(30_000);

const startTraining = api.startTraining as jest.Mock;
const finishTraining = api.finishTraining as jest.Mock;
const URL = (k: string) => `https://api.petprep.si/m/${k}.mp4?v=1`;
const POSE = 'https://api.petprep.si/m/place.png?v=1';
const LEARNED = { command: 'place', learned_at: '2026-11-28T10:00:00Z' } as const;

function schoolMedia(opts: { clips?: boolean; pose?: boolean; group?: boolean } = {}): PetMediaInfo {
  return normalizePetMedia(
    makeMedia({
      status: 'ready',
      reference_image_url: 'https://api.petprep.si/m/ref.png?v=1',
      poses: opts.pose === false ? {} : { place: POSE },
      phases: opts.clips === false ? {} : { school_place_success: URL('school_place_success'), school_place_performing: URL('school_place_performing') },
      phase_groups: opts.group === false ? [] : ['school'],
    }),
  );
}

function result(overrides: Record<string, unknown> = {}): TrainingResult {
  const parsed = readTrainingResult(makeTrainingResult({ command: 'place', progress_before: 94, progress_after: 100, progress_gain: 6, rules_version: 2, ...overrides }));
  if (parsed === null) throw new Error('bad fixture');
  return parsed;
}

function hero(props: Partial<Parameters<typeof CommandLearnedHero>[0]> = {}) {
  return (
    <CommandLearnedHero
      command="place"
      petName="Pika"
      media={schoolMedia()}
      mode="video"
      breed="unknown"
      species="dog"
      reduceMotion={false}
      soundAndHaptics
      {...props}
    />
  );
}

function renderResult(milestone: React.ReactNode | null) {
  return renderWithQuery(
    <SchoolResultV2 result={result()} status="accepted" canStartAgain practicesLeft={3} onAgain={jest.fn()} onDone={jest.fn()} milestone={milestone} />,
  );
}

async function advance(ms = 0) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

describe('command learned — readers and rules', () => {
  it('reads `command_learned` defensively (missing / malformed → null) and `learned_at` per command', () => {
    const body = { status: 'accepted', result: makeTrainingResult(), state: makeLiveChildState() };
    expect(readFinishResponse(body)?.command_learned).toBeNull();
    expect(readFinishResponse({ ...body, command_learned: { command: 'jump', learned_at: LEARNED.learned_at } })?.command_learned).toBeNull();
    expect(readFinishResponse({ ...body, command_learned: { command: 'place' } })?.command_learned).toBeNull();
    expect(readFinishResponse({ ...body, command_learned: LEARNED })?.command_learned).toEqual(LEARNED);
    const training = readChildTraining({ ...makeEnabledTraining(), commands: [{ command: 'sit', progress: 100, learned: true, last_practised_at: null, learned_at: '2026-11-20T08:00:00Z' }] });
    expect(training.commands[0].learned_at).toBe('2026-11-20T08:00:00Z');
    expect(training.commands[1].learned_at).toBeNull();
  });

  it('the milestone belongs only to its own command', () => {
    expect(milestoneFor(null, 'place')).toBeNull();
    expect(milestoneFor({ command: 'sit', learned_at: LEARNED.learned_at }, 'place')).toBeNull();
    expect(milestoneFor(LEARNED, 'place')).toEqual(LEARNED);
  });

  it('headline with the name, nameless »Kuža …«, and in English', async () => {
    expect(milestoneHeadline('place', 'Pika')).toBe('Pika zna ukaz »Prostor«!');
    expect(milestoneHeadline('place', null)).toBe('Kuža zna ukaz »Prostor«!');
    expect(milestoneHeadline('place', '  ')).toBe('Kuža zna ukaz »Prostor«!');
    await i18n.changeLanguage('en');
    expect(milestoneHeadline('place', 'Pika')).toBe('Pika knows “Place”!');
    expect(milestoneHeadline('sit', null)).toBe('Your pup knows “Sit”!');
    await i18n.changeLanguage('sl');
  });

  it('picture: the success clip in video mode, the pose in picture mode / without a clip, nothing without the pose or the school group', () => {
    const video = milestoneMedia(schoolMedia(), 'place', 'video');
    expect(video?.current).toMatchObject({ kind: 'clip', url: URL('school_place_success'), loop: false });
    expect(video?.still).toBe(POSE);
    expect(milestoneMedia(schoolMedia(), 'place', 'picture')?.current).toMatchObject({ kind: 'image', url: POSE });
    expect(milestoneMedia(schoolMedia({ clips: false }), 'place', 'video')?.current).toMatchObject({ kind: 'image', url: POSE });
    // Never the standing reference image, never a blank.
    expect(milestoneMedia(schoolMedia({ clips: false, pose: false }), 'place', 'video')).toBeNull();
    expect(milestoneMedia(schoolMedia({ group: false }), 'place', 'video')).toBeNull();
  });

  it('badges line: learned first (in learning order), then the rest; the header label leaves it out', () => {
    const commands = readChildTraining({
      ...makeEnabledTraining(),
      commands: [
        { command: 'sit', progress: 100, learned: true, last_practised_at: null, learned_at: '2026-11-25T08:00:00Z' },
        { command: 'come', progress: 60, learned: false, last_practised_at: null },
        { command: 'place', progress: 100, learned: true, last_practised_at: null, learned_at: '2026-11-28T08:00:00Z' },
        { command: 'potty', progress: 10, learned: false, last_practised_at: null },
      ],
    }).commands;
    expect(orderedCommands(commands).map((c) => c.command)).toEqual(['sit', 'place', 'come', 'potty']);
    expect(commandsLine(commands)).toBe('Sedi ✓ · Prostor ✓ · Pridi 60 % · Lulat zunaj 10 %');
    expect(commandsLine([])).toBeNull();
    const rows = profileSheetRows('Labradorec', { stage: 'puppy' } as never, null, commands);
    expect(rows.find((r) => r.key === 'commands')).toEqual({ key: 'commands', label: 'Ukazi', value: 'Sedi ✓ · Prostor ✓ · Pridi 60 % · Lulat zunaj 10 %' });
    expect(profileHeaderA11y(rows)).not.toContain('Sedi');
    expect(profileSheetRows('Labradorec', { stage: 'puppy' } as never).find((r) => r.key === 'commands')).toBeUndefined();
  });

  it('parent push `command_learned` → that pet, never urgent', () => {
    expect(parsePushData({ type: 'command_learned', pet_id: 7 })).toEqual({ type: 'command_learned', petId: 7 });
    expect(parsePushData({ type: 'command_learned' })).toBeNull();
    expect(isUrgentPush('command_learned')).toBe(false);
  });

  it('parent timeline row: »Kuža zna ukaz »Prostor« · Mia«, plain without a command', () => {
    const [row] = readTimeline([{ id: 1, activity_type: 'command_learned', meta: { command: 'place' }, actor_nickname: 'Mia', created_at: null }]);
    expect(row.command).toBe('place');
    expect(row.is_positive).toBe(true);
    expect(activityText(row)).toBe('Kuža zna ukaz »Prostor« · Mia');
    const [top] = readTimeline([{ id: 2, activity_type: 'command_learned', command: 'sit', created_at: null }]);
    expect(activityText(top)).toBe('Kuža zna ukaz »Sedi«');
    expect(commandLearnedTimelineText('command_learned', null, null)).toBe('Kuža se je naučil nov ukaz');
    expect(commandLearnedTimelineText('fed_pet', 'sit', null)).toBeNull();
    expect(readTimeline([{ id: 3, activity_type: 'fed_pet', command: 'sit' }])[0].command).toBeUndefined();
  });
});

describe('command learned — the result screen', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    resetFakeAudioPlayers();
    resetMilestoneFxForTests();
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
  });

  afterEach(() => {
    setGameLayerAvailableForTests(false);
    resetMilestoneFxForTests();
    jest.useRealTimers();
  });

  it('without `command_learned`: the old »Bravo!« result with the small green line', () => {
    renderResult(null);
    expect(screen.getByTestId('training-result-title').props.children).toBe('Bravo!');
    expect(screen.getByTestId('training-result-learned')).toBeTruthy();
    expect(screen.queryByTestId('training-milestone')).toBeNull();
    expect(screen.getByTestId('training-result-round-1')).toBeTruthy();
  });

  it('milestone: big headline instead of »Bravo!«, announced; burst; pose clip; details collapsed below the buttons', async () => {
    const announce = jest.spyOn(AccessibilityInfo, 'announceForAccessibility').mockImplementation(() => undefined);
    renderResult(hero());
    expect(screen.getByTestId('training-milestone-title').props.children).toBe('Pika zna ukaz »Prostor«!');
    expect(screen.queryByTestId('training-result-title')).toBeNull();
    expect(screen.queryByTestId('training-result-learned')).toBeNull();
    expect(announce).toHaveBeenCalledWith('Pika zna ukaz »Prostor«!');
    expect(screen.getByTestId('training-milestone-burst')).toBeTruthy();
    expect(screen.getByTestId('training-milestone-media')).toBeTruthy();
    expect(screen.getByTestId('training-milestone-badge')).toBeTruthy();
    expect(screen.getByTestId('training-result-gain')).toBeTruthy();
    expect(screen.getByTestId('training-again')).toBeTruthy();
    expect(screen.getByTestId('training-done')).toBeTruthy();
    // The grid and »Pravočasne pohvale« sit behind »Podrobnosti vaje«.
    expect(screen.queryByTestId('training-result-round-1')).toBeNull();
    expect(screen.queryByTestId('training-result-successes')).toBeNull();
    const toggle = screen.getByTestId('training-result-details-toggle');
    expect(toggle.props.accessibilityState).toEqual({ expanded: false });
    fireEvent.press(toggle);
    expect(screen.getByTestId('training-result-round-1')).toBeTruthy();
    expect(screen.getByTestId('training-result-successes')).toBeTruthy();
    expect(screen.getByTestId('training-result-details-toggle').props.accessibilityState).toEqual({ expanded: true });
    announce.mockRestore();
  });

  it('reduce motion: no burst, the static badge only', () => {
    renderResult(hero({ reduceMotion: true }));
    expect(screen.queryByTestId('training-milestone-burst')).toBeNull();
    expect(screen.getByTestId('training-milestone-badge')).toBeTruthy();
  });

  it('picture mode shows the pose image; no clip and no pose → the badge only, never blank', () => {
    renderResult(hero({ mode: 'picture' }));
    expect(screen.getByTestId('training-milestone-media')).toBeTruthy();
    screen.unmount();
    renderResult(hero({ media: schoolMedia({ clips: false, pose: false }), petName: null }));
    expect(screen.queryByTestId('training-milestone-media')).toBeNull();
    expect(screen.getByTestId('training-milestone-badge')).toBeTruthy();
    expect(screen.getByTestId('training-milestone-title').props.children).toBe('Kuža zna ukaz »Prostor«!');
  });

  it('sound + haptic only with the game layer, positive only, and off with `game_feedback` false', async () => {
    // No game layer (older binary): nothing native is loaded.
    renderResult(hero());
    await advance(300);
    expect(Haptics.notificationAsync).not.toHaveBeenCalled();
    expect(fakeAudioPlayers()).toHaveLength(0);
    screen.unmount();

    setGameLayerAvailableForTests(true);
    resetMilestoneFxForTests();
    renderResult(hero());
    await advance(300);
    expect(Haptics.notificationAsync).toHaveBeenCalledTimes(1);
    expect(Haptics.impactAsync).not.toHaveBeenCalled();
    expect(fakeAudioPlayers()).toHaveLength(1);
    expect(fakeAudioPlayers()[0].play).toHaveBeenCalledTimes(1);
    screen.unmount();

    jest.clearAllMocks();
    resetFakeAudioPlayers();
    renderResult(hero({ soundAndHaptics: false }));
    await advance(300);
    expect(Haptics.notificationAsync).not.toHaveBeenCalled();
    expect(fakeAudioPlayers().every((p) => p.play.mock.calls.length === 0)).toBe(true);
  });
});

describe('command learned — through TrainingOverlay (v1 result)', () => {
  function view(name: string | null): ChildPetView {
    const raw = makeLiveChildState({ training: makeEnabledTraining({ commands: makeTrainingCommands({ sit: 94 }) }) });
    const base = normalizeChildState(raw, 0, Date.parse('2026-10-04T12:00:00+02:00'));
    return { ...base, pet: { ...base.pet, name } };
  }

  async function playSession(commandLearned: unknown) {
    startTraining.mockResolvedValueOnce({ status: 'accepted', session: makeTrainingSessionPayload(), state: makeLiveChildState({ training: makeEnabledTraining({ can_start: false }) }) });
    finishTraining.mockResolvedValueOnce({
      status: 'accepted',
      result: makeTrainingResult({ successes: 5, progress_before: 94, progress_after: 100, progress_gain: 6 }),
      state: makeLiveChildState({ training: makeEnabledTraining({ today_done: true }) }),
      ...(commandLearned !== undefined ? { command_learned: commandLearned } : {}),
    });
    fireEvent.press(screen.getByTestId('training-start-sit'));
    await advance(0);
    await advance(50_200);
    await advance(0);
  }

  beforeEach(() => {
    jest.clearAllMocks();
    startTraining.mockReset();
    finishTraining.mockReset();
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    jest.setSystemTime(new Date('2026-10-04T12:00:00+02:00'));
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('`command_learned` → the milestone headline replaces the title (badge only without school media)', async () => {
    renderWithQuery(<TrainingOverlay view={view('Pika')} onClose={jest.fn()} reduceMotion />);
    await playSession({ command: 'sit', learned_at: '2026-10-04T10:00:50Z' });
    expect(screen.getByTestId('training-milestone-title').props.children).toBe('Pika zna ukaz »Sedi«!');
    expect(screen.queryByTestId('training-result-title')).toBeNull();
    expect(screen.queryByTestId('training-result-learned')).toBeNull();
    expect(screen.queryByTestId('training-milestone-media')).toBeNull();
    expect(screen.getByTestId('training-milestone-badge')).toBeTruthy();
    expect(screen.getByTestId('training-done')).toBeTruthy();
  });

  it('100 % without `command_learned` (e.g. an older server or a repeat): no milestone', async () => {
    renderWithQuery(<TrainingOverlay view={view(null)} onClose={jest.fn()} reduceMotion />);
    await playSession(undefined);
    expect(screen.queryByTestId('training-milestone')).toBeNull();
    expect(screen.getByTestId('training-result-learned')).toBeTruthy();
  });
});
