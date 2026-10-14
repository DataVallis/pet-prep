/**
 * M5-R06-02: species / breed reading — an unknown breed never becomes the mutt.
 */
import { i18n } from '@/i18n';
import {
  hasGroomingRoutine,
  breedName,
  foldForSearch,
  isDefaultFreeBreed,
  readBreed,
  readSpecies,
  speciesName,
} from '@/modules/species/species';

describe('readBreed / readSpecies', () => {
  it('keeps every known breed, dogs and cats', () => {
    for (const breed of ['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'domestic_cat', 'maine_coon', 'british_shorthair', 'ragdoll', 'siberian_cat', 'norwegian_forest_cat', 'bengal'] as const) {
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

  it('M5-R10-05: the Cavalier King Charles Spaniel is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('cavalier_king_charles_spaniel'))).toBe('dog');
    expect(isDefaultFreeBreed('cavalier_king_charles_spaniel')).toBe(false);
    expect(breedName('cavalier_king_charles_spaniel')).toBe('Kavalir King Charles španjel');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('cavalier_king_charles_spaniel')).toBe('Cavalier King Charles Spaniel');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-07: the Standard Poodle is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('standard_poodle'))).toBe('dog');
    expect(isDefaultFreeBreed('standard_poodle')).toBe(false);
    expect(breedName('standard_poodle')).toBe('Veliki koder');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('standard_poodle')).toBe('Poodle (Standard)');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-09: the Australian Shepherd is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('australian_shepherd'))).toBe('dog');
    expect(isDefaultFreeBreed('australian_shepherd')).toBe(false);
    expect(breedName('australian_shepherd')).toBe('Avstralski ovčar');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('australian_shepherd')).toBe('Australian Shepherd');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-13: the Siberian Husky is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('siberian_husky'))).toBe('dog');
    expect(isDefaultFreeBreed('siberian_husky')).toBe(false);
    expect(breedName('siberian_husky')).toBe('Sibirski haski');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('siberian_husky')).toBe('Siberian Husky');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-26: the Boxer is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('boxer'))).toBe('dog');
    expect(isDefaultFreeBreed('boxer')).toBe(false);
    expect(hasGroomingRoutine('boxer')).toBe(true);
    expect(breedName('boxer')).toBe('Boksar');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('boxer')).toBe('Boxer');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-28: the Shih Tzu is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('shih_tzu'))).toBe('dog');
    expect(isDefaultFreeBreed('shih_tzu')).toBe(false);
    expect(hasGroomingRoutine('shih_tzu')).toBe(true);
    expect(breedName('shih_tzu')).toBe('Shih tzu');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('shih_tzu')).toBe('Shih Tzu');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-31: the Boston Terrier is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('boston_terrier'))).toBe('dog');
    expect(isDefaultFreeBreed('boston_terrier')).toBe(false);
    expect(hasGroomingRoutine('boston_terrier')).toBe(true);
    expect(breedName('boston_terrier')).toBe('Bostonski terier');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('boston_terrier')).toBe('Boston Terrier');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-32: the Miniature American Shepherd is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('miniature_american_shepherd'))).toBe('dog');
    expect(isDefaultFreeBreed('miniature_american_shepherd')).toBe(false);
    expect(hasGroomingRoutine('miniature_american_shepherd')).toBe(true);
    expect(breedName('miniature_american_shepherd')).toBe('Mali ameriški ovčar');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('miniature_american_shepherd')).toBe('Miniature American Shepherd');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-36: the Brittany is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('brittany'))).toBe('dog');
    expect(isDefaultFreeBreed('brittany')).toBe(false);
    expect(hasGroomingRoutine('brittany')).toBe(true);
    expect(breedName('brittany')).toBe('Bretonski ptičar');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('brittany')).toBe('Brittany');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-35: the Shetland Sheepdog is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('shetland_sheepdog'))).toBe('dog');
    expect(isDefaultFreeBreed('shetland_sheepdog')).toBe(false);
    expect(hasGroomingRoutine('shetland_sheepdog')).toBe(true);
    expect(breedName('shetland_sheepdog')).toBe('Šetlandski ovčar');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('shetland_sheepdog')).toBe('Shetland Sheepdog');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-34: the English Springer Spaniel is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('english_springer_spaniel'))).toBe('dog');
    expect(isDefaultFreeBreed('english_springer_spaniel')).toBe(false);
    expect(hasGroomingRoutine('english_springer_spaniel')).toBe(true);
    expect(breedName('english_springer_spaniel')).toBe('Angleški springer španjel');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('english_springer_spaniel')).toBe('English Springer Spaniel');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-33: the Chihuahua is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('chihuahua'))).toBe('dog');
    expect(isDefaultFreeBreed('chihuahua')).toBe(false);
    expect(hasGroomingRoutine('chihuahua')).toBe(true);
    expect(breedName('chihuahua')).toBe('Čivava');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('chihuahua')).toBe('Chihuahua');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-30: the Pomeranian is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('pomeranian'))).toBe('dog');
    expect(isDefaultFreeBreed('pomeranian')).toBe(false);
    expect(hasGroomingRoutine('pomeranian')).toBe(true);
    expect(breedName('pomeranian')).toBe('Pomeranec');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('pomeranian')).toBe('Pomeranian');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-29: the Great Dane is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('great_dane'))).toBe('dog');
    expect(isDefaultFreeBreed('great_dane')).toBe(false);
    expect(hasGroomingRoutine('great_dane')).toBe(true);
    expect(breedName('great_dane')).toBe('Nemška doga');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('great_dane')).toBe('Great Dane');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-27: the Miniature Schnauzer is a paid, brushed dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('miniature_schnauzer'))).toBe('dog');
    expect(isDefaultFreeBreed('miniature_schnauzer')).toBe(false);
    expect(hasGroomingRoutine('miniature_schnauzer')).toBe(true);
    expect(breedName('miniature_schnauzer')).toBe('Pritlikavi šnavcer');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('miniature_schnauzer')).toBe('Miniature Schnauzer');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-25: the Dobermann is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('dobermann'))).toBe('dog');
    expect(isDefaultFreeBreed('dobermann')).toBe(false);
    expect(breedName('dobermann')).toBe('Doberman');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('dobermann')).toBe('Dobermann');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-24: the Pembroke Welsh Corgi is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('pembroke_welsh_corgi'))).toBe('dog');
    expect(isDefaultFreeBreed('pembroke_welsh_corgi')).toBe(false);
    expect(breedName('pembroke_welsh_corgi')).toBe('Welsh corgi pembroke');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('pembroke_welsh_corgi')).toBe('Pembroke Welsh Corgi');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-23: the Cane Corso is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('cane_corso'))).toBe('dog');
    expect(isDefaultFreeBreed('cane_corso')).toBe(false);
    expect(breedName('cane_corso')).toBe('Cane corso');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('cane_corso')).toBe('Cane Corso');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-22: the Yorkshire Terrier is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('yorkshire_terrier'))).toBe('dog');
    expect(isDefaultFreeBreed('yorkshire_terrier')).toBe(false);
    expect(breedName('yorkshire_terrier')).toBe('Yorkshirski terier');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('yorkshire_terrier')).toBe('Yorkshire Terrier');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-21: the German Shorthaired Pointer is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('german_shorthaired_pointer'))).toBe('dog');
    expect(isDefaultFreeBreed('german_shorthaired_pointer')).toBe(false);
    expect(breedName('german_shorthaired_pointer')).toBe('Nemški kratkodlaki ptičar');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('german_shorthaired_pointer')).toBe('German Shorthaired Pointer');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-20: the Bulldog is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('bulldog'))).toBe('dog');
    expect(isDefaultFreeBreed('bulldog')).toBe(false);
    expect(breedName('bulldog')).toBe('Angleški buldog');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('bulldog')).toBe('Bulldog');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-19: the Rottweiler is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('rottweiler'))).toBe('dog');
    expect(isDefaultFreeBreed('rottweiler')).toBe(false);
    expect(breedName('rottweiler')).toBe('Rotvajler');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('rottweiler')).toBe('Rottweiler');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-12: the Bernese Mountain Dog is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('bernese_mountain_dog'))).toBe('dog');
    expect(isDefaultFreeBreed('bernese_mountain_dog')).toBe(false);
    expect(breedName('bernese_mountain_dog')).toBe('Bernski planšarski pes');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('bernese_mountain_dog')).toBe('Bernese Mountain Dog');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-11: the West Highland White Terrier is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('west_highland_white_terrier'))).toBe('dog');
    expect(isDefaultFreeBreed('west_highland_white_terrier')).toBe(false);
    expect(breedName('west_highland_white_terrier')).toBe('Zahodnoškotski beli terier');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('west_highland_white_terrier')).toBe('West Highland White Terrier');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-10: the Havanese is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('havanese'))).toBe('dog');
    expect(isDefaultFreeBreed('havanese')).toBe(false);
    expect(breedName('havanese')).toBe('Havanski bišon');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('havanese')).toBe('Havanese');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-08: the Dachshund is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('dachshund'))).toBe('dog');
    expect(isDefaultFreeBreed('dachshund')).toBe(false);
    expect(breedName('dachshund')).toBe('Jazbečar');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('dachshund')).toBe('Dachshund');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-06: the Beagle is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('beagle'))).toBe('dog');
    expect(isDefaultFreeBreed('beagle')).toBe(false);
    expect(breedName('beagle')).toBe('Bigl');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('beagle')).toBe('Beagle');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-04: the German Shepherd is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('german_shepherd'))).toBe('dog');
    expect(isDefaultFreeBreed('german_shepherd')).toBe(false);
    expect(breedName('german_shepherd')).toBe('Nemški ovčar');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('german_shepherd')).toBe('German Shepherd Dog');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-03: the French Bulldog is a paid dog with its own name', async () => {
    expect(readSpecies(undefined, readBreed('french_bulldog'))).toBe('dog');
    expect(isDefaultFreeBreed('french_bulldog')).toBe(false);
    expect(breedName('french_bulldog')).toBe('Francoski buldog');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('french_bulldog')).toBe('French Bulldog');
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

  it('M5-R10-14: the British Shorthair is a paid cat with its own name', async () => {
    expect(readSpecies(undefined, readBreed('british_shorthair'))).toBe('cat');
    expect(isDefaultFreeBreed('british_shorthair')).toBe(false);
    expect(breedName('british_shorthair')).toBe('Britanska kratkodlaka mačka');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('british_shorthair')).toBe('British Shorthair');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('brushing routine for the Maine Coon, Ragdoll, British Shorthair (David 2026-10-11), Siberian (M5-R10-16), Norwegian Forest Cat (M5-R10-17) and Bengal (M5-R10-18) only', () => {
    for (const breed of ['maine_coon', 'ragdoll', 'british_shorthair', 'siberian_cat', 'norwegian_forest_cat', 'bengal']) expect(hasGroomingRoutine(breed)).toBe(true);
    for (const breed of ['domestic_cat', 'mutt', 'border_collie', 'unknown', null]) expect(hasGroomingRoutine(breed)).toBe(false);
  });

  it('M5-R10-15: the Ragdoll is a paid cat with its own name', async () => {
    expect(readSpecies(undefined, readBreed('ragdoll'))).toBe('cat');
    expect(isDefaultFreeBreed('ragdoll')).toBe(false);
    expect(breedName('ragdoll')).toBe('Ragdoll');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('ragdoll')).toBe('Ragdoll');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-16: the Siberian is a paid cat with its own name (never confused with the Siberian Husky)', async () => {
    expect(readSpecies(undefined, readBreed('siberian_cat'))).toBe('cat');
    expect(readSpecies(undefined, readBreed('siberian_husky'))).toBe('dog');
    expect(isDefaultFreeBreed('siberian_cat')).toBe(false);
    expect(breedName('siberian_cat')).toBe('Sibirska mačka');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('siberian_cat')).toBe('Siberian cat');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-17: the Norwegian Forest Cat is a paid cat with its own name', async () => {
    expect(readSpecies(undefined, readBreed('norwegian_forest_cat'))).toBe('cat');
    expect(isDefaultFreeBreed('norwegian_forest_cat')).toBe(false);
    expect(breedName('norwegian_forest_cat')).toBe('Norveška gozdna mačka');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('norwegian_forest_cat')).toBe('Norwegian Forest Cat');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-18: the Bengal is a paid cat with its own name', async () => {
    expect(readSpecies(undefined, readBreed('bengal'))).toBe('cat');
    expect(isDefaultFreeBreed('bengal')).toBe(false);
    expect(breedName('bengal')).toBe('Bengalska mačka');
    await i18n.changeLanguage('en');
    try {
      expect(breedName('bengal')).toBe('Bengal');
    } finally {
      await i18n.changeLanguage('sl');
    }
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
