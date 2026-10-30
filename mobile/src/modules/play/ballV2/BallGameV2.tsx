/**
 * Ball game v2 on the native game layer (GAMES_SPEC §4.3, phase 1; David 2026-10-30).
 *
 * Reached ONLY through `PlayOverlay#loadBallGameV2` when `gameLayerAvailable()` — this file
 * imports gesture-handler, reanimated, skia, expo-audio and expo-haptics (an older binary keeps
 * the v1 `BallGame`).
 *
 * - Full screen (`GameScreen`): the dog edge to edge on a `ClipDeck` (every ball clip preloaded
 *   once; the clock starts when the deck is ready, 12 s → »Slika«), the chain from
 *   `useClipQueue` (hub loop → run → [held on the empty room while the child may call] →
 *   return → hub — never a cut mid-clip, never a jump).
 * - The round is `ballMachine.ts` (pure); the ball is drawn on the UI thread (`PlayField` +
 *   `BallSprite`, 2.5D `ballFlight.ts`) and follows the video: it leaves the frame on the side
 *   the dog runs to (`media.phaseMeta.ballExitSide`) and comes back rolling to the camera.
 * - Gestures: pull the ball up and let go (Pan on the UI thread, `classifyRelease`); tap the
 *   rolling ball to catch it (Tap, hit-tested on the UI thread). »Lažje kretnje« / screen
 *   reader (`inputMode = hold`): »Vrzi žogo« (hold = power, let go = throw; activate = throw),
 *   »Ujemi žogo«; »Pokliči!« is a button for everyone.
 * - GameFx: sounds + haptics only for good moments, off when the parent switched »Zvoki in
 *   vibracija v igrah« off (`settings.game_feedback`). Reduce motion: the ball doesn't fly or
 *   roll (it vanishes and reappears in the hand), no pulsing, no pop-in.
 * - One `onRoundFinished()` per finished round (the HUD sends `POST /api/child/pet/play
 *   {kind:"play"}`); stars are display only. Closing mid-round reports nothing.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AccessibilityInfo, Animated as RNAnimated, Platform, Pressable, StyleSheet, View } from 'react-native';
import { Circle, Group, Oval } from '@shopify/react-native-skia';
import { useDerivedValue, useSharedValue, withSpring, withTiming, Easing, type SharedValue } from 'react-native-reanimated';
import { scheduleOnRN } from 'react-native-worklets';
import { CircleDot, Hand, Megaphone, Star } from 'lucide-react-native';

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
import PlayField, { useFieldSize, type FieldSizeValue } from '@/modules/gameLayer/PlayField';
import { useClipQueue } from '@/modules/gameLayer/useClipQueue';
import { useGameFx } from '@/modules/gameLayer/useGameFx';
import { useGameGestures, type GameGestureHandlers } from '@/modules/gameLayer/useGameGestures';
import { BALL_CHARGE_MS, chargePower } from '@/modules/gestures/holdPlay';
import type { ExitSide } from '@/modules/petMedia/petMedia';
import type { MediaMode } from '@/modules/petMedia/statePlayer';
import { ballDeck } from '@/modules/play/ballV2/ballDeck';
import { ballAtRest, ballInFlight, ballReturning, classifyRelease, FLIGHT_MS, isCatchTap, type BallFrame } from '@/modules/play/ballV2/ballFlight';
import {
  BALL_V2_START,
  ROLL_MS,
  ballCues,
  ballDots,
  ballLine,
  ballRole,
  ballStep,
  ballTimer,
  type BallEvent,
  type BallFeedbackLabel,
  type BallGameState,
} from '@/modules/play/ballV2/ballMachine';
import { BALL_V2_STRINGS, MOMENT_STRINGS } from '@/modules/play/play';
import { alpha, fonts, palette, radius, tightTracking } from '@/theme';

export interface BallGameV2Props {
  view: ChildPetView;
  reduceMotion: boolean;
  /** A round of 3 throws is over (called once per round). */
  onRoundFinished: () => void;
  onClose: () => void;
  onMediaExpired?: () => void;
  testID?: string;
}

/** Ball drawing modes (shared value, read on the UI thread). */
const AT_REST = 0;
const IN_FLIGHT = 1;
const HIDDEN = 2;
const RETURNING = 3;

/** Only the bottom of the screen starts a throw (the hand's third, §3.1). */
const HAND_ZONE_FROM = 0.55;

const FEEDBACK_MS: Readonly<Record<BallFeedbackLabel, number>> = { throw: 800, called: 1_000, caught: 1_000 };

/** The ball, drawn on the UI thread from its frame. */
function BallSprite({ frame }: { frame: SharedValue<BallFrame> }) {
  const shadowRect = useDerivedValue(() => {
    const f = frame.value;
    return { x: f.shadowX - f.shadowRx, y: f.shadowY - f.shadowRy, width: f.shadowRx * 2, height: f.shadowRy * 2 };
  });
  const shadowX = useDerivedValue(() => shadowRect.value.x);
  const shadowY = useDerivedValue(() => shadowRect.value.y);
  const shadowW = useDerivedValue(() => shadowRect.value.width);
  const shadowH = useDerivedValue(() => shadowRect.value.height);
  const shadowOpacity = useDerivedValue(() => frame.value.shadowOpacity);
  const cx = useDerivedValue(() => frame.value.x);
  const cy = useDerivedValue(() => frame.value.y);
  const r = useDerivedValue(() => frame.value.r);
  const opacity = useDerivedValue(() => frame.value.opacity);
  const shineX = useDerivedValue(() => frame.value.x - frame.value.r * 0.32);
  const shineY = useDerivedValue(() => frame.value.y - frame.value.r * 0.34);
  const shineR = useDerivedValue(() => frame.value.r * 0.3);
  const seamR = useDerivedValue(() => frame.value.r * 0.68);
  const seamW = useDerivedValue(() => Math.max(1, frame.value.r * 0.07));
  return (
    <Group>
      <Oval x={shadowX} y={shadowY} width={shadowW} height={shadowH} color={palette.graphite} opacity={shadowOpacity} />
      <Group opacity={opacity}>
        <Circle cx={cx} cy={cy} r={r} color={palette.mint} />
        <Circle cx={cx} cy={cy} r={seamR} color={alpha(palette.graphite, 0.3)} style="stroke" strokeWidth={seamW} />
        <Circle cx={shineX} cy={shineY} r={shineR} color={alpha(palette.white, 0.55)} />
      </Group>
    </Group>
  );
}

export default function BallGameV2({ view, reduceMotion, onRoundFinished, onClose, onMediaExpired, testID = 'play-ball-v2' }: BallGameV2Props) {
  const S = BALL_V2_STRINGS;
  const media = view.pet.media;
  // Decided once per game: the clips never change under a running game.
  const [plan] = useState(() => ballDeck(media, mediaModeOf(view)));
  const exitSide: ExitSide | null = media.phaseMeta.ballExitSide;
  const clock = useGameClock();
  const queue = useClipQueue(clock.now);
  const [deckMode, setDeckMode] = useState<MediaMode | null>(null);
  const [game, setGame] = useState<BallGameState>(BALL_V2_START);
  const gameRef = useRef(game);
  const fx = useGameFx(gameFeedbackOf(view));
  const line = useGameLine();
  const feedback = useRef<FeedbackHandle>(null);
  const roundRef = useRef(onRoundFinished);
  roundRef.current = onRoundFinished;

  // ── Ball on the UI thread ──
  const size = useFieldSize();
  const ballMode = useSharedValue(AT_REST);
  const progress = useSharedValue(0);
  const power = useSharedValue(1);
  const side = useSharedValue<ExitSide | null>(exitSide);
  const dragX = useSharedValue(0);
  const dragY = useSharedValue(0);
  const releaseX = useSharedValue(0);
  const releaseY = useSharedValue(0);
  const dragging = useSharedValue(false);
  const aimOpen = useSharedValue(false);
  const catchOpen = useSharedValue(false);

  const frame = useDerivedValue<BallFrame>(() => {
    const s: FieldSizeValue = size.value;
    const m = ballMode.value;
    if (m === IN_FLIGHT) return ballInFlight(s, progress.value, power.value, side.value, releaseX.value, releaseY.value);
    if (m === RETURNING) return ballReturning(s, progress.value);
    const rest = ballAtRest(s, dragX.value, dragY.value);
    return m === HIDDEN ? { ...rest, opacity: 0, shadowOpacity: 0 } : rest;
  });

  // ── The round ──
  const dispatch = useCallback(
    (event: BallEvent) => {
      const prev = gameRef.current;
      const next = ballStep(prev, event);
      if (next === prev) return;
      gameRef.current = next;
      setGame(next);
      if (ballRole(next) !== ballRole(prev)) queue.request(ballRole(next));
      for (const cue of ballCues(prev, next)) {
        if (cue.kind === 'sound') fx.sound(cue.sound, cue.delayMs);
        else if (cue.kind === 'haptic') fx.haptic(cue.haptic);
        else if (cue.kind === 'feedback') {
          void feedback.current?.show(cue.feedback, { label: S.fb[cue.label], totalMs: FEEDBACK_MS[cue.label] });
        } else if (cue.kind === 'roundDone') {
          roundRef.current();
          if (Platform.OS === 'ios') AccessibilityInfo.announceForAccessibility(S.done);
        }
      }
    },
    [queue, fx, S],
  );
  const dispatchRef = useRef(dispatch);
  dispatchRef.current = dispatch;
  const throwFromGesture = useCallback((p: number) => dispatchRef.current({ type: 'throw', power: p }), []);
  const catchFromGesture = useCallback(() => dispatchRef.current({ type: 'catch' }), []);

  const onDeckReady = useCallback(
    (mode: MediaMode) => {
      clock.start();
      // A deck that fell back to stills runs the game on the stored lengths alone.
      const specs = mode === plan.mode ? plan.specs : ballDeck(media, mode).specs;
      queue.start(specs, plan.initial);
      setDeckMode(mode);
    },
    [clock, plan, media, queue],
  );
  const playing = deckMode !== null;

  // The phase's picture: where the ball is and which gestures are open.
  useEffect(() => {
    const phase = game.phase;
    aimOpen.value = playing && phase === 'aim';
    catchOpen.value = phase === 'rolling';
    if (phase === 'aim' || phase === 'done') {
      ballMode.value = AT_REST;
      dragX.value = 0;
      dragY.value = 0;
    } else if (phase === 'flying') {
      power.value = game.power;
      side.value = exitSide;
      if (reduceMotion) {
        ballMode.value = HIDDEN;
      } else {
        progress.value = 0;
        ballMode.value = IN_FLIGHT;
        progress.value = withTiming(1, { duration: FLIGHT_MS, easing: Easing.linear });
      }
    } else if (phase === 'call' || phase === 'returning') {
      ballMode.value = HIDDEN;
    } else if (phase === 'rolling') {
      if (reduceMotion) {
        ballMode.value = AT_REST;
      } else {
        progress.value = 0;
        ballMode.value = RETURNING;
        progress.value = withTiming(1, { duration: ROLL_MS, easing: Easing.linear });
      }
    }
  }, [game.phase, game.power, playing, reduceMotion, exitSide, aimOpen, catchOpen, ballMode, dragX, dragY, power, side, progress]);

  // The clips drive the round: the run / the return ended → next act.
  const q = queue.state;
  useEffect(() => {
    if (q === null || !q.ended) return;
    if (game.phase === 'flying' && q.current === 'running') dispatch({ type: 'runEnded' });
    else if (game.phase === 'returning' && q.current === 'fetching') dispatch({ type: 'fetchEnded' });
  }, [q, game.phase, dispatch]);

  // Gestures (worklets on the UI thread; built once — they only read shared values).
  const handlers = useMemo<GameGestureHandlers>(
    () => ({
      onPanBegin: (e) => {
        'worklet';
        dragging.value = aimOpen.value && e.y >= size.value.height * HAND_ZONE_FROM;
      },
      onPanUpdate: (e) => {
        'worklet';
        if (!dragging.value) return;
        dragX.value = e.translationX * 0.5;
        dragY.value = Math.min(0, e.translationY) * 0.85;
      },
      onPanEnd: (e) => {
        'worklet';
        if (!dragging.value) return;
        dragging.value = false;
        const p = classifyRelease(e.translationY, e.velocityY);
        if (p !== null && aimOpen.value) {
          aimOpen.value = false;
          releaseX.value = dragX.value;
          releaseY.value = dragY.value;
          dragX.value = 0;
          dragY.value = 0;
          scheduleOnRN(throwFromGesture, p);
        } else {
          dragX.value = withSpring(0);
          dragY.value = withSpring(0);
        }
      },
      onTap: (e) => {
        'worklet';
        if (!catchOpen.value) return;
        if (!isCatchTap(frame.value, e.x, e.y)) return;
        catchOpen.value = false;
        scheduleOnRN(catchFromGesture);
      },
    }),
    [aimOpen, catchOpen, dragging, dragX, dragY, releaseX, releaseY, size, frame, throwFromGesture, catchFromGesture],
  );
  const gestures = useGameGestures(view, handlers);
  const easy = gestures.inputMode === 'hold';
  // One timer per phase (call wait, catch window — wider in hold mode).
  useEffect(() => {
    const timer = ballTimer(game, easy);
    if (timer === null) return;
    const handle = setTimeout(() => dispatchRef.current(timer.event), timer.ms);
    return () => clearTimeout(handle);
  }, [game, easy]);

  // The one line: the rule before the first throw, »getting ready« before the deck.
  const say = line.say;
  useEffect(() => {
    if (!playing) say(S.preparing);
    else say(ballLine(game) === 'hint' ? (easy ? S.holdHint : S.hint) : null);
  }, [playing, game, easy, say, S]);

  // ── Hold-mode throw (»Lažje kretnje« / screen reader) ──
  const chargeStart = useRef<number | null>(null);
  const charge = useRef(new RNAnimated.Value(0)).current;
  const [charging, setCharging] = useState(false);
  const startCharge = () => {
    if (!playing || gameRef.current.phase !== 'aim') return;
    chargeStart.current = Date.now();
    setCharging(true);
    charge.setValue(0);
    if (!reduceMotion) RNAnimated.timing(charge, { toValue: 1, duration: BALL_CHARGE_MS, useNativeDriver: true }).start();
  };
  const endCharge = () => {
    charge.stopAnimation();
    charge.setValue(0);
    setCharging(false);
  };
  const releaseCharge = () => {
    if (chargeStart.current === null) return;
    const held = chargePower(Date.now() - chargeStart.current);
    chargeStart.current = null;
    endCharge();
    releaseX.value = 0;
    releaseY.value = 0;
    dispatch({ type: 'throw', power: Math.max(0.35, held) });
  };

  // »Pokliči!« pulses while the dog waits (no pulse with reduce motion).
  const pulse = useRef(new RNAnimated.Value(1)).current;
  useEffect(() => {
    if (game.phase !== 'call' || reduceMotion) {
      pulse.setValue(1);
      return;
    }
    const loop = RNAnimated.loop(
      RNAnimated.sequence([
        RNAnimated.timing(pulse, { toValue: 1.08, duration: 420, useNativeDriver: true }),
        RNAnimated.timing(pulse, { toValue: 1, duration: 420, useNativeDriver: true }),
      ]),
    );
    loop.start();
    return () => loop.stop();
  }, [game.phase, reduceMotion, pulse]);

  const deckCurrent = useMemo(
    () => (q === null ? null : { role: q.current, instance: q.instance, lastSwitch: q.lastSwitch }),
    [q],
  );

  const dock = !playing ? null : game.phase === 'call' ? (
    <RNAnimated.View style={{ transform: [{ scale: pulse }] }}>
      <Pressable
        testID={`${testID}-call`}
        accessibilityRole="button"
        accessibilityLabel={S.a11y.call}
        onPress={() => dispatch({ type: 'call' })}
        style={({ pressed }) => [styles.primary, pressed && styles.pressed]}
      >
        <Megaphone color={palette.graphite} size={24} />
        <Text style={styles.primaryText}>{S.call}</Text>
      </Pressable>
    </RNAnimated.View>
  ) : easy && game.phase === 'aim' ? (
    <Pressable
      testID={`${testID}-throw`}
      accessibilityRole="button"
      accessibilityLabel={S.a11y.throw}
      accessibilityHint={S.a11y.throwHint}
      onPressIn={startCharge}
      onPressOut={releaseCharge}
      onPress={() => {
        // A screen reader's activate has no press-in: throw at once.
        if (chargeStart.current === null && gameRef.current.phase === 'aim') dispatch({ type: 'throw', power: 1 });
      }}
      style={({ pressed }) => [styles.secondary, pressed && styles.pressed]}
    >
      {charging && (
        <RNAnimated.View
          pointerEvents="none"
          testID={`${testID}-charge`}
          style={[styles.chargeFill, reduceMotion ? styles.chargeStatic : { transform: [{ scaleX: charge }] }]}
        />
      )}
      <CircleDot color={palette.mint} size={20} />
      <Text style={styles.secondaryText}>{S.a11y.throw}</Text>
    </Pressable>
  ) : easy && game.phase === 'rolling' ? (
    <Pressable
      testID={`${testID}-catch`}
      accessibilityRole="button"
      accessibilityLabel={S.a11y.catch}
      onPress={() => {
        catchOpen.value = false;
        dispatch({ type: 'catch' });
      }}
      style={({ pressed }) => [styles.catchButton, pressed && styles.pressed]}
    >
      <Hand color={palette.graphite} size={28} />
      <Text style={styles.primaryText}>{S.catch}</Text>
    </Pressable>
  ) : null;

  const result =
    game.phase === 'done' ? (
      <View style={styles.resultBackdrop} testID={`${testID}-done`}>
        <View style={styles.resultCard}>
          <View style={styles.resultStars} accessible accessibilityLabel={S.a11y.stars(game.stars)}>
            {[0, 1, 2].map((i) => (
              <Star key={i} size={40} color={palette.warnDark} fill={i < game.stars ? palette.warnDark : 'transparent'} />
            ))}
          </View>
          <Text style={styles.resultText} accessibilityLiveRegion="polite">
            {S.done}
          </Text>
          <Pressable
            testID={`${testID}-again`}
            accessibilityRole="button"
            onPress={() => dispatch({ type: 'again' })}
            style={({ pressed }) => [styles.primary, styles.wide, pressed && styles.pressed]}
          >
            <Text style={styles.primaryText}>{S.again}</Text>
          </Pressable>
          <Pressable
            testID={`${testID}-finish`}
            accessibilityRole="button"
            onPress={onClose}
            style={({ pressed }) => [styles.secondary, styles.wide, pressed && styles.pressed]}
          >
            <Text style={styles.secondaryText}>{MOMENT_STRINGS.finish}</Text>
          </Pressable>
        </View>
      </View>
    ) : null;

  return (
    <GameScreen
      testID={testID}
      stage={
        <ClipDeck
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
          <BallSprite frame={frame} />
        </PlayField>
      }
      feedback={<FeedbackLayer ref={feedback} reduceMotion={reduceMotion} onMaskChange={queue.masked} testID={`${testID}-feedback`} />}
      topBar={
        <GameTopBar
          dots={ballDots(game)}
          stars={game.stars}
          line={line.text}
          closeLabel={MOMENT_STRINGS.close}
          progressLabel={S.a11y.progress(game.throws, game.stars)}
          onClose={onClose}
          testID={`${testID}-top`}
        />
      }
      dock={dock}
      overlay={result}
    />
  );
}

const styles = StyleSheet.create({
  primary: {
    minHeight: 64,
    minWidth: 200,
    paddingHorizontal: 28,
    borderRadius: radius.button,
    backgroundColor: palette.mint,
    flexDirection: 'row',
    gap: 10,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryText: { color: palette.graphite, fontSize: 22, letterSpacing: tightTracking(22), fontFamily: fonts.displayBold },
  secondary: {
    overflow: 'hidden',
    minHeight: 56,
    minWidth: 200,
    paddingHorizontal: 22,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.graphite, 0.7),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.5),
    flexDirection: 'row',
    gap: 10,
    alignItems: 'center',
    justifyContent: 'center',
  },
  secondaryText: { color: palette.white, fontSize: 18, fontWeight: '700' },
  catchButton: {
    width: 96,
    height: 96,
    borderRadius: 48,
    backgroundColor: palette.mint,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 2,
  },
  chargeFill: { ...StyleSheet.absoluteFill, backgroundColor: alpha(palette.mint, 0.3), transformOrigin: 'left' },
  chargeStatic: { backgroundColor: alpha(palette.mint, 0.25) },
  resultBackdrop: { ...StyleSheet.absoluteFill, backgroundColor: alpha(palette.graphite, 0.72), alignItems: 'center', justifyContent: 'center', padding: 24 },
  resultCard: {
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
  resultStars: { flexDirection: 'row', gap: 8 },
  resultText: { color: palette.white, fontSize: 24, letterSpacing: tightTracking(24), fontFamily: fonts.displayBold, textAlign: 'center' },
  wide: { alignSelf: 'stretch' },
  pressed: { opacity: 0.8 },
});
