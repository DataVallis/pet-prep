/**
 * GAMES_SPEC phase 3 — school v2 end to end through `TrainingOverlay` (the HUD's entry): the
 * capability fallback (no game layer → `rules` never sent, v1 screen), `rules: 2` + full-screen
 * game with the layer, the deck gating the clock, the big command chip + cue sound, the treat token
 * (only with `treat_token`, else »Pohvali« ♥), the instant ✓, the cut back to standing only under
 * the mask (the release clip is never mounted), »Končaj« in the break → `ended_after_round: 1` and
 * the per-cue result, the parent's sound switch and picture mode.
 *
 * David's device test 2026-10-31 (clarity): the rule strip for the whole session (never cut), the
 * praise hint »Zdaj!« in every session (any progress, David 31. 10.), the dimmed »wait« token while the dog ignores (still counts),
 * »Nadaljuj« + countdown in the break with »Končaj« as a small secondary, and the positive result.
 */
import { act, fireEvent, render as rtlRender, screen } from '@testing-library/react-native';
import { StyleSheet } from 'react-native';
import * as Haptics from 'expo-haptics';

import { api } from '@/api/client';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { setGameLayerAvailableForTests } from '@/modules/gameLayer/capability';
import { GAME_SOUNDS } from '@/modules/gameLayer/gameFxCore';
import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import SchoolResultV2 from '@/modules/training/schoolV2/SchoolResultV2';
import { ruleText } from '@/modules/training/schoolV2/SchoolRuleStrip';
import TrainingOverlay, { resetSchoolGameV2ForTests } from '@/modules/training/TrainingOverlay';
import { readTrainingResult, SCHOOL_V2_STRINGS, type TrainingResult } from '@/modules/training/training';
import {
  makeEnabledTraining,
  makeTrainingCommands,
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

function schoolView(
  opts: { treat?: boolean; sitProgress?: number; settings?: { media_display?: 'video' | 'picture'; game_feedback?: boolean } } = {},
): ChildPetView {
  const training = makeEnabledTraining({ treat_token: opts.treat === true, commands: makeTrainingCommands({ sit: opts.sitProgress ?? 40, come: 100 }) });
  const raw = makeLiveChildState({ training, settings: opts.settings ?? {} });
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
    // The rule strip is up while the deck loads (treat wording); the game line stays free.
    expect(screen.getByTestId(`${ID}-rule-text`).props.children).toBe(ruleText('treat'));
    expect(screen.getByTestId(`${ID}-top-line`).props.children).toBe('');
    expect(screen.queryByTestId(`${ID}-token`)).toBeNull();
    await deckReady();
    expect(frontUrl()).toBe(CLIPS.att);
    expect(screen.getByTestId(`${ID}-token-treat`)).toBeTruthy();
    await advance(1_000);
    expect(screen.getByTestId(`${ID}-rule-text`).props.children).toBe(ruleText('treat'));

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

    // Ignored cue 2: the readable »don't praise, wait« chip with what the dog does (no emoji).
    await advanceTo(10_600);
    expect(screen.getByTestId(`${ID}-ignoring-text`).props.children).toBe(SCHOOL_V2_STRINGS.ignores);
    expect(screen.getByTestId(`${ID}-ignoring-caption`).props.children).toBe(SCHOOL_V2_STRINGS.ignoring.sit);
    await advance(2_000);
    expect(screen.getByTestId(`${ID}-ignoring`)).toBeTruthy();

    // Break after round 1: the token goes away, »Nadaljuj« is primary, »Končaj« a small secondary.
    await advanceTo(20_400);
    expect(screen.queryByTestId(`${ID}-token`)).toBeNull();
    expect(screen.getByTestId(`${ID}-continue`)).toBeTruthy();
    fireEvent.press(screen.getByTestId(`${ID}-finish-early`));
    await advance(0);
    expect(finishTraining).toHaveBeenCalledWith(session.id, [5_900], 1);
    expect(screen.getByTestId('training-result-round-1')).toBeTruthy();
    expect(screen.queryByTestId('training-result-round-2')).toBeNull();
    expect(screen.getByTestId('training-result-routine').props.children).toBe(SCHOOL_V2_STRINGS.result.earlyEnd);
    // in_time + no_praise → »Dober začetek!«, 6 practices left (300 s / 50 s) → never »tomorrow«.
    expect(screen.getByTestId('training-result-title').props.children).toBe(SCHOOL_V2_STRINGS.result.titlePartial);
    expect(screen.getByTestId('training-result-next').props.children).toBe(SCHOOL_V2_STRINGS.result.tryAgainLeft(6));
    // The ignored cue the child waited for is a green ✓ »Pravilno počakal(a)«.
    expect(screen.getByTestId('training-result-trial-1-ok')).toBeTruthy();
    expect(screen.getByTestId('training-result-trial-1-label').props.children).toBe(SCHOOL_V2_STRINGS.result.outcome.waited);
  });

  it('the rule strip stays the whole session and is never truncated (no numberOfLines anywhere at the top)', async () => {
    setGameLayerAvailableForTests(true);
    startTraining.mockResolvedValueOnce(startBody(makeSchoolV2SessionPayload()));
    render(schoolView());
    await pressStart();
    const check = () => {
      const strip = screen.getByTestId(`${ID}-rule-text`);
      expect(strip.props.children).toBe(ruleText('heart'));
      expect(strip.props.numberOfLines).toBeUndefined();
      expect(screen.getByTestId(`${ID}-top-line`).props.numberOfLines).toBeUndefined();
    };
    check(); // preparing (deck loading)
    await deckReady();
    // lead-in, listening, performing, window, mask, calm, ignored cue, ignoring, break, round 2, the end
    for (const ms of [500, 2_000, 2_600, 5_600, 7_100, 8_300, 9_000, 10_600, 20_400, 23_000, 41_000]) {
      await advanceTo(ms);
      check();
    }
  });

  it('praise hint: the token glows + »Zdaj!« in the praise window only, and the screen reader hears it', async () => {
    setGameLayerAvailableForTests(true);
    startTraining.mockResolvedValueOnce(startBody(makeSchoolV2SessionPayload()));
    render(schoolView({ sitProgress: 49 }));
    await pressStart();
    await deckReady();
    await advanceTo(3_000);
    expect(screen.queryByTestId(`${ID}-hint`, { includeHiddenElements: true })).toBeNull();
    expect(screen.queryByTestId(`${ID}-token-glow`)).toBeNull();
    await advanceTo(5_600);
    expect(screen.getByTestId(`${ID}-hint`, { includeHiddenElements: true })).toBeTruthy();
    expect(screen.getByTestId(`${ID}-token-glow`)).toBeTruthy();
    expect(screen.getByTestId(`${ID}-token-pulse`)).toBeTruthy();
    expect(screen.getByTestId(`${ID}-announce`).props.children).toBe(SCHOOL_V2_STRINGS.hintA11y);
    await advanceTo(7_100);
    expect(screen.queryByTestId(`${ID}-hint`, { includeHiddenElements: true })).toBeNull();
  });

  it('praise hint with reduce motion: the static highlight, no pulse', async () => {
    setGameLayerAvailableForTests(true);
    startTraining.mockResolvedValueOnce(startBody(makeSchoolV2SessionPayload()));
    renderWithQuery(<TrainingOverlay view={schoolView({ sitProgress: 0 })} onClose={jest.fn()} reduceMotion />);
    await pressStart();
    await deckReady();
    await advanceTo(5_600);
    expect(screen.getByTestId(`${ID}-token-glow`)).toBeTruthy();
    expect(screen.queryByTestId(`${ID}-token-pulse`)).toBeNull();
  });

  it('the hint shows at 80 % progress too (David 31. 10.: every session, any progress)', async () => {
    setGameLayerAvailableForTests(true);
    startTraining.mockResolvedValueOnce(startBody(makeSchoolV2SessionPayload()));
    render(schoolView({ sitProgress: 80 }));
    await pressStart();
    await deckReady();
    await advanceTo(5_600);
    expect(screen.getByTestId(`${ID}-hint`, { includeHiddenElements: true })).toBeTruthy();
    expect(screen.getByTestId(`${ID}-token-glow`)).toBeTruthy();
    expect(screen.getByTestId(`${ID}-announce`).props.children).toBe(SCHOOL_V2_STRINGS.hintA11y);
    await advanceTo(7_100);
    expect(screen.queryByTestId(`${ID}-hint`, { includeHiddenElements: true })).toBeNull();
  });

  it('while the dog ignores: the token is dimmed with »Počakaj«, but a tap still counts (as praise without obeying)', async () => {
    setGameLayerAvailableForTests(true);
    const session = makeSchoolV2SessionPayload();
    startTraining.mockResolvedValueOnce(startBody(session));
    finishTraining.mockResolvedValueOnce({ status: 'accepted', result: makeTrainingResult({ rules_version: 2 }), state: makeLiveChildState({ training: makeEnabledTraining() }) });
    render(schoolView());
    await pressStart();
    await deckReady();
    await advanceTo(9_200); // ignoredCue: the dog may still be thinking → not dimmed yet
    expect(screen.queryByTestId(`${ID}-token-wait`)).toBeNull();
    await advanceTo(10_600);
    expect(screen.getByTestId(`${ID}-token-wait`).props.children).toBe(SCHOOL_V2_STRINGS.wait);
    const style = StyleSheet.flatten(screen.getByTestId(`${ID}-token`).props.style);
    expect(style.opacity).toBeLessThan(0.5);
    fireEvent(screen.getByTestId(`${ID}-token`), 'pressIn', { nativeEvent: {} });
    await advance(0);
    expect(screen.getByTestId(`${ID}-feedback-miss`)).toBeTruthy();
    await advanceTo(13_500);
    expect(screen.queryByTestId(`${ID}-token-wait`)).toBeNull();
    await advanceTo(41_200);
    await advance(0);
    expect(finishTraining.mock.calls[0][1]).toEqual([10_600]);
  });

  // ── Tap OR drag + the tap hint (David's device feedback 2026-10-31: he tried to drag the treat).
  const finishBody = () => ({ status: 'accepted', result: makeTrainingResult({ rules_version: 2 }), state: makeLiveChildState({ training: makeEnabledTraining() }) });
  const flights = (n: number) => screen.queryByTestId(`${ID}-flights-${n}`, { includeHiddenElements: true });
  const surface = () => screen.getByTestId(`${ID}-token-drag-surface`);
  const touch = (x: number, y: number) => ({ nativeEvent: { pageX: x, pageY: y, timestamp: null } });

  it('a tap counts at its press-in, not at the release; the treat flies on the release', async () => {
    setGameLayerAvailableForTests(true);
    startTraining.mockResolvedValueOnce(startBody(makeSchoolV2SessionPayload()));
    finishTraining.mockResolvedValueOnce(finishBody());
    render(schoolView());
    await pressStart();
    await deckReady();
    await advanceTo(5_900);
    fireEvent(screen.getByTestId(`${ID}-token`), 'pressIn', { nativeEvent: {} });
    await advance(0);
    expect(screen.getByTestId(`${ID}-feedback-ok`)).toBeTruthy();
    expect(flights(0)).toBeTruthy(); // still held: nothing flies yet
    await advanceTo(6_400);
    fireEvent(screen.getByTestId(`${ID}-token`), 'pressOut');
    fireEvent.press(screen.getByTestId(`${ID}-token`));
    await advance(0);
    expect(flights(1)).toBeTruthy();
    await advanceTo(41_200);
    await advance(0);
    expect(finishTraining.mock.calls[0][1]).toEqual([5_900]);
  });

  it('drag then release (treat): counted once, at the press-in; the token follows the finger and the treat flies on release', async () => {
    setGameLayerAvailableForTests(true);
    startTraining.mockResolvedValueOnce(startBody(makeSchoolV2SessionPayload()));
    finishTraining.mockResolvedValueOnce(finishBody());
    render(schoolView({ treat: true }));
    await pressStart();
    await deckReady();
    await advanceTo(5_900);
    fireEvent(surface(), 'touchStart', touch(200, 700));
    fireEvent(screen.getByTestId(`${ID}-token`), 'pressIn', { nativeEvent: {} });
    await advance(0);
    expect(screen.getByTestId(`${ID}-feedback-ok`)).toBeTruthy();
    // A tiny wobble is not a drag; 8 pt is.
    expect(surface().props.onMoveShouldSetResponderCapture(touch(203, 697))).toBe(false);
    await advanceTo(6_100);
    let captured = false;
    act(() => {
      captured = surface().props.onMoveShouldSetResponderCapture(touch(200, 650));
    });
    expect(captured).toBe(true);
    // The Pressable is cancelled (press-out, no press): that is not a release — nothing flies.
    fireEvent(screen.getByTestId(`${ID}-token`), 'pressOut');
    fireEvent(surface(), 'responderMove', touch(190, 500));
    await advance(0);
    expect(flights(0)).toBeTruthy();
    await advanceTo(6_600);
    fireEvent(surface(), 'responderRelease', touch(180, 420));
    await advance(0);
    expect(flights(1)).toBeTruthy();
    // A second drag in the same cue changes nothing (first tap decides; a drag never taps).
    fireEvent(surface(), 'touchStart', touch(200, 700));
    act(() => {
      surface().props.onMoveShouldSetResponderCapture(touch(200, 600));
    });
    fireEvent(surface(), 'responderRelease', touch(200, 500));
    await advance(0);
    expect(flights(1)).toBeTruthy();
    await advanceTo(41_200);
    await advance(0);
    expect(finishTraining.mock.calls[0][1]).toEqual([5_900]);
  });

  it('drag works for »Pohvali« ♥ too: counted once at the press-in, the heart flies on release', async () => {
    setGameLayerAvailableForTests(true);
    startTraining.mockResolvedValueOnce(startBody(makeSchoolV2SessionPayload()));
    finishTraining.mockResolvedValueOnce(finishBody());
    render(schoolView());
    await pressStart();
    await deckReady();
    await advanceTo(5_900);
    fireEvent(surface(), 'touchStart', touch(200, 700));
    fireEvent(screen.getByTestId(`${ID}-token`), 'pressIn', { nativeEvent: {} });
    act(() => {
      surface().props.onMoveShouldSetResponderCapture(touch(200, 600));
    });
    fireEvent(screen.getByTestId(`${ID}-token`), 'pressOut');
    fireEvent(surface(), 'responderRelease', touch(200, 450));
    await advance(0);
    expect(flights(1)).toBeTruthy();
    expect(screen.getByTestId(`${ID}-token-heart`)).toBeTruthy();
    await advanceTo(41_200);
    await advance(0);
    expect(finishTraining.mock.calls[0][1]).toEqual([5_900]);
  });

  it('tap hint: the bobbing finger + »Tapni« before the first counted tap, gone after it (and stays gone)', async () => {
    setGameLayerAvailableForTests(true);
    startTraining.mockResolvedValueOnce(startBody(makeSchoolV2SessionPayload()));
    render(schoolView({ sitProgress: 40 }));
    await pressStart();
    await deckReady();
    await advanceTo(1_000);
    expect(screen.getByTestId(`${ID}-tap-hint-text`, { includeHiddenElements: true }).props.children).toBe(SCHOOL_V2_STRINGS.tapHint);
    expect(screen.getByTestId(`${ID}-tap-hint-anim`, { includeHiddenElements: true })).toBeTruthy();
    // Hidden from the screen reader — the token's own label says what to do.
    expect(screen.queryByTestId(`${ID}-tap-hint`)).toBeNull();
    await advanceTo(5_900);
    expect(screen.getByTestId(`${ID}-tap-hint`, { includeHiddenElements: true })).toBeTruthy();
    fireEvent(screen.getByTestId(`${ID}-token`), 'pressIn', { nativeEvent: {} });
    await advance(0);
    expect(screen.queryByTestId(`${ID}-tap-hint`, { includeHiddenElements: true })).toBeNull();
    await advanceTo(23_000); // round 2
    expect(screen.getByTestId(`${ID}-token`)).toBeTruthy();
    expect(screen.queryByTestId(`${ID}-tap-hint`, { includeHiddenElements: true })).toBeNull();
  });

  it('tap hint with reduce motion = a static label; shown at 80 % progress too', async () => {
    setGameLayerAvailableForTests(true);
    startTraining.mockResolvedValueOnce(startBody(makeSchoolV2SessionPayload()));
    const first = renderWithQuery(<TrainingOverlay view={schoolView({ sitProgress: 0 })} onClose={jest.fn()} reduceMotion />);
    await pressStart();
    await deckReady();
    await advanceTo(1_000);
    expect(screen.getByTestId(`${ID}-tap-hint-text`, { includeHiddenElements: true })).toBeTruthy();
    expect(screen.queryByTestId(`${ID}-tap-hint-anim`, { includeHiddenElements: true })).toBeNull();
    first.unmount();

    resetSchoolGameV2ForTests();
    startTraining.mockResolvedValueOnce(startBody(makeSchoolV2SessionPayload()));
    render(schoolView({ sitProgress: 80 }));
    await pressStart();
    await deckReady();
    await advanceTo(1_000);
    expect(screen.getByTestId(`${ID}-token`)).toBeTruthy();
    expect(screen.getByTestId(`${ID}-tap-hint`, { includeHiddenElements: true })).toBeTruthy();
  });

  it('round break: »Nadaljuj« counts down to round 2 and continues exactly at its start; »Končaj« only while the server takes it', async () => {
    setGameLayerAvailableForTests(true);
    startTraining.mockResolvedValueOnce(startBody(makeSchoolV2SessionPayload()));
    render(schoolView());
    await pressStart();
    await deckReady();
    await advanceTo(20_350);
    expect(screen.getByTestId(`${ID}-countdown`).props.children).toBe(3);
    expect(screen.getByTestId(`${ID}-finish-early`)).toBeTruthy();
    await advanceTo(20_900);
    expect(screen.getByTestId(`${ID}-countdown`).props.children).toBe(2);
    // »Nadaljuj« = the child's choice: only the countdown stays, »Končaj« goes.
    fireEvent.press(screen.getByTestId(`${ID}-continue`));
    await advance(0);
    expect(screen.queryByTestId(`${ID}-finish-early`)).toBeNull();
    expect(screen.getByTestId(`${ID}-round-in`).props.children).toBe(SCHOOL_V2_STRINGS.roundIn(2, 2));
    await advanceTo(22_799);
    expect(screen.queryByTestId(`${ID}-token`)).toBeNull();
    await advanceTo(22_800);
    await advance(0);
    expect(screen.queryByTestId(`${ID}-break`)).toBeNull();
    expect(screen.getByTestId(`${ID}-token`)).toBeTruthy();
  });

  it('untouched, the break auto-continues: from the round 2 start no »Nadaljuj« / »Končaj« (the server gate itself: earlyEndOpen tests)', async () => {
    setGameLayerAvailableForTests(true);
    startTraining.mockResolvedValueOnce(startBody(makeSchoolV2SessionPayload()));
    render(schoolView());
    await pressStart();
    await deckReady();
    await advanceTo(22_900);
    expect(screen.queryByTestId(`${ID}-finish-early`)).toBeNull();
    expect(screen.queryByTestId(`${ID}-continue`)).toBeNull();
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

describe('school v2 result wording', () => {
  const result = (outcomes: [string, boolean][]): TrainingResult =>
    readTrainingResult(
      makeTrainingResult({
        trials: outcomes.map(([outcome, obeys], index) => ({ index, obeys, outcome, tap_ms: outcome === 'no_praise' || outcome === 'waited' ? null : 6_000 })),
        progress_before: 40,
        progress_after: 40,
        progress_gain: 0,
        rules_version: 2,
      }),
    ) as TrainingResult;
  const NONE: [string, boolean][] = [
    ['no_praise', true],
    ['waited', false],
    ['too_early', true],
    ['too_early', true],
    ['praised_without_obeying', false],
    ['no_praise', true],
  ];
  const renderResult = (r: TrainingResult, practicesLeft: number) =>
    rtlRender(<SchoolResultV2 result={r} status="accepted" canStartAgain={practicesLeft > 0} practicesLeft={practicesLeft} onAgain={jest.fn()} onDone={jest.fn()} />);
  const allText = () => screen.root.findAll((n) => typeof n.props.children === 'string').map((n) => n.props.children as string).join(' ');

  it('practices remain today → »Vaja dela mojstra« + »Poskusi še enkrat — danes imaš še 4 vaje.«, never »tomorrow«', () => {
    renderResult(result(NONE), 4);
    expect(screen.getByTestId('training-result-title').props.children).toBe(SCHOOL_V2_STRINGS.result.titleNone);
    expect(screen.getByTestId('training-result-next').props.children).toBe(SCHOOL_V2_STRINGS.result.tryAgainLeft(4));
    expect(allText()).not.toMatch(/jutri|tomorrow/i);
    // The tip points at the most common mistake (too early ×2).
    expect(screen.getByTestId('training-result-tip').props.children).toBe(SCHOOL_V2_STRINGS.result.tip.early);
  });

  it('no practice left today → the calm »tomorrow« line', () => {
    renderResult(result(NONE), 0);
    expect(screen.getByTestId('training-result-next').props.children).toBe(SCHOOL_V2_STRINGS.result.noneLeft);
    expect(screen.queryByTestId('training-again')).toBeNull();
  });

  it('ignored + waited = green ✓ »Pravilno počakal(a)«; praise without obeying stays a soft amber ✗', () => {
    renderResult(result(NONE), 4);
    expect(screen.getByTestId('training-result-trial-1-ok')).toBeTruthy();
    expect(screen.getByTestId('training-result-trial-1-label').props.children).toBe(SCHOOL_V2_STRINGS.result.outcome.waited);
    expect(screen.getByTestId('training-result-trial-4-try')).toBeTruthy();
    expect(screen.getByTestId('training-result-trial-4-label').props.children).toBe(SCHOOL_V2_STRINGS.result.outcome.praised_without_obeying);
  });

  it('success → »Bravo!« with no try-again line and no tip', () => {
    renderResult(
      result([
        ['in_time', true],
        ['waited', false],
        ['in_time', true],
      ]),
      4,
    );
    expect(screen.getByTestId('training-result-title').props.children).toBe(SCHOOL_V2_STRINGS.result.titleSuccess);
    expect(screen.queryByTestId('training-result-next')).toBeNull();
    expect(screen.queryByTestId('training-result-tip')).toBeNull();
  });
});
