/**
 * Parent pet picker (M5-R04 → M5-R06-02, REALISM_SPEC §1 / §7, CAT_SPEC §1 / §10):
 * species → plan → breed → origin → age at arrival of a **new** pet, chosen before the
 * child's PIN. The choice goes to `POST /api/parent/generate-pin` as the full set
 * `{species, breed, origin, age_stage, plan}` — the server contract is all or nothing.
 *
 * The breed list comes from the server (`GET /api/breeds`, M5-R06_PLAN T3): free / paid,
 * which plan accepts a breed, search synonyms and order are data, not code. When the
 * catalogue can't be loaded the picker falls back to {@link FALLBACK_CATALOGUE} (today's
 * two dogs), so dog onboarding never breaks.
 *
 * Dog descriptions use only sourced facts and David's confirmed decisions (PRODUCT_SPEC
 * §4 / §5, DECISIONS 2026-10-05). Cat copy is a draft (DECISIONS 2026-10-08, CAT_SPEC §0 D)
 * and only reachable once cats are switched on (server + `CAT_UI_READY`).
 */

import type { LifeStage, NewPetProfile, PetBreed, PetOrigin, PetPlanType, PetSpecies } from '@/api/client';
import type { components } from '@/api/schema';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';
import { showableSpecies } from '@/config/features';
import { BREED_SPECIES, breedName, foldForSearch, isKnownBreed, isSpecies } from '@/modules/species/species';

/**
 * User-visible strings of the picker (`pet:picker`, M1-18). The dog `ageHints` hold the
 * confirmed meals + step goals (PRODUCT_SPEC §5; minutes × 100 steps; 1 week = 1 month).
 * Puppy / young: 10 min × age in months up to the adult goal (mutt 60 min, Border Collie
 * 120 min, Labrador 90 min — reached at 9 months, Golden Retriever 120 min — reached at
 * 12 months, French Bulldog 60 min — reached at 6 months, German Shepherd 120 min — reached at
 * 12 months); senior 75 % of adult (Labrador 68 min, whole minutes on the server; Golden 90 min;
 * French Bulldog 45 min; German Shepherd 90 min). Arrival age: puppy 2, young 9 months (§4). Cat texts
 * that differ by species live under `pet:picker.cat` (M5-R06_PLAN T8) — read them through
 * {@link pickerText}.
 */
export const PICKER_STRINGS = strings('pet', 'picker', {
  title: (name: string) => t('pet:picker.title', { name }),
  speciesTitle: (name: string) => t('pet:picker.speciesTitle', { name }),
  plans: {
    challenge: {
      /** "12-tedenski izziv — 49,99 €" — price from the store when known. */
      title: (price: string) => t('pet:picker.plans.challenge.title', { price }),
    },
  },
  lockedA11y: (breed: string) => t('pet:picker.lockedA11y', { breed }),
  lockedFreeOnlyA11y: (breed: string) => t('pet:picker.lockedFreeOnlyA11y', { breed }),
  searchEmpty: (query: string) => t('pet:picker.searchEmpty', { query }),
  cat: {
    title: (name: string) => t('pet:picker.cat.title', { name }),
  },
});

export const PICKER_ORIGINS: readonly PetOrigin[] = ['bought', 'adopted'];
export const PICKER_AGES: readonly LifeStage[] = ['puppy', 'young', 'adult', 'senior'];
export const PICKER_PLANS: readonly PetPlanType[] = ['free', 'challenge'];

/** Shown in the plan choice until the store price is loaded (PAYMENTS_SPEC P1). */
export const CHALLENGE_LIST_PRICE = '49,99 €';

type Suitability = components['schemas']['BreedCatalogResource']['suitability'];
/** "Za koga je primerna" tag keys (M5-R10, backend `config/breed_suitability.php`). */
export type SuitsTag = Suitability['suits'][number];
export type ConsiderTag = Suitability['consider'][number];

/** The whole vocabulary — a key outside it (a newer server) is dropped, never shown raw. */
export const SUITS_TAGS: readonly SuitsTag[] = [
  'active_family',
  'family_pet',
  'children',
  'small_children',
  'first_time_owner',
  'apartment',
  'large_home',
  'other_pets',
  'older_owners',
  'often_alone',
  'low_shedding',
] satisfies readonly SuitsTag[];
export const CONSIDER_TAGS: readonly ConsiderTag[] = [
  'long_daily_exercise',
  'needs_mental_stimulation',
  'may_herd_children',
  'chews_when_bored',
  'sheds',
  'food_motivated_weight',
  'frequent_grooming',
  'brachycephalic_breathing',
  'hips_hind_legs',
] satisfies readonly ConsiderTag[];

/** A breed's suitability tags (empty lists = no sourced tags, e.g. the mutt). */
export interface BreedSuitability {
  suits: readonly SuitsTag[];
  consider: readonly ConsiderTag[];
}

export const NO_SUITABILITY: BreedSuitability = { suits: [], consider: [] };

function readTags<T extends string>(raw: unknown, vocabulary: readonly T[]): T[] {
  if (!Array.isArray(raw)) return [];
  const known = raw.filter((tag): tag is T => typeof tag === 'string' && (vocabulary as readonly string[]).includes(tag));
  return [...new Set(known)];
}

/**
 * Loose `suitability` → typed: unknown tag keys dropped, duplicates removed, a missing or
 * malformed object (an older server, the mutt, cats) → no tags.
 */
export function readSuitability(raw: unknown): BreedSuitability {
  if (typeof raw !== 'object' || raw === null) return NO_SUITABILITY;
  const o = raw as Record<string, unknown>;
  return { suits: readTags(o.suits, SUITS_TAGS), consider: readTags(o.consider, CONSIDER_TAGS) };
}

export function hasSuitability(s: BreedSuitability): boolean {
  return s.suits.length > 0 || s.consider.length > 0;
}

/** Headings and tag labels (`pet:breedSuitability`), read while rendering. */
export const SUITABILITY_STRINGS = strings('pet', 'breedSuitability');

export function suitsLabel(tag: SuitsTag): string {
  return SUITABILITY_STRINGS.suits[tag];
}

export function considerLabel(tag: ConsiderTag): string {
  return SUITABILITY_STRINGS.consider[tag];
}

/**
 * One sentence for screen readers: "Primerno za: aktivno družino, veliko hišo z vrtom. Upoštevajte: izpada mu dlaka."
 * The labels are written to follow their heading (SL `suits` in the accusative, lower-case).
 */
export function suitabilityA11y(s: BreedSuitability): string {
  const parts: string[] = [];
  const S = SUITABILITY_STRINGS;
  if (s.suits.length > 0) parts.push(`${S.suitsTitle} ${s.suits.map(suitsLabel).join(', ')}.`);
  if (s.consider.length > 0) parts.push(`${S.considerTitle} ${s.consider.map(considerLabel).join(', ')}.`);
  return parts.join(' ');
}

/** One breed of the picker catalogue (cleaned `BreedCatalogResource`). */
export interface CatalogueBreed {
  breed: PetBreed;
  species: PetSpecies;
  /** Paid breed (12-week challenge). */
  premium: boolean;
  /** `plan: free` accepts it (the species' free breed). */
  free_plan_allowed: boolean;
  /** `plan: challenge` accepts it (a paid breed, M5-F03). */
  challenge_allowed: boolean;
  /** Search synonyms (lower case, e.g. "mejnkun"). */
  search_keywords: readonly string[];
  sort_order: number;
  /** "Za koga je primerna" tags (M5-R10); none for a breed without sourced tags. */
  suitability: BreedSuitability;
}

/** The picker catalogue: species the parent may choose (in order) and their breeds. */
export interface BreedCatalogue {
  species: readonly PetSpecies[];
  breeds: readonly CatalogueBreed[];
}

/**
 * Today's dogs, as the server seeds them (`BreedConfigsSeeder`, tags from
 * `config/breed_suitability.php`): used when the catalogue
 * can't be loaded (offline, server error) so dog onboarding never breaks. Never cats —
 * those exist only when the server says so.
 */
export const FALLBACK_CATALOGUE: BreedCatalogue = {
  species: ['dog'],
  breeds: [
    {
      breed: 'mutt',
      species: 'dog',
      premium: false,
      free_plan_allowed: true,
      challenge_allowed: false,
      search_keywords: ['mešanček', 'mesancek', 'mutt', 'mixed'],
      sort_order: 0,
      suitability: NO_SUITABILITY,
    },
    {
      breed: 'border_collie',
      species: 'dog',
      premium: true,
      free_plan_allowed: false,
      challenge_allowed: true,
      search_keywords: ['border collie', 'koli'],
      sort_order: 10,
      suitability: {
        suits: ['active_family'],
        consider: ['long_daily_exercise', 'needs_mental_stimulation', 'may_herd_children', 'chews_when_bored'],
      },
    },
    {
      breed: 'labrador_retriever',
      species: 'dog',
      premium: true,
      free_plan_allowed: false,
      challenge_allowed: true,
      search_keywords: [
        'labrador',
        'labrador retriever',
        'labradorec',
        'labradorski prinašalec',
        'labradorski prinasalec',
        'lab',
        'retriever',
        'prinašalec',
        'prinasalec',
      ],
      sort_order: 20,
      suitability: {
        suits: ['active_family', 'family_pet', 'large_home', 'other_pets'],
        consider: ['sheds', 'long_daily_exercise', 'food_motivated_weight'],
      },
    },
    {
      breed: 'golden_retriever',
      species: 'dog',
      premium: true,
      free_plan_allowed: false,
      challenge_allowed: true,
      search_keywords: ['golden', 'golden retriever', 'zlati prinašalec', 'zlati prinasalec', 'retriever'],
      sort_order: 30,
      suitability: {
        suits: ['active_family', 'family_pet', 'children', 'first_time_owner', 'large_home', 'other_pets'],
        consider: ['long_daily_exercise', 'sheds', 'food_motivated_weight', 'frequent_grooming'],
      },
    },
    {
      breed: 'french_bulldog',
      species: 'dog',
      premium: true,
      free_plan_allowed: false,
      challenge_allowed: true,
      search_keywords: ['french bulldog', 'frenchie', 'french', 'bulldog', 'francoski buldog', 'buldog'],
      sort_order: 40,
      suitability: {
        suits: ['apartment', 'family_pet', 'children'],
        consider: ['brachycephalic_breathing'],
      },
    },
    {
      breed: 'german_shepherd',
      species: 'dog',
      premium: true,
      free_plan_allowed: false,
      challenge_allowed: true,
      search_keywords: ['german shepherd', 'german shepherd dog', 'gsd', 'alsatian', 'nemški ovčar', 'nemski ovcar', 'ovčar', 'ovcar'],
      sort_order: 50,
      suitability: {
        suits: ['active_family', 'family_pet', 'large_home'],
        consider: ['long_daily_exercise', 'sheds', 'frequent_grooming', 'chews_when_bored', 'hips_hind_legs'],
      },
    },
  ],
};

function readEntry(raw: unknown): CatalogueBreed | null {
  if (typeof raw !== 'object' || raw === null) return null;
  const o = raw as Record<string, unknown>;
  // A breed this build doesn't know has no name to show — skipped (it needs a newer app).
  if (!isKnownBreed(o.breed) || !isSpecies(o.species) || BREED_SPECIES[o.breed] !== o.species) return null;
  const premium = o.premium === true;
  return {
    breed: o.breed,
    species: o.species,
    premium,
    free_plan_allowed: typeof o.free_plan_allowed === 'boolean' ? o.free_plan_allowed : !premium,
    challenge_allowed: typeof o.challenge_allowed === 'boolean' ? o.challenge_allowed : premium,
    search_keywords: Array.isArray(o.search_keywords)
      ? o.search_keywords.filter((k): k is string => typeof k === 'string')
      : [],
    sort_order: typeof o.sort_order === 'number' && Number.isFinite(o.sort_order) ? o.sort_order : 0,
    suitability: readSuitability(o.suitability),
  };
}

/**
 * Loose `GET /api/breeds` body → typed catalogue: species in the server's order (dog
 * first), a species without a single known breed dropped; per species the free breed
 * first, then the paid ones by `sort_order` (ties by breed key). Species this build can't
 * show (`showableSpecies()`, cats only with `CAT_UI_READY`) are dropped. Null when nothing usable
 * is left — the caller then uses {@link FALLBACK_CATALOGUE}.
 */
export function readBreedCatalogue(raw: unknown, showable: readonly PetSpecies[] = showableSpecies()): BreedCatalogue | null {
  if (typeof raw !== 'object' || raw === null) return null;
  const o = raw as Record<string, unknown>;
  // QA PR #94 m3: never offer a species this build can't show (a cat while `CAT_UI_READY`
  // is false), even if the server sends it by mistake.
  const listed = Array.isArray(o.species) ? o.species.filter(isSpecies).filter((sp) => showable.includes(sp)) : [];
  const entries = (Array.isArray(o.breeds) ? o.breeds : [])
    .map(readEntry)
    .filter((e): e is CatalogueBreed => e !== null && listed.includes(e.species));
  const species = [...new Set(listed)].filter((s) => entries.some((e) => e.species === s));
  if (species.length === 0) return null;
  const rank = (e: CatalogueBreed) => (e.free_plan_allowed && !e.premium ? 0 : 1);
  const breeds = [...entries].sort(
    (a, b) =>
      species.indexOf(a.species) - species.indexOf(b.species) ||
      rank(a) - rank(b) ||
      a.sort_order - b.sort_order ||
      a.breed.localeCompare(b.breed),
  );
  return { species, breeds };
}

/** The breeds of one species, in picker order ([] before a species is chosen). */
export function breedsOf(catalogue: BreedCatalogue, species: PetSpecies | null): CatalogueBreed[] {
  return species === null ? [] : catalogue.breeds.filter((b) => b.species === species);
}

/** The species' free breed (mutt / domestic cat): the first breed the free plan accepts. */
export function freeBreedOf(breeds: readonly CatalogueBreed[]): PetBreed | null {
  return breeds.find((b) => b.free_plan_allowed)?.breed ?? null;
}

/**
 * Does the breed match the search? Case- and diacritic-insensitive ("mesancek" finds
 * "Mešanček"), on the localized name and the server's synonyms ("mejnkun" → Maine Coon).
 * An empty query matches everything.
 */
export function matchesBreedSearch(entry: CatalogueBreed, query: string): boolean {
  const q = foldForSearch(query);
  if (q === '') return true;
  const haystack = [breedName(entry.breed, entry.species), entry.breed.replace(/_/g, ' '), ...entry.search_keywords];
  return haystack.some((text) => foldForSearch(text).includes(q));
}

export function searchBreeds(breeds: readonly CatalogueBreed[], query: string): CatalogueBreed[] {
  return breeds.filter((b) => matchesBreedSearch(b, query));
}

/** What the parent has picked so far (species, plan, origin and age have no default — a deliberate choice). */
export interface PickerChoice {
  species: PetSpecies | null;
  plan: PetPlanType | null;
  breed: PetBreed | null;
  origin: PetOrigin | null;
  age_stage: LifeStage | null;
}

export const INITIAL_PICKER_CHOICE: PickerChoice = { species: null, plan: null, breed: null, origin: null, age_stage: null };

/**
 * Breeds that can't be picked with this plan (`breeds` = the chosen species' catalogue
 * entries). The free plan (and no plan yet) takes only the free breed; the challenge only
 * a paid breed (M5-F03, server 422 `challenge_requires_paid_breed`). `serverLocked` =
 * breeds the server refused for this choice (422 `breed_locked`).
 */
export function lockedBreedsFor(
  plan: PetPlanType | null,
  serverLocked: readonly PetBreed[],
  breeds: readonly CatalogueBreed[],
): PetBreed[] {
  const base = breeds.filter((b) => (plan === 'challenge' ? !b.challenge_allowed : !b.free_plan_allowed)).map((b) => b.breed);
  return [...new Set([...base, ...serverLocked])];
}

/**
 * Why a breed can't be picked with this plan — for the note and the a11y label:
 * `free_only` = the free breed while the challenge is chosen, `challenge_only` = a paid
 * breed on the free plan (or before a plan), `server` = the server refused it; null = pickable.
 */
export type BreedLockReason = 'free_only' | 'challenge_only' | 'server';

export function breedLockReason(
  breed: PetBreed,
  plan: PetPlanType | null,
  serverLocked: readonly PetBreed[],
  breeds: readonly CatalogueBreed[],
): BreedLockReason | null {
  const entry = breeds.find((b) => b.breed === breed);
  if (!entry) return 'server';
  if (plan === 'challenge' && !entry.challenge_allowed) return 'free_only';
  if (plan !== 'challenge' && !entry.free_plan_allowed) return 'challenge_only';
  return serverLocked.includes(breed) ? 'server' : null;
}

export function isBreedLocked(breed: PetBreed | null, locked: readonly PetBreed[]): boolean {
  return breed === null || locked.includes(breed);
}

/**
 * The choice after the parent picks `plan`: the free plan is always the species' free
 * breed; the challenge never keeps a breed it locks — it moves to the first pickable one,
 * so switching plans never leaves an invalid selection behind. When no breed is pickable
 * the breed stays and {@link completeChoice} stays null.
 */
export function choiceWithPlan(
  choice: PickerChoice,
  plan: PetPlanType,
  serverLocked: readonly PetBreed[],
  breeds: readonly CatalogueBreed[],
): PickerChoice {
  if (plan === 'free') return { ...choice, plan, breed: freeBreedOf(breeds) ?? choice.breed };
  const locked = lockedBreedsFor(plan, serverLocked, breeds);
  if (!isBreedLocked(choice.breed, locked)) return { ...choice, plan };
  const firstOpen = breeds.find((b) => !isBreedLocked(b.breed, locked));
  return { ...choice, plan, breed: firstOpen?.breed ?? choice.breed };
}

/**
 * The choice after the parent picks a species: plan, origin and age stay; the breed is
 * re-chosen inside the new species (its free breed, or the first paid one on the challenge).
 */
export function choiceWithSpecies(
  choice: PickerChoice,
  species: PetSpecies,
  serverLocked: readonly PetBreed[],
  breeds: readonly CatalogueBreed[],
): PickerChoice {
  if (choice.species === species && choice.breed !== null && breeds.some((b) => b.breed === choice.breed)) return choice;
  const fresh: PickerChoice = { ...choice, species, breed: freeBreedOf(breeds) };
  return choice.plan === null ? fresh : choiceWithPlan(fresh, choice.plan, serverLocked, breeds);
}

/**
 * The full set for generate-pin, or null while anything is missing or the breed is locked.
 * The free plan always sends the species' free breed (a stale paid breed is never sent).
 */
export function completeChoice(
  choice: PickerChoice,
  serverLocked: readonly PetBreed[],
  breeds: readonly CatalogueBreed[],
): NewPetProfile | null {
  if (choice.species === null || choice.plan === null || choice.origin === null || choice.age_stage === null) return null;
  const ofSpecies = breeds.filter((b) => b.species === choice.species);
  const breed = choice.plan === 'free' ? freeBreedOf(ofSpecies) : choice.breed;
  if (breed === null || !ofSpecies.some((b) => b.breed === breed)) return null;
  if (isBreedLocked(breed, lockedBreedsFor(choice.plan, serverLocked, ofSpecies))) return null;
  return { species: choice.species, breed, origin: choice.origin, age_stage: choice.age_stage, plan: choice.plan };
}

/** Texts that differ by species (T8): dog keys unchanged, cat keys under `pet:picker.cat`. */
export interface PickerText {
  title: (name: string) => string;
  intro: string;
  freePlanTitle: string;
  freePlanHint: string;
  challengePlanHint: string;
  breedFreeNote: string;
  breedChallengeNote: string;
  breedLockedNote: string;
  noPaidBreed: string;
  origins: Readonly<Record<PetOrigin, string>>;
  originHints: Readonly<Record<PetOrigin, string>>;
  ages: Readonly<Record<LifeStage, string>>;
}

/** The picker texts of a species (read while rendering). */
export function pickerText(species: PetSpecies | null): PickerText {
  const S = PICKER_STRINGS;
  if (species === 'cat') {
    const C = S.cat;
    return {
      title: C.title,
      intro: C.intro,
      freePlanTitle: C.plans.free.title,
      freePlanHint: C.plans.free.hint,
      challengePlanHint: C.plans.challenge.hint,
      breedFreeNote: C.breedFreeNote,
      breedChallengeNote: C.breedChallengeNote,
      breedLockedNote: C.breedLockedNote,
      noPaidBreed: C.noPaidBreed,
      origins: C.origins,
      originHints: C.originHints,
      ages: C.ages,
    };
  }
  return {
    title: S.title,
    intro: S.intro,
    freePlanTitle: S.plans.free.title,
    freePlanHint: S.plans.free.hint,
    challengePlanHint: S.plans.challenge.hint,
    breedFreeNote: S.breedFreeNote,
    breedChallengeNote: S.breedChallengeNote,
    breedLockedNote: S.breedLockedNote,
    noPaidBreed: S.noPaidBreed,
    origins: S.origins,
    originHints: S.originHints,
    ages: S.ages,
  };
}

/** One-line hint of a breed row ("Kratka dlaka · brezplačna"). */
export function breedHint(breed: PetBreed): string {
  return PICKER_STRINGS.breedHints[breed];
}

/** Age-at-arrival hint of a breed (dogs: meals + step goal; cats: arrival age only). */
export function ageHint(breed: PetBreed, age: LifeStage): string {
  return PICKER_STRINGS.ageHints[breed][age];
}
