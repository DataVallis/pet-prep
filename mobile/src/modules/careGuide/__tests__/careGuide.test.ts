/**
 * M5-T05 — the care guide renders the server's numbers (never its own) in EN and SL, for a
 * dog (incl. a puppy with »Pelji ven«) and a cat; first launch is remembered per pet.
 */
import { i18n, setTextSpecies } from '@/i18n';
import { careGuideSections, careGuideTitle, readCareGuide } from '@/modules/careGuide/careGuide';
import { hasSeenCareGuide, markCareGuideSeen, setCareGuideStoreForTests } from '@/modules/careGuide/careGuideSeen';
import { makeCareGuide } from '@/test-utils/fixtures';

function texts(raw: Record<string, unknown>): Record<string, string> {
  const guide = readCareGuide(raw);
  if (!guide) throw new Error('no guide');
  return Object.fromEntries(careGuideSections(guide).map((s) => [s.key, s.lines.join(' ')]));
}

const puppy = makeCareGuide({
  life_stage: 'puppy',
  meals: {
    per_day: 4,
    by_child: 3,
    by_parent: 1,
    windows: [
      { start: '07:00', end: '08:00', parent_covered: false },
      { start: '11:00', end: '12:00', parent_covered: true },
      { start: '15:00', end: '16:00', parent_covered: false },
      { start: '19:00', end: '20:00', parent_covered: false },
    ],
  },
  steps: { goal: 1000, exercise_minutes: 10 },
  take_out: { hold_hours: 2 },
  grooming: { per_week: 3 },
  sleep: { night: { start: '21:00', end: '07:00' }, school: { start: '08:00', end: '13:00' } },
  digestion_gap_minutes: 60,
});

const cat = makeCareGuide({
  species: 'cat',
  steps: null,
  take_out: null,
  play: { sessions_per_day: 2, min_gap_minutes: 120 },
  litter: { scoop_within_hours: 4, change_every_days: 7 },
  scratching: true,
  grooming: { per_week: 1 },
});

afterEach(async () => {
  setTextSpecies(null);
  await i18n.changeLanguage('sl');
});

describe('readCareGuide', () => {
  it('is null for an older server or a malformed block', () => {
    expect(readCareGuide(undefined)).toBeNull();
    expect(readCareGuide({ species: 'hamster', meals: {} })).toBeNull();
  });

  it('drops rules that do not apply (zero / missing)', () => {
    const guide = readCareGuide(makeCareGuide({ take_out: { hold_hours: 0 }, grooming: { per_week: 0 }, steps: null }))!;
    expect(guide.take_out).toBeNull();
    expect(guide.grooming).toBeNull();
    expect(careGuideSections(guide).map((s) => s.key)).toEqual(['food', 'water', 'sleep', 'mess']);
  });
});

describe('dog (SL)', () => {
  it('uses the server numbers: meals, parent meals, steps, take-out, difference, digestion, grooming, sleep', () => {
    const t = texts(puppy);
    expect(t.food).toBe('Kuža dobi 4 obroke na dan. Nahrani ga v teh časih: 7:00–8:00, 15:00–16:00, 19:00–20:00. Med tihimi urami ga nahrani starš: 11:00–12:00.');
    expect(t.water).toBe('Ko je posoda skoraj prazna (voda 60 % ali manj), mu dolij svežo vodo. Med dvema dolivanjema naj mine vsaj 3 h.');
    expect(t.walk).toBe('Danes potrebuje 1.000 korakov sprehoda. Koraki mu dajo energijo.');
    expect(t.takeOut).toBe('Mladiček zdrži približno 2 h. Prej ga pelji ven s tipko »Pelji ven«.');
    expect(t.difference).toContain('Koraki sprehoda kužka ne peljejo lulat');
    expect(t.digestion).toBe('Po obroku počakaj 1 h, preden greš na sprehod.');
    expect(t.grooming).toBe('Počeši ga 3-krat na teden.');
    expect(t.sleep).toBe(
      'Spi od 21:00 do 7:00. Takrat ga ne budimo: igra, šola, crkljanje in česanje počakajo do jutra. Spi tudi, ko si v šoli (8:00–13:00).',
    );
    expect(t.mess).toBe('Če naredi nered, ga počisti najkasneje v 2 h.');
  });

  it('follows a changed number (no hard-coded rules in the app)', () => {
    expect(texts(makeCareGuide({ steps: { goal: 12500, exercise_minutes: 125 } })).walk).toContain('12.500 korakov');
    expect(texts(makeCareGuide({ water: { refill_below: 45, min_gap_minutes: 90 } })).water).toContain('voda 45 % ali manj');
    expect(texts(makeCareGuide({ water: { refill_below: 45, min_gap_minutes: 90 } })).water).toContain('vsaj 90 min');
  });

  it('shows no take-out / difference for a dog that is not a puppy', () => {
    const keys = careGuideSections(readCareGuide(makeCareGuide())!).map((s) => s.key);
    expect(keys).toEqual(['food', 'water', 'walk', 'sleep', 'mess']);
  });

  it('title uses the name as a label, else the species noun', () => {
    expect(careGuideTitle('dog', null)).toBe('Kako skrbim za kužka');
    expect(careGuideTitle('dog', 'Luna')).toBe('Kako skrbim za: ⁨Luna⁩');
  });
});

describe('dog (EN)', () => {
  it('renders the same numbers in English', async () => {
    await i18n.changeLanguage('en');
    const t = texts(puppy);
    expect(t.food).toBe('Your pup gets 4 meals a day. Feed it at these times: 7:00–8:00, 15:00–16:00, 19:00–20:00. During quiet hours a parent feeds it: 11:00–12:00.');
    expect(t.walk).toBe('Today it needs 1,000 steps of walking. Steps give it energy.');
    expect(t.takeOut).toBe('A puppy can hold it for about 2 h. Before that, press “Take out”.');
    expect(t.difference).toContain('Walking steps never take your pup to pee');
    expect(t.grooming).toBe('Brush it 3 times a week.');
    expect(careGuideTitle('dog', null)).toBe('How I care for my pup');
    expect(careGuideTitle('cat', 'Mici')).toBe('How I care for ⁨Mici⁩');
  });
});

describe('cat', () => {
  it('SL: muca, wand play, litter, scratching; no steps or take-out', () => {
    const t = texts(cat);
    expect(Object.keys(t)).toEqual(['food', 'water', 'play', 'litter', 'scratching', 'grooming', 'sleep', 'mess']);
    expect(t.food).toBe('Muca dobi 2 obroka na dan. Nahrani jo v teh časih: 7:00–9:00, 17:00–19:00.');
    expect(t.play).toBe('Vsak dan se z njo 2-krat poigraj s palico s peresom. Med igrama naj mine vsaj 2 h.');
    expect(t.litter).toBe('Pesek počisti v 4 h po uporabi, ves pesek pa zamenjaj vsakih 7 dni.');
    expect(t.grooming).toBe('Počeši jo enkrat na teden.');
    expect(careGuideTitle('cat', null)).toBe('Kako skrbim za muco');
    expect(Object.values(t).join(' ')).not.toMatch(/kuža|kužk|pes\b/i);
  });

  it('EN: kitty + it', async () => {
    await i18n.changeLanguage('en');
    const t = texts(cat);
    expect(t.food).toBe('Your kitty gets 2 meals a day. Feed it at these times: 7:00–9:00, 17:00–19:00.');
    expect(t.play).toBe('Play with it with the feather wand 2 times a day, at least 2 h apart.');
    expect(t.scratching).toContain('scratching post');
    expect(careGuideTitle('cat', null)).toBe('How I care for my kitty');
    expect(Object.values(t).join(' ')).not.toMatch(/\b(pup|dog|puppy)\b/i);
  });
});

describe('first launch per pet', () => {
  function fakeStore() {
    const data = new Map<string, string>();
    return {
      getItemAsync: jest.fn((k: string) => Promise.resolve(data.get(k) ?? null)),
      setItemAsync: jest.fn((k: string, v: string) => {
        data.set(k, v);
        return Promise.resolve();
      }),
    };
  }

  afterEach(() => setCareGuideStoreForTests(null));

  it('remembers each pet separately', async () => {
    setCareGuideStoreForTests(fakeStore());
    expect(await hasSeenCareGuide(7)).toBe(false);
    await markCareGuideSeen(7);
    expect(await hasSeenCareGuide(7)).toBe(true);
    expect(await hasSeenCareGuide(8)).toBe(false);
  });

  it('a broken store never pops the card again and again', async () => {
    setCareGuideStoreForTests({ getItemAsync: () => Promise.reject(new Error('locked')), setItemAsync: () => Promise.reject(new Error('locked')) });
    expect(await hasSeenCareGuide(7)).toBe(true);
    await expect(markCareGuideSeen(7)).resolves.toBeUndefined();
  });
});
