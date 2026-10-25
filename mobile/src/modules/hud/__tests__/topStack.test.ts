/**
 * Top stack of the child HUD (David 2026-10-25 »pes mora biti viden»): most urgent first,
 * at most two cards, the rest behind "+N".
 */
import { arrangeTopStack, STACK_PRIORITY, takeOutPriority, TOP_STACK_MAX_VISIBLE } from '@/modules/hud/topStack';

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
