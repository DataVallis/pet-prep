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
  onlyChewingOpen,
  panelScene,
  parentBehaviourLines,
  PARENT_BEHAVIOUR_STRINGS,
  readChildBehaviour,
  readPetBehaviour,
  sceneOf,
  takeOutCountdown,
  type BehaviourEvent,
  type ChildBehaviour,
} from '@/modules/behaviour/behaviour';
import { makeBehaviourEvent, makeTakeOut } from '@/test-utils/fixtures';

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
  it('take-out restarts the clock now; no clock → unchanged', () => {
    const now = at('2026-10-04T10:30:00Z');
    const next = afterTakeOut(child({ take_out: makeTakeOut(), can_take_out: true }), now);
    expect(next.take_out).toEqual({
      hold_hours: 2,
      clock_started_at: '2026-10-04T10:30:00.000Z',
      last_taken_out_at: '2026-10-04T10:30:00.000Z',
      next_due_at: '2026-10-04T12:30:00.000Z',
    });
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

  it('under 3 h: "čez ~1 h 20 min"', () => {
    const c = takeOutCountdown(makeTakeOut(), at('2026-10-04T13:00:00+02:00') - 80 * 60_000, TZ);
    expect(c).toEqual({ line: 'Kuža bo moral ven čez ~1 h 20 min', hint: '~1 h 20 min', due: false });
  });

  it('later (quiet hours in between): a family clock time', () => {
    const clock = makeTakeOut({ next_due_at: '2026-10-04T13:30:00+02:00' });
    const c = takeOutCountdown(clock, at('2026-10-04T07:00:00+02:00'), TZ);
    expect(c).toEqual({ line: 'Kuža bo moral ven ob 13:30', hint: 'ob 13:30', due: false });
    const tomorrow = takeOutCountdown(makeTakeOut({ next_due_at: '2026-10-05T07:10:00+02:00' }), at('2026-10-04T21:00:00+02:00'), TZ);
    expect(tomorrow?.line).toBe('Kuža bo moral ven jutri ob 07:10');
  });

  it('under a minute left: "wants to go out", not "~1 min"', () => {
    const c = takeOutCountdown(makeTakeOut(), at('2026-10-04T13:00:00+02:00') - 40_000, TZ);
    expect(c).toEqual({ line: 'Kuža bi rad šel ven.', hint: 'zdaj', due: true });
    const justOver = takeOutCountdown(makeTakeOut(), at('2026-10-04T13:00:00+02:00') - 61_000, TZ);
    expect(justOver?.line).toBe('Kuža bo moral ven čez ~1 min');
  });

  it('due: a calm line, never an alarm', () => {
    const c = takeOutCountdown(makeTakeOut(), at('2026-10-04T13:05:00+02:00'), TZ);
    expect(c).toEqual({ line: 'Kuža bi rad šel ven.', hint: 'zdaj', due: true });
    expect(c?.line).not.toMatch(/!|nujno|hitro/i);
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
    expect(lines).toEqual(['Mladiček mora ven jutri ob 07:10 (zdrži ~2 h)', 'Kakec']);
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
