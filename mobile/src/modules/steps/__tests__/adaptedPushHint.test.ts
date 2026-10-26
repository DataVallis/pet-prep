/**
 * M5-T12 (David 2026-10-26): what counts pushes (iPhone → Apple Watch only, Android → Health
 * Connect) and when the parent sees "Danes še ni potiskov" (adapted mode, a dog that can walk,
 * 0 steps and 0 logged minutes today, after 12:00 family time).
 */
import { i18n } from '@/i18n';
import { familyFromDashboard, normalizePet, type FamilyOverview, type FamilyPet } from '@/modules/family/family';
import {
  ADAPTED_PUSH_STRINGS,
  canLogWalk,
  noPushesHintDue,
  pushSourceLines,
} from '@/modules/steps/adaptedPushHint';
import { makeDayRow, makeFamilyPet, makeLast7Days, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';

const TZ = 'Europe/Ljubljana';
// 2026-10-04 is the fixtures' family "today" (CEST, UTC+2).
const at = (hhmm: string) => Date.parse(`2026-10-04T${hhmm}:00+02:00`);

function days(today: Partial<ReturnType<typeof makeDayRow>> = {}) {
  const rows = makeLast7Days();
  rows[rows.length - 1] = makeDayRow('2026-10-04', { walk_steps: 0, walk_done: false, walk_logged_minutes: 0, ...today });
  return rows;
}

function child(overrides: Parameters<typeof makeScoredChild>[0] = {}) {
  return makeScoredChild({ adapted_mode: true, last_7_days: days(), ...overrides });
}

const dog = (overrides: Partial<ReturnType<typeof makeFamilyPet>> = {}): FamilyPet => normalizePet(makeFamilyPet(overrides));

describe('noPushesHintDue', () => {
  it('adapted mode, 0 pushes and no logged walk after noon → shown', () => {
    expect(noPushesHintDue(child(), dog(), TZ, at('12:00'))).toBe(true);
    expect(noPushesHintDue(child(), dog(), TZ, at('18:30'))).toBe(true);
  });

  it('before noon family time → not yet', () => {
    expect(noPushesHintDue(child(), dog(), TZ, at('11:59'))).toBe(false);
    // 11:30 UTC is 13:30 in Ljubljana — the family zone decides, not UTC.
    expect(noPushesHintDue(child(), dog(), TZ, Date.parse('2026-10-04T11:30:00Z'))).toBe(true);
    expect(noPushesHintDue(child(), dog(), 'America/New_York', Date.parse('2026-10-04T11:30:00Z'))).toBe(false);
  });

  it('a null family zone falls back to Europe/Ljubljana', () => {
    expect(noPushesHintDue(child(), dog(), null, at('13:00'))).toBe(true);
  });

  it('adapted mode off → never', () => {
    expect(noPushesHintDue(child({ adapted_mode: false }), dog(), TZ, at('15:00'))).toBe(false);
  });

  it('any pushes or a logged walk today → hidden', () => {
    expect(noPushesHintDue(child({ last_7_days: days({ walk_steps: 1 }) }), dog(), TZ, at('15:00'))).toBe(false);
    expect(noPushesHintDue(child({ last_7_days: days({ walk_steps: 3000, walk_logged_minutes: 30 }) }), dog(), TZ, at('15:00'))).toBe(false);
  });

  it('no walk goal today, no today row, no pet, a cat or a locked pet → hidden', () => {
    expect(noPushesHintDue(child({ last_7_days: days({ walk_goal: null }) }), dog(), TZ, at('15:00'))).toBe(false);
    expect(noPushesHintDue(child({ last_7_days: makeLast7Days().slice(0, 6) }), dog(), TZ, at('15:00'))).toBe(false);
    expect(noPushesHintDue(child(), null, TZ, at('15:00'))).toBe(false);
    expect(noPushesHintDue(child(), dog({ species: 'cat', breed_type: 'maine_coon' }), TZ, at('15:00'))).toBe(false);
    expect(noPushesHintDue(child(), dog({ is_ill: true }), TZ, at('15:00'))).toBe(false);
    expect(noPushesHintDue(child(), dog({ is_hard_stopped: true }), TZ, at('15:00'))).toBe(false);
    expect(noPushesHintDue(child(), dog({ is_game_over: true }), TZ, at('15:00'))).toBe(false);
    expect(noPushesHintDue(child({ contract_signed: false }), dog(), TZ, at('15:00'))).toBe(false);
  });
});

describe('canLogWalk', () => {
  function family(adapted: boolean, species: 'dog' | 'cat' = 'dog'): FamilyOverview {
    return familyFromDashboard(
      makeScoredDashboard(
        [makeScoredChild({ id: 2, pet_id: 7, adapted_mode: adapted })],
        [makeFamilyPet({ id: 7, species, caretakers: [{ child_id: 2, contract_signed: true }] })],
      ) as never,
    ) as FamilyOverview;
  }

  it('only a dog of a child in adapted mode', () => {
    const on = family(true);
    expect(canLogWalk(on.children[0], on)).toBe(true);
    const off = family(false);
    expect(canLogWalk(off.children[0], off)).toBe(false);
    const cat = family(true, 'cat');
    expect(canLogWalk(cat.children[0], cat)).toBe(false);
  });
});

describe('texts (sl + en)', () => {
  afterEach(async () => {
    await i18n.changeLanguage('sl');
  });

  it('Slovenian: both phones (OS unknown), then the no-watch way', () => {
    expect(pushSourceLines()).toEqual([
      'Na iPhonu potiske šteje samo Apple Watch (v aplikaciji Zdravje: Podatki o zdravju → Invalidski voziček = Da). Sam iPhone potiskov ne šteje.',
      'Na Androidu potrebujete napravo ali aplikacijo, ki zapisuje potiske invalidskega vozička v Health Connect.',
      'Brez ure lahko vpišete minute sprehoda na strani otroka (Pregled → otrok → Vpiši sprehod).',
    ]);
    expect(ADAPTED_PUSH_STRINGS.adapted.noPushesToday.text).toBe(
      'Danes še ni potiskov. Brez Apple Watch (ali naprave za Health Connect) vpišite minute sprehoda.',
    );
    expect(ADAPTED_PUSH_STRINGS.adapted.noPushesToday.button).toBe('Vpiši sprehod');
  });

  it('a known platform shows only its line', () => {
    expect(pushSourceLines('ios')).toHaveLength(2);
    expect(pushSourceLines('ios')[0]).toMatch(/^Na iPhonu/);
    expect(pushSourceLines('android')[0]).toMatch(/^Na Androidu/);
  });

  it('English', async () => {
    await i18n.changeLanguage('en');
    expect(pushSourceLines()).toEqual([
      'On iPhone, only an Apple Watch counts pushes (in the Health app: Health Details → Wheelchair = Yes). The iPhone alone doesn\'t count pushes.',
      'On Android, you need a device or an app that records wheelchair pushes in Health Connect.',
      'Without a watch you can log walk minutes on your child\'s page (Overview → child → Log a walk).',
    ]);
    expect(ADAPTED_PUSH_STRINGS.adapted.noPushesToday.text).toBe(
      'No pushes yet today. Without an Apple Watch (or a Health Connect device), log the walk minutes.',
    );
    expect(ADAPTED_PUSH_STRINGS.adapted.noPushesToday.button).toBe('Log a walk');
  });
});
