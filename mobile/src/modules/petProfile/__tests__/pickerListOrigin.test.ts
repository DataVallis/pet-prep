/**
 * David 2026-10-12: the breed list shows a few breeds until "Pokaži vse pasme" (search always
 * covers everything), and a non-pedigree breed (mutt, domestic cat) is adoption only.
 */
import {
  BREED_PREVIEW_COUNT,
  choiceWithOrigin,
  completeChoice,
  FALLBACK_CATALOGUE,
  freeTrialLimits,
  freeTrialText,
  originsFor,
  soleOption,
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

  it('withValidOrigin (David 2026-10-25): the only origin is preselected, also over a stale "bought"', () => {
    expect(withValidOrigin({ ...base, plan: 'free', origin: null }, dogs)).toMatchObject({ origin: 'adopted', originAuto: true });
    expect(withValidOrigin({ ...base, plan: 'free' }, dogs)).toMatchObject({ origin: 'adopted', originAuto: true });
    // A tapped "adopted" stays the parent's own choice.
    expect(withValidOrigin({ ...base, plan: 'free', origin: 'adopted' }, dogs).originAuto).toBeUndefined();
    expect(withValidOrigin(base, dogs).origin).toBe('bought');
  });

  it('withValidOrigin: a preselected origin is cleared when several origins are possible again', () => {
    const auto = withValidOrigin({ ...base, plan: 'free', origin: null }, dogs);
    const back = withValidOrigin({ ...auto, plan: 'challenge', breed: 'border_collie' }, dogs);
    expect(back).toMatchObject({ origin: null, originAuto: false });
    // …but an origin the parent tapped is kept.
    const tapped = choiceWithOrigin(auto, 'adopted');
    expect(withValidOrigin({ ...tapped, plan: 'challenge', breed: 'border_collie' }, dogs)).toMatchObject({ origin: 'adopted', originAuto: false });
  });

  it('withValidOrigin: no plan yet → nothing preselected', () => {
    expect(withValidOrigin({ ...base, plan: null, breed: null, origin: null }, dogs).origin).toBeNull();
  });

  it('soleOption: exactly one option → it, else null', () => {
    expect(soleOption(['adopted'])).toBe('adopted');
    expect(soleOption([])).toBeNull();
    expect(soleOption(['bought', 'adopted'])).toBeNull();
  });

  it('completeChoice never sends a bought mutt', () => {
    expect(completeChoice({ ...base, plan: 'free' }, [], dogs)).toBeNull();
    // Through the picker's normalisation the mutt is complete with the preselected adoption.
    expect(completeChoice(withValidOrigin({ ...base, plan: 'free' }, dogs), [], dogs)).toMatchObject({ breed: 'mutt', origin: 'adopted' });
    expect(completeChoice({ ...base, plan: 'free', origin: 'adopted' }, [], dogs)).toMatchObject({ breed: 'mutt', origin: 'adopted' });
  });
});

describe('free-trial notice (David 2026-10-25)', () => {
  it('dog: five verified limits; cat: no dog-only care line', () => {
    expect(freeTrialLimits('dog')).toEqual(['program', 'history', 'origin', 'media', 'care']);
    expect(freeTrialLimits('cat')).toEqual(['program', 'history', 'origin', 'media']);
  });

  it('texts per species (Slovenian)', () => {
    const dog = freeTrialText('dog');
    expect(dog.title).toBe('Brezplačni mešanček je omejen preizkus');
    expect(dog.items).toHaveLength(5);
    expect(dog.items.join(' ')).toMatch(/zadnjih 7 dni/);
    const kitty = freeTrialText('cat');
    expect(kitty.title).toBe('Brezplačna domača mačka je omejen preizkus');
    expect(kitty.items).toHaveLength(4);
    expect(kitty.items.join(' ')).toMatch(/palico/);
    expect(kitty.cta).toBe(dog.cta);
  });
});
