import {
  ACQUISITION_SOURCES,
  EMPTY_ACQUISITION,
  acquisitionFields,
  acquisitionUnanswered,
  isAcquisitionSource,
  normalizeDetail,
  shouldOfferAcquisitionCard,
  sourceTakesDetail,
  toggleSource,
} from '../acquisition';
import { parseDoneIds } from '../acquisitionPrefs';

describe('acquisition source', () => {
  it('lists the 10 contract values in order', () => {
    expect(ACQUISITION_SOURCES).toEqual([
      'friend_family',
      'instagram',
      'tiktok',
      'facebook',
      'youtube',
      'school_kindergarten',
      'search',
      'website',
      'ad',
      'other',
    ]);
    expect(isAcquisitionSource('ad')).toBe(true);
    expect(isAcquisitionSource('radio')).toBe(false);
  });

  it('only ad / other take a detail', () => {
    expect(ACQUISITION_SOURCES.filter(sourceTakesDetail)).toEqual(['ad', 'other']);
    expect(sourceTakesDetail(null)).toBe(false);
  });

  it('nothing picked → no fields at all (never blocks sign-up)', () => {
    expect(acquisitionFields(EMPTY_ACQUISITION)).toEqual({});
    expect(acquisitionFields({ source: null, detail: 'leftover' })).toEqual({});
  });

  it('keeps the detail only for ad / other', () => {
    expect(acquisitionFields({ source: 'instagram', detail: 'code' })).toEqual({ acquisition_source: 'instagram' });
    expect(acquisitionFields({ source: 'ad', detail: '  JESEN26  ' })).toEqual({ acquisition_source: 'ad', acquisition_detail: 'JESEN26' });
    expect(acquisitionFields({ source: 'other', detail: 'Radio   City' })).toEqual({ acquisition_source: 'other', acquisition_detail: 'Radio City' });
    expect(acquisitionFields({ source: 'other', detail: '   ' })).toEqual({ acquisition_source: 'other' });
  });

  it('trims the detail to 60 characters', () => {
    expect(normalizeDetail('x'.repeat(80))).toHaveLength(60);
    expect([...normalizeDetail('č'.repeat(70))]).toHaveLength(60);
  });

  it('toggling selects, re-tapping clears, the detail is dropped when it no longer applies', () => {
    const ad = toggleSource(EMPTY_ACQUISITION, 'ad');
    expect(ad).toEqual({ source: 'ad', detail: '' });
    const typed = { ...ad, detail: 'KODA' };
    expect(toggleSource(typed, 'other')).toEqual({ source: 'other', detail: 'KODA' });
    expect(toggleSource(typed, 'tiktok')).toEqual({ source: 'tiktok', detail: '' });
    expect(toggleSource(typed, 'ad')).toEqual({ source: null, detail: '' });
  });
});

describe('dashboard card visibility', () => {
  const parent = (acquisition_source?: unknown) =>
    acquisition_source === undefined ? { role: 'parent' } : { role: 'parent', acquisition_source };

  it('only an explicit null from the server is "unanswered"', () => {
    expect(acquisitionUnanswered(parent(null))).toBe(true);
    expect(acquisitionUnanswered(parent('instagram'))).toBe(false);
    expect(acquisitionUnanswered(parent())).toBe(false); // older server
    expect(acquisitionUnanswered({ role: 'child', acquisition_source: null })).toBe(false);
    expect(acquisitionUnanswered(undefined)).toBe(false);
  });

  it('waits for the local store and respects the dismissal', () => {
    expect(shouldOfferAcquisitionCard({ user: parent(null), prefsLoaded: true, dismissed: false })).toBe(true);
    expect(shouldOfferAcquisitionCard({ user: parent(null), prefsLoaded: false, dismissed: false })).toBe(false);
    expect(shouldOfferAcquisitionCard({ user: parent(null), prefsLoaded: true, dismissed: true })).toBe(false);
    expect(shouldOfferAcquisitionCard({ user: parent('ad'), prefsLoaded: true, dismissed: false })).toBe(false);
    expect(shouldOfferAcquisitionCard({ user: undefined, prefsLoaded: true, dismissed: false })).toBe(false);
  });

  it('reads the stored ids defensively', () => {
    expect(parseDoneIds(null)).toEqual([]);
    expect(parseDoneIds('not json')).toEqual([]);
    expect(parseDoneIds('{"a":1}')).toEqual([]);
    expect(parseDoneIds('[1,"2",3.5,4]')).toEqual([1, 4]);
  });
});
