/**
 * "Album rasti" model (M5-R04 part 2): payload reading, the ≥ 2 rule, captions, dates
 * in the family zone and the expiry-based stale time.
 */
import {
  GROWTH_REFRESH_LEAD_MS,
  GROWTH_STRINGS,
  growthDate,
  growthStaleTime,
  growthTitle,
  growthViewerItems,
  hasGrowthSection,
  readGrowth,
  type GrowthEntry,
} from '@/modules/petMedia/growth';
import { makeGrowth, makeGrowthEntry } from '@/test-utils/fixtures';
import { i18n } from '@/i18n';

const entry = (overrides: Partial<GrowthEntry> = {}): GrowthEntry => ({
  generation: 1,
  stage: 'puppy',
  ageMonths: 2,
  takenAt: '2026-10-04T09:00:00Z',
  isCurrent: false,
  url: 'https://api.petprep.si/api/media/history/1?v=1&signature=a',
  ...overrides,
});

describe('readGrowth', () => {
  it('reads the payload oldest first by generation', () => {
    const album = readGrowth(
      makeGrowth([
        makeGrowthEntry({ generation: 3, life_stage: 'adult', is_current: true }),
        makeGrowthEntry({ generation: 1 }),
        makeGrowthEntry({ generation: 2, life_stage: 'young', age_months: 9 }),
      ]),
    );
    expect(album.petId).toBe(7);
    expect(album.entries.map((e) => e.generation)).toEqual([1, 2, 3]);
    expect(album.entries[2]).toMatchObject({ stage: 'adult', isCurrent: true });
    expect(album.expiresAt).toBe('2026-10-04T10:00:00Z');
  });

  it('drops broken entries and survives garbage', () => {
    const album = readGrowth({
      pet_id: 7,
      growth: [null, { generation: 'x', image_url: 'u' }, { generation: 2, image_url: '' }, makeGrowthEntry({ life_stage: 'kitten' as never, age_months: -1 })],
      expires_at: '',
    });
    expect(album.entries).toHaveLength(1);
    expect(album.entries[0]).toMatchObject({ stage: null, ageMonths: null });
    expect(album.expiresAt).toBeNull();
    expect(readGrowth(undefined)).toEqual({ petId: 0, entries: [], expiresAt: null });
    expect(readGrowth({ growth: 'nope' }).entries).toEqual([]);
  });
});

describe('hasGrowthSection', () => {
  it('needs at least two pictures', () => {
    expect(hasGrowthSection(null)).toBe(false);
    expect(hasGrowthSection(undefined)).toBe(false);
    expect(hasGrowthSection({ petId: 7, entries: [], expiresAt: null })).toBe(false);
    expect(hasGrowthSection({ petId: 7, entries: [entry()], expiresAt: null })).toBe(false);
    expect(hasGrowthSection({ petId: 7, entries: [entry(), entry({ generation: 2 })], expiresAt: null })).toBe(true);
  });
});

describe('captions', () => {
  it('stage and age with Slovenian number forms; null stage → age only; legacy → null', () => {
    expect(growthTitle(entry({ stage: 'puppy', ageMonths: 2 }))).toBe('Mladiček · 2 meseca');
    expect(growthTitle(entry({ stage: 'young', ageMonths: 9 }))).toBe('Mlad pes · 9 mesecev');
    expect(growthTitle(entry({ stage: 'adult', ageMonths: 36 }))).toBe('Odrasel · 3 leta');
    expect(growthTitle(entry({ stage: 'senior', ageMonths: 1 }))).toBe('Starejši · 1 mesec');
    expect(growthTitle(entry({ stage: null, ageMonths: 3 }))).toBe('3 mesece');
    expect(growthTitle(entry({ stage: null, ageMonths: null }))).toBeNull();
  });

  it('date is the family-local day (d. M. yyyy)', () => {
    // 23:30 UTC on 4 Oct is already 5 Oct in Ljubljana.
    const late = entry({ takenAt: '2026-10-04T23:30:00Z' });
    expect(growthDate(late, 'Europe/Ljubljana')).toBe('5. 10. 2026');
    expect(growthDate(late, 'America/New_York')).toBe('4. 10. 2026');
    expect(growthDate(entry({ takenAt: null }), 'Europe/Ljubljana')).toBeNull();
    expect(growthDate(entry({ takenAt: 'nope' }), 'Europe/Ljubljana')).toBeNull();
  });

  it('viewer items: label, detail with "Zdaj" on the current one, strip parts', () => {
    const items = growthViewerItems(
      {
        petId: 7,
        entries: [
          entry(),
          entry({ generation: 2, stage: null, ageMonths: null, takenAt: null, isCurrent: true, url: 'https://x/2?v=2' }),
        ],
        expiresAt: null,
      },
      'Europe/Ljubljana',
    );
    expect(items[0]).toMatchObject({
      id: 'growth-1',
      label: 'Mladiček · 2 meseca',
      detail: '4. 10. 2026',
      stageLabel: 'Mladiček',
      ageLabel: '2 meseca',
      dateLabel: '4. 10. 2026',
      isCurrent: false,
    });
    expect(items[1]).toMatchObject({ label: GROWTH_STRINGS.photo, detail: 'Zdaj', stageLabel: null, ageLabel: null, dateLabel: null, key: 'https://x/2?v=2' });
  });

  it('captions in English (M1-18)', async () => {
    await i18n.changeLanguage('en');
    try {
      const items = growthViewerItems(
        { petId: 7, entries: [entry(), entry({ generation: 2, stage: null, ageMonths: null, takenAt: null, isCurrent: true, url: 'https://x/2?v=2' })], expiresAt: null },
        'Europe/Ljubljana',
      );
      expect(items[0]).toMatchObject({ label: 'Puppy · 2 months', detail: '4 Oct 2026' });
      expect(items[1]).toMatchObject({ label: 'Photo', detail: 'Now' });
    } finally {
      await i18n.changeLanguage('sl');
    }
  });
});

describe('growthStaleTime', () => {
  const now = Date.parse('2026-10-04T09:00:00Z');
  it('fresh until 1 min before the URLs expire', () => {
    const album = { petId: 7, entries: [], expiresAt: '2026-10-04T09:30:00Z' };
    expect(growthStaleTime(album, now)).toBe(30 * 60_000 - GROWTH_REFRESH_LEAD_MS);
    expect(growthStaleTime(album, Date.parse('2026-10-04T09:31:00Z'))).toBe(0);
  });
  it('no data / no expiry / broken expiry → 0', () => {
    expect(growthStaleTime(undefined, now)).toBe(0);
    expect(growthStaleTime({ petId: 7, entries: [], expiresAt: null }, now)).toBe(0);
    expect(growthStaleTime({ petId: 7, entries: [], expiresAt: 'x' }, now)).toBe(0);
  });
});
