/**
 * "Na praskalnik" — redirect the cat from the scratched sofa to her scratching post and
 * praise her within 3 s (M5-R06-08a; CAT_SPEC Q10, AAFP C23 "reward immediately, never
 * punish"; server M5-R06-05 `ScratchingService`).
 *
 * 1. Intro: what happened (premium: the cat's own `scratching` video; free: the scratched
 *    sofa), that it is normal cat behaviour, never shout or punish; the 2 h deadline.
 * 2. Carry: the cat moves from the sofa to the post until the server's `land_at_ms`; then she
 *    scratches the post and the child praises. The FIRST praise decides (like a training cue):
 *    before the landing it is "too early", after 150 ms–3 s it counts. The app sends the
 *    praise right away (but never before the landing — the server refuses that), or, without
 *    a praise, when the window closed. Never a penalty: a miss just tries again.
 * 3. The server's verdict.
 */

import { useEffect, useRef } from 'react';
import { Animated, Pressable, ScrollView, StyleSheet, View, type GestureResponderEvent } from 'react-native';
import { Heart, Scissors } from 'lucide-react-native';
import { useQueryClient } from '@tanstack/react-query';

import { ScratchedSofaGraphic } from '@/components/BehaviourGraphics';
import { CatFigure, ScratchingPostGraphic } from '@/components/CatGraphics';
import { Text } from '@/components/ui/Text';
import { childPetKey } from '@/hooks/queries/useChildPet';
import {
  CAT_COMMON_STRINGS,
  SCRATCHING_STRINGS,
  catRefusalMessage,
  catWhen,
  onlyScratchingOpen,
  type CatFinishStatus,
  type ScratchingResult,
  type ScratchingSession,
} from '@/modules/catCare/catCare';
import { scratchingFrameAt } from '@/modules/catCare/catGames';
import { Busy, CatOverlayFrame, CatStage, FailedView, PrimaryButton, SecondaryButton, styles, useAnnounce } from '@/modules/catCare/CatGameParts';
import { useScratchingGame, type PraiseInput } from '@/modules/catCare/useCatGames';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import type { ClockSources } from '@/modules/training/game';
import { palette } from '@/theme';

const S = SCRATCHING_STRINGS;
const C = CAT_COMMON_STRINGS;

export interface ScratchingOverlayProps {
  view: ChildPetView;
  onClose: () => void;
  reduceMotion: boolean;
  clock?: ClockSources;
  testID?: string;
}

export default function ScratchingOverlay({ view, onClose, reduceMotion, clock, testID = 'cat-scratching' }: ScratchingOverlayProps) {
  const queryClient = useQueryClient();
  const game = useScratchingGame(view, { clock });
  const { phase } = game;
  const scratching = view.cat.scratching;
  // No "stop" here: the carry takes ~3–5 s and a praise ends it at once.
  const locked = phase.kind === 'starting' || phase.kind === 'finishing' || phase.kind === 'running';
  const onMediaExpired = () => void queryClient.invalidateQueries({ queryKey: childPetKey });
  const ctx = { nowIso: view.server_time, timezone: view.timezone, scratchingOnly: onlyScratchingOpen(view.cat) };
  const due = scratching?.active ? catWhen(scratching.active.due_at, ctx.nowIso, ctx.timezone) : null;

  return (
    <CatOverlayFrame title={S.title} icon={<Scissors color={palette.mint} size={22} />} onClose={locked ? null : onClose} testID={testID}>
      {phase.kind === 'intro' && (
        <ScrollView contentContainerStyle={styles.content} testID={`${testID}-intro`}>
          {scratching?.active ? (
            <>
              <View style={local.introStage}>
                <CatStage
                  view={view}
                  petState="idle"
                  scene="scratching"
                  illustration={<ScratchedSofaGraphic size={150} />}
                  onMediaExpired={onMediaExpired}
                  testID={`${testID}-scene`}
                />
              </View>
              <Text style={styles.body}>{S.intro}</Text>
              <Text style={styles.muted}>{S.never}</Text>
              {due !== null && (
                <Text style={styles.bodyStrong} testID={`${testID}-due`}>
                  {S.dueAt(due)}
                </Text>
              )}
            </>
          ) : (
            <Text style={styles.body} testID={`${testID}-nothing`}>
              {S.nothing}
            </Text>
          )}
          {scratching !== null && !scratching.can_start && scratching.blocked_reason !== null && scratching.active !== null && (
            <Text style={styles.note} testID={`${testID}-blocked`}>
              {catRefusalMessage(scratching.blocked_reason, null, ctx)}
            </Text>
          )}
          {scratching?.active ? (
            <PrimaryButton label={S.start} onPress={game.start} disabled={!scratching.can_start} testID={`${testID}-start`} />
          ) : (
            <PrimaryButton label={C.done} onPress={onClose} testID={`${testID}-close-done`} />
          )}
        </ScrollView>
      )}

      {phase.kind === 'starting' && <Busy text={C.starting} testID={`${testID}-starting`} />}

      {(phase.kind === 'running' || phase.kind === 'finishing') && (
        <ScratchingRunning
          session={phase.session}
          praise={phase.input}
          elapsedMs={game.elapsedMs}
          finishing={phase.kind === 'finishing'}
          reduceMotion={reduceMotion}
          onPraise={(touchTimestamp) => {
            const ms = game.msAt(touchTimestamp);
            // The first praise decides.
            game.setInput((current: PraiseInput) => (current === null ? ms : current));
          }}
          testID={testID}
        />
      )}

      {phase.kind === 'result' && (
        <ScrollView contentContainerStyle={styles.content}>
          <ScratchingResultView
            result={phase.result}
            status={phase.status}
            canAgain={view.cat.scratching?.active != null}
            onAgain={game.reset}
            onDone={onClose}
            testID={testID}
          />
        </ScrollView>
      )}

      {phase.kind === 'failed' && (
        <FailedView message={phase.message} canRetry={phase.retry !== null} onRetry={game.retryFinish} onBack={game.reset} testID={`${testID}-failed`} />
      )}
    </CatOverlayFrame>
  );
}

function ScratchingResultView({
  result,
  status,
  canAgain,
  onAgain,
  onDone,
  testID,
}: {
  result: ScratchingResult;
  status: CatFinishStatus;
  canAgain: boolean;
  onAgain: () => void;
  onDone: () => void;
  testID: string;
}) {
  return (
    <View style={styles.resultBox} testID={`${testID}-result`}>
      <Text style={styles.resultTitle} accessibilityRole="header" testID={`${testID}-result-title`}>
        {result.success ? S.result.successTitle : S.result.failTitle}
      </Text>
      {status === 'unchanged' && <Text style={styles.muted}>{C.stored}</Text>}
      <Text style={styles.body} testID={`${testID}-result-text`}>
        {result.success ? S.result.success : S.result.reasons[result.reason ?? 'unknown']}
      </Text>
      {!result.success && <Text style={styles.muted}>{C.noPenalty}</Text>}
      <View style={styles.row}>
        {!result.success && canAgain && <SecondaryButton label={S.result.again} onPress={onAgain} testID={`${testID}-again`} />}
        <View style={styles.flexButton}>
          <PrimaryButton label={S.result.done} onPress={onDone} testID={`${testID}-done`} />
        </View>
      </View>
    </View>
  );
}

function ScratchingRunning({
  session,
  praise,
  elapsedMs,
  finishing,
  reduceMotion,
  onPraise,
  testID,
}: {
  session: ScratchingSession;
  praise: PraiseInput;
  elapsedMs: number;
  finishing: boolean;
  reduceMotion: boolean;
  onPraise: (touchTimestamp: number | null) => void;
  testID: string;
}) {
  const frame = scratchingFrameAt(session, elapsedMs);
  const landed = frame.phase !== 'carrying';
  const status = landed ? S.landed : S.carrying;
  useAnnounce(status);
  // A praise before the landing is "too early" — say so at once (the server agrees on finish).
  const early = praise !== null && praise < session.land_at_ms;
  const touchActive = useRef(false);

  // The cat travels from the sofa (left) to the post (right) until the landing; with
  // "reduce motion" she just appears at the post when she lands.
  const travel = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    if (reduceMotion) {
      travel.setValue(landed ? 1 : 0);
      return;
    }
    const left = Math.max(0, session.land_at_ms - elapsedMs);
    Animated.timing(travel, { toValue: 1, duration: left, useNativeDriver: true }).start();
    // Once per landing state, not per 100 ms tick: `elapsedMs` is read at that moment only.
  }, [landed, reduceMotion, travel]); // eslint-disable-line react-hooks/exhaustive-deps
  const translateX = travel.interpolate({ inputRange: [0, 1], outputRange: [-90, 70] });

  return (
    <View style={styles.running} testID={`${testID}-running`}>
      <View style={[styles.stage, local.scene]}>
        <ScratchedSofaGraphic size={110} />
        <ScratchingPostGraphic size={130} />
        <Animated.View
          style={[local.cat, { transform: [{ translateX }] }]}
          testID={`${testID}-cat-${landed ? 'landed' : 'carrying'}`}
        >
          <CatFigure size={100} pose={landed ? 'pounce' : 'sit'} />
        </Animated.View>
      </View>
      <Text style={styles.statusLine} testID={`${testID}-status`} accessibilityLiveRegion="polite">
        {status}
      </Text>
      <View style={styles.feedbackSlot}>
        {early && (
          <Text style={[styles.feedback, styles.feedbackTry]} testID={`${testID}-early`}>
            {S.waitHint}
          </Text>
        )}
      </View>
      {finishing ? (
        <Busy text={C.finishing} testID={`${testID}-finishing`} />
      ) : (
        <Pressable
          testID={`${testID}-praise`}
          accessibilityRole="button"
          accessibilityLabel={S.praiseA11y}
          accessibilityState={{ disabled: praise !== null }}
          disabled={praise !== null}
          // The touch counts at once (timing game); a screen reader activates with onPress only.
          onPressIn={(e: GestureResponderEvent) => {
            touchActive.current = true;
            onPraise(e?.nativeEvent?.timestamp ?? null);
          }}
          onPress={() => {
            if (touchActive.current) {
              touchActive.current = false;
              return;
            }
            onPraise(null);
          }}
          style={({ pressed }) => [styles.bigButton, pressed && styles.bigButtonPressed, praise !== null && styles.disabled]}
        >
          <Heart color={palette.graphite} size={28} />
          <Text style={styles.bigButtonText}>{S.praise}</Text>
        </Pressable>
      )}
    </View>
  );
}

const local = StyleSheet.create({
  introStage: { height: 220 },
  scene: { flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'space-between', padding: 16 },
  cat: { position: 'absolute', left: 0, right: 0, bottom: 10, alignItems: 'center' },
});
