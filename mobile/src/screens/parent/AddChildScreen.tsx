/**
 * AddChildScreen — parent "Dodaj otroka" and "Nova koda za prijavo" (M2-02).
 *
 * New child:   nickname (+ optional birth year) → `POST /api/parent/children`
 *              → "Nov pes" / "Nov ljubljenček" → picker (M5-R04, species-aware since
 *                M5-R06-02: species → plan → breed → origin → age → summary)
 *                or "Pridruži se psu …" (active pets of the family; no picker)
 *              → `POST /api/parent/generate-pin {child_id, pet_id? | species, breed, origin, age_stage, plan}` → PIN.
 * Existing child without a pet: starts at the pet choice.
 * Paired child: starts at the PIN (mode `relogin` — a new device, same pet).
 *
 * The PIN step shows `734 912` with a live 15-min countdown, "Nova koda" (429 →
 * cooldown from Retry-After) and polls the dashboard every 5 s while the PIN is
 * valid, until that child shows a pet or a new device → "Otrok je povezan!".
 * Parent UI = clean light theme (ADR-007).
 */

import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Text, TextInput } from '@/components/ui/Text';
import { CheckCircle, ChevronLeft, ChevronRight, Dog, KeyRound, Lock, PawPrint, RefreshCw, Smartphone } from 'lucide-react-native';

import type { ChildPinResponse, NewPetProfile, PetBreed, PinLoginMode } from '@/api/client';
import { useQueryClient } from '@tanstack/react-query';

import PetPickerStep from '@/components/parent/PetPickerStep';
import { breedCatalogueKey, useBreedCatalogue } from '@/hooks/queries/useBreedCatalogue';
import { INITIAL_PICKER_CHOICE, type PickerChoice } from '@/modules/petProfile/picker';
import { challengePackage, useOfferings } from '@/modules/purchases';
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
import { classifyPinError, formatCountdown, formatPin, pinRequestKey, secondsUntil, type PinErrorKind } from '@/modules/pairing/pin';
import { maybeAskForPush } from '@/modules/push/pushPrompt';
import { refreshSessionPet } from '@/modules/session/logout';
import { fonts, palette, tightTracking } from '@/theme';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

const STEP_LINES = ['open', 'code', 'finish'] as const;

/** All user-visible strings of this screen (`parent:addChild`, M1-18). */
export const ADD_CHILD_STRINGS = strings('parent', 'addChild', {
  get nicknameInvalid(): string {
    return t('parent:addChild.nicknameInvalid', { max: NICKNAME_MAX_LENGTH });
  },
  birthYearInvalid: (min: number, max: number) => t('parent:addChild.birthYearInvalid', { min, max }),
  petTitle: (name: string) => t('parent:addChild.petTitle', { name }),
  joinPet: (label: string) => t('parent:addChild.joinPet', { label }),
  joinPetHint: (names: string) =>
    names ? t('parent:addChild.joinPetHintNames', { names }) : t('parent:addChild.joinPetHint'),
  pinFor: (name: string) => t('parent:addChild.pinFor', { name }),
  /** The three numbered instructions per PIN mode. */
  get steps(): Record<PinLoginMode, string[]> {
    const lines = (mode: PinLoginMode) => STEP_LINES.map((line) => t(`parent:addChild.stepLines.${mode}.${line}`));
    return { new_pet: lines('new_pet'), join_pet: lines('join_pet'), relogin: lines('relogin') };
  },
  validFor: (time: string) => t('parent:addChild.validFor', { time }),
  pairedBody: {
    new_pet: (name: string) => t('parent:addChild.pairedBody.new_pet', { name }),
    join_pet: (name: string) => t('parent:addChild.pairedBody.join_pet', { name }),
    relogin: (name: string) => t('parent:addChild.pairedBody.relogin', { name }),
  } satisfies Record<PinLoginMode, (name: string) => string>,
  errors: {
    rate_limited: (seconds: number) => t('parent:addChild.errors.rate_limited', { seconds }),
  },
});

const S = ADD_CHILD_STRINGS;
const PAIRING_POLL_MS = 5_000;

type Step = 'form' | 'pet' | 'dog' | 'pin';

/** The last PIN the server issued in this flow and what it was issued for (`pinRequestKey`). */
interface IssuedPin {
  response: ChildPinResponse;
  key: string;
}

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
  /** M5-R04 / M5-R06-02: the picker choice of a new pet (null = join / re-login → no profile). */
  const [newPetProfile, setNewPetProfile] = useState<NewPetProfile | null>(null);
  const [pickerChoice, setPickerChoice] = useState<PickerChoice>(INITIAL_PICKER_CHOICE);
  /** Breeds the server refused for the current choice (422 `breed_locked`); plan rules live in the picker. */
  const [lockedBreeds, setLockedBreeds] = useState<readonly PetBreed[]>([]);
  const offerings = useOfferings();
  const challengePrice = challengePackage(offerings.data)?.product.priceString ?? null;
  const [pickerNotice, setPickerNotice] = useState<string | null>(null);
  // Lives here, not in PinStep: "Spremeni kužka" / "Nazaj" unmount the PIN step, but the server's
  // PIN stays valid until a new one succeeds — so it is shown again (or reused for the same choice).
  const [issuedPin, setIssuedPin] = useState<IssuedPin | null>(null);
  const isRelogin = child !== undefined && child.pet_id !== null;
  // M5-R06-02: the picker catalogue (`GET /api/breeds`) — loaded early (already on the pet
  // choice) so the picker opens without a spinner; falls back to today's dogs on failure.
  const breedCatalogue = useBreedCatalogue({ enabled: step === 'pet' || step === 'dog' });
  const queryClient = useQueryClient();

  return (
    <View style={styles.root}>
      <View style={styles.header}>
        <Pressable onPress={onBack} hitSlop={8} accessibilityRole="button" accessibilityLabel={S.back}>
          <ChevronLeft color={palette.graphite} size={28} />
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
          severalSpecies={(breedCatalogue.catalogue?.species.length ?? 1) > 1}
          onChoose={(petId) => {
            setJoinPetId(petId);
            // A new pet first gets the picker; joining a shared pet never shows it.
            setNewPetProfile(null);
            setStep(petId === null ? 'dog' : 'pin');
          }}
        />
      )}
      {step === 'dog' && target && (
        <PetPickerStep
          childName={target.name}
          catalogue={breedCatalogue.catalogue}
          initial={pickerChoice}
          serverLockedBreeds={lockedBreeds}
          challengePrice={challengePrice}
          notice={pickerNotice}
          onBack={(choice) => {
            setPickerChoice(choice);
            setPickerNotice(null);
            setStep('pet');
          }}
          onConfirm={(profile, choice) => {
            setPickerChoice(choice);
            setNewPetProfile(profile);
            setPickerNotice(null);
            setStep('pin');
          }}
        />
      )}
      {step === 'pin' && target && (
        <PinStep
          target={target}
          joinPetId={joinPetId}
          profile={joinPetId === null && target.pet_id === null ? newPetProfile : null}
          issued={issuedPin}
          onIssued={setIssuedPin}
          onChangeDog={() => {
            setPickerNotice(null);
            setStep('dog');
          }}
          onProfileRejected={(kind) => {
            // The server refused the choice (premium breed / validation): back to the picker.
            // A species' free breed is never locked (PRODUCT_SPEC §3) — only a paid breed is
            // added (M3-11: premium breeds need the challenge plan; the picker enforces it).
            if (kind === 'breed_locked' && newPetProfile && newPetProfile.plan !== 'free') {
              const refused = newPetProfile.breed;
              setLockedBreeds((current) => (current.includes(refused) ? current : [...current, refused]));
            }
            if (kind === 'species_unavailable') {
              // M5-R06-01: the species was switched off meanwhile — reload what is offered and choose again.
              void queryClient.invalidateQueries({ queryKey: breedCatalogueKey() });
              setPickerChoice((c) => ({ ...c, species: null, breed: null }));
            }
            setPickerNotice(S.errors[kind]);
            setStep('dog');
          }}
          onDone={onBack}
        />
      )}
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
      {
        onSuccess: (res) => {
          onCreated({ id: res.child.id, name: res.child.display_name });
          // M3-02: a child to watch over — ask the parent about alarms (once, see pushPrompt).
          void maybeAskForPush('parent');
        },
      },
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
            placeholderTextColor={palette.n500}
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
            placeholderTextColor={palette.n500}
            keyboardType="number-pad"
            maxLength={4}
            editable={!create.isPending}
            accessibilityLabel={S.birthYearLabel}
            testID="child-birth-year"
          />

          <View style={styles.privacyBox}>
            <Lock color={palette.ok} size={16} />
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
            {create.isPending ? <ActivityIndicator color={palette.white} /> : <Text style={styles.primaryButtonText}>{S.next}</Text>}
          </Pressable>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

// ── Step 2: new pet or join an active pet of the family ─────────
function PetStep({
  childName,
  severalSpecies,
  onChoose,
}: {
  childName: string;
  /** M5-R06-02: the catalogue offers more than one species → "Nov ljubljenček" instead of "Nov pes". */
  severalSpecies: boolean;
  onChoose: (petId: number | null) => void;
}) {
  const dashboard = useParentDashboard();
  const family = familyFromDashboard(dashboard.data);
  const pets = joinablePets(family);

  return (
    <ScrollView contentContainerStyle={styles.content}>
      <Text style={styles.stepTitle}>{S.petTitle(childName)}</Text>

      <PetOption
        icon={<PawPrint color={palette.graphite} size={22} />}
        title={severalSpecies ? S.newPetAny : S.newPet}
        hint={severalSpecies ? S.newPetAnyHint : S.newPetHint}
        onPress={() => onChoose(null)}
        testID="pet-option-new"
      />

      {dashboard.isPending ? (
        <View style={styles.waitingRow}>
          <ActivityIndicator size="small" color={palette.n500} />
          <Text style={styles.muted}>{S.petsLoading}</Text>
        </View>
      ) : (
        family &&
        pets.map((pet) => (
          <PetOption
            key={pet.id}
            icon={<Dog color={palette.graphite} size={22} />}
            title={S.joinPet(breedLabel(pet.breed_type, pet.species))}
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
      <ChevronRight color={palette.n500} size={20} />
    </Pressable>
  );
}

/** Picker choices the server refused (back to the picker with an explanation). */
type ProfileRejection =
  | 'breed_locked'
  | 'invalid_profile'
  | 'challenge_requires_paid_breed'
  | 'breed_species_mismatch'
  | 'species_unavailable';

const PROFILE_REJECTIONS: readonly ProfileRejection[] = [
  'breed_locked',
  'invalid_profile',
  'challenge_requires_paid_breed',
  'breed_species_mismatch',
  'species_unavailable',
];

function profileRejectionOf(kind: string | undefined): ProfileRejection | null {
  return PROFILE_REJECTIONS.find((k) => k === kind) ?? null;
}

// ── Step 3: PIN with countdown, polling until the child is connected ──
interface PinStepProps {
  target: TargetChild;
  joinPetId: number | null;
  /** New pet (M5-R04): the full picker choice; null when joining a pet or re-logging in. */
  profile: NewPetProfile | null;
  /** The server refused the picker choice → let the parent choose again. */
  onProfileRejected: (kind: ProfileRejection) => void;
  /** New pet only: back to the picker before the child connects ("Spremeni kužka"). */
  onChangeDog: () => void;
  /** Last PIN issued in this flow (kept by the parent screen across picker round trips). */
  issued: IssuedPin | null;
  onIssued: (issued: IssuedPin) => void;
  onDone: () => void;
}

function PinStep({ target, joinPetId, profile, issued, onIssued, onProfileRejected, onChangeDog, onDone }: PinStepProps) {
  const generate = useGeneratePin();
  const [cooldownUntil, setCooldownUntil] = useState<string | null>(null);
  // Last PIN the server issued (state in AddChildScreen). Kept across a failed "Nova koda" (e.g. 429)
  // and across "Spremeni kužka": the server only replaces the PIN on success, so the old one is still valid.
  const pin = issued?.response ?? null;
  const requestKey = pinRequestKey(joinPetId, profile);
  /** The shown PIN was issued for another choice (the new one has no PIN yet). */
  const isForPreviousChoice = issued !== null && issued.key !== requestKey;
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

  const request = () =>
    generate.mutate(
      { child_id: target.id, pet_id: joinPetId, ...(profile ? { profile } : {}) },
      { onSuccess: (response) => onIssued({ response, key: requestKey }) },
    );

  // First PIN as soon as the step opens (the ref guards against a double effect run) — unless a
  // still-valid PIN for exactly this choice exists (parent opened the picker and kept the choice).
  useEffect(() => {
    if (startedRef.current) return;
    startedRef.current = true;
    if (issued !== null && issued.key === requestKey && secondsUntil(issued.response.expires_at) > 0) return;
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

  // The free breed (mutt / domestic cat) refused as locked = our misconfiguration: explain,
  // never send the parent round in circles.
  const freeBreedLocked = pinError?.kind === 'breed_locked' && profile?.plan === 'free';
  const profileRejected = (pin === null || isForPreviousChoice) && !freeBreedLocked ? profileRejectionOf(pinError?.kind) : null;

  const isCoolingDown = cooldown > 0;
  const canRequest = !generate.isPending && !isCoolingDown;
  const requestNewPin = () => {
    if (canRequest) request();
  };

  const errorText = (() => {
    if (!pinError) return null;
    if (freeBreedLocked) return profile?.species === 'cat' ? S.freeCatLocked : S.muttLocked;
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
          <CheckCircle color={palette.ok} size={44} />
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
          <KeyRound color={palette.graphite} size={22} />
        </View>
        <Text style={styles.pinLabel}>{S.pinLabel}</Text>
        <Text style={styles.pinFor}>{S.pinFor(target.name)}</Text>

        {pin === null && generate.isPending ? (
          <View style={styles.pinPlaceholder}>
            <ActivityIndicator color={palette.graphite} />
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
            {/* M3-13 (David 2026-10-08): no free trial — the challenge starts with a purchase. */}
            {(pin as { plan?: string | null }).plan === 'challenge' && (
              <Text style={styles.muted} testID="pin-buy-first">
                {t('pet:picker.buyFirst', { name: target.name })}
              </Text>
            )}
          </>
        ) : null}

        {isForPreviousChoice && !isExpired && (
          <Text style={styles.note} testID="pin-previous-choice">
            {S.previousChoicePin}
          </Text>
        )}
        {errorText && (
          <View style={styles.errorBox} testID="pin-error">
            <Text style={styles.errorText}>{errorText}</Text>
          </View>
        )}
        {profileRejected !== null && (
          <Pressable
            style={({ pressed }) => [styles.secondaryButton, pressed && styles.pressed]}
            onPress={() => onProfileRejected(profileRejected)}
            accessibilityRole="button"
            testID="pin-change-dog"
          >
            <Text style={styles.secondaryButtonText}>{S.changeDog}</Text>
          </Pressable>
        )}

        {profileRejected === null && (
          <Pressable
            style={({ pressed }) => [styles.secondaryButton, !canRequest && styles.buttonDisabled, pressed && styles.pressed]}
            onPress={requestNewPin}
            disabled={!canRequest}
            accessibilityRole="button"
            accessibilityState={{ disabled: !canRequest }}
          >
            {generate.isPending && pin !== null ? (
              <ActivityIndicator color={palette.graphite} />
            ) : (
              <>
                <RefreshCw color={palette.graphite} size={16} />
                <Text style={styles.secondaryButtonText}>{pin === null && pinError ? S.retry : S.newCode}</Text>
              </>
            )}
          </Pressable>
        )}

        {pin !== null && !isExpired && (
          <View style={styles.waitingRow}>
            <ActivityIndicator size="small" color={palette.n500} />
            <Text style={styles.muted}>{S.waiting}</Text>
          </View>
        )}
      </View>

      <View style={styles.card}>
        <View style={styles.instructionsHeader}>
          <Smartphone color={palette.graphite} size={20} />
          <Text style={styles.body}>{S.instructions}</Text>
        </View>
        {S.steps[mode].map((text, index) => (
          <View key={text} style={styles.stepRow}>
            <Text style={styles.stepNumber}>{index + 1}</Text>
            <Text style={styles.stepText}>{text}</Text>
          </View>
        ))}
        {mode === 'relogin' && <Text style={styles.note}>{S.reloginNote}</Text>}
        {profile !== null && pin !== null && (
          <Pressable
            onPress={onChangeDog}
            hitSlop={8}
            accessibilityRole="link"
            accessibilityHint={S.editDogHint}
            style={({ pressed }) => [styles.linkButton, pressed && styles.pressed]}
            testID="pin-edit-dog"
          >
            <Text style={styles.linkText}>{S.editDog}</Text>
          </Pressable>
        )}
        <Text style={styles.note}>{S.oneTime}</Text>
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: palette.fog },
  flex: { flex: 1 },
  header: {
    paddingTop: Platform.OS === 'ios' ? 56 : 40,
    paddingHorizontal: 16,
    paddingBottom: 14,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    backgroundColor: palette.white,
    borderBottomWidth: 1,
    borderBottomColor: palette.n200,
  },
  headerTitle: { fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.display, color: palette.graphite },
  content: { padding: 16, gap: 16, paddingBottom: 32 },
  card: {
    backgroundColor: palette.white,
    borderRadius: 20,
    padding: 20,
    borderWidth: 1,
    borderColor: palette.n200,
    alignItems: 'center',
    gap: 10,
  },
  formCard: {
    backgroundColor: palette.white,
    borderRadius: 20,
    padding: 20,
    borderWidth: 1,
    borderColor: palette.n200,
    gap: 8,
  },
  fieldLabel: { marginTop: 6, fontSize: 13, fontWeight: '700', color: palette.n700 },
  input: {
    height: 50,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: palette.n300,
    backgroundColor: palette.fog,
    paddingHorizontal: 14,
    fontSize: 16,
    color: palette.graphite,
  },
  privacyBox: {
    marginTop: 10,
    flexDirection: 'row',
    gap: 10,
    padding: 12,
    borderRadius: 14,
    backgroundColor: palette.okSoft,
    borderWidth: 1,
    borderColor: palette.okBorder,
  },
  privacyTitle: { fontSize: 14, fontWeight: '700', color: palette.mintDeep },
  privacyBody: { marginTop: 2, fontSize: 13, lineHeight: 18, color: palette.ok },
  stepTitle: { fontSize: 18, letterSpacing: tightTracking(18), fontFamily: fonts.displayBold, color: palette.graphite },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    padding: 16,
    borderRadius: 18,
    backgroundColor: palette.white,
    borderWidth: 1,
    borderColor: palette.mintBorder,
  },
  optionTitle: { fontSize: 16, fontWeight: '700', color: palette.graphite },
  optionHint: { marginTop: 2, fontSize: 13, lineHeight: 18, color: palette.n600 },
  iconBadge: {
    width: 44,
    height: 44,
    borderRadius: 14,
    backgroundColor: palette.mintSoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  pinLabel: { fontSize: 13, fontWeight: '700', letterSpacing: 0.6, color: palette.n600, textTransform: 'uppercase' },
  pinFor: { fontSize: 15, fontWeight: '600', color: palette.n700 },
  pinPlaceholder: { height: 72, alignItems: 'center', justifyContent: 'center', gap: 8 },
  pin: { fontSize: 52, fontWeight: '800', letterSpacing: 4, color: palette.graphite, fontVariant: ['tabular-nums'] },
  pinExpired: { color: palette.n300, textDecorationLine: 'line-through' },
  countdown: { fontSize: 15, fontWeight: '600', color: palette.ok, fontVariant: ['tabular-nums'] },
  expired: { fontSize: 15, fontWeight: '600', color: palette.danger },
  errorBox: {
    alignSelf: 'stretch',
    marginTop: 6,
    padding: 12,
    borderRadius: 12,
    backgroundColor: palette.dangerSoft,
    borderWidth: 1,
    borderColor: palette.dangerBorder,
  },
  errorText: { fontSize: 13, color: palette.danger, textAlign: 'center' },
  secondaryButton: {
    alignSelf: 'stretch',
    height: 48,
    marginTop: 6,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: palette.mintBorder,
    backgroundColor: palette.mintSoft,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
  },
  secondaryButtonText: { fontSize: 15, fontWeight: '700', color: palette.mintDeep },
  buttonDisabled: { opacity: 0.5 },
  waitingRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 4 },
  muted: { fontSize: 13, color: palette.n600 },
  instructionsHeader: { flexDirection: 'row', alignItems: 'center', gap: 10, alignSelf: 'stretch' },
  body: { flex: 1, fontSize: 14, lineHeight: 20, color: palette.n700 },
  bodyCentered: { fontSize: 14, lineHeight: 20, color: palette.n700, textAlign: 'center' },
  stepRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, alignSelf: 'stretch' },
  stepNumber: {
    width: 22,
    height: 22,
    borderRadius: 11,
    overflow: 'hidden',
    backgroundColor: palette.mintSoft,
    color: palette.mintDeep,
    fontSize: 12,
    fontWeight: '800',
    textAlign: 'center',
    lineHeight: 22,
  },
  stepText: { flex: 1, fontSize: 14, lineHeight: 20, color: palette.n700 },
  note: { alignSelf: 'stretch', fontSize: 12, color: palette.n500 },
  linkButton: { alignSelf: 'flex-start', paddingVertical: 4 },
  linkText: { fontSize: 14, fontWeight: '700', color: palette.mintDeep, textDecorationLine: 'underline' },
  pairedTitle: { fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.display, color: palette.graphite },
  primaryButton: {
    alignSelf: 'stretch',
    height: 50,
    marginTop: 12,
    borderRadius: 14,
    backgroundColor: palette.graphite,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryButtonText: { fontSize: 16, fontWeight: '700', color: palette.white },
  pressed: { opacity: 0.85 },
});
