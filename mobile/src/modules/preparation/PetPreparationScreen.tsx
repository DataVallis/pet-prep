/**
 * M5-T06 (APP_REFINEMENT_SPEC §13): the child's »Pripravljamo: Luna … to lahko traja nekaj
 * minut« over the HUD while the pet's first things are on their way. Curio waves gently
 * (a slow bob + tilt in a loop, RN Animated on the native driver; still with Reduce Motion).
 * It reads as an ordinary background set-up — no mention of how the pet is made.
 *
 * Rendered by the HUD only while `showPreparationScreen()` (the server's `preparing`, never
 * over a lock). The "Starš?" exit stays reachable (a set-up is not a dead end).
 */

import { useEffect, useRef } from 'react';
import { ActivityIndicator, Animated, Easing, Image, StyleSheet, View } from 'react-native';

import ParentExitLink from '@/components/ParentExitLink';
import { Text } from '@/components/ui/Text';
import { preparationA11y, preparationBody, preparationTitle } from '@/modules/preparation/preparation';
import { alpha, fonts, palette, tightTracking } from '@/theme';

// Curio, the PetPrep mascot (brand/mascot/curio-cutout.png, cropped for the app).
// eslint-disable-next-line @typescript-eslint/no-require-imports
const CURIO = require('../../../assets/curio.png') as number;

export const CURIO_LOOP_MS = 2_400;

interface Props {
  name: string | null;
  species: string | null;
  reduceMotion: boolean;
  testID?: string;
}

export default function PetPreparationScreen({ name, species, reduceMotion, testID = 'pet-preparation' }: Props) {
  const phase = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    if (reduceMotion) {
      phase.setValue(0);
      return;
    }
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(phase, { toValue: 1, duration: CURIO_LOOP_MS / 2, easing: Easing.inOut(Easing.sin), useNativeDriver: true }),
        Animated.timing(phase, { toValue: 0, duration: CURIO_LOOP_MS / 2, easing: Easing.inOut(Easing.sin), useNativeDriver: true }),
      ]),
    );
    loop.start();
    return () => loop.stop();
  }, [phase, reduceMotion]);

  const translateY = phase.interpolate({ inputRange: [0, 1], outputRange: [0, -10] });
  const rotate = phase.interpolate({ inputRange: [0, 1], outputRange: ['-3deg', '3deg'] });
  const title = preparationTitle(name, species);
  const body = preparationBody();

  return (
    <View style={styles.overlay} testID={testID} accessibilityViewIsModal>
      <View style={styles.center}>
        <Animated.View
          style={[styles.curioBox, { transform: [{ translateY }, { rotate }] }]}
          testID={`${testID}-curio`}
          accessible
          accessibilityRole="image"
          accessibilityLabel={preparationA11y()}
        >
          <Image source={CURIO} style={styles.curio} resizeMode="contain" />
        </Animated.View>
        <View style={styles.shadow} />
        <Text style={styles.title} accessibilityRole="header" testID={`${testID}-title`} maxFontSizeMultiplier={1.6}>
          {title}
        </Text>
        <Text style={styles.body} testID={`${testID}-body`} accessibilityLiveRegion="polite" maxFontSizeMultiplier={1.8}>
          {body}
        </Text>
        <ActivityIndicator color={palette.mint} style={styles.spinner} />
      </View>
      <View style={styles.exit}>
        <ParentExitLink testID={`${testID}-parent-exit`} />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  overlay: {
    ...StyleSheet.absoluteFill,
    backgroundColor: palette.graphite,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 28,
  },
  center: { alignItems: 'center', maxWidth: 420, width: '100%' },
  curioBox: { width: 168, height: 210, alignItems: 'center', justifyContent: 'flex-end' },
  curio: { width: 168, height: 210 },
  shadow: {
    width: 96,
    height: 10,
    borderRadius: 5,
    marginTop: 4,
    backgroundColor: alpha(palette.black, 0.35),
  },
  title: {
    marginTop: 28,
    color: palette.white,
    fontFamily: fonts.display,
    fontSize: 26,
    letterSpacing: tightTracking(26),
    textAlign: 'center',
  },
  body: { marginTop: 10, color: palette.n300, fontSize: 16, textAlign: 'center' },
  spinner: { marginTop: 22 },
  exit: { position: 'absolute', bottom: 40, alignSelf: 'center' },
});
