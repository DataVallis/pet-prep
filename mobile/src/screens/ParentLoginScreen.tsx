/**
 * ParentLoginScreen — "Sem starš": e-mail + password (`POST /api/login`).
 * Children never log in here any more (M2-02): a child account is signed out
 * again right away and pointed to the PIN path.
 */

import { useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { ChevronLeft, LogIn, ShieldCheck } from 'lucide-react-native';

import { ApiError, api, saveAuthToken } from '@/api/client';
import { logout } from '@/modules/session/logout';
import { useAppStore } from '@/store/appStore';

/** Seeded demo accounts are offered only in development builds (Expo Go / dev client). */
export function showDevLogins(): boolean {
  return __DEV__ === true;
}

/** All user-visible strings of this screen (extract to i18n with M1-18). */
export const PARENT_LOGIN_STRINGS = {
  title: 'Prijava za starše',
  back: 'Nazaj',
  email: 'E-pošta',
  password: 'Geslo',
  submit: 'Prijava',
  devTitle: 'HITRO TESTIRANJE (1 KLIK):',
  devParent: 'Starš (Nadzor)',
  divider: 'ali ročna prijava',
  wrongCredentials: 'E-pošta ali geslo ni pravilno.',
  offline: 'Ni povezave s strežnikom. Preverite internet in poskusite znova.',
  failed: 'Prijava ni uspela. Poskusite znova.',
  childAccount: 'To je otroški račun. Otrok se prijavi s kodo, ki jo ustvari starš (»Sem otrok«).',
} as const;

const S = PARENT_LOGIN_STRINGS;

interface ParentLoginScreenProps {
  onBack: () => void;
}

export default function ParentLoginScreen({ onBack }: ParentLoginScreenProps) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const signIn = useAppStore((s) => s.signIn);

  const handleLogin = async (loginEmail?: string, loginPassword?: string) => {
    const targetEmail = (loginEmail ?? email).trim();
    const targetPassword = loginPassword ?? password;
    if (!targetEmail || !targetPassword || isLoading) return;

    setIsLoading(true);
    setError(null);
    try {
      const response = await api.login(targetEmail, targetPassword);
      await saveAuthToken(response.token);
      if (response.user.role !== 'parent') {
        // Legacy child e-mail account: revoke the fresh token, show the PIN path instead.
        await logout();
        setError(S.childAccount);
        return;
      }
      // Same store update as the launch-time session restore (M1-12); AppNavigator routes by role.
      signIn({ token: response.token, user: response.user, pet: response.pet });
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.status === 401 || err.status === 422 ? S.wrongCredentials : S.failed);
      } else {
        setError(S.offline);
      }
    } finally {
      setIsLoading(false);
    }
  };

  const ready = email.trim().length > 0 && password.trim().length > 0;

  return (
    <View style={styles.container}>
      <View style={[styles.glowOrb, styles.glowIndigo]} />
      <View style={[styles.glowOrb, styles.glowEmerald]} />

      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
        <ScrollView
          contentContainerStyle={styles.scrollContent}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
          <Pressable onPress={onBack} hitSlop={10} style={styles.backRow} accessibilityRole="button" accessibilityLabel={S.back}>
            <ChevronLeft color="#94a3b8" size={22} />
            <Text style={styles.backText}>{S.back}</Text>
          </Pressable>

          <View style={styles.header}>
            <View style={styles.logoBadge}>
              <ShieldCheck color="#10b981" size={34} />
            </View>
            <Text style={styles.title}>{S.title}</Text>
          </View>

          <View style={styles.formCard}>
            {/* Quick 1-tap demo login — development builds only (M0-10). __DEV__ is false in
                EAS preview/production builds, so seeded test credentials never ship. */}
            {showDevLogins() && (
              <View style={styles.quickAccessSection}>
                <Text style={styles.quickAccessTitle}>{S.devTitle}</Text>
                <Pressable
                  style={({ pressed }) => [styles.quickButton, pressed && styles.pressed]}
                  onPress={() => {
                    setEmail('parent@test.com');
                    setPassword('password');
                    void handleLogin('parent@test.com', 'password');
                  }}
                  disabled={isLoading}
                >
                  <ShieldCheck color="#10b981" size={16} />
                  <Text style={styles.quickButtonText}>{S.devParent}</Text>
                </Pressable>
                <View style={styles.dividerContainer}>
                  <View style={styles.dividerLine} />
                  <Text style={styles.dividerText}>{S.divider}</Text>
                  <View style={styles.dividerLine} />
                </View>
              </View>
            )}

            <View style={styles.inputsContainer}>
              <TextInput
                style={styles.input}
                placeholder={S.email}
                placeholderTextColor="#64748b"
                keyboardType="email-address"
                autoCapitalize="none"
                autoCorrect={false}
                autoComplete="email"
                value={email}
                onChangeText={setEmail}
                editable={!isLoading}
              />
              <TextInput
                style={styles.input}
                placeholder={S.password}
                placeholderTextColor="#64748b"
                secureTextEntry
                autoComplete="password"
                value={password}
                onChangeText={setPassword}
                editable={!isLoading}
                onSubmitEditing={() => void handleLogin()}
              />
            </View>

            {error && (
              <View style={styles.errorBox} testID="parent-login-error">
                <Text style={styles.errorText}>{error}</Text>
              </View>
            )}

            <Pressable
              style={({ pressed }) => [styles.primaryButton, (!ready || isLoading) && styles.buttonDisabled, pressed && styles.pressed]}
              onPress={() => void handleLogin()}
              disabled={isLoading || !ready}
              accessibilityRole="button"
            >
              {isLoading ? (
                <ActivityIndicator color="#ffffff" />
              ) : (
                <>
                  <LogIn color="#ffffff" size={18} />
                  <Text style={styles.primaryButtonText}>{S.submit}</Text>
                </>
              )}
            </Pressable>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#020617' },
  flex: { flex: 1 },
  glowOrb: { position: 'absolute', borderRadius: 9999 },
  glowIndigo: { width: 280, height: 280, top: 60, left: -80, backgroundColor: 'rgba(79, 70, 229, 0.2)' },
  glowEmerald: { width: 240, height: 240, bottom: 80, right: -60, backgroundColor: 'rgba(16, 185, 129, 0.12)' },
  scrollContent: { flexGrow: 1, alignItems: 'center', paddingHorizontal: 24, paddingTop: 56, paddingBottom: 40 },
  backRow: { alignSelf: 'flex-start', flexDirection: 'row', alignItems: 'center', gap: 2, paddingVertical: 6 },
  backText: { fontSize: 15, color: '#94a3b8' },
  header: { alignItems: 'center', marginTop: 12, marginBottom: 24 },
  logoBadge: {
    width: 72,
    height: 72,
    borderRadius: 22,
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.18)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  title: { marginTop: 14, fontSize: 26, fontWeight: '800', color: '#ffffff' },
  formCard: {
    width: '100%',
    maxWidth: 360,
    backgroundColor: 'rgba(15, 23, 42, 0.75)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.12)',
    borderRadius: 24,
    padding: 22,
  },
  quickAccessSection: { marginBottom: 4 },
  quickAccessTitle: {
    fontSize: 11,
    fontWeight: '700',
    color: '#64748b',
    letterSpacing: 0.8,
    marginBottom: 10,
    textAlign: 'center',
  },
  quickButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    paddingVertical: 12,
    borderRadius: 14,
    borderWidth: 1,
    backgroundColor: 'rgba(16, 185, 129, 0.12)',
    borderColor: 'rgba(16, 185, 129, 0.35)',
  },
  quickButtonText: { fontSize: 13, fontWeight: '600', color: '#ffffff' },
  dividerContainer: { flexDirection: 'row', alignItems: 'center', marginVertical: 14 },
  dividerLine: { flex: 1, height: 1, backgroundColor: 'rgba(255, 255, 255, 0.1)' },
  dividerText: { paddingHorizontal: 10, fontSize: 11, color: '#64748b' },
  inputsContainer: { gap: 12 },
  input: {
    height: 52,
    backgroundColor: 'rgba(255, 255, 255, 0.05)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
    borderRadius: 14,
    paddingHorizontal: 16,
    fontSize: 15,
    color: '#ffffff',
  },
  errorBox: {
    marginTop: 14,
    padding: 12,
    backgroundColor: 'rgba(244, 63, 94, 0.12)',
    borderWidth: 1,
    borderColor: 'rgba(244, 63, 94, 0.35)',
    borderRadius: 12,
  },
  errorText: { fontSize: 13, color: '#fb7185', textAlign: 'center' },
  primaryButton: {
    marginTop: 18,
    height: 52,
    backgroundColor: '#4f46e5',
    borderRadius: 14,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
  },
  buttonDisabled: { backgroundColor: 'rgba(51, 65, 85, 0.6)' },
  primaryButtonText: { fontSize: 16, fontWeight: '700', color: '#ffffff' },
  pressed: { opacity: 0.85, transform: [{ scale: 0.98 }] },
});
