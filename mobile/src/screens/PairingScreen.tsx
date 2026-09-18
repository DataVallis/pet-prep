import { useRef, useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  Text,
  TextInput,
  View,
} from 'react-native';
import { ChevronRight, LogIn, PawPrint, Pencil, ScrollText } from 'lucide-react-native';

import { api, saveAuthToken } from '@/api/client';
import { useAppStore } from '@/store/appStore';
import type { PairingResponse, Pet } from '@/types';

const PIN_LENGTH = 6;

const CONTRACT_TEXT = `By accepting, you agree to care for your virtual pet every day:

• Feed your pet when it is hungry.
• Provide fresh water daily.
• Take your pet for walks to keep it healthy and active.
• Clean up after your pet regularly.
• Pay attention to warnings — neglecting your pet has consequences.

If you neglect your pet, it may become sick or be taken to the virtual shelter. Are you ready for this responsibility?`;

type Step = 'login' | 'pin';

export default function PairingScreen() {
  const [step, setStep] = useState<Step>('login');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [pinDigits, setPinDigits] = useState<string[]>(Array(PIN_LENGTH).fill(''));
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pairingResponse, setPairingResponse] = useState<PairingResponse | null>(null);
  const [showContract, setShowContract] = useState(false);
  const [signedAt, setSignedAt] = useState<number | null>(null);

  const setAuthToken = useAppStore((s) => s.setAuthToken);
  const setPet = useAppStore((s) => s.setPet);
  const setPairingStatus = useAppStore((s) => s.setPairingStatus);

  const pinRefs = useRef<(TextInput | null)[]>([]);

  // ── Step 1: Login ──────────────────────────────────────────────
  const handleLogin = async () => {
    if (!email.trim() || !password.trim()) return;
    setIsLoading(true);
    setError(null);
    try {
      const response = await api.login(email.trim(), password);
      await saveAuthToken(response.token);
      setAuthToken(response.token);
      setStep('pin');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Login failed. Check your credentials.');
    } finally {
      setIsLoading(false);
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
      setError(err instanceof Error ? err.message : 'Pairing failed. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  // ── Step 3: Accept Contract ────────────────────────────────────
  const handleAcceptContract = async () => {
    if (!pairingResponse) return;
    const p = pairingResponse.pet;
    const pet: Pet = {
      id: p.id, user_id: 0, breed_type: p.breed_type, pet_dna: p.pet_dna,
      current_video_url: p.current_video_url, hunger_level: p.hunger_level,
      thirst_level: p.thirst_level, energy_level: p.energy_level,
      hygiene_level: p.hygiene_level, daily_step_count: 0, born_at: p.born_at,
      is_active: p.is_active, pet_state: 'idle', illness_until: null,
      escalation_level: 0, is_game_over: false, certificate_eligible: false,
    };
    setPet(pet);
    setPairingStatus('paired');
  };

  const pinComplete = pinDigits.join('').length === PIN_LENGTH;

  return (
    <View className="flex-1 bg-slate-950">
      {/* Ambient glow background */}
      <View className="absolute left-[-80] top-[120] h-64 w-64 rounded-full bg-indigo-600/20" />
      <View className="absolute right-[-60] bottom-[80] h-48 w-48 rounded-full bg-emerald-500/10" />

      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        className="flex-1 items-center justify-center px-6"
      >
        <View className="items-center">
          {/* Logo badge with glassmorphism */}
          <View className="h-20 w-20 items-center justify-center rounded-3xl border border-white/20 bg-white/10 backdrop-blur-md">
            <PawPrint color="#818cf8" size={40} />
          </View>
          <Text className="mt-4 text-3xl font-bold tracking-tight text-white">PetPrep</Text>
          <Text className="mt-1 text-sm text-slate-400">
            {step === 'login' ? 'Sign in to your account' : 'Enter your pairing PIN'}
          </Text>
        </View>

        {/* ── Step 1: Login Form ── */}
        {step === 'login' && (
          <View className="mt-10 w-full max-w-[300]">
            <View className="flex-col gap-3">
              <TextInput
                className="h-14 rounded-2xl border border-white/15 bg-white/5 px-4 text-base text-white"
                placeholder="Email"
                placeholderTextColor="#64748b"
                keyboardType="email-address"
                autoCapitalize="none"
                autoCorrect={false}
                value={email}
                onChangeText={setEmail}
                editable={!isLoading}
              />
              <TextInput
                className="h-14 rounded-2xl border border-white/15 bg-white/5 px-4 text-base text-white"
                placeholder="Password"
                placeholderTextColor="#64748b"
                secureTextEntry
                value={password}
                onChangeText={setPassword}
                editable={!isLoading}
                onSubmitEditing={handleLogin}
              />
            </View>

            {error && (
              <View className="mt-5 rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3">
                <Text className="text-sm text-rose-400">{error}</Text>
              </View>
            )}

            <Pressable
              className={`mt-6 flex-row items-center justify-center gap-2 rounded-2xl py-4 active:scale-95 ${
                email.trim() && password.trim() && !isLoading
                  ? 'bg-indigo-600 shadow-lg shadow-indigo-600/30'
                  : 'bg-slate-800/60'
              }`}
              onPress={handleLogin}
              disabled={isLoading || !email.trim() || !password.trim()}
            >
              {isLoading ? (
                <ActivityIndicator color="#ffffff" />
              ) : (
                <>
                  <LogIn color="#ffffff" size={18} />
                  <Text className="text-base font-semibold text-white">Sign In</Text>
                </>
              )}
            </Pressable>

            <Text className="mt-6 text-center text-xs text-slate-500">
              Demo: parent@test.com / password{'\n'}child@test.com / password
            </Text>
          </View>
        )}

        {/* ── Step 2: PIN Entry ── */}
        {step === 'pin' && (
          <View className="mt-10">
            <View className="flex-row gap-3">
              {pinDigits.map((digit, index) => (
                <TextInput
                  key={index}
                  ref={(el) => { pinRefs.current[index] = el; }}
                  className={`h-16 w-12 rounded-2xl border text-center text-2xl font-bold text-white ${
                    digit
                      ? 'border-indigo-500/60 bg-indigo-500/15'
                      : 'border-white/15 bg-white/5'
                  }`}
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
              <View className="mt-5 rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3">
                <Text className="text-sm text-rose-400">{error}</Text>
              </View>
            )}

            <Pressable
              className={`mt-8 flex-row items-center justify-center gap-2 rounded-2xl px-10 py-4 active:scale-95 ${
                pinComplete && !isLoading
                  ? 'bg-indigo-600 shadow-lg shadow-indigo-600/30'
                  : 'bg-slate-800/60'
              }`}
              onPress={handleSubmitPin}
              disabled={isLoading || !pinComplete}
            >
              {isLoading ? (
                <ActivityIndicator color="#ffffff" />
              ) : (
                <>
                  <ChevronRight color="#ffffff" size={18} />
                  <Text className="text-base font-semibold text-white">Pair</Text>
                </>
              )}
            </Pressable>

            <Pressable
              className="mt-4 items-center"
              onPress={() => { setStep('login'); setPinDigits(Array(PIN_LENGTH).fill('')); setError(null); }}
            >
              <Text className="text-sm text-slate-500">← Back to login</Text>
            </Pressable>
          </View>
        )}
      </KeyboardAvoidingView>

      {/* Responsibility Contract Modal */}
      <Modal visible={showContract} animationType="slide" transparent>
        <View className="flex-1 items-center justify-center bg-black/70 p-4">
          <View className="max-h-[80%] w-full rounded-3xl border border-white/15 bg-slate-800/95 p-6 shadow-2xl">
            <View className="flex-row items-center gap-2">
              <View className="h-10 w-10 items-center justify-center rounded-xl bg-indigo-500/20">
                <ScrollText color="#818cf8" size={20} />
              </View>
              <Text className="text-xl font-bold text-white">Responsibility Contract</Text>
            </View>

            <ScrollView className="mt-5 max-h-60">
              <Text className="text-sm leading-6 text-slate-300">{CONTRACT_TEXT}</Text>
            </ScrollView>

            <Pressable
              className="mt-5 flex-row items-center justify-center gap-2 rounded-xl border border-white/15 bg-white/5 py-3.5 active:scale-95"
              onPress={() => setSignedAt(Date.now())}
            >
              <Pencil color="#a5b4fc" size={18} />
              <Text className="text-sm font-medium text-white">
                {signedAt ? `Signed · ${new Date(signedAt).toLocaleTimeString()}` : 'Tap to Sign'}
              </Text>
            </Pressable>

            <Pressable
              className={`mt-3 items-center rounded-xl py-4 active:scale-95 ${
                signedAt ? 'bg-indigo-600 shadow-lg shadow-indigo-600/30' : 'bg-slate-700/50'
              }`}
              onPress={handleAcceptContract}
              disabled={!signedAt}
            >
              <Text className="text-base font-semibold text-white">I Accept</Text>
            </Pressable>
          </View>
        </View>
      </Modal>
    </View>
  );
}
