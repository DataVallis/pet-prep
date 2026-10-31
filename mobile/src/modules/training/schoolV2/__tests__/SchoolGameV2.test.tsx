/**
 * GAMES_SPEC phase 3 — school v2 end to end through `TrainingOverlay` (the HUD's entry): the
 * capability fallback (no game layer → `rules` never sent, v1 screen), `rules: 2` + full-screen
 * game with the layer, the deck gating the clock, the big command chip + cue sound, the treat token
 * (only with `treat_token`, else »Pohvali« ♥), the instant ✓, the cut back to standing only under
 * the mask (the release clip is never mounted), »Končaj« in the break → `ended_after_round: 1` and
 * the per-cue result, the parent's sound switch and picture mode.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';
import * as Haptics from 'expo-haptics';

import { api } from '@/api/client';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { setGameLayerAvailableForTests } from '@/modules/gameLayer/capability';
import { GAME_SOUNDS } from '@/modules/gameLayer/gameFxCore';
import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import TrainingOverlay, { resetSchoolGameV2ForTests } from '@/modules/training/TrainingOverlay';
import { SCHOOL_V2_STRINGS, TRAINING_STRINGS } from '@/modules/training/training';
import {
  makeEnabledTraining,
  makeLiveChildState,
  makeMedia,
  makeSchoolV2SessionPayload,
  makeSmoothTrainingSessionPayload,
  makeTrainingResult,
} from '@/test-utils/fixtures';
import { fakeAudioPlayers, resetFakeAudioPlayers } from '@/test-utils/gameLayer';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { mockVideoPlayers, resetMockVideoPlayers, sourceUri } from '@/test-utils/videoPlayers';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, startTraining: jest.fn(), finishTraining: jest.fn(), getChildPet: jest.fn() } };
});

jest.setTimeout(30_000);

const startTraining = api.startTraining as jest.Mock;
const finishTraining = api.finishTraining as jest.Mock;
const ID = 'school-v2';
const URL = (k: string) => `https://api.petprep.si/m/${k}.mp4?v=1`;
const CLIPS = { att: URL('school_sit_attention_loop'), perf: URL('school_sit_performing'), succ: URL('school_sit_success_loop'), release: URL('school_sit_release') };

function schoolView(opts: { treat?: boolean; settings?: { media_display?: 'video' | 'picture'; game_feedback?: boolean } } = {}): ChildPetView {
  const raw = makeLiveChildState({ training: makeEnabledTraining({ treat_token: opts.treat === true }), settings: opts.settings ?? {} });
  const base = normalizeChildState(raw, 0, Date.parse('2026-10-04T12:00:00+02:00'));
  const media = normalizePetMedia(
    makeMedia({
      status: 'ready',
      reference_image_url: 'https://api.petprep.si/m/ref.png?v=1',
      poses: { sit: 'https://api.petprep.si/m/sit.png?v=1' },
      phases: {
        school_sit_attention_loop: CLIPS.att,
        school_sit_attention: URL('school_sit_attention'),
        school_sit_performing: CLIPS.perf,
        school_sit_success_loop: CLIPS.succ,
        school_sit_success: URL('school_sit_success'),
        school_sit_release: CLIPS.release,
      },
      phase_groups: ['school'],
      phase_seconds: { school_sit_performing: 3, school_sit_release: 2.5 },
    }),
  );
  return { ...base, pet: { ...base.pet, media } };
}

const startBody = (session: unknown) => ({ status: 'accepted', session, state: makeLiveChildState({ training: makeEnabledTraining({ can_start: false }) }) });

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

let t0 = 0;
/** Advance to `ms` on the session clock (it started at `t0`). */
async function advanceTo(ms: number) {
  await advance(t0 + ms - Date.now());
}

function render(view: ChildPetView) {
  const onClose = jest.fn();
  const utils = renderWithQuery(<TrainingOverlay view={view} onClose={onClose} reduceMotion={false} />);
  return { onClose, ...utils };
}

async function pressStart() {
  fireEvent.press(screen.getByTestId('training-start-sit'));
  await advance(0);
}

function player(url: string) {
  const found = mockVideoPlayers.find((p) => sourceUri(p) === url && !p.released);
  if (found === undefined) throw new Error(`no player for ${url}`);
  return found;
}

/** Every deck player rendered its first frame → the clock starts. */
async function deckReady() {
  for (let i = 0; i < 3; i += 1) fireEvent(screen.getByTestId(`${ID}-deck-player-${i}`), 'firstFrameRender');
  await advance(0);
  t0 = Date.now();
}

const frontUrl = () => {
  for (let i = 0; i < 3; i += 1) if (screen.queryByTestId(`${ID}-deck-player-${i}-front`)) return [CLIPS.att, CLIPS.perf, CLIPS.succ][i];
  return null;
};

describe('school v2 on the game layer', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    startTraining.mockReset();
    finishTraining.mockReset();
    resetMockVideoPlayers();
    resetFakeAudioPlayers();
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    jest.setSystemTime(new Date('2026-10-04T12:00:00+02:00'));
    resetSchoolGameV2ForTests();
  });

  afterEach(() => {
    setGameLayerAvailableForTests(false);
    resetSchoolGameV2ForTests();
    jest.useRealTimers();
  });

  it('capability fallback: without the game layer the start has no `rules` and the v1 screen plays', async () => {
    startTraining.mockResolvedValueOnce(startBody(makeSmoothTrainingSessionPayload()));
    render(schoolView({ treat: true }));
    await pressStart();
    expect(startTraining).toHaveBeenCalledWith('sit');
    expect(startTraining.mock.calls[0]).toHaveLength(1);
    expect(screen.queryByTestId(ID)).toBeNull();
    expect(screen.getByTestId('training-praise')).toBeTruthy();
  });

  it('with the layer: rules 2, full screen, the deck gates the clock, chip + cue sound, treat → ✓, cut under the mask, »Končaj« → early result', async () => {
    setGameLayerAvailableForTests(true);
    const session = makeSchoolV2SessionPayload();
    startTraining.mockResolvedValueOnce(startBody(session));
    finishTraining.mockResolvedValueOnce({
      status: 'accepted',
      result: makeTrainingResult({
        trials: [
          { index: 0, obeys: true, outcome: 'in_time', tap_ms: 5_900 },
          { index: 1, obeys: false, outcome: 'waited', tap_ms: null },
          { index: 2, obeys: true, outcome: 'no_praise', tap_ms: null },
        ],
        rules_version: 2,
        ended_after_round: 1,
        counts_for_routine: false,
      }),
      state: makeLiveChildState({ training: makeEnabledTraining() }),
    });
    render(schoolView({ treat: true }));
    await pressStart();
    expect(startTraining).toHaveBeenCalledWith('sit', 2);
    expect(screen.getByTestId(ID)).toBeTruthy();
    // Preparing: three players (never the release clip), nothing plays, »getting ready«.
    expect(mockVideoPlayers.map(sourceUri).sort()).toEqual([CLIPS.att, CLIPS.perf, CLIPS.succ].sort());
    // The rule line is read while the deck loads (it never runs into the first cue).
    expect(screen.getByTestId(`${ID}-top-line`).props.children).toBe(SCHOOL_V2_STRINGS.lead);
    expect(screen.queryByTestId(`${ID}-token`)).toBeNull();
    await deckReady();
    expect(frontUrl()).toBe(CLIPS.att);
    expect(screen.getByTestId(`${ID}-token-treat`)).toBeTruthy();
    await advance(1_000);
    expect(screen.getByTestId(`${ID}-top-line`).props.children).toBe(SCHOOL_V2_STRINGS.lead);

    // Cue at 2 s: the big chip with 👂, the cue sound (and its haptic).
    await advanceTo(2_000);
    expect(screen.getByTestId(`${ID}-cue`)).toBeTruthy();
    expect(screen.getByTestId(`${ID}-ear`)).toBeTruthy();
    expect(Haptics.selectionAsync).toHaveBeenCalled();
    const players = fakeAudioPlayers();
    const cuePlayer = players[players.length - GAME_SOUNDS.length + GAME_SOUNDS.indexOf('cue')];
    expect(cuePlayer.play).toHaveBeenCalled();

    // Performing from frame 0 at perform_at (crossfade from the hub loop), no text.
    await advanceTo(2_500);
    expect(frontUrl()).toBe(CLIPS.perf);
    expect(screen.getByTestId(`${ID}-top-line`).props.children).toBe('');
    // The clip ends at obey_at → success loop (same pose, instant); the chip is gone = »now«.
    act(() => player(CLIPS.perf).emit('playToEnd', {}));
    await advanceTo(5_500);
    expect(frontUrl()).toBe(CLIPS.succ);
    expect(screen.queryByTestId(`${ID}-cue`)).toBeNull();

    // Treat in time → ✓ »Bravo!«, success haptic, the treat flies.
    await advanceTo(5_900);
    fireEvent(screen.getByTestId(`${ID}-token`), 'pressIn', { nativeEvent: {} });
    fireEvent.press(screen.getByTestId(`${ID}-token`));
    await advance(0);
    expect(screen.getByTestId(`${ID}-feedback-ok`)).toBeTruthy();
    expect(Haptics.notificationAsync).toHaveBeenCalled();

    // release_at (7 s): the stage stays in the pose until the mask is up, then cuts to standing.
    await advanceTo(7_000);
    expect(frontUrl()).toBe(CLIPS.succ);
    expect(screen.getByTestId(`${ID}-feedback-mask`)).toBeTruthy();
    await advanceTo(7_200);
    expect(frontUrl()).toBe(CLIPS.att);
    expect(mockVideoPlayers.some((p) => sourceUri(p) === CLIPS.release)).toBe(false);

    // Ignored cue 2: »Ne uboga — počakaj.« with the distraction icon.
    await advanceTo(10_600);
    expect(screen.getByTestId(`${ID}-ignoring`)).toBeTruthy();
    await advance(2_000);
    expect(screen.getByTestId(`${ID}-top-line`).props.children).toBe(SCHOOL_V2_STRINGS.ignores);

    // Break after round 1: the token goes away, »Končaj« appears.
    await advanceTo(20_400);
    expect(screen.queryByTestId(`${ID}-token`)).toBeNull();
    fireEvent.press(screen.getByTestId(`${ID}-finish-early`));
    await advance(0);
    expect(finishTraining).toHaveBeenCalledWith(session.id, [5_900], 1);
    expect(screen.getByTestId('training-result-round-1')).toBeTruthy();
    expect(screen.queryByTestId('training-result-round-2')).toBeNull();
    expect(screen.getByTestId('training-result-routine').props.children).toBe(SCHOOL_V2_STRINGS.result.earlyEnd);
  });

  it('»Pohvali« ♥ without bought treats; no sound / haptic when the parent switched them off', async () => {
    setGameLayerAvailableForTests(true);
    startTraining.mockResolvedValueOnce(startBody(makeSchoolV2SessionPayload()));
    render(schoolView({ treat: false, settings: { game_feedback: false } }));
    await pressStart();
    await deckReady();
    expect(screen.getByTestId(`${ID}-token-heart`)).toBeTruthy();
    expect(screen.queryByTestId(`${ID}-token-treat`)).toBeNull();
    await advanceTo(5_900);
    fireEvent(screen.getByTestId(`${ID}-token`), 'pressIn', { nativeEvent: {} });
    await advance(0);
    expect(screen.getByTestId(`${ID}-feedback-ok`)).toBeTruthy();
    expect(fakeAudioPlayers()).toHaveLength(0);
    expect(Haptics.selectionAsync).not.toHaveBeenCalled();
    expect(Haptics.notificationAsync).not.toHaveBeenCalled();
  });

  it('picture mode: no player is created and the game starts at once', async () => {
    setGameLayerAvailableForTests(true);
    startTraining.mockResolvedValueOnce(startBody(makeSchoolV2SessionPayload()));
    render(schoolView({ settings: { media_display: 'picture' } }));
    await pressStart();
    await advance(0);
    expect(mockVideoPlayers).toHaveLength(0);
    expect(screen.getByTestId(`${ID}-token`)).toBeTruthy();
  });
});
