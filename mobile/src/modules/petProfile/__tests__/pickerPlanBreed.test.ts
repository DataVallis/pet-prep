/**
 * Fix 2026-10-12 (David): plan and breed always agree — "Mešanček" was ticked in PASMA while
 * neither plan radio was selected. Without a plan there is no breed; tapping the free breed
 * picks the free plan, a paid breed the challenge (M5-F03).
 */
import {
  breedsOf,
  choiceWithBreed,
  choiceWithPlan,
  choiceWithSpecies,
  FALLBACK_CATALOGUE,
  INITIAL_PICKER_CHOICE,
  readBreedCatalogue,
  withConsistentPlan,
  type BreedCatalogue,
  type PickerChoice,
} from '@/modules/petProfile/picker';

const dogs = FALLBACK_CATALOGUE.breeds;
const entry = (breed: string, species: string, premium: boolean) => ({
  breed,
  species,
  premium,
  free_plan_allowed: !premium,
  challenge_allowed: premium,
  search_keywords: [],
  sort_order: premium ? 10 : 0,
});
const BOTH = readBreedCatalogue(
  {
    species: ['dog', 'cat'],
    breeds: [entry('mutt', 'dog', false), entry('border_collie', 'dog', true), entry('domestic_cat', 'cat', false), entry('maine_coon', 'cat', true)],
  },
  ['dog', 'cat'],
) as BreedCatalogue;

/** The invariant the picker must keep: a breed only with a plan that accepts it. */
function consistent(c: PickerChoice, catalogue: BreedCatalogue): boolean {
  if (c.breed === null) return true;
  if (c.plan === null) return false;
  const e = catalogue.breeds.find((b) => b.breed === c.breed);
  return e !== undefined && (c.plan === 'free' ? e.free_plan_allowed : e.challenge_allowed);
}

describe('withConsistentPlan', () => {
  it('drops a breed that has no plan, keeps everything else', () => {
    const stale: PickerChoice = { species: 'dog', plan: null, breed: 'mutt', origin: 'adopted', age_stage: 'puppy' };
    expect(withConsistentPlan(stale)).toEqual({ ...stale, breed: null });
    const ok: PickerChoice = { ...stale, plan: 'free' };
    expect(withConsistentPlan(ok)).toBe(ok);
    expect(withConsistentPlan(INITIAL_PICKER_CHOICE)).toBe(INITIAL_PICKER_CHOICE);
  });
});

describe('choiceWithBreed', () => {
  const start: PickerChoice = { ...INITIAL_PICKER_CHOICE, species: 'dog' };

  it('no plan yet: the mutt picks the free plan', () => {
    expect(choiceWithBreed(start, 'mutt', dogs)).toEqual({ ...start, breed: 'mutt', plan: 'free' });
  });

  it('no plan yet: a paid breed picks the challenge', () => {
    expect(choiceWithBreed(start, 'border_collie', dogs)).toEqual({ ...start, breed: 'border_collie', plan: 'challenge' });
  });

  it('with a plan: only the breed changes', () => {
    const challenge: PickerChoice = { ...start, plan: 'challenge', breed: 'border_collie' };
    expect(choiceWithBreed(challenge, 'labrador_retriever', dogs)).toEqual({ ...challenge, breed: 'labrador_retriever' });
  });

  it('cats: the domestic cat picks the free plan, Maine Coon the challenge', () => {
    const cats = breedsOf(BOTH, 'cat');
    const cat: PickerChoice = { ...INITIAL_PICKER_CHOICE, species: 'cat' };
    expect(choiceWithBreed(cat, 'domestic_cat', cats).plan).toBe('free');
    expect(choiceWithBreed(cat, 'maine_coon', cats).plan).toBe('challenge');
  });
});

describe('choiceWithSpecies without a plan', () => {
  it('selects no breed (dog and cat)', () => {
    for (const species of ['dog', 'cat'] as const) {
      const c = choiceWithSpecies(INITIAL_PICKER_CHOICE, species, [], breedsOf(BOTH, species));
      expect(c).toEqual({ ...INITIAL_PICKER_CHOICE, species, breed: null });
      expect(consistent(c, BOTH)).toBe(true);
    }
  });
});

describe('plan ↔ breed invariant across every step', () => {
  it('holds for any sequence of species / plan / breed picks', () => {
    let c: PickerChoice = INITIAL_PICKER_CHOICE;
    const steps: Array<(c: PickerChoice) => PickerChoice> = [
      (x) => choiceWithSpecies(x, 'cat', [], breedsOf(BOTH, 'cat')),
      (x) => choiceWithBreed(x, 'domestic_cat', breedsOf(BOTH, 'cat')),
      (x) => choiceWithPlan(x, 'challenge', [], breedsOf(BOTH, 'cat')),
      (x) => choiceWithSpecies(x, 'dog', [], breedsOf(BOTH, 'dog')),
      (x) => choiceWithPlan(x, 'free', [], breedsOf(BOTH, 'dog')),
    ];
    for (const step of steps) {
      c = withConsistentPlan(step(c));
      expect(consistent(c, BOTH)).toBe(true);
    }
    expect(c).toEqual(expect.objectContaining({ species: 'dog', plan: 'free', breed: 'mutt' }));
  });
});
