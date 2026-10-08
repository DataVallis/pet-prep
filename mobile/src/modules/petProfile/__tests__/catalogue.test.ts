/**
 * M5-R06-02: the server-driven picker catalogue (`GET /api/breeds`), search and the
 * species-aware choice rules.
 */
import { showableSpecies } from '@/config/features';
import { i18n } from '@/i18n';
import {
  breedsOf,
  choiceWithPlan,
  choiceWithSpecies,
  completeChoice,
  FALLBACK_CATALOGUE,
  freeBreedOf,
  INITIAL_PICKER_CHOICE,
  lockedBreedsFor,
  readBreedCatalogue,
  searchBreeds,
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
    // Default = this build (CAT_UI_READY false) → dogs only.
    const catalogue = readBreedCatalogue(BOTH_RAW);
    expect(catalogue?.species).toEqual(['dog']);
    expect(catalogue?.breeds.map((b) => b.breed)).toEqual(['mutt', 'border_collie']);
    expect(readBreedCatalogue(BOTH_RAW, ['dog'])?.species).toEqual(['dog']);
    // Cats only → nothing this build can show → fallback (null).
    expect(readBreedCatalogue({ species: ['cat'], breeds: BOTH_RAW.breeds })).toBeNull();
    expect(showableSpecies(false)).toEqual(['dog']);
    expect(showableSpecies(true)).toEqual(['dog', 'cat']);
  });

  it('a breed listed for a species that is not offered is ignored (never a hidden cat)', () => {
    const catalogue = readBreedCatalogue({ species: ['dog'], breeds: BOTH_RAW.breeds });
    expect(catalogue?.breeds.some((b) => b.species === 'cat')).toBe(false);
  });

  it('the fallback is today’s two dogs, never a cat', () => {
    expect(FALLBACK_CATALOGUE.species).toEqual(['dog']);
    expect(FALLBACK_CATALOGUE.breeds.map((b) => b.breed)).toEqual(['mutt', 'border_collie']);
    expect(freeBreedOf(FALLBACK_CATALOGUE.breeds)).toBe('mutt');
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
