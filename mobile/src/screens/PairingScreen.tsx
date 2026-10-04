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
import { ChevronRight, Eraser, LogIn, PawPrint, RotateCcw, ScrollText, ShieldCheck, User } from 'lucide-react-native';

import { api, saveAuthToken, type ChildPetState } from '@/api/client';
import SignaturePad from '@/components/SignaturePad';
import { petFromChildState } from '@/modules/contract/petFromChildState';
import { hasSignature } from '@/modules/contract/signaturePath';
import { submitSignature } from '@/modules/contract/signContract';
import { logout } from '@/modules/session/logout';
import { isAwaitingContract, lockStateFromPet, useAppStore } from '@/store/appStore';
import type { PairingResponse } from '@/types';

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

/** User-visible strings of the contract step (i18n with M1-18). */
export const CONTRACT_STRINGS = {
  title: 'Pogodba o odgovornosti',
  padHint: 'Podpiši se s prstom v okvir spodaj',
  padLabel: 'Polje za podpis',
  clear: 'Pobriši',
  accept: 'Sprejmem odgovornost',
  retry: 'Poskusi znova',
  logout: 'Odjava',
  invalid: 'Podpis ni veljaven, poskusi znova',
  network: 'Povezava s strežnikom ni uspela. Preveri internet in poskusi znova.',
  failed: 'Podpis ni uspel. Poskusi znova kasneje.',
  locked: {
    hard_stopped: 'Starš je igro začasno ustavil. Pogodbo lahko podpišeš, ko jo spet vklopi.',
    inactive: 'Ljubljenček trenutno ni aktiven. Prosi starša, da preveri nastavitve.',
    game_over: 'Igra je končana, pogodbe ni več mogoče podpisati.',
    ill: 'Ljubljenček je pri veterinarju. Poskusi znova kasneje.',
    default: 'Podpis trenutno ni mogoč. Poskusi znova kasneje.',
  },
} as const;

type LockedReasonKey = keyof typeof CONTRACT_STRINGS.locked;

function lockedMessage(reason: string | null): string {
  const key: LockedReasonKey =
    reason !== null && reason in CONTRACT_STRINGS.locked ? (reason as LockedReasonKey) : 'default';
  return CONTRACT_STRINGS.locked[key];
}

type Step = 'login' | 'pin' | 'contract';

interface ContractError {
  message: string;
  /** Offline / 5xx: show the "Poskusi znova" button (re-sends the same signature). */
  retryable: boolean;
}

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
  const [signature, setSignature] = useState('');
  const [isSigning, setIsSigning] = useState(false);
  const [contractError, setContractError] = useState<ContractError | null>(null);

  const signIn = useAppStore((s) => s.signIn);
  const isSignedIn = useAppStore((s) => s.authToken !== null);
  const sessionUser = useAppStore((s) => s.user);
  const sessionPet = useAppStore((s) => s.pet);
  const setPet = useAppStore((s) => s.setPet);
  const setPairingStatus = useAppStore((s) => s.setPairingStatus);
  const setLockState = useAppStore((s) => s.setLockState);

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
      // Same store update as the launch-time session restore (M1-12); AppNavigator routes by role.
      signIn({ token: response.token, user: response.user, pet: response.pet });

      if (response.user.role === 'child') {
        if (!response.pet) setStep('pin');
        // Paired earlier but never signed (M1-07b): the pet is still unborn.
        else if (isAwaitingContract(response.pet)) setStep('contract');
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
      // The pet is unborn on the server until the contract is signed (M1-07b).
      setStep('contract');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Seznanitev ni uspela. Preverite PIN.');
    } finally {
      setIsLoading(false);
    }
  };

  // ── Step 3: Sign the contract (POST /api/child/contract, M1-07b) ──
  /** Put the server's pet (born now) into the session; AppNavigator then shows the HUD. */
  const completeContract = (state: ChildPetState) => {
    const pet = petFromChildState(state, {
      userId: sessionUser?.id ?? sessionPet?.user_id ?? 0,
      petDna: sessionPet?.pet_dna ?? pairingResponse?.pet.pet_dna ?? null,
    });
    setPet(pet);
    setPairingStatus('paired');
    setLockState(lockStateFromPet(pet));
  };

  const handleAcceptContract = async () => {
    if (!hasSignature(signature) || isSigning) return;
    setIsSigning(true);
    setContractError(null);
    const outcome = await submitSignature(signature);
    setIsSigning(false);

    switch (outcome.kind) {
      case 'signed':
      case 'already_signed':
        completeContract(outcome.state);
        return;
      case 'invalid':
        setSignature('');
        setContractError({ message: CONTRACT_STRINGS.invalid, retryable: false });
        return;
      case 'locked':
        setContractError({ message: lockedMessage(outcome.reason), retryable: false });
        return;
      case 'retryable':
        setContractError({ message: CONTRACT_STRINGS.network, retryable: true });
        return;
      case 'failed':
        setContractError({ message: CONTRACT_STRINGS.failed, retryable: false });
        return;
    }
  };

  const signatureReady = hasSignature(signature);

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
              {step === 'login'
                ? 'Prijava v račun'
                : step === 'pin'
                  ? 'Vnos 6-mestne kode za seznanitev (PIN)'
                  : CONTRACT_STRINGS.title}
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
                  setPinDigits(Array(PIN_LENGTH).fill(''));
                  setError(null);
                  if (isSignedIn) {
                    // A signed-in child without a pet: going "back" means signing out.
                    void logout();
                  } else {
                    setStep('login');
                  }
                }}
              >
                <Text style={styles.backButtonText}>← Nazaj na prijavo</Text>
              </Pressable>
            </View>
          )}
        </ScrollView>
      </KeyboardAvoidingView>

      {/* Responsibility Contract Modal — the pet is born when the server accepts the signature */}
      <Modal visible={step === 'contract'} animationType="slide" transparent>
        <View style={styles.modalBackdrop}>
          <View style={styles.contractModal}>
            <View style={styles.contractHeader}>
              <View style={styles.contractIconBadge}>
                <ScrollText color="#818cf8" size={22} />
              </View>
              <Text style={styles.contractTitle}>{CONTRACT_STRINGS.title}</Text>
            </View>

            <ScrollView style={styles.contractScroll}>
              <Text style={styles.contractBody}>{CONTRACT_TEXT}</Text>
            </ScrollView>

            <Text style={styles.padHint}>{CONTRACT_STRINGS.padHint}</Text>
            <SignaturePad
              value={signature}
              onChange={(path) => {
                setSignature(path);
                if (contractError && !contractError.retryable) setContractError(null);
              }}
              disabled={isSigning}
              accessibilityLabel={CONTRACT_STRINGS.padLabel}
            />
            <Pressable
              style={styles.clearButton}
              onPress={() => setSignature('')}
              disabled={isSigning || signature.length === 0}
            >
              <Eraser color="#94a3b8" size={14} />
              <Text style={styles.clearButtonText}>{CONTRACT_STRINGS.clear}</Text>
            </Pressable>

            {contractError && (
              <View style={styles.errorBox} testID="contract-error">
                <Text style={styles.errorText}>{contractError.message}</Text>
              </View>
            )}

            {contractError?.retryable ? (
              <Pressable
                style={({ pressed }) => [styles.primaryButton, isSigning && styles.buttonDisabled, pressed && styles.pressed]}
                onPress={handleAcceptContract}
                disabled={isSigning}
              >
                {isSigning ? (
                  <ActivityIndicator color="#ffffff" testID="contract-loading" />
                ) : (
                  <>
                    <RotateCcw color="#ffffff" size={18} />
                    <Text style={styles.primaryButtonText}>{CONTRACT_STRINGS.retry}</Text>
                  </>
                )}
              </Pressable>
            ) : (
              <Pressable
                style={({ pressed }) => [
                  styles.primaryButton,
                  (!signatureReady || isSigning) && styles.buttonDisabled,
                  pressed && styles.pressed,
                ]}
                onPress={handleAcceptContract}
                disabled={!signatureReady || isSigning}
              >
                {isSigning ? (
                  <ActivityIndicator color="#ffffff" testID="contract-loading" />
                ) : (
                  <Text style={styles.primaryButtonText}>{CONTRACT_STRINGS.accept}</Text>
                )}
              </Pressable>
            )}

            {isSignedIn && (
              <Pressable
                style={styles.backButton}
                onPress={() => {
                  void logout();
                }}
                disabled={isSigning}
              >
                <Text style={styles.backButtonText}>{CONTRACT_STRINGS.logout}</Text>
              </Pressable>
            )}
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
  padHint: {
    fontSize: 13,
    color: '#94a3b8',
    marginBottom: 8,
  },
  clearButton: {
    flexDirection: 'row',
    alignSelf: 'flex-end',
    alignItems: 'center',
    gap: 4,
    paddingVertical: 6,
    paddingHorizontal: 4,
  },
  clearButtonText: {
    fontSize: 12,
    color: '#94a3b8',
  },
});
