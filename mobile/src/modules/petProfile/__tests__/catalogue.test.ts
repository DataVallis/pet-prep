/**
 * M5-R06-02: the server-driven picker catalogue (`GET /api/breeds`), search and the
 * species-aware choice rules.
 */
import { showableSpecies } from '@/config/features';
import { i18n } from '@/i18n';
import {
  breedsOf,
  choiceWithPlan,
  considerLabel,
  CONSIDER_TAGS,
  hasSuitability,
  choiceWithSpecies,
  completeChoice,
  FALLBACK_CATALOGUE,
  freeBreedOf,
  INITIAL_PICKER_CHOICE,
  lockedBreedsFor,
  readBreedCatalogue,
  readSuitability,
  searchBreeds,
  suitabilityA11y,
  suitsLabel,
  SUITS_TAGS,
  type BreedCatalogue,
} from '@/modules/petProfile/picker';

function entry(breed: string, species: string, premium: boolean, sort_order = 0, keywords: string[] = []) {
  return {
    breed,
    slug: breed.replace('_', '-'),
    species,
    premium,
    free_plan_allowed: !premium,
    challenge_allowed: premium,
    label_key: `breeds.${breed}`,
    search_keywords: keywords,
    sort_order,
  };
}

/** The server with cats switched on and `features[]=species_cat` (seeded keywords). */
const BOTH_RAW = {
  species: ['dog', 'cat'],
  breeds: [
    entry('maine_coon', 'cat', true, 10, ['maine coon', 'mejnkun', 'mainska']),
    entry('border_collie', 'dog', true, 10, ['border collie', 'koli']),
    entry('domestic_cat', 'cat', false, 0, ['domača mačka', 'domaca macka', 'mešanka', 'mesanka', 'domestic cat', 'moggy']),
    entry('mutt', 'dog', false, 0, ['mešanček', 'mesancek', 'mutt', 'mixed']),
  ],
};

/** A build that can show cats (`CAT_UI_READY` on). */
const CATS_SHOWABLE = ['dog', 'cat'] as const;

function both(): BreedCatalogue {
  const catalogue = readBreedCatalogue(BOTH_RAW, CATS_SHOWABLE);
  if (!catalogue) throw new Error('catalogue');
  return catalogue;
}

describe('readBreedCatalogue', () => {
  it('orders species as sent, the free breed first, then paid by sort_order', () => {
    const catalogue = both();
    expect(catalogue.species).toEqual(['dog', 'cat']);
    expect(catalogue.breeds.map((b) => b.breed)).toEqual(['mutt', 'border_collie', 'domestic_cat', 'maine_coon']);
    expect(breedsOf(catalogue, 'cat').map((b) => b.breed)).toEqual(['domestic_cat', 'maine_coon']);
    expect(breedsOf(catalogue, null)).toEqual([]);
  });

  it('cats hidden (production today): only dogs', () => {
    const catalogue = readBreedCatalogue({ species: ['dog'], breeds: BOTH_RAW.breeds.filter((b) => b.species === 'dog') });
    expect(catalogue?.species).toEqual(['dog']);
    expect(catalogue?.breeds.map((b) => b.breed)).toEqual(['mutt', 'border_collie']);
  });

  it('drops unknown breeds, wrong species pairs and species without breeds; null when nothing usable', () => {
    const catalogue = readBreedCatalogue({
      species: ['dog', 'cat', 'fish'],
      breeds: [entry('mutt', 'dog', false), entry('sphynx', 'cat', true), entry('maine_coon', 'dog', true), 'nonsense'],
    }, CATS_SHOWABLE);
    expect(catalogue).toEqual({ species: ['dog'], breeds: [expect.objectContaining({ breed: 'mutt' })] });
    expect(readBreedCatalogue({ species: [], breeds: [] })).toBeNull();
    expect(readBreedCatalogue({ species: ['cat'], breeds: [entry('mutt', 'dog', false)] }, CATS_SHOWABLE)).toBeNull();
    expect(readBreedCatalogue(null)).toBeNull();
    expect(readBreedCatalogue('<html>')).toBeNull();
  });

  it('QA #94 m3: a build without the cat UI drops cats even if the server sends them (misconfiguration)', () => {
    const DOGS_ONLY = showableSpecies(false);
    const catalogue = readBreedCatalogue(BOTH_RAW, DOGS_ONLY);
    expect(catalogue?.species).toEqual(['dog']);
    expect(catalogue?.breeds.map((b) => b.breed)).toEqual(['mutt', 'border_collie']);
    // Cats only → nothing such a build can show → fallback (null).
    expect(readBreedCatalogue({ species: ['cat'], breeds: BOTH_RAW.breeds }, DOGS_ONLY)).toBeNull();
    expect(showableSpecies(false)).toEqual(['dog']);
    expect(showableSpecies(true)).toEqual(['dog', 'cat']);
  });

  it('M5-R06-09: this build shows cats (CAT_UI_READY on) — the server decides whether they are offered', () => {
    expect(showableSpecies()).toEqual(['dog', 'cat']);
    // Server flag on → dogs and cats.
    expect(readBreedCatalogue(BOTH_RAW)?.species).toEqual(['dog', 'cat']);
    // Server flag off (PETPREP_CATS_ENABLED=false): the server sends dogs only → exactly today's dog picker.
    const dogsOnly = readBreedCatalogue({ species: ['dog'], breeds: BOTH_RAW.breeds.filter((b) => b.species === 'dog') });
    expect(dogsOnly?.species).toEqual(['dog']);
    expect(dogsOnly?.breeds.map((b) => b.breed)).toEqual(['mutt', 'border_collie']);
  });

  it('a breed listed for a species that is not offered is ignored (never a hidden cat)', () => {
    const catalogue = readBreedCatalogue({ species: ['dog'], breeds: BOTH_RAW.breeds });
    expect(catalogue?.breeds.some((b) => b.species === 'cat')).toBe(false);
  });

  it('the fallback is today’s seeded dogs (M5-R10: + Labrador, sort 20; M5-R10-02: + Golden, sort 30; M5-R10-03: + French Bulldog, sort 40; M5-R10-04: + German Shepherd, sort 50; M5-R10-05: + Cavalier, sort 60; M5-R10-06: + Beagle, sort 70; M5-R10-07: + Standard Poodle, sort 80; M5-R10-08: + Dachshund, sort 90; M5-R10-09: + Australian Shepherd, sort 100; M5-R10-10: + Havanese, sort 110; M5-R10-11: + West Highland White Terrier, sort 120; M5-R10-12: + Bernese Mountain Dog, sort 130; M5-R10-13: + Siberian Husky, sort 140; M5-R10-19: + Rottweiler, sort 150; M5-R10-20: + Bulldog, sort 160; M5-R10-21: + German Shorthaired Pointer, sort 170; M5-R10-22: + Yorkshire Terrier, sort 180; M5-R10-23: + Cane Corso, sort 190; M5-R10-24: + Pembroke Welsh Corgi, sort 200; M5-R10-25: + Dobermann, sort 210; M5-R10-26: + Boxer, sort 220; M5-R10-27: + Miniature Schnauzer, sort 230; M5-R10-28: + Shih Tzu, sort 240), never a cat', () => {
    expect(FALLBACK_CATALOGUE.species).toEqual(['dog']);
    expect(FALLBACK_CATALOGUE.breeds.map((b) => b.breed)).toEqual([
      'mutt',
      'border_collie',
      'labrador_retriever',
      'golden_retriever',
      'french_bulldog',
      'german_shepherd',
      'cavalier_king_charles_spaniel',
      'beagle',
      'standard_poodle',
      'dachshund',
      'australian_shepherd',
      'havanese',
      'west_highland_white_terrier',
      'bernese_mountain_dog',
      'siberian_husky',
      'rottweiler',
      'bulldog',
      'german_shorthaired_pointer',
      'yorkshire_terrier',
      'cane_corso',
      'pembroke_welsh_corgi',
      'dobermann',
      'boxer',
      'miniature_schnauzer',
      'shih_tzu',
      'great_dane',
      'pomeranian',
      'boston_terrier',
      'miniature_american_shepherd',
      'chihuahua',
      'english_springer_spaniel',
    ]);
    expect(freeBreedOf(FALLBACK_CATALOGUE.breeds)).toBe('mutt');
    const lab = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'labrador_retriever');
    expect(lab).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 20 }),
    );
    expect(lab?.search_keywords).toEqual(expect.arrayContaining(['labradorec', 'labrador retriever', 'prinasalec']));
    // Mirrors config/breed_suitability.php.
    expect(lab?.suitability).toEqual({
      suits: ['active_family', 'family_pet', 'large_home', 'other_pets'],
      consider: ['sheds', 'long_daily_exercise', 'food_motivated_weight'],
    });
    const golden = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'golden_retriever');
    expect(golden).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 30 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php.
    expect(golden?.search_keywords).toEqual(['golden', 'golden retriever', 'zlati prinašalec', 'zlati prinasalec', 'retriever']);
    expect(golden?.suitability).toEqual({
      suits: ['active_family', 'family_pet', 'children', 'first_time_owner', 'large_home', 'other_pets'],
      consider: ['long_daily_exercise', 'sheds', 'food_motivated_weight', 'frequent_grooming'],
    });
    const frenchie = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'french_bulldog');
    expect(frenchie).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 40 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (David 2026-10-10).
    expect(frenchie?.search_keywords).toEqual(['french bulldog', 'frenchie', 'french', 'bulldog', 'francoski buldog', 'buldog']);
    expect(frenchie?.suitability).toEqual({ suits: ['apartment', 'family_pet', 'children'], consider: ['brachycephalic_breathing', 'chases_small_pets'] });
    const shepherd = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'german_shepherd');
    expect(shepherd).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 50 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-04, runbook rules).
    expect(shepherd?.search_keywords).toEqual(['german shepherd', 'german shepherd dog', 'gsd', 'alsatian', 'nemški ovčar', 'nemski ovcar', 'ovčar', 'ovcar']);
    expect(shepherd?.suitability).toEqual({
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'sheds', 'frequent_grooming', 'chews_when_bored', 'hips_hind_legs'],
    });
    const cavalier = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'cavalier_king_charles_spaniel');
    expect(cavalier).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 60 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-05, runbook rules).
    expect(cavalier?.search_keywords).toEqual(['cavalier king charles spaniel', 'cavalier', 'king charles', 'ckcs', 'spaniel', 'kavalir king charles španjel', 'kavalir king charles spanjel', 'kavalir', 'španjel', 'spanjel']);
    expect(cavalier?.suitability).toEqual({ suits: ['apartment', 'family_pet', 'children'], consider: ['sheds', 'frequent_grooming', 'heart_and_spine'] });
    const beagle = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'beagle');
    expect(beagle).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 70 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-06, runbook rules).
    expect(beagle?.search_keywords).toEqual(['beagle', 'bigl']);
    expect(beagle?.suitability).toEqual({ suits: ['family_pet'], consider: ['sheds', 'chews_when_bored'] });
    const poodle = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'standard_poodle');
    expect(poodle).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 80 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-07, runbook rules).
    expect(poodle?.search_keywords).toEqual(['poodle (standard)', 'standard poodle', 'poodle', 'veliki koder', 'koder', 'veliki pudelj', 'pudelj', 'standardni pudelj']);
    expect(poodle?.suitability).toEqual({ suits: ['children', 'large_home', 'other_pets', 'low_shedding'], consider: ['frequent_grooming'] });
    const dachshund = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'dachshund');
    expect(dachshund).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 90 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-08, runbook rules; back_spine = welfare rule).
    expect(dachshund?.search_keywords).toEqual(['dachshund', 'sausage dog', 'teckel', 'jazbečar', 'jazbecar']);
    expect(dachshund?.suitability).toEqual({ suits: ['children'], consider: ['back_spine', 'sheds', 'needs_mental_stimulation', 'chases_small_pets'] });
    const aussie = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'australian_shepherd');
    expect(aussie).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 100 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-09, runbook rules; no welfare chip).
    expect(aussie?.search_keywords).toEqual(['australian shepherd', 'aussie', 'avstralski ovčar', 'avstralski ovcar']);
    expect(aussie?.suitability).toEqual({
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'needs_mental_stimulation', 'may_herd_children', 'chews_when_bored', 'sheds', 'frequent_grooming'],
    });
    const havanese = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'havanese');
    expect(havanese).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 110 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-10, runbook rules; no welfare chip, never "hypoallergenic").
    expect(havanese?.search_keywords).toEqual(['havanese', 'havanski bišon', 'havanski bison', 'bichon havanais', 'havanez']);
    expect(havanese?.suitability).toEqual({ suits: ['apartment', 'family_pet', 'children', 'low_shedding'], consider: ['frequent_grooming'] });
    const westie = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'west_highland_white_terrier');
    expect(westie).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 120 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-11, runbook rules; welfare chip sensitive_skin).
    expect(westie?.search_keywords).toEqual(['west highland white terrier', 'westie', 'westy', 'zahodnoškotski beli terier', 'zahodnoskotski beli terier', 'west highland terier', 'vestie']);
    expect(westie?.suitability).toEqual({
      suits: ['apartment', 'family_pet', 'children'],
      consider: ['sensitive_skin', 'sheds', 'frequent_grooming', 'chews_when_bored', 'chases_small_pets'],
    });
    const berner = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'bernese_mountain_dog');
    expect(berner).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 130 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-12, runbook rules; new chip shorter_lifespan).
    expect(berner?.search_keywords).toEqual(['bernese mountain dog', 'berner', 'berner sennenhund', 'bernese', 'bernski planšarski pes', 'bernski plansarski pes', 'bernski planšar', 'bernski plansar']);
    expect(berner?.suitability).toEqual({
      suits: ['family_pet', 'large_home'],
      consider: ['shorter_lifespan', 'sheds', 'frequent_grooming'],
    });
    const husky = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'siberian_husky');
    expect(husky).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 140 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-13, runbook rules; new chip secure_fencing).
    expect(husky?.search_keywords).toEqual(['siberian husky', 'husky', 'huskie', 'sibirski haski', 'haski', 'sibirski husky', 'sibirec']);
    expect(husky?.suitability).toEqual({
      suits: ['active_family', 'large_home'],
      consider: ['long_daily_exercise', 'secure_fencing', 'chews_when_bored', 'sheds', 'frequent_grooming', 'chases_small_pets'],
    });
    const rottweiler = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'rottweiler');
    expect(rottweiler).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 150 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-19, runbook rules; new chip experienced_owner).
    expect(rottweiler?.search_keywords).toEqual(['rottweiler', 'rottie', 'rotty', 'rott', 'rotvajler', 'rotvajlerka', 'rotweiler', 'rotvailer']);
    expect(rottweiler?.suitability).toEqual({
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'experienced_owner', 'hips_hind_legs', 'shorter_lifespan', 'sheds'],
    });
    const bulldog = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'bulldog');
    expect(bulldog).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 160 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-20, runbook rules; flat-faced welfare chip).
    expect(bulldog?.search_keywords).toEqual(['bulldog', 'english bulldog', 'british bulldog', 'angleški buldog', 'angleski buldog', 'buldog']);
    expect(bulldog?.suitability).toEqual({
      suits: ['family_pet', 'other_pets'],
      consider: ['brachycephalic_breathing', 'sensitive_skin', 'shorter_lifespan', 'sheds'],
    });
    const pointer = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'german_shorthaired_pointer');
    expect(pointer).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 170 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-21, runbook rules; no welfare chip).
    expect(pointer?.search_keywords).toEqual(['german shorthaired pointer', 'german short-haired pointer', 'german pointer', 'gsp', 'shorthair', 'deutsch kurzhaar', 'kurzhaar', 'nemški kratkodlaki ptičar', 'nemski kratkodlaki pticar', 'kratkodlaki ptičar', 'kratkodlaki pticar', 'ptičar', 'pticar']);
    expect(pointer?.suitability).toEqual({
      suits: ['active_family', 'large_home'],
      consider: ['long_daily_exercise', 'sheds'],
    });
    const yorkie = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'yorkshire_terrier');
    expect(yorkie).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 180 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-22, runbook rules; new chip fragile_older_children).
    expect(yorkie?.search_keywords).toEqual(['yorkshire terrier', 'yorkshire', 'yorkie', 'yorki', 'yorkshirski terier', 'jorkširski terier', 'jorksirski terier', 'jorki']);
    expect(yorkie?.suitability).toEqual({
      suits: ['apartment', 'low_shedding'],
      consider: ['fragile_older_children', 'frequent_grooming'],
    });
    const corso = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'cane_corso');
    expect(corso).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 190 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-23, runbook rules; new chips not_for_small_children, drools).
    expect(corso?.search_keywords).toEqual(['cane corso', 'cane corso italiano', 'italian cane corso', 'italian corso dog', 'corso', 'italijanski cane corso', 'korzo', 'kane korso']);
    expect(corso?.suitability).toEqual({
      suits: ['active_family'],
      consider: ['long_daily_exercise', 'not_for_small_children', 'experienced_owner', 'needs_mental_stimulation', 'shorter_lifespan', 'sheds', 'drools'],
    });
    const dobermann = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'dobermann');
    expect(dobermann).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 210 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-25, runbook rules).
    expect(dobermann?.search_keywords).toEqual(['dobermann', 'doberman', 'doberman pinscher', 'dobie', 'dobermann pinscher']);
    expect(dobermann?.suitability).toEqual({
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'not_for_small_children', 'experienced_owner', 'needs_mental_stimulation', 'sheds', 'chases_small_pets'],
    });
    const boxer = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'boxer');
    expect(boxer).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 220 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-26, runbook rules; new tag needs_company).
    expect(boxer?.search_keywords).toEqual(['boxer', 'boksar', 'deutscher boxer']);
    expect(boxer?.suitability).toEqual({
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'short_muzzle_breathing', 'not_for_small_children', 'experienced_owner', 'needs_company', 'chews_when_bored', 'sheds', 'chases_small_pets'],
    });
    const schnauzer = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'miniature_schnauzer');
    expect(schnauzer).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 230 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-27, runbook rules; no new tag).
    expect(schnauzer?.search_keywords).toEqual(['miniature schnauzer', 'pritlikavi šnavcer', 'pritlikavi snavcer', 'mali šnavcer', 'zwergschnauzer', 'schnauzer', 'šnavcer', 'mini schnauzer']);
    expect(schnauzer?.suitability).toEqual({ suits: ['apartment', 'children', 'low_shedding'], consider: ['frequent_grooming'] });
    const shihTzu = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'shih_tzu');
    expect(shihTzu).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 240 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-28, runbook rules; no new tag).
    expect(shihTzu?.search_keywords).toEqual(['shih tzu', 'shih-tzu', 'shihtzu', 'ši-cu', 'šicu', 'ši cu', 'sicu']);
    expect(shihTzu?.suitability).toEqual({ suits: ['apartment', 'family_pet', 'first_time_owner'], consider: ['frequent_grooming', 'short_muzzle_breathing'] });
    const greatDane = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'great_dane');
    expect(greatDane).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 250 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-29, runbook rules; new welfare tag eyelid_problems).
    expect(greatDane?.search_keywords).toEqual(['great dane', 'nemška doga', 'nemska doga', 'doga', 'deutsche dogge', 'dogge', 'dane']);
    expect(greatDane?.suitability).toEqual({
      suits: ['active_family', 'family_pet', 'large_home'],
      consider: ['long_daily_exercise', 'not_for_small_children', 'shorter_lifespan', 'hips_hind_legs', 'eyelid_problems', 'chews_when_bored', 'sheds', 'drools', 'chases_small_pets'],
    });
    const pomeranian = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'pomeranian');
    expect(pomeranian).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 260 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-30, runbook rules; new tag older_children).
    expect(pomeranian?.search_keywords).toEqual(['pomeranian', 'pomeranec', 'pom', 'nemški špic', 'nemski spic', 'german toy spitz', 'toy spitz']);
    expect(pomeranian?.suitability).toEqual({ suits: ['apartment', 'family_pet', 'other_pets'], consider: ['older_children', 'frequent_grooming', 'sheds'] });
    const boston = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'boston_terrier');
    expect(boston).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 270 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-31, runbook rules; flat-faced chip first as the French Bulldog / Bulldog; no new tag).
    expect(boston?.search_keywords).toEqual(['boston terrier', 'bostonski terier', 'boston', 'american gentleman']);
    expect(boston?.suitability).toEqual({ suits: ['apartment', 'family_pet', 'children'], consider: ['brachycephalic_breathing', 'needs_company'] });
    const mas = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'miniature_american_shepherd');
    expect(mas).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 280 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-32, runbook rules; no new tag).
    expect(mas?.search_keywords).toEqual(['miniature american shepherd', 'mali ameriški ovčar', 'mali ameriski ovcar', 'mini american', 'mini aussie', 'miniature australian shepherd']);
    expect(mas?.suitability).toEqual({
      suits: ['family_pet', 'children', 'other_pets'],
      consider: ['needs_mental_stimulation', 'may_herd_children', 'needs_company', 'sheds'],
    });
    const chi = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'chihuahua');
    expect(chi).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 290 }));
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-33, runbook rules; no new tag).
    expect(chi?.search_keywords).toEqual(['chihuahua', 'čivava', 'civava', 'chi']);
    expect(chi?.suitability).toEqual({ suits: ['apartment', 'other_pets'], consider: ['older_children', 'needs_company', 'sheds'] });
    const ess = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'english_springer_spaniel');
    expect(ess).toEqual(expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 300 }));
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-34, runbook rules; no new tag).
    expect(ess?.search_keywords).toEqual(['english springer spaniel', 'angleški springer španjel', 'angleski springer spanjel', 'springer spaniel', 'springer španjel', 'springer']);
    expect(ess?.suitability).toEqual({
      suits: ['active_family', 'family_pet', 'children', 'other_pets'],
      consider: ['long_daily_exercise', 'needs_company', 'sheds', 'frequent_grooming'],
    });
    const corgi = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'pembroke_welsh_corgi');
    expect(corgi).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 200 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-24, runbook rules; no suits tag has a source).
    expect(corgi?.search_keywords).toEqual(['pembroke welsh corgi', 'welsh corgi pembroke', 'pembroke', 'corgi', 'welsh corgi', 'korgi', 'valizanski korgi', 'valižanski korgi']);
    expect(corgi?.suitability).toEqual({
      suits: [],
      consider: ['may_herd_children', 'sheds', 'frequent_grooming'],
    });
    expect(FALLBACK_CATALOGUE.breeds[0].suitability).toEqual({ suits: [], consider: [] });
    // The fallback is already in picker order.
    expect(readBreedCatalogue(FALLBACK_CATALOGUE)?.breeds.map((b) => b.breed)).toEqual([
      'mutt',
      'border_collie',
      'labrador_retriever',
      'golden_retriever',
      'french_bulldog',
      'german_shepherd',
      'cavalier_king_charles_spaniel',
      'beagle',
      'standard_poodle',
      'dachshund',
      'australian_shepherd',
      'havanese',
      'west_highland_white_terrier',
      'bernese_mountain_dog',
      'siberian_husky',
      'rottweiler',
      'bulldog',
      'german_shorthaired_pointer',
      'yorkshire_terrier',
      'cane_corso',
      'pembroke_welsh_corgi',
      'dobermann',
      'boxer',
      'miniature_schnauzer',
      'shih_tzu',
      'great_dane',
      'pomeranian',
      'boston_terrier',
      'miniature_american_shepherd',
      'chihuahua',
      'english_springer_spaniel',
    ]);
  });
});

describe('suitability tags (M5-R10)', () => {
  const withSuitability = (suitability: unknown) =>
    readBreedCatalogue({ species: ['dog'], breeds: [entry('mutt', 'dog', false), { ...entry('labrador_retriever', 'dog', true, 20), suitability }] })
      ?.breeds[1].suitability;

  it('reads the known tags in the server order', () => {
    expect(withSuitability({ suits: ['children', 'active_family'], consider: ['sheds'] })).toEqual({
      suits: ['children', 'active_family'],
      consider: ['sheds'],
    });
  });

  it('drops unknown keys, wrong kinds, non-strings and duplicates', () => {
    expect(
      withSuitability({
        suits: ['active_family', 'hypoallergenic', 'sheds', 42, null, 'active_family'],
        consider: ['long_daily_exercise', 'children', 'brand_new_tag'],
      }),
    ).toEqual({ suits: ['active_family'], consider: ['long_daily_exercise'] });
    expect(readSuitability({ suits: ['hypoallergenic'], consider: [] })).toEqual({ suits: [], consider: [] });
  });

  it('missing or malformed → no tags (older server, mutt, cats)', () => {
    expect(withSuitability(undefined)).toEqual({ suits: [], consider: [] });
    expect(withSuitability(null)).toEqual({ suits: [], consider: [] });
    expect(withSuitability('active_family')).toEqual({ suits: [], consider: [] });
    expect(withSuitability({ suits: 'active_family' })).toEqual({ suits: [], consider: [] });
    expect(hasSuitability(readSuitability(undefined))).toBe(false);
    expect(hasSuitability(readSuitability({ consider: ['sheds'] }))).toBe(true);
  });

  it('every tag of the vocabulary has a label in both languages, never "hypoallergenic"', async () => {
    try {
      for (const lang of ['sl', 'en']) {
        await i18n.changeLanguage(lang);
        for (const tag of SUITS_TAGS) expect(suitsLabel(tag)).not.toMatch(/^pet:|hipoaler|hypoaller/i);
        for (const tag of CONSIDER_TAGS) expect(considerLabel(tag)).not.toMatch(/^pet:|hipoaler|hypoaller/i);
      }
    } finally {
      await i18n.changeLanguage('sl');
    }
    expect(SUITS_TAGS).toHaveLength(11);
    expect(SUITS_TAGS).toEqual(expect.arrayContaining(['family_pet', 'large_home', 'children', 'small_children']));
    expect(SUITS_TAGS).not.toContain('house_with_garden');
    expect(CONSIDER_TAGS).toHaveLength(23);
    // M5-R10-30 (runbook new-tag rule): small dog — better with older children (the Pomeranian, AKC S251); no numbers.
    expect(CONSIDER_TAGS).toContain('older_children');
    // M5-R10-29 (runbook welfare rule, new key): eyelids and eyes — check eye tests (the Great Dane, RKC Breed Watch).
    expect(CONSIDER_TAGS).toContain('eyelid_problems');
    // M5-R10-26 (runbook welfare rule, new key): shorter muzzle — can have breathing problems (the Boxer; not the flat-face chip).
    expect(CONSIDER_TAGS).toContain('short_muzzle_breathing');
    // M5-R10-26 (runbook new-tag rule): loves company — best with someone home most of the day; no numbers.
    expect(CONSIDER_TAGS).toContain('needs_company');
    // David 2026-10-12: strong prey drive — may chase small pets (only breeds with a sourced prey-drive quote); no numbers.
    expect(CONSIDER_TAGS).toContain('chases_small_pets');
    // M5-R10-23 (runbook new-tag rule): large dog — not for homes with small children; drools; no numbers.
    expect(CONSIDER_TAGS).toContain('not_for_small_children');
    expect(CONSIDER_TAGS).toContain('drools');
    // M5-R10-22 (runbook new-tag rule): fragile bones — better with older children; no numbers.
    expect(CONSIDER_TAGS).toContain('fragile_older_children');
    // M5-R10-13 (runbook new-tag rule): escapes easily — needs a secure, high fence; no numbers.
    expect(CONSIDER_TAGS).toContain('secure_fencing');
    // M5-R10-19 (runbook new-tag rule): powerful dog — needs an experienced owner; no numbers.
    expect(CONSIDER_TAGS).toContain('experienced_owner');
    // M5-R10-12 (runbook new-tag rule): shorter lifespan — a calm chip, no numbers.
    expect(CONSIDER_TAGS).toContain('shorter_lifespan');
    // M5-R10-11 (runbook welfare rule): sensitive skin — itching and inflammation; no numbers, no allergy claim.
    expect(CONSIDER_TAGS).toContain('sensitive_skin');
    // M5-R10-08 (runbook welfare rule): long back — spine problems, avoid jumping; no numbers.
    expect(CONSIDER_TAGS).toContain('back_spine');
    // M5-R10-05 (runbook welfare rule): heart and spine — health tests; no numbers.
    expect(CONSIDER_TAGS).toContain('heart_and_spine');
    // M5-R10-04 (runbook welfare rule): hips and hind legs — health-tested parents; no numbers.
    expect(CONSIDER_TAGS).toContain('hips_hind_legs');
    // M5-R10-03 (David 2026-10-10): flat face — breathing and heat; no percentage in the label.
    expect(CONSIDER_TAGS).toContain('brachycephalic_breathing');
    // M5-R10-02 (David 2026-10-09): brushing several times a week; no "mouthy" tag.
    expect(CONSIDER_TAGS).toContain('frequent_grooming');
    expect(CONSIDER_TAGS).not.toContain('mouthy' as never);
  });

  it('one a11y sentence with both headings', () => {
    expect(suitabilityA11y({ suits: ['active_family', 'large_home'], consider: ['sheds'] })).toBe(
      'Primerno za: aktivno družino, veliko hišo z vrtom. Upoštevajte: izpada mu dlaka.',
    );
    expect(suitabilityA11y({ suits: [], consider: [] })).toBe('');
  });
});

describe('breed search', () => {
  afterEach(async () => {
    await i18n.changeLanguage('sl');
  });

  const names = (query: string, species: 'dog' | 'cat') => searchBreeds(breedsOf(both(), species), query).map((b) => b.breed);

  it('is case- and diacritic-insensitive on the name: "mesancek" → Mešanček', () => {
    expect(names('mesancek', 'dog')).toEqual(['mutt']);
    expect(names('MEŠAN', 'dog')).toEqual(['mutt']);
    expect(names('domaca', 'cat')).toEqual(['domestic_cat']);
  });

  it('matches the server synonyms: "mejnkun" → Maine Coon, "koli" → Border collie', () => {
    expect(names('mejnkun', 'cat')).toEqual(['maine_coon']);
    expect(names('Mejnkun ', 'cat')).toEqual(['maine_coon']);
    expect(names('koli', 'dog')).toEqual(['border_collie']);
  });

  it('M5-R10: finds the Labrador by its name and the server synonyms', () => {
    const lab = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(lab('labradorec')).toEqual(['labrador_retriever']);
    expect(lab('Labradorski prinašalec')).toEqual(['labrador_retriever']);
    expect(lab('koli')).toEqual(['border_collie']);
  });

  it('M5-R10-02: finds the Golden Retriever by its name and the server synonyms; "retriever" finds both', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('zlati prinasalec')).toEqual(['golden_retriever']);
    expect(find('Zlati')).toEqual(['golden_retriever']);
    expect(find('golden')).toEqual(['golden_retriever']);
    expect(find('retriever')).toEqual(['labrador_retriever', 'golden_retriever']);
  });

  it('M5-R10-03: finds the French Bulldog by its SL / EN name and "frenchie"', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('francoski')).toEqual(['french_bulldog']);
    expect(find('Francoski buldog')).toEqual(['french_bulldog']);
    expect(find('frenchie')).toEqual(['french_bulldog']);
    // M5-R10-20: "buldog" also finds the (English) Bulldog.
    expect(find('buldog')).toEqual(['french_bulldog', 'bulldog']);
  });

  it('M5-R10-26: finds the Boxer by its SL / EN / German names', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('boxer')).toEqual(['boxer']);
    expect(find('boksar')).toEqual(['boxer']);
    expect(find('Boksar')).toEqual(['boxer']);
  });

  it('M5-R10-27: finds the Miniature Schnauzer by its SL / EN / German names (with and without č)', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('miniature schnauzer')).toEqual(['miniature_schnauzer']);
    expect(find('šnavcer')).toEqual(['miniature_schnauzer']);
    expect(find('snavcer')).toEqual(['miniature_schnauzer']);
    expect(find('Pritlikavi')).toEqual(['miniature_schnauzer']);
    expect(find('zwergschnauzer')).toEqual(['miniature_schnauzer']);
  });

  it('M5-R10-28: finds the Shih Tzu by its EN / SL spellings (with and without š, with a hyphen)', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('shih tzu')).toEqual(['shih_tzu']);
    expect(find('Shih-Tzu')).toEqual(['shih_tzu']);
    expect(find('šicu')).toEqual(['shih_tzu']);
    expect(find('sicu')).toEqual(['shih_tzu']);
    expect(find('ši-cu')).toEqual(['shih_tzu']);
  });

  it('M5-R10-31: finds the Boston Terrier by its EN / SL names and the nickname', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('boston terrier')).toEqual(['boston_terrier']);
    expect(find('Bostonski terier')).toEqual(['boston_terrier']);
    expect(find('boston')).toEqual(['boston_terrier']);
    expect(find('American Gentleman')).toEqual(['boston_terrier']);
  });

  it('M5-R10-32: finds the Miniature American Shepherd by its EN / SL names and the nicknames', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('miniature american shepherd')).toEqual(['miniature_american_shepherd']);
    expect(find('Mali ameriški ovčar')).toEqual(['miniature_american_shepherd']);
    expect(find('mali ameriski')).toEqual(['miniature_american_shepherd']);
    expect(find('mini aussie')).toEqual(['miniature_american_shepherd']);
  });

  it('M5-R10-34: finds the English Springer Spaniel by its EN / SL names, with and without the carons, and as "springer"', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('english springer spaniel')).toEqual(['english_springer_spaniel']);
    expect(find('Angleški springer španjel')).toEqual(['english_springer_spaniel']);
    expect(find('angleski springer spanjel')).toEqual(['english_springer_spaniel']);
    expect(find('springer')).toEqual(['english_springer_spaniel']);
  });

  it('M5-R10-33: finds the Chihuahua by its EN / SL names, with and without the caron', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('chihuahua')).toEqual(['chihuahua']);
    expect(find('Čivava')).toEqual(['chihuahua']);
    expect(find('civava')).toEqual(['chihuahua']);
  });

  it('M5-R10-30: finds the Pomeranian by its EN / SL names, the nickname and the toy-spitz name (with and without š)', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('pomeranian')).toEqual(['pomeranian']);
    expect(find('pomeranec')).toEqual(['pomeranian']);
    expect(find('nemški špic')).toEqual(['pomeranian']);
    expect(find('nemski spic')).toEqual(['pomeranian']);
    expect(find('German Toy Spitz')).toEqual(['pomeranian']);
    // Never the Miniature Schnauzer (»pritlikavi šnavcer«) — no »pritlikavi« keyword.
    expect(find('Pritlikavi')).toEqual(['miniature_schnauzer']);
  });

  it('M5-R10-29: finds the Great Dane by its EN / SL / German names (with and without š)', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('great dane')).toEqual(['great_dane']);
    expect(find('nemška doga')).toEqual(['great_dane']);
    expect(find('nemska doga')).toEqual(['great_dane']);
    expect(find('Deutsche Dogge')).toEqual(['great_dane']);
  });

  it('M5-R10-25: finds the Dobermann by its SL / EN names and the AKC name', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('doberman')).toEqual(['dobermann']);
    expect(find('dobermann')).toEqual(['dobermann']);
    expect(find('pinscher')).toEqual(['dobermann']);
  });

  it('M5-R10-24: finds the Pembroke Welsh Corgi by its SL / EN names and nicknames', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('corgi')).toEqual(['pembroke_welsh_corgi']);
    expect(find('korgi')).toEqual(['pembroke_welsh_corgi']);
    expect(find('pembroke')).toEqual(['pembroke_welsh_corgi']);
    expect(find('valizanski')).toEqual(['pembroke_welsh_corgi']);
  });

  it('M5-R10-23: finds the Cane Corso by its SL / EN names and nicknames', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('cane corso')).toEqual(['cane_corso']);
    expect(find('korzo')).toEqual(['cane_corso']);
    expect(find('italijanski')).toEqual(['cane_corso']);
    // "Italian mastiff" is left out on purpose — it usually means the Neapolitan Mastiff.
    expect(find('italian mastiff')).toEqual([]);
  });

  it('M5-R10-22: finds the Yorkshire Terrier by its SL / EN names and nicknames', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('yorkshirski terier')).toEqual(['yorkshire_terrier']);
    expect(find('jorkširski')).toEqual(['yorkshire_terrier']);
    expect(find('jorksirski')).toEqual(['yorkshire_terrier']);
    expect(find('yorkie')).toEqual(['yorkshire_terrier']);
    expect(find('jorki')).toEqual(['yorkshire_terrier']);
  });

  it('M5-R10-21: finds the German Shorthaired Pointer by its SL / EN names, never the German Shepherd for "ptičar"', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('nemški kratkodlaki ptičar')).toEqual(['german_shorthaired_pointer']);
    expect(find('pticar')).toEqual(['german_shorthaired_pointer']);
    expect(find('kurzhaar')).toEqual(['german_shorthaired_pointer']);
    expect(find('pointer')).toEqual(['german_shorthaired_pointer']);
    expect(find('gsp')).toEqual(['german_shorthaired_pointer']);
  });

  it('M5-R10-20: finds the Bulldog by its SL / EN names, never the French Bulldog for "angleški"', () => {
    const find = (query: string) => searchBreeds(FALLBACK_CATALOGUE.breeds, query).map((b) => b.breed);
    expect(find('angleški buldog')).toEqual(['bulldog']);
    // M5-R10-34: »angleški« is also the start of the English Springer Spaniel's SL name — still never the French Bulldog.
    expect(find('angleski')).toEqual(['bulldog', 'english_springer_spaniel']);
    expect(find('english bulldog')).toEqual(['bulldog']);
    expect(find('british')).toEqual(['bulldog']);
  });

  it('empty query lists everything; no match → empty', () => {
    expect(names('  ', 'dog')).toEqual(['mutt', 'border_collie']);
    expect(names('pudelj', 'dog')).toEqual([]);
  });

  it('matches the localized name in English too', async () => {
    await i18n.changeLanguage('en');
    expect(names('mixed', 'dog')).toEqual(['mutt']);
    expect(names('domestic', 'cat')).toEqual(['domestic_cat']);
  });
});

describe('species-aware choice', () => {
  const cats = () => breedsOf(both(), 'cat');

  it('free cat = the domestic cat (no hard-coded mutt); the challenge = Maine Coon', () => {
    const cat = choiceWithSpecies(INITIAL_PICKER_CHOICE, 'cat', [], cats());
    // No plan yet → no breed preselected (fix 2026-10-12: never a ticked breed without its plan).
    expect(cat).toEqual({ species: 'cat', plan: null, breed: null, origin: null, age_stage: null });
    expect(choiceWithPlan(cat, 'free', [], cats()).breed).toBe('domestic_cat');
    const free = { ...choiceWithPlan(cat, 'free', [], cats()), origin: 'adopted' as const, age_stage: 'puppy' as const };
    expect(completeChoice(free, [], cats())).toEqual({ species: 'cat', breed: 'domestic_cat', origin: 'adopted', age_stage: 'puppy', plan: 'free' });
    const challenge = choiceWithPlan(free, 'challenge', [], cats());
    expect(challenge.breed).toBe('maine_coon');
    expect(completeChoice(challenge, [], cats())).toEqual(expect.objectContaining({ species: 'cat', breed: 'maine_coon', plan: 'challenge' }));
  });

  it('cats follow the same lock rules (M5-F03) from the catalogue', () => {
    expect(lockedBreedsFor('free', [], cats())).toEqual(['maine_coon']);
    expect(lockedBreedsFor('challenge', [], cats())).toEqual(['domestic_cat']);
    expect(lockedBreedsFor(null, [], cats())).toEqual(['maine_coon']);
  });

  it('switching species keeps plan, origin and age but re-chooses the breed inside the new species', () => {
    const dogChallenge = {
      species: 'dog' as const,
      plan: 'challenge' as const,
      breed: 'border_collie' as const,
      origin: 'bought' as const,
      age_stage: 'young' as const,
    };
    const asCat = choiceWithSpecies(dogChallenge, 'cat', [], cats());
    expect(asCat).toEqual({ ...dogChallenge, species: 'cat', breed: 'maine_coon' });
    // Same species again: nothing changes.
    expect(choiceWithSpecies(asCat, 'cat', [], cats())).toBe(asCat);
  });

  it('a breed of another species is never sent', () => {
    const mixed = { species: 'cat' as const, plan: 'challenge' as const, breed: 'border_collie' as const, origin: 'bought' as const, age_stage: 'young' as const };
    expect(completeChoice(mixed, [], cats())).toBeNull();
  });
});
