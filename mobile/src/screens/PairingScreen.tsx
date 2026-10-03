import { useRef, useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { ChevronRight, LogIn, PawPrint, Pencil, ScrollText, ShieldCheck, Sparkles, User, Users } from 'lucide-react-native';

import { api, saveAuthToken } from '@/api/client';
import { useAppStore } from '@/store/appStore';
import type { PairingResponse, Pet } from '@/types';

/** Seeded demo accounts are offered only in development builds (Expo Go / dev client). */
export function showDevLogins(): boolean {
  return __DEV__ === true;
}

const PIN_LENGTH = 6;

const CONTRACT_TEXT = `Zavezujem se, da bom vsak dan odgovorno skrbel za svojega virtualnega ljubljenčka:

• Hranil ga bom, ko bo lačen.
• Vsak dan mu bom zagotovil svežo vodo.
• Vodil ga bom na sprehode za gibanje in zdravje.
• Redno bom čistil za njim in skrbel za higieno.
• Odzval se bom na opozorila, preden pride do bolezni ali virtualnega zavetišča.

Ali sprejemaš to odgovornost?`;

type Step = 'login' | 'pin';

interface PairingScreenProps {
  initialStep?: Step;
}

export default function PairingScreen({ initialStep = 'login' }: PairingScreenProps) {
  const [step, setStep] = useState<Step>(initialStep);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [pinDigits, setPinDigits] = useState<string[]>(Array(PIN_LENGTH).fill(''));
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pairingResponse, setPairingResponse] = useState<PairingResponse | null>(null);
  const [showContract, setShowContract] = useState(false);
  const [signedAt, setSignedAt] = useState<number | null>(null);

  const setAuthToken = useAppStore((s) => s.setAuthToken);
  const setUser = useAppStore((s) => s.setUser);
  const setPet = useAppStore((s) => s.setPet);
  const setPairingStatus = useAppStore((s) => s.setPairingStatus);

  const pinRefs = useRef<(TextInput | null)[]>([]);

  // ── Step 1: Login ──────────────────────────────────────────────
  const handleLogin = async (loginEmail?: string, loginPassword?: string) => {
    const targetEmail = (loginEmail ?? email).trim();
    const targetPassword = loginPassword ?? password;

    if (!targetEmail || !targetPassword) return;
    setIsLoading(true);
    setError(null);
    try {
      const response = await api.login(targetEmail, targetPassword);
      await saveAuthToken(response.token);
      setAuthToken(response.token);
      setUser(response.user);

      if (response.pet) {
        setPet(response.pet);
        setPairingStatus('paired');
      } else if (response.user.role === 'child') {
        setStep('pin');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Prijava ni uspela. Preverite podatke.');
    } finally {
      setIsLoading(false);
    }
  };

  // Quick 1-tap demo logins for seamless local testing
  const handleQuickLogin = (role: 'child' | 'parent') => {
    if (!showDevLogins()) return;
    if (role === 'child') {
      setEmail('child@test.com');
      setPassword('password');
      handleLogin('child@test.com', 'password');
    } else {
      setEmail('parent@test.com');
      setPassword('password');
      handleLogin('parent@test.com', 'password');
    }
  };

  // ── Step 2: Pair with PIN ──────────────────────────────────────
  const handlePinChange = (index: number, value: string) => {
    const digit = value.replace(/[^0-9]/g, '').slice(-1);
    const next = [...pinDigits];
    next[index] = digit;
    setPinDigits(next);
    setError(null);
    if (digit && index < PIN_LENGTH - 1) {
      pinRefs.current[index + 1]?.focus();
    }
  };

  const handleKeyPress = (index: number, key: string) => {
    if (key === 'Backspace' && !pinDigits[index] && index > 0) {
      pinRefs.current[index - 1]?.focus();
    }
  };

  const handleSubmitPin = async () => {
    const pin = pinDigits.join('');
    if (pin.length !== PIN_LENGTH) return;
    setIsLoading(true);
    setError(null);
    try {
      const response = await api.pairChild(pin);
      setPairingResponse(response);
      setShowContract(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Seznanitev ni uspela. Preverite PIN.');
    } finally {
      setIsLoading(false);
    }
  };

  // ── Step 3: Accept Contract ────────────────────────────────────
  const handleAcceptContract = async () => {
    if (!pairingResponse) return;
    const p = pairingResponse.pet;
    const pet: Pet = {
      id: p.id,
      user_id: 0,
      breed_type: p.breed_type,
      pet_dna: p.pet_dna,
      current_video_url: p.current_video_url,
      hunger_level: p.hunger_level,
      thirst_level: p.thirst_level,
      energy_level: p.energy_level,
      hygiene_level: p.hygiene_level,
      daily_step_count: 0,
      born_at: p.born_at,
      is_active: p.is_active,
      pet_state: 'idle',
      illness_until: null,
      escalation_level: 0,
      is_game_over: false,
      certificate_eligible: false,
    };
    setPet(pet);
    setPairingStatus('paired');
  };

  const pinComplete = pinDigits.join('').length === PIN_LENGTH;

  return (
    <View style={styles.container}>
      {/* Ambient background glow orbs */}
      <View style={[styles.glowOrb, styles.glowIndigo]} />
      <View style={[styles.glowOrb, styles.glowEmerald]} />

      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        style={styles.keyboardView}
      >
        <ScrollView
          contentContainerStyle={styles.scrollContent}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
          {/* Logo badge with glassmorphism */}
          <View style={styles.header}>
            <View style={styles.logoBadge}>
              <PawPrint color="#818cf8" size={38} />
            </View>
            <Text style={styles.title}>PetPrep</Text>
            <Text style={styles.subtitle}>
              {step === 'login' ? 'Prijava v račun' : 'Vnos 6-mestne kode za seznanitev (PIN)'}
            </Text>
          </View>

          {/* ── Step 1: Login Form ── */}
          {step === 'login' && (
            <View style={styles.formCard}>
              {/* Quick 1-tap demo logins — development builds only (M0-10).
                  __DEV__ is false in EAS preview/production builds, so seeded test
                  credentials never ship to real families. */}
              {showDevLogins() && (
              <View style={styles.quickAccessSection}>
                <Text style={styles.quickAccessTitle}>HITRO TESTIRANJE (1 KLIK):</Text>
                <View style={styles.quickButtonsRow}>
                  <Pressable
                    style={({ pressed }) => [styles.quickButton, styles.quickChildButton, pressed && styles.pressed]}
                    onPress={() => handleQuickLogin('child')}
                    disabled={isLoading}
                  >
                    <User color="#818cf8" size={16} />
                    <Text style={styles.quickButtonText}>Otrok (HUD)</Text>
                  </Pressable>

                  <Pressable
                    style={({ pressed }) => [styles.quickButton, styles.quickParentButton, pressed && styles.pressed]}
                    onPress={() => handleQuickLogin('parent')}
                    disabled={isLoading}
                  >
                    <ShieldCheck color="#10b981" size={16} />
                    <Text style={styles.quickButtonText}>Starš (Nadzor)</Text>
                  </Pressable>
                </View>
              </View>
              )}

              <View style={styles.dividerContainer}>
                <View style={styles.dividerLine} />
                <Text style={styles.dividerText}>ali ročna prijava</Text>
                <View style={styles.dividerLine} />
              </View>

              <View style={styles.inputsContainer}>
                <TextInput
                  style={styles.input}
                  placeholder="E-pošta"
                  placeholderTextColor="#64748b"
                  keyboardType="email-address"
                  autoCapitalize="none"
                  autoCorrect={false}
                  value={email}
                  onChangeText={setEmail}
                  editable={!isLoading}
                />
                <TextInput
                  style={styles.input}
                  placeholder="Geslo (npr. password)"
                  placeholderTextColor="#64748b"
                  secureTextEntry
                  value={password}
                  onChangeText={setPassword}
                  editable={!isLoading}
                  onSubmitEditing={() => handleLogin()}
                />
              </View>

              {error && (
                <View style={styles.errorBox}>
                  <Text style={styles.errorText}>{error}</Text>
                </View>
              )}

              <Pressable
                style={({ pressed }) => [
                  styles.primaryButton,
                  (!email.trim() || !password.trim() || isLoading) && styles.buttonDisabled,
                  pressed && styles.pressed,
                ]}
                onPress={() => handleLogin()}
                disabled={isLoading || !email.trim() || !password.trim()}
              >
                {isLoading ? (
                  <ActivityIndicator color="#ffffff" />
                ) : (
                  <>
                    <LogIn color="#ffffff" size={18} />
                    <Text style={styles.primaryButtonText}>Prijava</Text>
                  </>
                )}
              </Pressable>
            </View>
          )}

          {/* ── Step 2: PIN Entry ── */}
          {step === 'pin' && (
            <View style={styles.formCard}>
              <Text style={styles.pinInstructions}>
                Vnesite 6-mestno kodo PIN, ki jo je vaš starš ustvaril na svoji nadzorni plošči.
              </Text>

              <View style={styles.pinRow}>
                {pinDigits.map((digit, index) => (
                  <TextInput
                    key={index}
                    ref={(el) => {
                      pinRefs.current[index] = el;
                    }}
                    style={[styles.pinBox, digit ? styles.pinBoxFilled : null]}
                    maxLength={1}
                    keyboardType="number-pad"
                    value={digit}
                    onChangeText={(v) => handlePinChange(index, v)}
                    onKeyPress={(e) => handleKeyPress(index, e.nativeEvent.key)}
                    editable={!isLoading}
                  />
                ))}
              </View>

              {error && (
                <View style={styles.errorBox}>
                  <Text style={styles.errorText}>{error}</Text>
                </View>
              )}

              <Pressable
                style={({ pressed }) => [
                  styles.primaryButton,
                  (!pinComplete || isLoading) && styles.buttonDisabled,
                  pressed && styles.pressed,
                ]}
                onPress={handleSubmitPin}
                disabled={isLoading || !pinComplete}
              >
                {isLoading ? (
                  <ActivityIndicator color="#ffffff" />
                ) : (
                  <>
                    <ChevronRight color="#ffffff" size={18} />
                    <Text style={styles.primaryButtonText}>Seznani se</Text>
                  </>
                )}
              </Pressable>

              <Pressable
                style={styles.backButton}
                onPress={() => {
                  setStep('login');
                  setPinDigits(Array(PIN_LENGTH).fill(''));
                  setError(null);
                }}
              >
                <Text style={styles.backButtonText}>← Nazaj na prijavo</Text>
              </Pressable>
            </View>
          )}
        </ScrollView>
      </KeyboardAvoidingView>

      {/* Responsibility Contract Modal */}
      <Modal visible={showContract} animationType="slide" transparent>
        <View style={styles.modalBackdrop}>
          <View style={styles.contractModal}>
            <View style={styles.contractHeader}>
              <View style={styles.contractIconBadge}>
                <ScrollText color="#818cf8" size={22} />
              </View>
              <Text style={styles.contractTitle}>Pogodba o odgovornosti</Text>
            </View>

            <ScrollView style={styles.contractScroll}>
              <Text style={styles.contractBody}>{CONTRACT_TEXT}</Text>
            </ScrollView>

            <Pressable
              style={({ pressed }) => [styles.signButton, pressed && styles.pressed]}
              onPress={() => setSignedAt(Date.now())}
            >
              <Pencil color="#a5b4fc" size={18} />
              <Text style={styles.signButtonText}>
                {signedAt
                  ? `Podpisano · ${new Date(signedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`
                  : 'Klikni za digitalni podpis'}
              </Text>
            </Pressable>

            <Pressable
              style={({ pressed }) => [
                styles.primaryButton,
                !signedAt && styles.buttonDisabled,
                pressed && styles.pressed,
              ]}
              onPress={handleAcceptContract}
              disabled={!signedAt}
            >
              <Text style={styles.primaryButtonText}>Sprejmem odgovornost</Text>
            </Pressable>
          </View>
        </View>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#020617',
  },
  glowOrb: {
    position: 'absolute',
    borderRadius: 9999,
  },
  glowIndigo: {
    width: 280,
    height: 280,
    top: 60,
    left: -80,
    backgroundColor: 'rgba(79, 70, 229, 0.2)',
  },
  glowEmerald: {
    width: 240,
    height: 240,
    bottom: 80,
    right: -60,
    backgroundColor: 'rgba(16, 185, 129, 0.12)',
  },
  keyboardView: {
    flex: 1,
  },
  scrollContent: {
    flexGrow: 1,
    justifyContent: 'center',
    alignItems: 'center',
    paddingHorizontal: 24,
    paddingVertical: 40,
  },
  header: {
    alignItems: 'center',
    marginBottom: 28,
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
    shadowColor: '#4f46e5',
    shadowOffset: { width: 0, height: 8 },
    shadowOpacity: 0.35,
    shadowRadius: 16,
    elevation: 8,
  },
  title: {
    marginTop: 14,
    fontSize: 32,
    fontWeight: '800',
    letterSpacing: -0.5,
    color: '#ffffff',
  },
  subtitle: {
    marginTop: 6,
    fontSize: 14,
    color: '#94a3b8',
    textAlign: 'center',
  },
  formCard: {
    width: '100%',
    maxWidth: 360,
    backgroundColor: 'rgba(15, 23, 42, 0.75)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.12)',
    borderRadius: 24,
    padding: 22,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 12 },
    shadowOpacity: 0.5,
    shadowRadius: 24,
    elevation: 10,
  },
  quickAccessSection: {
    marginBottom: 16,
  },
  quickAccessTitle: {
    fontSize: 11,
    fontWeight: '700',
    color: '#64748b',
    letterSpacing: 0.8,
    marginBottom: 10,
    textAlign: 'center',
  },
  quickButtonsRow: {
    flexDirection: 'row',
    gap: 10,
  },
  quickButton: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    paddingVertical: 12,
    paddingHorizontal: 8,
    borderRadius: 14,
    borderWidth: 1,
  },
  quickChildButton: {
    backgroundColor: 'rgba(79, 70, 229, 0.15)',
    borderColor: 'rgba(99, 102, 241, 0.4)',
  },
  quickParentButton: {
    backgroundColor: 'rgba(16, 185, 129, 0.12)',
    borderColor: 'rgba(16, 185, 129, 0.35)',
  },
  quickButtonText: {
    fontSize: 13,
    fontWeight: '600',
    color: '#ffffff',
  },
  dividerContainer: {
    flexDirection: 'row',
    alignItems: 'center',
    marginVertical: 14,
  },
  dividerLine: {
    flex: 1,
    height: 1,
    backgroundColor: 'rgba(255, 255, 255, 0.1)',
  },
  dividerText: {
    paddingHorizontal: 10,
    fontSize: 11,
    color: '#64748b',
  },
  inputsContainer: {
    gap: 12,
  },
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
  errorText: {
    fontSize: 13,
    color: '#fb7185',
    textAlign: 'center',
  },
  primaryButton: {
    marginTop: 18,
    height: 52,
    backgroundColor: '#4f46e5',
    borderRadius: 14,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    shadowColor: '#4f46e5',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.4,
    shadowRadius: 10,
    elevation: 4,
  },
  buttonDisabled: {
    backgroundColor: 'rgba(51, 65, 85, 0.6)',
    shadowOpacity: 0,
  },
  primaryButtonText: {
    fontSize: 16,
    fontWeight: '700',
    color: '#ffffff',
  },
  pressed: {
    opacity: 0.85,
    transform: [{ scale: 0.98 }],
  },
  pinInstructions: {
    fontSize: 13,
    color: '#94a3b8',
    textAlign: 'center',
    lineHeight: 18,
    marginBottom: 20,
  },
  pinRow: {
    flexDirection: 'row',
    justifyContent: 'center',
    gap: 8,
  },
  pinBox: {
    width: 44,
    height: 56,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
    backgroundColor: 'rgba(255, 255, 255, 0.05)',
    textAlign: 'center',
    fontSize: 22,
    fontWeight: '800',
    color: '#ffffff',
  },
  pinBoxFilled: {
    borderColor: '#6366f1',
    backgroundColor: 'rgba(99, 102, 241, 0.2)',
  },
  backButton: {
    marginTop: 16,
    alignItems: 'center',
    padding: 6,
  },
  backButtonText: {
    fontSize: 13,
    color: '#64748b',
  },
  modalBackdrop: {
    flex: 1,
    backgroundColor: 'rgba(0, 0, 0, 0.75)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 20,
  },
  contractModal: {
    width: '100%',
    maxHeight: '85%',
    backgroundColor: '#0f172a',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
    borderRadius: 24,
    padding: 22,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 16 },
    shadowOpacity: 0.6,
    shadowRadius: 28,
  },
  contractHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    marginBottom: 16,
  },
  contractIconBadge: {
    width: 40,
    height: 40,
    borderRadius: 12,
    backgroundColor: 'rgba(99, 102, 241, 0.2)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  contractTitle: {
    fontSize: 19,
    fontWeight: '700',
    color: '#ffffff',
  },
  contractScroll: {
    maxHeight: 200,
    marginBottom: 16,
  },
  contractBody: {
    fontSize: 14,
    lineHeight: 22,
    color: '#cbd5e1',
  },
  signButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    paddingVertical: 12,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
    backgroundColor: 'rgba(255, 255, 255, 0.05)',
  },
  signButtonText: {
    fontSize: 14,
    fontWeight: '600',
    color: '#ffffff',
  },
});
