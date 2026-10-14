/**
 * M5-R21 (David 2026-10-14): coat colour of a paid breed — reading `colours` from
 * `GET /api/breeds`, the picker rules (shown / hidden, first preselected) and the profile
 * sent to generate-pin.
 */
import { generatePinBody } from '@/api/client';
import { classifyPinError, pinRequestKey } from '@/modules/pairing/pin';
import { ApiError } from '@/api/client';
import {
  choiceWithBreed,
  choiceWithPlan,
  colourLabel,
  coloursFor,
  completeChoice,
  FALLBACK_CATALOGUE,
  INITIAL_PICKER_CHOICE,
  readBreedCatalogue,
  readColours,
  withValidColour,
  type BreedCatalogue,
  type PickerChoice,
} from '@/modules/petProfile/picker';

function entry(breed: string, premium: boolean, sort_order: number, colours?: unknown) {
  return {
    breed,
    slug: breed,
    species: 'dog',
    premium,
    free_plan_allowed: !premium,
    challenge_allowed: premium,
    label_key: `breeds.${breed}`,
    search_keywords: [],
    sort_order,
    ...(colours !== undefined ? { colours } : {}),
  };
}

const LAB_COLOURS = [
  { key: 'black', label: 'Črn' },
  { key: 'yellow', label: 'Rumen' },
  { key: 'chocolate', label: 'Čokoladno rjav' },
];

const CATALOGUE = readBreedCatalogue({
  species: ['dog'],
  breeds: [
    entry('mutt', false, 0, []),
    entry('labrador_retriever', true, 20, LAB_COLOURS),
    entry('west_highland_white_terrier', true, 120, [{ key: 'white', label: 'Bel' }]),
    // An older server: no `colours` key at all.
    entry('border_collie', true, 10),
  ],
}) as BreedCatalogue;
const BREEDS = CATALOGUE.breeds;

const base: PickerChoice = { ...INITIAL_PICKER_CHOICE, species: 'dog', origin: 'bought', age_stage: 'puppy' };
const lab = (colour?: string | null): PickerChoice => ({ ...base, plan: 'challenge', breed: 'labrador_retriever', ...(colour !== undefined ? { colour } : {}) });

describe('readColours', () => {
  it('keeps key + label pairs in server order', () => {
    expect(readColours(LAB_COLOURS)).toEqual(LAB_COLOURS);
  });

  it('drops malformed entries and repeated keys; anything but an array → none', () => {
    expect(
      readColours([{ key: 'black', label: 'Črn' }, { key: '', label: 'x' }, { key: 'blue' }, 7, null, { key: 'black', label: 'Again' }, { key: ' yellow ', label: ' Rumen ' }]),
    ).toEqual([
      { key: 'black', label: 'Črn' },
      { key: 'yellow', label: 'Rumen' },
    ]);
    expect(readColours(undefined)).toEqual([]);
    expect(readColours({ key: 'black' })).toEqual([]);
  });

  it('the catalogue reads colours of paid breeds only; an older server (no key) and the offline fallback have none', () => {
    const byBreed = Object.fromEntries(BREEDS.map((b) => [b.breed, b.colours]));
    expect(byBreed.labrador_retriever).toEqual(LAB_COLOURS);
    expect(byBreed.border_collie).toEqual([]);
    expect(byBreed.mutt).toEqual([]);
    // A free breed never gets a colour choice, even if a server sent one by mistake.
    const odd = readBreedCatalogue({ species: ['dog'], breeds: [entry('mutt', false, 0, LAB_COLOURS)] }) as BreedCatalogue;
    expect(odd.breeds[0].colours).toEqual([]);
    expect(FALLBACK_CATALOGUE.breeds.every((b) => b.colours.length === 0)).toBe(true);
  });
});

describe('colour choice rules', () => {
  it('colours only for a chosen paid breed on the challenge — never the free plan or before a plan', () => {
    expect(coloursFor(lab(), BREEDS)).toEqual(LAB_COLOURS);
    expect(coloursFor({ ...base, plan: 'free', breed: 'mutt' }, BREEDS)).toEqual([]);
    expect(coloursFor({ ...base, plan: null, breed: null }, BREEDS)).toEqual([]);
    expect(coloursFor({ ...base, plan: 'challenge', breed: 'border_collie' }, BREEDS)).toEqual([]);
  });

  it('the first colour is preselected; a valid choice is kept; another breed never keeps a foreign colour', () => {
    expect(withValidColour(lab(), BREEDS).colour).toBe('black');
    expect(withValidColour(lab('chocolate'), BREEDS).colour).toBe('chocolate');
    // Switch to the Westie: its only colour replaces the Labrador's.
    const westie = choiceWithBreed(lab('chocolate'), 'west_highland_white_terrier', BREEDS);
    expect(withValidColour(westie, BREEDS).colour).toBe('white');
    // The free plan drops the colour.
    const free = choiceWithPlan(lab('yellow'), 'free', [], BREEDS);
    expect(withValidColour(free, BREEDS).colour).toBeNull();
  });

  it('a choice without colours is returned unchanged (no extra key)', () => {
    const choice = { ...base, plan: 'free' as const, breed: 'mutt' as const };
    expect(withValidColour(choice, BREEDS)).toBe(choice);
  });
});

describe('completeChoice → generate-pin', () => {
  it('a paid breed with colours sends the chosen colour', () => {
    expect(completeChoice(lab('yellow'), [], BREEDS)).toEqual({
      species: 'dog',
      breed: 'labrador_retriever',
      origin: 'bought',
      age_stage: 'puppy',
      plan: 'challenge',
      colour: 'yellow',
    });
  });

  it('a single colour is sent too (shown as fact, nothing to choose)', () => {
    const westie = withValidColour({ ...base, plan: 'challenge', breed: 'west_highland_white_terrier' }, BREEDS);
    expect(completeChoice(westie, [], BREEDS)?.colour).toBe('white');
  });

  it('no colour while a breed with colours has none / an unknown one chosen (never a guess sent)', () => {
    expect(completeChoice(lab(null), [], BREEDS)).toBeNull();
    expect(completeChoice(lab('blue'), [], BREEDS)).toBeNull();
  });

  it('free breed and an older server: no colour key in the profile or the request body', () => {
    const free = completeChoice({ ...base, origin: 'adopted', plan: 'free', breed: 'mutt' }, [], BREEDS);
    expect(free).not.toHaveProperty('colour');
    const old = completeChoice({ ...base, plan: 'challenge', breed: 'border_collie' }, [], BREEDS);
    expect(old).not.toHaveProperty('colour');
    expect(generatePinBody({ child_id: 5, profile: old })).not.toHaveProperty('colour');
  });

  it('the request body carries `colour` next to the profile; joining a pet never does', () => {
    const profile = completeChoice(lab('chocolate'), [], BREEDS);
    expect(generatePinBody({ child_id: 5, profile }, ['training'])).toEqual({
      child_id: 5,
      species: 'dog',
      breed: 'labrador_retriever',
      origin: 'bought',
      age_stage: 'puppy',
      plan: 'challenge',
      colour: 'chocolate',
      features: ['training'],
    });
    expect(generatePinBody({ child_id: 5, pet_id: 9, profile })).toEqual({ child_id: 5, pet_id: 9 });
  });

  it('summary label of the chosen colour', () => {
    const profile = completeChoice(lab('chocolate'), [], BREEDS);
    expect(profile && colourLabel(profile, BREEDS)).toBe('Čokoladno rjav');
  });

  it('another colour is another PIN request (never reused across colours)', () => {
    const a = completeChoice(lab('black'), [], BREEDS);
    const b = completeChoice(lab('yellow'), [], BREEDS);
    expect(pinRequestKey(null, a)).not.toBe(pinRequestKey(null, b));
  });

  it('422 colour_invalid is its own error; a validation error on `colour` is an invalid profile', () => {
    expect(classifyPinError(new ApiError('x', 422, { reason: 'colour_invalid' })).kind).toBe('colour_invalid');
    expect(classifyPinError(new ApiError('x', 422, { message: 'x', errors: { colour: ['bad'] } })).kind).toBe('invalid_profile');
  });
});
