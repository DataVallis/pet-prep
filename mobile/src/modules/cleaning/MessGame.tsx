/**
 * Cleaning on the native game layer (GAMES_SPEC §6.2, phase 1; David 2026-10-30).
 *
 * Reached ONLY through `loadMessGames()` when `gameLayerAvailable()` — imports gesture-handler,
 * reanimated, skia, expo-audio and expo-haptics (an older binary keeps `CleaningOverlay`).
 *
 * Stages from `messPlan.ts#messStages` (poop first, then the puddle); ONE `onCleaned()` at the very
 * end → the HUD sends `POST /api/child/pet/clean`, exactly as before (server unchanged).
 * - Poop (and a cat's mess next to the tray): drag the bag over each swirl → it squeezes into the
 *   bag (250 ms); the last one → the bag ties itself and flies into the bin; mint sparkles.
 * - Puddle: wipe with the cloth, scratch-card style (Skia layer + clear strokes, coverage in
 *   `wipe.ts`) until ~90 % → it shrinks and dries, the cloth goes into the bucket; sparkles.
 * - Tap works too (tap a swirl = the bag jumps there; tap the puddle = one dab). »Lažje kretnje« /
 *   screen reader: a labelled button (bag: one piece per press; cloth: hold to wipe, activate =
 *   a big wipe). Reduce motion: nothing squeezes or flies — pieces just disappear.
 * - First clean of a kind: the teaching tip under the top bar (`cleaningTips.ts`, dogs only).
 * - Sounds + positive haptics through `useGameFx` (off with the parent's switch / silent mode).
 */

import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { StyleSheet, View } from 'react-native';
import { Canvas, Group, Oval, Path } from '@shopify/react-native-skia';
import { useDerivedValue, useSharedValue, withSpring, withTiming, type SharedValue } from 'react-native-reanimated';
import { scheduleOnRN } from 'react-native-worklets';
import { Brush, PaintBucket, ShoppingBag, Trash2 } from 'lucide-react-native';

import { BagGraphic, ClothGraphic, PoopGraphic } from '@/components/MessGraphics';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { MESS_GAME_STRINGS } from '@/modules/cleaning/cleaningStrings';
import { tipKindFor, useFirstTip } from '@/modules/cleaning/cleaningTips';
import { allDone, countBits, hasBit, pieceUnder } from '@/modules/cleaning/hits';
import { PEE_PATHS } from '@/modules/cleaning/messArt';
import { FLOOR_Y, piecePlacements, stageDots, type MessStage } from '@/modules/cleaning/messPlan';
import { LIFT, MessButton, MessField, MessStage as MessStageVideo, MintSparkles, Sprite, Target, TopArea, sendAway, usePieceAnim, type FieldLayout } from '@/modules/cleaning/MessParts';
import { AUTO_WIPE_RADIUS, autoWipeStroke, createWipeGrid, isWiped, wipeSegment, wipeStep, type WipeGrid } from '@/modules/cleaning/wipe';
import FeedbackLayer, { type FeedbackHandle } from '@/modules/gameLayer/FeedbackLayer';
import { gameFeedbackOf } from '@/modules/gameLayer/gameFeedback';
import type { GameFx } from '@/modules/gameLayer/gameFxCore';
import { useGameLine } from '@/modules/gameLayer/GameLine';
import GameScreen from '@/modules/gameLayer/GameScreen';
import GameTopBar from '@/modules/gameLayer/GameTopBar';
import { useGameFx } from '@/modules/gameLayer/useGameFx';
import { useGameGestures, type GameGestureHandlers } from '@/modules/gameLayer/useGameGestures';
import { alpha, mess, palette } from '@/theme';

export interface MessGameProps {
  view: ChildPetView;
  stages: readonly MessStage[];
  reduceMotion: boolean;
  /** Every stage is clean (called once) → the HUD sends `POST /clean`. */
  onCleaned: () => void;
  /** Omitted while the mess must be cleaned (hygiene 0 %, PRODUCT_SPEC §8) — the × is locked. */
  onClose?: () => void;
  /**
   * The escape from a forced mess (no `onClose`): the locked × after `GAME_ESCAPE_MS`, Android back
   * after that, the watchdog card. The HUD switches to the plain tap cleaning — the mess still
   * has to be cleaned, but never inside a game that may be broken.
   */
  onEscape?: () => void;
  onMediaExpired?: () => void;
  testID?: string;
}

type StageLabel = 'bagged' | 'wiped';

interface StageCtx {
  active: boolean;
  layout: FieldLayout | null;
  reduceMotion: boolean;
  fx: GameFx;
  view: ChildPetView;
  onProgress: (done: number) => void;
  onDone: (label: StageLabel) => void;
  testID: string;
}

interface StageUi {
  field: ReactNode;
  dock: ReactNode;
  easy: boolean;
}

/** Timers of a stage, cleared on unmount. */
function useTimers(): (fn: () => void, ms: number) => void {
  const timers = useRef<ReturnType<typeof setTimeout>[]>([]);
  useEffect(() => () => timers.current.forEach(clearTimeout), []);
  return useCallback((fn: () => void, ms: number) => {
    timers.current.push(setTimeout(fn, ms));
  }, []);
}

// ── Poop: the bag ─────────────────────────────────────────────

const POOP_W = 0.28;

function PoopPiece({ x, y, w, flip, anim, testID }: { x: number; y: number; w: number; flip: boolean; anim: ReturnType<typeof usePieceAnim>; testID: string }) {
  const px = useDerivedValue(() => x + anim.tx.value);
  const py = useDerivedValue(() => y + anim.ty.value);
  return (
    <Sprite x={px} y={py} width={w} height={(w * 80) / 120} scale={anim.scale} opacity={anim.opacity} flip={flip} testID={testID}>
      <PoopGraphic size={w} testID={`${testID}-art`} />
    </Sprite>
  );
}

function usePoopStage(stage: MessStage, ctx: StageCtx): StageUi {
  const { active, layout, reduceMotion, fx, onProgress, onDone, testID } = ctx;
  const W = layout?.width ?? 0;
  const H = layout?.height ?? 0;
  const count = stage.kind === 'poop' ? stage.pieces : 0;
  const place = useMemo(() => piecePlacements(Math.max(1, count), stage.seed).slice(0, count), [count, stage.seed]);
  const xs = useMemo(() => place.map((p) => p.x * W), [place, W]);
  const ys = useMemo(() => place.map((p) => p.y * H), [place, H]);
  const pieceW = W * POOP_W;
  const radius = Math.max(44, pieceW * 0.42);
  const home = { x: W * 0.2, y: H * 0.86 };
  const bin = { x: W * 0.8, y: H * 0.86 };

  const xsSV = useSharedValue<number[]>([]);
  const ysSV = useSharedValue<number[]>([]);
  const homeX = useSharedValue(0);
  const homeY = useSharedValue(0);
  const bagX = useSharedValue(0);
  const bagY = useSharedValue(0);
  const bagScale = useSharedValue(1);
  const bagOpacity = useSharedValue(1);
  const doneMask = useSharedValue(0);
  const open = useSharedValue(false);
  const dragging = useSharedValue(false);
  const radiusSV = useSharedValue(44);
  const a0 = usePieceAnim();
  const a1 = usePieceAnim();
  const a2 = usePieceAnim();
  const anims = useMemo(() => [a0, a1, a2], [a0, a1, a2]);
  const [tied, setTied] = useState(false);
  const [sparkle, setSparkle] = useState(0);
  const doneRef = useRef(0);
  const finished = useRef(false);
  const later = useTimers();

  useEffect(() => {
    xsSV.value = xs;
    ysSV.value = ys;
    homeX.value = home.x;
    homeY.value = home.y;
    radiusSV.value = radius;
    if (!dragging.value && !finished.current) {
      bagX.value = home.x;
      bagY.value = home.y;
    }
  }, [xs, ys, home.x, home.y, radius, xsSV, ysSV, homeX, homeY, radiusSV, bagX, bagY, dragging]);
  useEffect(() => {
    open.value = active && W > 0 && !finished.current;
  }, [active, W, open]);

  const finish = useCallback(() => {
    finished.current = true;
    open.value = false;
    setTied(true);
    later(() => {
      fx.sound('whoosh');
      if (reduceMotion) {
        bagOpacity.value = 0;
      } else {
        bagX.value = withTiming(bin.x, { duration: 600 });
        bagY.value = withTiming(bin.y - 8, { duration: 600 });
        bagScale.value = withTiming(0.55, { duration: 600 });
        bagOpacity.value = withTiming(0, { duration: 700 });
      }
    }, 300);
    later(() => {
      setSparkle((n) => n + 1);
      fx.sound('chime');
      fx.haptic('success');
      onDone('bagged');
    }, reduceMotion ? 350 : 950);
  }, [later, fx, reduceMotion, bagOpacity, bagX, bagY, bagScale, bin.x, bin.y, open, onDone]);

  const collect = useCallback(
    (i: number, bx: number, by: number) => {
      if (i < 0 || i >= count || hasBit(doneRef.current, i)) return;
      doneRef.current |= 1 << i;
      doneMask.value = doneRef.current;
      fx.sound('pop');
      fx.haptic('tap');
      sendAway(anims[i], bx - xs[i], by - ys[i], reduceMotion);
      onProgress(countBits(doneRef.current));
      if (allDone(doneRef.current, count)) finish();
    },
    [count, doneMask, fx, anims, xs, ys, reduceMotion, onProgress, finish],
  );

  const handlers = useMemo<GameGestureHandlers>(
    () => ({
      onPanBegin: (e) => {
        'worklet';
        if (!open.value) return;
        dragging.value = true;
        bagX.value = e.x;
        bagY.value = e.y - LIFT;
      },
      onPanUpdate: (e) => {
        'worklet';
        if (!dragging.value || !open.value) return;
        bagX.value = e.x;
        bagY.value = e.y - LIFT;
        const i = pieceUnder(xsSV.value, ysSV.value, doneMask.value, e.x, e.y - LIFT, radiusSV.value);
        if (i >= 0) {
          doneMask.value = doneMask.value | (1 << i);
          scheduleOnRN(collect, i, e.x, e.y - LIFT);
        }
      },
      onPanEnd: () => {
        'worklet';
        dragging.value = false;
        if (open.value) {
          bagX.value = withSpring(homeX.value);
          bagY.value = withSpring(homeY.value);
        }
      },
      onTap: (e) => {
        'worklet';
        if (!open.value) return;
        const i = pieceUnder(xsSV.value, ysSV.value, doneMask.value, e.x, e.y, radiusSV.value * 1.3);
        if (i < 0) return;
        doneMask.value = doneMask.value | (1 << i);
        bagX.value = xsSV.value[i];
        bagY.value = ysSV.value[i];
        scheduleOnRN(collect, i, xsSV.value[i], ysSV.value[i]);
      },
    }),
    [open, dragging, bagX, bagY, xsSV, ysSV, doneMask, radiusSV, homeX, homeY, collect],
  );
  const gestures = useGameGestures(ctx.view, handlers);
  const easy = gestures.inputMode === 'hold';

  const pickNext = () => {
    for (let i = 0; i < count; i++) {
      if (hasBit(doneRef.current, i)) continue;
      bagX.value = reduceMotion ? xs[i] : withTiming(xs[i], { duration: 200 });
      bagY.value = reduceMotion ? ys[i] : withTiming(ys[i], { duration: 200 });
      collect(i, xs[i], ys[i]);
      return;
    }
  };

  const S = MESS_GAME_STRINGS.poop;
  const bagW = Math.max(64, W * 0.18);
  const field =
    layout === null ? null : (
      <>
        <Target x={bin.x} y={bin.y} testID={`${testID}-bin`}>
          <Trash2 color={palette.n200} size={30} />
        </Target>
        {place.map((p, i) => (
          <PoopPiece key={i} x={xs[i]} y={ys[i]} w={pieceW * p.scale} flip={p.flip} anim={anims[i]} testID={`${testID}-poop-${i}`} />
        ))}
        <Sprite x={bagX} y={bagY} width={bagW} height={bagW} scale={bagScale} opacity={bagOpacity} testID={`${testID}-bag`}>
          <BagGraphic size={bagW} tied={tied} />
        </Sprite>
        <MintSparkles at={sparkle > 0 ? bin : null} burst={sparkle} reduceMotion={reduceMotion} />
      </>
    );
  const dock =
    easy && active && !tied ? (
      <MessButton label={S.button} a11y={S.a11y} icon={<ShoppingBag color={palette.graphite} size={22} />} onPress={pickNext} testID={`${testID}-bag-button`} />
    ) : null;
  return { field: active ? <MessField gesture={gestures.gesture} testID={`${testID}-field-poop`}>{field}</MessField> : null, dock, easy };
}

// ── Puddle: the cloth ─────────────────────────────────────────

const PUDDLE_W = 0.72;
const CLOTH_R = 34;
const HOLD_TICK_MS = 110;
/** A screen reader's activate (no press-in) wipes this many auto strokes at once. */
const ACTIVATE_STROKES = 10;

/** Box of the puddle's body ellipse inside the 120 × 80 drawing (x 8–112, y 30–72). */
const BODY = { x: 8, y: 30, w: 104, h: 42 } as const;

export function puddleGeometry(W: number, H: number) {
  const w = W * PUDDLE_W;
  const h = (w * 80) / 120;
  const x0 = W / 2 - w / 2;
  const y0 = H * FLOOR_Y + H * 0.02 - h / 2;
  const s = w / 120;
  return { w, h, x0, y0, s, cx: W / 2, cy: y0 + h / 2, body: { x: x0 + BODY.x * s, y: y0 + BODY.y * s, w: BODY.w * s, h: BODY.h * s } };
}

function PuddleCanvas({ geo, wipe, scale, opacity }: { geo: ReturnType<typeof puddleGeometry>; wipe: SharedValue<string>; scale: SharedValue<number>; opacity: SharedValue<number> }) {
  const transform = useDerivedValue(() => [
    { translateX: geo.cx },
    { translateY: geo.cy },
    { scale: scale.value },
    { translateX: -geo.cx },
    { translateY: -geo.cy },
  ]);
  const art = [{ translateX: geo.x0 }, { translateY: geo.y0 }, { scale: geo.s }];
  return (
    <Canvas style={StyleSheet.absoluteFill} pointerEvents="none">
      <Group layer opacity={opacity} transform={transform}>
        <Group transform={art}>
          <Path path={PEE_PATHS.body} color={alpha(mess.puddle, 0.7)} />
          <Path path={PEE_PATHS.body} color={mess.puddleEdge} style="stroke" strokeWidth={2} />
          <Path path={PEE_PATHS.rim} color={alpha(mess.puddleEdge, 0.55)} style="stroke" strokeWidth={1.5} />
          {PEE_PATHS.shine.map((o, i) => (
            <Oval key={i} x={o.cx - o.rx} y={o.cy - o.ry} width={o.rx * 2} height={o.ry * 2} color={alpha(palette.white, 0.5)} />
          ))}
          <Path path={PEE_PATHS.drop} color={mess.puddle} />
          <Path path={PEE_PATHS.drop} color={mess.puddleEdge} style="stroke" strokeWidth={2} />
        </Group>
        {/* Scratch-card: every cloth stroke clears the puddle under it. */}
        <Path path={wipe} style="stroke" strokeWidth={CLOTH_R * 2} strokeCap="round" strokeJoin="round" color={palette.black} blendMode="clear" />
      </Group>
    </Canvas>
  );
}

function usePuddleStage(stage: MessStage, ctx: StageCtx): StageUi {
  const { active, layout, reduceMotion, fx, onProgress, onDone, testID } = ctx;
  const W = layout?.width ?? 0;
  const H = layout?.height ?? 0;
  const geo = useMemo(() => puddleGeometry(W, H), [W, H]);
  const home = { x: W * 0.2, y: H * 0.86 };
  const bucket = { x: W * 0.8, y: H * 0.86 };
  const wipe = useSharedValue('M -100 -100');
  const clothX = useSharedValue(0);
  const clothY = useSharedValue(0);
  const clothOpacity = useSharedValue(1);
  const homeX = useSharedValue(0);
  const homeY = useSharedValue(0);
  const puddleScale = useSharedValue(1);
  const puddleOpacity = useSharedValue(1);
  const open = useSharedValue(false);
  const dragging = useSharedValue(false);
  const lastX = useSharedValue(0);
  const lastY = useSharedValue(0);
  const grid = useRef<WipeGrid>(createWipeGrid());
  const step = useRef(0);
  const autoStep = useRef(0);
  const finished = useRef(false);
  const [sparkle, setSparkle] = useState(0);
  const holdTimer = useRef<ReturnType<typeof setInterval> | null>(null);
  const later = useTimers();

  useEffect(() => {
    homeX.value = home.x;
    homeY.value = home.y;
    if (!dragging.value && !finished.current) {
      clothX.value = home.x;
      clothY.value = home.y;
    }
  }, [home.x, home.y, homeX, homeY, clothX, clothY, dragging]);
  useEffect(() => {
    open.value = active && W > 0 && !finished.current;
  }, [active, W, open]);
  useEffect(
    () => () => {
      if (holdTimer.current !== null) clearInterval(holdTimer.current);
    },
    [],
  );

  const finish = useCallback(() => {
    finished.current = true;
    open.value = false;
    if (holdTimer.current !== null) clearInterval(holdTimer.current);
    if (reduceMotion) {
      puddleOpacity.value = 0;
      clothOpacity.value = 0;
    } else {
      puddleScale.value = withTiming(0.3, { duration: 700 });
      puddleOpacity.value = withTiming(0, { duration: 700 });
      clothX.value = withTiming(bucket.x, { duration: 500 });
      clothY.value = withTiming(bucket.y, { duration: 500 });
      clothOpacity.value = withTiming(0, { duration: 600 });
    }
    later(() => {
      setSparkle((n) => n + 1);
      fx.sound('chime');
      fx.haptic('success');
      onDone('wiped');
    }, reduceMotion ? 300 : 750);
  }, [open, reduceMotion, puddleOpacity, clothOpacity, puddleScale, clothX, clothY, bucket.x, bucket.y, later, fx, onDone]);

  /** Apply a stroke in puddle units to the coverage grid. */
  const applyUnits = useCallback(
    (u0: number, v0: number, u1: number, v1: number, ru: number, rv: number) => {
      if (finished.current) return;
      grid.current = wipeSegment(grid.current, u0, v0, u1, v1, ru, rv);
      const s = wipeStep(grid.current);
      if (s !== step.current) {
        if (s > step.current && s % 2 === 0) fx.haptic('tap');
        step.current = s;
        onProgress(s);
      }
      if (isWiped(grid.current)) finish();
    },
    [fx, onProgress, finish],
  );

  /** A cloth stroke in field points (from the gesture). */
  const wipeField = useCallback(
    (x0: number, y0: number, x1: number, y1: number) => {
      const b = geo.body;
      if (b.w <= 0) return;
      applyUnits((x0 - b.x) / b.w, (y0 - b.y) / b.h, (x1 - b.x) / b.w, (y1 - b.y) / b.h, CLOTH_R / b.w, CLOTH_R / b.h);
    },
    [geo, applyUnits],
  );

  const handlers = useMemo<GameGestureHandlers>(
    () => ({
      onPanBegin: (e) => {
        'worklet';
        if (!open.value) return;
        dragging.value = true;
        clothX.value = e.x;
        clothY.value = e.y;
        lastX.value = e.x;
        lastY.value = e.y;
        wipe.value = `${wipe.value} M ${Math.round(e.x)} ${Math.round(e.y)}`;
        scheduleOnRN(wipeField, e.x, e.y, e.x, e.y);
      },
      onPanUpdate: (e) => {
        'worklet';
        if (!dragging.value || !open.value) return;
        clothX.value = e.x;
        clothY.value = e.y;
        wipe.value = `${wipe.value} L ${Math.round(e.x)} ${Math.round(e.y)}`;
        scheduleOnRN(wipeField, lastX.value, lastY.value, e.x, e.y);
        lastX.value = e.x;
        lastY.value = e.y;
      },
      onPanEnd: () => {
        'worklet';
        dragging.value = false;
        if (open.value) {
          clothX.value = withSpring(homeX.value);
          clothY.value = withSpring(homeY.value);
        }
      },
      onTap: (e) => {
        'worklet';
        if (!open.value) return;
        wipe.value = `${wipe.value} M ${Math.round(e.x)} ${Math.round(e.y)} L ${Math.round(e.x) + 1} ${Math.round(e.y)}`;
        scheduleOnRN(wipeField, e.x, e.y, e.x, e.y);
      },
    }),
    [open, dragging, clothX, clothY, lastX, lastY, wipe, wipeField, homeX, homeY],
  );
  const gestures = useGameGestures(ctx.view, handlers);
  const easy = gestures.inputMode === 'hold';

  /** One automatic stroke (hold mode): drawn, the cloth moves along, coverage counted. */
  const autoWipe = useCallback(() => {
    const [u0, v0, u1, v1] = autoWipeStroke(autoStep.current++);
    const b = geo.body;
    const fx0 = b.x + u0 * b.w;
    const fy0 = b.y + v0 * b.h;
    const fx1 = b.x + u1 * b.w;
    const fy1 = b.y + v1 * b.h;
    wipe.value = `${wipe.value} M ${Math.round(fx0)} ${Math.round(fy0)} L ${Math.round(fx1)} ${Math.round(fy1)}`;
    clothX.value = fx1;
    clothY.value = fy1;
    applyUnits(u0, v0, u1, v1, AUTO_WIPE_RADIUS.u, AUTO_WIPE_RADIUS.v);
  }, [geo, wipe, clothX, clothY, applyUnits]);

  const pressedIn = useRef(false);
  const holdStart = () => {
    if (!open.value) return;
    pressedIn.current = true;
    autoWipe();
    if (holdTimer.current !== null) clearInterval(holdTimer.current);
    holdTimer.current = setInterval(autoWipe, HOLD_TICK_MS);
  };
  const holdEnd = () => {
    if (holdTimer.current !== null) clearInterval(holdTimer.current);
    holdTimer.current = null;
  };
  const activate = () => {
    // A screen reader's activate has no press-in: one big wipe per press.
    if (pressedIn.current) {
      pressedIn.current = false;
      return;
    }
    for (let i = 0; i < ACTIVATE_STROKES && !finished.current; i++) autoWipe();
  };

  const S = MESS_GAME_STRINGS.puddle;
  const clothW = Math.max(60, W * 0.17);
  const field =
    layout === null ? null : (
      <>
        <Target x={bucket.x} y={bucket.y} testID={`${testID}-bucket`}>
          <PaintBucket color={palette.n200} size={30} />
        </Target>
        <PuddleCanvas geo={geo} wipe={wipe} scale={puddleScale} opacity={puddleOpacity} />
        <Sprite x={clothX} y={clothY} width={clothW} height={clothW} opacity={clothOpacity} testID={`${testID}-cloth`}>
          <ClothGraphic size={clothW} />
        </Sprite>
        <MintSparkles at={sparkle > 0 ? { x: geo.cx, y: geo.cy } : null} burst={sparkle} reduceMotion={reduceMotion} />
      </>
    );
  const dock =
    easy && active && !finished.current ? (
      <MessButton
        label={S.button}
        a11y={S.a11y}
        icon={<Brush color={palette.graphite} size={22} />}
        onPressIn={holdStart}
        onPressOut={holdEnd}
        onPress={activate}
        testID={`${testID}-wipe-button`}
      />
    ) : null;
  void stage;
  return { field: active ? <MessField gesture={gestures.gesture} testID={`${testID}-field-puddle`}>{field}</MessField> : null, dock, easy };
}

// ── The game ──────────────────────────────────────────────────

const FEEDBACK_MS = 1_000;

export default function MessGame({ view, stages, reduceMotion, onCleaned, onClose, onEscape, onMediaExpired, testID = 'mess-game' }: MessGameProps) {
  const S = MESS_GAME_STRINGS;
  // Fixed when the game opens: a mess that appears meanwhile waits for the next cleaning.
  const [safeStages] = useState<readonly MessStage[]>(() => (stages.length > 0 ? stages : [{ kind: 'poop', pieces: 1, seed: 0 }]));
  const [index, setIndex] = useState(0);
  const [piecesDone, setPiecesDone] = useState(0);
  const [layout, setLayout] = useState<FieldLayout | null>(null);
  const stage = safeStages[Math.min(index, safeStages.length - 1)];
  const fx = useGameFx(gameFeedbackOf(view));
  const line = useGameLine();
  const feedback = useRef<FeedbackHandle>(null);
  const cleanedRef = useRef(onCleaned);
  cleanedRef.current = onCleaned;
  const reported = useRef(false);
  const tipKind = useFirstTip(tipKindFor(stage.kind, view.pet.species));
  const later = useTimers();

  const onProgress = useCallback(
    (done: number) => {
      // Poop: pieces; puddle: one dot, done only when dry.
      if (stage.kind === 'poop') setPiecesDone(done);
    },
    [stage.kind],
  );
  const onDone = useCallback(
    (label: StageLabel) => {
      setPiecesDone(stage.pieces);
      void feedback.current?.show('ok', { label: S.fb[label], totalMs: FEEDBACK_MS });
      later(() => {
        if (index + 1 < safeStages.length) {
          setIndex(index + 1);
          setPiecesDone(0);
          return;
        }
        if (reported.current) return;
        reported.current = true;
        cleanedRef.current();
      }, FEEDBACK_MS);
    },
    [stage.pieces, S, later, index, safeStages.length],
  );

  const base = { layout, reduceMotion, fx, view, onProgress, onDone, testID };
  const poopStage = stage.kind === 'poop' ? stage : { kind: 'poop' as const, pieces: 0, seed: 0 };
  const puddleStage = stage.kind === 'puddle' ? stage : { kind: 'puddle' as const, pieces: 1, seed: 0 };
  const poop = usePoopStage(poopStage, { ...base, active: stage.kind === 'poop' });
  const puddle = usePuddleStage(puddleStage, { ...base, active: stage.kind === 'puddle' });
  const ui = stage.kind === 'poop' ? poop : puddle;

  const say = line.say;
  useEffect(() => {
    const texts = stage.kind === 'poop' ? S.poop : S.puddle;
    say(ui.easy ? texts.holdLine : texts.line);
  }, [stage.kind, ui.easy, say, S]);

  const dots = stageDots(safeStages, index, piecesDone);
  const total = dots.length;
  const done = dots.filter(Boolean).length;
  const tipText = tipKind === null ? null : S.tips[tipKind];

  return (
    <GameScreen
      testID={testID}
      onExit={onClose ?? onEscape}
      stage={<MessStageVideo view={view} onMediaExpired={onMediaExpired} />}
      field={
        <LayoutProbe onLayout={setLayout} testID={`${testID}-layout`}>
          {ui.field}
        </LayoutProbe>
      }
      feedback={<FeedbackLayer ref={feedback} reduceMotion={reduceMotion} testID={`${testID}-feedback`} />}
      topBar={
        <TopArea
          tip={tipText}
          bar={
            <GameTopBar
              dots={dots}
              stars={0}
              line={line.text}
              closeLabel={S.close}
              progressLabel={S.a11y.progress(done, total)}
              onClose={onClose ?? onEscape}
              closeLocked={onClose === undefined}
              testID={`${testID}-top`}
            />
          }
        />
      }
      dock={ui.dock}
    />
  );
}

/** Measures the full field once (the stages draw in these coordinates). */
function LayoutProbe({ onLayout, children, testID }: { onLayout: (l: FieldLayout) => void; children: ReactNode; testID: string }) {
  return (
    <View
      style={StyleSheet.absoluteFill}
      pointerEvents="box-none"
      onLayout={(e) => {
        const { width, height } = e.nativeEvent.layout;
        onLayout({ width, height });
      }}
      testID={testID}
    >
      {children}
    </View>
  );
}
