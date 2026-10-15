/**
 * "Posodabljamo PetPrep — takoj smo nazaj." (M5-F13): a small, calm, non-blocking pill
 * while the server is updating / unreachable during a deploy. It never takes touches
 * (`pointerEvents="none"`), never replaces content, and disappears by itself once a
 * request succeeds again (`serverStatusStore`). Child = dark glass, parent = light card.
 * A soft fade in / out — none with Reduce Motion.
 */

import { useEffect, useRef, useState } from 'react';
import { AccessibilityInfo, Animated, Platform, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { RefreshCw } from 'lucide-react-native';
import { useTranslation } from 'react-i18next';

import { Text } from '@/components/ui/Text';
import { useReduceMotion } from '@/hooks/useReduceMotion';
import { strings } from '@/i18n/strings';
import { useServerOutage } from '@/store/serverStatusStore';
import { dark, light, palette, radius } from '@/theme';

/** User-visible strings (`common:serverUpdating`). */
export const SERVER_UPDATING_STRINGS = strings('common', 'serverUpdating');

export const BANNER_FADE_MS = 220;

interface ServerUpdatingBannerProps {
  /** `dark` = child HUD glass, `light` = parent / auth screens. */
  tone: 'dark' | 'light';
  /** Where the pill sits (absolute position from the parent screen). */
  style?: StyleProp<ViewStyle>;
  testID?: string;
}

export default function ServerUpdatingBanner({ tone, style, testID = 'server-updating-banner' }: ServerUpdatingBannerProps) {
  const outage = useServerOutage();
  // Re-render on a language switch also where no parent does (it may sit outside the screens).
  useTranslation();
  const reduceMotion = useReduceMotion();
  const opacity = useRef(new Animated.Value(outage ? 1 : 0)).current;
  // Stay mounted while fading out.
  const [mounted, setMounted] = useState(outage);

  // QA n2: iOS VoiceOver doesn't read a live region — announce the banner once when it appears
  // (Android reads `accessibilityLiveRegion`).
  useEffect(() => {
    if (outage && Platform.OS === 'ios') AccessibilityInfo.announceForAccessibility?.(SERVER_UPDATING_STRINGS.a11y);
  }, [outage]);

  useEffect(() => {
    if (outage) setMounted(true);
    if (reduceMotion) {
      opacity.setValue(outage ? 1 : 0);
      if (!outage) setMounted(false);
      return;
    }
    const animation = Animated.timing(opacity, {
      toValue: outage ? 1 : 0,
      duration: BANNER_FADE_MS,
      useNativeDriver: true,
    });
    animation.start(({ finished }) => {
      if (finished && !outage) setMounted(false);
    });
    return () => animation.stop();
  }, [outage, reduceMotion, opacity]);

  if (!mounted) return null;
  const S = SERVER_UPDATING_STRINGS;
  const t = tone === 'dark' ? toneDark : toneLight;
  return (
    <View pointerEvents="none" style={[styles.slot, style]} testID={`${testID}-slot`}>
      <Animated.View
        style={[styles.pill, t.pill, { opacity }]}
        accessibilityRole="alert"
        accessibilityLiveRegion="polite"
        accessibilityLabel={S.a11y}
        testID={testID}
      >
        <RefreshCw color={t.iconColor} size={14} />
        <Text style={[styles.text, t.text]}>{S.title}</Text>
      </Animated.View>
    </View>
  );
}

const styles = StyleSheet.create({
  slot: {
    alignItems: 'center',
    paddingHorizontal: 16,
  },
  pill: {
    maxWidth: '100%',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: radius.pill,
    borderWidth: 1,
  },
  text: {
    flexShrink: 1,
    fontSize: 13,
    fontWeight: '600',
  },
});

const toneDark = {
  pill: { backgroundColor: dark.glassStrong, borderColor: dark.glassBorder },
  text: { color: dark.ink },
  iconColor: palette.mint,
};

const toneLight = {
  pill: { backgroundColor: light.surface, borderColor: light.border },
  text: { color: light.ink },
  iconColor: light.mintText,
};
