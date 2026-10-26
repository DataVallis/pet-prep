/**
 * Top stack of the child HUD (David 2026-10-25 »pes mora biti viden»): most urgent first,
 * at most two cards, the rest behind "+N".
 */
import {
  alertTokens,
  arrangeTopStack,
  collapsedSummary,
  completesStackSwipe,
  isUrgentPriority,
  reconcileAlerts,
  STACK_PRIORITY,
  startsStackSwipe,
  takeOutPriority,
  TOP_STACK_MAX_VISIBLE,
  TOP_STACK_SWIPE_DISTANCE,
  TOP_STACK_SWIPE_START,
  TOP_STACK_SWIPE_VELOCITY,
} from '@/modules/hud/topStack';

const card = (key: string, priority: number) => ({ key, priority });

describe('arrangeTopStack', () => {
  it('sorts by urgency: a red take-out clock comes before the mess card, the meals row last', () => {
    const arranged = arrangeTopStack(
      [
        card('meals', STACK_PRIORITY.meals),
        card('scene', STACK_PRIORITY.scene),
        card('hint', STACK_PRIORITY.hint),
        card('take-out', takeOutPriority('urgent')),
      ],
      true,
    );
    expect(arranged.visible.map((c) => c.key)).toEqual(['take-out', 'scene', 'hint', 'meals']);
  });

  it('a calm clock yields to the mess card', () => {
    const { visible } = arrangeTopStack([card('take-out', takeOutPriority('calm')), card('scene', STACK_PRIORITY.scene)], false);
    expect(visible.map((c) => c.key)).toEqual(['scene', 'take-out']);
    expect(takeOutPriority('soon')).toBeLessThan(STACK_PRIORITY.scene);
  });

  it(`shows at most ${TOP_STACK_MAX_VISIBLE} cards and counts the rest for "+N"`, () => {
    const entries = [card('a', 1), card('b', 2), card('c', 3), card('d', 4)];
    const collapsed = arrangeTopStack(entries, false);
    expect(collapsed.visible.map((c) => c.key)).toEqual(['a', 'b']);
    expect(collapsed).toMatchObject({ hiddenCount: 2, collapsible: true });

    const expanded = arrangeTopStack(entries, true);
    expect(expanded.visible).toHaveLength(4);
    expect(expanded).toMatchObject({ hiddenCount: 0, collapsible: true });
  });

  it('no toggle when everything fits', () => {
    expect(arrangeTopStack([card('a', 1), card('b', 2)], false)).toMatchObject({ hiddenCount: 0, collapsible: false });
    expect(arrangeTopStack([], false)).toMatchObject({ visible: [], hiddenCount: 0, collapsible: false });
  });

  it('keeps the given order for equal priorities and never loses a card', () => {
    for (let n = 0; n <= 8; n += 1) {
      const entries = Array.from({ length: n }, (_, i) => card(`k${i}`, STACK_PRIORITY.hint));
      const { visible, hiddenCount } = arrangeTopStack(entries, false);
      expect(visible.length + hiddenCount).toBe(n);
      expect(visible.map((c) => c.key)).toEqual(entries.slice(0, TOP_STACK_MAX_VISIBLE).map((c) => c.key));
    }
  });
});

describe('collapsed stack (David 2026-10-26)', () => {
  it('the pill shows the most urgent card, "+N" for the others, red only for urgent cards', () => {
    const summary = collapsedSummary([card('meals', STACK_PRIORITY.meals), card('take-out', takeOutPriority('soon')), card('hint', STACK_PRIORITY.hint)]);
    expect(summary.top?.key).toBe('take-out');
    expect(summary).toMatchObject({ others: 2, urgent: true });

    expect(collapsedSummary([card('take-out', takeOutPriority('calm')), card('meals', STACK_PRIORITY.meals)])).toMatchObject({
      others: 1,
      urgent: false,
    });
    expect(collapsedSummary([])).toEqual({ top: null, others: 0, urgent: false });
  });

  it('urgent = red / orange take-out clock, an open mess and the cat first aid', () => {
    expect(isUrgentPriority(takeOutPriority('urgent'))).toBe(true);
    expect(isUrgentPriority(takeOutPriority('soon'))).toBe(true);
    expect(isUrgentPriority(STACK_PRIORITY.scene)).toBe(true);
    expect(isUrgentPriority(STACK_PRIORITY.firstAid)).toBe(true);
    for (const calm of [takeOutPriority('calm'), STACK_PRIORITY.invitation, STACK_PRIORITY.notice, STACK_PRIORITY.hint, STACK_PRIORITY.meals]) {
      expect(isUrgentPriority(calm)).toBe(false);
    }
  });

  it('alert tokens: only urgent cards, by alert id and priority', () => {
    expect(
      alertTokens([
        { key: 'scene', priority: STACK_PRIORITY.scene, alertId: 'scene-accident-7' },
        card('take-out', takeOutPriority('soon')),
        card('meals', STACK_PRIORITY.meals),
      ]),
    ).toEqual([`scene-accident-7:${STACK_PRIORITY.scene}`, `take-out:${takeOutPriority('soon')}`]);
  });

  it('auto-expands once for a new urgent item, never again for the same one', () => {
    const orange = `take-out:${takeOutPriority('soon')}`;
    const red = `take-out:${takeOutPriority('urgent')}`;
    let state = reconcileAlerts([], []);
    expect(state.fresh).toBe(false);
    // The clock turns orange → new.
    state = reconcileAlerts(state.seen, [orange]);
    expect(state).toEqual({ fresh: true, seen: [orange] });
    // Same orange clock on the next renders → nothing.
    expect(reconcileAlerts(state.seen, [orange]).fresh).toBe(false);
    // Orange → red is new again.
    state = reconcileAlerts(state.seen, [red]);
    expect(state.fresh).toBe(true);
    // A new mess while the clock stays red.
    state = reconcileAlerts(state.seen, [red, 'scene-chewing-9:2']);
    expect(state.fresh).toBe(true);
    // Gone (taken out / tidied) and back later → new.
    state = reconcileAlerts(state.seen, []);
    expect(reconcileAlerts(state.seen, [red]).fresh).toBe(true);
  });

  it('swipe: up collapses, down expands; mostly vertical and past the start distance', () => {
    expect(startsStackSwipe('up', 2, -(TOP_STACK_SWIPE_START + 1))).toBe(true);
    expect(startsStackSwipe('up', 2, TOP_STACK_SWIPE_START + 1)).toBe(false);
    expect(startsStackSwipe('up', 40, -20)).toBe(false);
    expect(startsStackSwipe('up', 0, -4)).toBe(false);
    expect(startsStackSwipe('down', 0, TOP_STACK_SWIPE_START + 1)).toBe(true);

    expect(completesStackSwipe('up', -(TOP_STACK_SWIPE_DISTANCE + 1), 0)).toBe(true);
    expect(completesStackSwipe('up', -10, -(TOP_STACK_SWIPE_VELOCITY + 0.1))).toBe(true);
    expect(completesStackSwipe('up', -10, 0)).toBe(false);
    expect(completesStackSwipe('down', TOP_STACK_SWIPE_DISTANCE + 1, 0)).toBe(true);
    expect(completesStackSwipe('down', -(TOP_STACK_SWIPE_DISTANCE + 1), 0)).toBe(false);
  });
});
