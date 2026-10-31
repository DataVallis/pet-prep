/**
 * School v2 on the native game layer (GAMES_SPEC §4.1 / §4.2, phase 3; David 2026-10-30, Š1–Š3, V5, V6).
 *
 * Reached ONLY through `TrainingOverlay#loadSchoolGameV2` when `gameLayerAvailable()` — this file
 * imports reanimated, expo-audio and expo-haptics (through the game layer); an older binary keeps
 * school v1 (`TrainingOverlay`'s RunningView) and starts its sessions without `rules`.
 *
 * Fixes what David saw on his phone on 2026-10-30:
 * - the dog fills the screen (`GameScreen` + `ClipDeck`: attention loop, performing, success loop —
 *   all preloaded once, the clock starts when they are ready, 12 s → »Slika«; never cut mid-clip);
 * - one big treat token (only with `training.treat_token`, else »Pohvali« ♥ — V6, display only);
 * - at most ONE line at the top, held ≥ 2 s (`GameLine`, momentary feedback only); no »posluša«
 *   text while the dog performs (👂 by the chip instead);
 * - the verdict is a big ✓ »Bravo!« / soft amber ✗ in the middle (`FeedbackLayer`), and the cut
 *   back to standing happens UNDER its mask (1200 ms) — the reversed »stand up« clip is never played;
 * - the command is a big chip (34 pt) with a cue sound (V5 interim — no recorded voice yet);
 * - 2 rounds × 3 cues; in the break »1. krog ✓« + stars and »Končaj« (Š3, while the server takes it).
 *
 * And what David's device test on 2026-10-31 showed (TestFlight, »Pridi«):
 * - the rule is a STRIP under the top bar for the whole session (`SchoolRuleStrip`, wraps, no ellipsis);
 * - beginner hint: while the command's progress is < 50 % the token glows / pulses and »Zdaj!«
 *   shows for the praise window (`praiseHintOn`; reduce motion = static highlight; a screen reader
 *   hears »Kuža je ubogal — pohvali zdaj«); nothing stored;
 * - a cue the dog ignores: a readable text chip »Ne uboga — ne pohvali, počakaj.« + what the dog
 *   does (»Kuža voha«) for the whole `ignoring` step (≥ 2.9 s) and a dimmed »Počakaj« token that
 *   stays tappable (a tap still counts as praised-without-obeying — server rule);
 * - the break's primary button is »Nadaljuj (3)« counting down to round 2's start on the server's
 *   timeline (never longer than the break); »Končaj« is a small text button with the same gating.
 * - tap OR drag (he tried to drag the treat to the dog): the praise counts at the press-in (first
 *   tap per cue decides, unchanged); a drag that follows moves the token with the finger and the
 *   treat flies to the dog from where it is let go (`TokenDrag`) — never a second tap, never a new
 *   time. A tap's flight starts at the release. Same for »Pohvali« ♥. A small bobbing finger +
 *   »Tapni« sits on the token while the beginner hint is on and nothing has counted yet
 *   (`tapHintOn`; reduce motion = static label).
 *
 * Timing is the server's schedule only (`schoolV2.ts`); one timer per step boundary keeps every
 * switch on time. Sounds + haptics follow the parent's »Zvoki in vibracija v igrah«; reduce motion =
 * no flight, no pop-in, no confetti; a screen reader gets the token as a button and hears the cue
 * and »Kuža se usede.« (announcement + live region).
 */

import { useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AccessibilityInfo, Platform, Pressable, StyleSheet, View, type GestureResponderEvent } from 'react-native';
import Animated, { Easing, cancelAnimation, useAnimatedStyle, useSharedValue, withRepeat, withSequence, withTiming } from 'react-native-reanimated';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { ActivityIndicator } from 'react-native';
import { Bone, Ear, Heart, Pointer } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { mediaModeOf } from '@/modules/display/display';
import ClipDeck from '@/modules/gameLayer/ClipDeck';
import FeedbackLayer, { type FeedbackHandle } from '@/modules/gameLayer/FeedbackLayer';
import { gameFeedbackOf } from '@/modules/gameLayer/gameFeedback';
import { useGameLine } from '@/modules/gameLayer/GameLine';
import GameScreen from '@/modules/gameLayer/GameScreen';
import GameTopBar from '@/modules/gameLayer/GameTopBar';
import { useClipQueue } from '@/modules/gameLayer/useClipQueue';
import { useGameFx } from '@/modules/gameLayer/useGameFx';
import type { MediaMode } from '@/modules/petMedia/statePlayer';
import { slotIndex } from '@/modules/training/game';
import { schoolDeck } from '@/modules/training/schoolV2/schoolDeck';
import SchoolRuleStrip from '@/modules/training/schoolV2/SchoolRuleStrip';
import TokenDrag from '@/modules/training/schoolV2/TokenDrag';
import {
  TOKEN_COOLDOWN_MS,
  breakCountdown,
  liveOutcomes,
  nextBoundaryAfter,
  praiseHintEnabled,
  praiseHintOn,
  praiseToken,
  schoolBoundaries,
  schoolCues,
  schoolFrameAt,
  starCount,
  tapFeedback,
  tapHintOn,
  tokenWaiting,
  type SchoolFeedback,
  type SchoolFrame,
} from '@/modules/training/schoolV2/schoolV2';
import { SCHOOL_V2_STRINGS, TRAINING_STRINGS, type TrainingSession } from '@/modules/training/training';
import type { TrainingGame } from '@/modules/training/useTrainingGame';
import { alpha, fonts, palette, radius, tightTracking } from '@/theme';

export interface SchoolGameV2Props {
  view: ChildPetView;
  game: TrainingGame;
  session: TrainingSession;
  taps: readonly number[];
  resumedAtMs: number | null;
  /** The schedule is here, the deck loads (no clock yet). */
  preparing: boolean;
  /** The session is being saved (full end, or an early end — the clock keeps running then). */
  finishing: boolean;
  reduceMotion: boolean;
  /** The school clips are still on their way → stills only. */
  posePending: boolean;
  onMediaExpired?: () => void;
  testID?: string;
}

const S = SCHOOL_V2_STRINGS;
const T = TRAINING_STRINGS;

const TREAT_FLIGHT_MS = 520;
const CONFETTI_MS = 1_600;
const CONFETTI_PIECES = 14;

function feedbackLabel(fb: SchoolFeedback): string {
  return S.fb[fb.label];
}

/** One flight: `n` counts the shots, `dx` / `dy` = where a drag let go (0 / 0 for a tap). */
interface FlightShot {
  n: number;
  dx: number;
  dy: number;
}

/**
 * The treat (or heart) flying in an arc from the token — or from where a drag let go — to the dog;
 * reduce motion = a short flash at the dog.
 */
function TreatFlight({ shot, token, reduceMotion }: { shot: FlightShot; token: 'treat' | 'heart'; reduceMotion: boolean }) {
  const progress = useSharedValue(0);
  const opacity = useSharedValue(0);
  const fromX = useSharedValue(0);
  const fromY = useSharedValue(0);
  useEffect(() => {
    if (shot.n === 0) return;
    fromX.value = shot.dx;
    fromY.value = shot.dy;
    if (reduceMotion) {
      progress.value = 1;
      opacity.value = withSequence(withTiming(1, { duration: 120 }), withTiming(0, { duration: 380 }));
      return;
    }
    progress.value = 0;
    opacity.value = 1;
    progress.value = withTiming(1, { duration: TREAT_FLIGHT_MS, easing: Easing.out(Easing.quad) });
    opacity.value = withSequence(withTiming(1, { duration: TREAT_FLIGHT_MS - 120 }), withTiming(0, { duration: 160 }));
  }, [shot, reduceMotion, progress, opacity, fromX, fromY]);
  const style = useAnimatedStyle(() => {
    const p = progress.value;
    // From the dock (bottom) — or the drag's release point — up to the dog (middle) in a soft arc.
    return {
      opacity: opacity.value,
      transform: [
        { translateX: fromX.value * (1 - p) + Math.sin(p * Math.PI) * 60 },
        { translateY: fromY.value * (1 - p) - p * 320 },
        { scale: 1 - p * 0.45 },
      ],
    };
  });
  return (
    // The shot count is in the wrapper's testID so tests can tell how many flights started.
    <View pointerEvents="none" style={[StyleSheet.absoluteFill, reduceMotion ? styles.flashArea : styles.flightArea]} testID={`school-v2-flights-${shot.n}`}>
      <Animated.View style={style} testID="school-v2-treat-flight">
        {token === 'treat' ? <Bone color={palette.warnDark} fill={palette.warnDark} size={44} /> : <Heart color={palette.raspberry} fill={palette.raspberry} size={44} />}
      </Animated.View>
    </View>
  );
}

/** A few mint pieces falling after round 1 (never with reduce motion). */
function Confetti({ burst }: { burst: number }) {
  const fall = useSharedValue(0);
  useEffect(() => {
    if (burst === 0) return;
    fall.value = 0;
    fall.value = withTiming(1, { duration: CONFETTI_MS, easing: Easing.in(Easing.quad) });
  }, [burst, fall]);
  const pieces = useMemo(() => Array.from({ length: CONFETTI_PIECES }, (_, i) => ({ x: (i * 37) % 100, delay: (i % 5) * 0.08, size: 6 + (i % 3) * 3 })), []);
  return (
    <View pointerEvents="none" style={StyleSheet.absoluteFill} testID="school-v2-confetti">
      {pieces.map((p, i) => (
        <ConfettiPiece key={i} fall={fall} x={p.x} delay={p.delay} size={p.size} mint={i % 3 !== 0} />
      ))}
    </View>
  );
}

function ConfettiPiece({ fall, x, delay, size, mint }: { fall: { value: number }; x: number; delay: number; size: number; mint: boolean }) {
  const style = useAnimatedStyle(() => {
    const p = Math.max(0, Math.min(1, (fall.value - delay) / (1 - delay)));
    return { opacity: p > 0 && p < 1 ? 1 - p * 0.6 : 0, transform: [{ translateY: p * 700 }, { rotate: `${p * 300}deg` }] };
  });
  return <Animated.View style={[styles.piece, { left: `${x}%`, width: size, height: size, backgroundColor: mint ? palette.mint : palette.white }, style]} />;
}

const PULSE_MS = 420;

/**
 * The beginner hint's glow around the token: a soft pulse while `on`; reduce motion = the static
 * highlight only (the ring style is applied by the caller either way).
 */
function TokenPulse({ on, reduceMotion, children }: { on: boolean; reduceMotion: boolean; children: ReactNode }) {
  const scale = useSharedValue(1);
  useEffect(() => {
    if (!on || reduceMotion) {
      cancelAnimation(scale);
      scale.value = 1;
      return;
    }
    scale.value = withRepeat(withSequence(withTiming(1.1, { duration: PULSE_MS }), withTiming(1, { duration: PULSE_MS })), -1, false);
    return () => cancelAnimation(scale);
  }, [on, reduceMotion, scale]);
  const style = useAnimatedStyle(() => ({ transform: [{ scale: scale.value }] }));
  return <Animated.View style={style} testID={on && !reduceMotion ? 'school-v2-token-pulse' : undefined}>{children}</Animated.View>;
}

const TAP_BOB_MS = 520;

/**
 * The discreet tap hint on the token (David 2026-10-31: »mogoče bi lahko tak diskreten namig bil
 * tap«): a small finger that bobs + »Tapni«; reduce motion = a static label. Hidden from the screen
 * reader — the token's button label already says what to do.
 */
function TapHint({ reduceMotion, testID }: { reduceMotion: boolean; testID: string }) {
  const bob = useSharedValue(0);
  useEffect(() => {
    if (reduceMotion) {
      cancelAnimation(bob);
      bob.value = 0;
      return;
    }
    bob.value = withRepeat(withSequence(withTiming(-5, { duration: TAP_BOB_MS }), withTiming(0, { duration: TAP_BOB_MS })), -1, false);
    return () => cancelAnimation(bob);
  }, [reduceMotion, bob]);
  const style = useAnimatedStyle(() => ({ transform: [{ translateY: bob.value }] }));
  return (
    <View pointerEvents="none" style={styles.tapHint} testID={testID} importantForAccessibility="no-hide-descendants" accessibilityElementsHidden>
      <Animated.View style={style} testID={reduceMotion ? undefined : `${testID}-anim`}>
        <Pointer color={palette.graphite} size={18} />
      </Animated.View>
      <Text style={styles.tapHintText} testID={`${testID}-text`}>
        {S.tapHint}
      </Text>
    </View>
  );
}

export default function SchoolGameV2({
  view,
  game,
  session,
  taps,
  resumedAtMs,
  preparing,
  finishing,
  reduceMotion,
  posePending,
  onMediaExpired,
  testID = 'school-v2',
}: SchoolGameV2Props) {
  const insets = useContext(SafeAreaInsetsContext) ?? { top: 0, bottom: 0, left: 0, right: 0 };
  const media = view.pet.media;
  const command = session.command;
  const token = praiseToken(view.training);
  // Beginner hint (David 2026-10-31): decided once per session from the command's progress at the start.
  const [hintEnabled] = useState(() => praiseHintEnabled(view.training.commands.find((c) => c.command === command)?.progress ?? 0));
  // Decided once per session: the clips never change under a running game.
  const [plan] = useState(() => schoolDeck(media, command, posePending || mediaModeOf(view) === 'picture' ? 'picture' : 'video', session));
  const queue = useClipQueue(game.now);
  const [deckMode, setDeckMode] = useState<MediaMode | null>(null);
  const fx = useGameFx(gameFeedbackOf(view));
  const line = useGameLine();
  const feedback = useRef<FeedbackHandle>(null);
  const boundaries = useMemo(() => schoolBoundaries(session), [session]);

  const onDeckReady = useCallback(
    (mode: MediaMode) => {
      game.begin();
      // A deck that fell back to stills runs the chain on the server's lengths alone.
      queue.start(mode === plan.mode ? plan.specs : schoolDeck(media, command, mode, session).specs, plan.initial);
      setDeckMode(mode);
    },
    [game, queue, plan, media, command, session],
  );

  // ── The clock: the hook ticks every 100 ms; one timer per step boundary keeps switches exact.
  const [tick, setTick] = useState(0);
  const clockRunning = !preparing;
  const e = clockRunning ? game.now() : 0;
  const frame: SchoolFrame = schoolFrameAt(session, e);
  const frameKey = `${frame.kind}|${frame.trial ?? ''}|${frame.round}|${frame.chip ? 1 : 0}`;
  useEffect(() => {
    if (!clockRunning) return;
    const now = game.now();
    const next = nextBoundaryAfter(boundaries, now);
    if (next === null) return;
    const timer = setTimeout(() => setTick((n) => n + 1), Math.max(0, next - now) + 1);
    return () => clearTimeout(timer);
  }, [clockRunning, boundaries, game, frameKey, tick]);

  // ── The stage follows the step (ClipQueue decides when the switch may happen).
  const request = queue.request;
  useEffect(() => {
    if (deckMode !== null && clockRunning) request(frame.role);
  }, [deckMode, clockRunning, frame.role, request]);

  // ── Step changes: cue sound, announcements, the verdict under the mask, the round card.
  const [announce, setAnnounce] = useState('');
  const [confetti, setConfetti] = useState(0);
  const prevFrame = useRef<SchoolFrame | null>(null);
  const tapsRef = useRef(taps);
  tapsRef.current = taps;
  const stepKey = `${frame.kind}|${frame.trial ?? ''}|${frame.round}`;
  useEffect(() => {
    if (!clockRunning) return;
    const prev = prevFrame.current;
    prevFrame.current = frame;
    for (const cue of schoolCues(session, prev, frame, tapsRef.current, game.now(), resumedAtMs)) {
      if (cue.kind === 'cueSound') {
        fx.sound('cue');
        fx.haptic('cue');
        const cueText = T.commands[command].cue;
        setAnnounce(cueText);
        if (Platform.OS === 'ios') AccessibilityInfo.announceForAccessibility(cueText);
      } else if (cue.kind === 'announce') {
        const obeys = hintEnabled ? S.hintA11y : T.commands[command].obeys;
        setAnnounce(obeys);
        if (Platform.OS === 'ios') AccessibilityInfo.announceForAccessibility(obeys);
      } else if (cue.kind === 'feedback') {
        void feedback.current?.show(cue.feedback.kind, { label: feedbackLabel(cue.feedback), mask: cue.feedback.mask, totalMs: cue.feedback.totalMs });
      } else {
        void feedback.current?.show('round', { label: S.round(cue.round), totalMs: cue.totalMs });
        fx.sound('chime');
        fx.haptic('success');
        if (!reduceMotion) setConfetti((n) => n + 1);
      }
    }
  }, [stepKey, clockRunning]); // eslint-disable-line react-hooks/exhaustive-deps

  // ── The one line at the top (≥ 2 s each) — momentary feedback only. The rule itself is the
  // strip under the top bar (whole session); »don't praise, wait« is the chip by the dog.
  const say = line.say;
  // Š3 graceful: »Končaj« came too late — round 2 plays on.
  const declined = game.earlyEndDeclined;
  useEffect(() => {
    if (declined === 0) return;
    say(S.keepGoing);
    say(null);
  }, [declined, say]);

  // ── The token: the FIRST tap of a cue decides (server rule); instant mirror feedback.
  // Tap OR drag (David 2026-10-31): the praise counts at the press-in; the treat flies when the
  // finger lets go — from the token (tap) or from where a drag ended. A drag never adds a tap.
  const [shot, setShot] = useState<FlightShot>({ n: 0, dx: 0, dy: 0 });
  const pendingFlight = useRef(false);
  const dragActive = useRef(false);
  const [counted, setCounted] = useState(false);
  const launchFlight = useCallback((dx: number, dy: number) => {
    if (!pendingFlight.current) return;
    pendingFlight.current = false;
    setShot((s) => ({ n: s.n + 1, dx, dy }));
  }, []);
  const [coolUntil, setCoolUntil] = useState(0);
  useEffect(() => {
    if (coolUntil === 0) return;
    const timer = setTimeout(() => setCoolUntil(0), TOKEN_COOLDOWN_MS);
    return () => clearTimeout(timer);
  }, [coolUntil]);
  const touchActive = useRef(false);
  const onTap = useCallback(
    (touchTimestamp: number | null) => {
      setCoolUntil(Date.now());
      const ms = game.praise(touchTimestamp);
      if (ms === null) return;
      setCounted(true);
      const i = slotIndex(session.trials, ms);
      if (i === null) return;
      const fb = tapFeedback(session, i, ms, schoolFrameAt(session, ms));
      void feedback.current?.show(fb.kind, { label: feedbackLabel(fb), mask: fb.mask, totalMs: fb.totalMs });
      if (fb.label === 'inTime') {
        // A screen-reader activation has no press-out / drag to wait for → fly at once.
        pendingFlight.current = true;
        if (!touchActive.current) launchFlight(0, 0);
        fx.sound(token === 'treat' ? 'crunch' : 'chime');
        fx.haptic('success');
      }
    },
    [game, session, fx, token, launchFlight],
  );

  const outcomes = clockRunning ? liveOutcomes(session, taps, e, resumedAtMs) : session.trials.map(() => null);
  const stars = starCount(outcomes);
  const decided = outcomes.map((o) => o !== null);
  const doneCount = decided.filter(Boolean).length;
  const inBreak = frame.kind === 'break';
  const tokenHidden = preparing || finishing || inBreak || frame.kind === 'over';
  const cooling = coolUntil !== 0;
  const canEndEarly = !finishing && inBreak && game.canEndEarly();
  const hintOn = clockRunning && praiseHintOn(frame, hintEnabled);
  const tapHint = clockRunning && tapHintOn(hintEnabled, counted, frame);
  const waiting = clockRunning && tokenWaiting(frame);
  const countdown = clockRunning ? breakCountdown(session, frame, e) : null;
  // »Nadaljuj« pressed in this break: only the countdown stays (it cannot shorten the server's break).
  const [continuedBreak, setContinuedBreak] = useState<number | null>(null);
  const continued = inBreak && continuedBreak === frame.round;
  const deckCurrent = useMemo(() => (queue.state === null ? null : { role: queue.state.current, instance: queue.state.instance, lastSwitch: queue.state.lastSwitch }), [queue.state]);
  const noPicture = plan.clips.every((c) => c.url === null && c.picture === null);

  const tokenLabel = token === 'treat' ? S.treat : S.praise;
  const dock = finishing ? (
    <View style={styles.saving} testID={`${testID}-finishing`}>
      <ActivityIndicator color={palette.mint} />
      <Text style={styles.savingText}>{T.finishing}</Text>
    </View>
  ) : inBreak && countdown !== null ? (
    <View style={styles.breakDock} testID={`${testID}-break`}>
      {continued ? (
        <Text style={styles.roundIn} testID={`${testID}-round-in`} accessibilityLiveRegion="polite">
          {S.roundIn(frame.round + 2, countdown)}
        </Text>
      ) : (
        <Pressable
          testID={`${testID}-continue`}
          accessibilityRole="button"
          accessibilityLabel={S.continueA11y(frame.round + 2)}
          onPress={() => setContinuedBreak(frame.round)}
          style={({ pressed }) => [styles.continue, pressed && styles.pressed]}
        >
          <Text style={styles.continueText}>{S.continue}</Text>
          <View style={styles.countdown}>
            <Text style={styles.countdownText} testID={`${testID}-countdown`}>
              {countdown}
            </Text>
          </View>
        </Pressable>
      )}
      {canEndEarly && !continued && (
        <Pressable
          testID={`${testID}-finish-early`}
          accessibilityRole="button"
          accessibilityLabel={S.finishEarlyA11y}
          onPress={game.endEarly}
          hitSlop={8}
          style={({ pressed }) => [styles.finishEarly, pressed && styles.pressed]}
        >
          <Text style={styles.finishEarlyText}>{S.finishEarly}</Text>
        </Pressable>
      )}
    </View>
  ) : tokenHidden ? null : (
    <>
      {hintOn && (
        <View style={styles.nowChip} testID={`${testID}-hint`} importantForAccessibility="no-hide-descendants" accessibilityElementsHidden>
          <Text style={styles.nowText}>{S.now}</Text>
        </View>
      )}
      <TokenDrag
        reduceMotion={reduceMotion}
        testID={`${testID}-token-drag`}
        onDragStart={() => {
          // The drag took the touch over: no onPress follows, and the press-out is not a release.
          dragActive.current = true;
          touchActive.current = false;
        }}
        onDragEnd={(dx, dy) => {
          dragActive.current = false;
          launchFlight(dx, dy);
        }}
      >
      <TokenPulse on={hintOn} reduceMotion={reduceMotion}>
    <Pressable
      testID={`${testID}-token`}
      accessibilityRole="button"
      accessibilityLabel={token === 'treat' ? S.treatA11y : S.praiseA11y}
      accessibilityHint={waiting ? S.waitA11yHint : undefined}
      // The touch counts at once (timing game); a screen reader activates with onPress only.
      onPressIn={(ev: GestureResponderEvent) => {
        touchActive.current = true;
        onTap(ev?.nativeEvent?.timestamp ?? null);
      }}
      // A tap's release flies the treat (a drag flies it from where it ends — TokenDrag).
      onPressOut={() => {
        if (!dragActive.current) launchFlight(0, 0);
      }}
      onPress={() => {
        if (touchActive.current) {
          touchActive.current = false;
          return;
        }
        onTap(null);
      }}
      style={({ pressed }) => [
        styles.token,
        token === 'treat' ? styles.tokenTreat : styles.tokenHeart,
        hintOn && styles.tokenGlow,
        waiting && styles.tokenWaiting,
        cooling && styles.tokenCooling,
        pressed && !reduceMotion && styles.tokenPressed,
      ]}
    >
      {token === 'treat' ? (
        <Bone color={palette.graphite} fill={palette.warnDark} size={48} testID={`${testID}-token-treat`} />
      ) : (
        <Heart color={palette.graphite} fill={palette.raspberry} size={44} testID={`${testID}-token-heart`} />
      )}
      <Text style={styles.tokenText} testID={waiting ? `${testID}-token-wait` : hintOn ? `${testID}-token-glow` : undefined}>
        {waiting ? S.wait : tokenLabel}
      </Text>
    </Pressable>
        {tapHint && <TapHint reduceMotion={reduceMotion} testID={`${testID}-tap-hint`} />}
      </TokenPulse>
      </TokenDrag>
    </>
  );

  const field = (
    <View pointerEvents="none" style={StyleSheet.absoluteFill}>
      {noPicture && (
        <View style={[StyleSheet.absoluteFill, styles.fallback]} testID={`${testID}-illustration`}>
          <Text style={styles.fallbackEmoji}>🐕</Text>
        </View>
      )}
      <View style={[styles.chipRow, { top: insets.top + CHIP_TOP }]}>
        {frame.chip && clockRunning && (
          <View style={styles.chip} testID={`${testID}-cue`} accessibilityLiveRegion="polite">
            <Text style={styles.chipText}>{T.commands[command].cue}</Text>
            {frame.icon === 'ear' && <Ear color={palette.graphite} size={28} testID={`${testID}-ear`} accessibilityLabel={S.listeningA11y} />}
          </View>
        )}
        {frame.icon === 'ignore' && clockRunning && (
          <View style={styles.ignoreChip} testID={`${testID}-ignoring`} accessibilityLiveRegion="polite">
            <Text style={styles.ignoreText} testID={`${testID}-ignoring-text`}>
              {S.ignores}
            </Text>
            <Text style={styles.ignoreCaption} testID={`${testID}-ignoring-caption`}>
              {S.ignoring[command]}
            </Text>
          </View>
        )}
      </View>
      <TreatFlight shot={shot} token={token} reduceMotion={reduceMotion} />
      {!reduceMotion && confetti > 0 && <Confetti burst={confetti} />}
      {/* Android reads live regions; iOS gets announceForAccessibility above. */}
      <Text style={styles.srOnly} accessibilityLiveRegion="assertive" testID={`${testID}-announce`}>
        {announce}
      </Text>
    </View>
  );

  return (
    <GameScreen
      testID={testID}
      stage={
        <ClipDeck clips={plan.clips} current={deckCurrent} mode={plan.mode} onReady={onDeckReady} onEnded={queue.ended} onMediaExpired={onMediaExpired} testID={`${testID}-deck`} />
      }
      field={field}
      feedback={<FeedbackLayer ref={feedback} reduceMotion={reduceMotion} onMaskChange={queue.masked} testID={`${testID}-feedback`} />}
      topBar={
        <View style={styles.topStack}>
          <GameTopBar
            dots={decided}
            stars={stars}
            line={line.text}
            closeLabel={T.close}
            progressLabel={S.progressA11y(Math.min(session.trials.length, doneCount + (frame.trial !== null && !decided[frame.trial] ? 1 : 0)), session.trials.length, stars)}
            testID={`${testID}-top`}
          />
          {/* The rule, the whole session (David 2026-10-31) — never replaced by the line above. */}
          <SchoolRuleStrip token={token} testID={`${testID}-rule`} />
        </View>
      }
      dock={dock}
    />
  );
}

/** The command chip sits under the top bar + the (up to two-line) rule strip. */
const CHIP_TOP = 136;

const styles = StyleSheet.create({
  topStack: { gap: 8 },
  chipRow: { position: 'absolute', left: 16, right: 16, alignItems: 'center', gap: 10 },
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingHorizontal: 28,
    paddingVertical: 12,
    borderRadius: radius.button,
    backgroundColor: palette.mint,
    shadowColor: palette.graphite,
    shadowOpacity: 0.35,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 4 },
  },
  chipText: { color: palette.graphite, fontSize: 34, letterSpacing: tightTracking(34), fontFamily: fonts.display },
  ignoreChip: {
    maxWidth: 340,
    alignItems: 'center',
    gap: 4,
    paddingHorizontal: 20,
    paddingVertical: 12,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 2,
    borderColor: palette.warnDark,
  },
  ignoreText: { color: palette.white, fontSize: 20, lineHeight: 25, fontFamily: fonts.displayBold, letterSpacing: tightTracking(20), textAlign: 'center' },
  ignoreCaption: { color: palette.n200, fontSize: 15, fontWeight: '600', textAlign: 'center' },
  flightArea: { alignItems: 'center', justifyContent: 'flex-end', paddingBottom: 150 },
  flashArea: { alignItems: 'center', justifyContent: 'center' },
  piece: { position: 'absolute', top: -20, borderRadius: 3 },
  fallback: { alignItems: 'center', justifyContent: 'center' },
  fallbackEmoji: { fontSize: 120 },
  srOnly: { position: 'absolute', width: 1, height: 1, opacity: 0, left: -10, top: -10 },
  token: {
    width: 128,
    height: 128,
    borderRadius: 64,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 2,
    borderWidth: 3,
    borderColor: alpha(palette.white, 0.85),
    shadowColor: palette.mint,
    shadowOpacity: 0.45,
    shadowRadius: 16,
    shadowOffset: { width: 0, height: 6 },
  },
  tokenTreat: { backgroundColor: palette.mint },
  tokenHeart: { backgroundColor: palette.mint },
  tokenCooling: { opacity: 0.5 },
  /** Beginner hint: a bright ring + glow (the static highlight under reduce motion). */
  tokenGlow: { borderWidth: 6, borderColor: palette.white, shadowColor: palette.white, shadowOpacity: 0.9, shadowRadius: 24 },
  /** The dog is not obeying: dimmed, neutral »wait« look — still tappable (a tap still counts). */
  tokenWaiting: { opacity: 0.4, backgroundColor: palette.n300, borderColor: alpha(palette.white, 0.35), shadowOpacity: 0 },
  nowChip: { paddingHorizontal: 22, paddingVertical: 6, borderRadius: radius.button, backgroundColor: palette.white },
  nowText: { color: palette.graphite, fontSize: 22, letterSpacing: tightTracking(22), fontFamily: fonts.displayBold },
  tokenPressed: { transform: [{ scale: 0.95 }] },
  /** The discreet tap hint: a small white pill on the token's lower edge. */
  tapHint: {
    position: 'absolute',
    bottom: -10,
    alignSelf: 'center',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingHorizontal: 10,
    paddingVertical: 3,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.white, 0.92),
  },
  tapHintText: { color: palette.graphite, fontSize: 13, fontFamily: fonts.displayBold, letterSpacing: tightTracking(13) },
  tokenText: { color: palette.graphite, fontSize: 16, fontFamily: fonts.displayBold, letterSpacing: tightTracking(16) },
  breakDock: { alignItems: 'center', gap: 8 },
  continue: {
    flexDirection: 'row',
    gap: 12,
    minHeight: 64,
    minWidth: 220,
    paddingHorizontal: 28,
    borderRadius: radius.button,
    backgroundColor: palette.mint,
    alignItems: 'center',
    justifyContent: 'center',
  },
  continueText: { color: palette.graphite, fontSize: 22, letterSpacing: tightTracking(22), fontFamily: fonts.displayBold },
  countdown: { minWidth: 34, height: 34, borderRadius: 17, backgroundColor: alpha(palette.graphite, 0.15), alignItems: 'center', justifyContent: 'center' },
  countdownText: { color: palette.graphite, fontSize: 18, fontFamily: fonts.displayBold },
  roundIn: { color: palette.white, fontSize: 18, fontWeight: '700', textAlign: 'center', minHeight: 64, textAlignVertical: 'center' },
  /** Secondary: a small text button (David 2026-10-31 — it was mistaken for »continue«). */
  finishEarly: { minHeight: 44, paddingHorizontal: 16, alignItems: 'center', justifyContent: 'center' },
  finishEarlyText: { color: palette.n200, fontSize: 15, fontWeight: '600', textDecorationLine: 'underline' },
  saving: { flexDirection: 'row', alignItems: 'center', gap: 10, minHeight: 64 },
  savingText: { color: palette.white, fontSize: 17, fontWeight: '700' },
  pressed: { opacity: 0.8 },
});
