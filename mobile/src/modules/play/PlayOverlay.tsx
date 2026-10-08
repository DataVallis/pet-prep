/**
 * "Igra" — the play layer over the child HUD (M5-R05, PLAY_CUDDLE_SPEC §4; dark glass like
 * "Šola"). A separate layer on purpose: a future interactive 3D dog (§6.1) can replace it
 * without any API change — the server only learns "a ball game / a cuddle was finished".
 *
 * - `pick`: Žoga / Crkljanje.
 * - Ball game (§4.2): swipe the ball up and let go → the dog runs after it and brings it
 *   back; 3 throws = done. Every throw counts (no miss, no score). "Vrzi žogo" does the
 *   same for VoiceOver / TalkBack and anyone who can't swipe; no time limit between throws.
 * - Cuddles (§4.3): stroke the dog with a finger (≥ 60 pt of path per stroke, any
 *   direction); a heart per stroke; 5 strokes = done. "Drži in pobožaj" — holding it 3 s
 *   replaces the strokes; a screen reader's activate action cuddles at once.
 * - "Reduce motion": the ball doesn't fly across the screen and the hearts don't float —
 *   short fades only.
 * Gestures use RN `PanResponder` (no gesture-handler, no haptics: no new native module).
 * Closing (×) mid-game reports nothing and has no consequence. A finished game is reported
 * once (`onFinished`); the HUD sends it and shows the happy dog.
 */

import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Animated, PanResponder, Pressable, StyleSheet, View, type AccessibilityActionEvent } from 'react-native';
import { Text } from '@/components/ui/Text';
import { CircleDot, HandHeart, Heart, X } from 'lucide-react-native';

import PetMediaView from '@/components/PetMediaView';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { selectMediaSource } from '@/modules/petMedia/petMedia';
import {
  BALL_START,
  BALL_THROWS,
  ballFetched,
  CUDDLE_STRINGS,
  CUDDLE_STROKES,
  doneText,
  DONE_AUTO_CLOSE_MS,
  FETCH_MS,
  FETCH_MS_REDUCED,
  HOLD_MS,
  isBallDone,
  isStroke,
  isThrowRelease,
  MOMENT_STRINGS,
  PLAY_STRINGS,
  STROKE_START,
  throwBall,
  trackStroke,
  type BallState,
  type PlayKind,
} from '@/modules/play/play';
import type { PlayOverlayMode } from '@/store/appStore';
import { MIN_TOUCH, alpha, fonts, palette, radius, tightTracking } from '@/theme';

export interface PlayOverlayProps {
  view: ChildPetView;
  mode: PlayOverlayMode;
  /** From `pick`: start one of the games. */
  onPick: (kind: PlayKind) => void;
  /** The game was finished (called once per game). */
  onFinished: (kind: PlayKind) => void;
  onClose: () => void;
  reduceMotion: boolean;
  testID?: string;
}

/** Stage: the pet's own video (`playing`, else `idle`) when stored, else a calm illustration. */
function Stage({ view, children, onMediaExpired }: { view: ChildPetView; children?: ReactNode; onMediaExpired?: () => void }) {
  const hasVideo = useMemo(() => selectMediaSource(view.pet.media, 'playing').kind === 'video', [view.pet.media]);
  return (
    <View style={styles.stage}>
      {hasVideo ? (
        <PetMediaView
          media={view.pet.media}
          petState="playing"
          breed={view.pet.breed_type}
          onMediaExpired={onMediaExpired}
          variant="card"
          style={styles.video}
          testID="play-dog-video"
        />
      ) : null}
      {children}
    </View>
  );
}

function DogEmoji({ offset }: { offset: Animated.Value | Animated.AnimatedInterpolation<number> | number }) {
  return (
    <Animated.View style={[styles.dogCircle, { transform: [{ translateY: offset }] }]} testID="play-dog-illustration">
      <Text style={styles.dogEmoji}>🐕</Text>
    </Animated.View>
  );
}

// ── Ball game ─────────────────────────────────────────────────

function BallGame({ view, reduceMotion, onDone }: { view: ChildPetView; reduceMotion: boolean; onDone: () => void }) {
  const [ball, setBall] = useState<BallState>(BALL_START);
  const ballRef = useRef(ball);
  ballRef.current = ball;
  const drag = useRef(new Animated.ValueXY({ x: 0, y: 0 })).current;
  const flight = useRef(new Animated.Value(0)).current;
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const hasVideo = useMemo(() => selectMediaSource(view.pet.media, 'playing').kind === 'video', [view.pet.media]);

  useEffect(
    () => () => {
      if (timer.current) clearTimeout(timer.current);
    },
    [],
  );

  const doThrow = useCallback(() => {
    const next = throwBall(ballRef.current);
    if (next === ballRef.current) return;
    ballRef.current = next;
    setBall(next);
    const fetchMs = reduceMotion ? FETCH_MS_REDUCED : FETCH_MS;
    drag.setValue({ x: 0, y: 0 });
    flight.setValue(0);
    // Out and back: the ball flies up (or just fades with reduce motion) and the dog brings it.
    Animated.sequence([
      Animated.timing(flight, { toValue: 1, duration: fetchMs / 2, useNativeDriver: true }),
      Animated.timing(flight, { toValue: 0, duration: fetchMs / 2, useNativeDriver: true }),
    ]).start();
    // The game state moves on by the clock (not the animation callback): testable, never stuck.
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => {
      const fetched = ballFetched(ballRef.current);
      ballRef.current = fetched;
      setBall(fetched);
    }, fetchMs);
  }, [drag, flight, reduceMotion]);

  const doThrowRef = useRef(doThrow);
  doThrowRef.current = doThrow;

  const responder = useMemo(
    () =>
      PanResponder.create({
        onStartShouldSetPanResponder: () => !ballRef.current.fetching && ballRef.current.throws < BALL_THROWS,
        onMoveShouldSetPanResponder: () => !ballRef.current.fetching && ballRef.current.throws < BALL_THROWS,
        onPanResponderMove: (_e, g) => {
          // The ball follows the finger upwards only (a little sideways).
          drag.setValue({ x: g.dx * 0.5, y: Math.min(0, g.dy) });
        },
        onPanResponderRelease: (_e, g) => {
          if (isThrowRelease(g.dy, g.vy)) {
            doThrowRef.current();
          } else {
            Animated.spring(drag, { toValue: { x: 0, y: 0 }, useNativeDriver: true }).start();
          }
        },
        onPanResponderTerminate: () => {
          Animated.spring(drag, { toValue: { x: 0, y: 0 }, useNativeDriver: true }).start();
        },
      }),
    [drag],
  );

  const done = isBallDone(ball);
  useEffect(() => {
    if (done) onDone();
  }, [done, onDone]);

  const ballFly = reduceMotion
    ? { opacity: flight.interpolate({ inputRange: [0, 1], outputRange: [1, 0.15] }) }
    : {
        transform: [
          { translateY: flight.interpolate({ inputRange: [0, 1], outputRange: [0, -220] }) },
          { scale: flight.interpolate({ inputRange: [0, 1], outputRange: [1, 0.55] }) },
        ],
      };
  const dogRun = reduceMotion ? 0 : flight.interpolate({ inputRange: [0, 1], outputRange: [0, -40] });
  const status = ball.fetching ? PLAY_STRINGS.fetching : ball.throws === 0 ? PLAY_STRINGS.hint : PLAY_STRINGS.progress(ball.throws);

  return (
    <View style={styles.game} testID="play-ball">
      <Stage view={view}>{!hasVideo && <DogEmoji offset={dogRun} />}</Stage>
      <Text style={styles.status} testID="play-ball-status" accessibilityLiveRegion="polite">
        {status}
      </Text>
      <View style={styles.ballArea}>
        <Animated.View style={ballFly}>
          <Animated.View
            {...responder.panHandlers}
            testID="play-ball-target"
            importantForAccessibility="no"
            accessibilityElementsHidden
            style={[styles.ball, { transform: drag.getTranslateTransform() }]}
          >
            <View style={styles.ballSeam} />
          </Animated.View>
        </Animated.View>
      </View>
      <Pressable
        testID="play-throw"
        accessibilityRole="button"
        accessibilityLabel={PLAY_STRINGS.throwA11y}
        accessibilityState={{ disabled: ball.fetching }}
        disabled={ball.fetching}
        onPress={doThrow}
        style={({ pressed }) => [styles.secondaryButton, ball.fetching && styles.disabled, pressed && styles.pressed]}
      >
        <CircleDot color={palette.mint} size={18} />
        <Text style={styles.secondaryText}>{PLAY_STRINGS.throwButton}</Text>
      </Pressable>
    </View>
  );
}

// ── Cuddles ───────────────────────────────────────────────────

interface FloatingHeart {
  id: number;
  x: number;
  y: number;
}

const HEART_MS = 900;

function CuddleGame({ view, reduceMotion, onDone }: { view: ChildPetView; reduceMotion: boolean; onDone: () => void }) {
  const [strokes, setStrokes] = useState(0);
  const [hearts, setHearts] = useState<FloatingHeart[]>([]);
  const tracker = useRef(STROKE_START);
  const nextHeartId = useRef(1);
  const timers = useRef<Set<ReturnType<typeof setTimeout>>>(new Set());
  const holdTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const finished = useRef(false);
  const hasVideo = useMemo(() => selectMediaSource(view.pet.media, 'playing').kind === 'video', [view.pet.media]);

  useEffect(() => {
    const pending = timers.current;
    return () => {
      pending.forEach(clearTimeout);
      if (holdTimer.current) clearTimeout(holdTimer.current);
    };
  }, []);

  const strokesRef = useRef(0);
  const doneRef = useRef(onDone);
  doneRef.current = onDone;

  const finish = useCallback(() => {
    if (finished.current) return;
    finished.current = true;
    if (holdTimer.current) clearTimeout(holdTimer.current);
    strokesRef.current = CUDDLE_STROKES;
    setStrokes(CUDDLE_STROKES);
    doneRef.current();
  }, []);

  const addStroke = useCallback((x: number, y: number) => {
    if (finished.current) return;
    const id = nextHeartId.current++;
    setHearts((list) => [...list, { id, x, y }]);
    const t = setTimeout(() => {
      timers.current.delete(t);
      setHearts((list) => list.filter((h) => h.id !== id));
    }, HEART_MS);
    timers.current.add(t);
    const next = Math.min(CUDDLE_STROKES, strokesRef.current + 1);
    strokesRef.current = next;
    setStrokes(next);
    if (next >= CUDDLE_STROKES) {
      finished.current = true;
      doneRef.current();
    }
  }, []);

  const addStrokeRef = useRef(addStroke);
  addStrokeRef.current = addStroke;

  const responder = useMemo(
    () =>
      PanResponder.create({
        onStartShouldSetPanResponder: () => !finished.current,
        onMoveShouldSetPanResponder: () => !finished.current,
        onPanResponderGrant: () => {
          tracker.current = STROKE_START;
        },
        onPanResponderMove: (_e, g) => {
          tracker.current = trackStroke(tracker.current, g.dx, g.dy);
        },
        onPanResponderRelease: (e, g) => {
          tracker.current = trackStroke(tracker.current, g.dx, g.dy);
          if (isStroke(tracker.current)) addStrokeRef.current(e.nativeEvent.locationX, e.nativeEvent.locationY);
          tracker.current = STROKE_START;
        },
        onPanResponderTerminate: () => {
          tracker.current = STROKE_START;
        },
      }),
    [],
  );

  const startHold = () => {
    if (finished.current) return;
    if (holdTimer.current) clearTimeout(holdTimer.current);
    holdTimer.current = setTimeout(finish, HOLD_MS);
  };
  const endHold = () => {
    if (holdTimer.current) clearTimeout(holdTimer.current);
    holdTimer.current = null;
  };
  const onA11yAction = (event: AccessibilityActionEvent) => {
    if (event.nativeEvent.actionName === 'activate' || event.nativeEvent.actionName === 'cuddle') finish();
  };

  const status = strokes === 0 ? CUDDLE_STRINGS.hint : CUDDLE_STRINGS.progress(Math.min(strokes, CUDDLE_STROKES));

  return (
    <View style={styles.game} testID="play-cuddle">
      <View style={styles.cuddleArea} {...responder.panHandlers} testID="play-cuddle-area" accessible accessibilityLabel={CUDDLE_STRINGS.areaA11y}>
        <Stage view={view}>{!hasVideo && <DogEmoji offset={0} />}</Stage>
        {hearts.map((h) => (
          <CuddleHeart key={h.id} x={h.x} y={h.y} reduceMotion={reduceMotion} />
        ))}
      </View>
      <Text style={styles.status} testID="play-cuddle-status" accessibilityLiveRegion="polite">
        {status}
      </Text>
      <Pressable
        testID="play-hold"
        accessibilityRole="button"
        accessibilityLabel={CUDDLE_STRINGS.holdButton}
        accessibilityActions={[{ name: 'activate', label: CUDDLE_STRINGS.start }]}
        onAccessibilityAction={onA11yAction}
        onPressIn={startHold}
        onPressOut={endHold}
        style={({ pressed }) => [styles.secondaryButton, pressed && styles.holding]}
      >
        <HandHeart color={palette.mint} size={18} />
        <Text style={styles.secondaryText}>{CUDDLE_STRINGS.holdButton}</Text>
      </Pressable>
    </View>
  );
}

function CuddleHeart({ x, y, reduceMotion }: { x: number; y: number; reduceMotion: boolean }) {
  const v = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    Animated.timing(v, { toValue: 1, duration: HEART_MS, useNativeDriver: true }).start();
  }, [v]);
  const opacity = v.interpolate({ inputRange: [0, 0.2, 1], outputRange: [0, 1, 0] });
  const translateY = reduceMotion ? 0 : v.interpolate({ inputRange: [0, 1], outputRange: [0, -60] });
  return (
    <Animated.View pointerEvents="none" style={[styles.cuddleHeart, { left: x - 14, top: y - 14, opacity, transform: [{ translateY }] }]} testID="play-cuddle-heart">
      <Heart color={palette.raspberry} fill={palette.raspberry} size={28} />
    </Animated.View>
  );
}

// ── Overlay ───────────────────────────────────────────────────

export default function PlayOverlay({ view, mode, onPick, onFinished, onClose, reduceMotion, testID = 'play-overlay' }: PlayOverlayProps) {
  const [doneKind, setDoneKind] = useState<PlayKind | null>(null);
  const reported = useRef<PlayKind | null>(null);
  const finishedRef = useRef(onFinished);
  finishedRef.current = onFinished;
  const closeRef = useRef(onClose);
  closeRef.current = onClose;

  // A new game (mode change) starts fresh.
  useEffect(() => {
    setDoneKind(null);
    reported.current = null;
  }, [mode]);

  const finish = useCallback((kind: PlayKind) => {
    if (reported.current !== null) return;
    reported.current = kind;
    setDoneKind(kind);
    finishedRef.current(kind);
  }, []);
  const finishBall = useCallback(() => finish('play'), [finish]);
  const finishCuddle = useCallback(() => finish('cuddle'), [finish]);

  // The "thank you" card closes by itself.
  useEffect(() => {
    if (doneKind === null) return;
    const timer = setTimeout(() => closeRef.current(), DONE_AUTO_CLOSE_MS);
    return () => clearTimeout(timer);
  }, [doneKind]);

  return (
    <View style={styles.overlay} testID={testID} accessibilityViewIsModal>
      <View style={styles.header}>
        <View style={styles.titleRow}>
          <HandHeart color={palette.mint} size={22} />
          <Text style={styles.title}>{MOMENT_STRINGS.title}</Text>
        </View>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={MOMENT_STRINGS.close}
          testID="play-close"
          onPress={onClose}
          hitSlop={10}
          style={({ pressed }) => [styles.closeButton, pressed && styles.pressed]}
        >
          <X color={palette.n300} size={20} />
        </Pressable>
      </View>

      {doneKind !== null ? (
        <View style={styles.centered} testID="play-done">
          <Heart color={palette.raspberry} fill={palette.raspberry} size={44} />
          <Text style={styles.doneText} accessibilityLiveRegion="polite">
            {doneText(doneKind)}
          </Text>
          <Pressable
            accessibilityRole="button"
            testID="play-done-close"
            onPress={onClose}
            style={({ pressed }) => [styles.primaryButton, pressed && styles.pressed]}
          >
            <Text style={styles.primaryText}>{MOMENT_STRINGS.finish}</Text>
          </Pressable>
        </View>
      ) : mode === 'pick' ? (
        <View style={styles.centered} testID="play-pick">
          <Text style={styles.pickTitle}>{MOMENT_STRINGS.pickTitle}</Text>
          <Pressable
            testID="play-pick-ball"
            accessibilityRole="button"
            onPress={() => onPick('play')}
            style={({ pressed }) => [styles.pickButton, pressed && styles.pressed]}
          >
            <CircleDot color={palette.graphite} size={26} />
            <Text style={styles.pickText}>{PLAY_STRINGS.pickBall}</Text>
          </Pressable>
          <Pressable
            testID="play-pick-cuddle"
            accessibilityRole="button"
            onPress={() => onPick('cuddle')}
            style={({ pressed }) => [styles.pickButton, pressed && styles.pressed]}
          >
            <HandHeart color={palette.graphite} size={26} />
            <Text style={styles.pickText}>{PLAY_STRINGS.pickCuddle}</Text>
          </Pressable>
        </View>
      ) : mode === 'play' ? (
        <BallGame view={view} reduceMotion={reduceMotion} onDone={finishBall} />
      ) : (
        <CuddleGame view={view} reduceMotion={reduceMotion} onDone={finishCuddle} />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  overlay: {
    ...StyleSheet.absoluteFill,
    zIndex: 50,
    backgroundColor: alpha(palette.graphite, 0.96),
    paddingTop: 56,
    paddingHorizontal: 16,
    paddingBottom: 28,
  },
  header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
  titleRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  title: { color: palette.white, fontSize: 22, letterSpacing: tightTracking(22), fontFamily: fonts.display },
  closeButton: {
    width: MIN_TOUCH,
    height: MIN_TOUCH,
    borderRadius: 12,
    backgroundColor: alpha(palette.white, 0.08),
    alignItems: 'center',
    justifyContent: 'center',
  },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 16, paddingHorizontal: 12 },
  pickTitle: { color: palette.white, fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.displayBold, textAlign: 'center' },
  pickButton: {
    alignSelf: 'stretch',
    minHeight: 72,
    borderRadius: 24,
    backgroundColor: palette.mint,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 12,
  },
  pickText: { color: palette.graphite, fontSize: 22, letterSpacing: tightTracking(22), fontFamily: fonts.display },
  game: { flex: 1, gap: 12 },
  stage: {
    flex: 1,
    minHeight: 200,
    borderRadius: 24,
    overflow: 'hidden',
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.12),
    alignItems: 'center',
    justifyContent: 'center',
  },
  video: { ...StyleSheet.absoluteFill },
  dogCircle: {
    width: 140,
    height: 140,
    borderRadius: 70,
    backgroundColor: alpha(palette.white, 0.08),
    borderWidth: 2,
    borderColor: alpha(palette.white, 0.2),
    alignItems: 'center',
    justifyContent: 'center',
  },
  dogEmoji: { fontSize: 72 },
  status: { color: palette.white, fontSize: 17, fontWeight: '700', textAlign: 'center', minHeight: 24 },
  ballArea: { height: 110, alignItems: 'center', justifyContent: 'center' },
  ball: {
    width: 72,
    height: 72,
    borderRadius: 36,
    backgroundColor: palette.mint,
    borderWidth: 3,
    borderColor: alpha(palette.white, 0.6),
    alignItems: 'center',
    justifyContent: 'center',
  },
  ballSeam: { width: 52, height: 52, borderRadius: 26, borderWidth: 2, borderColor: alpha(palette.graphite, 0.35) },
  cuddleArea: { flex: 1 },
  cuddleHeart: { position: 'absolute' },
  secondaryButton: {
    minHeight: 52,
    paddingHorizontal: 18,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.white, 0.08),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.4),
    flexDirection: 'row',
    gap: 8,
    alignItems: 'center',
    justifyContent: 'center',
  },
  secondaryText: { color: palette.white, fontSize: 16, fontWeight: '700' },
  holding: { backgroundColor: alpha(palette.mint, 0.25) },
  primaryButton: {
    minHeight: 48,
    paddingHorizontal: 28,
    borderRadius: radius.button,
    backgroundColor: palette.mint,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryText: { color: palette.graphite, fontSize: 16, fontWeight: '800' },
  doneText: { color: palette.white, fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.displayBold, textAlign: 'center' },
  disabled: { opacity: 0.45 },
  pressed: { opacity: 0.75 },
});
