/**
 * M5-T13 — the first birthday, pure parts: reading the server's `birthday`, when the hat shows,
 * the wording (the SL name is never declined; cat = »muca« / "kitty"), the safe cake (never
 * chocolate) and the sourced tip, and the once-per-pet card memory.
 */
import i18n from '@/i18n';
import { resources } from '@/i18n/resources';
import {
  BIRTHDAY_TIP_SOURCE,
  birthdayAgeLine,
  birthdayCakeLine,
  birthdayPayload,
  birthdayTip,
  birthdayTitle,
  parentBirthdayLine,
  readBirthday,
  showBirthdayHat,
} from '@/modules/birthday/birthday';
import { hasSeenBirthday, markBirthdaySeen, setBirthdayStoreForTests } from '@/modules/birthday/birthdaySeen';
import { applyBroadcast, normalizeChildState } from '@/modules/childPet/childPetView';
import { makeBroadcast, makeLiveChildState } from '@/test-utils/fixtures';

const FSI = '⁨';
const PDI = '⁩';

afterEach(async () => {
  await i18n.changeLanguage('sl');
  setBirthdayStoreForTests(null);
});

describe('readBirthday', () => {
  it('reads the server object and round-trips it', () => {
    const raw = { date: '2026-12-16', is_today: true, celebrated: false };
    expect(readBirthday(raw)).toEqual({ date: '2026-12-16', isToday: true, celebrated: false });
    expect(birthdayPayload(readBirthday(raw))).toEqual(raw);
  });

  it('treats missing / malformed data (older server, no birthday) as none', () => {
    for (const raw of [undefined, null, 'x', [], {}, { date: '16. 12.' }, { date: 20261216 }]) {
      expect(readBirthday(raw)).toBeNull();
    }
    expect(birthdayPayload(null)).toBeNull();
  });
});

describe('showBirthdayHat', () => {
  it('shows only on the day and never over a lock', () => {
    const today = readBirthday({ date: '2026-12-16', is_today: true, celebrated: true });
    const later = readBirthday({ date: '2026-12-16', is_today: false, celebrated: false });
    expect(showBirthdayHat(today, false)).toBe(true);
    expect(showBirthdayHat(today, true)).toBe(false);
    expect(showBirthdayHat(later, false)).toBe(false);
    expect(showBirthdayHat(null, false)).toBe(false);
  });
});

describe('wording', () => {
  it('SL: the name stays in the nominative (address / subject), species agreement, no declension', () => {
    expect(birthdayTitle('Luna', 'dog')).toBe(`Vse najboljše, ${FSI}Luna${PDI}!`);
    expect(birthdayAgeLine('Luna', 'dog')).toBe(`${FSI}Luna${PDI} je danes star 1 leto.`);
    expect(birthdayAgeLine('Luna', 'cat')).toBe(`${FSI}Luna${PDI} je danes stara 1 leto.`);
    expect(birthdayTitle(null, 'dog')).toBe('Vse najboljše, kuža!');
    expect(birthdayTitle(null, 'cat')).toBe('Vse najboljše, muca!');
    expect(parentBirthdayLine('Luna', 'cat')).toBe(`${FSI}Luna${PDI} danes praznuje prvi rojstni dan.`);
  });

  it('EN: "pup" / "kitty"', async () => {
    await i18n.changeLanguage('en');
    expect(birthdayTitle(null, 'cat')).toBe('Happy birthday, kitty!');
    expect(birthdayAgeLine(null, 'dog')).toBe('Your pup is 1 year old today.');
    expect(birthdayTitle('Luna', 'dog')).toBe(`Happy birthday, ${FSI}Luna${PDI}!`);
  });

  it('the cake is always a safe treat — it says no chocolate — and the tip carries its source', async () => {
    for (const lang of ['sl', 'en'] as const) {
      await i18n.changeLanguage(lang);
      for (const species of ['dog', 'cat'] as const) {
        const cake = birthdayCakeLine(species);
        expect(cake).toMatch(lang === 'sl' ? /brez čokolade/ : /no chocolate/);
        const tip = birthdayTip(species);
        expect(tip.text).toMatch(lang === 'sl' ? /čokolada/i : /chocolate/i);
        expect(tip.source).toContain(BIRTHDAY_TIP_SOURCE[species]);
      }
    }
  });

  it('cat texts never name a dog, and EN / SL have the same keys', () => {
    const dogWord = /(kuž|pes|psa|psi|pup|dog)/i;
    for (const lang of ['en', 'sl'] as const) {
      expect(JSON.stringify(resources[lang].birthday.cat)).not.toMatch(dogWord);
    }
    const keys = (o: object): string[] => Object.entries(o).flatMap(([k, v]) => (typeof v === 'object' && v !== null ? keys(v).map((s) => `${k}.${s}`) : [k])).sort();
    expect(keys(resources.sl.birthday)).toEqual(keys(resources.en.birthday));
  });
});

describe('child view', () => {
  it('reads `birthday` from the state and keeps it on a broadcast without one (older server)', () => {
    const view = normalizeChildState(makeLiveChildState({ pet: { birthday: { date: '2026-12-16', is_today: true, celebrated: false } } }));
    expect(view.pet.birthday).toEqual({ date: '2026-12-16', isToday: true, celebrated: false });

    const kept = applyBroadcast(view, makeBroadcast());
    expect(kept?.view.pet.birthday).toEqual(view.pet.birthday);

    const updated = applyBroadcast(view, makeBroadcast({ birthday: { date: '2026-12-16', is_today: true, celebrated: true }, event_type: 'pet_birthday' }));
    expect(updated?.view.pet.birthday?.celebrated).toBe(true);
  });
});

describe('birthdaySeen', () => {
  it('remembers the card per pet; an unreadable store counts as seen', async () => {
    const data = new Map<string, string>();
    setBirthdayStoreForTests({
      getItemAsync: (k) => Promise.resolve(data.get(k) ?? null),
      setItemAsync: (k, v) => {
        data.set(k, v);
        return Promise.resolve();
      },
    });
    expect(await hasSeenBirthday(7)).toBe(false);
    await markBirthdaySeen(7);
    expect(await hasSeenBirthday(7)).toBe(true);
    expect(await hasSeenBirthday(8)).toBe(false);

    setBirthdayStoreForTests({ getItemAsync: () => Promise.reject(new Error('x')), setItemAsync: () => Promise.reject(new Error('x')) });
    expect(await hasSeenBirthday(9)).toBe(true);
  });
});
