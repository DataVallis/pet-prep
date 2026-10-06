/**
 * SplashScreen — shown while the saved session is restored on launch (M1-12),
 * and when that restore can't reach the server (token kept, retry offered).
 * The role isn't known yet, so it uses the neutral brand background: fog with the
 * stacked logo, continuing the native splash (CGP v2 face on #F3F5F2).
 */

import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { WifiOff } from 'lucide-react-native';

import { BrandLogo } from '@/components/brand/BrandLogo';
import { fonts, light, radius, tightTracking } from '@/theme';

/** User-visible strings (extract to i18n with M1-18). */
export const SPLASH_STRINGS = {
  restoring: 'Nalagam …',
  offlineTitle: 'Ni povezave',
  offlineBody: 'Ne moremo doseči strežnika PetPrep. Preverite internet in poskusite znova.',
  retry: 'Poskusi znova',
  logout: 'Odjava',
} as const;

interface SplashScreenProps {
  mode: 'restoring' | 'offline';
  onRetry?: () => void;
  onLogout?: () => void;
}

export default function SplashScreen({ mode, onRetry, onLogout }: SplashScreenProps) {
  const S = SPLASH_STRINGS;
  return (
    <View style={styles.container} testID={`splash-${mode}`}>
      <BrandLogo layout="stacked" width={150} tone="light" testID="splash-logo" />

      {mode === 'restoring' ? (
        <>
          <ActivityIndicator color={light.ink} style={styles.spinner} />
          <Text style={styles.subtitle}>{S.restoring}</Text>
        </>
      ) : (
        <>
          <View style={styles.offlineRow}>
            <WifiOff color={light.danger} size={20} />
            <Text style={styles.offlineTitle}>{S.offlineTitle}</Text>
          </View>
          <Text style={styles.subtitle}>{S.offlineBody}</Text>
          <Pressable
            style={({ pressed }) => [styles.primaryButton, pressed && styles.pressed]}
            onPress={onRetry}
            accessibilityRole="button"
          >
            <Text style={styles.primaryButtonText}>{S.retry}</Text>
          </Pressable>
          <Pressable onPress={onLogout} style={styles.linkButton} accessibilityRole="button">
            <Text style={styles.linkText}>{S.logout}</Text>
          </Pressable>
        </>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 32,
    backgroundColor: light.bg,
  },
  spinner: { marginTop: 28 },
  subtitle: { marginTop: 10, maxWidth: 320, fontSize: 14, lineHeight: 20, color: light.inkMuted, textAlign: 'center' },
  offlineRow: { marginTop: 28, flexDirection: 'row', alignItems: 'center', gap: 8 },
  offlineTitle: { fontFamily: fonts.displayBold, fontSize: 20, letterSpacing: tightTracking(20), color: light.ink },
  primaryButton: {
    marginTop: 24,
    alignSelf: 'stretch',
    maxWidth: 380,
    height: 52,
    borderRadius: radius.button,
    backgroundColor: light.action,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryButtonText: { fontSize: 16, fontWeight: '600', color: light.onAction },
  linkButton: { marginTop: 14, padding: 10, minHeight: 44, justifyContent: 'center' },
  linkText: { fontSize: 14, fontWeight: '600', color: light.inkMuted },
  pressed: { opacity: 0.85 },
});
