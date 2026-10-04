/**
 * AddChildScreen — parent "Dodaj otroka" and "Nova koda za prijavo" (M2-02).
 *
 * New child:   nickname (+ optional birth year) → `POST /api/parent/children`
 *              → "Nov pes" or "Pridruži se psu …" (active pets of the family)
 *              → `POST /api/parent/generate-pin {child_id, pet_id?}` → PIN.
 * Existing child without a pet: starts at the pet choice.
 * Paired child: starts at the PIN (mode `relogin` — a new device, same pet).
 *
 * The PIN step shows `734 912` with a live 15-min countdown, "Nova koda" (429 →
 * cooldown from Retry-After) and polls the dashboard every 5 s while the PIN is
 * valid, until that child shows a pet or a new device → "Otrok je povezan!".
 * Parent UI = clean light theme (ADR-007).
 */

import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
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
import { CheckCircle, ChevronLeft, ChevronRight, Dog, KeyRound, Lock, PawPrint, RefreshCw, Smartphone } from 'lucide-react-native';

import type { ChildPinResponse, PinLoginMode } from '@/api/client';
import { useCreateChild } from '@/hooks/queries/useFamilyMutations';
import { useGeneratePin } from '@/hooks/queries/useGeneratePin';
import { useParentDashboard } from '@/hooks/queries/useParentDashboard';
import { useCountdown } from '@/hooks/useCountdown';
import {
  breedLabel,
  caretakerNames,
  classifyCreateChildError,
  familyFromDashboard,
  isChildConnected,
  joinablePets,
  NICKNAME_MAX_LENGTH,
  normalizeNickname,
  parseBirthYear,
  birthYearRange,
  type ChildBaseline,
  type CreateChildErrorKind,
  type FamilyChild,
} from '@/modules/family/family';
import { classifyPinError, formatCountdown, formatPin, type PinErrorKind } from '@/modules/pairing/pin';
import { refreshSessionPet } from '@/modules/session/logout';

/** All user-visible strings of this screen (extract to i18n with M1-18). */
export const ADD_CHILD_STRINGS = {
  title: 'Dodaj otroka',
  titleRelogin: 'Nova koda za prijavo',
  back: 'Nazaj',
  // Step 1 — profile
  nicknameLabel: 'Vzdevek otroka',
  nicknamePlaceholder: 'npr. Maja',
  birthYearLabel: 'Letnica rojstva (neobvezno)',
  birthYearPlaceholder: 'npr. 2016',
  privacy: 'Ne potrebujemo e-pošte ali priimka.',
  privacyDetail: 'Otrok se bo prijavil samo s kodo, ki jo ustvarite tukaj.',
  next: 'Naprej',
  nicknameInvalid: `Vpišite vzdevek (do ${NICKNAME_MAX_LENGTH} znakov, brez @).`,
  birthYearInvalid: (min: number, max: number) => `Letnica mora biti med ${min} in ${max}.`,
  createErrors: {
    too_many_children: 'V družini je lahko največ 10 otrok.',
    invalid_name: 'Vzdevek lahko vsebuje le črke, številke, presledke, vezaj, opuščaj in piko.',
    offline: 'Ni povezave s strežnikom. Preverite internet in poskusite znova.',
    server: 'Otroka trenutno ni bilo mogoče dodati. Poskusite znova.',
  } satisfies Record<CreateChildErrorKind, string>,
  // Step 2 — pet
  petTitle: (name: string) => `Za katerega psa bo skrbel(a) ${name}?`,
  newPet: 'Nov pes',
  newPetHint: 'Otrok dobi svojega kužka. Rodi se, ko otrok podpiše Pogodbo o odgovornosti.',
  joinPet: (label: string) => `Pridruži se psu: ${label}`,
  joinPetHint: (names: string) => (names ? `Zanj že skrbi: ${names}. Skupni pes, vsak otrok ima svojo oceno.` : 'Skupni pes, vsak otrok ima svojo oceno.'),
  petsLoading: 'Nalagam pse v družini …',
  // Step 3 — PIN
  pinLabel: 'Koda za prijavo',
  pinFor: (name: string) => `Za: ${name}`,
  instructions: 'Otrok naj na svojem telefonu odpre PetPrep, izbere »Sem otrok« in vtipka to kodo.',
  steps: {
    new_pet: ['Na otrokovem telefonu odprite PetPrep.', 'Otrok izbere »Sem otrok« in vtipka 6-mestno kodo.', 'Otrok podpiše Pogodbo o odgovornosti in kuža se rodi.'],
    join_pet: ['Na otrokovem telefonu odprite PetPrep.', 'Otrok izbere »Sem otrok« in vtipka 6-mestno kodo.', 'Otrok podpiše svojo Pogodbo o odgovornosti, nato lahko skrbi za psa.'],
    relogin: ['Na novem telefonu ali tablici odprite PetPrep.', 'Otrok izbere »Sem otrok« in vtipka 6-mestno kodo.', 'Kuža in napredek ostaneta ista.'],
  } satisfies Record<PinLoginMode, string[]>,
  reloginNote: 'Otrok je lahko prijavljen na največ 3 napravah — ob četrti se najstarejša odjavi.',
  validFor: (time: string) => `Koda velja še ${time}`,
  expired: 'Koda je potekla. Ustvarite novo.',
  newCode: 'Nova koda',
  generating: 'Ustvarjam kodo …',
  waiting: 'Čakam, da otrok vnese kodo …',
  oneTime: 'Koda deluje samo enkrat. Nova koda razveljavi prejšnjo.',
  pairedTitle: 'Otrok je povezan!',
  pairedBody: {
    new_pet: (name: string) => `${name} naj zdaj na svojem telefonu podpiše Pogodbo o odgovornosti — takrat se kuža rodi.`,
    join_pet: (name: string) => `${name} naj zdaj podpiše svojo Pogodbo o odgovornosti, nato lahko skrbi za psa.`,
    relogin: (name: string) => `${name} je prijavljen(a) na novi napravi. Kuža je ostal isti.`,
  } satisfies Record<PinLoginMode, (name: string) => string>,
  toDashboard: 'Na pregled',
  errors: {
    rate_limited: (seconds: number) => `Preveč novih kod v kratkem času. Poskusite znova čez ${seconds} s.`,
    forbidden: 'Kodo lahko ustvari samo starševski račun.',
    unauthorized: 'Seja je potekla. Prijavite se znova.',
    child_not_found: 'Tega otroka ni več v vaši družini.',
    pet_not_joinable: 'Temu psu se ni več mogoče pridružiti. Izberite drugega ali novega psa.',
    already_paired: 'Otrok že skrbi za psa — lahko dobi samo kodo za prijavo na novi napravi.',
    offline: 'Ni povezave s strežnikom. Preverite internet in poskusite znova.',
    server: 'Kode trenutno ni bilo mogoče ustvariti. Poskusite znova.',
  } satisfies Record<PinErrorKind, string | ((seconds: number) => string)>,
  rateLimitedNoWait: 'Preveč novih kod v kratkem času. Poskusite znova.',
  retry: 'Poskusi znova',
} as const;

const S = ADD_CHILD_STRINGS;
const PAIRING_POLL_MS = 5_000;

type Step = 'form' | 'pet' | 'pin';

/** The child this flow is about (created here or picked from the family list). */
interface TargetChild extends ChildBaseline {
  id: number;
  name: string;
}

interface AddChildScreenProps {
  onBack: () => void;
  /** Existing child: no pet yet → pet choice; paired → re-login PIN. Omit for a new child. */
  child?: FamilyChild;
}

export default function AddChildScreen({ onBack, child }: AddChildScreenProps) {
  const [target, setTarget] = useState<TargetChild | null>(
    child ? { id: child.id, name: child.name, devices: child.devices, pet_id: child.pet_id } : null,
  );
  const [step, setStep] = useState<Step>(child ? (child.pet_id === null ? 'pet' : 'pin') : 'form');
  /** pet_id for the PIN request: null = new pet (or re-login). */
  const [joinPetId, setJoinPetId] = useState<number | null>(null);
  const isRelogin = child !== undefined && child.pet_id !== null;

  return (
    <View style={styles.root}>
      <View style={styles.header}>
        <Pressable onPress={onBack} hitSlop={8} accessibilityRole="button" accessibilityLabel={S.back}>
          <ChevronLeft color="#4f46e5" size={28} />
        </Pressable>
        <Text style={styles.headerTitle}>{isRelogin ? S.titleRelogin : S.title}</Text>
      </View>

      {step === 'form' && (
        <ProfileStep
          onCreated={(created) => {
            setTarget({ id: created.id, name: created.name, devices: 0, pet_id: null });
            setStep('pet');
          }}
        />
      )}
      {step === 'pet' && target && (
        <PetStep
          childName={target.name}
          onChoose={(petId) => {
            setJoinPetId(petId);
            setStep('pin');
          }}
        />
      )}
      {step === 'pin' && target && <PinStep target={target} joinPetId={joinPetId} onDone={onBack} />}
    </View>
  );
}

// ── Step 1: nickname + optional birth year ──────────────────────
function ProfileStep({ onCreated }: { onCreated: (child: { id: number; name: string }) => void }) {
  const [nickname, setNickname] = useState('');
  const [birthYear, setBirthYear] = useState('');
  const [validation, setValidation] = useState<string | null>(null);
  const create = useCreateChild();
  const range = birthYearRange();

  const submit = () => {
    if (create.isPending) return;
    const name = normalizeNickname(nickname);
    if (name === null) {
      setValidation(S.nicknameInvalid);
      return;
    }
    const year = parseBirthYear(birthYear);
    if (year === 'invalid') {
      setValidation(S.birthYearInvalid(range.min, range.max));
      return;
    }
    setValidation(null);
    create.mutate(
      { display_name: name, birth_year: year },
      { onSuccess: (res) => onCreated({ id: res.child.id, name: res.child.display_name }) },
    );
  };

  const serverError = create.isError ? S.createErrors[classifyCreateChildError(create.error)] : null;
  const message = validation ?? serverError;

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <View style={styles.formCard}>
          <Text style={styles.fieldLabel}>{S.nicknameLabel}</Text>
          <TextInput
            style={styles.input}
            value={nickname}
            onChangeText={(v) => {
              setNickname(v);
              setValidation(null);
            }}
            placeholder={S.nicknamePlaceholder}
            placeholderTextColor="#94a3b8"
            maxLength={NICKNAME_MAX_LENGTH}
            autoCapitalize="words"
            autoCorrect={false}
            autoComplete="off"
            editable={!create.isPending}
            accessibilityLabel={S.nicknameLabel}
            testID="child-nickname"
          />

          <Text style={styles.fieldLabel}>{S.birthYearLabel}</Text>
          <TextInput
            style={styles.input}
            value={birthYear}
            onChangeText={(v) => {
              setBirthYear(v.replace(/[^0-9]/g, '').slice(0, 4));
              setValidation(null);
            }}
            placeholder={S.birthYearPlaceholder}
            placeholderTextColor="#94a3b8"
            keyboardType="number-pad"
            maxLength={4}
            editable={!create.isPending}
            accessibilityLabel={S.birthYearLabel}
            testID="child-birth-year"
          />

          <View style={styles.privacyBox}>
            <Lock color="#059669" size={16} />
            <View style={styles.flex}>
              <Text style={styles.privacyTitle}>{S.privacy}</Text>
              <Text style={styles.privacyBody}>{S.privacyDetail}</Text>
            </View>
          </View>

          {message && (
            <View style={styles.errorBox} testID="add-child-error">
              <Text style={styles.errorText}>{message}</Text>
            </View>
          )}

          <Pressable
            style={({ pressed }) => [styles.primaryButton, (create.isPending || nickname.trim() === '') && styles.buttonDisabled, pressed && styles.pressed]}
            onPress={submit}
            disabled={create.isPending || nickname.trim() === ''}
            accessibilityRole="button"
          >
            {create.isPending ? <ActivityIndicator color="#ffffff" /> : <Text style={styles.primaryButtonText}>{S.next}</Text>}
          </Pressable>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

// ── Step 2: new pet or join an active pet of the family ─────────
function PetStep({ childName, onChoose }: { childName: string; onChoose: (petId: number | null) => void }) {
  const dashboard = useParentDashboard();
  const family = familyFromDashboard(dashboard.data);
  const pets = joinablePets(family);

  return (
    <ScrollView contentContainerStyle={styles.content}>
      <Text style={styles.stepTitle}>{S.petTitle(childName)}</Text>

      <PetOption
        icon={<PawPrint color="#4f46e5" size={22} />}
        title={S.newPet}
        hint={S.newPetHint}
        onPress={() => onChoose(null)}
        testID="pet-option-new"
      />

      {dashboard.isPending ? (
        <View style={styles.waitingRow}>
          <ActivityIndicator size="small" color="#94a3b8" />
          <Text style={styles.muted}>{S.petsLoading}</Text>
        </View>
      ) : (
        family &&
        pets.map((pet) => (
          <PetOption
            key={pet.id}
            icon={<Dog color="#4f46e5" size={22} />}
            title={S.joinPet(breedLabel(pet.breed_type))}
            hint={S.joinPetHint(caretakerNames(pet, family))}
            onPress={() => onChoose(pet.id)}
            testID={`pet-option-${pet.id}`}
          />
        ))
      )}
    </ScrollView>
  );
}

interface PetOptionProps {
  icon: ReactNode;
  title: string;
  hint: string;
  onPress: () => void;
  testID: string;
}

function PetOption({ icon, title, hint, onPress, testID }: PetOptionProps) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.option, pressed && styles.pressed]}
      accessibilityRole="button"
      accessibilityLabel={title}
      testID={testID}
    >
      <View style={styles.iconBadge}>{icon}</View>
      <View style={styles.flex}>
        <Text style={styles.optionTitle}>{title}</Text>
        <Text style={styles.optionHint}>{hint}</Text>
      </View>
      <ChevronRight color="#94a3b8" size={20} />
    </Pressable>
  );
}

// ── Step 3: PIN with countdown, polling until the child is connected ──
function PinStep({ target, joinPetId, onDone }: { target: TargetChild; joinPetId: number | null; onDone: () => void }) {
  const generate = useGeneratePin();
  const [cooldownUntil, setCooldownUntil] = useState<string | null>(null);
  // Last PIN the server issued. Kept across a failed "Nova koda" (e.g. 429): the
  // server only replaces the PIN on success, so the old one is still valid.
  const [pin, setPin] = useState<ChildPinResponse | null>(null);
  const startedRef = useRef(false);

  const remaining = useCountdown(pin?.expires_at ?? null);
  const cooldown = useCountdown(cooldownUntil);
  const isExpired = pin !== null && remaining <= 0;
  const baseline: ChildBaseline = { devices: target.devices, pet_id: target.pet_id };

  const connectedIn = (data: Parameters<typeof familyFromDashboard>[0]) =>
    isChildConnected(familyFromDashboard(data)?.children.find((c) => c.id === target.id), baseline);

  // Poll only while a valid PIN is shown and the child hasn't connected yet.
  const dashboard = useParentDashboard({
    refetchInterval: (data) => (pin !== null && !isExpired && !connectedIn(data) ? PAIRING_POLL_MS : false),
  });
  const isConnected = pin !== null && connectedIn(dashboard.data);
  const mode: PinLoginMode = pin?.mode ?? (target.pet_id !== null ? 'relogin' : joinPetId !== null ? 'join_pet' : 'new_pet');

  const pinError = useMemo(
    () => (generate.isError ? classifyPinError(generate.error) : null),
    [generate.isError, generate.error],
  );

  const request = () => generate.mutate({ child_id: target.id, pet_id: joinPetId }, { onSuccess: setPin });

  // First PIN as soon as the step opens (the ref guards against a double effect run).
  useEffect(() => {
    if (startedRef.current) return;
    startedRef.current = true;
    request();
  });

  // 429 → block "Nova koda" for Retry-After seconds.
  useEffect(() => {
    if (pinError?.kind === 'rate_limited' && pinError.retryAfterSeconds) {
      setCooldownUntil(new Date(Date.now() + pinError.retryAfterSeconds * 1000).toISOString());
    }
  }, [pinError]);

  // First pairing → pull the pet into the parent's session (legacy single-pet metrics + websocket).
  useEffect(() => {
    if (!isConnected || mode === 'relogin') return;
    refreshSessionPet().catch(() => {
      // The dashboard query already shows the pet; the session pet syncs on next launch.
    });
  }, [isConnected, mode]);

  const isCoolingDown = cooldown > 0;
  const canRequest = !generate.isPending && !isCoolingDown;
  const requestNewPin = () => {
    if (canRequest) request();
  };

  const errorText = (() => {
    if (!pinError) return null;
    if (pinError.kind === 'rate_limited') {
      if (pinError.retryAfterSeconds === null) return S.rateLimitedNoWait;
      if (cooldownUntil !== null && !isCoolingDown) return null; // wait is over — "Nova koda" works again
      return S.errors.rate_limited(isCoolingDown ? cooldown : pinError.retryAfterSeconds);
    }
    return S.errors[pinError.kind];
  })();

  if (isConnected) {
    return (
      <ScrollView contentContainerStyle={styles.content}>
        <View style={styles.card} testID="add-child-paired">
          <CheckCircle color="#10b981" size={44} />
          <Text style={styles.pairedTitle}>{S.pairedTitle}</Text>
          <Text style={styles.bodyCentered}>{S.pairedBody[mode](target.name)}</Text>
          <Pressable style={({ pressed }) => [styles.primaryButton, pressed && styles.pressed]} onPress={onDone}>
            <Text style={styles.primaryButtonText}>{S.toDashboard}</Text>
          </Pressable>
        </View>
      </ScrollView>
    );
  }

  return (
    <ScrollView contentContainerStyle={styles.content}>
      <View style={styles.card}>
        <View style={styles.iconBadge}>
          <KeyRound color="#4f46e5" size={22} />
        </View>
        <Text style={styles.pinLabel}>{S.pinLabel}</Text>
        <Text style={styles.pinFor}>{S.pinFor(target.name)}</Text>

        {pin === null && generate.isPending ? (
          <View style={styles.pinPlaceholder}>
            <ActivityIndicator color="#4f46e5" />
            <Text style={styles.muted}>{S.generating}</Text>
          </View>
        ) : pin !== null ? (
          <>
            <Text
              style={[styles.pin, isExpired && styles.pinExpired]}
              testID="pairing-pin"
              accessibilityLabel={pin.pin.split('').join(' ')}
              selectable
            >
              {formatPin(pin.pin)}
            </Text>
            {isExpired ? (
              <Text style={styles.expired}>{S.expired}</Text>
            ) : (
              <Text style={styles.countdown} testID="pin-countdown">
                {S.validFor(formatCountdown(remaining))}
              </Text>
            )}
          </>
        ) : null}

        {errorText && (
          <View style={styles.errorBox} testID="pin-error">
            <Text style={styles.errorText}>{errorText}</Text>
          </View>
        )}

        <Pressable
          style={({ pressed }) => [styles.secondaryButton, !canRequest && styles.buttonDisabled, pressed && styles.pressed]}
          onPress={requestNewPin}
          disabled={!canRequest}
          accessibilityRole="button"
          accessibilityState={{ disabled: !canRequest }}
        >
          {generate.isPending && pin !== null ? (
            <ActivityIndicator color="#4f46e5" />
          ) : (
            <>
              <RefreshCw color="#4f46e5" size={16} />
              <Text style={styles.secondaryButtonText}>{pin === null && pinError ? S.retry : S.newCode}</Text>
            </>
          )}
        </Pressable>

        {pin !== null && !isExpired && (
          <View style={styles.waitingRow}>
            <ActivityIndicator size="small" color="#94a3b8" />
            <Text style={styles.muted}>{S.waiting}</Text>
          </View>
        )}
      </View>

      <View style={styles.card}>
        <View style={styles.instructionsHeader}>
          <Smartphone color="#4f46e5" size={20} />
          <Text style={styles.body}>{S.instructions}</Text>
        </View>
        {S.steps[mode].map((text, index) => (
          <View key={text} style={styles.stepRow}>
            <Text style={styles.stepNumber}>{index + 1}</Text>
            <Text style={styles.stepText}>{text}</Text>
          </View>
        ))}
        {mode === 'relogin' && <Text style={styles.note}>{S.reloginNote}</Text>}
        <Text style={styles.note}>{S.oneTime}</Text>
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: '#f8fafc' },
  flex: { flex: 1 },
  header: {
    paddingTop: Platform.OS === 'ios' ? 56 : 40,
    paddingHorizontal: 16,
    paddingBottom: 14,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    backgroundColor: '#ffffff',
    borderBottomWidth: 1,
    borderBottomColor: '#e2e8f0',
  },
  headerTitle: { fontSize: 20, fontWeight: '800', color: '#0f172a' },
  content: { padding: 16, gap: 16, paddingBottom: 32 },
  card: {
    backgroundColor: '#ffffff',
    borderRadius: 20,
    padding: 20,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    alignItems: 'center',
    gap: 10,
  },
  formCard: {
    backgroundColor: '#ffffff',
    borderRadius: 20,
    padding: 20,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    gap: 8,
  },
  fieldLabel: { marginTop: 6, fontSize: 13, fontWeight: '700', color: '#334155' },
  input: {
    height: 50,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: '#cbd5e1',
    backgroundColor: '#f8fafc',
    paddingHorizontal: 14,
    fontSize: 16,
    color: '#0f172a',
  },
  privacyBox: {
    marginTop: 10,
    flexDirection: 'row',
    gap: 10,
    padding: 12,
    borderRadius: 14,
    backgroundColor: '#ecfdf5',
    borderWidth: 1,
    borderColor: '#a7f3d0',
  },
  privacyTitle: { fontSize: 14, fontWeight: '700', color: '#065f46' },
  privacyBody: { marginTop: 2, fontSize: 13, lineHeight: 18, color: '#047857' },
  stepTitle: { fontSize: 18, fontWeight: '800', color: '#0f172a' },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    padding: 16,
    borderRadius: 18,
    backgroundColor: '#ffffff',
    borderWidth: 1,
    borderColor: '#c7d2fe',
  },
  optionTitle: { fontSize: 16, fontWeight: '700', color: '#0f172a' },
  optionHint: { marginTop: 2, fontSize: 13, lineHeight: 18, color: '#64748b' },
  iconBadge: {
    width: 44,
    height: 44,
    borderRadius: 14,
    backgroundColor: '#eef2ff',
    alignItems: 'center',
    justifyContent: 'center',
  },
  pinLabel: { fontSize: 13, fontWeight: '700', letterSpacing: 0.6, color: '#64748b', textTransform: 'uppercase' },
  pinFor: { fontSize: 15, fontWeight: '600', color: '#334155' },
  pinPlaceholder: { height: 72, alignItems: 'center', justifyContent: 'center', gap: 8 },
  pin: { fontSize: 52, fontWeight: '800', letterSpacing: 4, color: '#0f172a', fontVariant: ['tabular-nums'] },
  pinExpired: { color: '#cbd5e1', textDecorationLine: 'line-through' },
  countdown: { fontSize: 15, fontWeight: '600', color: '#10b981', fontVariant: ['tabular-nums'] },
  expired: { fontSize: 15, fontWeight: '600', color: '#f43f5e' },
  errorBox: {
    alignSelf: 'stretch',
    marginTop: 6,
    padding: 12,
    borderRadius: 12,
    backgroundColor: '#fff1f2',
    borderWidth: 1,
    borderColor: '#fecdd3',
  },
  errorText: { fontSize: 13, color: '#be123c', textAlign: 'center' },
  secondaryButton: {
    alignSelf: 'stretch',
    height: 48,
    marginTop: 6,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: '#c7d2fe',
    backgroundColor: '#eef2ff',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
  },
  secondaryButtonText: { fontSize: 15, fontWeight: '700', color: '#4f46e5' },
  buttonDisabled: { opacity: 0.5 },
  waitingRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 4 },
  muted: { fontSize: 13, color: '#64748b' },
  instructionsHeader: { flexDirection: 'row', alignItems: 'center', gap: 10, alignSelf: 'stretch' },
  body: { flex: 1, fontSize: 14, lineHeight: 20, color: '#334155' },
  bodyCentered: { fontSize: 14, lineHeight: 20, color: '#334155', textAlign: 'center' },
  stepRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, alignSelf: 'stretch' },
  stepNumber: {
    width: 22,
    height: 22,
    borderRadius: 11,
    overflow: 'hidden',
    backgroundColor: '#eef2ff',
    color: '#4f46e5',
    fontSize: 12,
    fontWeight: '800',
    textAlign: 'center',
    lineHeight: 22,
  },
  stepText: { flex: 1, fontSize: 14, lineHeight: 20, color: '#334155' },
  note: { alignSelf: 'stretch', fontSize: 12, color: '#94a3b8' },
  pairedTitle: { fontSize: 20, fontWeight: '800', color: '#0f172a' },
  primaryButton: {
    alignSelf: 'stretch',
    height: 50,
    marginTop: 12,
    borderRadius: 14,
    backgroundColor: '#4f46e5',
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryButtonText: { fontSize: 16, fontWeight: '700', color: '#ffffff' },
  pressed: { opacity: 0.85 },
});
