/**
 * FeedbackLayer — the big ✓ / ✗ / ★ / round card in the middle of a game (GAMES_SPEC §3.1,
 * §7.1). The glyph animates on the UI thread (reanimated); the timing is `feedback.ts` and the
 * mask window / promise run on JS timers (testable with fake timers).
 *
 * - `show(kind, {label, mask, totalMs})` → a promise that resolves when it is gone; a newer
 *   `show` replaces the current one (the older promise resolves at once).
 * - `mask: true` dims the stage to ≥ 85 % — `onMaskChange(true)` from the end of the enter to
 *   the start of the exit: the only moment ClipQueue may cut (§3.2 rule 6).
 * - ✗ is amber (`warnDark`) and soft — never red / raspberry, never a shaming text.
 * - Reduce motion: no pop-in scale, only the fade.
 * The label is announced for screen readers.
 */

import { useCallback, useEffect, useImperativeHandle, useRef, useState, type Ref } from 'react';
import { AccessibilityInfo, Platform, StyleSheet, View } from 'react-native';
import Animated, { Easing, useAnimatedStyle, useSharedValue, withDelay, withSequence, withTiming, cancelAnimation } from 'react-native-reanimated';
import { Check, Star, X } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { feedbackStartScale, feedbackTimeline, MASK_OPACITY, type FeedbackKind, type FeedbackOptions } from '@/modules/gameLayer/feedback';
import { alpha, fonts, palette, tightTracking } from '@/theme';

export interface FeedbackHandle {
  show: (kind: FeedbackKind, options?: FeedbackOptions) => Promise<void>;
  isMasking: () => boolean;
}

export interface FeedbackLayerProps {
  ref?: Ref<FeedbackHandle>;
  reduceMotion: boolean;
  onMaskChange?: (masked: boolean) => void;
  testID?: string;
}

interface Shown {
  id: number;
  kind: FeedbackKind;
  label: string | null;
  mask: boolean;
}

const GLYPH_COLOR: Readonly<Record<FeedbackKind, string>> = {
  ok: palette.mint,
  miss: palette.warnDark,
  star: palette.warnDark,
  round: palette.mint,
};

function Glyph({ kind, size }: { kind: FeedbackKind; size: number }) {
  const color = GLYPH_COLOR[kind];
  if (kind === 'miss') return <X color={color} size={size} strokeWidth={3} />;
  if (kind === 'star') return <Star color={color} fill={color} size={size} />;
  return <Check color={color} size={size} strokeWidth={3} />;
}

export default function FeedbackLayer({ ref, reduceMotion, onMaskChange, testID = 'game-feedback' }: FeedbackLayerProps) {
  const [shown, setShown] = useState<Shown | null>(null);
  const opacity = useSharedValue(0);
  const scale = useSharedValue(1);
  const timers = useRef<ReturnType<typeof setTimeout>[]>([]);
  const resolveRef = useRef<(() => void) | null>(null);
  const masking = useRef(false);
  const nextId = useRef(1);
  const maskRef = useRef(onMaskChange);
  maskRef.current = onMaskChange;

  const setMask = useCallback((value: boolean) => {
    if (masking.current === value) return;
    masking.current = value;
    maskRef.current?.(value);
  }, []);

  const clear = useCallback(() => {
    timers.current.forEach(clearTimeout);
    timers.current = [];
    resolveRef.current?.();
    resolveRef.current = null;
  }, []);

  useEffect(
    () => () => {
      clear();
      cancelAnimation(opacity);
      cancelAnimation(scale);
    },
    [clear, opacity, scale],
  );

  const show = useCallback(
    (kind: FeedbackKind, options: FeedbackOptions = {}) => {
      clear();
      const t = feedbackTimeline(kind, options);
      const id = nextId.current++;
      const label = options.label ?? null;
      setShown({ id, kind, label, mask: options.mask === true });
      opacity.value = withSequence(
        withTiming(1, { duration: t.enterMs, easing: Easing.out(Easing.quad) }),
        withDelay(t.holdMs, withTiming(0, { duration: t.exitMs, easing: Easing.in(Easing.quad) })),
      );
      scale.value = feedbackStartScale(kind, reduceMotion);
      if (!reduceMotion) scale.value = withTiming(1, { duration: t.enterMs + 80, easing: Easing.out(Easing.back(2)) });
      if (label !== null && Platform.OS === 'ios') AccessibilityInfo.announceForAccessibility(label);
      if (t.maskFrom !== null && t.maskUntil !== null) {
        timers.current.push(setTimeout(() => setMask(true), t.maskFrom));
        timers.current.push(setTimeout(() => setMask(false), t.maskUntil));
      } else {
        setMask(false);
      }
      return new Promise<void>((resolve) => {
        resolveRef.current = resolve;
        timers.current.push(
          setTimeout(() => {
            setMask(false);
            setShown((s) => (s?.id === id ? null : s));
            resolveRef.current = null;
            resolve();
          }, t.totalMs),
        );
      });
    },
    [clear, opacity, scale, reduceMotion, setMask],
  );

  useImperativeHandle(ref, () => ({ show, isMasking: () => masking.current }), [show]);

  const layerStyle = useAnimatedStyle(() => ({ opacity: opacity.value }));
  const glyphStyle = useAnimatedStyle(() => ({ transform: [{ scale: scale.value }] }));

  if (shown === null) return null;
  const big = shown.mask || shown.kind === 'round';
  return (
    <Animated.View pointerEvents="none" style={[StyleSheet.absoluteFill, styles.center, layerStyle]} testID={testID}>
      {shown.mask && <View style={[StyleSheet.absoluteFill, styles.mask]} testID={`${testID}-mask`} />}
      <Animated.View style={[styles.badge, big && styles.badgeBig, glyphStyle]} testID={`${testID}-${shown.kind}`}>
        <Glyph kind={shown.kind} size={big ? 120 : 64} />
        {shown.label !== null && <Text style={[styles.label, shown.kind === 'miss' && styles.labelMiss]}>{shown.label}</Text>}
      </Animated.View>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  center: { alignItems: 'center', justifyContent: 'center' },
  mask: { backgroundColor: alpha(palette.graphite, MASK_OPACITY) },
  badge: {
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 22,
    paddingVertical: 16,
    borderRadius: 28,
    backgroundColor: alpha(palette.graphite, 0.55),
  },
  badgeBig: { backgroundColor: 'transparent' },
  label: { color: palette.white, fontSize: 26, letterSpacing: tightTracking(26), fontFamily: fonts.displayBold, textAlign: 'center' },
  labelMiss: { color: palette.warnDark },
});
