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
 * - at most ONE line at the top, held ≥ 2 s (`GameLine`) — the rule before the first cue and
 *   »Ne uboga — počakaj.«; no »posluša« text while the dog performs (👂 by the chip instead);
 * - the verdict is a big ✓ »Bravo!« / soft amber ✗ in the middle (`FeedbackLayer`), and the cut
 *   back to standing happens UNDER its mask (1200 ms) — the reversed »stand up« clip is never played;
 * - the command is a big chip (34 pt) with a cue sound (V5 interim — no recorded voice yet);
 * - 2 rounds × 3 cues; in the break »1. krog ✓« + stars and »Končaj« (Š3, while the server takes it).
 *
 * Timing is the server's schedule only (`schoolV2.ts`); one timer per step boundary keeps every
 * switch on time. Sounds + haptics follow the parent's »Zvoki in vibracija v igrah«; reduce motion =
 * no flight, no pop-in, no confetti; a screen reader gets the token as a button and hears the cue
 * and »Kuža se usede.« (announcement + live region).
 */

import { useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { AccessibilityInfo, Platform, Pressable, StyleSheet, View, type GestureResponderEvent } from 'react-native';
import Animated, { Easing, useAnimatedStyle, useSharedValue, withSequence, withTiming } from 'react-native-reanimated';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { ActivityIndicator } from 'react-native';
import { Bone, Ear, Heart } from 'lucide-react-native';

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
import {
  TOKEN_COOLDOWN_MS,
  liveOutcomes,
  nextBoundaryAfter,
  praiseToken,
  schoolBoundaries,
  schoolCues,
  schoolFrameAt,
  starCount,
  tapFeedback,
  type SchoolFeedback,
  type SchoolFrame,
} from '@/modules/training/schoolV2/schoolV2';
import { SCHOOL_V2_STRINGS, TRAINING_STRINGS, type TrainingCommand, type TrainingSession } from '@/modules/training/training';
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

/** What the dog does instead of obeying (§4.1 »ikona 🦋 / 👃«, matches `commands.*.ignores`). */
const IGNORE_ICON: Readonly<Record<TrainingCommand, string>> = { sit: '👃', come: '👃', place: '🎾', potty: '🦋' };

const TREAT_FLIGHT_MS = 520;
const CONFETTI_MS = 1_600;
const CONFETTI_PIECES = 14;

function feedbackLabel(fb: SchoolFeedback): string {
  return S.fb[fb.label];
}

/** The treat (or heart) flying in an arc from the token to the dog; reduce motion = a short flash at the dog. */
function TreatFlight({ shot, token, reduceMotion }: { shot: number; token: 'treat' | 'heart'; reduceMotion: boolean }) {
  const progress = useSharedValue(0);
  const opacity = useSharedValue(0);
  useEffect(() => {
    if (shot === 0) return;
    if (reduceMotion) {
      progress.value = 1;
      opacity.value = withSequence(withTiming(1, { duration: 120 }), withTiming(0, { duration: 380 }));
      return;
    }
    progress.value = 0;
    opacity.value = 1;
    progress.value = withTiming(1, { duration: TREAT_FLIGHT_MS, easing: Easing.out(Easing.quad) });
    opacity.value = withSequence(withTiming(1, { duration: TREAT_FLIGHT_MS - 120 }), withTiming(0, { duration: 160 }));
  }, [shot, reduceMotion, progress, opacity]);
  const style = useAnimatedStyle(() => {
    const p = progress.value;
    // From the dock (bottom) up to the dog (middle) in a soft arc to the side.
    return {
      opacity: opacity.value,
      transform: [{ translateX: Math.sin(p * Math.PI) * 60 }, { translateY: -p * 320 }, { scale: 1 - p * 0.45 }],
    };
  });
  return (
    <View pointerEvents="none" style={[StyleSheet.absoluteFill, reduceMotion ? styles.flashArea : styles.flightArea]}>
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
        const obeys = T.commands[command].obeys;
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

  // ── The one line at the top (≥ 2 s each).
  // The rule is read while the deck loads and through the lead-in (§3.1: the rule before the start) —
  // so its 2 s never run into the first cue.
  const leadLine = token === 'treat' ? S.lead : S.leadHeart;
  const desiredLine = preparing || frame.line === 'lead' ? leadLine : frame.line === 'ignores' ? S.ignores : null;
  const desiredRef = useRef(desiredLine);
  desiredRef.current = desiredLine;
  const say = line.say;
  useEffect(() => {
    say(desiredLine);
  }, [desiredLine, say]);
  // Š3 graceful: »Končaj« came too late — round 2 plays on.
  const declined = game.earlyEndDeclined;
  useEffect(() => {
    if (declined === 0) return;
    say(S.keepGoing);
    say(desiredRef.current);
  }, [declined, say]);

  // ── The token: the FIRST tap of a cue decides (server rule); instant mirror feedback.
  const [shot, setShot] = useState(0);
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
      const i = slotIndex(session.trials, ms);
      if (i === null) return;
      const fb = tapFeedback(session, i, ms, schoolFrameAt(session, ms));
      void feedback.current?.show(fb.kind, { label: feedbackLabel(fb), mask: fb.mask, totalMs: fb.totalMs });
      if (fb.label === 'inTime') {
        setShot((n) => n + 1);
        fx.sound(token === 'treat' ? 'crunch' : 'chime');
        fx.haptic('success');
      }
    },
    [game, session, fx, token],
  );

  const outcomes = clockRunning ? liveOutcomes(session, taps, e, resumedAtMs) : session.trials.map(() => null);
  const stars = starCount(outcomes);
  const decided = outcomes.map((o) => o !== null);
  const doneCount = decided.filter(Boolean).length;
  const inBreak = frame.kind === 'break';
  const tokenHidden = preparing || finishing || inBreak || frame.kind === 'over';
  const cooling = coolUntil !== 0;
  const canEndEarly = !finishing && inBreak && game.canEndEarly();
  const deckCurrent = useMemo(() => (queue.state === null ? null : { role: queue.state.current, instance: queue.state.instance, lastSwitch: queue.state.lastSwitch }), [queue.state]);
  const noPicture = plan.clips.every((c) => c.url === null && c.picture === null);

  const tokenLabel = token === 'treat' ? S.treat : S.praise;
  const dock = finishing ? (
    <View style={styles.saving} testID={`${testID}-finishing`}>
      <ActivityIndicator color={palette.mint} />
      <Text style={styles.savingText}>{T.finishing}</Text>
    </View>
  ) : canEndEarly ? (
    <Pressable
      testID={`${testID}-finish-early`}
      accessibilityRole="button"
      accessibilityLabel={S.finishEarlyA11y}
      onPress={game.endEarly}
      style={({ pressed }) => [styles.finishEarly, pressed && styles.pressed]}
    >
      <Text style={styles.finishEarlyText}>{S.finishEarly}</Text>
    </Pressable>
  ) : tokenHidden ? null : (
    <Pressable
      testID={`${testID}-token`}
      accessibilityRole="button"
      accessibilityLabel={token === 'treat' ? S.treatA11y : S.praiseA11y}
      // The touch counts at once (timing game); a screen reader activates with onPress only.
      onPressIn={(ev: GestureResponderEvent) => {
        touchActive.current = true;
        onTap(ev?.nativeEvent?.timestamp ?? null);
      }}
      onPress={() => {
        if (touchActive.current) {
          touchActive.current = false;
          return;
        }
        onTap(null);
      }}
      style={({ pressed }) => [styles.token, token === 'treat' ? styles.tokenTreat : styles.tokenHeart, cooling && styles.tokenCooling, pressed && !reduceMotion && styles.tokenPressed]}
    >
      {token === 'treat' ? (
        <Bone color={palette.graphite} fill={palette.warnDark} size={48} testID={`${testID}-token-treat`} />
      ) : (
        <Heart color={palette.graphite} fill={palette.raspberry} size={44} testID={`${testID}-token-heart`} />
      )}
      <Text style={styles.tokenText}>{tokenLabel}</Text>
    </Pressable>
  );

  const field = (
    <View pointerEvents="none" style={StyleSheet.absoluteFill}>
      {noPicture && (
        <View style={[StyleSheet.absoluteFill, styles.fallback]} testID={`${testID}-illustration`}>
          <Text style={styles.fallbackEmoji}>🐕</Text>
        </View>
      )}
      <View style={[styles.chipRow, { top: insets.top + 76 }]}>
        {frame.chip && clockRunning && (
          <View style={styles.chip} testID={`${testID}-cue`} accessibilityLiveRegion="polite">
            <Text style={styles.chipText}>{T.commands[command].cue}</Text>
            {frame.icon === 'ear' && <Ear color={palette.graphite} size={28} testID={`${testID}-ear`} accessibilityLabel={S.listeningA11y} />}
          </View>
        )}
        {frame.icon === 'ignore' && clockRunning && (
          <View style={styles.ignoreBubble} testID={`${testID}-ignoring`}>
            <Text style={styles.ignoreIcon}>{IGNORE_ICON[command]}</Text>
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
        <GameTopBar
          dots={decided}
          stars={stars}
          line={line.text}
          closeLabel={T.close}
          progressLabel={S.progressA11y(Math.min(session.trials.length, doneCount + (frame.trial !== null && !decided[frame.trial] ? 1 : 0)), session.trials.length, stars)}
          testID={`${testID}-top`}
        />
      }
      dock={dock}
    />
  );
}

const styles = StyleSheet.create({
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
  ignoreBubble: {
    width: 64,
    height: 64,
    borderRadius: 32,
    backgroundColor: alpha(palette.graphite, 0.6),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.2),
    alignItems: 'center',
    justifyContent: 'center',
  },
  ignoreIcon: { fontSize: 32 },
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
  tokenPressed: { transform: [{ scale: 0.95 }] },
  tokenText: { color: palette.graphite, fontSize: 16, fontFamily: fonts.displayBold, letterSpacing: tightTracking(16) },
  finishEarly: {
    minHeight: 64,
    minWidth: 200,
    paddingHorizontal: 28,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.graphite, 0.8),
    borderWidth: 2,
    borderColor: palette.mint,
    alignItems: 'center',
    justifyContent: 'center',
  },
  finishEarlyText: { color: palette.white, fontSize: 22, letterSpacing: tightTracking(22), fontFamily: fonts.displayBold },
  saving: { flexDirection: 'row', alignItems: 'center', gap: 10, minHeight: 64 },
  savingText: { color: palette.white, fontSize: 17, fontWeight: '700' },
  pressed: { opacity: 0.8 },
});
