/**
 * M5-R02 behaviour events: tolerant readers (legacy / older payloads → nothing new),
 * which cleaning tool fits which mess, optimistic effects, the calm countdown and the
 * parent's lines.
 */
import {
  EMPTY_BEHAVIOUR,
  afterClean,
  afterResolveChewing,
  afterTakeOut,
  cleaningMess,
  durationText,
  hasOpenChewing,
  needsScrubbing,
  nextTakeOutText,
  onlyChewingOpen,
  panelScene,
  parentBehaviourLines,
  PARENT_BEHAVIOUR_STRINGS,
  readChildBehaviour,
  readPetBehaviour,
  sceneOf,
  takeOutCountdown,
  takeOutLevel,
  takeOutToastText,
  type BehaviourEvent,
  type ChildBehaviour,
} from '@/modules/behaviour/behaviour';
import { makeBehaviourEvent, makeTakeOut } from '@/test-utils/fixtures';
import { i18n } from '@/i18n';
import { applyTimeFormat } from '@/modules/time/clock';

const TZ = 'Europe/Ljubljana';
const at = (iso: string) => Date.parse(iso);

function child(overrides: Partial<ChildBehaviour> = {}): ChildBehaviour {
  return { ...EMPTY_BEHAVIOUR, ...overrides };
}

function ev(kind: BehaviourEvent['kind'], id: number): BehaviourEvent {
  return makeBehaviourEvent(kind, { id });
}

describe('readers', () => {
  it('missing / malformed behaviour → nothing open (legacy pet, older server)', () => {
    expect(readChildBehaviour(undefined)).toEqual(EMPTY_BEHAVIOUR);
    expect(readChildBehaviour(null)).toEqual(EMPTY_BEHAVIOUR);
    expect(readChildBehaviour('x')).toEqual(EMPTY_BEHAVIOUR);
    expect(readChildBehaviour([])).toEqual(EMPTY_BEHAVIOUR);
    expect(readPetBehaviour(undefined)).toEqual({ take_out: null, active_events: [], scene: null });
  });

  it('reads a full child behaviour', () => {
    const raw = {
      take_out: makeTakeOut(),
      active_events: [makeBehaviourEvent('accident')],
      scene: 'accident',
      can_take_out: true,
      can_resolve_chewing: false,
    };
    expect(readChildBehaviour(raw)).toEqual(raw);
  });

  it('drops broken parts instead of throwing', () => {
    const b = readChildBehaviour({
      take_out: { hold_hours: 0, clock_started_at: 'x', next_due_at: null },
      active_events: [
        { id: 1, kind: 'fire', started_at: '2026-10-04T10:00:00Z', due_at: '2026-10-04T12:00:00Z' },
        { id: 2, kind: 'poop', started_at: 'never', due_at: '2026-10-04T12:00:00Z' },
        null,
        makeBehaviourEvent('chewing', { id: 3 }),
      ],
      scene: 'zoomies',
      can_take_out: 'true',
    });
    expect(b.take_out).toBeNull();
    expect(b.active_events.map((e) => e.id)).toEqual([3]);
    expect(b.scene).toBeNull();
    // Only a real boolean true enables an action.
    expect(b.can_take_out).toBe(false);
  });
});

describe('which tool for which mess', () => {
  it('scrubbing is for poop / accidents; a chewed slipper alone is not scrubbed', () => {
    expect(needsScrubbing(false, { active_events: [ev('poop', 1)] })).toBe(false);
    expect(needsScrubbing(true, { active_events: [ev('poop', 1)] })).toBe(true);
    expect(needsScrubbing(true, { active_events: [ev('accident', 1)] })).toBe(true);
    expect(needsScrubbing(true, { active_events: [ev('chewing', 1)] })).toBe(false);
    expect(needsScrubbing(true, { active_events: [ev('chewing', 1), ev('poop', 2)] })).toBe(true);
    // Dirty without event info (older server) → the classic scrub game.
    expect(needsScrubbing(true, { active_events: [] })).toBe(true);
  });

  it('onlyChewingOpen / hasOpenChewing', () => {
    expect(onlyChewingOpen({ active_events: [] })).toBe(false);
    expect(onlyChewingOpen({ active_events: [ev('chewing', 1)] })).toBe(true);
    expect(onlyChewingOpen({ active_events: [ev('chewing', 1), ev('accident', 2)] })).toBe(false);
    expect(hasOpenChewing({ active_events: [ev('poop', 1), ev('chewing', 2)] })).toBe(true);
  });

  it('cleaning game shows puddles only when every scrub-able mess is an accident', () => {
    expect(cleaningMess({ active_events: [ev('accident', 1)] })).toBe('accident');
    expect(cleaningMess({ active_events: [ev('accident', 1), ev('chewing', 2)] })).toBe('accident');
    expect(cleaningMess({ active_events: [ev('accident', 1), ev('poop', 2)] })).toBe('poop');
    expect(cleaningMess({ active_events: [] })).toBe('poop');
  });

  it('scene = newest open accident / chewing; panel falls back to chewing', () => {
    expect(sceneOf([ev('chewing', 1), ev('accident', 2), ev('poop', 3)])).toBe('accident');
    expect(sceneOf([ev('accident', 1), ev('chewing', 2)])).toBe('chewing');
    expect(sceneOf([ev('poop', 1)])).toBeNull();
    expect(panelScene({ take_out: null, active_events: [ev('chewing', 1)], scene: null })).toBe('chewing');
    expect(panelScene({ take_out: null, active_events: [], scene: null })).toBeNull();
  });
});

describe('optimistic effects', () => {
  it('take-out keeps the deadline until the server answers (David 2026-11-27); no clock → unchanged', () => {
    const now = at('2026-10-04T10:30:00Z');
    const next = afterTakeOut(child({ take_out: makeTakeOut(), can_take_out: true }), now);
    expect(next.take_out).toEqual({ ...makeTakeOut(), last_taken_out_at: '2026-10-04T10:30:00.000Z' });
    const none = child();
    expect(afterTakeOut(none, now)).toBe(none);
  });

  it('clean removes poop / accidents but keeps the slipper', () => {
    const b = child({ active_events: [ev('accident', 1), ev('chewing', 2)], scene: 'chewing', can_resolve_chewing: true });
    const next = afterClean(b);
    expect(next.active_events.map((e) => e.kind)).toEqual(['chewing']);
    expect(next.scene).toBe('chewing');
    expect(next.can_resolve_chewing).toBe(true);
  });

  it('resolve-chewing removes the slipper but keeps an accident', () => {
    const b = child({ active_events: [ev('accident', 1), ev('chewing', 2)], scene: 'chewing', can_resolve_chewing: true });
    const next = afterResolveChewing(b);
    expect(next.active_events.map((e) => e.kind)).toEqual(['accident']);
    expect(next.scene).toBe('accident');
    expect(next.can_resolve_chewing).toBe(false);
  });
});

describe('calm countdown', () => {
  it('durationText rounds down (to 5 above 10 min) — never later than said', () => {
    expect(durationText(80)).toBe('~1 h 20 min');
    expect(durationText(84.9)).toBe('~1 h 20 min');
    expect(durationText(77.2)).toBe('~1 h 15 min');
    expect(durationText(120)).toBe('~2 h');
    expect(durationText(44)).toBe('~40 min');
    expect(durationText(7.3)).toBe('~7 min');
    expect(durationText(0.2)).toBe('~1 min');
  });

  const HOW_TO = 'Pritisni »Pelji ven«, preden se čas izteče.';

  it('under 3 h: a deadline "mora ven v ~1 h 20 min." + how-to (M5-T01)', () => {
    const c = takeOutCountdown(makeTakeOut(), at('2026-10-04T13:00:00+02:00') - 80 * 60_000, TZ);
    expect(c).toEqual({ line: 'Kuža mora ven v ~1 h 20 min.', hint: '~1 h 20 min', due: false, level: 'calm', howTo: HOW_TO, short: 'Ven čez ~1 h 20 min' });
  });

  it('later (quiet hours in between): "najkasneje" + a family clock time', () => {
    const clock = makeTakeOut({ next_due_at: '2026-10-04T13:30:00+02:00' });
    const c = takeOutCountdown(clock, at('2026-10-04T07:00:00+02:00'), TZ);
    expect(c).toEqual({ line: 'Kuža mora ven najkasneje ob 13:30.', hint: 'ob 13:30', due: false, level: 'calm', howTo: HOW_TO, short: 'Ven najkasneje ob 13:30' });
    const tomorrow = takeOutCountdown(makeTakeOut({ next_due_at: '2026-10-05T07:10:00+02:00' }), at('2026-10-04T21:00:00+02:00'), TZ);
    expect(tomorrow?.line).toBe('Kuža mora ven najkasneje jutri ob 7:10.');
  });

  it('under a minute left: "needs to go out", not "~1 min"', () => {
    const c = takeOutCountdown(makeTakeOut(), at('2026-10-04T13:00:00+02:00') - 40_000, TZ);
    expect(c).toEqual({ line: 'Kuža mora ven.', hint: 'zdaj', due: true, level: 'urgent', howTo: null, short: 'Kuža mora ven zdaj' });
    const justOver = takeOutCountdown(makeTakeOut(), at('2026-10-04T13:00:00+02:00') - 61_000, TZ);
    expect(justOver?.line).toBe('Kuža mora ven v ~1 min.');
  });

  it('due: a calm line, never an alarm', () => {
    const c = takeOutCountdown(makeTakeOut(), at('2026-10-04T13:05:00+02:00'), TZ);
    expect(c).toEqual({ line: 'Kuža mora ven.', hint: 'zdaj', due: true, level: 'urgent', howTo: null, short: 'Kuža mora ven zdaj' });
    expect(c?.line).not.toMatch(/!|nujno|hitro/i);
  });
});

describe('evening take-out (David 2026-11-26)', () => {
  const evening = makeTakeOut({ kind: 'evening', clock_started_at: '2026-10-04T16:30:00+02:00', next_due_at: '2026-10-04T21:00:00+02:00' });

  afterEach(async () => {
    applyTimeFormat('auto');
    await i18n.changeLanguage('sl');
  });

  it('reads kind; an unknown or missing kind is left out (= regular)', () => {
    expect(readPetBehaviour({ take_out: evening }).take_out?.kind).toBe('evening');
    expect(readPetBehaviour({ take_out: { ...evening, kind: 'noon' } }).take_out?.kind).toBeUndefined();
    const { kind: _k, ...old } = evening;
    void _k;
    expect(readPetBehaviour({ take_out: old }).take_out).toEqual(old);
  });

  it('card + pill: »Pred spanjem ven — najkasneje ob 21:00«, also under 3 h', async () => {
    await i18n.changeLanguage('sl');
    applyTimeFormat('24h');
    const far = takeOutCountdown(evening, at('2026-10-04T16:30:00+02:00'), TZ);
    expect(far?.line).toBe('Pred spanjem ven — najkasneje ob 21:00');
    expect(far?.short).toBe('Pred spanjem ven — najkasneje ob 21:00');
    expect(far?.hint).toBe('ob 21:00');
    expect(far?.due).toBe(false);
    const near = takeOutCountdown(evening, at('2026-10-04T19:00:00+02:00'), TZ);
    expect(near?.line).toBe('Pred spanjem ven — najkasneje ob 21:00');
    expect(near?.hint).toBe('~2 h');
    expect(near?.howTo).toBe('Pritisni »Pelji ven«, preden se čas izteče.');
  });

  it('English, 12 h clock through modules/time/clock.ts', async () => {
    await i18n.changeLanguage('en');
    applyTimeFormat('24h');
    expect(takeOutCountdown(evening, at('2026-10-04T16:30:00+02:00'), TZ)?.line).toBe('Out before bed — by 21:00 at the latest');
    applyTimeFormat('12h');
    expect(takeOutCountdown(evening, at('2026-10-04T16:30:00+02:00'), TZ)?.short).toBe('Out before bed — by 9:00 PM at the latest');
  });

  it('a regular or morning deadline keeps the old texts', () => {
    applyTimeFormat('24h');
    const morning = makeTakeOut({ kind: 'morning', next_due_at: '2026-10-05T07:30:00+02:00' });
    expect(takeOutCountdown(morning, at('2026-10-04T21:30:00+02:00'), TZ)?.line).toBe('Kuža mora ven najkasneje jutri ob 7:30.');
  });

  it('the optimistic take-out keeps the evening card (never a plain »now + hold« across the night)', () => {
    const next = afterTakeOut(child({ take_out: evening, can_take_out: true }), at('2026-10-04T19:30:00+02:00'));
    expect(next.take_out?.kind).toBe('evening');
    expect(next.take_out?.next_due_at).toBe(evening.next_due_at);
  });
});

describe('evening window (David 2026-11-27 device bug: out at 18:56, card still »najkasneje ob 21:00«)', () => {
  const win = makeTakeOut({
    kind: 'evening',
    clock_started_at: '2026-10-04T18:56:00+02:00',
    next_due_at: '2026-10-04T21:00:00+02:00',
    last_taken_out_at: '2026-10-04T18:56:00+02:00',
    window_starts_at: '2026-10-04T19:00:00+02:00',
  });

  afterEach(async () => {
    applyTimeFormat('auto');
    await i18n.changeLanguage('sl');
  });

  it('reads window_starts_at; unreadable → null, missing (older server) → absent', () => {
    expect(readPetBehaviour({ take_out: win }).take_out?.window_starts_at).toBe('2026-10-04T19:00:00+02:00');
    expect(readPetBehaviour({ take_out: { ...win, window_starts_at: null } }).take_out?.window_starts_at).toBeNull();
    expect(readPetBehaviour({ take_out: { ...win, window_starts_at: 'x' } }).take_out?.window_starts_at).toBeNull();
    const { window_starts_at: _w, ...older } = win;
    void _w;
    expect(readPetBehaviour({ take_out: older }).take_out).not.toHaveProperty('window_starts_at');
  });

  it('before the window: »med 19:00 in 21:00«, the button says »od 19:00«, calm, never due', () => {
    applyTimeFormat('24h');
    const c = takeOutCountdown(win, at('2026-10-04T18:56:30+02:00'), TZ);
    expect(c).toEqual({
      line: 'Pred spanjem ven — med 19:00 in 21:00',
      hint: 'od 19:00',
      due: false,
      level: 'calm',
      howTo: 'Pritisni »Pelji ven« med 19:00 in 21:00. Prej ne šteje.',
      short: 'Pred spanjem ven — med 19:00 in 21:00',
    });
  });

  it('inside the window: »najkasneje ob 21:00« with the countdown', () => {
    applyTimeFormat('24h');
    const c = takeOutCountdown(win, at('2026-10-04T19:00:00+02:00'), TZ);
    expect(c?.line).toBe('Pred spanjem ven — najkasneje ob 21:00');
    expect(c?.hint).toBe('~2 h');
    expect(c?.howTo).toBe('Pritisni »Pelji ven«, preden se čas izteče.');
  });

  it('English + 12 h through modules/time/clock.ts, in the pet zone', async () => {
    await i18n.changeLanguage('en');
    applyTimeFormat('12h');
    const c = takeOutCountdown(win, at('2026-10-04T18:00:00+02:00'), TZ);
    expect(c?.line).toBe('Out before bed — between 7:00 PM and 9:00 PM');
    expect(c?.hint).toBe('from 7:00 PM');
    expect(c?.howTo).toBe('Press “Take out” between 7:00 PM and 9:00 PM. Earlier doesn’t count.');
  });

  it('toast before the window is honest; no »next time« line then', () => {
    applyTimeFormat('24h');
    expect(takeOutToastText('accepted', win, '2026-10-04T18:56:00+02:00', TZ)).toBe(
      'Lepo, da je bil zunaj! Pred spanjem (med 19:00 in 21:00) ga pelji ven še enkrat.',
    );
    expect(takeOutToastText('unchanged', win, '2026-10-04T18:57:00+02:00', TZ)).toBe(
      'Kuža je bil pravkar zunaj. Pred spanjem (med 19:00 in 21:00) ga pelji ven še enkrat.',
    );
    expect(nextTakeOutText(win, '2026-10-04T18:56:00+02:00', TZ)).toBeNull();
  });

  it('toast: the usual text inside the window, for other kinds and older servers', () => {
    expect(takeOutToastText('accepted', win, '2026-10-04T19:00:00+02:00', TZ)).toBeNull();
    expect(takeOutToastText('accepted', makeTakeOut(), '2026-10-04T11:00:00+02:00', TZ)).toBeNull();
    expect(takeOutToastText('accepted', { ...win, window_starts_at: undefined }, '2026-10-04T18:56:00+02:00', TZ)).toBeNull();
    expect(takeOutToastText('accepted', null, '2026-10-04T18:56:00+02:00', TZ)).toBeNull();
    expect(takeOutToastText('accepted', win, null, TZ)).toBeNull();
  });

  it('pet zone, not the phone: a New York pet shows its own clock', () => {
    applyTimeFormat('24h');
    const ny = makeTakeOut({ kind: 'evening', next_due_at: '2026-10-04T21:00:00-04:00', window_starts_at: '2026-10-04T19:00:00-04:00' });
    expect(takeOutCountdown(ny, at('2026-10-04T18:00:00-04:00'), 'America/New_York')?.line).toBe('Pred spanjem ven — med 19:00 in 21:00');
  });
});

describe('clock colour (M5-T01)', () => {
  it('green → orange at ≤ 60 min (or the last half of a long hold) → red at ≤ 30 min', () => {
    // 2 h hold: half = 60 min → orange from 60 min.
    expect(takeOutLevel(61, 2)).toBe('calm');
    expect(takeOutLevel(60, 2)).toBe('soon');
    expect(takeOutLevel(31, 2)).toBe('soon');
    expect(takeOutLevel(30, 2)).toBe('urgent');
    expect(takeOutLevel(0, 2)).toBe('urgent');
    // 1 h hold: never shorter than 60 min of orange — so orange from the start.
    expect(takeOutLevel(59, 1)).toBe('soon');
    // 4 h hold: orange in the last 2 h.
    expect(takeOutLevel(121, 4)).toBe('calm');
    expect(takeOutLevel(120, 4)).toBe('soon');
  });

  it('the countdown carries the level', () => {
    const due = at('2026-10-04T13:00:00+02:00');
    expect(takeOutCountdown(makeTakeOut(), due - 45 * 60_000, TZ)?.level).toBe('soon');
    expect(takeOutCountdown(makeTakeOut(), due - 20 * 60_000, TZ)?.level).toBe('urgent');
  });
});

describe('next take-out line (M5-T01 confirmation)', () => {
  it('from the state that came with the answer', () => {
    expect(nextTakeOutText(makeTakeOut(), '2026-10-04T11:00:00+02:00', TZ)).toBe('Naslednjič čez ~2 h.');
    expect(nextTakeOutText(makeTakeOut({ next_due_at: '2026-10-05T07:10:00+02:00' }), '2026-10-04T21:00:00+02:00', TZ)).toBe(
      'Naslednjič jutri ob 7:10.',
    );
  });

  it('nothing when unknown or already due (never invented)', () => {
    expect(nextTakeOutText(null, '2026-10-04T11:00:00+02:00', TZ)).toBeNull();
    expect(nextTakeOutText(makeTakeOut(), null, TZ)).toBeNull();
    expect(nextTakeOutText(makeTakeOut(), '2026-10-04T13:00:00+02:00', TZ)).toBeNull();
  });
});

describe('parent lines', () => {
  it('bladder clock, last take-out and open messes in the family clock', () => {
    const lines = parentBehaviourLines(
      {
        take_out: makeTakeOut(),
        active_events: [makeBehaviourEvent('accident'), makeBehaviourEvent('chewing', { id: 2, due_at: '2026-10-04T15:00:00+02:00' })],
        scene: 'chewing',
      },
      TZ,
      '2026-10-04T12:00:00+02:00',
    );
    expect(lines).toEqual([
      'Mladiček mora ven ob 13:00 (zdrži ~2 h)',
      'Nazadnje zunaj ob 11:00',
      'Luža — počistiti do 13:30',
      'Pregrizen copat — počistiti do 15:00',
    ]);
  });

  it('a take-out due tomorrow says "jutri"; an unreadable deadline is omitted, never "?"', () => {
    const lines = parentBehaviourLines(
      {
        take_out: makeTakeOut({ next_due_at: '2026-10-05T07:10:00+02:00', last_taken_out_at: null }),
        active_events: [{ ...makeBehaviourEvent('poop'), due_at: '' }],
        scene: null,
      },
      TZ,
      '2026-10-04T21:00:00+02:00',
    );
    expect(lines).toEqual(['Mladiček mora ven jutri ob 7:10 (zdrži ~2 h)', 'Kakec']);
    expect(PARENT_BEHAVIOUR_STRINGS.openEvent('Luža', null)).toBe('Luža');
  });

  it('lastDays agrees with the number', () => {
    expect(PARENT_BEHAVIOUR_STRINGS.lastDays(7)).toBe('Zadnjih 7 dni');
    expect(PARENT_BEHAVIOUR_STRINGS.lastDays(1)).toBe('Zadnji dan');
    expect(PARENT_BEHAVIOUR_STRINGS.lastDays(2)).toBe('Zadnja 2 dneva');
    expect(PARENT_BEHAVIOUR_STRINGS.lastDays(3)).toBe('Zadnji 3 dnevi');
  });

  it('nothing for a pet without behaviour', () => {
    expect(parentBehaviourLines(readPetBehaviour(undefined), TZ)).toEqual([]);
  });

  it('stats text only names what happened', () => {
    expect(PARENT_BEHAVIOUR_STRINGS.stats(0, 0)).toBe('');
    expect(PARENT_BEHAVIOUR_STRINGS.stats(5, 0)).toBe('5× peljal(a) ven');
    expect(PARENT_BEHAVIOUR_STRINGS.stats(5, 1)).toBe('5× peljal(a) ven · 1× pospravil(a) copat');
  });
});
