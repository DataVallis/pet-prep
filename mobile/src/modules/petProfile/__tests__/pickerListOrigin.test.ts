/**
 * David 2026-10-12: the breed list shows a few breeds until "Pokaži vse pasme" (search always
 * covers everything), and a non-pedigree breed (mutt, domestic cat) is adoption only.
 */
import {
  BREED_PREVIEW_COUNT,
  completeChoice,
  FALLBACK_CATALOGUE,
  originsFor,
  visibleBreeds,
  withValidOrigin,
  type CatalogueBreed,
  type PickerChoice,
} from '@/modules/petProfile/picker';

const dogs = FALLBACK_CATALOGUE.breeds;
const base: PickerChoice = { species: 'dog', plan: 'challenge', breed: 'border_collie', origin: 'bought', age_stage: 'puppy' };
const cat = (breed: string, premium: boolean): CatalogueBreed =>
  ({ ...dogs[0], breed, species: 'cat', premium, free_plan_allowed: !premium, challenge_allowed: premium }) as CatalogueBreed;

describe('visibleBreeds', () => {
  it('collapsed: the first breeds; the picked breed further down is appended', () => {
    expect(visibleBreeds(dogs, '', false, null)).toHaveLength(BREED_PREVIEW_COUNT);
    const picked = dogs[dogs.length - 1].breed;
    const rows = visibleBreeds(dogs, '', false, picked);
    expect(rows).toHaveLength(BREED_PREVIEW_COUNT + 1);
    expect(rows[rows.length - 1].breed).toBe(picked);
  });

  it('expanded or searching: every row it is given', () => {
    expect(visibleBreeds(dogs, '', true, null)).toHaveLength(dogs.length);
    expect(visibleBreeds(dogs, 'x', false, null)).toHaveLength(dogs.length);
    expect(visibleBreeds(dogs, '   ', false, null)).toHaveLength(BREED_PREVIEW_COUNT);
  });
});

describe('origin rule', () => {
  it('free plan (mutt) → adoption only; a paid breed → both; no plan yet → both', () => {
    expect(originsFor({ ...base, plan: 'free' }, dogs)).toEqual(['adopted']);
    expect(originsFor(base, dogs)).toEqual(['bought', 'adopted']);
    expect(originsFor({ ...base, plan: null, breed: 'mutt' }, dogs)).toEqual(['bought', 'adopted']);
  });

  it('the domestic cat is adoption only as well, the Maine Coon is not', () => {
    const cats = [cat('domestic_cat', false), cat('maine_coon', true)];
    expect(originsFor({ ...base, species: 'cat', plan: 'free', breed: 'domestic_cat' }, cats)).toEqual(['adopted']);
    expect(originsFor({ ...base, species: 'cat', breed: 'maine_coon' }, cats)).toEqual(['bought', 'adopted']);
  });

  it('withValidOrigin clears an origin that no longer fits, keeps one that does', () => {
    expect(withValidOrigin({ ...base, plan: 'free' }, dogs).origin).toBeNull();
    expect(withValidOrigin({ ...base, plan: 'free', origin: 'adopted' }, dogs).origin).toBe('adopted');
    expect(withValidOrigin(base, dogs).origin).toBe('bought');
  });

  it('completeChoice never sends a bought mutt', () => {
    expect(completeChoice({ ...base, plan: 'free' }, [], dogs)).toBeNull();
    expect(completeChoice({ ...base, plan: 'free', origin: 'adopted' }, [], dogs)).toMatchObject({ breed: 'mutt', origin: 'adopted' });
  });
});
