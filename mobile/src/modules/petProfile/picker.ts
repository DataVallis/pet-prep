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
 * 12 months, French Bulldog, Miniature Schnauzer, Shih Tzu, Boston Terrier, Miniature American Shepherd and Shetland Sheepdog 60 min — reached at 6 months, German Shepherd 120 min — reached at
 * 12 months, Cavalier King Charles Spaniel, Beagle, Standard Poodle and Dachshund 60 min — reached at 6 months, Australian Shepherd 120 min — reached at 12 months, Havanese, Yorkshire Terrier, Pomeranian and Chihuahua 30 min — reached at 3 months, West Highland White Terrier and Bernese Mountain Dog 60 min — reached at 6 months, Siberian Husky and Rottweiler 120 min — reached at 12 months, Bulldog 60 min — reached at 6 months, German Shorthaired Pointer, Cane Corso, Dobermann, Boxer, Great Dane, English Springer Spaniel, Brittany and American Cocker Spaniel 120 min — reached at 12 months, Belgian Malinois 120 min — reached at 12 months, Basset Hound 60 min — reached at 6 months, Hungarian Vizsla, Rhodesian Ridgeback, Dalmatian, Australian Cattle Dog, Wirehaired Pointing Griffon, Chesapeake Bay Retriever, Samoyed, Weimaraner and Giant Schnauzer and Bloodhound and Akita and German Wirehaired Pointer and Bullmastiff and Irish Wolfhound 120 min — reached at 12 months, English Cocker Spaniel 60 min — reached at 6 months, Maltese, Papillon, Bichon Frise and Chinese Crested 30 min — reached at 3 months, Pug 60 min — reached at 6 months, Rough Collie, Mastiff, Shiba Inu, Portuguese Water Dog, Newfoundland, Whippet, Scottish Terrier, Italian Greyhound and Saint Bernard and Staffordshire Bull Terrier and Airedale Terrier and Russell Terrier and Nova Scotia Duck Tolling Retriever and Cairn Terrier and Bull Terrier 60 min — reached at 6 months, Miniature Pinscher 30 min — reached at 3 months, Pyrenean Mountain Dog, Soft Coated Wheaten Terrier and American Staffordshire Terrier 60 min — reached at 6 months, Lagotto Romagnolo 120 min — reached at 12 months, Pembroke Welsh Corgi and Cardigan Welsh Corgi 60 min — reached at 6 months); senior 75 % of adult (Labrador 68 min, whole minutes on the server; Golden 90 min;
 * French Bulldog 45 min; German Shepherd 90 min; Cavalier 45 min; Beagle 45 min; Standard Poodle 45 min; Dachshund 45 min; Australian Shepherd 90 min; Havanese 23 min; West Highland White Terrier 45 min; Bernese Mountain Dog 45 min; Siberian Husky 90 min; Rottweiler 90 min; Bulldog 45 min; German Shorthaired Pointer 90 min; Yorkshire Terrier 23 min; Cane Corso 90 min; Pembroke Welsh Corgi 45 min; Dobermann 90 min; Boxer 90 min; Miniature Schnauzer 45 min; Shih Tzu 45 min; Great Dane 90 min; Pomeranian 23 min; Boston Terrier 45 min; Miniature American Shepherd 45 min; Chihuahua 23 min; English Springer Spaniel 90 min; Shetland Sheepdog 45 min; Brittany 90 min; American Cocker Spaniel 90 min; Belgian Malinois 90 min; Basset Hound 45 min; Hungarian Vizsla 90 min; English Cocker Spaniel 45 min; Maltese 23 min; Pug 45 min; Rough Collie 45 min; Mastiff 45 min; Shiba Inu 45 min; Rhodesian Ridgeback 90 min; Papillon 23 min; Portuguese Water Dog 45 min; Bichon Frise 23 min; Newfoundland 45 min; Dalmatian 90 min; Australian Cattle Dog 90 min; Whippet 45 min; Wirehaired Pointing Griffon 90 min; Chesapeake Bay Retriever 90 min; Samoyed 90 min; Scottish Terrier 45 min; Weimaraner 90 min; Italian Greyhound 45 min; Giant Schnauzer 90 min; Bloodhound 90 min; Akita 90 min; Cardigan Welsh Corgi 45 min; German Wirehaired Pointer 90 min; Saint Bernard 45 min; Staffordshire Bull Terrier 45 min; Airedale Terrier 45 min; Russell Terrier 45 min; Bullmastiff 90 min; Chinese Crested 23 min; Nova Scotia Duck Tolling Retriever 45 min; Cairn Terrier 45 min; Bull Terrier 45 min; Miniature Pinscher 23 min; Pyrenean Mountain Dog 45 min; Soft Coated Wheaten Terrier 45 min; American Staffordshire Terrier 45 min; Irish Wolfhound 90 min; Lagotto Romagnolo 90 min). Arrival age: puppy 2, young 9 months (§4). Cat texts
 * that differ by species live under `pet:picker.cat` (M5-R06_PLAN T8) — read them through
 * {@link pickerText}.
 */
export const PICKER_STRINGS = strings('pet', 'picker', {
  title: (name: string) => t('pet:picker.title', { name }),
  speciesTitle: (name: string) => t('pet:picker.speciesTitle', { name }),
  plans: {
    challenge: {
      /**
       * "12-tedenski izziv — 49,99 €" with the STORE's localized price (`priceString`);
       * null → "… — …" while loading, else without a number (bug 2026-10-14).
       */
      title: (price: string | null, loading = false) =>
        price !== null
          ? t('pet:picker.plans.challenge.title', { price })
          : loading
            ? t('pet:picker.plans.challenge.titleLoading')
            : t('pet:picker.plans.challenge.titleNoPrice'),
    },
  },
  lockedA11y: (breed: string) => t('pet:picker.lockedA11y', { breed }),
  lockedFreeOnlyA11y: (breed: string) => t('pet:picker.lockedFreeOnlyA11y', { breed }),
  searchEmpty: (query: string) => t('pet:picker.searchEmpty', { query }),
  showAllBreeds: (count: number) => t('pet:picker.showAllBreeds', { count }),
  originAdoptionOnly: (breed: string) => t('pet:picker.originAdoptionOnly', { breed }),
  /** M5-R21: a breed with a single colour — shown as fact, nothing to choose. */
  colourOnly: (colour: string) => t('pet:picker.colourOnly', { colour }),
  colourA11y: (colour: string) => t('pet:picker.colourA11y', { colour }),
  cat: {
    title: (name: string) => t('pet:picker.cat.title', { name }),
  },
});

export const PICKER_ORIGINS: readonly PetOrigin[] = ['bought', 'adopted'];
export const PICKER_AGES: readonly LifeStage[] = ['puppy', 'young', 'adult', 'senior'];
export const PICKER_PLANS: readonly PetPlanType[] = ['free', 'challenge'];

// No list price any more (bug 2026-10-14): the plan choice shows only the store's own
// localized price; while it loads "…", without one no number (never a currency guess).

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
  'heart_and_spine',
  'back_spine',
  'sensitive_skin',
  'shorter_lifespan',
  'secure_fencing',
  'experienced_owner',
  'fragile_older_children',
  'not_for_small_children',
  'drools',
  'chases_small_pets',
  'needs_company',
  'short_muzzle_breathing',
  'eyelid_problems',
  'older_children',
  'independent_experienced_owner',
  'sensitive_older_children',
  'heat_sensitive',
  'cold_sensitive',
  'sun_protection',
  'lively_older_children',
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
  /**
   * M5-R21: coat colours the parent may pick (paid breeds only; labels already in the app
   * language). [] = no colour choice (free breed, an older server, the offline fallback).
   */
  colours: readonly BreedColour[];
}

/**
 * One coat colour of a paid breed (`GET /api/breeds` → `colours[]`, M5-R21): `key` is sent as
 * `colour` to generate-pin, `label` is in the request language (Accept-Language), shown as is.
 */
export type BreedColour = components['schemas']['BreedCatalogResource']['colours'][number];

export const NO_COLOURS: readonly BreedColour[] = [];

/**
 * Loose `colours` → typed: entries without a non-empty string `key` and `label` are dropped,
 * a repeated key keeps its first entry; anything else (missing on an older server) → none.
 */
export function readColours(raw: unknown): readonly BreedColour[] {
  if (!Array.isArray(raw)) return NO_COLOURS;
  const seen = new Set<string>();
  const colours: BreedColour[] = [];
  for (const item of raw) {
    if (typeof item !== 'object' || item === null) continue;
    const { key, label } = item as Record<string, unknown>;
    if (typeof key !== 'string' || typeof label !== 'string') continue;
    const k = key.trim();
    const l = label.trim();
    if (k === '' || l === '' || seen.has(k)) continue;
    seen.add(k);
    colours.push({ key: k, label: l });
  }
  return colours;
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
const FALLBACK_BREEDS: ReadonlyArray<Omit<CatalogueBreed, 'colours'>> = [
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
      consider: ['brachycephalic_breathing', 'chases_small_pets'],
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
  {
    breed: 'cavalier_king_charles_spaniel',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['cavalier king charles spaniel', 'cavalier', 'king charles', 'ckcs', 'spaniel', 'kavalir king charles španjel', 'kavalir king charles spanjel', 'kavalir', 'španjel', 'spanjel'],
    sort_order: 60,
    suitability: {
      suits: ['apartment', 'family_pet', 'children'],
      consider: ['sheds', 'frequent_grooming', 'heart_and_spine'],
    },
  },
  {
    breed: 'beagle',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['beagle', 'bigl'],
    sort_order: 70,
    suitability: {
      suits: ['family_pet'],
      consider: ['sheds', 'chews_when_bored'],
    },
  },
  {
    breed: 'standard_poodle',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['poodle (standard)', 'standard poodle', 'poodle', 'veliki koder', 'koder', 'veliki pudelj', 'pudelj', 'standardni pudelj'],
    sort_order: 80,
    suitability: {
      suits: ['children', 'large_home', 'other_pets', 'low_shedding'],
      consider: ['frequent_grooming'],
    },
  },
  {
    breed: 'dachshund',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['dachshund', 'sausage dog', 'teckel', 'jazbečar', 'jazbecar'],
    sort_order: 90,
    suitability: {
      suits: ['children'],
      consider: ['back_spine', 'sheds', 'needs_mental_stimulation', 'chases_small_pets'],
    },
  },
  {
    breed: 'australian_shepherd',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['australian shepherd', 'aussie', 'avstralski ovčar', 'avstralski ovcar'],
    sort_order: 100,
    suitability: {
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'needs_mental_stimulation', 'may_herd_children', 'chews_when_bored', 'sheds', 'frequent_grooming'],
    },
  },
  {
    breed: 'havanese',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['havanese', 'havanski bišon', 'havanski bison', 'bichon havanais', 'havanez'],
    sort_order: 110,
    suitability: {
      suits: ['apartment', 'family_pet', 'children', 'low_shedding'],
      consider: ['frequent_grooming'],
    },
  },
  {
    breed: 'west_highland_white_terrier',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['west highland white terrier', 'westie', 'westy', 'zahodnoškotski beli terier', 'zahodnoskotski beli terier', 'west highland terier', 'vestie'],
    sort_order: 120,
    suitability: {
      suits: ['apartment', 'family_pet', 'children'],
      consider: ['sensitive_skin', 'sheds', 'frequent_grooming', 'chews_when_bored', 'chases_small_pets'],
    },
  },
  {
    breed: 'bernese_mountain_dog',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['bernese mountain dog', 'berner', 'berner sennenhund', 'bernese', 'bernski planšarski pes', 'bernski plansarski pes', 'bernski planšar', 'bernski plansar'],
    sort_order: 130,
    suitability: {
      suits: ['family_pet', 'large_home'],
      consider: ['shorter_lifespan', 'sheds', 'frequent_grooming'],
    },
  },
  {
    breed: 'siberian_husky',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['siberian husky', 'husky', 'huskie', 'sibirski haski', 'haski', 'sibirski husky', 'sibirec'],
    sort_order: 140,
    suitability: {
      suits: ['active_family', 'large_home'],
      consider: ['long_daily_exercise', 'secure_fencing', 'chews_when_bored', 'sheds', 'frequent_grooming', 'chases_small_pets'],
    },
  },
  {
    breed: 'rottweiler',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['rottweiler', 'rottie', 'rotty', 'rott', 'rotvajler', 'rotvajlerka', 'rotweiler', 'rotvailer'],
    sort_order: 150,
    suitability: {
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'experienced_owner', 'hips_hind_legs', 'shorter_lifespan', 'sheds'],
    },
  },
  {
    breed: 'bulldog',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['bulldog', 'english bulldog', 'british bulldog', 'angleški buldog', 'angleski buldog', 'buldog'],
    sort_order: 160,
    suitability: {
      suits: ['family_pet', 'other_pets'],
      consider: ['brachycephalic_breathing', 'sensitive_skin', 'shorter_lifespan', 'sheds'],
    },
  },
  {
    breed: 'german_shorthaired_pointer',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['german shorthaired pointer', 'german short-haired pointer', 'german pointer', 'gsp', 'shorthair', 'deutsch kurzhaar', 'kurzhaar', 'nemški kratkodlaki ptičar', 'nemski kratkodlaki pticar', 'kratkodlaki ptičar', 'kratkodlaki pticar', 'ptičar', 'pticar'],
    sort_order: 170,
    suitability: {
      suits: ['active_family', 'large_home'],
      consider: ['long_daily_exercise', 'sheds'],
    },
  },
  {
    breed: 'yorkshire_terrier',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['yorkshire terrier', 'yorkshire', 'yorkie', 'yorki', 'yorkshirski terier', 'jorkširski terier', 'jorksirski terier', 'jorki'],
    sort_order: 180,
    suitability: {
      suits: ['apartment', 'low_shedding'],
      consider: ['fragile_older_children', 'frequent_grooming'],
    },
  },
  {
    breed: 'cane_corso',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['cane corso', 'cane corso italiano', 'italian cane corso', 'italian corso dog', 'corso', 'italijanski cane corso', 'korzo', 'kane korso'],
    sort_order: 190,
    suitability: {
      suits: ['active_family'],
      consider: ['long_daily_exercise', 'not_for_small_children', 'experienced_owner', 'needs_mental_stimulation', 'shorter_lifespan', 'sheds', 'drools'],
    },
  },
  {
    breed: 'pembroke_welsh_corgi',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['pembroke welsh corgi', 'welsh corgi pembroke', 'pembroke', 'corgi', 'welsh corgi', 'korgi', 'valizanski korgi', 'valižanski korgi'],
    sort_order: 200,
    suitability: {
      suits: [],
      consider: ['may_herd_children', 'sheds', 'frequent_grooming'],
    },
  },
  {
    breed: 'dobermann',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['dobermann', 'doberman', 'doberman pinscher', 'dobie', 'dobermann pinscher'],
    sort_order: 210,
    suitability: {
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'not_for_small_children', 'experienced_owner', 'needs_mental_stimulation', 'sheds', 'chases_small_pets'],
    },
  },
  {
    breed: 'boxer',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['boxer', 'boksar', 'deutscher boxer'],
    sort_order: 220,
    suitability: {
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'short_muzzle_breathing', 'not_for_small_children', 'experienced_owner', 'needs_company', 'chews_when_bored', 'sheds', 'chases_small_pets'],
    },
  },
  {
    breed: 'miniature_schnauzer',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['miniature schnauzer', 'pritlikavi šnavcer', 'pritlikavi snavcer', 'mali šnavcer', 'zwergschnauzer', 'schnauzer', 'šnavcer', 'mini schnauzer'],
    sort_order: 230,
    suitability: {
      suits: ['apartment', 'children', 'low_shedding'],
      consider: ['frequent_grooming'],
    },
  },
  {
    breed: 'shih_tzu',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['shih tzu', 'shih-tzu', 'shihtzu', 'ši-cu', 'šicu', 'ši cu', 'sicu'],
    sort_order: 240,
    suitability: {
      suits: ['apartment', 'family_pet', 'first_time_owner'],
      consider: ['frequent_grooming', 'short_muzzle_breathing'],
    },
  },
  {
    breed: 'great_dane',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['great dane', 'nemška doga', 'nemska doga', 'doga', 'deutsche dogge', 'dogge', 'dane'],
    sort_order: 250,
    suitability: {
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'not_for_small_children', 'shorter_lifespan', 'hips_hind_legs', 'eyelid_problems', 'chews_when_bored', 'sheds', 'drools', 'chases_small_pets'],
    },
  },
  {
    breed: 'pomeranian',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['pomeranian', 'pomeranec', 'pom', 'nemški špic', 'nemski spic', 'german toy spitz', 'toy spitz'],
    sort_order: 260,
    suitability: {
      suits: ['apartment', 'family_pet', 'other_pets'],
      consider: ['older_children', 'frequent_grooming', 'sheds'],
    },
  },
  {
    breed: 'boston_terrier',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['boston terrier', 'bostonski terier', 'boston', 'american gentleman'],
    sort_order: 270,
    suitability: {
      suits: ['apartment', 'family_pet', 'children'],
      consider: ['brachycephalic_breathing', 'needs_company'],
    },
  },
  {
    breed: 'miniature_american_shepherd',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['miniature american shepherd', 'mali ameriški ovčar', 'mali ameriski ovcar', 'mini american', 'mini aussie', 'miniature australian shepherd'],
    sort_order: 280,
    suitability: {
      suits: ['family_pet', 'children', 'other_pets'],
      consider: ['needs_mental_stimulation', 'may_herd_children', 'needs_company', 'sheds'],
    },
  },
  {
    breed: 'chihuahua',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['chihuahua', 'čivava', 'civava', 'chi'],
    sort_order: 290,
    suitability: {
      suits: ['apartment', 'other_pets'],
      consider: ['older_children', 'needs_company', 'sheds'],
    },
  },
  {
    breed: 'english_springer_spaniel',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['english springer spaniel', 'angleški springer španjel', 'angleski springer spanjel', 'springer spaniel', 'springer španjel', 'springer'],
    sort_order: 300,
    suitability: {
      suits: ['active_family', 'family_pet', 'children', 'other_pets'],
      consider: ['long_daily_exercise', 'needs_company', 'sheds', 'frequent_grooming'],
    },
  },
  {
    breed: 'shetland_sheepdog',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['shetland sheepdog', 'šetlandski ovčar', 'setlandski ovcar', 'sheltie', 'šeltie', 'shetland'],
    sort_order: 310,
    suitability: {
      suits: ['family_pet', 'children'],
      consider: ['needs_mental_stimulation', 'may_herd_children', 'frequent_grooming', 'sheds'],
    },
  },
  {
    breed: 'brittany',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['brittany', 'bretonski ptičar', 'bretonski pticar', 'epagneul breton', 'bretonski španjel', 'brittany spaniel'],
    sort_order: 320,
    suitability: {
      suits: ['active_family', 'family_pet', 'children'],
      consider: ['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'sheds', 'chases_small_pets'],
    },
  },
  {
    breed: 'american_cocker_spaniel',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['american cocker spaniel', 'ameriški koker španjel', 'ameriski koker spanjel', 'american cocker', 'ameriški koker', 'ameriski koker'],
    sort_order: 330,
    suitability: {
      suits: ['family_pet', 'children', 'other_pets'],
      consider: ['frequent_grooming', 'food_motivated_weight', 'sheds'],
    },
  },
  {
    breed: 'belgian_malinois',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['belgian malinois', 'belgijski ovčar malinois', 'belgijski ovcar malinois', 'malinois', 'belgian shepherd malinois', 'belgian shepherd dog malinois'],
    sort_order: 340,
    suitability: {
      suits: ['active_family', 'large_home'],
      consider: ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'may_herd_children', 'needs_mental_stimulation', 'needs_company', 'sheds', 'chases_small_pets'],
    },
  },
  {
    breed: 'basset_hound',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['basset hound', 'baset', 'basset', 'baset hound', 'baset haund', 'bassett'],
    sort_order: 350,
    suitability: {
      suits: ['family_pet', 'children', 'large_home'],
      consider: ['eyelid_problems', 'sensitive_skin', 'food_motivated_weight', 'needs_company', 'sheds', 'drools'],
    },
  },
  {
    breed: 'hungarian_vizsla',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['hungarian vizsla', 'vizsla', 'madžarski kratkodlaki ptičar', 'madzarski kratkodlaki pticar', 'vižla', 'vizla', 'madžarska vižla', 'madzarska vizla', 'magyar vizsla', 'hungarian pointer'],
    sort_order: 360,
    suitability: {
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets'],
    },
  },
  {
    breed: 'english_cocker_spaniel',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['english cocker spaniel', 'angleški koker španjel', 'angleski koker spanjel', 'english cocker', 'angleški koker', 'angleski koker', 'cocker spaniel', 'koker španjel', 'koker spanjel', 'merry cocker'],
    sort_order: 370,
    suitability: {
      suits: ['family_pet', 'children', 'other_pets'],
      consider: ['frequent_grooming', 'food_motivated_weight', 'chews_when_bored', 'needs_company', 'sheds'],
    },
  },
  {
    breed: 'maltese',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['maltese', 'maltežan', 'maltezan', 'malteški bišon', 'malteski bison', 'malteški psiček', 'malteski psicek', 'bichon maltais', 'maltese terrier'],
    sort_order: 380,
    suitability: {
      suits: ['apartment', 'low_shedding'],
      consider: ['frequent_grooming', 'needs_company'],
    },
  },
  {
    breed: 'pug',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['pug', 'mops', 'mopsek', 'pug dog', 'carlin'],
    sort_order: 390,
    suitability: {
      suits: ['apartment', 'children', 'other_pets'],
      consider: ['brachycephalic_breathing', 'sensitive_skin', 'food_motivated_weight', 'needs_company', 'sheds'],
    },
  },
  {
    breed: 'rough_collie',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['rough collie', 'collie', 'dolgodlaki škotski ovčar', 'dolgodlaki skotski ovcar', 'škotski ovčar', 'skotski ovcar', 'long-haired collie', 'collie rough'],
    sort_order: 400,
    suitability: {
      suits: ['children', 'other_pets'],
      consider: ['may_herd_children', 'frequent_grooming', 'needs_company', 'sheds'],
    },
  },
  {
    breed: 'mastiff',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['mastiff', 'english mastiff', 'old english mastiff', 'angleški mastif', 'angleski mastif', 'mastif'],
    sort_order: 410,
    suitability: {
      suits: ['family_pet', 'children', 'other_pets'],
      consider: ['not_for_small_children', 'shorter_lifespan', 'eyelid_problems', 'sensitive_skin', 'hips_hind_legs', 'sheds', 'drools'],
    },
  },
  {
    breed: 'shiba_inu',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['shiba inu', 'shiba', 'japanese shiba inu', 'šiba inu', 'šiba', 'siba inu', 'siba', 'japonski šiba inu', 'japonski siba inu'],
    sort_order: 420,
    suitability: {
      suits: [],
      consider: ['independent_experienced_owner', 'older_children', 'needs_mental_stimulation', 'sheds'],
    },
  },
  {
    breed: 'rhodesian_ridgeback',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['rhodesian ridgeback', 'ridgeback', 'rodezijski ridgeback', 'rodezijski', 'african lion dog'],
    sort_order: 430,
    suitability: {
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets'],
    },
  },
  {
    breed: 'papillon',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['papillon', 'phalene', 'phalène', 'butterfly dog', 'papijon', 'falen'],
    sort_order: 440,
    suitability: {
      suits: ['apartment', 'family_pet', 'children', 'other_pets'],
      consider: ['frequent_grooming', 'needs_mental_stimulation', 'needs_company', 'sheds'],
    },
  },
  {
    breed: 'portuguese_water_dog',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['portuguese water dog', 'portie', 'pwd', 'cao de agua', 'cão de água português', 'portugalski vodni pes', 'vodni pes', 'portugalski'],
    sort_order: 450,
    suitability: {
      suits: ['family_pet', 'children', 'large_home', 'other_pets', 'low_shedding'],
      consider: ['frequent_grooming'],
    },
  },
  {
    breed: 'bichon_frise',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['bichon frise', 'bichon frisé', 'bichon', 'bichon a poil frise', 'bichon tenerife', 'kodrasti bišon', 'kodrasti bison', 'bišon'],
    sort_order: 460,
    suitability: {
      suits: ['apartment', 'family_pet', 'children', 'other_pets', 'low_shedding'],
      consider: ['frequent_grooming', 'needs_company'],
    },
  },
  {
    breed: 'newfoundland',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['newfoundland', 'newfie', 'newf', 'novofundlandec', 'novofundlandski pes', 'njufi', 'terra nova'],
    sort_order: 470,
    suitability: {
      suits: ['family_pet', 'children', 'other_pets'],
      consider: ['shorter_lifespan', 'eyelid_problems', 'needs_company', 'sheds', 'frequent_grooming', 'drools'],
    },
  },
  {
    breed: 'dalmatian',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['dalmatian', 'dalmatinec', 'dalmatinski pas', 'dalmatiner', 'dalmatinka', 'carriage dog', 'firehouse dog', 'dalmatin'],
    sort_order: 480,
    suitability: {
      suits: ['active_family', 'large_home'],
      consider: ['long_daily_exercise', 'not_for_small_children', 'needs_company', 'sheds'],
    },
  },
  {
    breed: 'australian_cattle_dog',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['australian cattle dog', 'avstralski govedar', 'avstralski govedarski pes', 'cattle dog', 'blue heeler', 'red heeler', 'queensland heeler', 'heeler', 'acd'],
    sort_order: 490,
    suitability: {
      suits: ['active_family', 'family_pet'],
      consider: ['long_daily_exercise', 'needs_mental_stimulation', 'may_herd_children', 'sheds'],
    },
  },
  {
    breed: 'whippet',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['whippet', 'mali angleški hrt', 'mali angleski hrt', 'angleški hrt', 'angleski hrt', 'vipet', 'hrt', 'snap dog'],
    sort_order: 500,
    suitability: {
      suits: ['family_pet', 'children'],
      consider: ['sensitive_older_children', 'needs_company', 'chases_small_pets'],
    },
  },
  {
    breed: 'wirehaired_pointing_griffon',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['wirehaired pointing griffon', 'korthals griffon', 'korthals', 'griffon', 'griff', 'francoski ostrodlaki ptičar', 'francoski ostrodlaki pticar', 'ostrodlaki ptičar', 'ostrodlaki pticar', 'korthalsov grifon', 'grifon'],
    sort_order: 510,
    suitability: {
      suits: ['active_family', 'family_pet', 'children', 'large_home'],
      consider: ['long_daily_exercise', 'chases_small_pets'],
    },
  },
  {
    breed: 'chesapeake_bay_retriever',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['chesapeake bay retriever', 'chesapeake', 'chessie', 'chesapeake retriever', 'chesapeake bay retriver', 'chesapeake retriver'],
    sort_order: 520,
    suitability: {
      suits: ['active_family', 'family_pet', 'children', 'large_home'],
      consider: ['long_daily_exercise', 'needs_company', 'sheds'],
    },
  },
  {
    breed: 'samoyed',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['samoyed', 'samojed', 'sammy', 'samoyede', 'samojede', 'samojedski pes', 'samoyed dog'],
    sort_order: 530,
    suitability: {
      suits: ['active_family', 'family_pet', 'children'],
      consider: ['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'secure_fencing', 'may_herd_children', 'sheds', 'frequent_grooming', 'heat_sensitive', 'chases_small_pets'],
    },
  },
  {
    breed: 'scottish_terrier',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['scottish terrier', 'skotski terier', 'škotski terier', 'scottie', 'scotty', 'aberdeen terrier', 'scottish'],
    sort_order: 540,
    suitability: {
      suits: ['family_pet'],
      consider: ['frequent_grooming', 'needs_company'],
    },
  },
  {
    breed: 'weimaraner',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['weimaraner', 'weimarski ptičar', 'weimarski pticar', 'vajmarski ptičar', 'vajmarski pticar', 'weim'],
    sort_order: 550,
    suitability: {
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets'],
    },
  },
  {
    breed: 'italian_greyhound',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['italian greyhound', 'mali italijanski hrt', 'italijanski hrt', 'italijanski hrtek', 'piccolo levriero italiano', 'italian sighthound', 'iggy'],
    sort_order: 560,
    suitability: {
      suits: ['children', 'low_shedding'],
      consider: ['fragile_older_children', 'cold_sensitive'],
    },
  },
  {
    breed: 'giant_schnauzer',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['giant schnauzer', 'veliki šnavcer', 'veliki snavcer', 'riesenschnauzer', 'riesen', 'munich schnauzer'],
    sort_order: 570,
    suitability: {
      suits: ['active_family', 'family_pet', 'large_home', 'low_shedding'],
      consider: ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'frequent_grooming'],
    },
  },
  {
    breed: 'bloodhound',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['bloodhound', 'pes svetega huberta', 'pes sv. huberta', 'chien de saint-hubert', 'st. hubert hound', 'sleuth hound'],
    sort_order: 580,
    suitability: {
      suits: ['active_family', 'large_home'],
      consider: ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'sheds', 'drools', 'shorter_lifespan', 'eyelid_problems', 'chases_small_pets'],
    },
  },
  {
    breed: 'akita',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['akita', 'american akita', 'ameriška akita', 'ameriska akita', 'great japanese dog'],
    sort_order: 590,
    suitability: {
      suits: ['active_family'],
      consider: ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets'],
    },
  },
  {
    breed: 'cardigan_welsh_corgi',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['cardigan welsh corgi', 'welsh corgi cardigan', 'cardigan', 'corgi', 'welsh corgi', 'korgi', 'valizanski korgi', 'valižanski korgi', 'cardi'],
    sort_order: 600,
    suitability: {
      suits: [],
      consider: ['may_herd_children', 'sheds', 'frequent_grooming'],
    },
  },
  {
    breed: 'german_wirehaired_pointer',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['german wirehaired pointer', 'deutsch drahthaar', 'drahthaar', 'gwp', 'nemški ostrodlaki ptičar', 'nemski ostrodlaki pticar', 'drahtar'],
    sort_order: 610,
    suitability: {
      suits: ['active_family', 'family_pet', 'children', 'large_home'],
      consider: ['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'sheds', 'chases_small_pets'],
    },
  },
  {
    breed: 'saint_bernard',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['saint bernard', 'st. bernard', 'st bernard', 'st bernhardshund', 'bernhardiner', 'bernardinec', 'sveti bernard', 'pes svetega bernarda'],
    sort_order: 620,
    suitability: {
      suits: ['family_pet', 'children', 'large_home'],
      consider: ['shorter_lifespan', 'eyelid_problems', 'hips_hind_legs', 'heat_sensitive', 'needs_company', 'sheds', 'frequent_grooming', 'drools'],
    },
  },
  {
    breed: 'staffordshire_bull_terrier',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['staffordshire bull terrier', 'staffordshire', 'staffie', 'staffy', 'stafford', 'sbt', 'stafordširski bulterier', 'stafordsirski bulterier', 'staffordshirski bulterier'],
    sort_order: 630,
    suitability: {
      suits: ['family_pet', 'children'],
      consider: ['chews_when_bored', 'needs_company', 'sheds', 'chases_small_pets'],
    },
  },
  {
    breed: 'airedale_terrier',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['airedale terrier', 'airedale', 'king of terriers', 'waterside terrier', 'bingley terrier', 'airedalski terier', 'airedale terier', 'erdelterier'],
    sort_order: 640,
    suitability: {
      suits: ['family_pet', 'children', 'large_home', 'low_shedding'],
      consider: ['needs_mental_stimulation', 'needs_company', 'frequent_grooming'],
    },
  },
  {
    breed: 'russell_terrier',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['jack russell terrier', 'jack russell', 'russell terrier', 'jrt', 'jack', 'jack russell terier', 'jack rasel terier', 'džek rasel'],
    sort_order: 650,
    suitability: {
      suits: ['family_pet', 'first_time_owner', 'apartment'],
      consider: ['older_children', 'sheds', 'chases_small_pets'],
    },
  },
  {
    breed: 'bullmastiff',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['bullmastiff', 'bull mastiff', 'bulmastif', 'bul mastif', 'bulmastiff', "gamekeeper's night dog"],
    sort_order: 660,
    suitability: {
      suits: ['active_family', 'large_home'],
      consider: ['long_daily_exercise', 'short_muzzle_breathing', 'not_for_small_children', 'experienced_owner', 'needs_company', 'shorter_lifespan', 'drools', 'chases_small_pets'],
    },
  },
  {
    breed: 'chinese_crested',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['chinese crested', 'chinese crested dog', 'crested', 'hairless dog', 'powder puff', 'powderpuff', 'kitajski goli pes', 'kitajski copasti pes', 'kitajski čopasti pes', 'goli pes'],
    sort_order: 670,
    suitability: {
      suits: ['family_pet', 'children', 'apartment', 'other_pets'],
      consider: ['sun_protection', 'cold_sensitive'],
    },
  },
  {
    breed: 'nova_scotia_duck_tolling_retriever',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['nova scotia duck tolling retriever', 'nova scotia retriever', 'duck tolling retriever', 'toller', 'tolling retriever', 'nsdtr', 'novoškotski prinašalec', 'novoskotski prinasalec', 'novoškotski retriver', 'prinašalec rac'],
    sort_order: 680,
    suitability: {
      suits: ['family_pet', 'active_family', 'children'],
      consider: ['needs_mental_stimulation', 'sheds', 'frequent_grooming', 'chases_small_pets'],
    },
  },
  {
    breed: 'cairn_terrier',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['cairn terrier', 'cairn', 'cairnie', 'cairn terier', 'cairnski terier', 'kern terier', 'kernski terier'],
    sort_order: 690,
    suitability: {
      suits: ['family_pet', 'children', 'low_shedding'],
      consider: ['needs_mental_stimulation'],
    },
  },
  {
    breed: 'bull_terrier',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['bull terrier', 'english bull terrier', 'bully', 'bulterier', 'bul terier'],
    sort_order: 700,
    suitability: {
      suits: ['children'],
      consider: ['lively_older_children', 'needs_company', 'chases_small_pets'],
    },
  },
  {
    breed: 'miniature_pinscher',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['miniature pinscher', 'min pin', 'minpin', 'zwergpinscher', 'pritlikavi pinč', 'pritlikavi pinc', 'pinč', 'pinc', 'mini pinč'],
    sort_order: 710,
    suitability: {
      suits: ['family_pet', 'children'],
      consider: ['older_children', 'cold_sensitive'],
    },
  },
  {
    breed: 'pyrenean_mountain_dog',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['pyrenean mountain dog', 'great pyrenees', 'pyrenees', 'pyr', 'patou', 'chien de montagne des pyrenees', 'pirenejski gorski pes', 'pirenejski pes', 'pirenejec', 'pirenejski planšar'],
    sort_order: 720,
    suitability: {
      suits: ['children', 'large_home', 'other_pets'],
      consider: ['not_for_small_children', 'sheds', 'frequent_grooming', 'shorter_lifespan'],
    },
  },
  {
    breed: 'soft_coated_wheaten_terrier',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['soft coated wheaten terrier', 'wheaten terrier', 'wheaten', 'scwt', 'irish soft coated wheaten terrier', 'irski mehkodlaki pšenični terier', 'irski mehkodlaki psenicni terier', 'pšenični terier', 'psenicni terier'],
    sort_order: 730,
    suitability: {
      suits: ['family_pet', 'children', 'low_shedding'],
      consider: ['frequent_grooming', 'needs_company'],
    },
  },
  {
    breed: 'irish_wolfhound',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['irish wolfhound', 'wolfhound', 'cu faoil', 'irski volčji hrt', 'irski volcji hrt', 'volčji hrt', 'volcji hrt', 'irski hrt'],
    sort_order: 740,
    suitability: {
      suits: ['large_home'],
      consider: ['long_daily_exercise', 'shorter_lifespan', 'not_for_small_children', 'needs_company', 'frequent_grooming', 'chases_small_pets'],
    },
  },
  {
    breed: 'american_staffordshire_terrier',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['american staffordshire terrier', 'amstaff', 'am staff', 'staffordshire terrier', 'american stafford', 'ameriški staffordshirski terier', 'ameriski staffordshirski terier', 'staffordshirski terier'],
    sort_order: 750,
    suitability: {
      suits: ['children'],
      consider: ['needs_company', 'chases_small_pets'],
    },
  },
  {
    breed: 'lagotto_romagnolo',
    species: 'dog',
    premium: true,
    free_plan_allowed: false,
    challenge_allowed: true,
    search_keywords: ['lagotto romagnolo', 'lagotto', 'lagotti', 'romagna water dog', 'truffle dog', 'pes za tartufe', 'tartufar'],
    sort_order: 760,
    suitability: {
      suits: ['family_pet', 'low_shedding'],
      consider: ['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'frequent_grooming'],
    },
  },
];

/**
 * Today's dogs (see {@link FALLBACK_BREEDS}). No colours offline (M5-R21): the labels come from
 * the server in the app language, so without the catalogue nothing is sent and the server picks
 * the colour by the breed's weights.
 */
export const FALLBACK_CATALOGUE: BreedCatalogue = {
  species: ['dog'],
  breeds: FALLBACK_BREEDS.map((b) => ({ ...b, colours: NO_COLOURS })),
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
    // Only a paid breed has a colour choice (the server sends [] for a free one anyway).
    colours: premium ? readColours(o.colours) : NO_COLOURS,
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

/**
 * How many breeds the list shows before "Pokaži vse pasme" (David 2026-10-12): the catalogue
 * grows to hundreds of breeds, so the phone shows the first few (server order = free breed,
 * then the most popular) and search always covers the whole catalogue.
 */
export const BREED_PREVIEW_COUNT = 4;

/**
 * The rows the breed list shows. A search (or "Pokaži vse") shows every match; otherwise
 * the first {@link BREED_PREVIEW_COUNT}, plus the selected breed when it sits further down
 * (so a choice made through search never disappears from the list).
 */
export function visibleBreeds(
  shown: readonly CatalogueBreed[],
  query: string,
  expanded: boolean,
  selected: PetBreed | null,
  limit: number = BREED_PREVIEW_COUNT,
): CatalogueBreed[] {
  if (expanded || foldForSearch(query) !== '' || shown.length <= limit) return [...shown];
  const head = shown.slice(0, limit);
  const picked = selected === null ? undefined : shown.slice(limit).find((b) => b.breed === selected);
  return picked ? [...head, picked] : head;
}

/** Is `breed` a non-pedigree free breed (mutt / domestic cat)? Unknown breeds → false. */
export function isNonPedigreeBreed(breed: PetBreed | null, breeds: readonly CatalogueBreed[]): boolean {
  const entry = breed === null ? undefined : breeds.find((b) => b.breed === breed);
  return entry !== undefined && entry.free_plan_allowed && !entry.premium;
}

/**
 * The breed the origin applies to: the free plan always takes the species' free breed, the
 * challenge the chosen breed; before a plan nothing is decided yet (null).
 */
export function originBreed(choice: PickerChoice, breeds: readonly CatalogueBreed[]): PetBreed | null {
  if (choice.plan === null || choice.species === null) return null;
  const ofSpecies = breeds.filter((b) => b.species === choice.species);
  return choice.plan === 'free' ? freeBreedOf(ofSpecies) : choice.breed;
}

/**
 * Origins the parent can pick (David 2026-10-12): a mutt or a domestic cat is never bought
 * from a breeder — breeders only raise pedigree animals — so a non-pedigree breed is adoption
 * only. Before a plan / breed is decided, both origins.
 */
export function originsFor(choice: PickerChoice, breeds: readonly CatalogueBreed[]): readonly PetOrigin[] {
  return isNonPedigreeBreed(originBreed(choice, breeds), breeds) ? ['adopted'] : PICKER_ORIGINS;
}

/**
 * The only option of a required choice, or null when there are none or several. A required
 * choice with exactly one option is preselected (tester feedback, David 2026-10-25) — the
 * parent never has to tap the only possible answer.
 */
export function soleOption<T>(options: readonly T[]): T | null {
  return options.length === 1 ? options[0] : null;
}

/**
 * The choice with an origin the breed allows (David 2026-10-25, tester feedback):
 * - a single possible origin (the free mutt / domestic cat → adoption) is preselected and
 *   marked `originAuto`;
 * - when several origins become possible again (switch to a paid breed / back to no plan)
 *   an origin the app preselected is cleared, so the parent decides — origin has no default;
 * - an origin the parent tapped that no longer fits is cleared, never swapped silently.
 */
export function withValidOrigin(choice: PickerChoice, breeds: readonly CatalogueBreed[]): PickerChoice {
  const options = originsFor(choice, breeds);
  const only = soleOption(options);
  if (only !== null) return choice.origin === only ? choice : { ...choice, origin: only, originAuto: true };
  if (choice.originAuto === true) return { ...choice, origin: null, originAuto: false };
  if (choice.origin === null || options.includes(choice.origin)) return choice;
  return { ...choice, origin: null };
}

/** The choice after the parent taps an origin (a deliberate choice, never `originAuto`). */
export function choiceWithOrigin(choice: PickerChoice, origin: PetOrigin): PickerChoice {
  return { ...choice, origin, originAuto: false };
}

/** What the parent has picked so far (species, plan, origin and age have no default — a deliberate choice). */
export interface PickerChoice {
  species: PetSpecies | null;
  plan: PetPlanType | null;
  breed: PetBreed | null;
  origin: PetOrigin | null;
  age_stage: LifeStage | null;
  /**
   * M5-R21: the coat colour key of the chosen paid breed (null = none: a breed without
   * colours, or not chosen yet — {@link withValidColour} then preselects the first). Optional:
   * a choice from before M5-R21 simply has none.
   */
  colour?: string | null;
  /**
   * True when the app preselected the origin because it was the only one (the free mutt /
   * domestic cat → adoption, {@link withValidOrigin}); it is cleared again when the choice
   * offers several origins. Optional: absent = the parent's own tap (or none).
   */
  originAuto?: boolean;
}

export const INITIAL_PICKER_CHOICE: PickerChoice = { species: null, plan: null, breed: null, origin: null, age_stage: null };

/**
 * The colours the parent chooses from for this choice: those of the chosen paid breed on the
 * challenge; none on the free plan, before a plan or for a breed without colours.
 */
export function coloursFor(choice: PickerChoice, breeds: readonly CatalogueBreed[]): readonly BreedColour[] {
  if (choice.plan !== 'challenge' || choice.breed === null) return NO_COLOURS;
  return breeds.find((b) => b.breed === choice.breed)?.colours ?? NO_COLOURS;
}

/**
 * The choice with a colour its breed offers (M5-R21, David 2026-10-14): a kept colour that
 * still fits stays; otherwise the breed's FIRST colour is preselected, so the colour never
 * blocks the PIN and a breed change never carries a colour of another breed. No colours → null.
 */
export function withValidColour(choice: PickerChoice, breeds: readonly CatalogueBreed[]): PickerChoice {
  const colours = coloursFor(choice, breeds);
  const current = choice.colour ?? null;
  const colour = colours.some((c) => c.key === current) ? current : (colours[0]?.key ?? null);
  return colour === current ? choice : { ...choice, colour };
}

/**
 * Plan and breed always agree (fix 2026-10-12, David: "Mešanček" was ticked while neither
 * plan was): the breed belongs to the plan, so without a plan there is no breed either —
 * the plan has no default (PAYMENTS_SPEC, M5-F03). A choice with a plan is returned as is.
 */
export function withConsistentPlan(choice: PickerChoice): PickerChoice {
  return choice.plan === null && choice.breed !== null ? { ...choice, breed: null } : choice;
}

/**
 * The choice after the parent taps a pickable breed. Before a plan is chosen the breed
 * decides it: the species' free breed (mutt / domestic cat) → free plan, a paid breed →
 * challenge (M5-F03), so a breed is never selected without its plan. With a plan already
 * chosen only the breed changes (a breed that plan locks never reaches here).
 */
export function choiceWithBreed(choice: PickerChoice, breed: PetBreed, breeds: readonly CatalogueBreed[]): PickerChoice {
  if (choice.plan !== null) return { ...choice, breed };
  const entry = breeds.find((b) => b.breed === breed);
  if (!entry) return choice;
  return { ...choice, breed, plan: entry.free_plan_allowed ? 'free' : 'challenge' };
}

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
 * re-chosen inside the new species (its free breed, or the first paid one on the challenge;
 * none while no plan is chosen).
 */
export function choiceWithSpecies(
  choice: PickerChoice,
  species: PetSpecies,
  serverLocked: readonly PetBreed[],
  breeds: readonly CatalogueBreed[],
): PickerChoice {
  if (choice.species === species && choice.breed !== null && breeds.some((b) => b.breed === choice.breed)) return choice;
  // No plan yet → no breed either (never a ticked free breed next to an empty plan choice).
  if (choice.plan === null) return { ...choice, species, breed: null };
  return choiceWithPlan({ ...choice, species, breed: freeBreedOf(breeds) }, choice.plan, serverLocked, breeds);
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
  if (!originsFor(choice, ofSpecies).includes(choice.origin)) return null;
  const profile: NewPetProfile = { species: choice.species, breed, origin: choice.origin, age_stage: choice.age_stage, plan: choice.plan };
  // M5-R21: a breed with colours always sends one it offers (also the only one, shown as fixed).
  const colours = coloursFor({ ...choice, breed }, ofSpecies);
  if (colours.length === 0) return profile;
  const colour = colours.find((c) => c.key === choice.colour)?.key;
  return colour === undefined ? null : { ...profile, colour };
}

/** The label of the profile's colour (summary), null without a colour or an unknown key. */
export function colourLabel(profile: NewPetProfile, breeds: readonly CatalogueBreed[]): string | null {
  if (profile.colour === undefined) return null;
  return breeds.find((b) => b.breed === profile.breed)?.colours.find((c) => c.key === profile.colour)?.label ?? null;
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

/**
 * What the free pet (mutt / domestic cat) lacks compared with a 12-week challenge breed — the
 * notice under the plans when the free plan is chosen (tester feedback, David 2026-10-25: the
 * free mutt is a limited trial, so testers don't take it for the whole app). ONLY facts the
 * code enforces:
 * - `program`: no 12-week progress (CareScoreService `progress` null for a free pet) and no
 *   certificate (ChallengeCertificateService::processPet skips free pets);
 * - `history`: the parent's history is the last 7 days (FamilyDashboardService::FREE_HISTORY_DAYS);
 * - `origin`: adoption only ({@link originsFor}; server GeneratePinRequest stores a bought free
 *   breed as adopted) and no coat colour ({@link coloursFor} — challenge breeds only);
 * - `media`: the basic video tier, idle + sleeping (MediaEntitlementService::tierFor,
 *   config media.video_states.basic) — no other state / behaviour clips, no state-media key
 *   images and transitions, no school / game phase clips (full tier only);
 * - `care` (dogs only): the mutt has no grooming row (BreedStageParamsSeeder::DOG_GROOMING)
 *   and no breed care task row (DOG_CARE_TASKS) → no brushing, no teeth / ears / folds / nails.
 */
export type FreeTrialLimit = 'program' | 'history' | 'origin' | 'media' | 'care';

export function freeTrialLimits(species: PetSpecies): readonly FreeTrialLimit[] {
  return species === 'dog' ? ['program', 'history', 'origin', 'media', 'care'] : ['program', 'history', 'origin', 'media'];
}

export interface FreeTrialText {
  title: string;
  intro: string;
  items: string[];
  cta: string;
}

/** The free-trial notice of a species (cat texts override title, intro, origin and media). */
export function freeTrialText(species: PetSpecies): FreeTrialText {
  const F = PICKER_STRINGS.freeTrial;
  const C = PICKER_STRINGS.cat.freeTrial;
  const cat = species === 'cat';
  const item = (limit: FreeTrialLimit): string => {
    if (cat && limit === 'origin') return C.items.origin;
    if (cat && limit === 'media') return C.items.media;
    return F.items[limit];
  };
  return {
    title: cat ? C.title : F.title,
    intro: cat ? C.intro : F.intro,
    items: freeTrialLimits(species).map(item),
    cta: F.cta,
  };
}
