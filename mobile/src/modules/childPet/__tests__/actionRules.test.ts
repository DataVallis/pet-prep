/**
 * M5-T02 — the one rules table of the child HUD (APP_REFINEMENT_SPEC §8): server flags
 * are the truth; the table adds the sleeping reason + wake time and the lock reason, so
 * every disabled button can say why (»💤 Kuža spi do 7:00«).
 */
import { i18n, setTextSpecies } from '@/i18n';
import { actionRule, actionRules, disabledText, HUD_ACTIONS, sleepingText } from '@/modules/childPet/actionRules';
import { applyBroadcast, nextRefreshDelay, normalizeChildState, readSleep, type ChildPetView } from '@/modules/childPet/childPetView';
import { startBlock, trainingRefusalMessage } from '@/modules/training/training';
import {
  makeBroadcast,
  makeEnabledTraining,
  makeGroomingState,
  makeLiveChildState,
  makePlayState,
  makeSocialisation,
  makeWandState,
} from '@/test-utils/fixtures';

const WAKE = '2026-10-05T07:00:00+02:00';
const ASLEEP = { asleep: true, until: WAKE };

const view = (o: Parameters<typeof makeLiveChildState>[0] = {}): ChildPetView => normalizeChildState(makeLiveChildState(o));

afterEach(async () => {
  setTextSpecies(null);
  await i18n.changeLanguage('sl');
});

describe('sleep in the view', () => {
  it('reads `sleep`; an older server derives it from pet_state', () => {
    expect(view({ sleep: ASLEEP }).sleep).toEqual(ASLEEP);
    expect(view().sleep).toEqual({ asleep: false, until: null });
    expect(view({ sleep: 'absent', pet: { pet_state: 'sleeping' } }).sleep).toEqual({ asleep: true, until: null });
    expect(readSleep({ asleep: false, until: WAKE }, 'idle')).toEqual({ asleep: false, until: null });
    expect(readSleep({ asleep: true, until: 'nonsense' }, 'idle')).toEqual({ asleep: true, until: null });
  });

  it('a broadcast that falls asleep turns school off at once and refetches', () => {
    const awake = view({ training: makeEnabledTraining() });
    const out = applyBroadcast(awake, makeBroadcast({ pet_id: awake.pet.id, pet_state: 'sleeping', sleep: ASLEEP, emitted_at: '2026-10-04T19:00:01.000+00:00' }));
    expect(out?.view.sleep).toEqual(ASLEEP);
    expect(out?.view.training.can_start).toBe(false);
    expect(out?.view.training.blocked_reason).toBe('training_pet_sleeping');
    expect(out?.view.training.next_allowed_at).toBe(WAKE);
    expect(out?.refetch).toBe(true);
  });

  it('the wake time is a refresh boundary', () => {
    const v = view({ sleep: { asleep: true, until: '2026-10-04T12:30:00+02:00' } });
    const now = Date.parse('2026-10-04T12:00:00+02:00');
    expect(nextRefreshDelay({ ...v, clockSkewMs: 0 }, now)).toBeLessThanOrEqual(30 * 60_000);
  });
});

describe('actionRule — every HUD action', () => {
  it('covers each action; an action the pet lacks is just off (no reason)', () => {
    const rules = actionRules(view());
    expect(Object.keys(rules).sort()).toEqual([...HUD_ACTIONS].sort());
    // A dog without play / training / cat blocks / take-out / socialisation.
    for (const a of ['play', 'cuddle', 'training', 'grooming', 'wand', 'scoop', 'take_out', 'socialisation'] as const) {
      expect(rules[a]).toEqual({ enabled: false });
    }
    expect(rules.walk.enabled).toBe(true);
    expect(rules.water.enabled).toBe(true);
    // Outside a meal window: off for a server reason, with the next window's start.
    expect(rules.feed).toEqual({ enabled: false, reason: 'server', until: '2026-10-04T17:00:00+02:00' });
  });

  it('while the pet sleeps: play AND school off with »💤 Kuža spi do 7:00«; care stays as the server says', () => {
    const v = view({
      sleep: ASLEEP,
      pet: { pet_state: 'sleeping' },
      play: makePlayState({ can_play: false }),
      training: makeEnabledTraining({ can_start: false, blocked_reason: 'training_pet_sleeping', next_allowed_at: WAKE }),
    });
    const play = actionRule(v, 'play');
    const school = actionRule(v, 'training');
    expect(play).toEqual({ enabled: false, reason: 'sleeping', until: WAKE, text: '💤 Kuža spi do 7:00' });
    expect(school).toEqual({ enabled: false, reason: 'sleeping', until: WAKE, text: '💤 Kuža spi do 7:00' });
    expect(actionRule(v, 'cuddle').reason).toBe('sleeping');
    // Water / walk are not sleep rules (the server decides; the parent covers meals).
    expect(actionRule(v, 'water').enabled).toBe(true);
    expect(actionRule(v, 'walk').enabled).toBe(true);
    expect(startBlock(v.training)).toBe('sleeping');
  });

  it('an older server without `blocked_reason` still names the sleep', () => {
    const v = view({ sleep: ASLEEP, training: makeEnabledTraining({ can_start: false, blocked_reason: undefined, next_allowed_at: undefined }) });
    expect(actionRule(v, 'training').text).toBe('💤 Kuža spi do 7:00');
  });

  it('other training blocks are the server\'s (no sleep text)', () => {
    const v = view({ training: makeEnabledTraining({ can_start: false, blocked_reason: 'training_daily_budget_used', next_allowed_at: '2026-10-05T00:00:00+02:00', daily_budget_left_seconds: 0 }) });
    expect(actionRule(v, 'training')).toEqual({ enabled: false, reason: 'server', until: '2026-10-05T00:00:00+02:00' });
    expect(disabledText(actionRule(v, 'training'), 'fallback')).toBe('fallback');
  });

  it('a lock wins over everything, with its own text', () => {
    const v = view({
      sleep: ASLEEP,
      lock: { is_locked: true, reason: 'payment_required', until: null },
      play: makePlayState({ can_play: false }),
      training: makeEnabledTraining({ can_start: false }),
    });
    for (const a of ['feed', 'water', 'clean', 'walk', 'play', 'training'] as const) {
      expect(actionRule(v, a)).toMatchObject({ enabled: false, reason: 'locked', lock: 'payment_required', text: 'Igra počaka na starša' });
    }
    const ill = view({ lock: { is_locked: true, reason: 'ill', until: '2026-10-04T18:30:00+02:00' } });
    expect(actionRule(ill, 'feed')).toMatchObject({ reason: 'locked', lock: 'ill', until: '2026-10-04T18:30:00+02:00' });
    const contract = view({ lock: { is_locked: true, reason: 'contract_required', until: null } });
    expect(actionRule(contract, 'feed').text).toBe('Najprej podpiši pogodbo');
  });

  it('cat games and socialisation: their quiet-hours reasons read as sleep', () => {
    const cat = view({
      pet: { breed_type: 'domestic_cat', species: 'cat' },
      sleep: ASLEEP,
      wand: makeWandState({ can_start: false, blocked_reason: 'wand_quiet_hours', next_allowed_at: WAKE }),
      grooming: makeGroomingState({ can_start: false, blocked_reason: 'grooming_quiet_hours', next_allowed_at: WAKE }),
    });
    setTextSpecies('cat');
    expect(actionRule(cat, 'wand')).toEqual({ enabled: false, reason: 'sleeping', until: WAKE, text: '💤 Muca spi do 7:00' });
    expect(actionRule(cat, 'grooming').reason).toBe('sleeping');
    expect(actionRule(cat, 'walk')).toEqual({ enabled: false });

    setTextSpecies(null);
    const pup = view({ socialisation: makeSocialisation({ can_record: false, blocked_reason: 'socialisation_quiet_hours', next_allowed_at: WAKE }) });
    expect(actionRule(pup, 'socialisation').text).toBe('💤 Kuža spi do 7:00');
    const done = view({ socialisation: makeSocialisation({ can_record: false, blocked_reason: 'socialisation_week_done', next_allowed_at: null }) });
    expect(actionRule(done, 'socialisation')).toEqual({ enabled: false, reason: 'server', until: null });
  });
});

describe('M5-T02b — cuddling and brushing while the pet sleeps (David 2026-10-21)', () => {
  it('cuddle (dog + cat) is off with the sleep text and the wake time', () => {
    const dog = view({ sleep: ASLEEP, play: makePlayState({ can_play: false }) });
    expect(actionRule(dog, 'cuddle')).toEqual({ enabled: false, reason: 'sleeping', until: WAKE, text: '💤 Kuža spi do 7:00' });
    const cat = view({ pet: { breed_type: 'domestic_cat', species: 'cat' }, sleep: ASLEEP, play: makePlayState({ can_play: false }) });
    setTextSpecies('cat');
    expect(actionRule(cat, 'cuddle')).toEqual({ enabled: false, reason: 'sleeping', until: WAKE, text: '💤 Muca spi do 7:00' });
  });

  it('sleep wins over a mess for play / cuddle (the server refuses with play_pet_sleeping first)', () => {
    const v = view({ sleep: ASLEEP, pet: { hygiene_level: 0, needs_cleaning: true }, play: makePlayState({ can_play: false }) });
    expect(actionRule(v, 'cuddle').reason).toBe('sleeping');
  });

  it('brushing a dog: grooming_quiet_hours → »💤 Kuža spi do 7:00« with the server time', () => {
    const v = view({ sleep: ASLEEP, grooming: makeGroomingState({ can_start: false, blocked_reason: 'grooming_quiet_hours', next_allowed_at: WAKE }) });
    expect(actionRule(v, 'grooming')).toEqual({ enabled: false, reason: 'sleeping', until: WAKE, text: '💤 Kuža spi do 7:00' });
  });

  it('brushing a cat: »💤 Muca spi do 7:00«; EN keeps the 24 h clock without the leading zero', async () => {
    const cat = view({
      pet: { breed_type: 'maine_coon', species: 'cat' },
      sleep: ASLEEP,
      grooming: makeGroomingState({ can_start: false, blocked_reason: 'grooming_quiet_hours', next_allowed_at: WAKE }),
    });
    setTextSpecies('cat');
    expect(actionRule(cat, 'grooming').text).toBe('💤 Muca spi do 7:00');
    await i18n.changeLanguage('en');
    expect(actionRule(cat, 'grooming').text).toBe('💤 Your kitty is asleep until 7:00');
  });

  it('an older server that names another block while asleep: the sleep still explains it (wake time from `sleep`)', () => {
    const v = view({ sleep: ASLEEP, grooming: makeGroomingState({ can_start: false, blocked_reason: 'grooming_done_today', next_allowed_at: '2026-10-05T00:00:00+02:00' }) });
    expect(actionRule(v, 'grooming')).toEqual({ enabled: false, reason: 'sleeping', until: WAKE, text: '💤 Kuža spi do 7:00' });
  });

  it('awake: brushing blocks stay the server\'s; a running own session stays on; a lock wins', () => {
    const done = view({ grooming: makeGroomingState({ can_start: false, blocked_reason: 'grooming_done_today', next_allowed_at: '2026-10-05T00:00:00+02:00' }) });
    expect(actionRule(done, 'grooming')).toEqual({ enabled: false, reason: 'server', until: '2026-10-05T00:00:00+02:00' });
    expect(actionRule(view({ grooming: makeGroomingState() }), 'grooming')).toEqual({ enabled: true });
    const ill = view({ sleep: ASLEEP, lock: { is_locked: true, reason: 'ill', until: '2026-10-04T18:30:00+02:00' }, grooming: makeGroomingState({ can_start: false }) });
    expect(actionRule(ill, 'grooming').reason).toBe('locked');
  });
});

describe('texts (EN + SL)', () => {
  it('sleepingText names the family-local wake time, or none', async () => {
    expect(sleepingText(WAKE, 'Europe/Ljubljana')).toBe('💤 Kuža spi do 7:00');
    expect(sleepingText('2026-10-05T07:30:00-04:00', 'America/New_York')).toBe('💤 Kuža spi do 7:30');
    expect(sleepingText(null, 'Europe/Ljubljana')).toBe('💤 Kuža spi');
    await i18n.changeLanguage('en');
    expect(sleepingText(WAKE, 'Europe/Ljubljana')).toBe('💤 Your pup is asleep until 7:00');
  });

  it('the 422 training_pet_sleeping toast names the wake time', async () => {
    expect(trainingRefusalMessage('training_pet_sleeping', WAKE, 'Europe/Ljubljana')).toBe('Kuža spi do 7:00. Takrat spet v šolo!');
    expect(trainingRefusalMessage('training_pet_sleeping', null, 'Europe/Ljubljana')).toBe('Kuža spi. V šolo gresta, ko se zbudi.');
    await i18n.changeLanguage('en');
    expect(trainingRefusalMessage('training_pet_sleeping', WAKE, 'Europe/Ljubljana')).toBe('Your pup is asleep until 7:00. School then!');
  });
});

describe('M5-T04 — a game waits for all its clips (David 2026-10-20, spec §10 / §13)', () => {
  const preparingSl = 'Igra se še pripravlja';

  it('play / cuddle: media_ready false → off with »Igra se še pripravlja« (no time, no word about media)', async () => {
    const v = view({ play: makePlayState({ can_play: false, media_ready: false }) });
    for (const action of ['play', 'cuddle'] as const) {
      expect(actionRule(v, action)).toEqual({ enabled: false, reason: 'preparing', until: null, text: preparingSl });
    }
    expect(disabledText(actionRule(v, 'play'), 'ob 17:00')).toBe(preparingSl);
    await i18n.changeLanguage('en');
    expect(actionRule(v, 'play').text).toBe('The game is still getting ready');
    expect(actionRule(v, 'play').text).not.toMatch(/video|image|picture|AI/i);
  });

  it('sleep, a lock and a mess explain the block first (server order)', () => {
    const notReady = makePlayState({ can_play: false, media_ready: false });
    expect(actionRule(view({ sleep: ASLEEP, play: notReady }), 'play').reason).toBe('sleeping');
    expect(actionRule(view({ pet: { hygiene_level: 0, needs_cleaning: true }, play: notReady }), 'play').reason).toBe('server');
    const ill = view({ lock: { is_locked: true, reason: 'ill', until: '2026-10-04T18:30:00+02:00' }, play: notReady });
    expect(actionRule(ill, 'play').reason).toBe('locked');
  });

  it('an older server without media_ready keeps the game ready', () => {
    const raw = makePlayState();
    const { media_ready: _dropped, ...older } = raw;
    void _dropped;
    expect(view({ play: older }).play?.media_ready).toBe(true);
    expect(actionRule(view({ play: older }), 'play')).toEqual({ enabled: true });
  });

  it('the wand (cat): blocked_reason media_not_ready → preparing', () => {
    const cat = view({
      pet: { breed_type: 'domestic_cat', species: 'cat' },
      wand: makeWandState({ can_start: false, blocked_reason: 'media_not_ready', next_allowed_at: null }),
    });
    expect(actionRule(cat, 'wand')).toEqual({ enabled: false, reason: 'preparing', until: null, text: preparingSl });
  });

  it('turns on live: the PetUpdated of the last clip carries media_ready true / the wand unblocked', () => {
    const dog = view({ play: makePlayState({ can_play: false, media_ready: false }) });
    const out = applyBroadcast(dog, makeBroadcast({ pet_id: dog.pet.id, play: makePlayState(), emitted_at: '2026-10-04T19:00:01.000+00:00' }));
    expect(out?.view.play?.media_ready).toBe(true);
    expect(actionRule(out!.view, 'play')).toEqual({ enabled: true });

    const cat = view({
      pet: { breed_type: 'domestic_cat', species: 'cat' },
      wand: makeWandState({ can_start: false, blocked_reason: 'media_not_ready', next_allowed_at: null }),
    });
    const ready = applyBroadcast(cat, makeBroadcast({ pet_id: cat.pet.id, wand: makeWandState(), emitted_at: '2026-10-04T19:00:01.000+00:00' }));
    expect(actionRule(ready!.view, 'wand')).toEqual({ enabled: true });
  });
});
