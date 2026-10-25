/**
 * Parent pet picker (M5-R04 "Izberi kužka" → M5-R06-02 species-aware, CAT_SPEC §10):
 *
 * 1. **Species** — big tiles "Pes" / "Mačka", no default. Only the species the server's
 *    catalogue offers are shown; with a single species (today: dogs only, cats hidden) the
 *    step is skipped and that species is used (DECISIONS 2026-10-08).
 * 2. **Plan** — free / 12-week challenge (M3-09 / M3-13: the challenge starts with a purchase).
 * 3. **Breed** — the server's list (`GET /api/breeds`) with search (case- and
 *    diacritic-insensitive, synonyms) over the whole catalogue; without a search only the
 *    first few breeds show; "Pokaži vse pasme (N)" opens the whole list in a bottom sheet with its
 *    own search (David 2026-10-12 / tester feedback 2026-10-25: never a long inline list) —
 *    closes with ×, a swipe down on its header, the backdrop or Android back; tapping a
 *    pickable breed selects it and closes the sheet. Free breed first with a "Brezplačno" badge, paid
 *    breeds with "Izziv"; a breed the plan doesn't take is greyed and explained on tap (M5-F03).
 *    Each breed with sourced tags shows "Primerno za:" / "Upoštevajte:" chips (M5-R10).
 *    A paid breed with colours (M5-R21) shows "Barva": chips when there are several (the first
 *    preselected, so it never blocks), a plain line when there is only one; none for a free breed.
 * 4. **Origin** ("Od kod prihaja") and **age at arrival**, as before (texts per species, T8). A
 *    non-pedigree breed (mutt, domestic cat) is adoption only — never "from a breeder" (David
 *    2026-10-12) — and that only origin is preselected (`withValidOrigin`, David 2026-10-25).
 *    The free plan shows a short "limited trial" notice under the plans ({@link freeTrialText}).
 *    Required sections carry the shared asterisk (`FieldLabel required`) + one legend.
 * 5. **Summary** (species, breed, origin, age, plan) above "Ustvari kodo".
 *
 * Premium breeds need the challenge (server 422 `breed_locked` otherwise); the challenge
 * needs a paid breed (422 `challenge_requires_paid_breed`). Plan, origin and age have no
 * default, and neither has the breed while no plan is chosen — tapping the free breed picks the
 * free plan, so a breed is never ticked without its plan. Price shown honestly: only the store's localized price ("…" while loading, no number without it); no
 * automatic charge. Light parent theme (ADR-007). Test IDs keep the M5-R04 names
 * (`dog-picker*`) so existing flows stay stable.
 */

import { useContext, useState } from 'react';
import { ActivityIndicator, Animated, FlatList, Modal, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { Text, TextInput } from '@/components/ui/Text';
import { FieldLabel, RequiredLegend } from '@/components/ui/RequiredLabel';
import { useSwipeToClose } from '@/components/ui/useSwipeToClose';
import { Cat, Check, Dog, Info, Lock, Search, X } from 'lucide-react-native';

import type { NewPetProfile, PetBreed, PetSpecies } from '@/api/client';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import {
  ageHint,
  BREED_PREVIEW_COUNT,
  breedHint,
  breedLockReason,
  breedsOf,
  choiceWithBreed,
  choiceWithOrigin,
  choiceWithPlan,
  choiceWithSpecies,
  colourLabel,
  coloursFor,
  completeChoice,
  considerLabel,
  freeBreedOf,
  freeTrialText,
  hasSuitability,
  isBreedLocked,
  lockedBreedsFor,
  originBreed,
  originsFor,
  PICKER_AGES,
  PICKER_PLANS,
  PICKER_STRINGS as S,
  pickerText,
  searchBreeds,
  SUITABILITY_STRINGS,
  suitabilityA11y,
  suitsLabel,
  type BreedCatalogue,
  type BreedColour,
  type BreedLockReason,
  type BreedSuitability,
  type CatalogueBreed,
  type PickerChoice,
  type PickerText,
  visibleBreeds,
  withConsistentPlan,
  withValidColour,
  withValidOrigin,
} from '@/modules/petProfile/picker';
import { breedName, foldForSearch, speciesName } from '@/modules/species/species';
import { MIN_TOUCH, alpha, fonts, palette, radius, tightTracking } from '@/theme';

interface PetPickerStepProps {
  childName: string;
  /** The server's catalogue (or the fallback dogs after a failure); null while loading. */
  catalogue: BreedCatalogue | null;
  /** The choice to start from (kept when the parent comes back from the PIN). */
  initial: PickerChoice;
  /** Breeds the server refused for this choice (422 `breed_locked`), on top of the plan rules. */
  serverLockedBreeds?: readonly PetBreed[];
  /** Store price of the challenge, localized by the store ("49,99 €"); null = unknown → no number. */
  challengePrice?: string | null;
  /** The store price is still loading (→ "…" instead of a number). */
  challengePriceLoading?: boolean;
  /** A message to show on top (e.g. the server refused the last choice). */
  notice?: string | null;
  onConfirm: (profile: NewPetProfile, choice: PickerChoice) => void;
  /** How many breeds the collapsed list shows (default {@link BREED_PREVIEW_COUNT}; tests list every breed inline). */
  previewCount?: number;
  /** "Nazaj" → the previous step (new pet / join a pet); the current choice is handed back to keep it. */
  onBack?: (choice: PickerChoice) => void;
}

export default function PetPickerStep(props: PetPickerStepProps) {
  if (props.catalogue === null) {
    return (
      <View style={styles.loading} testID="pet-picker-loading">
        <ActivityIndicator color={palette.graphite} />
        <Text style={styles.intro}>{S.loading}</Text>
        {props.onBack && (
          <Pressable
            style={({ pressed }) => [styles.secondaryButton, styles.stretch, pressed && styles.pressed]}
            onPress={() => props.onBack?.(props.initial)}
            accessibilityRole="button"
            testID="dog-picker-back"
          >
            <Text style={styles.secondaryButtonText}>{S.back}</Text>
          </Pressable>
        )}
      </View>
    );
  }
  // A changed species list (e.g. cats switched off between two loads) starts the picker afresh.
  return <PickerBody key={props.catalogue.species.join(',')} {...props} catalogue={props.catalogue} />;
}

/**
 * The choice to start from: a species the catalogue still offers (or its only one) and a valid
 * breed. Without a plan nothing is preselected — neither plan nor breed (the plan has no default,
 * and a ticked "Mešanček" next to two empty plan radios was the bug of 2026-10-12).
 */
function startChoice(initial: PickerChoice, catalogue: BreedCatalogue, serverLocked: readonly PetBreed[]): PickerChoice {
  const only = catalogue.species.length === 1 ? catalogue.species[0] : null;
  const species = initial.species !== null && catalogue.species.includes(initial.species) ? initial.species : only;
  if (species === null) return { ...initial, species: null, breed: null };
  const breeds = breedsOf(catalogue, species);
  const choice = { ...initial, species };
  const valid = choice.breed !== null && breeds.some((b) => b.breed === choice.breed);
  if (valid && !isBreedLocked(choice.breed, lockedBreedsFor(choice.plan, serverLocked, breeds))) return choice;
  if (choice.plan === null) return { ...choice, breed: null };
  return choiceWithPlan(choice, choice.plan, serverLocked, breeds);
}

function PickerBody({
  childName,
  catalogue,
  initial,
  serverLockedBreeds = [],
  challengePrice = null,
  challengePriceLoading = false,
  notice = null,
  onConfirm,
  onBack,
  previewCount = BREED_PREVIEW_COUNT,
}: PetPickerStepProps & { catalogue: BreedCatalogue }) {
  /**
   * Every choice goes through the plan ↔ breed rule (never a breed without its plan), the
   * origin rule (a mutt can't stay "bought from a breeder") and the colour rule (M5-R21: only a
   * colour of the chosen paid breed, the first one preselected).
   */
  const normalize = (c: PickerChoice) => withValidColour(withValidOrigin(withConsistentPlan(c), catalogue.breeds), catalogue.breeds);
  const [choice, setChoice] = useState<PickerChoice>(() => normalize(startChoice(initial, catalogue, serverLockedBreeds)));
  const update = (change: (c: PickerChoice) => PickerChoice) => setChoice((c) => normalize(change(c)));
  const [stage, setStage] = useState<'species' | 'details'>(() => (choice.species === null ? 'species' : 'details'));
  /** The locked breed the parent tapped last (explains why), null when none. */
  const [lockedTapped, setLockedTapped] = useState<PetBreed | null>(null);
  const [query, setQuery] = useState('');
  /** "Pokaži vse pasme" sheet open (closes on a pick, ×, swipe, back or a species change). */
  const [sheetOpen, setSheetOpen] = useState(false);
  const severalSpecies = catalogue.species.length > 1;

  if (stage === 'species' || choice.species === null) {
    return (
      <SpeciesStep
        childName={childName}
        species={catalogue.species}
        selected={choice.species}
        notice={notice}
        onPick={(species) => {
          setLockedTapped(null);
          setQuery('');
          setSheetOpen(false);
          update((c) => choiceWithSpecies(c, species, serverLockedBreeds, breedsOf(catalogue, species)));
          setStage('details');
        }}
        onBack={onBack ? () => onBack(choice) : undefined}
      />
    );
  }

  const species = choice.species;
  const T = pickerText(species);
  const breeds = breedsOf(catalogue, species);
  const lockedBreeds = lockedBreedsFor(choice.plan, serverLockedBreeds, breeds);
  // Every breed of this plan is locked (e.g. the server refused the only paid breed): the
  // way out is the free plan — say so instead of "choose a breed" (M5-F03).
  const noOpenBreed = breeds.every((b) => isBreedLocked(b.breed, lockedBreeds));
  const profile = completeChoice(choice, serverLockedBreeds, breeds);
  const challengeTitle = S.plans.challenge.title(challengePrice, challengePriceLoading);
  const shown = searchBreeds(breeds, query);
  const listed = visibleBreeds(shown, query, false, choice.breed, previewCount);
  // "Pokaži vse pasme" only without a search (a search always lists every match inline).
  const canToggleAll = foldForSearch(query) === '' && shown.length > previewCount;
  const origins = originsFor(choice, breeds);
  const adoptionOnlyBreed = origins.length === 1 ? originBreed(choice, breeds) : null;
  const ageBreed = choice.breed ?? freeBreedOf(breeds);
  const colours = coloursFor(choice, breeds);

  return (
    <ScrollView contentContainerStyle={styles.content} testID="dog-picker" keyboardShouldPersistTaps="handled">
      <Text style={styles.title} accessibilityRole="header">
        {T.title(childName)}
      </Text>
      <Text style={styles.intro}>{T.intro}</Text>
      <RequiredLegend />
      {severalSpecies && (
        <Pressable
          onPress={() => setStage('species')}
          hitSlop={8}
          accessibilityRole="link"
          style={({ pressed }) => [styles.linkButton, pressed && styles.pressed]}
          testID="picker-change-species"
        >
          <Text style={styles.linkText}>
            {speciesName(species)} · {S.changeSpecies}
          </Text>
        </Pressable>
      )}

      {notice && (
        <View style={styles.noticeBox} testID="dog-picker-notice">
          <Text style={styles.noticeText}>{notice}</Text>
        </View>
      )}

      <FieldLabel required style={styles.sectionTitleText} containerStyle={styles.sectionTitleRow} accessibilityRole="header" testID="plan-title">
        {S.planTitle}
      </FieldLabel>
      {PICKER_PLANS.map((plan) => (
        <Option
          key={plan}
          title={plan === 'challenge' ? challengeTitle : T.freePlanTitle}
          hint={plan === 'challenge' ? T.challengePlanHint : T.freePlanHint}
          selected={choice.plan === plan}
          onPress={() => {
            setLockedTapped(null);
            // Free plan = always the species' free breed; the challenge never keeps it (M5-F03).
            update((c) => choiceWithPlan(c, plan, serverLockedBreeds, breeds));
          }}
          testID={`plan-option-${plan}`}
        />
      ))}
      {choice.plan === 'free' && <FreeTrialNotice species={species} />}

      <FieldLabel required style={styles.sectionTitleText} containerStyle={styles.sectionTitleRow} accessibilityRole="header" testID="breed-title">
        {S.breedTitle}
      </FieldLabel>
      <View style={styles.searchBox}>
        <Search color={C.faint} size={16} />
        <TextInput
          style={styles.searchInput}
          value={query}
          onChangeText={setQuery}
          placeholder={S.searchPlaceholder}
          placeholderTextColor={palette.n500}
          autoCorrect={false}
          autoCapitalize="none"
          autoComplete="off"
          accessibilityLabel={S.searchLabel}
          testID="breed-search"
        />
      </View>
      {listed.map((entry) => (
        <BreedRow
          key={entry.breed}
          entry={entry}
          locked={isBreedLocked(entry.breed, lockedBreeds)}
          lockReason={breedLockReason(entry.breed, choice.plan, serverLockedBreeds, breeds)}
          selected={choice.breed === entry.breed}
          onPress={(locked) => {
            if (locked) {
              setLockedTapped(entry.breed);
              return;
            }
            setLockedTapped(null);
            // Before a plan, the breed picks it (free breed → free plan, M5-F03).
            update((c) => choiceWithBreed(c, entry.breed, breeds));
          }}
        />
      ))}
      {canToggleAll && (
        <Pressable
          onPress={() => {
            setLockedTapped(null);
            setSheetOpen(true);
          }}
          hitSlop={8}
          accessibilityRole="button"
          accessibilityLabel={S.showAllBreeds(shown.length)}
          accessibilityState={{ expanded: sheetOpen }}
          style={({ pressed }) => [styles.showAllButton, pressed && styles.pressed]}
          testID="breed-show-all"
        >
          <Text style={styles.linkText}>{S.showAllBreeds(shown.length)}</Text>
        </Pressable>
      )}
      <BreedListSheet
        visible={sheetOpen}
        breeds={breeds}
        lockedBreeds={lockedBreeds}
        lockReasonOf={(breed) => breedLockReason(breed, choice.plan, serverLockedBreeds, breeds)}
        lockedNoteOf={(breed) => lockedNote(breedLockReason(breed, choice.plan, serverLockedBreeds, breeds), T)}
        selected={choice.breed}
        onPick={(breed) => {
          setLockedTapped(null);
          update((c) => choiceWithBreed(c, breed, breeds));
          setSheetOpen(false);
        }}
        onClose={() => setSheetOpen(false)}
      />
      {shown.length === 0 && (
        <Text style={styles.note} testID="breed-search-empty">
          {S.searchEmpty(query.trim())}
        </Text>
      )}
      {lockedTapped !== null && (
        <Text style={styles.lockedNote} testID="breed-locked-note">
          {lockedNote(breedLockReason(lockedTapped, choice.plan, serverLockedBreeds, breeds), T)}
        </Text>
      )}

      {colours.length > 0 && (
        <ColourChoice colours={colours} selected={choice.colour ?? null} onPick={(colour) => update((c) => ({ ...c, colour }))} />
      )}

      <FieldLabel required style={styles.sectionTitleText} containerStyle={styles.sectionTitleRow} accessibilityRole="header" testID="origin-title">
        {S.originTitle}
      </FieldLabel>
      {origins.map((origin) => (
        <Option
          key={origin}
          title={T.origins[origin]}
          hint={T.originHints[origin]}
          selected={choice.origin === origin}
          onPress={() => update((c) => choiceWithOrigin(c, origin))}
          testID={`origin-option-${origin}`}
        />
      ))}
      {adoptionOnlyBreed !== null && (
        <Text style={styles.note} testID="origin-adoption-only">
          {S.originAdoptionOnly(breedName(adoptionOnlyBreed, species))}
        </Text>
      )}

      <FieldLabel required style={styles.sectionTitleText} containerStyle={styles.sectionTitleRow} accessibilityRole="header" testID="age-title">
        {S.ageTitle}
      </FieldLabel>
      {PICKER_AGES.map((age) => (
        <Option
          key={age}
          title={T.ages[age]}
          hint={ageBreed ? ageHint(ageBreed, age) : ''}
          selected={choice.age_stage === age}
          onPress={() => update((c) => ({ ...c, age_stage: age }))}
          testID={`age-option-${age}`}
        />
      ))}
      <Text style={styles.note}>{S.quietHoursNote}</Text>

      {profile !== null ? (
        <Summary profile={profile} text={T} colour={colourLabel(profile, breeds)} />
      ) : (
        <Text style={styles.missing}>
          {choice.plan === null
            ? S.planMissing
            : noOpenBreed
              ? T.noPaidBreed
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
      {(onBack || severalSpecies) && (
        <Pressable
          style={({ pressed }) => [styles.secondaryButton, pressed && styles.pressed]}
          onPress={() => (severalSpecies ? setStage('species') : onBack?.(choice))}
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
function lockedNote(reason: BreedLockReason | null, text: PickerText): string {
  if (reason === 'free_only') return text.breedChallengeNote;
  if (reason === 'challenge_only') return text.breedFreeNote;
  return text.breedLockedNote;
}

// ── Free plan: "limited trial" notice (David 2026-10-25) ──────────
/** What the free pet lacks vs. a challenge breed — facts only, see {@link freeTrialText}. */
function FreeTrialNotice({ species }: { species: PetSpecies }) {
  const text = freeTrialText(species);
  return (
    <View style={styles.trialBox} testID="free-trial-notice" accessible accessibilityLabel={[text.title, text.intro, ...text.items, text.cta].join(' ')}>
      <View style={styles.trialTitleRow}>
        <Info color={C.link} size={16} />
        <Text style={styles.trialTitle}>{text.title}</Text>
      </View>
      <Text style={styles.trialBody}>{text.intro}</Text>
      {text.items.map((item) => (
        <View key={item} style={styles.trialItem}>
          <Text style={styles.trialBullet}>•</Text>
          <Text style={[styles.trialBody, styles.flexShrink]}>{item}</Text>
        </View>
      ))}
      <Text style={styles.trialCta}>{text.cta}</Text>
    </View>
  );
}

// ── "Pokaži vse pasme": the whole list in a bottom sheet ──────────
interface BreedListSheetProps {
  visible: boolean;
  breeds: readonly CatalogueBreed[];
  lockedBreeds: readonly PetBreed[];
  lockReasonOf: (breed: PetBreed) => BreedLockReason | null;
  lockedNoteOf: (breed: PetBreed) => string;
  selected: PetBreed | null;
  onPick: (breed: PetBreed) => void;
  onClose: () => void;
}

/**
 * Tester feedback (David 2026-10-25): the full catalogue never expands inline (endless scroll
 * to its end or back to the top) — it opens here, with its own search, and closes any time
 * with ×, a swipe down on the header, the backdrop or Android back. A pickable breed selects
 * and closes; a locked one explains itself in the sheet.
 */
function BreedListSheet({ visible, onClose, ...rest }: BreedListSheetProps) {
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      {visible && <BreedListSheetBody onClose={onClose} {...rest} />}
    </Modal>
  );
}

function BreedListSheetBody({ breeds, lockedBreeds, lockReasonOf, lockedNoteOf, selected, onPick, onClose }: Omit<BreedListSheetProps, 'visible'>) {
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;
  const topInset = useContext(SafeAreaInsetsContext)?.top ?? 0;
  const swipe = useSwipeToClose(onClose);
  const [query, setQuery] = useState('');
  const [lockedTapped, setLockedTapped] = useState<PetBreed | null>(null);
  const shown = searchBreeds(breeds, query);
  return (
    <View style={[styles.sheetBackdrop, { paddingTop: topInset + 24 }]}>
      <Pressable
        style={StyleSheet.absoluteFill}
        onPress={onClose}
        accessible={false}
        importantForAccessibility="no"
        testID="breed-sheet-backdrop"
      />
      <Animated.View
        style={[styles.sheet, { paddingBottom: 12 + bottomInset, transform: [{ translateY: swipe.dragY }] }]}
        accessibilityViewIsModal
        testID="breed-sheet"
      >
        <View {...swipe.panHandlers} style={styles.sheetHeader} testID="breed-sheet-header">
          <View style={styles.handle} accessible={false} importantForAccessibility="no" />
          <View style={styles.sheetTitleRow}>
            <Text style={styles.sheetTitle} accessibilityRole="header">
              {S.allBreedsTitle} ({shown.length})
            </Text>
            <Pressable
              onPress={onClose}
              hitSlop={8}
              accessibilityRole="button"
              accessibilityLabel={S.allBreedsClose}
              style={({ pressed }) => [styles.sheetClose, pressed && styles.pressed]}
              testID="breed-sheet-close"
            >
              <X color={C.text} size={20} />
            </Pressable>
          </View>
          <View style={styles.searchBox}>
            <Search color={C.faint} size={16} />
            <TextInput
              style={styles.searchInput}
              value={query}
              onChangeText={setQuery}
              placeholder={S.searchPlaceholder}
              placeholderTextColor={palette.n500}
              autoCorrect={false}
              autoCapitalize="none"
              autoComplete="off"
              accessibilityLabel={S.searchLabel}
              testID="breed-sheet-search"
            />
          </View>
          {lockedTapped !== null && (
            <Text style={styles.lockedNote} testID="breed-sheet-locked-note">
              {lockedNoteOf(lockedTapped)}
            </Text>
          )}
        </View>
        <FlatList
          data={shown}
          keyExtractor={(entry) => entry.breed}
          keyboardShouldPersistTaps="handled"
          contentContainerStyle={styles.sheetList}
          initialNumToRender={12}
          testID="breed-sheet-list"
          ListEmptyComponent={
            <Text style={styles.note} testID="breed-sheet-empty">
              {S.searchEmpty(query.trim())}
            </Text>
          }
          renderItem={({ item }) => (
            <BreedRow
              entry={item}
              locked={isBreedLocked(item.breed, lockedBreeds)}
              lockReason={lockReasonOf(item.breed)}
              selected={selected === item.breed}
              testIDPrefix="breed-sheet-option"
              onPress={(locked) => {
                if (locked) {
                  setLockedTapped(item.breed);
                  return;
                }
                onPick(item.breed);
              }}
            />
          )}
        />
      </Animated.View>
    </View>
  );
}

// ── Step 1: species tiles ─────────────────────────────────────────
interface SpeciesStepProps {
  childName: string;
  species: readonly PetSpecies[];
  selected: PetSpecies | null;
  notice: string | null;
  onPick: (species: PetSpecies) => void;
  onBack?: () => void;
}

function SpeciesStep({ childName, species, selected, notice, onPick, onBack }: SpeciesStepProps) {
  return (
    <ScrollView contentContainerStyle={styles.content} testID="species-picker">
      <Text style={styles.title} accessibilityRole="header">
        {S.speciesTitle(childName)}
      </Text>
      <Text style={styles.intro}>{S.speciesIntro}</Text>
      {notice && (
        <View style={styles.noticeBox} testID="dog-picker-notice">
          <Text style={styles.noticeText}>{notice}</Text>
        </View>
      )}
      <View style={styles.tiles}>
        {species.map((s) => {
          const isSelected = selected === s;
          return (
            <Pressable
              key={s}
              onPress={() => onPick(s)}
              style={({ pressed }) => [styles.tile, isSelected && styles.optionSelected, pressed && styles.pressed]}
              accessibilityRole="radio"
              accessibilityState={{ checked: isSelected }}
              accessibilityLabel={speciesName(s)}
              testID={`species-option-${s}`}
            >
              <View style={styles.tileIcon}>
                {s === 'cat' ? <Cat color={palette.graphite} size={36} /> : <Dog color={palette.graphite} size={36} />}
              </View>
              <Text style={styles.tileTitle}>{speciesName(s)}</Text>
              <Text style={styles.tileHint}>{S.speciesHints[s]}</Text>
            </Pressable>
          );
        })}
      </View>
      {onBack && (
        <Pressable
          style={({ pressed }) => [styles.secondaryButton, pressed && styles.pressed]}
          onPress={onBack}
          accessibilityRole="button"
          testID="dog-picker-back"
        >
          <Text style={styles.secondaryButtonText}>{S.back}</Text>
        </Pressable>
      )}
    </ScrollView>
  );
}

// ── Breed row: icon + name + hint + badge ─────────────────────────
interface BreedRowProps {
  entry: CatalogueBreed;
  locked: boolean;
  lockReason: BreedLockReason | null;
  selected: boolean;
  onPress: (locked: boolean) => void;
  /** Test id prefix (`breed-option` inline, `breed-sheet-option` in the sheet). */
  testIDPrefix?: string;
}

function BreedRow({ entry, locked, lockReason, selected, onPress, testIDPrefix = 'breed-option' }: BreedRowProps) {
  const name = breedName(entry.breed, entry.species);
  const checked = !locked && selected;
  const a11y = !locked ? name : lockReason === 'free_only' ? S.lockedFreeOnlyA11y(name) : S.lockedA11y(name);
  const free = entry.free_plan_allowed && !entry.premium;
  const tagged = hasSuitability(entry.suitability);
  return (
    <Pressable
      onPress={() => onPress(locked)}
      style={({ pressed }) => [styles.option, checked && styles.optionSelected, locked && styles.optionLocked, pressed && styles.pressed]}
      accessibilityRole="radio"
      accessibilityState={{ checked, disabled: locked }}
      accessibilityLabel={a11y}
      accessibilityHint={tagged ? suitabilityA11y(entry.suitability) : undefined}
      testID={`${testIDPrefix}-${entry.breed}`}
    >
      <View style={[styles.breedIcon, locked && styles.breedIconLocked]}>
        {entry.species === 'cat' ? <Cat color={C.muted} size={20} /> : <Dog color={C.muted} size={20} />}
      </View>
      <View style={styles.flex}>
        <View style={styles.breedTitleRow}>
          <Text style={[styles.optionTitle, styles.flexShrink, locked && styles.lockedText]}>{name}</Text>
          <View style={[styles.badge, free ? styles.badgeFree : styles.badgeChallenge]}>
            <Text style={[styles.badgeText, free ? styles.badgeFreeText : styles.badgeChallengeText]}>
              {free ? S.badgeFree : S.badgeChallenge}
            </Text>
          </View>
        </View>
        <Text style={styles.optionHint}>{breedHint(entry.breed)}</Text>
        {tagged && <SuitabilityTags breed={entry.breed} suitability={entry.suitability} />}
      </View>
      <View style={[styles.radio, checked && styles.radioSelected]}>
        {locked ? <Lock color={C.faint} size={12} /> : checked ? <Check color={palette.white} size={12} /> : null}
      </View>
    </Pressable>
  );
}

// ── "Za koga je primerna" chips (M5-R10) ──────────────────────────
/**
 * Sourced suitability tags as small chips under the breed hint. The row is one accessible
 * element (its label overrides the children), so its a11y hint reads the same tags as one
 * sentence ({@link suitabilityA11y}).
 */
function SuitabilityTags({ breed, suitability }: { breed: PetBreed; suitability: BreedSuitability }) {
  const groups: Array<{ kind: 'suits' | 'consider'; title: string; labels: string[] }> = [
    { kind: 'suits', title: SUITABILITY_STRINGS.suitsTitle, labels: suitability.suits.map(suitsLabel) },
    { kind: 'consider', title: SUITABILITY_STRINGS.considerTitle, labels: suitability.consider.map(considerLabel) },
  ];
  return (
    <View style={styles.tags} testID={`breed-suitability-${breed}`}>
      {groups
        .filter((g) => g.labels.length > 0)
        .map((g) => (
          <View key={g.kind} style={styles.tagGroup} testID={`breed-suitability-${breed}-${g.kind}`}>
            <Text style={styles.tagTitle}>{g.title}</Text>
            {g.labels.map((label) => (
              <View key={label} style={[styles.chip, g.kind === 'suits' ? styles.chipSuits : styles.chipConsider]}>
                <Text style={[styles.chipText, g.kind === 'consider' && styles.chipConsiderText]}>{label}</Text>
              </View>
            ))}
          </View>
        ))}
    </View>
  );
}

// ── Coat colour of a paid breed (M5-R21) ──────────────────────────
/**
 * Several colours → a radio group of chips (brand light theme, 44 pt touch targets); a single
 * colour → one line, nothing to choose. Labels come from the server in the app language.
 */
function ColourChoice({
  colours,
  selected,
  onPick,
}: {
  colours: readonly BreedColour[];
  selected: string | null;
  onPick: (colour: string) => void;
}) {
  if (colours.length === 1) {
    return (
      <View style={styles.colourSection}>
        <Text style={styles.sectionTitle} accessibilityRole="header">
          {S.colourTitle}
        </Text>
        <Text style={styles.optionHint} testID="colour-only">
          {S.colourOnly(colours[0].label)}
        </Text>
      </View>
    );
  }
  return (
    <View style={styles.colourSection}>
      <FieldLabel required style={styles.sectionTitleText} containerStyle={styles.sectionTitleRow} accessibilityRole="header" testID="colour-title">
        {S.colourTitle}
      </FieldLabel>
      <Text style={styles.note}>{S.colourHint}</Text>
      <View style={styles.colourChips} accessibilityRole="radiogroup" accessibilityLabel={S.colourTitle} testID="colour-choice">
        {colours.map((colour) => {
          const checked = colour.key === selected;
          return (
            <Pressable
              key={colour.key}
              onPress={() => onPick(colour.key)}
              style={({ pressed }) => [styles.colourChip, checked && styles.colourChipSelected, pressed && styles.pressed]}
              accessibilityRole="radio"
              accessibilityState={{ checked }}
              accessibilityLabel={S.colourA11y(colour.label)}
              testID={`colour-option-${colour.key}`}
            >
              {checked && <Check color={palette.white} size={14} />}
              <Text style={[styles.colourChipText, checked && styles.colourChipTextSelected]}>{colour.label}</Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

// ── Summary before the PIN ────────────────────────────────────────
function Summary({ profile, text, colour }: { profile: NewPetProfile; text: PickerText; colour: string | null }) {
  const rows: Array<[string, string]> = [
    [S.summary.species, speciesName(profile.species)],
    [S.summary.breed, breedName(profile.breed, profile.species)],
    ...(colour !== null ? ([[S.summary.colour, colour]] as Array<[string, string]>) : []),
    [S.summary.origin, text.origins[profile.origin]],
    [S.summary.age, text.ages[profile.age_stage]],
    [S.summary.plan, S.planNames[profile.plan]],
  ];
  return (
    <View style={styles.summary} testID="picker-summary">
      <Text style={styles.summaryTitle} accessibilityRole="header">
        {S.summaryTitle}
      </Text>
      {rows.map(([label, value]) => (
        <View key={label} style={styles.summaryRow}>
          <Text style={styles.summaryLabel}>{label}</Text>
          <Text style={styles.summaryValue}>{value}</Text>
        </View>
      ))}
    </View>
  );
}

interface OptionProps {
  title: string;
  hint: string;
  selected: boolean;
  onPress: () => void;
  testID: string;
}

function Option({ title, hint, selected, onPress, testID }: OptionProps) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.option, selected && styles.optionSelected, pressed && styles.pressed]}
      accessibilityRole="radio"
      accessibilityState={{ checked: selected, disabled: false }}
      accessibilityLabel={title}
      testID={testID}
    >
      <View style={[styles.radio, selected && styles.radioSelected]}>
        {selected ? <Check color={palette.white} size={12} /> : null}
      </View>
      <View style={styles.flex}>
        <Text style={styles.optionTitle}>{title}</Text>
        {hint !== '' && <Text style={styles.optionHint}>{hint}</Text>}
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  flexShrink: { flexShrink: 1 },
  stretch: { alignSelf: 'stretch' },
  loading: { flex: 1, padding: 16, gap: 12, alignItems: 'center', justifyContent: 'center' },
  content: { padding: 16, gap: 10, paddingBottom: 32 },
  title: { fontSize: 18, letterSpacing: tightTracking(18), fontFamily: fonts.displayBold, color: C.text },
  intro: { fontSize: 14, lineHeight: 20, color: C.muted },
  sectionTitle: { marginTop: 12, fontSize: 13, fontWeight: '700', color: palette.n700, textTransform: 'uppercase', letterSpacing: 0.4 },
  sectionTitleRow: { marginTop: 12 },
  sectionTitleText: { fontSize: 13, fontWeight: '700', color: palette.n700, textTransform: 'uppercase', letterSpacing: 0.4 },
  trialBox: { padding: 14, gap: 6, borderRadius: 16, backgroundColor: C.accentSoft, borderWidth: 1, borderColor: C.accentBorder },
  trialTitleRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  trialTitle: { flexShrink: 1, fontSize: 14, fontWeight: '700', color: C.text },
  trialBody: { fontSize: 13, lineHeight: 18, color: C.text },
  trialItem: { flexDirection: 'row', gap: 6, paddingLeft: 4 },
  trialBullet: { fontSize: 13, lineHeight: 18, color: C.muted },
  trialCta: { marginTop: 2, fontSize: 13, lineHeight: 18, fontWeight: '700', color: C.link },
  sheetBackdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: alpha(palette.black, 0.4) },
  sheet: {
    flex: 1,
    paddingHorizontal: 16,
    paddingTop: 8,
    borderTopLeftRadius: radius.sheet,
    borderTopRightRadius: radius.sheet,
    backgroundColor: C.bg,
  },
  sheetHeader: { gap: 10, paddingBottom: 10 },
  handle: { alignSelf: 'center', width: 44, height: 5, borderRadius: 3, backgroundColor: palette.n300 },
  sheetTitleRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 },
  sheetTitle: { flex: 1, fontSize: 18, fontFamily: fonts.displayBold, letterSpacing: tightTracking(18), color: C.text },
  sheetClose: { width: MIN_TOUCH, height: MIN_TOUCH, alignItems: 'center', justifyContent: 'center' },
  sheetList: { gap: 10, paddingBottom: 16 },
  tiles: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, marginTop: 4 },
  tile: {
    flexGrow: 1,
    flexBasis: '45%',
    minHeight: 168,
    padding: 16,
    borderRadius: 20,
    backgroundColor: C.card,
    borderWidth: 1,
    borderColor: C.border,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
  },
  tileIcon: {
    width: 64,
    height: 64,
    borderRadius: 20,
    backgroundColor: C.accentSoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  tileTitle: { fontSize: 18, fontFamily: fonts.displayBold, letterSpacing: tightTracking(18), color: C.text },
  tileHint: { fontSize: 12, lineHeight: 17, color: C.muted, textAlign: 'center' },
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
  breedIcon: {
    width: 36,
    height: 36,
    borderRadius: 12,
    backgroundColor: C.accentSoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  breedIconLocked: { backgroundColor: C.track },
  breedTitleRow: { flexDirection: 'row', alignItems: 'center', gap: 8, flexWrap: 'wrap' },
  badge: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: 999 },
  badgeFree: { backgroundColor: C.greenSoft },
  badgeChallenge: { backgroundColor: C.yellowSoft },
  badgeText: { fontSize: 11, fontWeight: '700' },
  badgeFreeText: { color: C.greenText },
  badgeChallengeText: { color: C.yellowText },
  tags: { marginTop: 8, gap: 6 },
  tagGroup: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 6 },
  tagTitle: { fontSize: 12, fontWeight: '700', color: C.muted },
  chip: { maxWidth: '100%', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 999, borderWidth: 1, backgroundColor: C.card },
  chipSuits: { borderColor: C.accentBorder },
  chipConsider: { borderColor: C.border },
  chipText: { fontSize: 12, lineHeight: 16, fontWeight: '600', color: C.text },
  chipConsiderText: { color: C.muted },
  colourSection: { gap: 8 },
  colourChips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  colourChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    minHeight: 44,
    paddingHorizontal: 14,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.card,
  },
  colourChipSelected: { borderColor: C.accent, backgroundColor: C.accent },
  colourChipText: { fontSize: 14, fontWeight: '600', color: C.text },
  colourChipTextSelected: { color: palette.white },
  searchBox: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    height: 44,
    paddingHorizontal: 12,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.card,
  },
  searchInput: { flex: 1, fontSize: 15, color: C.text },
  lockedNote: { fontSize: 13, lineHeight: 18, color: C.yellowText },
  note: { fontSize: 12, lineHeight: 17, color: C.faint },
  noticeBox: { padding: 12, borderRadius: 12, backgroundColor: C.redSoft, borderWidth: 1, borderColor: palette.dangerBorder },
  noticeText: { fontSize: 13, color: C.redText },
  missing: { marginTop: 8, fontSize: 13, color: C.muted, textAlign: 'center' },
  summary: {
    marginTop: 8,
    padding: 14,
    borderRadius: 16,
    backgroundColor: C.card,
    borderWidth: 1,
    borderColor: C.accentBorder,
    gap: 6,
  },
  summaryTitle: { fontSize: 13, fontWeight: '700', color: palette.n700, textTransform: 'uppercase', letterSpacing: 0.4 },
  summaryRow: { flexDirection: 'row', justifyContent: 'space-between', gap: 12 },
  summaryLabel: { fontSize: 14, color: C.muted },
  summaryValue: { flexShrink: 1, fontSize: 14, fontWeight: '700', color: C.text, textAlign: 'right' },
  linkButton: { alignSelf: 'flex-start', paddingVertical: 2 },
  showAllButton: { alignSelf: 'center', minHeight: 44, justifyContent: 'center', paddingHorizontal: 12 },
  linkText: { fontSize: 14, fontWeight: '700', color: C.link, textDecorationLine: 'underline' },
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
