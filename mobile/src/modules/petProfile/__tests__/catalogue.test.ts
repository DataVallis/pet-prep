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

  it('the fallback is today’s seeded dogs (M5-R10: + Labrador, sort 20; M5-R10-02: + Golden, sort 30; M5-R10-03: + French Bulldog, sort 40; M5-R10-04: + German Shepherd, sort 50; M5-R10-05: + Cavalier, sort 60; M5-R10-06: + Beagle, sort 70; M5-R10-08: + Dachshund, sort 90; M5-R10-07: + Standard Poodle, sort 80), never a cat', () => {
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
    expect(frenchie?.suitability).toEqual({ suits: ['apartment', 'family_pet', 'children'], consider: ['brachycephalic_breathing'] });
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
    expect(poodle?.search_keywords).toEqual(['poodle (standard)', 'standard poodle', 'poodle', 'veliki pudelj', 'pudelj', 'standardni pudelj']);
    expect(poodle?.suitability).toEqual({ suits: ['children', 'large_home', 'other_pets', 'low_shedding'], consider: ['frequent_grooming'] });
    const dachshund = FALLBACK_CATALOGUE.breeds.find((b) => b.breed === 'dachshund');
    expect(dachshund).toEqual(
      expect.objectContaining({ species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, sort_order: 90 }),
    );
    // Mirrors BreedConfigsSeeder and config/breed_suitability.php (M5-R10-08, runbook rules; back_spine = welfare rule).
    expect(dachshund?.search_keywords).toEqual(['dachshund', 'sausage dog', 'teckel', 'jazbečar', 'jazbecar']);
    expect(dachshund?.suitability).toEqual({ suits: ['children'], consider: ['back_spine', 'sheds', 'needs_mental_stimulation'] });
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
    expect(CONSIDER_TAGS).toHaveLength(11);
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
    expect(find('buldog')).toEqual(['french_bulldog']);
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
    expect(cat).toEqual({ species: 'cat', plan: null, breed: 'domestic_cat', origin: null, age_stage: null });
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
