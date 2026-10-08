/**
 * Soft hearts over the dog while it is happy (M5-R05, PLAY_CUDDLE_SPEC §5) — drawn in the
 * app when the pet has no `playing` video (trial / grandfathered / free tier play `idle`).
 * Three hearts rise and fade in a slow loop; with "reduce motion" they stand still.
 * Decorative: hidden from screen readers (the "Kuža je vesel" badge carries the meaning)
 * and never catches touches.
 */

import { useEffect, useMemo } from 'react';
import { Animated, StyleSheet, View } from 'react-native';
import { Heart } from 'lucide-react-native';

import { alpha, palette } from '@/theme';

const HEARTS = [
  { left: '34%', top: '38%', size: 22, delay: 0 },
  { left: '58%', top: '33%', size: 18, delay: 900 },
  { left: '46%', top: '45%', size: 26, delay: 1800 },
] as const;

const RISE_MS = 2_600;

export interface HeartsLayerProps {
  reduceMotion: boolean;
  testID?: string;
}

export default function HeartsLayer({ reduceMotion, testID = 'hud-hearts' }: HeartsLayerProps) {
  const values = useMemo(() => HEARTS.map(() => new Animated.Value(0)), []);
  useEffect(() => {
    if (reduceMotion) {
      values.forEach((v) => v.setValue(0.5));
      return;
    }
    const loops = values.map((v, i) =>
      Animated.loop(
        Animated.sequence([
          Animated.delay(HEARTS[i].delay),
          Animated.timing(v, { toValue: 1, duration: RISE_MS, useNativeDriver: true }),
          Animated.timing(v, { toValue: 0, duration: 0, useNativeDriver: true }),
        ]),
      ),
    );
    loops.forEach((l) => l.start());
    return () => loops.forEach((l) => l.stop());
  }, [reduceMotion, values]);

  return (
    <View
      pointerEvents="none"
      style={styles.layer}
      testID={testID}
      importantForAccessibility="no-hide-descendants"
      accessibilityElementsHidden
    >
      {HEARTS.map((heart, i) => {
        const v = values[i];
        const translateY = reduceMotion ? 0 : v.interpolate({ inputRange: [0, 1], outputRange: [0, -70] });
        const opacity = reduceMotion ? 0.8 : v.interpolate({ inputRange: [0, 0.2, 0.8, 1], outputRange: [0, 0.9, 0.6, 0] });
        return (
          <Animated.View
            key={i}
            style={[styles.heart, { left: heart.left, top: heart.top, opacity, transform: [{ translateY }] }]}
          >
            <Heart color={palette.raspberry} fill={alpha(palette.raspberry, 0.85)} size={heart.size} />
          </Animated.View>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  layer: { ...StyleSheet.absoluteFill, zIndex: 5 },
  heart: { position: 'absolute' },
});
