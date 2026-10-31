/**
 * Cuddles v2 on the native game layer (GAMES_SPEC §4.4, C1, phase 4) — the 3-second rule.
 *
 * Reached ONLY through `PlayOverlay#loadCuddleGameV2` when `gameLayerAvailable()` (this file
 * imports gesture-handler, reanimated, skia, expo-audio and expo-haptics; an older binary keeps
 * the v1 five strokes).
 *
 * - Full screen: the pet's `playing` / `idle` loop edge to edge (`ClipDeck`, one role), three
 *   hearts at the top under the one game line.
 * - Strokes (Pan on the UI thread, samples handed to JS) feed the pure `cuddleMachine.ts`; hearts
 *   trail the finger (Skia), a soft haptic every 0.5 s, a purr every second, a heart sound when
 *   a heart is full; ✋ »Počakaj …« until the finger lifts; after the pause the pet asks 💗 »Še!«.
 * - »Lažje kretnje« / screen reader: the »Pobožaj« button (hold = slow stroking, let go = the
 *   pause; a screen reader's activate fills the heart).
 * - Reduce motion: no trail, no pulse.
 * - ONE `onDone()` per finished cuddle (the HUD sends `POST /api/child/pet/play {kind:"cuddle"}`).
 *   Closing early reports nothing. Dog and cat alike (species-neutral texts).
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AccessibilityInfo, Platform, Pressable, StyleSheet, View, type AccessibilityActionEvent } from 'react-native';
import { Group, Path } from '@shopify/react-native-skia';
import { useDerivedValue, useSharedValue, withTiming, Easing, type SharedValue } from 'react-native-reanimated';
import { scheduleOnRN } from 'react-native-worklets';
import { Hand, HandHeart, Heart } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { mediaModeOf } from '@/modules/display/display';
import ClipDeck from '@/modules/gameLayer/ClipDeck';
import FeedbackLayer, { type FeedbackHandle } from '@/modules/gameLayer/FeedbackLayer';
import { useGameClock } from '@/modules/gameLayer/gameClock';
import { gameFeedbackOf } from '@/modules/gameLayer/gameFeedback';
import { useGameLine } from '@/modules/gameLayer/GameLine';
import GameScreen from '@/modules/gameLayer/GameScreen';
import GameTopBar from '@/modules/gameLayer/GameTopBar';
import PlayField, { useFieldSize } from '@/modules/gameLayer/PlayField';
import { useClipQueue } from '@/modules/gameLayer/useClipQueue';
import { useGameFx } from '@/modules/gameLayer/useGameFx';
import type { GameSound } from '@/modules/gameLayer/gameFxCore';
import { useGameGestures, type GameGestureHandlers } from '@/modules/gameLayer/useGameGestures';
import type { MediaMode } from '@/modules/petMedia/statePlayer';
import { cuddleDeck, cuddleHasPicture } from '@/modules/play/cuddleV2/cuddleDeck';
import {
  CUDDLE_HEARTS,
  CUDDLE_HOLD_TICK_MS,
  CUDDLE_START,
  cuddleCues,
  cuddleLine,
  cuddleNextTick,
  cuddleStep,
  heartFill,
  type CuddleEvent,
  type CuddleState,
} from '@/modules/play/cuddleV2/cuddleMachine';
import { CUDDLE_V2_STRINGS, MOMENT_STRINGS } from '@/modules/play/play';
import { alpha, fonts, palette, radius, tightTracking } from '@/theme';

export interface CuddleGameV2Props {
  view: ChildPetView;
  reduceMotion: boolean;
  /** The cuddle is finished (called once). */
  onDone: () => void;
  onClose: () => void;
  onMediaExpired?: () => void;
  testID?: string;
}

const S = CUDDLE_V2_STRINGS;
const TRAIL = 6;
const CUDDLE_SOUNDS: readonly GameSound[] = ['purr', 'heart', 'chime'];
const SPAWN_EVERY_MS = 140;
const HEART_RISE_MS = 900;
/** A heart, centred on (0, 0), ~28 pt wide. */
const HEART_PATH = 'M 0 9 C -14 -1 -14 -12 -7 -14 C -3 -15 0 -12 0 -9 C 0 -12 3 -15 7 -14 C 14 -12 14 -1 0 9 Z';

function TrailHeart({ x, y, p }: { x: SharedValue<number>; y: SharedValue<number>; p: SharedValue<number> }) {
  const transform = useDerivedValue(() => [{ translateX: x.value }, { translateY: y.value - p.value * 60 }, { scale: 1 + p.value * 0.4 }]);
  const opacity = useDerivedValue(() => (p.value <= 0 || p.value >= 1 ? 0 : 1 - p.value));
  return (
    <Group transform={transform} opacity={opacity}>
      <Path path={HEART_PATH} color={palette.raspberry} />
    </Group>
  );
}

function HeartsRow({ state, testID }: { state: CuddleState; testID: string }) {
  return (
    <View style={styles.hearts} testID={testID}>
      {Array.from({ length: CUDDLE_HEARTS }, (_, i) => {
        const f = heartFill(state, i);
        return (
          <View key={i} style={styles.heartSlot} testID={`${testID}-${f >= 1 ? 'full' : 'open'}`}>
            <Heart color={palette.raspberry} size={30} />
            <View style={[styles.heartFill, { height: `${Math.round(f * 100)}%` }]}>
              <Heart color={palette.raspberry} fill={palette.raspberry} size={30} />
            </View>
          </View>
        );
      })}
    </View>
  );
}

export default function CuddleGameV2({ view, reduceMotion, onDone, onClose, onMediaExpired, testID = 'play-cuddle-v2' }: CuddleGameV2Props) {
  const media = view.pet.media;
  const [plan] = useState(() => cuddleDeck(media, mediaModeOf(view)));
  const [hasPicture] = useState(() => cuddleHasPicture(media));
  const clock = useGameClock();
  const queue = useClipQueue(clock.now);
  const fx = useGameFx(gameFeedbackOf(view), CUDDLE_SOUNDS);
  const line = useGameLine();
  const feedback = useRef<FeedbackHandle>(null);
  const [game, setGame] = useState<CuddleState>(CUDDLE_START);
  const gameRef = useRef(game);
  const doneRef = useRef(onDone);
  doneRef.current = onDone;

  const onDeckReady = useCallback(
    (m: MediaMode) => {
      clock.start();
      queue.start(m === plan.mode ? plan.specs : cuddleDeck(media, m).specs, plan.initial);
    },
    [clock, queue, plan, media],
  );

  // ── Hearts trail (UI thread, spawned from JS) ──
  const size = useFieldSize();
  const slots = [
    { x: useSharedValue(0), y: useSharedValue(0), p: useSharedValue(0) },
    { x: useSharedValue(0), y: useSharedValue(0), p: useSharedValue(0) },
    { x: useSharedValue(0), y: useSharedValue(0), p: useSharedValue(0) },
    { x: useSharedValue(0), y: useSharedValue(0), p: useSharedValue(0) },
    { x: useSharedValue(0), y: useSharedValue(0), p: useSharedValue(0) },
    { x: useSharedValue(0), y: useSharedValue(0), p: useSharedValue(0) },
  ];
  const slotsRef = useRef(slots);
  const nextSlot = useRef(0);
  const lastSpawn = useRef(0);
  const spawn = useCallback(
    (x: number, y: number, at: number) => {
      if (reduceMotion || at - lastSpawn.current < SPAWN_EVERY_MS) return;
      lastSpawn.current = at;
      const slot = slotsRef.current[nextSlot.current++ % TRAIL];
      slot.x.value = x;
      slot.y.value = y;
      slot.p.value = 0.001;
      slot.p.value = withTiming(1, { duration: HEART_RISE_MS, easing: Easing.out(Easing.quad) });
    },
    [reduceMotion],
  );

  // ── The machine ──
  const say = line.say;
  const dispatch = useCallback(
    (event: CuddleEvent) => {
      const prev = gameRef.current;
      const next = cuddleStep(prev, event);
      if (next === prev) return;
      gameRef.current = next;
      setGame(next);
      for (const cue of cuddleCues(prev, next)) {
        if (cue.kind === 'haptic') fx.haptic('tap');
        else if (cue.kind === 'purr') fx.sound('purr');
        else if (cue.kind === 'heart') {
          fx.sound('heart');
          fx.haptic('success');
        } else if (cue.kind === 'ask') {
          fx.haptic('cue');
          if (Platform.OS === 'ios') AccessibilityInfo.announceForAccessibility(S.more);
        } else if (cue.kind === 'done') {
          void feedback.current?.show('ok', { totalMs: 1_200 });
          fx.sound('chime');
          fx.haptic('success');
          doneRef.current();
        }
      }
    },
    [fx],
  );
  useEffect(() => {
    const l = cuddleLine(game);
    say(l === 'slow' ? S.slow : l === 'wait' ? S.wait : l === 'more' ? S.more : S.doneLine);
  }, [game, say]);
  useEffect(() => {
    const at = cuddleNextTick(game);
    if (at === null) return;
    const timer = setTimeout(() => dispatch({ type: 'tick', at: Date.now() }), Math.max(0, at - Date.now()));
    return () => clearTimeout(timer);
  }, [game, dispatch]);

  const onDown = useCallback(() => dispatch({ type: 'down', at: Date.now() }), [dispatch]);
  const onMove = useCallback(
    (x: number, y: number, speed: number) => {
      const at = Date.now();
      dispatch({ type: 'move', at, speed });
      if (gameRef.current.phase === 'stroking' && gameRef.current.tooFastUntil === null) spawn(x, y, at);
    },
    [dispatch, spawn],
  );
  const onUp = useCallback(() => dispatch({ type: 'up', at: Date.now() }), [dispatch]);

  const handlers = useMemo<GameGestureHandlers>(
    () => ({
      onPanBegin: () => {
        'worklet';
        scheduleOnRN(onDown);
      },
      onPanUpdate: (e) => {
        'worklet';
        scheduleOnRN(onMove, e.x, e.y, Math.hypot(e.velocityX, e.velocityY));
      },
      onPanEnd: () => {
        'worklet';
        scheduleOnRN(onUp);
      },
    }),
    [onDown, onMove, onUp],
  );
  const gestures = useGameGestures(view, handlers);
  const easy = gestures.inputMode === 'hold';

  // ── »Pobožaj« (hold mode) ──
  const holdTimer = useRef<ReturnType<typeof setInterval> | null>(null);
  const pressedIn = useRef(false);
  const stopHold = useCallback(() => {
    if (holdTimer.current !== null) clearInterval(holdTimer.current);
    holdTimer.current = null;
  }, []);
  useEffect(() => stopHold, [stopHold]);
  const holdIn = () => {
    pressedIn.current = true;
    stopHold();
    dispatch({ type: 'hold', at: Date.now(), dtMs: 0 });
    holdTimer.current = setInterval(() => dispatch({ type: 'hold', at: Date.now(), dtMs: CUDDLE_HOLD_TICK_MS }), CUDDLE_HOLD_TICK_MS);
  };
  const holdOut = () => {
    stopHold();
    dispatch({ type: 'up', at: Date.now() });
  };
  const holdPress = () => {
    // A screen reader's activate has no press-in: fill the heart at once (then the pause).
    if (!pressedIn.current) dispatch({ type: 'complete', at: Date.now() });
    pressedIn.current = false;
  };
  const onA11yAction = (e: AccessibilityActionEvent) => {
    if (e.nativeEvent.actionName === 'activate') dispatch({ type: 'complete', at: Date.now() });
  };
  useEffect(() => {
    if (game.phase === 'done') stopHold();
  }, [game.phase, stopHold]);

  const q = queue.state;
  const deckCurrent = useMemo(() => (q === null ? null : { role: q.current, instance: q.instance, lastSwitch: q.lastSwitch }), [q]);
  const waiting = game.phase === 'full' || game.phase === 'paused';

  const badges = (
    <View pointerEvents="none" style={styles.badges} importantForAccessibility="no-hide-descendants">
      {game.tooFastUntil !== null && (
        <View style={styles.badge} testID={`${testID}-slow`}>
          <Text style={styles.badgeEmoji}>🐢</Text>
        </View>
      )}
      {waiting && (
        <View style={styles.badge} testID={`${testID}-wait`}>
          <Hand color={palette.white} size={40} />
        </View>
      )}
      {game.phase === 'ask' && (
        <View style={[styles.badge, styles.bubble]} testID={`${testID}-more`}>
          <Text style={styles.badgeEmoji}>💗</Text>
          <Text style={styles.bubbleText}>{S.more}</Text>
        </View>
      )}
    </View>
  );

  const result =
    game.phase === 'done' ? (
      <View style={styles.backdrop} testID={`${testID}-done`}>
        <View style={styles.card}>
          <HeartsRow state={game} testID={`${testID}-done-hearts`} />
          <Text style={styles.cardText} accessibilityLiveRegion="polite">
            {S.done}
          </Text>
          <Pressable testID={`${testID}-finish`} accessibilityRole="button" onPress={onClose} style={({ pressed }) => [styles.primary, pressed && styles.pressed]}>
            <Text style={styles.primaryText}>{MOMENT_STRINGS.finish}</Text>
          </Pressable>
        </View>
      </View>
    ) : null;

  const dock =
    easy && game.phase !== 'done' ? (
      <Pressable
        testID={`${testID}-hold`}
        accessibilityRole="button"
        accessibilityLabel={S.button}
        accessibilityHint={S.buttonHint}
        accessibilityActions={[{ name: 'activate' }]}
        onAccessibilityAction={onA11yAction}
        onPressIn={holdIn}
        onPressOut={holdOut}
        onPress={holdPress}
        style={({ pressed }) => [styles.primary, pressed && styles.pressed]}
      >
        <HandHeart color={palette.graphite} size={24} />
        <Text style={styles.primaryText}>{S.button}</Text>
      </Pressable>
    ) : null;

  return (
    <GameScreen
      testID={testID}
      onExit={onClose}
      stage={
        hasPicture ? (
          <ClipDeck clips={plan.clips} current={deckCurrent} mode={plan.mode} onReady={onDeckReady} onEnded={queue.ended} onMediaExpired={onMediaExpired} testID={`${testID}-deck`} />
        ) : (
          <View style={styles.emojiStage} testID={`${testID}-illustration`}>
            <Text style={styles.emoji}>{view.pet.species === 'cat' ? '🐈' : '🐕'}</Text>
          </View>
        )
      }
      field={
        <PlayField size={size} gesture={gestures.gesture} testID={`${testID}-field`}>
          {slots.map((s, i) => (
            <TrailHeart key={i} x={s.x} y={s.y} p={s.p} />
          ))}
        </PlayField>
      }
      feedback={
        <>
          {badges}
          <FeedbackLayer ref={feedback} reduceMotion={reduceMotion} onMaskChange={queue.masked} testID={`${testID}-feedback`} />
        </>
      }
      topBar={
        <View style={styles.top}>
          <GameTopBar dots={[]} stars={0} line={line.text} closeLabel={MOMENT_STRINGS.close} progressLabel={S.a11y.progress(game.hearts)} onClose={onClose} testID={`${testID}-top`} />
          <HeartsRow state={game} testID={`${testID}-hearts`} />
        </View>
      }
      dock={dock}
      overlay={result}
    />
  );
}

const styles = StyleSheet.create({
  top: { gap: 10 },
  hearts: { flexDirection: 'row', justifyContent: 'center', gap: 14 },
  heartSlot: { width: 30, height: 30 },
  heartFill: { position: 'absolute', left: 0, right: 0, bottom: 0, overflow: 'hidden', justifyContent: 'flex-end' },
  badges: { ...StyleSheet.absoluteFill, alignItems: 'center', justifyContent: 'center' },
  badge: {
    minWidth: 84,
    minHeight: 84,
    paddingHorizontal: 16,
    borderRadius: 42,
    backgroundColor: alpha(palette.graphite, 0.66),
    alignItems: 'center',
    justifyContent: 'center',
    marginVertical: 6,
  },
  bubble: { flexDirection: 'row', gap: 8, borderRadius: radius.button },
  bubbleText: { color: palette.white, fontSize: 26, letterSpacing: tightTracking(26), fontFamily: fonts.displayBold },
  badgeEmoji: { fontSize: 40 },
  emojiStage: { ...StyleSheet.absoluteFill, alignItems: 'center', justifyContent: 'center' },
  emoji: { fontSize: 140 },
  backdrop: { ...StyleSheet.absoluteFill, backgroundColor: alpha(palette.graphite, 0.66), alignItems: 'center', justifyContent: 'center', padding: 24 },
  card: {
    alignSelf: 'stretch',
    alignItems: 'center',
    gap: 16,
    paddingVertical: 28,
    paddingHorizontal: 20,
    borderRadius: 28,
    backgroundColor: alpha(palette.graphite, 0.9),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.12),
  },
  cardText: { color: palette.white, fontSize: 22, letterSpacing: tightTracking(22), fontFamily: fonts.displayBold, textAlign: 'center' },
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
  pressed: { opacity: 0.8 },
});
