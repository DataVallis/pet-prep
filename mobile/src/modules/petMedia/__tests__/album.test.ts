import {
  ALBUM_REFRESH_COOLDOWN_MS,
  ALBUM_STRINGS,
  buildAlbumItems,
  hasAlbum,
  isPlayable,
  mayRefresh,
  refreshDelay,
  stepIndex,
  swipeDirection,
} from '@/modules/petMedia/album';
import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import { makeMedia } from '@/test-utils/fixtures';

const url = (id: number, v: string) => `https://api.petprep.si/api/media/${id}?expires=1&v=${v}&signature=s`;
const IMG = url(1, 'img');
const IDLE = url(2, 'idle');
const SLEEP = url(3, 'sleep');
const SICK = url(4, 'sick');

describe('buildAlbumItems', () => {
  it('photo first, then every entitled state in a fixed order with Slovenian labels', () => {
    const items = buildAlbumItems(
      normalizePetMedia(
        makeMedia({
          status: 'ready',
          reference_image_url: IMG,
          videos: { sick: SICK, idle: IDLE, sleeping: SLEEP },
          states: ['sick', 'sleeping', 'idle'],
        }),
      ),
    );

    expect(items.map((i) => [i.kind, i.id, i.label])).toEqual([
      ['photo', 'photo', 'Fotografija'],
      ['video', 'idle', 'Miruje'],
      ['video', 'sleeping', 'Spi'],
      ['video', 'sick', 'Bolan'],
    ]);
  });

  it('entitled but not stored yet → greyed "missing" item; not entitled → hidden (no teasing)', () => {
    const items = buildAlbumItems(
      normalizePetMedia(
        makeMedia({
          status: 'partial',
          reference_image_url: IMG,
          // A stray stored video outside the entitlement must not appear either.
          videos: { idle: IDLE, playing: url(9, 'play') },
          states: ['idle', 'sleeping'],
        }),
      ),
    );

    expect(items.map((i) => `${i.kind}:${i.id}`)).toEqual(['photo:photo', 'video:idle', 'missing:sleeping']);
    expect(items.filter(isPlayable)).toHaveLength(2);
    expect(items.some((i) => i.id === 'playing' || i.id === 'hungry')).toBe(false);
  });

  it('all six labels exist for a premium breed', () => {
    expect(Object.values(ALBUM_STRINGS.states)).toEqual(['Miruje', 'Spi', 'Utrujen', 'Lačen', 'Bolan', 'Se igra']);
    const items = buildAlbumItems(
      normalizePetMedia(makeMedia({ status: 'pending', states: ['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing'] })),
    );
    expect(items.map((i) => i.label)).toEqual(['Miruje', 'Spi', 'Utrujen', 'Lačen', 'Bolan', 'Se igra']);
    expect(items.every((i) => i.kind === 'missing')).toBe(true);
  });

  it('PR #31: nothing stored + generation disabled / failed → empty album (backend `states` present)', () => {
    for (const status of ['disabled', 'failed'] as const) {
      const media = normalizePetMedia(makeMedia({ status, states: ['idle', 'sleeping'] }));
      expect(buildAlbumItems(media)).toEqual([]);
      expect(hasAlbum(media)).toBe(false);
    }
  });

  it('PR #31: still generating → greyed tiles stay; failed with a photo → photo + greyed tiles', () => {
    for (const status of ['pending', 'partial'] as const) {
      const media = normalizePetMedia(makeMedia({ status, states: ['idle', 'sleeping'] }));
      expect(buildAlbumItems(media).map((i) => i.kind)).toEqual(['missing', 'missing']);
      expect(hasAlbum(media)).toBe(true);
    }
    const failedWithPhoto = normalizePetMedia(makeMedia({ status: 'failed', reference_image_url: IMG, states: ['idle'] }));
    expect(buildAlbumItems(failedWithPhoto).map((i) => i.kind)).toEqual(['photo', 'missing']);
  });

  it('no media at all → empty album', () => {
    expect(buildAlbumItems(normalizePetMedia(makeMedia({ states: [] })))).toEqual([]);
  });

  it('older payloads: without states the stored videos count, or the single current video', () => {
    expect(
      buildAlbumItems(normalizePetMedia(makeMedia({ videos: { sleeping: SLEEP }, states: [] }))).map((i) => i.id),
    ).toEqual(['sleeping']);
    expect(buildAlbumItems(normalizePetMedia(null, { current_video_url: IDLE }))).toEqual([
      expect.objectContaining({ kind: 'video', id: 'idle', url: IDLE }),
    ]);
  });

  it('keys items by media identity (path + v), not the signature', () => {
    const [photo] = buildAlbumItems(normalizePetMedia(makeMedia({ reference_image_url: IMG })));
    expect(photo).toMatchObject({ key: 'https://api.petprep.si/api/media/1?v=img' });
  });
});

describe('navigation helpers', () => {
  it('stepIndex wraps around and handles an empty list', () => {
    expect(stepIndex(0, 1, 3)).toBe(1);
    expect(stepIndex(2, 1, 3)).toBe(0);
    expect(stepIndex(0, -1, 3)).toBe(2);
    expect(stepIndex(0, 1, 0)).toBeNull();
  });

  it('swipeDirection: left swipe = next, right = previous, small / vertical moves ignored', () => {
    expect(swipeDirection(-80, 5)).toBe(1);
    expect(swipeDirection(80, 5)).toBe(-1);
    expect(swipeDirection(30, 0)).toBeNull();
    expect(swipeDirection(-80, 70)).toBeNull();
  });
});

describe('refresh policy', () => {
  it('refreshes once per media, again after the 5-min cooldown', () => {
    expect(mayRefresh(undefined, 1_000)).toBe(true);
    expect(mayRefresh(1_000, 1_000 + ALBUM_REFRESH_COOLDOWN_MS - 1)).toBe(false);
    expect(mayRefresh(1_000, 1_000 + ALBUM_REFRESH_COOLDOWN_MS)).toBe(true);
  });

  it('refreshes 1 min before the signed URLs expire', () => {
    const now = Date.parse('2026-10-05T10:00:00Z');
    expect(refreshDelay('2026-10-05T10:30:00Z', now)).toBe(29 * 60_000);
    expect(refreshDelay('2026-10-05T10:00:30Z', now)).toBe(0);
    expect(refreshDelay(null, now)).toBeNull();
    expect(refreshDelay('soon', now)).toBeNull();
  });
});
