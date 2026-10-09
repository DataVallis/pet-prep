/**
 * "Palica s peresom" — the cat's daily wand play over the child HUD (M5-R06-08a,
 * CAT_SPEC Q1 / §5.2; server M5-R06-04).
 *
 * 1. Intro: how to play (pull the feather AWAY from the cat, like prey — C11), today's games
 *    (`sessions_today` / `goal`), why "Začni igro" is off (`blocked_reason` worded with the
 *    family-local time).
 * 2. ~60 s game: the child drags the feather over the stage; one move per stroke of the
 *    feather (`moveWandStroke`) with `away` = it got further from the cat. The cat stalks,
 *    crouches and pounces at the server's `pounces_ms` (an "away" move within 2 s answers a
 *    pounce) and catches the feather at the end. Quarter dots show the spread over the minute.
 *    "Umakni pero" records an away move for VoiceOver / TalkBack and anyone who can't drag.
 * 3. The server's verdict: counts (meter, routine) or a friendly "try again" — never shaming;
 *    an aborted / failed game has no consequence (CAT_SPEC §5.2).
 * "Ustavi" (×) during the game stops it locally (nothing sent, no penalty).
 */

import { useEffect, useMemo, useRef, useState } from 'react';
import { Animated, PanResponder, Pressable, ScrollView, View, type LayoutChangeEvent } from 'react-native';
import { Feather, MoveUpRight } from 'lucide-react-native';
import { useQueryClient } from '@tanstack/react-query';

import { CatFigure, FeatherGraphic, type CatPose } from '@/components/CatGraphics';
import { Text } from '@/components/ui/Text';
import { childPetKey } from '@/hooks/queries/useChildPet';
import { CAT_COMMON_STRINGS, WAND_STRINGS, catRefusalMessage, onlyScratchingOpen, type WandResult, type WandSession } from '@/modules/catCare/catCare';
import {
  addWandMove,
  beginWandStroke,
  endWandStroke,
  moveWandStroke,
  wandFeedbackAt,
  wandFrameAt,
  wandSegmentsHit,
  WAND_SESSION_SECONDS,
  type CatAction,
  type Point,
  type WandMove,
  type WandTracker,
} from '@/modules/catCare/catGames';
import {
  Busy,
  CatOverlayFrame,
  CatStage,
  FailedView,
  PrimaryButton,
  SecondaryButton,
  SegmentDots,
  styles,
  useAnnounce,
} from '@/modules/catCare/CatGameParts';
import { useWandGame } from '@/modules/catCare/useCatGames';
import type { CatFinishStatus } from '@/modules/catCare/catCare';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import type { ClockSources } from '@/modules/training/game';
import { palette } from '@/theme';

const W = WAND_STRINGS;
const C = CAT_COMMON_STRINGS;

export interface WandOverlayProps {
  view: ChildPetView;
  onClose: () => void;
  reduceMotion: boolean;
  /** Injected in tests (game clock). */
  clock?: ClockSources;
  testID?: string;
}

function catLine(action: CatAction): string {
  switch (action) {
    case 'stalking':
      return W.stalking;
    case 'crouching':
      return W.crouching;
    case 'pouncing':
      return W.pouncing;
    case 'catching':
      return W.catching;
    case 'caught':
      return W.caught;
  }
}

function poseOf(action: CatAction): CatPose {
  return action === 'crouching' ? 'crouch' : action === 'pouncing' ? 'pounce' : action === 'caught' ? 'happy' : 'sit';
}

export default function WandOverlay({ view, onClose, reduceMotion, clock, testID = 'cat-wand' }: WandOverlayProps) {
  const queryClient = useQueryClient();
  const game = useWandGame(view, { clock });
  const { phase } = game;
  const wand = view.cat.wand;
  const busy = phase.kind === 'starting' || phase.kind === 'finishing';
  const onMediaExpired = () => void queryClient.invalidateQueries({ queryKey: childPetKey });

  return (
    <CatOverlayFrame
      title={W.title}
      icon={<Feather color={palette.mint} size={22} />}
      onClose={busy ? null : phase.kind === 'running' ? game.stop : onClose}
      closeMode={phase.kind === 'running' ? 'stop' : 'close'}
      testID={testID}
    >
      {phase.kind === 'intro' && (
        <ScrollView contentContainerStyle={styles.content} testID={`${testID}-intro`}>
          <Text style={styles.body}>{W.intro}</Text>
          <Text style={styles.muted}>{W.tip}</Text>
          {wand !== null && (
            <Text style={[styles.bodyStrong, wand.goal > 0 && wand.sessions_today >= wand.goal && styles.doneText]} testID={`${testID}-today`}>
              {wand.goal > 0 && wand.sessions_today >= wand.goal ? W.todayDone : W.today(wand.sessions_today, wand.goal)}
            </Text>
          )}
          <Text style={styles.muted}>{W.length(WAND_SESSION_SECONDS)}</Text>
          {wand !== null && !wand.can_start && wand.blocked_reason !== null && (
            <Text style={styles.note} testID={`${testID}-blocked`}>
              {catRefusalMessage(wand.blocked_reason, wand.next_allowed_at, {
                nowIso: view.server_time,
                timezone: view.timezone,
                scratchingOnly: onlyScratchingOpen(view.cat),
              })}
            </Text>
          )}
          <PrimaryButton label={W.start} onPress={game.start} disabled={wand === null || !wand.can_start} testID={`${testID}-start`} />
        </ScrollView>
      )}

      {phase.kind === 'starting' && <Busy text={C.starting} testID={`${testID}-starting`} />}

      {(phase.kind === 'running' || phase.kind === 'finishing') && (
        <WandRunning
          view={view}
          session={phase.session}
          moves={phase.input}
          elapsedMs={game.elapsedMs}
          finishing={phase.kind === 'finishing'}
          resumed={phase.resumedAtMs !== null}
          reduceMotion={reduceMotion}
          msAt={game.msAt}
          onMove={(move) => game.setInput((moves) => addWandMove(moves, move, phase.session.duration_ms))}
          onMediaExpired={onMediaExpired}
          testID={testID}
        />
      )}

      {phase.kind === 'result' && (
        <ScrollView contentContainerStyle={styles.content}>
          <WandResultView result={phase.result} status={phase.status} onAgain={game.reset} onDone={onClose} testID={testID} />
        </ScrollView>
      )}

      {phase.kind === 'failed' && (
        <FailedView message={phase.message} canRetry={phase.retry !== null} onRetry={game.retryFinish} onBack={game.reset} testID={`${testID}-failed`} />
      )}
    </CatOverlayFrame>
  );
}

/** Result title per verdict; `unchanged` = a repeat of a saved finish. */
function resultTitle(result: WandResult, status: CatFinishStatus): string {
  if (status === 'unchanged') return W.result.storedTitle;
  return result.success ? W.result.successTitle : W.result.failTitle;
}

function WandResultView({
  result,
  status,
  onAgain,
  onDone,
  testID,
}: {
  result: WandResult;
  status: CatFinishStatus;
  onAgain: () => void;
  onDone: () => void;
  testID: string;
}) {
  return (
    <View style={styles.resultBox} testID={`${testID}-result`}>
      <Text style={styles.resultTitle} accessibilityRole="header" testID={`${testID}-result-title`}>
        {resultTitle(result, status)}
      </Text>
      {status === 'unchanged' && <Text style={styles.muted}>{C.stored}</Text>}
      <Text style={styles.body} testID={`${testID}-result-text`}>
        {result.success ? W.result.success : W.result.reasons[result.reason ?? 'unknown']}
      </Text>
      <Text style={styles.muted}>{W.result.escapes(result.away_moves)}</Text>
      {!result.success && <Text style={styles.muted}>{C.noPenalty}</Text>}
      <View style={styles.row}>
        {!result.success && <SecondaryButton label={W.result.again} onPress={onAgain} testID={`${testID}-again`} />}
        <View style={styles.flexButton}>
          <PrimaryButton label={W.result.done} onPress={onDone} testID={`${testID}-done`} />
        </View>
      </View>
    </View>
  );
}

const FEATHER_SIZE = 48;
/** The cat sits here (from the stage's bottom), the reference for "away". */
const CAT_BOTTOM_OFFSET = 70;

function WandRunning({
  view,
  session,
  moves,
  elapsedMs,
  finishing,
  resumed,
  reduceMotion,
  msAt,
  onMove,
  onMediaExpired,
  testID,
}: {
  view: ChildPetView;
  session: WandSession;
  moves: readonly WandMove[];
  elapsedMs: number;
  finishing: boolean;
  resumed: boolean;
  reduceMotion: boolean;
  msAt: (touchTimestamp?: number | null) => number;
  onMove: (move: WandMove) => void;
  onMediaExpired: () => void;
  testID: string;
}) {
  const frame = wandFrameAt(session, elapsedMs);
  const hit = wandSegmentsHit(session, moves);
  const feedback = wandFeedbackAt(session, moves, elapsedMs);
  const status = catLine(frame.cat);
  useAnnounce(status);

  const [size, setSize] = useState({ width: 0, height: 0 });
  const sizeRef = useRef(size);
  sizeRef.current = size;
  const catAt = (): Point => ({ x: sizeRef.current.width / 2, y: sizeRef.current.height - CAT_BOTTOM_OFFSET });
  const feather = useRef(new Animated.ValueXY({ x: 0, y: 0 })).current;
  const tracker = useRef<WandTracker | null>(null);
  const origin = useRef<Point>({ x: 0, y: 0 });
  const onMoveRef = useRef(onMove);
  onMoveRef.current = onMove;
  const msAtRef = useRef(msAt);
  msAtRef.current = msAt;
  const disabled = finishing || frame.over;
  const disabledRef = useRef(disabled);
  disabledRef.current = disabled;

  const onLayout = (e: LayoutChangeEvent) => {
    const { width, height } = e.nativeEvent.layout;
    setSize({ width, height });
    // The feather starts above the cat.
    feather.setValue({ x: width / 2 - FEATHER_SIZE / 2, y: Math.max(0, height / 3 - FEATHER_SIZE / 2) });
  };

  const responder = useMemo(
    () =>
      PanResponder.create({
        onStartShouldSetPanResponder: () => !disabledRef.current,
        onMoveShouldSetPanResponder: () => !disabledRef.current,
        onPanResponderGrant: (e) => {
          const p = { x: e.nativeEvent.locationX, y: e.nativeEvent.locationY };
          origin.current = p;
          feather.setValue({ x: p.x - FEATHER_SIZE / 2, y: p.y - FEATHER_SIZE / 2 });
          tracker.current = beginWandStroke(p, msAtRef.current(e.nativeEvent.timestamp), catAt());
        },
        onPanResponderMove: (e, g) => {
          const p = { x: origin.current.x + g.dx, y: origin.current.y + g.dy };
          feather.setValue({ x: p.x - FEATHER_SIZE / 2, y: p.y - FEATHER_SIZE / 2 });
          if (tracker.current === null) return;
          const step = moveWandStroke(tracker.current, p, msAtRef.current(e.nativeEvent.timestamp), catAt());
          tracker.current = step.tracker;
          if (step.move !== null) onMoveRef.current(step.move);
        },
        onPanResponderRelease: (e, g) => {
          if (tracker.current === null) return;
          const p = { x: origin.current.x + g.dx, y: origin.current.y + g.dy };
          const move = endWandStroke(tracker.current, p, msAtRef.current(e.nativeEvent.timestamp), catAt());
          tracker.current = null;
          if (move !== null) onMoveRef.current(move);
        },
        onPanResponderTerminate: () => {
          tracker.current = null;
        },
      }),
    // `catAt` reads refs only.
    [feather],
  );

  // The cat leaps up a little when it pounces (no movement with "reduce motion").
  const leap = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    if (reduceMotion) {
      leap.setValue(0);
      return;
    }
    Animated.timing(leap, { toValue: frame.cat === 'pouncing' ? 1 : 0, duration: 220, useNativeDriver: true }).start();
  }, [frame.cat, leap, reduceMotion]);
  const leapY = leap.interpolate({ inputRange: [0, 1], outputRange: [0, -36] });

  const premiumState = frame.cat === 'pouncing' || frame.cat === 'caught' ? 'playing' : 'idle';

  return (
    <View style={styles.running} testID={`${testID}-running`}>
      <View style={styles.rowBetween}>
        <SegmentDots total={session.segments} hit={hit} current={frame.over ? null : frame.segment} label={W.quarterA11y} testID={`${testID}-quarters`} />
        <Text style={styles.muted} testID={`${testID}-seconds`}>
          {C.secondsLeft(frame.secondsLeft)}
        </Text>
      </View>
      {resumed && (
        <Text style={styles.muted} testID={`${testID}-resumed`}>
          {C.resumed}
        </Text>
      )}
      {/* The touch layer: the stage below ignores touches, so locations are relative to this view. */}
      <View style={styles.running} onLayout={onLayout} {...responder.panHandlers} testID={`${testID}-stage-touch`}>
        <View style={styles.running} pointerEvents="none">
        <CatStage
          view={view}
          petState={premiumState}
          onMediaExpired={onMediaExpired}
          accessibilityLabel={W.stageA11y}
          testID={`${testID}-stage`}
          illustration={
            <Animated.View
              style={[{ transform: [{ translateY: leapY }] }, reduceMotion && frame.cat === 'pouncing' && { borderRadius: 60, borderWidth: 2, borderColor: palette.mint }]}
              testID={`${testID}-cat-${frame.cat}`}
            >
              <CatFigure pose={poseOf(frame.cat)} size={120} />
            </Animated.View>
          }
        >
          <Animated.View
            pointerEvents="none"
            style={{ position: 'absolute', transform: feather.getTranslateTransform() }}
            importantForAccessibility="no"
            accessibilityElementsHidden
          >
            <FeatherGraphic size={FEATHER_SIZE} />
          </Animated.View>
        </CatStage>
        </View>
      </View>
      <Text style={styles.statusLine} testID={`${testID}-status`} accessibilityLiveRegion="polite">
        {status}
      </Text>
      <View style={styles.feedbackSlot}>
        {feedback !== null && (
          <Text style={[styles.feedback, feedback === 'toward' ? styles.feedbackTry : styles.feedbackGood]} testID={`${testID}-feedback`}>
            {feedback === 'toward' ? W.feedbackToward : feedback === 'pounce' ? W.feedbackPounce : W.feedbackAway}
          </Text>
        )}
      </View>
      {finishing ? (
        <Busy text={C.finishing} testID={`${testID}-finishing`} />
      ) : (
        <Pressable
          testID={`${testID}-pull-away`}
          accessibilityRole="button"
          accessibilityLabel={W.pullAwayA11y}
          accessibilityState={{ disabled }}
          disabled={disabled}
          onPress={() => onMove({ t: msAt(null), away: true })}
          style={({ pressed }) => [styles.secondaryButton, disabled && styles.disabled, pressed && styles.pressed]}
        >
          <MoveUpRight color={palette.mint} size={18} />
          <Text style={styles.secondaryText}>{W.pullAway}</Text>
        </Pressable>
      )}
    </View>
  );
}
