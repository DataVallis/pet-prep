/**
 * M5-R08 — pet name: client-side rules mirror the server (PetNameService), error mapping,
 * the card label, and the live paths (child broadcast, parent dashboard broadcast / cache).
 */
import { ApiError } from '@/api/client';
import { i18n } from '@/i18n';
import { applyBroadcast, normalizeChildState } from '@/modules/childPet/childPetView';
import { patchDashboardPet, setDashboardPetName } from '@/modules/family/live';
import {
  PET_NAME_MAX_LENGTH,
  classifyPetNameError,
  normalizePetName,
  petLabel,
  petNameErrorText,
  readPetName,
  validatePetName,
} from '@/modules/petName/petName';
import { makeBroadcast, makeChildState, makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import type { ParentDashboardResponse } from '@/api/client';

afterEach(async () => {
  await i18n.changeLanguage('sl');
});

describe('normalizePetName / validatePetName', () => {
  it('trims, collapses spaces and turns ’ into an apostrophe', () => {
    expect(normalizePetName('  Mala   Luna  ')).toBe('Mala Luna');
    expect(normalizePetName('O’Malley')).toBe("O'Malley");
    expect(normalizePetName('Luna Bela')).toBe('Luna Bela');
  });

  it('an empty / whitespace input means "no name"', () => {
    expect(normalizePetName('')).toBeNull();
    expect(validatePetName('   ')).toEqual({ ok: true, name: null });
  });

  it('accepts letters incl. č š ž, space, hyphen and apostrophe', () => {
    for (const name of ['Luna', 'Čarli', 'Šapa Žužek', 'Mary-Ann', "D'Artagnan", 'Zoë', 'Ñandú']) {
      expect(validatePetName(name)).toEqual({ ok: true, name });
    }
  });

  it('20 characters pass, 21 are too long (code points, after trimming)', () => {
    expect(validatePetName('a'.repeat(PET_NAME_MAX_LENGTH))).toEqual({ ok: true, name: 'a'.repeat(20) });
    expect(validatePetName('ž'.repeat(20))).toEqual({ ok: true, name: 'ž'.repeat(20) });
    expect(validatePetName(`  ${'a'.repeat(20)}  `).ok).toBe(true);
    expect(validatePetName('a'.repeat(21))).toEqual({ ok: false, code: 'name_too_long' });
  });

  it('digits, emoji, symbols or no letter at all are invalid', () => {
    for (const name of ['Luna2', 'Luna 🐶', 'Luna!', 'Luna_Bela', '--', "' -"]) {
      expect(validatePetName(name)).toEqual({ ok: false, code: 'name_invalid' });
    }
  });

  it('length is checked before the characters (the server order)', () => {
    expect(validatePetName('1'.repeat(21))).toEqual({ ok: false, code: 'name_too_long' });
  });
});

describe('readPetName', () => {
  it('a non-empty string or null', () => {
    expect(readPetName('Luna')).toBe('Luna');
    expect(readPetName('  ')).toBeNull();
    expect(readPetName(null)).toBeNull();
    expect(readPetName(undefined)).toBeNull();
    expect(readPetName(12)).toBeNull();
  });
});

describe('classifyPetNameError / petNameErrorText', () => {
  const unprocessable = (body: unknown) => new ApiError('Invalid', 422, body);

  it('maps the three 422 codes (codes.name first, then reason)', () => {
    expect(classifyPetNameError(unprocessable({ codes: { name: 'name_too_long' }, reason: 'name_too_long' }))).toBe('name_too_long');
    expect(classifyPetNameError(unprocessable({ codes: { name: 'name_invalid' } }))).toBe('name_invalid');
    expect(classifyPetNameError(unprocessable({ reason: 'name_not_allowed' }))).toBe('name_not_allowed');
    expect(classifyPetNameError(unprocessable({ message: 'x' }))).toBe('name_invalid');
  });

  it('404 / 403 / 5xx / network', () => {
    expect(classifyPetNameError(new ApiError('x', 404, { reason: 'pet_not_found' }))).toBe('not_found');
    expect(classifyPetNameError(new ApiError('x', 403))).toBe('forbidden');
    expect(classifyPetNameError(new ApiError('x', 500))).toBe('server');
    expect(classifyPetNameError(new TypeError('Network request failed'))).toBe('offline');
  });

  it('friendly texts in Slovenian and English', async () => {
    expect(petNameErrorText('name_too_long')).toBe('Ime je lahko dolgo največ 20 znakov.');
    expect(petNameErrorText('name_invalid')).toBe("Uporabite samo črke, presledek, vezaj (-) in opuščaj (').");
    expect(petNameErrorText('name_not_allowed')).toBe('Prosimo, izberite drugo ime.');
    await i18n.changeLanguage('en');
    expect(petNameErrorText('name_too_long')).toBe('The name can be at most 20 characters long.');
    expect(petNameErrorText('name_invalid')).toBe("Use only letters, spaces, hyphens (-) and apostrophes (').");
    expect(petNameErrorText('name_not_allowed')).toBe('Please choose a different name.');
  });
});

describe('petLabel', () => {
  it('the breed alone without a name (unchanged), "Name · Breed" with one', () => {
    expect(petLabel({ name: null, breed_type: 'mutt', species: 'dog' })).toBe('Mešanček');
    expect(petLabel({ breed_type: 'domestic_cat', species: 'cat' })).toBe('Domača mačka');
    expect(petLabel({ name: 'Luna', breed_type: 'border_collie', species: 'dog' })).toBe('Luna · Border collie');
  });
});

describe('child live path (applyBroadcast)', () => {
  const view = () => normalizeChildState(makeChildState(), 0, Date.parse('2026-10-04T10:00:00Z'));

  it('reads the name of the child state (null without one)', () => {
    expect(view().pet.name).toBeNull();
    expect(normalizeChildState(makeChildState({ name: 'Luna' })).pet.name).toBe('Luna');
  });

  it('`pet_renamed` sets, changes and clears the name', () => {
    const named = applyBroadcast(view(), makeBroadcast({ event_type: 'pet_renamed', name: 'Luna' }));
    expect(named?.view.pet.name).toBe('Luna');
    const cleared = applyBroadcast(named!.view, makeBroadcast({ event_type: 'pet_renamed', name: null, emitted_at: '2026-10-04T10:00:06.000+00:00' }));
    expect(cleared?.view.pet.name).toBeNull();
  });

  it('any PetUpdated carries the name; an older server without the key keeps it', () => {
    const named = normalizeChildState(makeChildState({ name: 'Luna' }), 0, Date.parse('2026-10-04T10:00:00Z'));
    expect(applyBroadcast(named, makeBroadcast({ name: 'Bela' }))?.view.pet.name).toBe('Bela');
    expect(applyBroadcast(named, makeBroadcast())?.view.pet.name).toBe('Luna');
  });
});

describe('parent live path (dashboard cache)', () => {
  const dashboard = () =>
    makeScoredDashboard([makeScoredChild()], [makeFamilyPet({ id: 7 }), makeFamilyPet({ id: 8 })]) as unknown as ParentDashboardResponse;

  it('a broadcast patches the name of that pet only', () => {
    const next = patchDashboardPet(dashboard(), makeBroadcast({ pet_id: 7, event_type: 'pet_renamed', name: 'Luna' }));
    expect(next?.family?.pets.map((p) => p.name)).toEqual(['Luna', null]);
    const old = patchDashboardPet(next, makeBroadcast({ pet_id: 7 }));
    expect(old?.family?.pets[0].name).toBe('Luna');
  });

  it('setDashboardPetName sets / clears one pet', () => {
    const named = setDashboardPetName(dashboard(), 8, 'Muri');
    expect(named?.family?.pets.map((p) => p.name)).toEqual([null, 'Muri']);
    expect(setDashboardPetName(named, 8, null)?.family?.pets[1].name).toBeNull();
    expect(setDashboardPetName(undefined, 8, 'x')).toBeUndefined();
  });
});
