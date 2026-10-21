/**
 * M5-T13 — the party hat over the pet on its first birthday (APP_REFINEMENT_SPEC §9). Drawn
 * by the app (no AI media): a mint cone with graphite stripes and one raspberry pom-pom (the
 * screen's single raspberry accent, CGP v2). A gentle wobble; still with Reduce Motion. A tap
 * opens the birthday card (≥ 44 pt target).
 */

import { useEffect, useRef } from 'react';
import { Animated, Easing, Pressable, StyleSheet } from 'react-native';
import Svg, { Circle, Path } from 'react-native-svg';

import { t } from '@/i18n';
import { MIN_TOUCH, alpha, palette } from '@/theme';

export const HAT_WOBBLE_MS = 2_800;

interface Props {
  onPress: () => void;
  reduceMotion: boolean;
  testID?: string;
}

export default function BirthdayHat({ onPress, reduceMotion, testID = 'birthday-hat' }: Props) {
  const phase = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    if (reduceMotion) {
      phase.setValue(0.5);
      return;
    }
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(phase, { toValue: 1, duration: HAT_WOBBLE_MS / 2, easing: Easing.inOut(Easing.sin), useNativeDriver: true }),
        Animated.timing(phase, { toValue: 0, duration: HAT_WOBBLE_MS / 2, easing: Easing.inOut(Easing.sin), useNativeDriver: true }),
      ]),
    );
    loop.start();
    return () => loop.stop();
  }, [phase, reduceMotion]);

  const rotate = phase.interpolate({ inputRange: [0, 1], outputRange: ['-8deg', '8deg'] });

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={t('birthday:hatA11y')}
      hitSlop={8}
      style={({ pressed }) => [styles.touch, pressed && styles.pressed]}
      testID={testID}
    >
      <Animated.View style={{ transform: [{ rotate }] }}>
        <Svg width={56} height={64} viewBox="0 0 56 64">
          <Path d="M28 8 L48 58 Q28 64 8 58 Z" fill={palette.mint} stroke={palette.graphite} strokeWidth={2} strokeLinejoin="round" />
          <Path d="M21 26 L35 24 M16 40 L41 37 M11 52 L46 49" stroke={palette.graphite} strokeWidth={3} strokeLinecap="round" />
          <Circle cx={28} cy={7} r={6} fill={palette.raspberry} stroke={palette.graphite} strokeWidth={2} />
        </Svg>
      </Animated.View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  touch: {
    minWidth: MIN_TOUCH,
    minHeight: MIN_TOUCH,
    padding: 6,
    borderRadius: 999,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.graphite, 0.35),
  },
  pressed: { opacity: 0.8 },
});
