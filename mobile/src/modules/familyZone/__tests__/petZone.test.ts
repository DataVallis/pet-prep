/**
 * Time zone per pet (David 2026-11-09): which pets the banner suggests, and that every time of a
 * pet is read on that pet's clock. November 2026: Ljubljana UTC+1, Lisbon UTC+0.
 */
import {
  followsFamily,
  petZoneNote,
  petZoneOf,
  petZoneSuggestion,
  phonePetIds,
  type ZonePet,
} from '@/modules/familyZone/familyZone';
import { activityWhenText, missedWhenText } from '@/modules/family/scoring';
import { whenText } from '@/modules/childPet/familyTime';

const AT = Date.parse('2026-11-09T12:00:00Z');

function pet(id: number, zone: string | undefined, childIds: number[], extra: Partial<ZonePet> = {}): ZonePet {
  return {
    id,
    timezone: zone,
    timezone_follows_family: zone === undefined,
    is_active: true,
    is_game_over: false,
    caretakers: childIds.map((child_id) => ({ child_id })),
    ...extra,
  };
}

describe('petZoneOf / followsFamily', () => {
  it('reads the pet zone, else the family zone (older server)', () => {
    expect(petZoneOf(pet(1, 'Europe/Lisbon', []), 'Europe/Ljubljana')).toBe('Europe/Lisbon');
    expect(petZoneOf(pet(1, undefined, []), 'Europe/Ljubljana')).toBe('Europe/Ljubljana');
    expect(petZoneOf(null, 'Europe/Ljubljana')).toBe('Europe/Ljubljana');
    expect(followsFamily({ timezone_follows_family: false })).toBe(false);
    expect(followsFamily({})).toBe(true);
  });
});

describe('phonePetIds', () => {
  it('lists the active pets of the child profiles signed in on this phone', () => {
    const pets = [pet(1, undefined, [10]), pet(2, undefined, [11]), pet(3, undefined, [10], { is_active: false })];
    expect(phonePetIds(pets, [10])).toEqual([1]);
    expect(phonePetIds(pets, [])).toEqual([]);
    expect(phonePetIds([{ id: 4, caretakers: [{ child_id: null }] }], [10])).toEqual([]);
  });
});

describe('petZoneSuggestion (banner)', () => {
  // David in Lisbon; his own profile (child 10) cares for Suki (1), his wife's child 11 for Bela (2).
  const family = [pet(1, 'Europe/Ljubljana', [10]), pet(2, 'Europe/Ljubljana', [11]), pet(3, 'Europe/Lisbon', [12])];

  it('suggests only when a pet of this phone runs on another clock; its pets first and pre-checked', () => {
    const rows = petZoneSuggestion({
      pets: family,
      familyZone: 'Europe/Ljubljana',
      phoneZone: 'Europe/Lisbon',
      phonePetIds: [1],
      dismissedZone: null,
      atMs: AT,
    });
    expect(rows).toEqual([
      { petId: 1, zone: 'Europe/Ljubljana', preselected: true },
      { petId: 2, zone: 'Europe/Ljubljana', preselected: false },
    ]);
  });

  it('never suggests pets of other family members alone', () => {
    expect(
      petZoneSuggestion({ pets: family, familyZone: 'Europe/Ljubljana', phoneZone: 'Europe/Lisbon', phonePetIds: [3], dismissedZone: null, atMs: AT }),
    ).toEqual([]);
    expect(
      petZoneSuggestion({ pets: family, familyZone: 'Europe/Ljubljana', phoneZone: 'Europe/Lisbon', phonePetIds: [], dismissedZone: null, atMs: AT }),
    ).toEqual([]);
  });

  it('is hidden for a dismissed phone zone, an unknown phone zone and the same clock', () => {
    const base = { pets: family, familyZone: 'Europe/Ljubljana', phonePetIds: [1], atMs: AT };
    expect(petZoneSuggestion({ ...base, phoneZone: 'Europe/Lisbon', dismissedZone: 'Europe/Lisbon' })).toEqual([]);
    expect(petZoneSuggestion({ ...base, phoneZone: null, dismissedZone: null })).toEqual([]);
    expect(petZoneSuggestion({ ...base, phoneZone: 'Europe/Vienna', dismissedZone: null })).toEqual([]);
  });

  it('skips inactive and game-over pets', () => {
    const pets = [pet(1, 'Europe/Ljubljana', [10], { is_game_over: true }), pet(2, 'Europe/Ljubljana', [10], { is_active: false })];
    expect(
      petZoneSuggestion({ pets, familyZone: 'Europe/Ljubljana', phoneZone: 'Europe/Lisbon', phonePetIds: [1, 2], dismissedZone: null, atMs: AT }),
    ).toEqual([]);
  });
});

describe('per-pet time formatting', () => {
  const ljubljana = pet(1, 'Europe/Ljubljana', [10]);
  const lisbon = pet(2, 'Europe/Lisbon', [11]);

  it('reads the same instant on each pet\'s own clock', () => {
    const missed = { type: 'feed' as const, kind: null, date: '2026-11-09', opens_at: '2026-11-09T18:00:00Z', due_at: '2026-11-09T19:00:00Z' };
    expect(missedWhenText(missed, petZoneOf(ljubljana, 'Europe/Ljubljana'))).toContain('19:00');
    expect(missedWhenText(missed, petZoneOf(lisbon, 'Europe/Ljubljana'))).toContain('18:00');
    expect(activityWhenText('2026-11-09T18:00:00Z', petZoneOf(lisbon, 'Europe/Ljubljana'), '2026-11-09')).toContain('18:00');
    expect(whenText('2026-11-10T07:00:00Z', '2026-11-09T21:00:00Z', petZoneOf(ljubljana, null))).toContain('8:00');
    expect(whenText('2026-11-10T07:00:00Z', '2026-11-09T21:00:00Z', petZoneOf(lisbon, null))).toContain('7:00');
  });

  it('labels a pet\'s times only when its clock is not the phone\'s', () => {
    expect(petZoneNote('Europe/Lisbon', 'Europe/Ljubljana', AT, 'sl')).toBe('Lizbona');
    expect(petZoneNote('Europe/Ljubljana', 'Europe/Vienna', AT, 'sl')).toBeNull();
    expect(petZoneNote('Europe/Lisbon', null, AT, 'sl')).toBeNull();
  });
});
