/**
 * Shared pieces of the cat mini-game overlays (M5-R06-08a; dark glass HUD like "Šola" /
 * "Igra", ADR-007, CGP v2): the frame with title and close / stop, the stage (the pet's own
 * video for a premium cat, a calm illustration for a free one), the failure card and the
 * styles. All text comes from the `cat` namespace.
 */

import { useEffect, useMemo, useRef, type ReactNode } from 'react';
import { AccessibilityInfo, ActivityIndicator, Animated, Platform, Pressable, StyleSheet, View } from 'react-native';
import { X } from 'lucide-react-native';

import PetMediaView from '@/components/PetMediaView';
import { Text } from '@/components/ui/Text';
import type { BehaviourScene } from '@/modules/behaviour/behaviour';
import { CAT_COMMON_STRINGS } from '@/modules/catCare/catCare';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import type { PetState } from '@/types';
import { selectMediaSource } from '@/modules/petMedia/petMedia';
import { MIN_TOUCH, alpha, fonts, palette, radius, tightTracking } from '@/theme';

const C = CAT_COMMON_STRINGS;

/**
 * iOS VoiceOver doesn't read `accessibilityLiveRegion` (Android only): announce a changed
 * status line — not the first one (it is on screen already).
 */
export function useAnnounce(text: string | null) {
  const first = useRef(true);
  useEffect(() => {
    if (first.current) {
      first.current = false;
      return;
    }
    if (text && Platform.OS === 'ios') AccessibilityInfo.announceForAccessibility(text);
  }, [text]);
}

export interface CatOverlayFrameProps {
  title: string;
  icon: ReactNode;
  /** null hides the button (while a game is being saved / started). */
  onClose: (() => void) | null;
  /** `stop` while a game runs (it doesn't count, no penalty), else `close`. */
  closeMode?: 'close' | 'stop';
  children: ReactNode;
  testID: string;
}

export function CatOverlayFrame({ title, icon, onClose, closeMode = 'close', children, testID }: CatOverlayFrameProps) {
  return (
    <View style={styles.overlay} testID={testID} accessibilityViewIsModal>
      <View style={styles.header}>
        <View style={styles.titleRow}>
          {icon}
          <Text style={styles.title} accessibilityRole="header">
            {title}
          </Text>
        </View>
        {onClose !== null && (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={closeMode === 'stop' ? C.stopA11y : C.close}
            testID={`${testID}-${closeMode}`}
            onPress={onClose}
            hitSlop={10}
            style={({ pressed }) => [styles.closeButton, pressed && styles.pressed]}
          >
            <X color={palette.n300} size={20} />
          </Pressable>
        )}
      </View>
      {children}
    </View>
  );
}

/**
 * Does this pet have its own stored video (premium)? For a pet state any video of the
 * fallback chain will do; a behaviour scene (`scratching`) needs its own video — otherwise
 * the illustration tells the story better than an idle clip.
 */
export function useHasVideo(view: ChildPetView, state: PetState | BehaviourScene, scene = false): boolean {
  return useMemo(() => {
    const source = selectMediaSource(view.pet.media, state);
    return source.kind === 'video' && (!scene || source.state === state);
  }, [view.pet.media, state, scene]);
}

export interface CatStageProps {
  view: ChildPetView;
  /** The pet state whose video plays for a premium cat. */
  petState: PetState;
  /** A behaviour scene video (e.g. `scratching`) that wins while stored. */
  scene?: BehaviourScene | null;
  /** Shown when the cat has no own video (free tier). */
  illustration: ReactNode;
  onMediaExpired?: () => void;
  children?: ReactNode;
  accessibilityLabel?: string;
  testID?: string;
}

/** The stage: the cat's own video (premium) or the illustration, with game layers on top. */
export function CatStage({ view, petState, scene = null, illustration, onMediaExpired, children, accessibilityLabel, testID = 'cat-stage' }: CatStageProps) {
  const hasVideo = useHasVideo(view, scene ?? petState, scene !== null);
  return (
    <View style={styles.stage} testID={testID} accessible={accessibilityLabel !== undefined} accessibilityLabel={accessibilityLabel}>
      {hasVideo ? (
        <PetMediaView
          media={view.pet.media}
          petState={petState}
          scene={scene}
          breed={view.pet.breed_type}
          species={view.pet.species}
          onMediaExpired={onMediaExpired}
          variant="card"
          style={styles.video}
          testID={`${testID}-video`}
        />
      ) : (
        <View style={styles.illustration} pointerEvents="none">
          {illustration}
        </View>
      )}
      {children}
    </View>
  );
}

/**
 * QA m2: the pounce cue — a mint ring around the stage while a pounce can be answered
 * (premium video and free drawing alike). It pulses; with "reduce motion" it is steady.
 */
export function PounceCue({ reduceMotion, testID }: { reduceMotion: boolean; testID: string }) {
  const pulse = useRef(new Animated.Value(1)).current;
  useEffect(() => {
    if (reduceMotion) {
      pulse.setValue(1);
      return;
    }
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(pulse, { toValue: 0.35, duration: 300, useNativeDriver: true }),
        Animated.timing(pulse, { toValue: 1, duration: 300, useNativeDriver: true }),
      ]),
    );
    loop.start();
    return () => loop.stop();
  }, [pulse, reduceMotion]);
  return <Animated.View pointerEvents="none" style={[styles.pounceCue, { opacity: pulse }]} testID={testID} importantForAccessibility="no" accessibilityElementsHidden />;
}

export function Busy({ text, testID }: { text: string; testID: string }) {
  return (
    <View style={styles.centered} testID={testID}>
      <ActivityIndicator color={palette.mint} />
      <Text style={styles.body}>{text}</Text>
    </View>
  );
}

export function FailedView({
  message,
  canRetry,
  onRetry,
  onBack,
  testID,
}: {
  message: string;
  canRetry: boolean;
  onRetry: () => void;
  onBack: () => void;
  testID: string;
}) {
  return (
    <View style={styles.centered} testID={testID}>
      <Text style={styles.bodyCenter} accessibilityLiveRegion="polite">
        {message}
      </Text>
      {canRetry ? (
        <PrimaryButton label={C.retry} onPress={onRetry} testID={`${testID}-retry`} />
      ) : (
        // While the session can still be saved, leaving would throw it away — only "Poskusi znova".
        <SecondaryButton label={C.back} onPress={onBack} testID={`${testID}-back`} />
      )}
    </View>
  );
}

export function PrimaryButton({
  label,
  onPress,
  disabled = false,
  accessibilityLabel,
  testID,
}: {
  label: string;
  onPress: () => void;
  disabled?: boolean;
  accessibilityLabel?: string;
  testID: string;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? label}
      accessibilityState={{ disabled }}
      disabled={disabled}
      testID={testID}
      onPress={onPress}
      style={({ pressed }) => [styles.primaryButton, disabled && styles.disabled, pressed && !disabled && styles.pressed]}
    >
      <Text style={styles.primaryText}>{label}</Text>
    </Pressable>
  );
}

export function SecondaryButton({
  label,
  onPress,
  disabled = false,
  accessibilityLabel,
  icon,
  testID,
}: {
  label: string;
  onPress: () => void;
  disabled?: boolean;
  accessibilityLabel?: string;
  icon?: ReactNode;
  testID: string;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? label}
      accessibilityState={{ disabled }}
      disabled={disabled}
      testID={testID}
      onPress={onPress}
      style={({ pressed }) => [styles.secondaryButton, disabled && styles.disabled, pressed && !disabled && styles.pressed]}
    >
      {icon}
      <Text style={styles.secondaryText}>{label}</Text>
    </Pressable>
  );
}

/** Progress dots (parts of the game that are done). */
export function SegmentDots({ total, hit, current, label, testID }: { total: number; hit: ReadonlySet<number>; current: number | null; label: (n: number) => string; testID: string }) {
  return (
    <View style={styles.dots} testID={testID}>
      {Array.from({ length: total }, (_, i) => (
        <View
          key={i}
          testID={`${testID}-${i}`}
          accessible
          accessibilityLabel={label(i + 1)}
          accessibilityState={{ checked: hit.has(i), selected: current === i }}
          style={[styles.dot, hit.has(i) ? styles.dotDone : styles.dotOpen, current === i && styles.dotCurrent]}
        />
      ))}
    </View>
  );
}

export const styles = StyleSheet.create({
  overlay: {
    ...StyleSheet.absoluteFill,
    zIndex: 50,
    backgroundColor: alpha(palette.graphite, 0.96),
    paddingTop: 56,
    paddingHorizontal: 16,
    paddingBottom: 28,
  },
  header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
  titleRow: { flexDirection: 'row', alignItems: 'center', gap: 8, flexShrink: 1 },
  title: { color: palette.white, fontSize: 22, letterSpacing: tightTracking(22), fontFamily: fonts.display, flexShrink: 1 },
  closeButton: {
    width: MIN_TOUCH,
    height: MIN_TOUCH,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.white, 0.08),
    alignItems: 'center',
    justifyContent: 'center',
  },
  content: { gap: 12, paddingBottom: 24 },
  body: { color: palette.n200, fontSize: 15, lineHeight: 21 },
  bodyCenter: { color: palette.n200, fontSize: 16, textAlign: 'center', lineHeight: 22 },
  bodyStrong: { color: palette.white, fontSize: 15, fontWeight: '700' },
  doneText: { color: palette.mint },
  muted: { color: palette.n400, fontSize: 13, lineHeight: 18 },
  note: {
    color: palette.warnDark,
    fontSize: 14,
    padding: 12,
    borderRadius: 14,
    backgroundColor: alpha(palette.warnDark, 0.12),
    borderWidth: 1,
    borderColor: alpha(palette.warnDark, 0.3),
  },
  primaryButton: {
    minHeight: 48,
    paddingHorizontal: 20,
    borderRadius: radius.button,
    backgroundColor: palette.mint,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryText: { color: palette.graphite, fontSize: 16, fontWeight: '800' },
  secondaryButton: {
    minHeight: 48,
    paddingHorizontal: 18,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.white, 0.08),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.2),
    flexDirection: 'row',
    gap: 8,
    alignItems: 'center',
    justifyContent: 'center',
  },
  secondaryText: { color: palette.n200, fontSize: 15, fontWeight: '700' },
  disabled: { opacity: 0.45 },
  pressed: { opacity: 0.75 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 16, paddingHorizontal: 12 },
  running: { flex: 1, gap: 12 },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  row: { flexDirection: 'row', gap: 10, alignItems: 'center' },
  stage: {
    flex: 1,
    minHeight: 220,
    borderRadius: 24,
    overflow: 'hidden',
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.12),
  },
  video: { ...StyleSheet.absoluteFill },
  pounceCue: { ...StyleSheet.absoluteFill, borderRadius: 24, borderWidth: 4, borderColor: palette.mint },
  illustration: { ...StyleSheet.absoluteFill, alignItems: 'center', justifyContent: 'flex-end', paddingBottom: 12 },
  statusLine: { color: palette.white, fontSize: 17, fontWeight: '700', textAlign: 'center' },
  feedbackSlot: { minHeight: 40, justifyContent: 'center' },
  feedback: { fontSize: 15, fontWeight: '700', textAlign: 'center' },
  feedbackGood: { color: palette.mint },
  feedbackTry: { color: palette.warnDark },
  dots: { flexDirection: 'row', gap: 8, justifyContent: 'center' },
  dot: { width: 14, height: 14, borderRadius: 7 },
  dotOpen: { backgroundColor: alpha(palette.white, 0.18) },
  dotDone: { backgroundColor: palette.okDark },
  dotCurrent: { borderWidth: 2, borderColor: palette.mint },
  resultBox: { gap: 12 },
  resultTitle: { color: palette.white, fontSize: 28, letterSpacing: tightTracking(28), fontFamily: fonts.display },
  flexButton: { flex: 1 },
  bigButton: {
    minHeight: 96,
    borderRadius: 28,
    backgroundColor: palette.mint,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 12,
  },
  bigButtonPressed: { transform: [{ scale: 0.97 }], opacity: 0.9 },
  bigButtonText: { color: palette.graphite, fontSize: 26, letterSpacing: tightTracking(26), fontFamily: fonts.display },
});
