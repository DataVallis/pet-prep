/**
 * M5-R06-08a: reading the cat blocks / sessions / verdicts from `unknown` (never guessing a
 * schedule), times in the family timezone, and the child text for EVERY server refusal of
 * the cat endpoints in both languages (never empty, never a raw code, never shaming).
 */
import { i18n } from '@/i18n';
import {
  CAT_REFUSALS,
  EMPTY_CAT_CARE,
  catRefusalMessage,
  catWhen,
  onlyScratchingOpen,
  ownSession,
  readCatFinishResponse,
  readCatStartResponse,
  readChildCatCare,
  readChoreResult,
  readChoreSession,
  readScoopResponse,
  readScratchingResult,
  readScratchingSession,
  readWandResult,
  readWandSession,
} from '@/modules/catCare/catCare';
import { normalizeChildState } from '@/modules/childPet/childPetView';
import {
  makeChoreSession,
  makeGroomingState,
  makeLiveChildState,
  makeLitterState,
  makeScratchingSession,
  makeScratchingState,
  makeWandSession,
  makeWandState,
} from '@/test-utils/fixtures';

const TZ = 'Europe/Ljubljana';
const NOW = '2026-10-04T12:00:00+02:00'; // a Sunday

afterEach(async () => {
  await i18n.changeLanguage('sl');
});

describe('session readers', () => {
  it('reads a wand schedule (pounces sorted) and refuses a broken one', () => {
    const s = readWandSession(makeWandSession({ pounces_ms: [45_000, 15_000] }));
    expect(s).toMatchObject({ duration_ms: 60_000, catch_at_ms: 60_000, pounces_ms: [15_000, 45_000], min_away_moves: 8, segments: 4, pounce_window_ms: 2_000 });
    expect(readWandSession(makeWandSession({ pounces_ms: [70_000] }))).toBeNull();
    expect(readWandSession(makeWandSession({ duration_ms: 0 }))).toBeNull();
    expect(readWandSession(makeWandSession({ started_at: 'nope' }))).toBeNull();
    expect(readWandSession(makeWandSession({ pounces_ms: null }))).toBeNull();
    // The schema's mis-inferred training shape is not a wand session.
    expect(readWandSession({ id: 'x', command: 'sit', started_at: NOW, ends_at: NOW, expires_at: NOW, trials: [] })).toBeNull();
  });

  it('reads a chore session of the expected kind only', () => {
    expect(readChoreSession(makeChoreSession('grooming', { matted: true, duration_ms: 60_000, min_strokes: 20 }), 'grooming')).toMatchObject({
      kind: 'grooming',
      matted: true,
      duration_ms: 60_000,
      min_strokes: 20,
      segments: 3,
    });
    expect(readChoreSession(makeChoreSession('litter_change'), 'grooming')).toBeNull();
    expect(readChoreSession({ ...makeChoreSession('grooming'), kind: 'walk' })).toBeNull();
  });

  it('a scratching carry lasts landing + window', () => {
    expect(readScratchingSession(makeScratchingSession())).toMatchObject({ land_at_ms: 1_200, praise_window_ms: 3_000, duration_ms: 4_200, min_reaction_ms: 150 });
    expect(readScratchingSession(makeScratchingSession({ praise_window_ms: 0 }))).toBeNull();
    // A reaction floor that would close the window falls back to the server default.
    expect(readScratchingSession(makeScratchingSession({ min_reaction_ms: 5_000 }))?.min_reaction_ms).toBe(150);
  });

  it('reads verdicts; an unknown reason is kept as null, a success never carries one', () => {
    expect(readWandResult({ session_id: 's', success: false, reason: 'missed_pounces', away_moves: 9 })).toMatchObject({ reason: 'missed_pounces', away_moves: 9 });
    expect(readWandResult({ session_id: 's', success: false, reason: 'cheating' })?.reason).toBeNull();
    expect(readWandResult({ session_id: 's', success: true, reason: 'not_spread' })?.reason).toBeNull();
    expect(readWandResult({ success: true })).toBeNull();
    expect(readChoreResult({ session_id: 's', success: true, strokes: 14, matted: true })).toMatchObject({ strokes: 14, matted: true });
    expect(readScratchingResult({ session_id: 's', success: false, reason: 'too_late', praise_ms: 5_000, delay_ms: 3_800 })).toMatchObject({
      reason: 'too_late',
      delay_ms: 3_800,
    });
  });

  it('start / finish / scoop bodies need a status, the payload and a state', () => {
    const state = makeLiveChildState();
    expect(readCatStartResponse({ status: 'accepted', session: makeWandSession(), state }, readWandSession)?.session.id).toBe(makeWandSession().id);
    expect(readCatStartResponse({ status: 'accepted', session: { id: 'x' }, state }, readWandSession)).toBeNull();
    expect(readCatStartResponse({ status: 'accepted', session: makeWandSession() }, readWandSession)).toBeNull();
    const verdict = { session_id: 's', success: false, reason: 'too_few_strokes' };
    expect(readCatFinishResponse({ status: 'rejected', result: verdict, state }, readChoreResult)?.status).toBe('rejected');
    expect(readCatFinishResponse({ status: 'maybe', result: verdict, state }, readChoreResult)).toBeNull();
    expect(readScoopResponse({ status: 'accepted', scooped: 2, state })).toMatchObject({ status: 'accepted', scooped: 2 });
    expect(readScoopResponse({ status: 'unchanged', state })).toMatchObject({ scooped: 0 });
    expect(readScoopResponse({ status: 'accepted' })).toBeNull();
  });
});

describe('child state blocks', () => {
  it('a dog (no cat blocks) normalises to EMPTY_CAT_CARE — nothing changes for dogs', () => {
    const view = normalizeChildState(makeLiveChildState());
    expect(view.cat).toBe(EMPTY_CAT_CARE);
    expect(readChildCatCare({ wand: null, litter: null, grooming: null, scratching: null })).toBe(EMPTY_CAT_CARE);
  });

  it('reads all four blocks of a Maine Coon, incl. the own running sessions for resume', () => {
    const raw = makeLiveChildState({
      wand: makeWandState({ session: makeWandSession(), can_start: false, blocked_reason: 'wand_session_active' }),
      litter: makeLitterState({}, { session: makeChoreSession('litter_change') }),
      grooming: makeGroomingState({ matted: true, session_seconds: 60 }),
      scratching: makeScratchingState({ session: makeScratchingSession() }),
    });
    const cat = normalizeChildState(raw).cat;
    expect(cat.wand).toMatchObject({ goal: 2, can_start: false, blocked_reason: 'wand_session_active' });
    expect(ownSession(cat, 'wand')?.pounces_ms).toEqual([15_000, 30_000, 45_000]);
    expect(ownSession(cat, 'litter_change')?.kind).toBe('litter_change');
    expect(ownSession(cat, 'grooming')).toBeNull();
    expect(ownSession(cat, 'scratching')?.land_at_ms).toBe(1_200);
    expect(cat.grooming).toMatchObject({ matted: true, session_seconds: 60, done_this_week: 1 });
    expect(cat.litter?.open_uses).toHaveLength(1);
    expect(cat.scratching?.active?.id).toBe(21);
  });

  it('a domestic cat has no grooming; a malformed block is dropped, not guessed', () => {
    const cat = readChildCatCare({ wand: makeWandState(), litter: makeLitterState(), grooming: null, scratching: { active: { id: 'x' } } });
    expect(cat.grooming).toBeNull();
    expect(cat.scratching?.active).toBeNull();
    expect(readChildCatCare({ grooming: { goal_per_week: 3 } }).grooming).toBeNull();
  });

  it('needs_cleaning means "scratcher first" only while the scratched sofa is the only mess', () => {
    const scratchOnly = readChildCatCare({ scratching: makeScratchingState(), litter: makeLitterState() });
    expect(onlyScratchingOpen(scratchOnly)).toBe(true);
    const withLitterMess = readChildCatCare({
      scratching: makeScratchingState(),
      litter: makeLitterState({ open_uses: [{ id: 1, used_at: NOW, due_at: NOW, expired: true }] }),
    });
    expect(onlyScratchingOpen(withLitterMess)).toBe(false);
    expect(onlyScratchingOpen(readChildCatCare({ scratching: makeScratchingState({ active: null }) }))).toBe(false);
  });
});

describe('catWhen (family timezone)', () => {
  it('today / tomorrow / weekday — Slovenian', () => {
    expect(catWhen('2026-10-04T17:00:00+02:00', NOW, TZ)).toBe('ob 17:00');
    expect(catWhen('2026-10-05T06:00:00+02:00', NOW, TZ)).toBe('jutri ob 06:00');
    expect(catWhen('2026-10-07T09:30:00+02:00', NOW, TZ)).toBe('v sredo ob 09:30');
    // A UTC instant is shown in the family zone.
    expect(catWhen('2026-10-04T15:00:00Z', NOW, TZ)).toBe('ob 17:00');
    expect(catWhen(null, NOW, TZ)).toBeNull();
  });

  it('English', async () => {
    await i18n.changeLanguage('en');
    expect(catWhen('2026-10-04T17:00:00+02:00', NOW, TZ)).toBe('at 17:00');
    expect(catWhen('2026-10-05T06:00:00+02:00', NOW, TZ)).toBe('tomorrow at 06:00');
    expect(catWhen('2026-10-10T08:00:00+02:00', NOW, TZ)).toBe('on Saturday at 08:00');
  });
});

describe('refusal texts', () => {
  const ctx = { nowIso: NOW, timezone: TZ };
  const NEXT = '2026-10-04T16:20:00+02:00';

  it.each(['sl', 'en'])('every cat refusal has a real text (%s), with and without a time', async (lang) => {
    await i18n.changeLanguage(lang);
    const unknown = catRefusalMessage('nope', null, ctx);
    for (const reason of CAT_REFUSALS) {
      for (const next of [NEXT, null]) {
        const text = catRefusalMessage(reason, next, ctx);
        expect({ reason, text: text.length > 10 }).toEqual({ reason, text: true });
        expect(text).not.toContain('{{');
        expect(text).not.toContain('cat:');
        expect(text).not.toBe(unknown);
        // Never a scolding word.
        expect(text.toLowerCase()).not.toMatch(/bad|naughty|wrong of you|slab|poreden/);
      }
    }
  });

  it('names the family-local time when the server sends one (Slovenian, feminine "muca")', () => {
    expect(catRefusalMessage('wand_too_soon', NEXT, ctx)).toBe('Muca po zadnji igri počiva. Igrata se lahko spet ob 16:20.');
    expect(catRefusalMessage('wand_too_soon', null, ctx)).toBe('Muca po zadnji igri počiva. Igrata se lahko malo kasneje.');
    expect(catRefusalMessage('wand_quiet_hours', '2026-10-05T07:00:00+02:00', ctx)).toBe('Muca zdaj spi. Igrata se lahko spet jutri ob 07:00.');
    expect(catRefusalMessage('grooming_week_done', '2026-10-08T09:30:00+02:00', ctx)).toBe(
      'Vsa česanja ta teden so opravljena. Naslednje česanje v četrtek ob 09:30.',
    );
  });

  it('English', async () => {
    await i18n.changeLanguage('en');
    expect(catRefusalMessage('wand_session_active', '2026-10-04T12:02:00+02:00', ctx)).toBe('Someone else is playing with your kitty. Try again at 12:02.');
    expect(catRefusalMessage('litter_change_done', '2026-10-08T09:30:00+02:00', ctx)).toBe(
      'You already changed the litter this week. The next change is on Thursday at 09:30.',
    );
  });

  it('needs_cleaning: "scratcher first" while only the scratched sofa is open', () => {
    expect(catRefusalMessage('needs_cleaning', null, ctx)).toBe('Najprej počisti nered, potem lahko to narediš.');
    expect(catRefusalMessage('needs_cleaning', null, { ...ctx, scratchingOnly: true })).toBe('Najprej odnesi muco na praskalnik in jo pohvali.');
  });

  it('an unknown / missing code gets a calm fallback', () => {
    expect(catRefusalMessage(null, null, ctx)).toBe('Tega zdaj ni mogoče narediti. Poskusi kasneje.');
  });
});

describe('scratching scene (M5-R06-05 server scene, read since 08a)', () => {
  it('the cat\'s `scratching` scene is read, its video key is kept, the dog scenes are unchanged', () => {
    const { readChildBehaviour } = jest.requireActual<typeof import('@/modules/behaviour/behaviour')>('@/modules/behaviour/behaviour');
    const { normalizePetMedia, selectMediaSource } = jest.requireActual<typeof import('@/modules/petMedia/petMedia')>('@/modules/petMedia/petMedia');
    expect(readChildBehaviour({ scene: 'scratching', active_events: [] }).scene).toBe('scratching');
    expect(readChildBehaviour({ scene: 'chewing', active_events: [] }).scene).toBe('chewing');
    expect(readChildBehaviour({ scene: 'litter_accident', active_events: [] }).scene).toBeNull();
    const media = normalizePetMedia({ status: 'ready', videos: { idle: 'https://x/idle.mp4', scratching: 'https://x/scratch.mp4' }, states: ['idle', 'scratching'] }, {});
    expect(selectMediaSource(media, 'scratching')).toMatchObject({ kind: 'video', state: 'scratching', url: 'https://x/scratch.mp4' });
  });
});
