/**
 * »Zamenjaj ves pesek« on the game layer — three steps, three gestures (David 2026-11-15: »Želim
 * da je bolj interaktivno«; drawn animation that follows the finger, no paid media):
 *
 *   1. Izprazni — drag the tray down to the bin; it tilts with the drag and the old litter pours
 *      into the bin while it is tipped (2–3 drags).
 *   2. Umij    — rub inside the tray; foam follows the finger and the dirt spots fade where rubbed.
 *   3. Napolni — press and hold (the bag or the tray); fresh litter pours while held and stops
 *      at the marked line; letting go pauses.
 *
 * Rules, thresholds and the mapping to the server's strokes are pure in `litterChange.ts` (NO
 * server change: the session's three equal parts are the three steps, `ChoreOverlay` still sends
 * `strokes`). This file only wires gestures (UI-thread worklets) to shared values (the drawing)
 * and to the JS progress (`LitterProgress`: ✓ per step, the line, the screen-reader readout).
 * The progress is shown BY the drawing — the litter level, the spots that are left, the fill line.
 *
 * - Hold mode (»Lažje kretnje« / screen reader): one labelled button per step (»Izprazni« /
 *   »Umij« / »Napolni«) does the same work in equal beats at the irregular `holdGap`s; the
 *   gestures stay on.
 * - Reduce motion: same gestures; no falling grains loop, no spring back, no sparkle, the level
 *   moves in steps instead of gliding.
 * - Nothing the child aims at is within 24 dp of the screen edge (`EDGE_PT` inside the shell's
 *   inset field) — Android's back gesture starts there.
 *
 * Reached ONLY through `loadLitterChangeGame()` behind `gameLayerAvailable()` (imports reanimated
 * and gesture-handler); an older binary keeps the card screen with the rub.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import Animated, { cancelAnimation, Easing, useAnimatedStyle, useSharedValue, withDelay, withRepeat, withSpring, withTiming, type SharedValue } from 'react-native-reanimated';
import { scheduleOnRN } from 'react-native-worklets';
import { ArrowDownToLine, Droplets, PackageOpen } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { CAT_COMMON_STRINGS, CHORE_STRINGS, type ChoreSession } from '@/modules/catCare/catCare';
import { HOLD_START_MS, holdGap } from '@/modules/catCare/brushing';
import { choreProgress, choreStepAt, sessionTime } from '@/modules/catCare/catGames';
import { BAG, BIN, BIN_HEAP, FILL_LINE, FOAM, FRESH_LITTER, GRAIN, OLD_LITTER, SPOT, SUDS, TRAY_BACK, TRAY_FRONT } from '@/modules/catCare/litterChange/litterArt';
import { ArtView } from '@/modules/catCare/litterChange/LitterGraphics';
import {
  BAG_TILT_DEG,
  FILL_FIRST_BEAT_MS,
  FRESH_FILL,
  LITTER_START,
  OLD_FILL,
  SPOT_DRAW,
  SPOTS,
  TRAY_ART,
  afterFill,
  afterPour,
  afterScrub,
  bagSpout,
  cavityPoint,
  cleanMask,
  dirtySpots,
  dragProgress,
  fillLevel,
  fillRemainingMs,
  floorTop,
  freshSurfaceY,
  holdBeat,
  leftAfter,
  litterFrame,
  litterLayout,
  litterReadout,
  pourChunks,
  rubBegin,
  rubEnd,
  rubMove,
  scrubSpots,
  settleBefore,
  stepDone,
  tiltAt,
  trayLip,
  type LitterLayout,
  type LitterProgress,
  type LitterStep,
  type Rub,
} from '@/modules/catCare/litterChange/litterChange';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { MessField, MintSparkles, type FieldLayout } from '@/modules/cleaning/MessParts';
import { gameFeedbackOf } from '@/modules/gameLayer/gameFeedback';
import type { CareGameShellType } from '@/modules/gameLayer/loadCareShell';
import { useGameGestures, type GameGestureHandlers } from '@/modules/gameLayer/useGameGestures';
import { fonts, palette, radius, tightTracking } from '@/theme';

const S = CHORE_STRINGS;
const C = CAT_COMMON_STRINGS;
const GRAINS = [0, 1, 2, 3, 4, 5, 6] as const;
const NO_DIRT: readonly number[] = SPOTS.map(() => 0);
const ALL_CLEAN = (1 << SPOTS.length) - 1;
const NO_RUB: Rub = { sx: 0, sy: 0, fx: 0, fy: 0, fd: 0, path: 0, lx: 0, ly: 0 };

export interface LitterChangeGameProps {
  /** The care games' shell (top line, dots, ✓, haptics, ×, Android back = a hint). */
  Shell: CareGameShellType;
  view: ChildPetView;
  session: ChoreSession;
  strokes: readonly number[];
  elapsedMs: number;
  finishing: boolean;
  reduceMotion: boolean;
  msAt: (touchTimestamp?: number | null) => number;
  onStroke: (ms: number) => void;
  onStop?: () => void;
  testID: string;
}

/** One falling grain of the old litter: from the tray's tipped corner into the bin. */
function PourGrain({ i, L, d, loop, on }: { i: number; L: LitterLayout; d: SharedValue<number>; loop: SharedValue<number>; on: SharedValue<number> }) {
  const style = useAnimatedStyle(() => {
    const lip = trayLip(L, d.value);
    const p = (loop.value + i / GRAINS.length) % 1;
    return {
      opacity: on.value,
      transform: [
        { translateX: lip.x + (L.bin.mouthX - lip.x) * p + (i % 2 === 0 ? -3 : 3) - 3.5 },
        { translateY: lip.y + (L.bin.mouthY - lip.y) * p * p - 3.5 },
      ],
    };
  });
  return <Animated.View pointerEvents="none" style={[styles.grain, styles.grainOld, style]} />;
}

/** One grain of the fresh litter: from the bag's corner down to the litter's surface. */
function StreamGrain({ i, L, x, y, fill, loop, on }: { i: number; L: LitterLayout; x: number; y: number; fill: SharedValue<number>; loop: SharedValue<number>; on: SharedValue<number> }) {
  const style = useAnimatedStyle(() => {
    const p = (loop.value + i / GRAINS.length) % 1;
    return {
      opacity: on.value,
      transform: [{ translateX: x + (i % 2 === 0 ? -2.5 : 2.5) - 3.5 }, { translateY: y + (freshSurfaceY(L, fill.value) - y) * p - 3.5 }],
    };
  });
  return <Animated.View pointerEvents="none" style={[styles.grain, styles.grainFresh, style]} />;
}

/** A dirt spot (fades with the rubbing) and the suds left where it was. */
function Spot({ i, left, top, width, dirt, suds, testID }: { i: number; left: number; top: number; width: number; dirt: SharedValue<readonly number[]>; suds: SharedValue<number>; testID: string }) {
  const spotStyle = useAnimatedStyle(() => ({ opacity: dirt.value[i] }));
  const sudsStyle = useAnimatedStyle(() => ({ opacity: dirt.value[i] <= 0 ? suds.value : 0 }));
  return (
    <>
      <Animated.View pointerEvents="none" style={[styles.abs, { left, top }, spotStyle]} testID={testID}>
        <ArtView art={SPOT} width={width} />
      </Animated.View>
      <Animated.View pointerEvents="none" style={[styles.abs, { left, top }, sudsStyle]}>
        <ArtView art={SUDS} width={width} />
      </Animated.View>
    </>
  );
}

/** One press-and-hold of step 3 (JS timers; times on the session clock). */
interface Pour {
  start: number;
  base: number;
  /** When this hold's current beat interval began. */
  beatFrom: number;
  beat: ReturnType<typeof setTimeout> | null;
  end: ReturnType<typeof setTimeout> | null;
}

const STEP_ICONS = { 1: ArrowDownToLine, 2: Droplets, 3: PackageOpen } as const;

export default function LitterChangeGame({ Shell, view, session, strokes, elapsedMs, finishing, reduceMotion, msAt, onStroke, onStop, testID }: LitterChangeGameProps) {
  const time = sessionTime(session.duration_ms, elapsedMs);
  const step: LitterStep = choreStepAt(session, elapsedMs);
  const disabled = finishing || time.over;
  const counted = choreProgress(session, strokes).strokes;

  const [layout, setLayout] = useState<FieldLayout | null>(null);
  const L = useMemo(() => litterLayout(layout?.width ?? 1, layout?.height ?? 1), [layout]);
  const k = L.trayW / TRAY_ART.w;
  const cav = { x: TRAY_ART.cavity.x * k, y: TRAY_ART.cavity.y * k, w: TRAY_ART.cavity.w * k, h: TRAY_ART.cavity.h * k };

  // ── JS progress (the truth for ✓, the line and the readout) ──
  const [p, setP] = useState<LitterProgress>(LITTER_START);
  const pRef = useRef(p);
  const update = useCallback((fn: (q: LitterProgress) => LitterProgress): LitterProgress => {
    const next = fn(pRef.current);
    if (next !== pRef.current) {
      pRef.current = next;
      setP(next);
    }
    return next;
  }, []);
  const stepRef = useRef(step);
  stepRef.current = step;
  const disabledRef = useRef(disabled);
  disabledRef.current = disabled;
  const msAtRef = useRef(msAt);
  msAtRef.current = msAt;
  const onStrokeRef = useRef(onStroke);
  onStrokeRef.current = onStroke;
  const reduceRef = useRef(reduceMotion);
  reduceRef.current = reduceMotion;
  /** One stroke for the server: a piece of real work, at its real time. */
  const stroke = useCallback(() => {
    if (!disabledRef.current) onStrokeRef.current(msAtRef.current(null));
  }, []);

  // ── Shared values (the drawing, on the UI thread) ──
  const open = useSharedValue(false);
  const stepSV = useSharedValue<number>(step);
  const touchStep = useSharedValue(0);
  /** Tray: how far on its way to the bin (0…1), and how far enlarged for washing / filling (0…1). */
  const d = useSharedValue(0);
  const big = useSharedValue(0);
  const left = useSharedValue(1);
  const dragLeft = useSharedValue(1);
  const chunks = useSharedValue(0);
  const pouring = useSharedValue(0);
  const dirt = useSharedValue<readonly number[]>(dirtySpots());
  const clean = useSharedValue(0);
  const suds = useSharedValue(1);
  const rub = useSharedValue<Rub>(NO_RUB);
  const rubbing = useSharedValue(false);
  const lastU = useSharedValue(0);
  const lastV = useSharedValue(0);
  const foamX = useSharedValue(0);
  const foamY = useSharedValue(0);
  const foamOn = useSharedValue(0);
  const fill = useSharedValue(0);
  const bagTilt = useSharedValue(0);
  const stream = useSharedValue(0);
  const loop = useSharedValue(0);
  const reduce = useSharedValue(reduceMotion);

  useEffect(() => {
    reduce.value = reduceMotion;
    if (reduceMotion) return;
    loop.value = 0;
    loop.value = withRepeat(withTiming(1, { duration: 560, easing: Easing.linear }), -1);
    return () => cancelAnimation(loop);
  }, [reduceMotion, reduce, loop]);

  /** A value change that glides — or jumps with reduce motion. */
  const glide = useCallback((to: number, ms: number) => (reduceRef.current ? to : withTiming(to, { duration: ms })), []);

  // ── Step 1 (JS side): the pour reached `left` ──
  const onPoured = useCallback(
    (now: number) => {
      const before = pourChunks(pRef.current.left);
      const next = update((q) => afterPour(q, now));
      if (pourChunks(next.left) > before) stroke();
    },
    [update, stroke],
  );
  // ── Step 2 (JS side) ──
  const onCleaned = useCallback(
    (mask: number) => {
      const before = pRef.current.clean;
      const next = update((q) => afterScrub(q, mask));
      // A spot rubbed away is a piece of work too (next to the rub's own turns / lift).
      if (next.clean !== before) stroke();
    },
    [update, stroke],
  );

  // ── Step 3: press and hold pours (JS timers decide; the level glides on the UI thread) ──
  const pour = useRef<Pour | null>(null);
  /** Pour beats so far and the holding since the last one — kept across holds, so short presses add up to the same strokes as one long hold. */
  const beats = useRef({ n: 0, sinceMs: 0 });
  const stopPour = useCallback(
    (atLine = false) => {
      const h = pour.current;
      if (h === null) return;
      pour.current = null;
      if (h.beat !== null) clearTimeout(h.beat);
      if (h.end !== null) clearTimeout(h.end);
      const now = msAtRef.current(null);
      beats.current.sinceMs += Math.max(0, now - h.beatFrom);
      const level = atLine ? 1 : fillLevel(h.base, now - h.start);
      const next = update((q) => afterFill(q, level));
      cancelAnimation(fill);
      fill.value = next.fill;
      bagTilt.value = glide(0, 160);
      stream.value = 0;
    },
    [update, fill, bagTilt, stream, glide],
  );
  const startPour = useCallback(() => {
    if (pour.current !== null || disabledRef.current || stepRef.current !== 3 || pRef.current.fill >= 1) return;
    const base = pRef.current.fill;
    const start = msAtRef.current(null);
    const h: Pour = { start, base, beatFrom: start, beat: null, end: null };
    pour.current = h;
    const remaining = fillRemainingMs(base);
    if (!reduceRef.current) fill.value = withTiming(1, { duration: remaining, easing: Easing.linear });
    bagTilt.value = glide(1, 160);
    stream.value = 1;
    const gap = () => (beats.current.n === 0 ? FILL_FIRST_BEAT_MS : holdGap(beats.current.n));
    const beat = () => {
      if (pour.current !== h) return;
      stroke();
      beats.current = { n: beats.current.n + 1, sinceMs: 0 };
      h.beatFrom = msAtRef.current(null);
      const next = update((q) => afterFill(q, fillLevel(h.base, h.beatFrom - h.start)));
      if (reduceRef.current) fill.value = next.fill;
      h.beat = setTimeout(beat, gap());
    };
    h.beat = setTimeout(beat, Math.max(0, gap() - beats.current.sinceMs));
    h.end = setTimeout(() => stopPour(true), remaining);
  }, [fill, bagTilt, stream, glide, stroke, update, stopPour]);
  const endPour = useCallback(() => stopPour(), [stopPour]);

  // ── Hold mode: the step's button does the same work in beats ──
  const button = useRef<{ beats: number; timer: ReturnType<typeof setTimeout> | null }>({ beats: 0, timer: null });
  const spotAt = useCallback(
    (i: number) => {
      const kk = k * L.bigScale;
      return {
        x: L.big.x - (TRAY_ART.w / 2 - TRAY_ART.cavity.x) * kk + SPOTS[i].u * TRAY_ART.cavity.w * kk,
        y: L.big.y - (TRAY_ART.h / 2 - TRAY_ART.cavity.y) * kk + SPOTS[i].v * TRAY_ART.cavity.h * kk,
      };
    },
    [L, k],
  );
  const buttonBeat = useCallback((): boolean => {
    if (disabledRef.current) return false;
    const at = stepRef.current;
    const before = pRef.current;
    const next = update((q) => holdBeat(q, at));
    if (next === before) return false;
    stroke();
    if (at === 1) {
      left.value = glide(next.left, 300);
      chunks.value = pourChunks(next.left);
      pouring.value = 1;
      pouring.value = withDelay(350, withTiming(0, { duration: 160 }));
    } else if (at === 2) {
      const fresh = next.clean & ~before.clean;
      for (let i = 0; i < SPOTS.length; i++) {
        if ((fresh & (1 << i)) === 0) continue;
        const at2 = spotAt(i);
        foamX.value = at2.x;
        foamY.value = at2.y;
        break;
      }
      dirt.value = SPOTS.map((_, i) => ((next.clean & (1 << i)) !== 0 ? 0 : dirt.value[i]));
      clean.value = next.clean;
      foamOn.value = 1;
      foamOn.value = withDelay(350, withTiming(0, { duration: 160 }));
    } else {
      fill.value = glide(next.fill, 400);
    }
    return true;
  }, [update, stroke, glide, spotAt, left, chunks, pouring, foamX, foamY, foamOn, dirt, clean, fill]);
  const stopButton = useCallback(() => {
    if (button.current.timer !== null) clearTimeout(button.current.timer);
    button.current.timer = null;
    if (stepRef.current === 1) d.value = glide(0, 200);
    if (stepRef.current === 3 && pour.current === null) {
      bagTilt.value = glide(0, 160);
      stream.value = 0;
    }
  }, [d, bagTilt, stream, glide]);
  const scheduleButton = useCallback(
    (delay: number) => {
      button.current.timer = setTimeout(() => {
        button.current.timer = null;
        if (!buttonBeat()) return stopButton();
        button.current.beats += 1;
        scheduleButton(holdGap(button.current.beats));
      }, delay);
    },
    [buttonBeat, stopButton],
  );
  const onButtonIn = () => {
    if (disabledRef.current || stepDone(pRef.current, stepRef.current)) return;
    stopButton();
    button.current.beats = 0;
    // The picture of the work: the tray tips / the bag pours while the button is held.
    if (stepRef.current === 1) d.value = glide(0.9, 250);
    if (stepRef.current === 3) {
      bagTilt.value = glide(1, 160);
      stream.value = 1;
    }
    scheduleButton(HOLD_START_MS);
  };
  const onButtonPress = () => {
    // A tap / a screen-reader activation = one beat.
    if (button.current.beats === 0) buttonBeat();
    button.current.beats = 0;
  };

  // ── The session moved on / stopped ──
  useEffect(() => {
    stepSV.value = step;
    stopPour();
    stopButton();
    const next = update((q) => settleBefore(q, step));
    if (step >= 2) {
      d.value = glide(0, 200);
      left.value = glide(next.left, 300);
      chunks.value = pourChunks(next.left);
      big.value = glide(1, 350);
    }
    if (step >= 3) {
      dirt.value = NO_DIRT;
      clean.value = ALL_CLEAN;
      suds.value = glide(0, 400);
      foamOn.value = 0;
    }
    // Only when the step changes (the helpers are stable).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [step]);
  useEffect(() => {
    open.value = layout !== null && !disabled;
    if (disabled) {
      stopPour();
      stopButton();
    }
  }, [layout, disabled, open, stopPour, stopButton]);
  useEffect(
    () => () => {
      const h = pour.current;
      pour.current = null;
      if (h !== null && h.beat !== null) clearTimeout(h.beat);
      if (h !== null && h.end !== null) clearTimeout(h.end);
      if (button.current.timer !== null) clearTimeout(button.current.timer);
    },
    [],
  );

  // A step the child finished: the leftover spots go, a mint sparkle where it happened.
  const [sparkle, setSparkle] = useState<{ n: number; at: { x: number; y: number } | null }>({ n: 0, at: null });
  const doneCount = p.done.filter(Boolean).length;
  const seenDone = useRef(0);
  useEffect(() => {
    if (doneCount <= seenDone.current) return;
    seenDone.current = doneCount;
    if (pRef.current.done[1]) {
      dirt.value = NO_DIRT;
      clean.value = ALL_CLEAN;
    }
    const at = stepRef.current === 1 ? { x: L.bin.mouthX, y: L.bin.mouthY } : { x: L.big.x, y: L.big.y };
    setSparkle((s) => ({ n: s.n + 1, at }));
  }, [doneCount, dirt, clean, L]);

  // ── Gestures (UI thread) ──
  const handlers = useMemo<GameGestureHandlers>(
    () => ({
      onPanBegin: (e) => {
        'worklet';
        if (!open.value) return;
        touchStep.value = stepSV.value;
        if (stepSV.value === 1) {
          dragLeft.value = left.value;
        } else if (stepSV.value === 2) {
          const uv = cavityPoint(e.x, e.y, L.big.x, L.big.y, L.trayW, L.bigScale);
          lastU.value = uv.u;
          lastV.value = uv.v;
          rub.value = rubBegin(e.x, e.y);
          rubbing.value = true;
          foamX.value = e.x;
          foamY.value = e.y;
          foamOn.value = 1;
        } else {
          scheduleOnRN(startPour);
        }
      },
      onPanUpdate: (e) => {
        'worklet';
        if (!open.value || touchStep.value !== stepSV.value) return;
        if (stepSV.value === 1) {
          const at = dragProgress(e.translationX, e.translationY, L.ux, L.uy, L.pathLen);
          d.value = at;
          const now = leftAfter(dragLeft.value, at);
          if (now < left.value) {
            left.value = now;
            pouring.value = 1;
            pouring.value = withDelay(180, withTiming(0, { duration: 160 }));
            const n = pourChunks(now);
            if (n > chunks.value) {
              chunks.value = n;
              scheduleOnRN(onPoured, now);
            }
          }
        } else if (stepSV.value === 2 && rubbing.value) {
          const uv = cavityPoint(e.x, e.y, L.big.x, L.big.y, L.trayW, L.bigScale);
          const next = scrubSpots(dirt.value, lastU.value, lastV.value, uv.u, uv.v);
          lastU.value = uv.u;
          lastV.value = uv.v;
          if (next !== dirt.value) {
            dirt.value = next;
            const mask = cleanMask(next);
            if (mask !== clean.value) {
              clean.value = mask;
              scheduleOnRN(onCleaned, mask);
            }
          }
          const r = rubMove(rub.value, e.x, e.y);
          rub.value = r.rub;
          if (r.stroke) scheduleOnRN(stroke);
          foamX.value = e.x;
          foamY.value = e.y;
        }
      },
      onPanEnd: () => {
        'worklet';
        if (touchStep.value === 2 && stepSV.value === 2 && rubbing.value && open.value && rubEnd(rub.value)) scheduleOnRN(stroke);
      },
      onPanFinalize: () => {
        'worklet';
        if (touchStep.value === 1) {
          // What this drag poured stays out; the tray goes back for the next one.
          if (stepSV.value === 1) scheduleOnRN(onPoured, left.value);
          d.value = reduce.value ? 0 : withSpring(0, { damping: 18, stiffness: 180 });
        } else if (touchStep.value === 2) {
          rubbing.value = false;
          foamOn.value = reduce.value ? 0 : withTiming(0, { duration: 200 });
        } else if (touchStep.value === 3) {
          scheduleOnRN(endPour);
        }
        touchStep.value = 0;
      },
    }),
    [L, open, stepSV, touchStep, d, left, dragLeft, chunks, pouring, dirt, clean, rub, rubbing, lastU, lastV, foamX, foamY, foamOn, reduce, onPoured, onCleaned, stroke, startPour, endPour],
  );
  const gestures = useGameGestures(view, handlers);
  const easy = gestures.inputMode === 'hold';

  // ── Animated styles ──
  const trayStyle = useAnimatedStyle(() => {
    const b = big.value;
    const at = d.value * (1 - b);
    const cx = L.home.x + (L.pose.x - L.home.x) * at;
    const cy = L.home.y + (L.pose.y - L.home.y) * at;
    return {
      transform: [
        { translateX: cx + (L.big.x - cx) * b - L.trayW / 2 },
        { translateY: cy + (L.big.y - cy) * b - L.trayH / 2 },
        { rotate: `${tiltAt(at)}deg` },
        { scale: 1 + (L.bigScale - 1) * b },
      ],
    };
  });
  // The old litter stays level in the tipped tray and sinks as it pours out.
  const oldStyle = useAnimatedStyle(() => ({
    opacity: left.value > 0 ? 1 : 0,
    transform: [{ translateY: (1 - left.value * OLD_FILL) * cav.h }, { rotate: `${-tiltAt(d.value * (1 - big.value)) * 0.85}deg` }],
  }));
  const freshStyle = useAnimatedStyle(() => ({ opacity: fill.value > 0 ? 1 : 0, transform: [{ translateY: (1 - fill.value * FRESH_FILL) * cav.h }] }));
  const foamStyle = useAnimatedStyle(() => ({ opacity: foamOn.value, transform: [{ translateX: foamX.value - 36 }, { translateY: foamY.value - 36 }] }));
  const bagStyle = useAnimatedStyle(() => ({
    transform: [{ translateX: L.bag.x - L.bag.w / 2 }, { translateY: L.bag.y - L.bag.h / 2 }, { rotate: `${-BAG_TILT_DEG * bagTilt.value}deg` }],
  }));

  const key = String(step) as '1' | '2' | '3';
  const done = stepDone(p, step);
  const words = S.litter_change;
  const line = finishing ? C.finishing : done ? words.playDone[key] : easy ? words.playHold[key] : words.play[key];
  const readout = litterReadout(p, step);
  const readoutText = readout.kind === 'old' ? words.playReadout.old(readout.value) : readout.kind === 'spots' ? words.playReadout.spots(readout.value) : words.playReadout.fresh(readout.value);
  const spout = bagSpout(L);
  const spotW = cav.w * SPOT_DRAW;
  const litterW = cav.w * 1.5;
  const Icon = STEP_ICONS[step];

  const scene =
    layout === null ? null : (
      <>
        <View pointerEvents="none" style={[styles.floor, { top: floorTop(L, step), height: L.H }]} />
        {step === 1 && (
          <View pointerEvents="none" style={[styles.abs, { left: L.bin.x - L.bin.w / 2, top: L.bin.y - L.bin.h / 2 }]} testID={`${testID}-bin`}>
            <ArtView art={BIN} width={L.bin.w} />
            {p.left < 1 && (
              <View style={styles.abs}>
                <ArtView art={BIN_HEAP} width={L.bin.w} />
              </View>
            )}
          </View>
        )}
        <Animated.View pointerEvents="none" style={[styles.abs, { width: L.trayW, height: L.trayH }, trayStyle]} testID={`${testID}-tray`}>
          <View style={styles.abs}>
            <ArtView art={TRAY_BACK} width={L.trayW} />
          </View>
          <View style={[styles.abs, { left: cav.x, top: cav.y, width: cav.w, height: cav.h }]}>
            {SPOTS.map((s, i) => (
              <Spot key={i} i={i} left={s.u * cav.w - spotW / 2} top={s.v * cav.h - (spotW * 0.8) / 2} width={spotW} dirt={dirt} suds={suds} testID={`${testID}-spot-${i}`} />
            ))}
          </View>
          <View style={[styles.abs, styles.clip, { left: cav.x, top: cav.y, width: cav.w, height: cav.h }]}>
            <Animated.View style={[styles.abs, styles.litter, { left: -cav.w * 0.25, width: litterW }, oldStyle]} testID={`${testID}-old-litter`}>
              <ArtView art={OLD_LITTER} width={litterW} />
            </Animated.View>
            <Animated.View style={[styles.abs, { left: -cav.w * 0.25, width: litterW }, freshStyle]} testID={`${testID}-fresh-litter`}>
              <ArtView art={FRESH_LITTER} width={litterW} />
            </Animated.View>
          </View>
          {step === 3 && (
            <View style={[styles.abs, { left: cav.x, top: cav.y + (1 - FRESH_FILL) * cav.h - (cav.w * FILL_LINE.h) / FILL_LINE.w / 2 }]} testID={`${testID}-fill-line`}>
              <ArtView art={FILL_LINE} width={cav.w} />
            </View>
          )}
          <View style={styles.abs}>
            <ArtView art={TRAY_FRONT} width={L.trayW} testID="cat-tray" />
          </View>
        </Animated.View>
        {step === 1 && GRAINS.map((i) => <PourGrain key={i} i={i} L={L} d={d} loop={loop} on={pouring} />)}
        {step === 3 && (
          <>
            <Animated.View pointerEvents="none" style={[styles.abs, { width: L.bag.w, height: L.bag.h }, bagStyle]} testID={`${testID}-bag`}>
              <ArtView art={BAG} width={L.bag.w} />
            </Animated.View>
            {GRAINS.map((i) => (
              <StreamGrain key={i} i={i} L={L} x={spout.x} y={spout.y} fill={fill} loop={loop} on={stream} />
            ))}
          </>
        )}
        {step === 2 && (
          <Animated.View pointerEvents="none" style={[styles.abs, foamStyle]} testID={`${testID}-foam`}>
            <ArtView art={FOAM} width={72} />
          </Animated.View>
        )}
        <MintSparkles at={sparkle.at} burst={sparkle.n} reduceMotion={reduceMotion} />
      </>
    );

  const dock = easy ? (
    <Pressable
      testID={`${testID}-hold`}
      accessibilityRole="button"
      accessibilityLabel={words.playButtonA11y[key]}
      accessibilityValue={{ text: readoutText }}
      accessibilityState={{ disabled: disabled || done }}
      disabled={disabled || done}
      onPressIn={onButtonIn}
      onPressOut={stopButton}
      onPress={onButtonPress}
      style={({ pressed }) => [styles.button, (disabled || done) && styles.buttonOff, pressed && styles.pressed]}
    >
      <Icon color={palette.graphite} size={22} />
      <Text style={styles.buttonText}>{words.playButton[key]}</Text>
    </Pressable>
  ) : undefined;

  return (
    <Shell
      testID={`${testID}-layer`}
      stage={<View style={styles.stage} testID={`${testID}-stage`} />}
      line={line}
      frame={litterFrame(counted, p)}
      gameFeedback={gameFeedbackOf(view)}
      reduceMotion={reduceMotion}
      closeLabel={C.stopA11y}
      progressLabel={`${S.stepA11y(step)}. ${readoutText}`}
      onClose={finishing ? undefined : onStop}
      dock={dock}
    >
      <MessField gesture={gestures.gesture} onLayout={setLayout} testID={`${testID}-field`}>
        {scene}
      </MessField>
    </Shell>
  );
}

const styles = StyleSheet.create({
  stage: { ...StyleSheet.absoluteFill, backgroundColor: palette.graphite },
  abs: { position: 'absolute', left: 0, top: 0 },
  floor: { position: 'absolute', left: -40, right: -40, borderTopLeftRadius: 36, borderTopRightRadius: 36, backgroundColor: palette.n850 },
  clip: { overflow: 'hidden' },
  litter: { transformOrigin: 'top' },
  grain: { position: 'absolute', left: 0, top: 0, width: 7, height: 7, borderRadius: 3.5 },
  grainOld: { backgroundColor: GRAIN.old },
  grainFresh: { backgroundColor: GRAIN.fresh },
  button: {
    minHeight: 60,
    minWidth: 200,
    paddingHorizontal: 24,
    borderRadius: radius.button,
    backgroundColor: palette.mint,
    flexDirection: 'row',
    gap: 10,
    alignItems: 'center',
    justifyContent: 'center',
  },
  buttonOff: { opacity: 0.45 },
  buttonText: { color: palette.graphite, fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.displayBold },
  pressed: { opacity: 0.8 },
});
