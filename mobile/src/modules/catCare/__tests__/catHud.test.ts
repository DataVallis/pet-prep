/**
 * M5-R06-08b — the cat HUD logic: dock per species, the scoop / wand dock states, chips,
 * "Na praskalnik", first aid, resume; the `needs_cleaning` refusal mapping per species;
 * the cat blocks after a `PetUpdated`; the species-aware texts and the contract.
 */
import { applyBroadcast, normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import {
  catChips,
  dockKinds,
  isCatView,
  ownCatGame,
  scoopDock,
  showFirstAid,
  showScratcherButton,
  wandDock,
} from '@/modules/catCare/catHud';
import { broadcastCatCare, EMPTY_CAT_CARE, readChildCatCare } from '@/modules/catCare/catCare';
import { cleanFirstHint, cleanFirstKind, cleanHint, needsCleaningMessage, refusalMessage } from '@/modules/childPet/actionMessages';
import { contractBody } from '@/modules/contract/contractText';
import { i18n, setTextSpecies, speciesKey, t } from '@/i18n';
import {
  makeBehaviourEvent,
  makeBroadcast,
  makeChoreSession,
  makeGroomingState,
  makeLitterState,
  makeLiveChildState,
  makeScratchingSession,
  makeScratchingState,
  makeTakeOut,
  makeWandSession,
  makeWandState,
} from '@/test-utils/fixtures';

type Overrides = Parameters<typeof makeLiveChildState>[0];

function dogView(o: Overrides = {}): ChildPetView {
  return normalizeChildState(makeLiveChildState(o));
}

function catView(o: Overrides = {}): ChildPetView {
  return normalizeChildState(
    makeLiveChildState({
      wand: makeWandState(),
      litter: makeLitterState(),
      grooming: null,
      scratching: makeScratchingState({ active: null, blocked_reason: 'scratching_not_needed', can_start: false }),
      ...o,
      pet: { breed_type: 'domestic_cat', species: 'cat', ...o.pet },
    }),
  );
}

afterEach(() => setTextSpecies(null));

describe('dock composition per species', () => {
  it('dog: food, water, walk, clean — a puppy with a bladder clock adds "Pelji ven" (unchanged)', () => {
    expect(dockKinds(dogView())).toEqual(['feed', 'water', 'walk', 'clean']);
    expect(dockKinds(dogView({ behaviour: { take_out: makeTakeOut() } }))).toEqual(['feed', 'water', 'take_out', 'walk', 'clean']);
    expect(isCatView(dogView())).toBe(false);
  });

  it('cat: food, water, "Pesek", "Igra", clean — never a walk', () => {
    expect(dockKinds(catView())).toEqual(['feed', 'water', 'scoop', 'wand', 'clean']);
    expect(isCatView(catView())).toBe(true);
  });

  it('a cat from an older server without the blocks: food, water, clean', () => {
    const view = normalizeChildState(makeLiveChildState({ pet: { breed_type: 'domestic_cat', species: 'cat' } }));
    expect(view.cat).toBe(EMPTY_CAT_CARE);
    expect(dockKinds(view)).toEqual(['feed', 'water', 'clean']);
  });
});

describe('"Pesek" (scoop)', () => {
  it('a waiting use: enabled, due, the deadline as hint', () => {
    expect(scoopDock(catView())).toEqual({ disabled: false, due: true, hint: { day: null, text: 'ob 15:00', a11y: 'ob 15:00' } });
  });

  it('a clean tray: disabled, "Čist"', () => {
    const view = catView({ litter: makeLitterState({ open_uses: [], next_due_at: null, can_scoop: false }) });
    expect(scoopDock(view)).toMatchObject({ disabled: true, due: false, hint: { text: 'Čist' } });
  });

  it('locked: disabled without a hint', () => {
    const view = catView({ lock: { is_locked: true, reason: 'hard_stopped' } });
    expect(scoopDock(view)).toEqual({ disabled: true, due: false, hint: null });
  });
});

describe('"Igra" (feather wand)', () => {
  it('counts today\'s games', () => {
    expect(wandDock(catView())).toMatchObject({ disabled: false, hint: { text: '0/2' } });
  });

  it('goal reached: "Opravljeno ✓"', () => {
    expect(wandDock(catView({ wand: makeWandState({ sessions_today: 2 }) })).hint?.text).toBe('Opravljeno ✓');
  });

  it('a timed block names its end (gap, quiet hours, another child — QA 08a m5), tomorrow on two lines', () => {
    const gap = catView({ wand: makeWandState({ sessions_today: 1, can_start: false, blocked_reason: 'wand_too_soon', next_allowed_at: '2026-10-04T14:00:00+02:00' }) });
    expect(wandDock(gap)).toMatchObject({ disabled: false, hint: { text: 'ob 14:00' } });
    const night = catView({ wand: makeWandState({ can_start: false, blocked_reason: 'wand_quiet_hours', next_allowed_at: '2026-10-05T07:00:00+02:00' }) });
    expect(wandDock(night).hint).toEqual({ day: 'jutri', text: '07:00', a11y: 'jutri ob 07:00' });
  });

  it('quiet hours without a time (older server): "Spi"', () => {
    const view = catView({ wand: makeWandState({ can_start: false, blocked_reason: 'wand_quiet_hours', next_allowed_at: null }) });
    expect(wandDock(view).hint?.text).toBe('Spi');
  });

  it('not available: disabled', () => {
    expect(wandDock(catView({ wand: makeWandState({ goal: 0, can_start: false, blocked_reason: 'wand_not_available' }) })).disabled).toBe(true);
  });
});

describe('chips, scratcher, first aid, resume', () => {
  it('a domestic cat: only the weekly litter change while it is open', () => {
    expect(catChips(catView()).map((c) => c.kind)).toEqual(['litter_change']);
    expect(catChips(catView({ litter: makeLitterState({}, { done: true, can_start: false, blocked_reason: 'litter_change_done' }) }))).toEqual([]);
  });

  it('Maine Coon: "Počeši" with the week and the matted note; overdue tray note', () => {
    const chips = catChips(
      catView({ pet: { breed_type: 'maine_coon' }, grooming: makeGroomingState({ matted: true }), litter: makeLitterState({}, { overdue: true }) }),
    );
    expect(chips.map((c) => [c.kind, c.pending, c.note])).toEqual([
      ['grooming', true, 'Dlaka ima vozel — počeši jo.'],
      ['litter_change', true, 'Pesek smrdi — zamenjaj ves pesek.'],
    ]);
    expect(chips[0].a11y).toBe('Počeši muco. Ta teden: 1 od 3.');
  });

  it('this week\'s brushing done → no chip; locked → no chips; a dog → none', () => {
    expect(catChips(catView({ grooming: makeGroomingState({ done_this_week: 3, can_start: false }), litter: makeLitterState({}, null) }))).toEqual([]);
    expect(catChips(catView({ lock: { is_locked: true, reason: 'ill' } }))).toEqual([]);
    expect(catChips(dogView())).toEqual([]);
  });

  it('"Na praskalnik" only for a cat with an open scratching', () => {
    expect(showScratcherButton(catView())).toBe(false);
    expect(showScratcherButton(catView({ scratching: makeScratchingState() }))).toBe(true);
    expect(showScratcherButton(dogView({ behaviour: { active_events: [makeBehaviourEvent('chewing')] } }))).toBe(false);
  });

  it('first aid: a hungry cat (≤ 30 %), never a dog, never while locked', () => {
    expect(showFirstAid(catView({ pet: { hunger_level: 30 } }))).toBe(true);
    expect(showFirstAid(catView({ pet: { hunger_level: 31 } }))).toBe(false);
    expect(showFirstAid(catView({ pet: { hunger_level: 10 }, lock: { is_locked: true, reason: 'ill' } }))).toBe(false);
    expect(showFirstAid(dogView({ pet: { hunger_level: 10 } }))).toBe(false);
  });

  it('resumes the child\'s own running game (wand first, then grooming, litter change, scratching)', () => {
    expect(ownCatGame(catView())).toBeNull();
    expect(ownCatGame(catView({ wand: makeWandState({ session: makeWandSession() }) }))?.kind).toBe('wand');
    expect(ownCatGame(catView({ grooming: makeGroomingState({ session: makeChoreSession('grooming') }) }))?.kind).toBe('grooming');
    expect(ownCatGame(catView({ litter: makeLitterState({}, { session: makeChoreSession('litter_change') }) }))?.kind).toBe('litter_change');
    expect(ownCatGame(catView({ scratching: makeScratchingState({ session: makeScratchingSession() }) }))?.kind).toBe('scratching');
    expect(ownCatGame(dogView())).toBeNull();
  });
});

describe('needs_cleaning per species (the API message always says "Clean up the mess first.")', () => {
  const scratchingOnly = () =>
    catView({
      pet: { hygiene_level: 0, needs_cleaning: true },
      behaviour: { active_events: [makeBehaviourEvent('scratching', { id: 21 })], scene: 'scratching' },
      scratching: makeScratchingState(),
    });

  it('cat, only the scratched sofa: "first the scratcher" (toast, dock hint, clean hint)', () => {
    const view = scratchingOnly();
    expect(cleanFirstKind(view)).toBe('scratcher');
    expect(refusalMessage('needs_cleaning', null, view)).toBe('Najprej odnesi muco na praskalnik in jo pohvali.');
    expect(cleanFirstHint(view).text).toBe('Najprej praskalnik');
    expect(cleanHint(view)).toBe('Najprej praskalnik');
  });

  it('cat, a mess next to the tray (+ the sofa): clean first, with the cat\'s words', () => {
    setTextSpecies('cat');
    const view = catView({
      pet: { hygiene_level: 0, needs_cleaning: true },
      behaviour: { active_events: [makeBehaviourEvent('litter_accident', { id: 22 }), makeBehaviourEvent('scratching', { id: 21 })] },
      litter: makeLitterState({ open_uses: [{ id: 11, used_at: '2026-10-04T06:00:00+02:00', due_at: '2026-10-04T10:00:00+02:00', expired: true }] }),
      scratching: makeScratchingState(),
    });
    expect(cleanFirstKind(view)).toBe('clean');
    expect(needsCleaningMessage(view)).toBe('Najprej počisti za muco!');
    expect(cleanFirstHint(view).text).toBe('Najprej pospravi');
  });

  it('dog, only a chewed slipper: "tidy first"', () => {
    const view = dogView({ pet: { hygiene_level: 0, needs_cleaning: true }, behaviour: { active_events: [makeBehaviourEvent('chewing')] } });
    expect(cleanFirstKind(view)).toBe('tidy');
    expect(needsCleaningMessage(view)).toBe('Najprej pospravi copat in daj kužku igračo!');
    expect(cleanFirstHint(view).text).toBe('Najprej pospravi');
  });

  it('dog with poop (or no view): the usual text, unchanged', () => {
    const view = dogView({ pet: { hygiene_level: 0, needs_cleaning: true }, behaviour: { active_events: [makeBehaviourEvent('poop')] } });
    expect(needsCleaningMessage(view)).toBe('Najprej pospravi za kužkom!');
    expect(needsCleaningMessage(null)).toBe('Najprej pospravi za kužkom!');
    expect(refusalMessage('needs_cleaning', null, view)).toBe('Najprej pospravi za kužkom!');
  });
});

describe('broadcastCatCare — cat blocks after a PetUpdated', () => {
  const current = () => readChildCatCare({ wand: makeWandState({ my_sessions_today: 1, sessions_today: 1 }), litter: makeLitterState(), grooming: makeGroomingState(), scratching: makeScratchingState({ active: null, can_start: false, blocked_reason: 'scratching_not_needed' }) });

  it('takes the pet-level counters, deadlines and blocks; keeps the child\'s own count', () => {
    const next = broadcastCatCare(
      current(),
      {
        wand: makeWandState({ sessions_today: 2, my_sessions_today: null, can_start: false, blocked_reason: 'wand_too_soon', next_allowed_at: '2026-10-04T14:00:00+02:00' }),
        litter: makeLitterState({ open_uses: [], next_due_at: null, can_scoop: false }),
        grooming: makeGroomingState({ done_this_week: 2 }),
        scratching: makeScratchingState(),
      },
      false,
    );
    expect(next.wand).toMatchObject({ sessions_today: 2, my_sessions_today: 1, blocked_reason: 'wand_too_soon', next_allowed_at: '2026-10-04T14:00:00+02:00', can_start: false });
    expect(next.litter).toMatchObject({ open_uses: [], can_scoop: false });
    expect(next.grooming?.done_this_week).toBe(2);
    expect(next.scratching?.active?.id).toBe(21);
  });

  it('a new family day (lower count) resets the child\'s own count', () => {
    const next = broadcastCatCare(current(), { wand: makeWandState({ sessions_today: 0, my_sessions_today: null }) }, false);
    expect(next.wand?.my_sessions_today).toBe(0);
  });

  it('the child\'s own running game survives (the broadcast has no viewer and calls the pet busy)', () => {
    const mine = readChildCatCare({ wand: makeWandState({ session: makeWandSession(), session_running: true }) });
    const next = broadcastCatCare(mine, { wand: makeWandState({ session: null, session_running: true, can_start: false, blocked_reason: 'wand_session_active' }) }, false);
    expect(next.wand).toMatchObject({ can_start: true, blocked_reason: null });
    expect(next.wand?.session?.id).toBe(makeWandSession().id);
    // The game ended (session_running false): the session is gone.
    const ended = broadcastCatCare(mine, { wand: makeWandState({ session_running: false, sessions_today: 1 }) }, false);
    expect(ended.wand?.session).toBeNull();
  });

  it('a lock turns every start off; a missing key (older server) keeps the block; null drops it', () => {
    const locked = broadcastCatCare(current(), { wand: makeWandState(), litter: makeLitterState() }, true);
    expect(locked.wand?.can_start).toBe(false);
    expect(locked.litter?.can_scoop).toBe(false);
    expect(locked.litter?.change?.can_start).toBe(false);
    expect(locked.grooming?.can_start).toBe(false);
    const kept = broadcastCatCare(current(), {}, false);
    expect(kept.grooming?.done_this_week).toBe(1);
    const dropped = broadcastCatCare(current(), { wand: null, litter: null, grooming: null, scratching: null }, false);
    expect(dropped).toBe(EMPTY_CAT_CARE);
  });

  it('applyBroadcast updates view.cat; a dog\'s broadcast (null blocks) leaves EMPTY_CAT_CARE', () => {
    const cat = catView();
    const result = applyBroadcast(
      cat,
      makeBroadcast({ breed_type: 'domestic_cat', species: 'cat', emitted_at: '2026-10-04T10:00:10.000+00:00', wand: makeWandState({ sessions_today: 1, my_sessions_today: null }) }),
    );
    expect(result?.view.cat.wand?.sessions_today).toBe(1);
    // Litter key missing → kept.
    expect(result?.view.cat.litter?.open_uses).toHaveLength(1);

    const dog = dogView();
    const dogResult = applyBroadcast(dog, makeBroadcast({ emitted_at: '2026-10-04T10:00:10.000+00:00', wand: null, litter: null, grooming: null, scratching: null }));
    expect(dogResult?.view.cat).toBe(EMPTY_CAT_CARE);
  });
});

describe('species texts (T8) and the contract', () => {
  it('a cat override wins only while the child app shows a cat', () => {
    expect(t('child:actions.success.feed')).toBe('Njam! Kuža je sit.');
    setTextSpecies('cat');
    expect(t('child:actions.success.feed')).toBe('Njam! Muca je sita.');
    expect(t('child:hud.metrics.energy')).toBe('Igra');
    expect(t('play:mood.happy')).toBe('Muca je vesela');
    // Species-neutral keys without an override stay.
    expect(t('child:hud.water')).toBe('Voda');
    // Parent namespaces are never overridden.
    expect(speciesKey('family:activities.fed_pet')).toBe('family:activities.fed_pet');
    setTextSpecies('dog');
    expect(t('child:actions.success.feed')).toBe('Njam! Kuža je sit.');
  });

  it('every override exists in the base namespace (no stray keys) — EN', () => {
    const flatten = (tree: Record<string, unknown>, prefix = ''): string[] =>
      Object.entries(tree).flatMap(([k, v]) => (typeof v === 'string' ? [`${prefix}${k}`] : flatten(v as Record<string, unknown>, `${prefix}${k}.`)));
    const overrides = i18n.getResourceBundle('en', 'cat').override as Record<string, Record<string, unknown>>;
    for (const [ns, tree] of Object.entries(overrides)) {
      for (const key of flatten(tree)) expect({ key: `${ns}:${key}`, exists: i18n.exists(`${ns}:${key}`, { lng: 'en' }) }).toEqual({ key: `${ns}:${key}`, exists: true });
    }
  });

  it('contract per species: dog, domestic cat (CAT_SPEC §9), Maine Coon with brushing', () => {
    expect(contractBody('dog', 'mutt')).toBe(t('contract:screen.body'));
    expect(contractBody('cat', 'domestic_cat')).toMatch(/^Zavezujem se, da bom vsak dan odgovorno skrbel za svojo virtualno muco/);
    expect(contractBody('cat', 'domestic_cat')).not.toMatch(/česal/);
    expect(contractBody('cat', 'maine_coon')).toMatch(/Redno jo bom česal, da se ji dlaka ne bo zavozlala\./);
    expect(contractBody(null, null)).toBe(t('contract:screen.body'));
  });
});
