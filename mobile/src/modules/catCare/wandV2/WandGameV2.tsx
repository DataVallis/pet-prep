/**
 * »Palica s peresom« — the simple hunt on the native game layer (GAMES_SPEC §5; David 2026-11-08
 * »naredi bolj preprosto igro«, the rule designed by Claude — it replaced the strict stillness /
 * pull-away rule of 2026-10-30 / 31, which nobody could score).
 *
 * Reached ONLY through `loadWandGameV2()` when `gameLayerAvailable()` — this file imports
 * gesture-handler, reanimated, skia, expo-audio and expo-haptics (an older binary keeps the v1
 * `WandOverlay`, which never sends `rules`).
 *
 * - Full screen (`GameScreen`): the cat edge to edge on a `ClipDeck` (every wand clip preloaded
 *   ONCE when the game opens; »Začni lov« only once the deck is ready, 12 s → »Slika«), so the
 *   hunt clock (= the session clock, started by the start response) and the first clip begin
 *   together. The chain comes from `useClipQueue`: stalk → crouch loop ⇄ catch (`wandDeck.ts`,
 *   `wandStageAt`); after each catch the ★ masks the cut back to the crouch loop.
 * - The numbers are the SERVER's (`POST /wand/start {rules: 3}`): the first cue, the 2 s window,
 *   the gaps, 3 catches — read from the session, none is a constant here.
 * - A Skia feather springs after the finger (lifted above the thumb) — only so the cat has
 *   something to stalk; its moves are not scored. What counts: LETTING GO (the finger lifted —
 *   any lift, also a finger that rested without moving — or »Pusti!« in »Lažje kretnje«) while
 *   the 👀 badge shows (a countdown ring around it). The cat pounces at once and catches the
 *   feather (the catch clip; ★ »Ujela ga je!« when it has played). Too early / never: nothing —
 *   no ✗, no text but the rule line; the next 👀 comes. 3 catches = done → the result screen.
 * - Instant feedback from the pure mirror (`wandHuntAt` / `wandCues`); the finish response wins
 *   (result screen: the server's stars and its hint). One finish call per hunt.
 * - Text only on the one top line (≤ 32 chars, ≥ 2 s); ★ in the middle; nothing under the thumb.
 * - »Lažje kretnje« / screen reader (`inputMode === 'hold'`): the feather floats by itself;
 *   »Pusti!« is the let-go; lifting a finger still works.
 * - Reduce motion: no spring, no trail, no float, no paw arc (a static ⚡), no pulse.
 */

import { useCallback, useEffect, useMemo, useReducer, useRef, useState, type ReactNode } from 'react';
import { AccessibilityInfo, ActivityIndicator, Platform, Pressable, StyleSheet, View } from 'react-native';
import { Circle, Group, Line, Path } from '@shopify/react-native-skia';
import Animated, { useAnimatedStyle, useDerivedValue, useSharedValue, withRepeat, withSequence, withSpring, withTiming, Easing, cancelAnimation, type SharedValue } from 'react-native-reanimated';
import { scheduleOnRN } from 'react-native-worklets';
import { Hand, Star, Zap } from 'lucide-react-native';
import { useQueryClient } from '@tanstack/react-query';

import { Text } from '@/components/ui/Text';
import { childPetKey } from '@/hooks/queries/useChildPet';
import { CAT_COMMON_STRINGS, WAND_STRINGS, catRefusalMessage, onlyScratchingOpen } from '@/modules/catCare/catCare';
import type { Point } from '@/modules/catCare/catGames';
import { useWandGameV2 } from '@/modules/catCare/useCatGames';
import { wandDeck } from '@/modules/catCare/wandV2/wandDeck';
import {
  WAND_PAW_AFTER_MS,
  addWandRelease,
  wandCues,
  wandDots,
  wandHuntAt,
  wandNextBoundary,
  wandStageAt,
  wandStars,
  type WandHuntState,
} from '@/modules/catCare/wandV2/wandRules';
import { WAND_V2_STRINGS } from '@/modules/catCare/wandV2/wandStrings';
import { FLOAT_STEP_MS, featherForFinger, floatPoint, wandZone } from '@/modules/catCare/wandV2/wandStroke';
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
/** The ★ after a catch; it also masks the cut from the catch pose back to the crouch loop. */
const STAR_MS = 1_200;
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

/**
 * Colours used inside UI-thread worklets are computed HERE, on the JS thread: `alpha()` is not a
 * worklet, and calling it from a `useDerivedValue` on the UI runtime is a fatal crash of the
 * whole app in a release build (the cat wand crash, David 2026-11-02). Never call a non-worklet
 * helper inside a worklet — the reanimated Jest mock (`assertUiWorklet`) fails such a test.
 */
const STRING_COLOUR = alpha(palette.white, 0.5);
const TRAIL_NEAR = alpha(palette.mint, 0.55);
const TRAIL_FAR = alpha(palette.mint, 0.35);
const SPINE_COLOUR = alpha(palette.graphite, 0.45);
const RING_TRACK = alpha(palette.white, 0.18);

function FeatherSprite({
  size,
  fx,
  fy,
  trail,
  showTrail,
  paw,
}: {
  size: SharedValue<FieldSizeValue>;
  fx: SharedValue<number>;
  fy: SharedValue<number>;
  trail: readonly { x: SharedValue<number>; y: SharedValue<number> }[];
  showTrail: boolean;
  paw: SharedValue<number>;
}) {
  const pos = useDerivedValue(() => featherAt(size.value, fx.value, fy.value));
  const transform = useDerivedValue(() => [{ translateX: pos.value.x }, { translateY: pos.value.y }]);
  const handle = useDerivedValue(() => ({ x: size.value.width * 0.9, y: size.value.height + 10 }));
  const quill = useDerivedValue(() => ({ x: pos.value.x, y: pos.value.y + 6 }));
  const t0x = useDerivedValue(() => featherAt(size.value, trail[0].x.value, trail[0].y.value).x);
  const t0y = useDerivedValue(() => featherAt(size.value, trail[0].x.value, trail[0].y.value).y - 30);
  const t1x = useDerivedValue(() => featherAt(size.value, trail[1].x.value, trail[1].y.value).x);
  const t1y = useDerivedValue(() => featherAt(size.value, trail[1].x.value, trail[1].y.value).y - 30);
  return (
    <Group>
      <Line p1={handle} p2={quill} color={STRING_COLOUR} strokeWidth={2} />
      {showTrail && <Circle cx={t0x} cy={t0y} r={4} color={TRAIL_NEAR} />}
      {showTrail && <Circle cx={t1x} cy={t1y} r={3} color={TRAIL_FAR} />}
      <Group transform={transform}>
        <Path path={FEATHER_PATH} color={palette.mint} />
        <Path path={FEATHER_SPINE} color={SPINE_COLOUR} style="stroke" strokeWidth={2} />
        <Path path={PAW_PATH} color={palette.warnDark} style="stroke" strokeWidth={6} strokeCap="round" opacity={paw} />
      </Group>
    </Group>
  );
}

function CueRing({ size, progress }: { size: SharedValue<FieldSizeValue>; progress: SharedValue<number> }) {
  const path = useDerivedValue(() => circlePath(size.value.width * CUE_X, size.value.height * CUE_Y, CUE_R + 6));
  const cx = useDerivedValue(() => size.value.width * CUE_X);
  const cy = useDerivedValue(() => size.value.height * CUE_Y);
  const shown = useDerivedValue(() => (progress.value > 0 ? 1 : 0));
  return (
    <Group opacity={shown}>
      <Circle cx={cx} cy={cy} r={CUE_R + 6} color={RING_TRACK} style="stroke" strokeWidth={6} />
      <Path path={path} color={palette.mint} style="stroke" strokeWidth={6} strokeCap="round" start={0} end={progress} />
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
  const [deckMode, setDeckMode] = useState<MediaMode | null>(null);
  const msAt = game.msAt;
  const now = useCallback(() => msAt(null), [msAt]);
  const queue = useClipQueue(now);
  const fx = useGameFx(gameFeedbackOf(view), WAND_SOUNDS);
  const line = useGameLine();
  const feedback = useRef<FeedbackHandle>(null);
  const onMediaExpired = useCallback(() => void queryClient.invalidateQueries({ queryKey: childPetKey }), [queryClient]);

  const session = phase.kind === 'running' || phase.kind === 'finishing' ? phase.session : null;
  const releases: readonly number[] = phase.kind === 'running' || phase.kind === 'finishing' ? phase.input : [];
  const running = phase.kind === 'running';
  const runningRef = useRef(running);
  runningRef.current = running;
  const sessionRef = useRef(session);
  sessionRef.current = session;

  // ── The hunt clock: the 100 ms game tick plus one timer to the next timeline boundary ──
  const [, force] = useReducer((n: number) => n + 1, 0);
  const t = session === null ? 0 : Math.max(game.elapsedMs, msAt(null));
  const next = session === null ? null : wandNextBoundary(session, releases, t);
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
  const role = session === null ? null : wandStageAt(session, releases, t);
  const request = queue.request;
  useEffect(() => {
    if (role !== null) request(role);
  }, [role, sessionId, request]);

  // ── Feather on the UI thread ──
  const size = useFieldSize();
  const fxv = useSharedValue(-1);
  const fyv = useSharedValue(-1);
  const paw = useSharedValue(0);
  const t0x = useSharedValue(-1);
  const t0y = useSharedValue(-1);
  const t1x = useSharedValue(-1);
  const t1y = useSharedValue(-1);
  const trail = useMemo(() => [{ x: t0x, y: t0y }, { x: t1x, y: t1y }], [t0x, t0y, t1x, t1y]);
  const cueProgress = useSharedValue(0);
  const cuePulse = useSharedValue(1);

  // ── Let-go (JS): every finger lift, and »Pusti!» ──
  const letGo = useCallback(() => {
    const s = sessionRef.current;
    if (s === null || !runningRef.current) return;
    const at = msAt(null);
    game.setInput((list) => addWandRelease(list, at, s.duration_ms));
  }, [game, msAt]);

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
        place(featherForFinger({ x: e.x, y: e.y }));
      },
      onPanUpdate: (e) => {
        'worklet';
        place(featherForFinger({ x: e.x, y: e.y }));
      },
      onPanFinalize: () => {
        'worklet';
        scheduleOnRN(letGo);
      },
    };
  }, [reduceMotion, fxv, fyv, t0x, t0y, t1x, t1y, letGo]);
  const gestures = useGameGestures(view, handlers);
  const easy = gestures.inputMode === 'hold';

  // ── Live mirror → cues ──
  const live: WandHuntState | null = session === null ? null : wandHuntAt(session, releases, t);
  const prevLive = useRef<WandHuntState | null>(null);
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
  const endCue = () => {
    cancelAnimation(cuePulse);
    cancelAnimation(cueProgress);
    cuePulse.value = 1;
    cueProgress.value = 0;
  };
  useEffect(() => {
    if (live === null || session === null) return;
    const cues = wandCues(prevLive.current, live);
    prevLive.current = live;
    for (const cue of cues) {
      if (cue.kind === 'crouch') {
        fx.sound('crouch');
        for (const gap of CUE_HAPTIC_GAPS_MS) timers.current.push(setTimeout(() => fx.haptic('cue'), gap));
        say(S.letGo);
        announce(S.a11y.crouch);
        const m = live.moment;
        if (m.kind === 'cue') {
          cueProgress.value = Math.max(0.001, Math.min(1, (t - m.cueMs) / Math.max(1, m.endsMs - m.cueMs)));
          cueProgress.value = withTiming(1, { duration: Math.max(0, m.endsMs - t), easing: Easing.linear });
          if (!reduceMotion) cuePulse.value = withRepeat(withSequence(withTiming(1.12, { duration: 260 }), withTiming(1, { duration: 260 })), -1);
        }
      } else if (cue.kind === 'pounce') {
        endCue();
        fx.sound('swish');
        announce(S.a11y.pounce);
        const release = live.decided[cue.index]?.release_ms ?? t;
        timers.current.push(
          setTimeout(() => {
            if (reduceMotion) {
              setStaticPaw(true);
              timers.current.push(setTimeout(() => setStaticPaw(false), 600));
            } else {
              paw.value = withSequence(withTiming(1, { duration: 120 }), withTiming(0, { duration: 380 }));
            }
          }, Math.max(0, release + WAND_PAW_AFTER_MS - t)),
        );
      } else if (cue.kind === 'caught') {
        void feedback.current?.show('star', { mask: true, totalMs: STAR_MS });
        fx.sound('chime');
        fx.haptic('success');
        say(S.fb.caught);
        announce(S.fb.caught);
      } else if (cue.kind === 'lostInterest') {
        // No ✗, no fault: the cat just looks away; the rule line comes back for the next 👀.
        endCue();
        say(S.ruleLine);
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

  // ── Hold mode: the feather floats by itself ──
  useEffect(() => {
    if (!easy || !running || reduceMotion) return;
    let i = 0;
    const step = () => {
      const p = floatPoint(wandZone(size.value), i++);
      fxv.value = withTiming(p.x, { duration: FLOAT_STEP_MS - 100 });
      fyv.value = withTiming(p.y, { duration: FLOAT_STEP_MS - 100 });
    };
    step();
    const id = setInterval(step, FLOAT_STEP_MS);
    return () => clearInterval(id);
  }, [easy, running, reduceMotion, size, fxv, fyv]);

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
  const stars = serverResult?.stars ?? (live === null ? 0 : wandStars(live));
  const dots = live === null || session === null ? [] : wandDots(live, session);
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
          <Text style={styles.bodyStrong} testID={`${testID}-result-hint`}>
            {S.hint.letGo}
          </Text>
        )}
        {/* The learning point (GAMES_SPEC §5.1, CAT_SPEC C11 / G1): the hunt must end with the catch. */}
        <Text style={styles.muted} testID={`${testID}-result-learn`}>
          {S.result.learn}
        </Text>
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
        testID={`${testID}-let-go`}
        accessibilityRole="button"
        accessibilityLabel={S.letGoButton}
        accessibilityHint={S.a11y.letGoHint}
        onPress={letGo}
        style={({ pressed }) => [styles.primary, pressed && styles.pressed]}
      >
        <Hand color={palette.graphite} size={24} />
        <Text style={styles.primaryText}>{S.letGoButton}</Text>
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
          <CueRing size={size} progress={cueProgress} />
          <FeatherSprite size={size} fx={fxv} fy={fyv} trail={trail} showTrail={!reduceMotion} paw={paw} />
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
          progressLabel={S.a11y.progress(stars, dots.length)}
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
