/**
 * M5-R06-02: species / breed reading — an unknown breed never becomes the mutt.
 */
import { i18n } from '@/i18n';
import {
  breedName,
  foldForSearch,
  isDefaultFreeBreed,
  readBreed,
  readSpecies,
  speciesName,
} from '@/modules/species/species';

describe('readBreed / readSpecies', () => {
  it('keeps every known breed, dogs and cats', () => {
    for (const breed of ['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'domestic_cat', 'maine_coon'] as const) {
      expect(readBreed(breed)).toBe(breed);
    }
  });

  it('M5-R10: the Labrador Retriever is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('labrador_retriever'))).toBe('dog');
    expect(isDefaultFreeBreed('labrador_retriever')).toBe(false);
    expect(breedName('labrador_retriever')).toBe('Labradorec');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('labrador_retriever')).toBe('Labrador Retriever');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-02: the Golden Retriever is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('golden_retriever'))).toBe('dog');
    expect(isDefaultFreeBreed('golden_retriever')).toBe(false);
    expect(breedName('golden_retriever')).toBe('Zlati prinašalec');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('golden_retriever')).toBe('Golden Retriever');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('an unknown or missing breed is `unknown`, never the mutt', () => {
    expect(readBreed('sphynx')).toBe('unknown');
    expect(readBreed('')).toBe('unknown');
    expect(readBreed(null)).toBe('unknown');
    expect(readBreed(42)).toBe('unknown');
  });

  it('species: the payload wins, else derived from a known breed, else null', () => {
    expect(readSpecies('cat', 'unknown')).toBe('cat');
    expect(readSpecies(undefined, 'maine_coon')).toBe('cat');
    expect(readSpecies(undefined, 'mutt')).toBe('dog');
    expect(readSpecies('fish', 'border_collie')).toBe('dog');
    expect(readSpecies(undefined, 'unknown')).toBeNull();
    expect(readSpecies(null)).toBeNull();
  });

  it('free breeds by default (safety net only): mutt and domestic cat', () => {
    expect(isDefaultFreeBreed('mutt')).toBe(true);
    expect(isDefaultFreeBreed('domestic_cat')).toBe(true);
    expect(isDefaultFreeBreed('border_collie')).toBe(false);
    expect(isDefaultFreeBreed('maine_coon')).toBe(false);
    expect(isDefaultFreeBreed('sphynx')).toBe(false);
  });
});

describe('breedName / speciesName (one source: family:breeds)', () => {
  afterEach(async () => {
    await i18n.changeLanguage('sl');
  });

  it('Slovenian names, neutral label for an unknown breed', () => {
    expect(breedName('mutt')).toBe('Mešanček');
    expect(breedName('domestic_cat')).toBe('Domača mačka');
    expect(breedName('maine_coon')).toBe('Maine Coon');
    expect(breedName('unknown', 'cat')).toBe('Mačka');
    expect(breedName('husky', 'dog')).toBe('Pes');
    expect(breedName(null)).toBe('Ljubljenček');
    expect(speciesName('dog')).toBe('Pes');
    expect(speciesName('cat')).toBe('Mačka');
  });

  it('English', async () => {
    await i18n.changeLanguage('en');
    expect(breedName('domestic_cat')).toBe('Domestic cat');
    expect(breedName('unknown')).toBe('Pet');
    expect(speciesName('cat')).toBe('Cat');
  });
});

describe('foldForSearch', () => {
  it('drops case and Slovenian diacritics', () => {
    expect(foldForSearch('Mešanček')).toBe('mesancek');
    expect(foldForSearch('DOMAČA  Mačka ')).toBe('domaca macka');
    expect(foldForSearch('Žiga Đurić')).toBe('ziga duric');
  });
});
