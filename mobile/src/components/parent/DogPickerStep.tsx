/**
 * "Izberi kužka" (M5-R04): plan (M3-09: free mutt sandbox or the 12-week challenge with a
 * 7-day trial — PAYMENTS_SPEC), breed, origin and age at arrival of a new pet, before the
 * child's PIN (REALISM_SPEC §1). Premium breeds need the challenge plan (server 422
 * `breed_locked` otherwise); the challenge needs a premium breed — the mutt is greyed out
 * while it is chosen (M5-F03, server 422 `challenge_requires_paid_breed`). Plan, origin and age have no default — the parent reads the
 * description of each and chooses. Price shown honestly: from the store when loaded, else
 * the list price; no automatic charge. Light parent theme (ADR-007).
 */

import { useState } from 'react';
import { Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Check, Lock } from 'lucide-react-native';

import type { NewPetProfile, PetBreed } from '@/api/client';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import {
  breedLockReason,
  CHALLENGE_LIST_PRICE,
  choiceWithPlan,
  completeChoice,
  isBreedLocked,
  lockedBreedsFor,
  PICKER_AGES,
  PICKER_BREEDS,
  PICKER_ORIGINS,
  PICKER_PLANS,
  PICKER_STRINGS as S,
  type PickerChoice,
} from '@/modules/petProfile/picker';
import { fonts, palette, tightTracking } from '@/theme';

interface DogPickerStepProps {
  childName: string;
  /** The choice to start from (kept when the parent comes back from the PIN). */
  initial: PickerChoice;
  /** Breeds the server refused for this choice (422 `breed_locked`), on top of the plan rules. */
  serverLockedBreeds?: readonly PetBreed[];
  /** Store price of the challenge ("49,99 €"), null while unknown → list price. */
  challengePrice?: string | null;
  /** A message to show on top (e.g. the server refused the last choice). */
  notice?: string | null;
  onConfirm: (profile: NewPetProfile, choice: PickerChoice) => void;
  /** "Nazaj" → the previous step (new pet / join a pet); the current choice is handed back to keep it. */
  onBack?: (choice: PickerChoice) => void;
}

export default function DogPickerStep({
  childName,
  initial,
  serverLockedBreeds = [],
  challengePrice = null,
  notice = null,
  onConfirm,
  onBack,
}: DogPickerStepProps) {
  const [choice, setChoice] = useState<PickerChoice>(() => {
    if (!isBreedLocked(initial.breed, lockedBreedsFor(initial.plan, serverLockedBreeds))) return initial;
    return initial.plan === null ? { ...initial, breed: 'mutt' } : choiceWithPlan(initial, initial.plan, serverLockedBreeds);
  });
  /** The locked breed the parent tapped last (explains why), null when none. */
  const [lockedTapped, setLockedTapped] = useState<PetBreed | null>(null);
  const lockedBreeds = lockedBreedsFor(choice.plan, serverLockedBreeds);
  // Every breed of this plan is locked (e.g. the server refused the only paid breed): the
  // way out is the free plan — say so instead of "choose a breed" (M5-F03).
  const noOpenBreed = PICKER_BREEDS.every((b) => isBreedLocked(b, lockedBreeds));
  const profile = completeChoice(choice, serverLockedBreeds);
  const price = challengePrice ?? CHALLENGE_LIST_PRICE;

  return (
    <ScrollView contentContainerStyle={styles.content} testID="dog-picker">
      <Text style={styles.title} accessibilityRole="header">
        {S.title(childName)}
      </Text>
      <Text style={styles.intro}>{S.intro}</Text>

      {notice && (
        <View style={styles.noticeBox} testID="dog-picker-notice">
          <Text style={styles.noticeText}>{notice}</Text>
        </View>
      )}

      <Text style={styles.sectionTitle} accessibilityRole="header">
        {S.planTitle}
      </Text>
      {PICKER_PLANS.map((plan) => (
        <Option
          key={plan}
          title={plan === 'challenge' ? S.plans.challenge.title(price) : S.plans.free.title}
          hint={plan === 'challenge' ? S.plans.challenge.hint : S.plans.free.hint}
          selected={choice.plan === plan}
          onPress={() => {
            setLockedTapped(null);
            // Free plan = always the mutt (PAYMENTS_SPEC §2); the challenge never keeps the mutt (M5-F03).
            setChoice((c) => choiceWithPlan(c, plan, serverLockedBreeds));
          }}
          testID={`plan-option-${plan}`}
        />
      ))}

      <Text style={styles.sectionTitle} accessibilityRole="header">
        {S.breedTitle}
      </Text>
      {PICKER_BREEDS.map((breed) => {
        const locked = isBreedLocked(breed, lockedBreeds);
        return (
          <Option
            key={breed}
            title={S.breeds[breed]}
            hint={S.breedHints[breed]}
            selected={!locked && choice.breed === breed}
            locked={locked}
            accessibilityLabel={
              !locked
                ? S.breeds[breed]
                : breedLockReason(breed, choice.plan, serverLockedBreeds) === 'free_only'
                  ? S.lockedFreeOnlyA11y(S.breeds[breed])
                  : S.lockedA11y(S.breeds[breed])
            }
            onPress={() => {
              if (locked) {
                setLockedTapped(breed);
                return;
              }
              setLockedTapped(null);
              setChoice((c) => ({ ...c, breed }));
            }}
            testID={`breed-option-${breed}`}
          />
        );
      })}
      {lockedTapped !== null && (
        <Text style={styles.lockedNote} testID="breed-locked-note">
          {lockedNote(breedLockReason(lockedTapped, choice.plan, serverLockedBreeds))}
        </Text>
      )}

      <Text style={styles.sectionTitle} accessibilityRole="header">
        {S.originTitle}
      </Text>
      {PICKER_ORIGINS.map((origin) => (
        <Option
          key={origin}
          title={S.origins[origin]}
          hint={S.originHints[origin]}
          selected={choice.origin === origin}
          onPress={() => setChoice((c) => ({ ...c, origin }))}
          testID={`origin-option-${origin}`}
        />
      ))}

      <Text style={styles.sectionTitle} accessibilityRole="header">
        {S.ageTitle}
      </Text>
      {PICKER_AGES.map((age) => (
        <Option
          key={age}
          title={S.ages[age]}
          hint={S.ageHints[choice.breed][age]}
          selected={choice.age_stage === age}
          onPress={() => setChoice((c) => ({ ...c, age_stage: age }))}
          testID={`age-option-${age}`}
        />
      ))}
      <Text style={styles.note}>{S.quietHoursNote}</Text>

      {profile === null && (
        <Text style={styles.missing}>
          {choice.plan === null
            ? S.planMissing
            : noOpenBreed
              ? S.noPaidBreed
              : isBreedLocked(choice.breed, lockedBreeds)
                ? S.breedMissing
                : S.missing}
        </Text>
      )}
      <Pressable
        style={({ pressed }) => [styles.primaryButton, profile === null && styles.buttonDisabled, pressed && styles.pressed]}
        onPress={() => {
          if (profile) onConfirm(profile, choice);
        }}
        disabled={profile === null}
        accessibilityRole="button"
        accessibilityState={{ disabled: profile === null }}
        testID="dog-picker-confirm"
      >
        <Text style={styles.primaryButtonText}>{S.confirm}</Text>
      </Pressable>
      {onBack && (
        <Pressable
          style={({ pressed }) => [styles.secondaryButton, pressed && styles.pressed]}
          onPress={() => onBack(choice)}
          accessibilityRole="button"
          testID="dog-picker-back"
        >
          <Text style={styles.secondaryButtonText}>{S.back}</Text>
        </Pressable>
      )}
    </ScrollView>
  );
}

/** The note under the breeds after a tap on a locked one. */
function lockedNote(reason: ReturnType<typeof breedLockReason>): string {
  if (reason === 'free_only') return S.breedChallengeNote;
  if (reason === 'challenge_only') return S.breedFreeNote;
  return S.breedLockedNote;
}

interface OptionProps {
  title: string;
  hint: string;
  selected: boolean;
  locked?: boolean;
  accessibilityLabel?: string;
  onPress: () => void;
  testID: string;
}

function Option({ title, hint, selected, locked = false, accessibilityLabel, onPress, testID }: OptionProps) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.option, selected && styles.optionSelected, locked && styles.optionLocked, pressed && styles.pressed]}
      accessibilityRole="radio"
      accessibilityState={{ checked: selected, disabled: locked }}
      accessibilityLabel={accessibilityLabel ?? title}
      testID={testID}
    >
      <View style={[styles.radio, selected && styles.radioSelected]}>
        {locked ? <Lock color={C.faint} size={12} /> : selected ? <Check color={palette.white} size={12} /> : null}
      </View>
      <View style={styles.flex}>
        <Text style={[styles.optionTitle, locked && styles.lockedText]}>{title}</Text>
        <Text style={styles.optionHint}>{hint}</Text>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  content: { padding: 16, gap: 10, paddingBottom: 32 },
  title: { fontSize: 18, letterSpacing: tightTracking(18), fontFamily: fonts.displayBold, color: C.text },
  intro: { fontSize: 14, lineHeight: 20, color: C.muted },
  sectionTitle: { marginTop: 12, fontSize: 13, fontWeight: '700', color: palette.n700, textTransform: 'uppercase', letterSpacing: 0.4 },
  option: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 12,
    padding: 14,
    borderRadius: 16,
    backgroundColor: C.card,
    borderWidth: 1,
    borderColor: C.border,
  },
  optionSelected: { borderColor: C.accent, backgroundColor: C.accentSoft },
  optionLocked: { backgroundColor: C.bg },
  radio: {
    width: 22,
    height: 22,
    marginTop: 1,
    borderRadius: 11,
    borderWidth: 2,
    borderColor: palette.n300,
    alignItems: 'center',
    justifyContent: 'center',
  },
  radioSelected: { borderColor: C.accent, backgroundColor: C.accent },
  optionTitle: { fontSize: 16, fontWeight: '700', color: C.text },
  lockedText: { color: C.muted },
  optionHint: { marginTop: 2, fontSize: 13, lineHeight: 18, color: C.muted },
  lockedNote: { fontSize: 13, lineHeight: 18, color: C.yellowText },
  note: { fontSize: 12, lineHeight: 17, color: C.faint },
  noticeBox: { padding: 12, borderRadius: 12, backgroundColor: C.redSoft, borderWidth: 1, borderColor: palette.dangerBorder },
  noticeText: { fontSize: 13, color: C.redText },
  missing: { marginTop: 8, fontSize: 13, color: C.muted, textAlign: 'center' },
  primaryButton: {
    height: 50,
    marginTop: 4,
    borderRadius: 14,
    backgroundColor: C.accent,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryButtonText: { fontSize: 16, fontWeight: '700', color: palette.white },
  secondaryButton: {
    height: 48,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.card,
    alignItems: 'center',
    justifyContent: 'center',
  },
  secondaryButtonText: { fontSize: 15, fontWeight: '700', color: C.accent },
  buttonDisabled: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
});
