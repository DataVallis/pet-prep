/**
 * M6-W03 bought items in the album (David 2026-11-04): »Spi« shows the bed exactly like the live
 * sleeping scene, and every owned item gets its own tile (clip / picture mode → image).
 */
import { ALBUM_STRINGS, buildAlbumItems, hasAlbum, isPlayable, type AlbumItem } from '@/modules/petMedia/album';
import { normalizePetMedia, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import { steadyItem } from '@/modules/petMedia/statePlayer';
import type { PetMedia } from '@/types';
import { makeMedia } from '@/test-utils/fixtures';

const url = (path: string, v: string) => `https://api.petprep.si/api/media/${path}?expires=1&v=${v}&signature=s`;
const IMG = url('1', 'img');
const IDLE = url('2', 'idle');
const SLEEP = url('3', 'sleep');
const BED_IMG = url('items/41', 'bedimg');
const BED_VID = url('items/42', 'bedvid');
const BOWL_IMG = url('items/51', 'bowlimg');
const BOWL_VID = url('items/52', 'bowlvid');
const TOY1_IMG = url('items/61', 'toy1img');
const TOY1_VID = url('items/62', 'toy1vid');
const TOY2_IMG = url('items/63', 'toy2img');
const TOY2_VID = url('items/64', 'toy2vid');
const TREAT_IMG = url('items/71', 'treatimg');

type RawItems = PetMedia['items'];

const loop = (image: string | null, video: string | null) => ({ image, video, video_seconds: video === null ? null : 3, loop: true });
const once = (image: string | null, video: string | null) => ({ image, video, video_seconds: video === null ? null : 3, loop: false });

function media(items: RawItems, overrides: Partial<PetMedia> = {}): PetMediaInfo {
  return normalizePetMedia(
    makeMedia({
      status: 'ready',
      reference_image_url: IMG,
      videos: { idle: IDLE, sleeping: SLEEP },
      states: ['idle', 'sleeping'],
      items,
      ...overrides,
    }),
  );
}

const summary = (items: AlbumItem[]) => items.map((i) => [i.kind, i.id, i.kind === 'missing' ? null : i.url]);
const byId = (items: AlbumItem[], id: string) => items.find((i) => i.id === id);

describe('album — bed on »Spi«', () => {
  it('without a bed the sleeping tile keeps its own clip and no item tiles appear', () => {
    expect(summary(buildAlbumItems(media({})))).toEqual([
      ['photo', 'photo', IMG],
      ['video', 'idle', IDLE],
      ['video', 'sleeping', SLEEP],
    ]);
  });

  it('with the bed the sleeping tile plays the bed clip (poster = bed image), no second bed tile', () => {
    const items = buildAlbumItems(media({ bed: loop(BED_IMG, BED_VID) }));
    expect(summary(items)).toEqual([
      ['photo', 'photo', IMG],
      ['video', 'idle', IDLE],
      ['video', 'sleeping', BED_VID],
    ]);
    expect(byId(items, 'sleeping')).toMatchObject({ label: 'Spi', poster: BED_IMG, state: 'sleeping' });
    expect(byId(items, 'bed')).toBeUndefined();
  });

  it('agrees with the live sleeping scene (same helper) in video and picture mode', () => {
    const m = media({ bed: loop(BED_IMG, BED_VID) });
    for (const mode of ['video', 'picture'] as const) {
      const live = steadyItem({ media: m, wanted: 'sleeping', petState: 'sleeping', mode });
      const tile = byId(buildAlbumItems(m, mode), 'sleeping');
      expect(live.kind === 'placeholder' ? null : live.url).toBe(tile !== undefined && tile.kind !== 'missing' ? tile.url : null);
    }
  });

  it('picture mode → the bed image as a photo tile', () => {
    expect(byId(buildAlbumItems(media({ bed: loop(BED_IMG, BED_VID) }), 'picture'), 'sleeping')).toMatchObject({
      kind: 'photo',
      url: BED_IMG,
      label: 'Spi',
    });
  });

  it('bed without a clip → its image; also replaces a not-yet-stored sleeping clip', () => {
    expect(byId(buildAlbumItems(media({ bed: loop(BED_IMG, null) })), 'sleeping')).toMatchObject({ kind: 'photo', url: BED_IMG });
    const noSleepClip = media({ bed: loop(BED_IMG, BED_VID) }, { videos: { idle: IDLE } });
    expect(byId(buildAlbumItems(noSleepClip), 'sleeping')).toMatchObject({ kind: 'video', url: BED_VID });
  });

  it('bed with only a clip in picture mode → nothing usable: the normal sleeping tile + no bed tile', () => {
    const items = buildAlbumItems(media({ bed: loop(null, BED_VID) }), 'picture');
    expect(byId(items, 'sleeping')).toMatchObject({ kind: 'video', url: SLEEP });
    expect(byId(items, 'bed')).toBeUndefined();
  });

  it('sleeping not in the album (not entitled) → the bed gets its own tile', () => {
    const items = buildAlbumItems(media({ bed: loop(BED_IMG, BED_VID) }, { states: ['idle'], videos: { idle: IDLE } }));
    expect(byId(items, 'sleeping')).toBeUndefined();
    expect(byId(items, 'bed')).toMatchObject({ kind: 'video', url: BED_VID, poster: BED_IMG, state: null, label: 'Na svoji postelji' });
  });
});

describe('album — item tiles', () => {
  it('every owned item gets a tile after the states, in a fixed order with Slovenian labels', () => {
    const items = buildAlbumItems(
      media({
        treats: once(TREAT_IMG, null),
        toy_2: loop(TOY2_IMG, TOY2_VID),
        bowls: once(BOWL_IMG, BOWL_VID),
        toy_1: loop(TOY1_IMG, TOY1_VID),
      }),
    );
    expect(items.map((i) => [i.kind, i.id, i.label])).toEqual([
      ['photo', 'photo', 'Fotografija'],
      ['video', 'idle', 'Miruje'],
      ['video', 'sleeping', 'Spi'],
      ['video', 'bowls', 'Je iz svoje sklede'],
      ['video', 'toy_1', 'Igra se z igračo 1'],
      ['video', 'toy_2', 'Igra se z igračo 2'],
      ['photo', 'treats', 'Poje priboljšek'],
    ]);
    expect(byId(items, 'bowls')).toMatchObject({ url: BOWL_VID, poster: BOWL_IMG });
    expect(items.every(isPlayable)).toBe(true);
  });

  it('one toy → the plain toy label, whichever slot it is in', () => {
    expect(byId(buildAlbumItems(media({ toy_2: loop(TOY2_IMG, TOY2_VID) })), 'toy_2')?.label).toBe('Igra se z igračo');
    expect(byId(buildAlbumItems(media({ toy_1: loop(TOY1_IMG, null) })), 'toy_1')?.label).toBe(ALBUM_STRINGS.items.toy);
  });

  it('picture mode → every item tile shows its image', () => {
    const items = buildAlbumItems(media({ bowls: once(BOWL_IMG, BOWL_VID), toy_1: loop(TOY1_IMG, TOY1_VID) }), 'picture');
    expect(byId(items, 'bowls')).toMatchObject({ kind: 'photo', url: BOWL_IMG });
    expect(byId(items, 'toy_1')).toMatchObject({ kind: 'photo', url: TOY1_IMG });
  });

  it('a clip without an image → a video tile without its own poster (the reference photo is used)', () => {
    const tile = byId(buildAlbumItems(media({ toy_1: loop(null, TOY1_VID) })), 'toy_1');
    expect(tile).toMatchObject({ kind: 'video', url: TOY1_VID });
    expect(tile !== undefined && 'poster' in tile).toBe(false);
  });

  it('missing / malformed URLs never make a tile (and never throw)', () => {
    const raw = {
      bowls: { image: null, video: null, video_seconds: null, loop: false },
      treats: { image: '', video: 42, video_seconds: null, loop: false },
    } as unknown as RawItems;
    const items = buildAlbumItems(media(raw));
    expect(items.map((i) => i.id)).toEqual(['photo', 'idle', 'sleeping']);
  });

  it('items alone keep the album (e.g. state clips still generating, generation failed)', () => {
    const m = media({ toy_1: loop(TOY1_IMG, TOY1_VID) }, { status: 'failed', reference_image_url: null, videos: {}, states: [] });
    expect(summary(buildAlbumItems(m))).toEqual([['video', 'toy_1', TOY1_VID]]);
    expect(hasAlbum(m)).toBe(true);
  });
});
