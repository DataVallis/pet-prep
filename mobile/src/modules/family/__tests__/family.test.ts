/**
 * M2-02 / M2-01a: family helpers for the parent's children list and "Dodaj otroka".
 */
import { ApiError } from '@/api/client';
import {
  breedLabel,
  caretakerNames,
  classifyCreateChildError,
  classifyRevokeError,
  devicesLabel,
  familyFromDashboard,
  isChildConnected,
  joinablePets,
  normalizeNickname,
  parseBirthYear,
} from '@/modules/family/family';
import { classifyPinError } from '@/modules/pairing/pin';
import { makeFamilyChild, makeFamilyDashboard, makeFamilyPet } from '@/test-utils/fixtures';

const NOW = new Date('2026-10-04T10:00:00Z');

describe('family helpers', () => {
  it('familyFromDashboard reads the hand-typed children and drops malformed rows', () => {
    const data = makeFamilyDashboard([makeFamilyChild(), { bogus: true } as never], [makeFamilyPet()]);
    const family = familyFromDashboard(data as never);
    expect(family?.children.map((c) => c.id)).toEqual([5]);
    expect(family?.pets).toHaveLength(1);
    expect(familyFromDashboard(undefined)).toBeNull();
    expect(familyFromDashboard({ ...data, family: null } as never)).toBeNull();
  });

  it('joinablePets keeps only active, not-game-over pets', () => {
    const family = familyFromDashboard(
      makeFamilyDashboard([], [
        makeFamilyPet({ id: 1 }),
        makeFamilyPet({ id: 2, is_active: false }),
        makeFamilyPet({ id: 3, is_game_over: true }),
      ]) as never,
    );
    expect(joinablePets(family).map((p) => p.id)).toEqual([1]);
    expect(joinablePets(null)).toEqual([]);
  });

  it('caretakerNames and breedLabel', () => {
    const pet = makeFamilyPet({ caretakers: [{ child_id: 5, contract_signed: true }, { child_id: 6, contract_signed: false }] });
    const family = familyFromDashboard(
      makeFamilyDashboard([makeFamilyChild({ id: 5, name: 'Maja' }), makeFamilyChild({ id: 6, name: 'Luka' })], [pet]) as never,
    );
    expect(caretakerNames(pet, family!)).toBe('Maja, Luka');
    expect(breedLabel('mutt')).toBe('Mešanček');
    expect(breedLabel('border_collie')).toBe('Border collie');
    expect(breedLabel('husky')).toBe('husky');
  });

  it.each([
    [0, '0 naprav'],
    [1, '1 naprava'],
    [2, '2 napravi'],
    [3, '3 naprave'],
    [4, '4 naprave'],
    [5, '5 naprav'],
    [101, '101 naprava'],
  ])('devicesLabel(%i) = %s', (n, label) => {
    expect(devicesLabel(n)).toBe(label);
  });

  it('isChildConnected: a first pet or one more device', () => {
    const base = { devices: 1, pet_id: null };
    expect(isChildConnected(undefined, base)).toBe(false);
    expect(isChildConnected(makeFamilyChild({ devices: 1, pet_id: null }), base)).toBe(false);
    expect(isChildConnected(makeFamilyChild({ devices: 1, pet_id: 7 }), base)).toBe(true);
    expect(isChildConnected(makeFamilyChild({ devices: 2, pet_id: 7 }), { devices: 1, pet_id: 7 })).toBe(true);
    expect(isChildConnected(makeFamilyChild({ devices: 3, pet_id: 7 }), { devices: 3, pet_id: 7 })).toBe(false); // known limit
  });

  it('normalizeNickname collapses whitespace and refuses empty / too long / e-mail-like', () => {
    expect(normalizeNickname('  Maja   Mala ')).toBe('Maja Mala');
    expect(normalizeNickname('   ')).toBeNull();
    expect(normalizeNickname('a'.repeat(31))).toBeNull();
    expect(normalizeNickname('maja@x.si')).toBeNull();
    expect(normalizeNickname('Žan-Luka')).toBe('Žan-Luka');
  });

  it('parseBirthYear: optional, 4 digits, within the last 18 years', () => {
    expect(parseBirthYear('', NOW)).toBeNull();
    expect(parseBirthYear('2016', NOW)).toBe(2016);
    expect(parseBirthYear('2008', NOW)).toBe(2008);
    expect(parseBirthYear('2007', NOW)).toBe('invalid');
    expect(parseBirthYear('2027', NOW)).toBe('invalid');
    expect(parseBirthYear('16', NOW)).toBe('invalid');
  });

  it('classifies create / revoke / generate-pin failures', () => {
    expect(classifyCreateChildError(new ApiError('x', 422, { reason: 'too_many_children' }))).toBe('too_many_children');
    expect(classifyCreateChildError(new ApiError('x', 422, { errors: {} }))).toBe('invalid_name');
    expect(classifyCreateChildError(new ApiError('x', 500))).toBe('server');
    expect(classifyCreateChildError(new TypeError('offline'))).toBe('offline');

    expect(classifyRevokeError(new ApiError('x', 404))).toBe('not_found');
    expect(classifyRevokeError(new ApiError('x', 500))).toBe('server');
    expect(classifyRevokeError(new TypeError('offline'))).toBe('offline');

    expect(classifyPinError(new ApiError('x', 404, { reason: 'child_not_found' })).kind).toBe('child_not_found');
    expect(classifyPinError(new ApiError('x', 422, { reason: 'pet_not_joinable' })).kind).toBe('pet_not_joinable');
    expect(classifyPinError(new ApiError('x', 422, { reason: 'already_paired' })).kind).toBe('already_paired');
    expect(classifyPinError(new ApiError('x', 422, { errors: {} })).kind).toBe('server');
  });
});
