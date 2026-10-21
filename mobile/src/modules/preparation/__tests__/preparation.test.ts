/**
 * M5-T06 — pet preparation, pure parts: reading the server summary, the wording (the SL name
 * is never declined; no mention of how the pet is made), the once-per-pet "ready" rule.
 */
import i18n from '@/i18n';
import {
  NO_PREPARATION,
  READY_NEWS_MAX_AGE_MS,
  parentPreparationTitle,
  preparationBody,
  preparationPayload,
  preparationTitle,
  readPreparation,
  readyMessage,
  schoolClipsPending,
  schoolPreparingText,
  shouldAnnounceReady,
  showPreparationScreen,
} from '@/modules/preparation/preparation';
import { resources } from '@/i18n/resources';

const FSI = '⁨';
const PDI = '⁩';

afterEach(async () => {
  await i18n.changeLanguage('sl');
});

describe('readPreparation', () => {
  it('reads the server summary', () => {
    expect(readPreparation({ ready: false, pending: ['idle', 'sleeping'], preparing: true, running: true, ready_at: null })).toEqual({
      ready: false,
      pending: ['idle', 'sleeping'],
      preparing: true,
      running: true,
      readyAt: null,
    });
  });

  it('treats a missing / malformed summary (older server) as ready — never a set-up screen', () => {
    expect(readPreparation(undefined)).toBe(NO_PREPARATION);
    expect(readPreparation(null)).toBe(NO_PREPARATION);
    expect(readPreparation('x')).toBe(NO_PREPARATION);
    expect(readPreparation([])).toBe(NO_PREPARATION);
  });

  it('drops unknown items and never shows the set-up screen once ready', () => {
    const p = readPreparation({ ready: true, pending: ['idle', 'nonsense'], preparing: true, running: false, ready_at: '2026-10-21T10:00:00+00:00' });
    expect(p.pending).toEqual([]);
    expect(p.preparing).toBe(false);
    expect(p.readyAt).toBe('2026-10-21T10:00:00+00:00');
  });

  it('round-trips to the wire shape', () => {
    const raw = { ready: false, pending: ['image' as const], preparing: true, running: true, ready_at: null };
    expect(preparationPayload(readPreparation(raw))).toEqual(raw);
  });
});

describe('showPreparationScreen', () => {
  it('shows only while the server says so and never over a lock', () => {
    const preparing = readPreparation({ ready: false, pending: ['idle'], preparing: true, running: true, ready_at: null });
    expect(showPreparationScreen(preparing, false)).toBe(true);
    expect(showPreparationScreen(preparing, true)).toBe(false);
    expect(showPreparationScreen(NO_PREPARATION, false)).toBe(false);
  });
});

describe('wording', () => {
  it('SL: the name stands after a colon / as the subject (never declined)', () => {
    expect(preparationTitle('Luna', 'dog')).toBe(`Pripravljamo: ${FSI}Luna${PDI}`);
    expect(readyMessage('Luna', 'dog')).toBe(`${FSI}Luna${PDI} je pripravljen!`);
    expect(readyMessage('Luna', 'cat')).toBe(`${FSI}Luna${PDI} je pripravljena!`);
    expect(preparationBody()).toBe('To lahko traja nekaj minut.');
  });

  it('SL: without a name the species noun', () => {
    expect(preparationTitle(null, 'dog')).toBe('Pripravljamo tvojega kužka');
    expect(preparationTitle(null, 'cat')).toBe('Pripravljamo tvojo muco');
    expect(readyMessage(null, 'dog')).toBe('Tvoj kuža je pripravljen!');
    expect(parentPreparationTitle(null, 'cat')).toBe('Pripravljamo muco');
    expect(schoolPreparingText()).toBe('Šola se še pripravlja');
  });

  it('EN', async () => {
    await i18n.changeLanguage('en');
    expect(preparationTitle('Luna', 'dog')).toBe(`Getting ${FSI}Luna${PDI} ready`);
    expect(preparationTitle(null, 'dog')).toBe('Getting your pup ready');
    expect(readyMessage('Luna', 'cat')).toBe(`${FSI}Luna${PDI} is ready!`);
    expect(preparationBody()).toBe('This can take a few minutes.');
  });

  it('never mentions AI, generating, images or videos (David 2026-10-20)', () => {
    const texts = [JSON.stringify(resources.en.preparation), JSON.stringify(resources.sl.preparation)].join(' ').toLowerCase();
    expect(texts).not.toMatch(/\bai\b|generat|ustvarj|video|slik|image|first to|prvi /);
  });
});

describe('shouldAnnounceReady', () => {
  const at = '2026-10-21T10:00:00+00:00';
  const readyAt = Date.parse(at);
  const ended = readPreparation({ ready: true, pending: [], preparing: false, running: false, ready_at: at });

  it('once, when the shown preparation ended recently and the HUD is not locked', () => {
    expect(shouldAnnounceReady(ended, false, false, readyAt + 60_000)).toBe(true);
    expect(shouldAnnounceReady(ended, true, false, readyAt + 60_000)).toBe(false);
    expect(shouldAnnounceReady(ended, false, true, readyAt + 60_000)).toBe(false);
  });

  it('not for a pet that never showed the preparation, nor days later', () => {
    expect(shouldAnnounceReady(NO_PREPARATION, false, false, readyAt)).toBe(false);
    expect(shouldAnnounceReady(ended, false, false, readyAt + READY_NEWS_MAX_AGE_MS + 1)).toBe(false);
  });
});

describe('schoolClipsPending', () => {
  it('is true while idle or playing is still on its way', () => {
    expect(schoolClipsPending(readPreparation({ ready: false, pending: ['playing'], preparing: false, running: true, ready_at: null }))).toBe(true);
    expect(schoolClipsPending(readPreparation({ ready: false, pending: ['sick'], preparing: false, running: true, ready_at: null }))).toBe(false);
    expect(schoolClipsPending(NO_PREPARATION)).toBe(false);
  });
});
