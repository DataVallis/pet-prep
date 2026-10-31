/**
 * Shared pieces of the cleaning games on the native game layer (GAMES_SPEC §6.2, §7.1).
 * Reached only through `loadMessGames()` (imports gesture-handler + reanimated).
 *
 * - `MessField`: the touch surface over the pet (gesture-handler, worklet callbacks); hidden from
 *   screen readers — every action also exists as a labelled button in hold mode.
 * - `Sprite`: a drawing (react-native-svg, `MessGraphics`) moved by reanimated shared values on
 *   the UI thread — the same drawings the HUD and the old overlays show.
 * - `MintSparkles`: the »clean« burst (mint only); none with reduce motion (the ✓ says it).
 * - `TipBanner`: the first-clean teaching tip under the top bar.
 */

import { useEffect, useMemo, type ReactNode } from 'react';
import { Pressable, StyleSheet, View, type LayoutChangeEvent } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withTiming, type SharedValue } from 'react-native-reanimated';
import { GestureDetector, type ComposedGesture, type GestureType } from 'react-native-gesture-handler';
import { Lightbulb, Sparkles } from 'lucide-react-native';

import PetMediaView from '@/components/PetMediaView';
import { Text } from '@/components/ui/Text';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { mediaModeOf } from '@/modules/display/display';
import { alpha, fonts, palette, radius, tightTracking } from '@/theme';


export interface FieldLayout {
  width: number;
  height: number;
}

/** The touch surface of a cleaning game (field coordinates = this view's). */
export function MessField({
  gesture,
  onLayout,
  children,
  testID,
}: {
  gesture: GestureType | ComposedGesture;
  onLayout?: (layout: FieldLayout) => void;
  children: ReactNode;
  testID: string;
}) {
  const handleLayout = (e: LayoutChangeEvent) => onLayout?.({ width: e.nativeEvent.layout.width, height: e.nativeEvent.layout.height });
  return (
    <GestureDetector gesture={gesture}>
      <View
        style={StyleSheet.absoluteFill}
        onLayout={handleLayout}
        testID={testID}
        importantForAccessibility="no-hide-descendants"
        accessibilityElementsHidden
      >
        {children}
      </View>
    </GestureDetector>
  );
}

/** A drawing centred on (x, y), moved on the UI thread. */
export function Sprite({
  x,
  y,
  width,
  height,
  scale,
  opacity,
  flip = false,
  children,
  testID,
}: {
  x: SharedValue<number>;
  y: SharedValue<number>;
  width: number;
  height: number;
  scale?: SharedValue<number>;
  opacity?: SharedValue<number>;
  flip?: boolean;
  children: ReactNode;
  testID?: string;
}) {
  const style = useAnimatedStyle(() => ({
    opacity: opacity === undefined ? 1 : opacity.value,
    transform: [
      { translateX: x.value - width / 2 },
      { translateY: y.value - height / 2 },
      { scale: scale === undefined ? 1 : scale.value },
      { scaleX: flip ? -1 : 1 },
    ],
  }));
  return (
    <Animated.View pointerEvents="none" style={[styles.sprite, { width, height }, style]} testID={testID}>
      {children}
    </Animated.View>
  );
}

/** Animated offsets of one piece (squeeze into the bag, fly into the box …). */
export interface PieceAnim {
  tx: SharedValue<number>;
  ty: SharedValue<number>;
  scale: SharedValue<number>;
  opacity: SharedValue<number>;
}

export function usePieceAnim(): PieceAnim {
  const tx = useSharedValue(0);
  const ty = useSharedValue(0);
  const scale = useSharedValue(1);
  const opacity = useSharedValue(1);
  return useMemo(() => ({ tx, ty, scale, opacity }), [tx, ty, scale, opacity]);
}

/** Move a piece by (dx, dy) and shrink it away; reduce motion → it just disappears. */
export function sendAway(anim: PieceAnim, dx: number, dy: number, reduceMotion: boolean, ms = 250, endScale = 0.2): void {
  if (reduceMotion) {
    anim.opacity.value = 0;
    return;
  }
  anim.tx.value = withTiming(dx, { duration: ms });
  anim.ty.value = withTiming(dy, { duration: ms });
  anim.scale.value = withTiming(endScale, { duration: ms });
  anim.opacity.value = withTiming(0, { duration: ms + 80 });
}

/** A static glass target on the floor (bin, bucket, box, bag). */
export function Target({ x, y, size = 72, children, testID }: { x: number; y: number; size?: number; children: ReactNode; testID?: string }) {
  return (
    <View pointerEvents="none" style={[styles.target, { left: x - size / 2, top: y - size / 2, width: size, height: size, borderRadius: size / 2 }]} testID={testID}>
      {children}
    </View>
  );
}

const SPARK_ANGLES = [0, 1, 2, 3, 4, 5].map((i) => (i / 6) * Math.PI * 2 - Math.PI / 2);

function Spark({ angle, p }: { angle: number; p: SharedValue<number> }) {
  const style = useAnimatedStyle(() => ({
    opacity: 1 - p.value,
    transform: [
      { translateX: Math.cos(angle) * 48 * p.value - 9 },
      { translateY: Math.sin(angle) * 48 * p.value - 9 },
      { scale: 0.6 + 0.6 * p.value },
    ],
  }));
  return (
    <Animated.View style={[styles.spark, style]}>
      <Sparkles color={palette.mint} size={18} />
    </Animated.View>
  );
}

/** Mint sparkles at (x, y) each time `burst` grows; nothing with reduce motion. */
export function MintSparkles({ at, burst, reduceMotion }: { at: { x: number; y: number } | null; burst: number; reduceMotion: boolean }) {
  const p = useSharedValue(1);
  useEffect(() => {
    if (burst === 0 || reduceMotion) return;
    p.value = 0;
    p.value = withTiming(1, { duration: 700 });
  }, [burst, reduceMotion, p]);
  if (at === null || burst === 0 || reduceMotion) return null;
  return (
    <View pointerEvents="none" style={[styles.sparks, { left: at.x, top: at.y }]} testID="mess-sparkles">
      {SPARK_ANGLES.map((a) => (
        <Spark key={a} angle={a} p={p} />
      ))}
    </View>
  );
}

/** The first-clean tip (one card under the top bar, announced by screen readers). */
export function TipBanner({ text, testID = 'mess-tip' }: { text: string | null; testID?: string }) {
  if (text === null) return null;
  return (
    <View style={styles.tip} accessibilityLiveRegion="polite" accessible accessibilityLabel={text} testID={testID}>
      <Lightbulb color={palette.warnDark} size={18} />
      <Text style={styles.tipText}>{text}</Text>
    </View>
  );
}

/** The big labelled button of hold mode (»Lažje kretnje« / screen reader). */
export function MessButton({
  label,
  a11y,
  icon,
  onPress,
  onPressIn,
  onPressOut,
  testID,
}: {
  label: string;
  a11y: string;
  icon: ReactNode;
  onPress: () => void;
  onPressIn?: () => void;
  onPressOut?: () => void;
  testID: string;
}) {
  return (
    <Pressable
      testID={testID}
      accessibilityRole="button"
      accessibilityLabel={a11y}
      onPress={onPress}
      onPressIn={onPressIn}
      onPressOut={onPressOut}
      style={({ pressed }) => [styles.button, pressed && styles.pressed]}
    >
      {icon}
      <Text style={styles.buttonText}>{label}</Text>
    </Pressable>
  );
}

/** The pet behind the mess (the state loop, never the behaviour scene — the mess is drawn). */
export function MessStage({ view, onMediaExpired }: { view: ChildPetView; onMediaExpired?: () => void }) {
  return (
    <PetMediaView
      media={view.pet.media}
      petState={view.pet.pet_state}
      breed={view.pet.breed_type}
      species={view.pet.species}
      mode={mediaModeOf(view)}
      onMediaExpired={onMediaExpired}
      variant="hud"
      style={StyleSheet.absoluteFill}
      testID="mess-stage"
    />
  );
}

/** The shared top area: the bar, and the tip just under it. */
export function TopArea({ bar, tip }: { bar: ReactNode; tip: string | null }) {
  return (
    <View style={styles.topArea}>
      {bar}
      <TipBanner text={tip} />
    </View>
  );
}

export const LIFT = 44;

const styles = StyleSheet.create({
  sprite: { position: 'absolute', left: 0, top: 0 },
  target: {
    position: 'absolute',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.graphite, 0.55),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.18),
  },
  sparks: { position: 'absolute', width: 0, height: 0 },
  spark: { position: 'absolute', left: 0, top: 0 },
  topArea: { gap: 8 },
  tip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 14,
    paddingVertical: 10,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.graphite, 0.75),
    borderWidth: 1,
    borderColor: alpha(palette.warnDark, 0.4),
  },
  tipText: { flex: 1, color: palette.white, fontSize: 16, fontWeight: '700' },
  button: {
    minHeight: 60,
    minWidth: 200,
    paddingHorizontal: 24,
    borderRadius: radius.button,
    backgroundColor: palette.mint,
    flexDirection: 'row',
    gap: 10,
    alignItems: 'center',
    justifyContent: 'center',
  },
  buttonText: { color: palette.graphite, fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.displayBold },
  pressed: { opacity: 0.8 },
});

