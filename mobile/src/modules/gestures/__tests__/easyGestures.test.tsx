/**
 * M5-T17 »Lažje kretnje«: the gating rule (switch off → swipe, on → hold, screen reader →
 * hold regardless of the switch), the payload readers (older servers → off) and the pure
 * hold helpers (same inputs the server already scores).
 */
import { act, renderHook } from '@testing-library/react-native';
import { AccessibilityInfo } from 'react-native';

import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { familyFromDashboard } from '@/modules/family/family';
import { easyGesturesOf, gestureModeFor, readEasyGestures, useGestureMode } from '@/modules/gestures/easyGestures';
import { BALL_CHARGE_MS, WAND_HOLD_GAPS_MS, ballFlightHeight, chargePower, wandHoldFeatherAt, wandHoldGap } from '@/modules/gestures/holdPlay';
import { HOLD_GAPS_MS } from '@/modules/catCare/brushing';
import { WAND_UNIFORM_MAX_SPREAD_MS } from '@/modules/catCare/catGames';
import { makeLiveChildState, makeScoredChild, makeScoredDashboard, withEasyGestures } from '@/test-utils/fixtures';

describe('gestureModeFor', () => {
  it('switch off → swipe only; on → hold; a screen reader → hold whatever the switch says', () => {
    expect(gestureModeFor(false, false)).toBe('swipe');
    expect(gestureModeFor(true, false)).toBe('hold');
    expect(gestureModeFor(false, true)).toBe('hold');
    expect(gestureModeFor(true, true)).toBe('hold');
  });
});

describe('readers', () => {
  it('only a real true turns the switch on (older servers send nothing)', () => {
    expect(readEasyGestures(true)).toBe(true);
    for (const v of [false, undefined, null, 'true', 1]) expect(readEasyGestures(v)).toBe(false);
  });

  it('the child view carries settings.easy_gestures (missing → off)', () => {
    const raw = makeLiveChildState();
    expect(normalizeChildState(raw).settings.easy_gestures).toBe(false);
    const on = normalizeChildState({ ...raw, settings: { media_display: 'video', easy_gestures: true } } as never);
    expect(on.settings).toEqual({ media_display: 'video', easy_gestures: true });
    expect(easyGesturesOf(on)).toBe(true);
    expect(easyGesturesOf(null)).toBe(false);
  });

  it('the family child carries easy_gestures (missing → off)', () => {
    const child = makeScoredChild({ id: 2 }) as unknown as Record<string, unknown>;
    delete child.easy_gestures;
    const off = familyFromDashboard(makeScoredDashboard([child as never], []) as never);
    expect(off?.children[0].easy_gestures).toBe(false);
    const on = familyFromDashboard(makeScoredDashboard([makeScoredChild({ id: 2, easy_gestures: true })], []) as never);
    expect(on?.children[0].easy_gestures).toBe(true);
  });
});

describe('useGestureMode (live screen reader)', () => {
  it('follows the switch and turns to hold when VoiceOver / TalkBack starts, back when it stops', async () => {
    let listener: ((v: boolean) => void) | null = null;
    const add = jest.spyOn(AccessibilityInfo, 'addEventListener').mockImplementation(((event: string, cb: (v: boolean) => void) => {
      if (event === 'screenReaderChanged') listener = cb;
      return { remove: jest.fn() };
    }) as never);
    const view = normalizeChildState(makeLiveChildState());
    const { result, rerender } = renderHook(({ v }: { v: ChildPetView }) => useGestureMode(v), { initialProps: { v: view } });
    await act(async () => undefined);
    expect(result.current).toBe('swipe');
    rerender({ v: withEasyGestures(view) });
    expect(result.current).toBe('hold');
    rerender({ v: view });
    act(() => listener?.(true));
    expect(result.current).toBe('hold');
    act(() => listener?.(false));
    expect(result.current).toBe('swipe');
    add.mockRestore();
  });

  it('a screen reader already on at start → hold', async () => {
    (AccessibilityInfo.isScreenReaderEnabled as jest.Mock).mockResolvedValueOnce(true);
    const { result } = renderHook(() => useGestureMode(normalizeChildState(makeLiveChildState())));
    await act(async () => undefined);
    expect(result.current).toBe('hold');
  });
});

describe('hold helpers (M5-T17) keep the server inputs valid', () => {
  it('wand gaps: ≥ 300 ms move interval, < 2 s pounce window, not machine-regular', () => {
    for (const g of WAND_HOLD_GAPS_MS) {
      expect(g).toBeGreaterThanOrEqual(300);
      expect(g).toBeLessThan(2_000);
    }
    expect(Math.max(...WAND_HOLD_GAPS_MS) - Math.min(...WAND_HOLD_GAPS_MS)).toBeGreaterThan(WAND_UNIFORM_MAX_SPREAD_MS);
    expect(wandHoldGap(0)).toBe(WAND_HOLD_GAPS_MS[0]);
    expect(wandHoldGap(WAND_HOLD_GAPS_MS.length)).toBe(WAND_HOLD_GAPS_MS[0]);
    expect(wandHoldGap(-1)).toBe(WAND_HOLD_GAPS_MS[WAND_HOLD_GAPS_MS.length - 1]);
  });

  it('the feather stays on the stage, above the cat', () => {
    for (let n = 0; n < 20; n++) {
      const p = wandHoldFeatherAt(n);
      expect(p.x).toBeGreaterThan(0);
      expect(p.x).toBeLessThan(1);
      expect(p.y).toBeGreaterThan(0);
      expect(p.y).toBeLessThanOrEqual(0.5);
    }
  });

  it('ball power fills over BALL_CHARGE_MS and only changes the flight height', () => {
    expect(chargePower(0)).toBe(0);
    expect(chargePower(BALL_CHARGE_MS / 2)).toBeCloseTo(0.5);
    expect(chargePower(BALL_CHARGE_MS * 3)).toBe(1);
    expect(chargePower(Number.NaN)).toBe(0);
    expect(ballFlightHeight(0)).toBe(120);
    expect(ballFlightHeight(1)).toBe(220);
  });

  it('brushing keeps the M5-T14 irregular hold rhythm', () => {
    expect(Math.max(...HOLD_GAPS_MS) - Math.min(...HOLD_GAPS_MS)).toBeGreaterThan(40);
  });
});
