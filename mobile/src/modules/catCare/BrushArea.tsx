/**
 * The grooming stage as a scratch card (M5-T14, APP_REFINEMENT_SPEC §11 + §6 last bullet).
 *
 * - Swipe: the same `beginRub` / `moveRub` / `endRub` strokes as before (server unchanged);
 *   the brush follows the finger, sparkles come out of the coat, the patch nearest to the
 *   finger fades with every counted stroke, the bar fills (`brushing.ts`).
 * - Hold (no swiping needed) — only in `gestureMode="hold"` (M5-T17 »Lažje kretnje« or a
 *   running screen reader): a finger resting on the pet for `HOLD_START_MS`, or holding the
 *   "Počeši" button, brushes on its own at the irregular `HOLD_GAPS_MS` rhythm; a short tap
 *   on the button is one stroke. In `swipe` mode there is no hold and no button.
 * - VoiceOver / TalkBack (always hold mode): the stage is one element with the "activate"
 *   action (= one stroke) and the button keeps its label; the bar is a `progressbar`.
 * - The same component scrubs the weekly litter change in hold mode (M5-T17): patches on the
 *   box, the bar fills, same strokes.
 * - Reduce motion: no sparkles, patches change without a fade.
 */

import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { PanResponder, Pressable, StyleSheet, View, type AccessibilityActionEvent, type LayoutChangeEvent } from 'react-native';
import { Hand } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { BrushLayers, useBrushFx } from '@/modules/catCare/BrushLayers';
import {
  HOLD_SLOP_PT,
  HOLD_START_MS,
  applyStroke,
  brushProgress,
  holdGap,
  initialBrush,
  layoutPatches,
  normalisePoint,
  type BrushState,
} from '@/modules/catCare/brushing';
import { beginRub, endRub, moveRub, type ChoreRules, type Point, type RubTracker } from '@/modules/catCare/catGames';
import { styles as partStyles } from '@/modules/catCare/CatGameParts';
import type { GestureMode } from '@/modules/gestures/easyGestures';
import { alpha, palette, radius } from '@/theme';

export interface BrushTexts {
  holdHint: string;
  /** The hint in swipe mode (no hold, no button). */
  swipeHint: string;
  clean: string;
  action: string;
  holdA11yHint: string;
  bar: (percent: number) => string;
}

export interface BrushAreaProps {
  rules: ChoreRules;
  /** The stage (pet video / drawing) — rendered under the brush layers. */
  renderStage: (layers: ReactNode) => ReactNode;
  texts: BrushTexts;
  stageA11y: string;
  strokeLabel: string;
  strokeA11y: string;
  disabled: boolean;
  /** Hide the stroke button (while finishing). */
  hideButton: boolean;
  reduceMotion: boolean;
  /** M5-T17: `hold` = hold on the stage + the stroke button + the "activate" action; `swipe` = swipe only. */
  gestureMode: GestureMode;
  msAt: (touchTimestamp?: number | null) => number;
  /** Record a stroke (ms on the game clock) — the same input the server scores. */
  onStroke: (ms: number) => void;
  /** GAMES_SPEC §7.7: on the game layer the hint line moves to the top bar (no text under the thumb). */
  hideHint?: boolean;
  testID: string;
}

export function BrushArea({ rules, renderStage, texts, stageA11y, strokeLabel, strokeA11y, disabled, hideButton, reduceMotion, gestureMode, msAt, onStroke, hideHint = false, testID }: BrushAreaProps) {
  const holdMode = gestureMode === 'hold';
  const holdModeRef = useRef(holdMode);
  holdModeRef.current = holdMode;
  const patches = useMemo(() => layoutPatches(rules.segments), [rules.segments]);
  const [brush, setBrush] = useState<BrushState>(() => initialBrush(patches.length));
  const brushRef = useRef(brush);
  const [size, setSize] = useState({ width: 0, height: 0 });
  const sizeRef = useRef(size);
  sizeRef.current = size;
  const { fx, brush: brushAnim, sparks } = useBrushFx(reduceMotion);

  const disabledRef = useRef(disabled);
  disabledRef.current = disabled;
  const rulesRef = useRef(rules);
  rulesRef.current = rules;
  const onStrokeRef = useRef(onStroke);
  onStrokeRef.current = onStroke;
  const msAtRef = useRef(msAt);
  msAtRef.current = msAt;

  /** One stroke: to the game input (always) and to the patches (when it counts). */
  const stroke = useCallback(
    (ms: number, at: Point | null) => {
      if (disabledRef.current) return;
      onStrokeRef.current(ms);
      const next = applyStroke(brushRef.current, rulesRef.current, patches, ms, at === null ? null : normalisePoint(at, sizeRef.current));
      if (next !== brushRef.current) {
        brushRef.current = next;
        setBrush(next);
      }
    },
    [patches],
  );

  // ── Hold: timer chain (button or a finger resting on the pet) ──
  const holdTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const holdCount = useRef(0);
  const holdPoint = useRef<Point | null>(null);
  const stopHold = useCallback(() => {
    if (holdTimer.current !== null) clearTimeout(holdTimer.current);
    holdTimer.current = null;
  }, []);
  const scheduleHold = useCallback(
    (delay: number) => {
      stopHold();
      holdTimer.current = setTimeout(() => {
        holdTimer.current = null;
        if (disabledRef.current) return;
        stroke(msAtRef.current(null), holdPoint.current);
        holdCount.current += 1;
        scheduleHold(holdGap(holdCount.current));
      }, delay);
    },
    [stopHold, stroke],
  );
  useEffect(() => stopHold, [stopHold]);
  useEffect(() => {
    if (disabled || !holdMode) stopHold();
  }, [disabled, holdMode, stopHold]);

  // ── Swipe ──
  const rub = useRef<RubTracker | null>(null);
  const origin = useRef<Point>({ x: 0, y: 0 });
  const lastPoint = useRef<Point>({ x: 0, y: 0 });
  const holding = useRef(false);

  const responder = useMemo(
    () =>
      PanResponder.create({
        onStartShouldSetPanResponder: () => !disabledRef.current,
        onMoveShouldSetPanResponder: () => !disabledRef.current,
        onPanResponderGrant: (e) => {
          origin.current = { x: e.nativeEvent.locationX, y: e.nativeEvent.locationY };
          lastPoint.current = origin.current;
          rub.current = beginRub(origin.current, msAtRef.current(e.nativeEvent.timestamp));
          fx.move(origin.current, e.nativeEvent.timestamp ?? 0);
          // Hold mode: a finger that rests on the pet starts the hold brushing.
          if (holdModeRef.current) {
            holdCount.current = 0;
            holdPoint.current = origin.current;
            holding.current = true;
            scheduleHold(HOLD_START_MS);
          }
        },
        onPanResponderMove: (e, g) => {
          if (rub.current === null) return;
          const p = { x: origin.current.x + g.dx, y: origin.current.y + g.dy };
          lastPoint.current = p;
          holdPoint.current = p;
          if (holding.current && Math.hypot(g.dx, g.dy) > HOLD_SLOP_PT) {
            // It's a swipe, not a hold (also after a hold: the swipe takes over).
            holding.current = false;
            stopHold();
          }
          fx.move(p, e.nativeEvent.timestamp ?? 0);
          const step = moveRub(rub.current, p, msAtRef.current(e.nativeEvent.timestamp));
          rub.current = step.tracker;
          if (step.stroke !== null) stroke(step.stroke, p);
        },
        onPanResponderRelease: (e) => {
          stopHold();
          holding.current = false;
          fx.lift();
          if (rub.current === null) return;
          const end = endRub(rub.current, msAtRef.current(e.nativeEvent.timestamp));
          rub.current = null;
          if (end !== null) stroke(end, lastPoint.current);
        },
        onPanResponderTerminate: () => {
          stopHold();
          holding.current = false;
          fx.lift();
          rub.current = null;
        },
      }),
    [fx, scheduleHold, stopHold, stroke],
  );

  const onLayout = (e: LayoutChangeEvent) => {
    const { width, height } = e.nativeEvent.layout;
    if (width !== sizeRef.current.width || height !== sizeRef.current.height) setSize({ width, height });
  };

  const onA11yAction = (e: AccessibilityActionEvent) => {
    if (e.nativeEvent.actionName === 'activate') stroke(msAtRef.current(null), null);
  };

  const progress = brushProgress(brush, rules, patches);
  const layers = <BrushLayers patches={patches} dirt={progress.dirt} size={size} brush={brushAnim} sparks={sparks} reduceMotion={reduceMotion} testID={testID} />;

  // The button: a tap = one stroke (as before), holding = brushing on its own.
  const onPressIn = () => {
    if (disabledRef.current) return;
    holdCount.current = 0;
    holdPoint.current = null;
    scheduleHold(HOLD_START_MS);
  };
  const onPressOut = () => stopHold();
  const onPress = () => {
    if (holdCount.current === 0) stroke(msAtRef.current(null), null);
    holdCount.current = 0;
  };

  return (
    <>
      <View
        style={partStyles.running}
        onLayout={onLayout}
        {...responder.panHandlers}
        accessible
        accessibilityRole={holdMode ? 'button' : undefined}
        accessibilityLabel={stageA11y}
        accessibilityHint={holdMode ? texts.holdA11yHint : undefined}
        accessibilityActions={holdMode ? [{ name: 'activate', label: texts.action }] : undefined}
        onAccessibilityAction={holdMode ? onA11yAction : undefined}
        testID={`${testID}-stage-touch`}
      >
        <View style={partStyles.running} pointerEvents="none">
          {renderStage(layers)}
        </View>
      </View>
      <View
        style={brushStyles.track}
        accessible
        accessibilityRole="progressbar"
        accessibilityLabel={texts.bar(progress.percent)}
        accessibilityValue={{ min: 0, max: 100, now: progress.percent }}
        testID={`${testID}-bar`}
      >
        <View style={[brushStyles.fill, { width: `${Math.round(progress.fraction * 100)}%` }]} testID={`${testID}-bar-fill`} />
      </View>
      {!hideHint && (
        <Text style={[partStyles.muted, brushStyles.center, progress.allClean && partStyles.doneText]} testID={`${testID}-brush-hint`} accessibilityLiveRegion="polite">
          {progress.allClean ? texts.clean : holdMode ? texts.holdHint : texts.swipeHint}
        </Text>
      )}
      {holdMode && !hideButton && (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={strokeA11y}
          accessibilityHint={texts.holdA11yHint}
          accessibilityState={{ disabled }}
          disabled={disabled}
          onPress={onPress}
          onPressIn={onPressIn}
          onPressOut={onPressOut}
          testID={`${testID}-stroke`}
          style={({ pressed }) => [partStyles.secondaryButton, disabled && partStyles.disabled, pressed && !disabled && partStyles.pressed]}
        >
          <Hand color={palette.mint} size={18} />
          <Text style={partStyles.secondaryText}>{strokeLabel}</Text>
        </Pressable>
      )}
    </>
  );
}

const brushStyles = StyleSheet.create({
  track: { height: 10, borderRadius: radius.pill, backgroundColor: alpha(palette.white, 0.12), overflow: 'hidden' },
  fill: { height: '100%', borderRadius: radius.pill, backgroundColor: palette.mint },
  center: { textAlign: 'center' },
});
