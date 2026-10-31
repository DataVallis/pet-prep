/**
 * »Palica s peresom« v2 — one ~25 s hunt on the native game layer (GAMES_SPEC §5, phase 2;
 * David 2026-10-30 P1 / P2, strict calm rule 2026-10-31).
 *
 * Reached ONLY through `loadWandGameV2()` when `gameLayerAvailable()` — this file imports
 * gesture-handler, reanimated, skia, expo-audio and expo-haptics (an older binary keeps the v1
 * `WandOverlay`, which never sends `rules`).
 *
 * - Full screen (`GameScreen`): the cat edge to edge on a `ClipDeck` (every wand clip preloaded
 *   ONCE when the game opens; »Začni lov« only once the deck is ready, 12 s → »Slika«), so the
 *   hunt clock (= the session clock, started by the start response) and the first clip begin
 *   together. The chain comes from `useClipQueue`: stalk → crouch loop ⇄ pounce → catch
 *   (`wandDeck.ts`, `wandStageAt`).
 * - The schedule is the SERVER's (`POST /wand/start {rules: 2}`): every cue, window and limit
 *   is read from the session — none is a constant here.
 * - A Skia feather springs after the finger (lifted above the thumb) inside a visible play zone;
 *   one move per stroke (`wandStroke.ts`) with `in_zone` / `speed_ok`. The 👀 cue badge pulses
 *   with a countdown ring from the cue to the pounce and turns amber when the feather moved in
 *   the calm window. ★ per dodge and for the catch (the child LETS the cat catch it).
 * - Instant feedback from the pure mirror (`wandLive` / `wandCues`); the finish response wins
 *   (result screen: the server's stars and its one `hint`).
 * - Text only on the one top line (≤ 32 chars, ≥ 2 s); ★ in the middle; nothing under the thumb.
 * - »Lažje kretnje« / screen reader (`inputMode === 'hold'`): the feather floats by itself, holds
 *   still at the cue, »Umakni!« = one clean away move; the swipe stays on.
 * - Reduce motion: no spring, no trail, no float, no shake, no paw arc (a static ⚡), no pulse.
 */

import { useCallback, useEffect, useMemo, useReducer, useRef, useState, type ReactNode } from 'react';
import { AccessibilityInfo, ActivityIndicator, Platform, Pressable, StyleSheet, View } from 'react-native';
import { Circle, Group, Line, Path, RoundedRect } from '@shopify/react-native-skia';
import Animated, { useAnimatedStyle, useDerivedValue, useSharedValue, withRepeat, withSequence, withSpring, withTiming, Easing, cancelAnimation, type SharedValue } from 'react-native-reanimated';
import { scheduleOnRN } from 'react-native-worklets';
import { Hand, MoveUpRight, Star, Zap } from 'lucide-react-native';
import { useQueryClient } from '@tanstack/react-query';

import { Text } from '@/components/ui/Text';
import { childPetKey } from '@/hooks/queries/useChildPet';
import { CAT_COMMON_STRINGS, WAND_STRINGS, catRefusalMessage, onlyScratchingOpen } from '@/modules/catCare/catCare';
import type { Point } from '@/modules/catCare/catGames';
import { useWandGameV2 } from '@/modules/catCare/useCatGames';
import { wandDeck, wandPounceClipMs } from '@/modules/catCare/wandV2/wandDeck';
import {
  WAND_GRAB_SHAKE_MS,
  WAND_PAW_AFTER_MS,
  addWandV2Move,
  wandCues,
  wandDots,
  wandLive,
  wandNextBoundary,
  wandStageAt,
  type WandLive,
  type WandV2Move,
} from '@/modules/catCare/wandV2/wandRules';
import { WAND_V2_STRINGS } from '@/modules/catCare/wandV2/wandStrings';
import {
  FLOAT_STEP_MS,
  beginStroke,
  dodgeButtonMove,
  endStroke,
  featherForFinger,
  floatPoint,
  inZone,
  moveStroke,
  strokeToMove,
  wandCatAt,
  wandZone,
  type StrokeTracker,
} from '@/modules/catCare/wandV2/wandStroke';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { mediaModeOf } from '@/modules/display/display';
import ClipDeck from '@/modules/gameLayer/ClipDeck';
import FeedbackLayer, { type FeedbackHandle } from '@/modules/gameLayer/FeedbackLayer';
import { gameFeedbackOf } from '@/modules/gameLayer/gameFeedback';
import { useGameLine } from '@/modules/gameLayer/GameLine';
import GameScreen from '@/modules/gameLayer/GameScreen';
import GameTopBar from '@/modules/gameLayer/GameTopBar';
import PlayField, { useFieldSize, type FieldSizeValue } from '@/modules/gameLayer/PlayField';
import { useClipQueue } from '@/modules/gameLayer/useClipQueue';
import { useGameFx } from '@/modules/gameLayer/useGameFx';
import type { GameSound } from '@/modules/gameLayer/gameFxCore';
import { useGameGestures, type GameGestureHandlers } from '@/modules/gameLayer/useGameGestures';
import type { MediaMode } from '@/modules/petMedia/statePlayer';
import type { ClockSources } from '@/modules/training/game';
import { alpha, fonts, palette, radius, tightTracking } from '@/theme';

export interface WandGameV2Props {
  view: ChildPetView;
  onClose: () => void;
  reduceMotion: boolean;
  /** Injected in tests (session clock). */
  clock?: ClockSources;
  testID?: string;
}

const S = WAND_V2_STRINGS;
const W = WAND_STRINGS;
const C = CAT_COMMON_STRINGS;

/** The 👀 badge sits over the cat (fractions of the screen; the ring is drawn at the same spot). */
const CUE_X = 0.5;
const CUE_Y = 0.7;
const CUE_R = 44;
const SPRING = { damping: 14, stiffness: 160, mass: 0.6 };
const TRAIL_SPRING = { damping: 18, stiffness: 70, mass: 0.8 };
/** Three soft selection taps at the cue (GAMES_SPEC §5.2 »selection × 3«). */
const CUE_HAPTIC_GAPS_MS = [0, 180, 360];
const STAR_MS = 1_000;
const WAND_SOUNDS: readonly GameSound[] = ['crouch', 'swish', 'chime'];

/** A feather: a leaf with a spine, tip up, centred on its quill (0, 0). */
const FEATHER_PATH = 'M 0 0 C -16 -14 -18 -44 0 -70 C 18 -44 16 -14 0 0 Z';
const FEATHER_SPINE = 'M 0 4 L 0 -64';
/** The paw's swipe over the feather (relative to the feather). */
const PAW_PATH = 'M -54 -64 Q 0 -110 54 -60';

function circlePath(cx: number, cy: number, r: number): string {
  'worklet';
  // Starts at the top, clockwise (the `end` trim runs the countdown).
  return `M ${cx} ${cy - r} A ${r} ${r} 0 1 1 ${cx} ${cy + r} A ${r} ${r} 0 1 1 ${cx} ${cy - r}`;
}

function featherAt(size: FieldSizeValue, x: number, y: number): Point {
  'worklet';
  if (x >= 0 && y >= 0) return { x, y };
  const z = wandZone(size);
  return { x: z.x + z.width / 2, y: z.y + z.height * 0.6 };
}

function ZoneRing({ size, fx, fy }: { size: SharedValue<FieldSizeValue>; fx: SharedValue<number>; fy: SharedValue<number> }) {
  const x = useDerivedValue(() => wandZone(size.value).x);
  const y = useDerivedValue(() => wandZone(size.value).y);
  const w = useDerivedValue(() => wandZone(size.value).width);
  const h = useDerivedValue(() => wandZone(size.value).height);
  const color = useDerivedValue(() => {
    const p = featherAt(size.value, fx.value, fy.value);
    return inZone(p, wandZone(size.value)) ? alpha(palette.mint, 0.55) : alpha(palette.white, 0.3);
  });
  return (
    <Group>
      <RoundedRect x={x} y={y} width={w} height={h} r={28} color={alpha(palette.white, 0.06)} />
      <RoundedRect x={x} y={y} width={w} height={h} r={28} color={color} style="stroke" strokeWidth={3} />
    </Group>
  );
}

function FeatherSprite({
  size,
  fx,
  fy,
  shake,
  trail,
  showTrail,
  paw,
}: {
  size: SharedValue<FieldSizeValue>;
  fx: SharedValue<number>;
  fy: SharedValue<number>;
  shake: SharedValue<number>;
  trail: readonly { x: SharedValue<number>; y: SharedValue<number> }[];
  showTrail: boolean;
  paw: SharedValue<number>;
}) {
  const pos = useDerivedValue(() => featherAt(size.value, fx.value + shake.value, fy.value));
  const transform = useDerivedValue(() => [{ translateX: pos.value.x }, { translateY: pos.value.y }, { rotate: shake.value * 0.04 }]);
  const handle = useDerivedValue(() => ({ x: size.value.width * 0.9, y: size.value.height + 10 }));
  const quill = useDerivedValue(() => ({ x: pos.value.x, y: pos.value.y + 6 }));
  const t0x = useDerivedValue(() => featherAt(size.value, trail[0].x.value, trail[0].y.value).x);
  const t0y = useDerivedValue(() => featherAt(size.value, trail[0].x.value, trail[0].y.value).y - 30);
  const t1x = useDerivedValue(() => featherAt(size.value, trail[1].x.value, trail[1].y.value).x);
  const t1y = useDerivedValue(() => featherAt(size.value, trail[1].x.value, trail[1].y.value).y - 30);
  return (
    <Group>
      <Line p1={handle} p2={quill} color={alpha(palette.white, 0.5)} strokeWidth={2} />
      {showTrail && <Circle cx={t0x} cy={t0y} r={4} color={alpha(palette.mint, 0.55)} />}
      {showTrail && <Circle cx={t1x} cy={t1y} r={3} color={alpha(palette.mint, 0.35)} />}
      <Group transform={transform}>
        <Path path={FEATHER_PATH} color={palette.mint} />
        <Path path={FEATHER_SPINE} color={alpha(palette.graphite, 0.45)} style="stroke" strokeWidth={2} />
        <Path path={PAW_PATH} color={palette.warnDark} style="stroke" strokeWidth={6} strokeCap="round" opacity={paw} />
      </Group>
    </Group>
  );
}

function CueRing({ size, progress, broken }: { size: SharedValue<FieldSizeValue>; progress: SharedValue<number>; broken: SharedValue<number> }) {
  const path = useDerivedValue(() => circlePath(size.value.width * CUE_X, size.value.height * CUE_Y, CUE_R + 6));
  const cx = useDerivedValue(() => size.value.width * CUE_X);
  const cy = useDerivedValue(() => size.value.height * CUE_Y);
  const shown = useDerivedValue(() => (progress.value > 0 ? 1 : 0));
  const color = useDerivedValue(() => (broken.value > 0 ? palette.warnDark : palette.mint));
  return (
    <Group opacity={shown}>
      <Circle cx={cx} cy={cy} r={CUE_R + 6} color={alpha(palette.white, 0.18)} style="stroke" strokeWidth={6} />
      <Path path={path} color={color} style="stroke" strokeWidth={6} strokeCap="round" start={0} end={progress} />
    </Group>
  );
}

export default function WandGameV2({ view, onClose, reduceMotion, clock, testID = 'cat-wand-v2' }: WandGameV2Props) {
  const queryClient = useQueryClient();
  const game = useWandGameV2(view, { clock });
  const { phase } = game;
  const media = view.pet.media;
  const mode = mediaModeOf(view);
  // A new hunt remounts the deck (fresh players, fresh instances) — clips never change under a hunt.
  const [hunt, setHunt] = useState(0);
  const plan = useMemo(() => wandDeck(media, mode), [hunt]); // eslint-disable-line react-hooks/exhaustive-deps
  const pounceMs = useMemo(() => wandPounceClipMs(media), [hunt]); // eslint-disable-line react-hooks/exhaustive-deps
  const [deckMode, setDeckMode] = useState<MediaMode | null>(null);
  const msAt = game.msAt;
  const now = useCallback(() => msAt(null), [msAt]);
  const queue = useClipQueue(now);
  const fx = useGameFx(gameFeedbackOf(view), WAND_SOUNDS);
  const line = useGameLine();
  const feedback = useRef<FeedbackHandle>(null);
  const onMediaExpired = useCallback(() => void queryClient.invalidateQueries({ queryKey: childPetKey }), [queryClient]);

  const session = phase.kind === 'running' || phase.kind === 'finishing' ? phase.session : null;
  const moves: readonly WandV2Move[] = phase.kind === 'running' || phase.kind === 'finishing' ? phase.input : [];
  const running = phase.kind === 'running';
  const runningRef = useRef(running);
  runningRef.current = running;
  const sessionRef = useRef(session);
  sessionRef.current = session;

  // ── The hunt clock: the 100 ms game tick plus one timer to the next schedule boundary ──
  const [, force] = useReducer((n: number) => n + 1, 0);
  const t = session === null ? 0 : Math.max(game.elapsedMs, msAt(null));
  const next = session === null ? null : wandNextBoundary(session, t, pounceMs);
  useEffect(() => {
    if (next === null || !running) return;
    const timer = setTimeout(force, Math.max(0, next - msAt(null)));
    return () => clearTimeout(timer);
  }, [next, running, msAt]);

  // ── Deck → queue ──
  const onDeckReady = useCallback((m: MediaMode) => setDeckMode(m), []);
  const sessionId = session?.id ?? null;
  useEffect(() => {
    if (sessionId === null || deckMode === null) return;
    const specs = deckMode === plan.mode ? plan.specs : wandDeck(media, deckMode).specs;
    queue.start(specs, plan.initial);
  }, [sessionId]); // eslint-disable-line react-hooks/exhaustive-deps
  const role = session === null ? null : wandStageAt(session, t, pounceMs);
  const request = queue.request;
  useEffect(() => {
    if (role !== null) request(role);
  }, [role, sessionId, request]);

  // ── Feather on the UI thread ──
  const size = useFieldSize();
  const fxv = useSharedValue(-1);
  const fyv = useSharedValue(-1);
  const shake = useSharedValue(0);
  const paw = useSharedValue(0);
  const t0x = useSharedValue(-1);
  const t0y = useSharedValue(-1);
  const t1x = useSharedValue(-1);
  const t1y = useSharedValue(-1);
  const trail = useMemo(() => [{ x: t0x, y: t0y }, { x: t1x, y: t1y }], [t0x, t0y, t1x, t1y]);
  const cueProgress = useSharedValue(0);
  const cueBroken = useSharedValue(0);
  const cuePulse = useSharedValue(1);

  // ── Moves (JS): one per stroke ──
  const tracker = useRef<StrokeTracker | null>(null);
  const record = useCallback(
    (move: WandV2Move) => {
      const s = sessionRef.current;
      if (s === null || !runningRef.current) return;
      game.setInput((list) => addWandV2Move(list, move, s.duration_ms));
    },
    [game],
  );
  const onBegin = useCallback(
    (x: number, y: number, w: number, h: number) => {
      tracker.current = beginStroke({ x, y }, msAt(null), wandCatAt({ width: w, height: h }));
    },
    [msAt],
  );
  const onSample = useCallback(
    (x: number, y: number, speed: number, w: number, h: number) => {
      const tr = tracker.current;
      if (tr === null) return;
      const s = sessionRef.current;
      const step = moveStroke(tr, { x, y }, msAt(null), wandCatAt({ width: w, height: h }), speed);
      tracker.current = step.tracker;
      if (step.stroke !== null && s !== null) record(strokeToMove(step.stroke, wandZone({ width: w, height: h }), s));
    },
    [msAt, record],
  );
  const onEnd = useCallback(
    (x: number, y: number, speed: number, w: number, h: number) => {
      const tr = tracker.current;
      tracker.current = null;
      const s = sessionRef.current;
      if (tr === null || s === null) return;
      const stroke = endStroke(tr, { x, y }, msAt(null), wandCatAt({ width: w, height: h }), speed);
      if (stroke !== null) record(strokeToMove(stroke, wandZone({ width: w, height: h }), s));
    },
    [msAt, record],
  );

  const handlers = useMemo<GameGestureHandlers>(() => {
    const place = (p: Point) => {
      'worklet';
      if (reduceMotion) {
        fxv.value = p.x;
        fyv.value = p.y;
        return;
      }
      fxv.value = withSpring(p.x, SPRING);
      fyv.value = withSpring(p.y, SPRING);
      t0x.value = withSpring(p.x, TRAIL_SPRING);
      t0y.value = withSpring(p.y, TRAIL_SPRING);
      t1x.value = withSpring(p.x, { ...TRAIL_SPRING, stiffness: 45 });
      t1y.value = withSpring(p.y, { ...TRAIL_SPRING, stiffness: 45 });
    };
    return {
      onPanBegin: (e) => {
        'worklet';
        const p = featherForFinger({ x: e.x, y: e.y });
        place(p);
        scheduleOnRN(onBegin, p.x, p.y, size.value.width, size.value.height);
      },
      onPanUpdate: (e) => {
        'worklet';
        const p = featherForFinger({ x: e.x, y: e.y });
        place(p);
        scheduleOnRN(onSample, p.x, p.y, Math.hypot(e.velocityX, e.velocityY), size.value.width, size.value.height);
      },
      onPanEnd: (e) => {
        'worklet';
        const p = featherForFinger({ x: e.x, y: e.y });
        scheduleOnRN(onEnd, p.x, p.y, Math.hypot(e.velocityX, e.velocityY), size.value.width, size.value.height);
      },
    };
  }, [reduceMotion, fxv, fyv, t0x, t0y, t1x, t1y, size, onBegin, onSample, onEnd]);
  const gestures = useGameGestures(view, handlers);
  const easy = gestures.inputMode === 'hold';

  // ── Live mirror → cues ──
  const live: WandLive | null = session === null ? null : wandLive(session, moves, t);
  const prevLive = useRef<WandLive | null>(null);
  const timers = useRef<ReturnType<typeof setTimeout>[]>([]);
  useEffect(
    () => () => {
      timers.current.forEach(clearTimeout);
      timers.current = [];
    },
    [],
  );
  useEffect(() => {
    if (sessionId === null) prevLive.current = null;
  }, [sessionId]);
  const say = line.say;
  const announce = (text: string) => {
    if (Platform.OS === 'ios') AccessibilityInfo.announceForAccessibility(text);
  };
  const [staticPaw, setStaticPaw] = useState(false);
  useEffect(() => {
    if (live === null || session === null) return;
    const cues = wandCues(prevLive.current, live);
    prevLive.current = live;
    for (const cue of cues) {
      if (cue.kind === 'crouch') {
        fx.sound('crouch');
        for (const gap of CUE_HAPTIC_GAPS_MS) timers.current.push(setTimeout(() => fx.haptic('cue'), gap));
        say(S.freeze);
        announce(S.a11y.crouch);
        const p = session.pounces_ms[cue.pounce];
        const cueAt = session.crouch_cues_ms[cue.pounce];
        const left = Math.max(0, p - t);
        cueBroken.value = 0;
        cueProgress.value = Math.max(0.001, Math.min(1, (t - cueAt) / Math.max(1, p - cueAt)));
        cueProgress.value = withTiming(1, { duration: left, easing: Easing.linear });
        if (!reduceMotion) cuePulse.value = withRepeat(withSequence(withTiming(1.12, { duration: 260 }), withTiming(1, { duration: 260 })), -1);
      } else if (cue.kind === 'calmBroken') {
        cueBroken.value = 1;
      } else if (cue.kind === 'pounce') {
        cancelAnimation(cuePulse);
        cuePulse.value = 1;
        cueProgress.value = 0;
        announce(S.a11y.pounce);
        timers.current.push(
          setTimeout(() => {
            if (reduceMotion) {
              setStaticPaw(true);
              timers.current.push(setTimeout(() => setStaticPaw(false), 600));
            } else {
              paw.value = withSequence(withTiming(1, { duration: 120 }), withTiming(0, { duration: 380 }));
            }
          }, Math.max(0, session.pounces_ms[cue.pounce] + WAND_PAW_AFTER_MS - t)),
        );
      } else if (cue.kind === 'dodged') {
        void feedback.current?.show('star', { totalMs: STAR_MS });
        fx.sound('swish');
        fx.haptic('success');
        say(S.fb.dodge);
      } else if (cue.kind === 'grabbed') {
        // Caught in the paw: the feather shakes, then wriggles free — no ✗, no text (§5.2).
        if (!reduceMotion) {
          const at = Math.max(0, session.pounces_ms[cue.pounce] + WAND_PAW_AFTER_MS - t);
          timers.current.push(
            setTimeout(() => {
              shake.value = withRepeat(withSequence(withTiming(-9, { duration: 50 }), withTiming(9, { duration: 50 })), Math.round(WAND_GRAB_SHAKE_MS / 100), true);
              timers.current.push(setTimeout(() => (shake.value = 0), WAND_GRAB_SHAKE_MS));
            }, at),
          );
        }
      } else if (cue.kind === 'catchCue') {
        say(S.catchCue);
        announce(S.catchCue);
      } else if (cue.kind === 'caught') {
        void feedback.current?.show('star', { totalMs: STAR_MS });
        fx.sound('chime');
        fx.haptic('success');
        say(S.fb.caught);
      }
    }
  });

  // ── The one line outside the hunt's own cues ──
  useEffect(() => {
    if (deckMode === null) say(S.preparing);
    else if (phase.kind === 'intro' || phase.kind === 'starting') say(S.ruleLine);
    else if (phase.kind === 'finishing') say(S.saving);
    else if (phase.kind === 'result' || phase.kind === 'failed') say(null);
  }, [deckMode, phase.kind, say]);
  useEffect(() => {
    if (sessionId !== null) say(S.ruleLine);
  }, [sessionId, say]);

  // ── Hold mode: the feather floats by itself, still from the 👀 cue until the pounce ──
  const crouching = live?.pounces.some((p) => p.state === 'cue') === true;
  useEffect(() => {
    if (!easy || !running || reduceMotion || crouching) return;
    let i = 0;
    const step = () => {
      const p = floatPoint(wandZone(size.value), i++);
      fxv.value = withTiming(p.x, { duration: FLOAT_STEP_MS - 100 });
      fyv.value = withTiming(p.y, { duration: FLOAT_STEP_MS - 100 });
    };
    step();
    const id = setInterval(step, FLOAT_STEP_MS);
    return () => clearInterval(id);
  }, [easy, running, reduceMotion, crouching, size, fxv, fyv]);
  const dodgeTap = () => {
    if (!runningRef.current) return;
    record(dodgeButtonMove(msAt(null)));
    const z = wandZone(size.value);
    const away = { x: z.x + z.width * 0.85, y: z.y + z.height * 0.15 };
    fxv.value = reduceMotion ? away.x : withTiming(away.x, { duration: 220 });
    fyv.value = reduceMotion ? away.y : withTiming(away.y, { duration: 220 });
  };

  // ── Start / again ──
  const wand = view.cat.wand;
  const ctx = { nowIso: view.server_time, timezone: view.timezone, scratchingOnly: onlyScratchingOpen(view.cat) };
  const canStart = deckMode !== null && wand !== null && wand.can_start && phase.kind === 'intro';
  const again = () => {
    game.reset();
    setDeckMode(null);
    setHunt((h) => h + 1);
  };

  const cueStyle = useAnimatedStyle(() => ({ opacity: cueProgress.value > 0 ? 1 : 0, transform: [{ scale: cuePulse.value }] }));
  const q = queue.state;
  const deckCurrent = useMemo(() => (q === null ? null : { role: q.current, instance: q.instance, lastSwitch: q.lastSwitch }), [q]);

  const serverResult = phase.kind === 'result' ? phase.result : null;
  const stars = serverResult?.stars ?? live?.stars ?? 0;
  const dots = live === null ? [] : wandDots(live);
  const close = phase.kind === 'running' ? game.stop : phase.kind === 'starting' || phase.kind === 'finishing' ? undefined : onClose;

  const card = (children: ReactNode, id: string) => (
    <View style={styles.backdrop} testID={`${testID}-${id}`}>
      <View style={styles.card}>{children}</View>
    </View>
  );

  let overlay: ReactNode = null;
  if (phase.kind === 'intro' || phase.kind === 'starting') {
    overlay = card(
      <>
        <Text style={styles.title} accessibilityRole="header">
          {S.title}
        </Text>
        <Text style={styles.body}>{easy ? S.easyIntro : S.intro}</Text>
        <View style={styles.rules}>
          <RuleRow icon={<Text style={styles.emoji}>👀</Text>} text={S.rules.crouch} />
          <RuleRow icon={<Zap color={palette.warnDark} size={22} />} text={S.rules.pounce} />
          <RuleRow icon={<Hand color={palette.mint} size={22} />} text={S.rules.catch} />
        </View>
        {wand !== null && (
          <Text style={styles.muted} testID={`${testID}-today`}>
            {wand.goal > 0 && wand.sessions_today >= wand.goal ? W.todayDone : W.today(wand.sessions_today, wand.goal)}
          </Text>
        )}
        {wand !== null && !wand.can_start && wand.blocked_reason !== null && (
          <Text style={styles.note} testID={`${testID}-blocked`}>
            {catRefusalMessage(wand.blocked_reason, wand.next_allowed_at, ctx)}
          </Text>
        )}
        <Pressable
          testID={`${testID}-start`}
          accessibilityRole="button"
          accessibilityState={{ disabled: !canStart, busy: phase.kind === 'starting' }}
          disabled={!canStart}
          onPress={game.start}
          style={({ pressed }) => [styles.primary, !canStart && styles.disabled, pressed && styles.pressed]}
        >
          {phase.kind === 'starting' || deckMode === null ? <ActivityIndicator color={palette.graphite} /> : null}
          <Text style={styles.primaryText}>{phase.kind === 'starting' ? C.starting : deckMode === null ? S.preparing : S.start}</Text>
        </Pressable>
      </>,
      'intro',
    );
  } else if (phase.kind === 'result') {
    const r = phase.result;
    overlay = card(
      <>
        <View style={styles.stars} accessible accessibilityLabel={S.a11y.stars(r.stars, r.max_stars)} testID={`${testID}-result-stars`}>
          {Array.from({ length: Math.max(r.max_stars, 1) }, (_, i) => (
            <Star key={i} size={40} color={palette.warnDark} fill={i < r.stars ? palette.warnDark : 'transparent'} />
          ))}
        </View>
        <Text style={styles.title} accessibilityRole="header" accessibilityLiveRegion="polite" testID={`${testID}-result-title`}>
          {r.success ? S.result.success : S.result.again}
        </Text>
        {phase.status === 'unchanged' && <Text style={styles.muted}>{S.result.stored}</Text>}
        {r.success && phase.status !== 'unchanged' && <Text style={styles.body}>{S.result.counts}</Text>}
        {r.hint !== null && (
          <Text style={r.success ? styles.muted : styles.bodyStrong} testID={`${testID}-result-hint`}>
            {S.hint[r.hint]}
          </Text>
        )}
        {!r.success && <Text style={styles.muted}>{C.noPenalty}</Text>}
        {!r.success && (
          <Pressable testID={`${testID}-again`} accessibilityRole="button" onPress={again} style={({ pressed }) => [styles.primary, pressed && styles.pressed]}>
            <Text style={styles.primaryText}>{S.result.againButton}</Text>
          </Pressable>
        )}
        <Pressable
          testID={`${testID}-done`}
          accessibilityRole="button"
          onPress={onClose}
          style={({ pressed }) => [r.success ? styles.primary : styles.secondary, pressed && styles.pressed]}
        >
          <Text style={r.success ? styles.primaryText : styles.secondaryText}>{S.result.done}</Text>
        </Pressable>
      </>,
      'result',
    );
  } else if (phase.kind === 'failed') {
    overlay = card(
      <>
        <Text style={styles.body} accessibilityLiveRegion="polite" testID={`${testID}-failed-text`}>
          {phase.message}
        </Text>
        {phase.retry !== null ? (
          <Pressable testID={`${testID}-retry`} accessibilityRole="button" onPress={game.retryFinish} style={({ pressed }) => [styles.primary, pressed && styles.pressed]}>
            <Text style={styles.primaryText}>{C.retry}</Text>
          </Pressable>
        ) : (
          <Pressable testID={`${testID}-back`} accessibilityRole="button" onPress={again} style={({ pressed }) => [styles.secondary, pressed && styles.pressed]}>
            <Text style={styles.secondaryText}>{C.back}</Text>
          </Pressable>
        )}
      </>,
      'failed',
    );
  }

  const dock =
    running && easy ? (
      <Pressable
        testID={`${testID}-dodge`}
        accessibilityRole="button"
        accessibilityLabel={S.dodgeButton}
        accessibilityHint={S.a11y.dodgeHint}
        onPress={dodgeTap}
        style={({ pressed }) => [styles.primary, pressed && styles.pressed]}
      >
        <MoveUpRight color={palette.graphite} size={24} />
        <Text style={styles.primaryText}>{S.dodgeButton}</Text>
      </Pressable>
    ) : null;

  return (
    <GameScreen
      testID={testID}
      onExit={close ?? onClose}
      stage={
        <ClipDeck
          key={hunt}
          clips={plan.clips}
          current={deckCurrent}
          mode={plan.mode}
          onReady={onDeckReady}
          onEnded={queue.ended}
          onMediaExpired={onMediaExpired}
          testID={`${testID}-deck`}
        />
      }
      field={
        <PlayField size={size} gesture={gestures.gesture} testID={`${testID}-field`}>
          <ZoneRing size={size} fx={fxv} fy={fyv} />
          <CueRing size={size} progress={cueProgress} broken={cueBroken} />
          <FeatherSprite size={size} fx={fxv} fy={fyv} shake={shake} trail={trail} showTrail={!reduceMotion} paw={paw} />
        </PlayField>
      }
      feedback={
        <>
          <Animated.View pointerEvents="none" style={[styles.cue, cueStyle]} testID={`${testID}-cue`} importantForAccessibility="no-hide-descendants">
            <Text style={styles.cueEmoji}>👀</Text>
            {staticPaw && <Zap color={palette.warnDark} size={30} />}
          </Animated.View>
          <FeedbackLayer ref={feedback} reduceMotion={reduceMotion} onMaskChange={queue.masked} testID={`${testID}-feedback`} />
        </>
      }
      topBar={
        <GameTopBar
          dots={dots}
          stars={stars}
          line={line.text}
          closeLabel={phase.kind === 'running' ? C.stopA11y : C.close}
          progressLabel={S.a11y.progress(dots.filter(Boolean).length, dots.length, stars)}
          onClose={close ?? onClose}
          closeLocked={close === undefined}
          testID={`${testID}-top`}
        />
      }
      dock={dock}
      overlay={overlay}
    />
  );
}

function RuleRow({ icon, text }: { icon: ReactNode; text: string }) {
  return (
    <View style={styles.ruleRow}>
      <View style={styles.ruleIcon}>{icon}</View>
      <Text style={styles.ruleText}>{text}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  backdrop: { ...StyleSheet.absoluteFill, backgroundColor: alpha(palette.graphite, 0.6), alignItems: 'center', justifyContent: 'center', padding: 20 },
  card: {
    alignSelf: 'stretch',
    gap: 14,
    paddingVertical: 24,
    paddingHorizontal: 20,
    borderRadius: 28,
    backgroundColor: alpha(palette.graphite, 0.92),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.12),
  },
  title: { color: palette.white, fontSize: 24, letterSpacing: tightTracking(24), fontFamily: fonts.displayBold, textAlign: 'center' },
  body: { color: palette.white, fontSize: 17, textAlign: 'center' },
  bodyStrong: { color: palette.white, fontSize: 17, fontWeight: '700', textAlign: 'center' },
  muted: { color: palette.n300, fontSize: 15, textAlign: 'center' },
  note: { color: palette.warnDark, fontSize: 15, textAlign: 'center' },
  rules: { gap: 10 },
  ruleRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  ruleIcon: { width: 32, alignItems: 'center' },
  ruleText: { flex: 1, color: palette.white, fontSize: 16, fontWeight: '600' },
  emoji: { fontSize: 22 },
  stars: { flexDirection: 'row', gap: 8, justifyContent: 'center' },
  primary: {
    minHeight: 60,
    minWidth: 200,
    paddingHorizontal: 26,
    borderRadius: radius.button,
    backgroundColor: palette.mint,
    flexDirection: 'row',
    gap: 10,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryText: { color: palette.graphite, fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.displayBold },
  secondary: {
    minHeight: 54,
    paddingHorizontal: 22,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.graphite, 0.7),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.5),
    alignItems: 'center',
    justifyContent: 'center',
  },
  secondaryText: { color: palette.white, fontSize: 18, fontWeight: '700' },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.8 },
  cue: {
    position: 'absolute',
    left: `${CUE_X * 100}%`,
    top: `${CUE_Y * 100}%`,
    width: CUE_R * 2,
    height: CUE_R * 2,
    marginLeft: -CUE_R,
    marginTop: -CUE_R,
    borderRadius: CUE_R,
    backgroundColor: alpha(palette.graphite, 0.7),
    alignItems: 'center',
    justifyContent: 'center',
  },
  cueEmoji: { fontSize: 40 },
});
