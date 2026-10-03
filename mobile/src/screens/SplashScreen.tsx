/**
 * SplashScreen — shown while the saved session is restored on launch (M1-12),
 * and when that restore can't reach the server (token kept, retry offered).
 * The role isn't known yet, so it uses the neutral brand background.
 */

import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { PawPrint, WifiOff } from 'lucide-react-native';

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
      <View style={styles.logoBadge}>
        {mode === 'offline' ? <WifiOff color="#fb7185" size={34} /> : <PawPrint color="#818cf8" size={38} />}
      </View>
      <Text style={styles.title}>PetPrep</Text>

      {mode === 'restoring' ? (
        <>
          <ActivityIndicator color="#818cf8" style={styles.spinner} />
          <Text style={styles.subtitle}>{S.restoring}</Text>
        </>
      ) : (
        <>
          <Text style={styles.offlineTitle}>{S.offlineTitle}</Text>
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
    backgroundColor: '#020617',
  },
  logoBadge: {
    width: 80,
    height: 80,
    borderRadius: 24,
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.18)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  title: { marginTop: 14, fontSize: 30, fontWeight: '800', color: '#ffffff' },
  spinner: { marginTop: 24 },
  subtitle: { marginTop: 10, fontSize: 14, lineHeight: 20, color: '#94a3b8', textAlign: 'center' },
  offlineTitle: { marginTop: 20, fontSize: 18, fontWeight: '700', color: '#ffffff' },
  primaryButton: {
    marginTop: 24,
    alignSelf: 'stretch',
    height: 52,
    borderRadius: 14,
    backgroundColor: '#4f46e5',
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryButtonText: { fontSize: 16, fontWeight: '700', color: '#ffffff' },
  linkButton: { marginTop: 14, padding: 8 },
  linkText: { fontSize: 14, color: '#64748b' },
  pressed: { opacity: 0.85 },
});
