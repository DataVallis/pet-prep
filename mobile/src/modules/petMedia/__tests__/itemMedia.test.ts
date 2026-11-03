/**
 * M6-W03 bought items in the pet's scenes: `media.items` parsing, which scene an item replaces
 * (owned + ready, absent, picture mode, failing URL, both toys), the one-shot moments and the
 * game decks (toy loop only without the phase group).
 */
import { cuddleDeck } from '@/modules/play/cuddleV2/cuddleDeck';
import { ballDeck } from '@/modules/play/ballV2/ballDeck';
import { wandDeck } from '@/modules/catCare/wandV2/wandDeck';
import {
  ITEM_CLIP_DEFAULT_MS,
  ITEM_CLIP_GRACE_MS,
  ITEM_PICTURE_MS,
  advanceToyTurn,
  currentToyTurn,
  hasPlayingMedia,
  itemMomentPlayback,
  pickToy,
  readItemMedia,
  resetToyTurnForTests,
  sceneItemFor,
  toyLoop,
} from '@/modules/petMedia/itemMedia';
import { EMPTY_PET_MEDIA, mediaKey, normalizePetMedia, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import { nextPlayback, steadyItem, type PlaybackInput } from '@/modules/petMedia/statePlayer';
import { makeMedia } from '@/test-utils/fixtures';

const u = (id: number, v: string) => `https://api.petprep.si/api/media/${id}?expires=1&v=${v}&signature=x`;
const IMAGE = u(1, 'img');
const IDLE = u(2, 'idle');
const SLEEPING = u(3, 'sleep');
const PLAYING = u(4, 'play');
const KEY_SLEEPING = u(5, 'ksleep');
const BED_IMG = u(10, 'bedimg');
const BED_VID = u(11, 'bedvid');
const BOWLS_IMG = u(12, 'bowlimg');
const BOWLS_VID = u(13, 'bowlvid');
const ROPE_IMG = u(14, 'ropeimg');
const ROPE_VID = u(15, 'ropevid');
const SQUEAK_IMG = u(16, 'sqimg');
const SQUEAK_VID = u(17, 'sqvid');
const TREAT_VID = u(18, 'treatvid');

const RAW_ITEMS = {
  bed: { image: BED_IMG, video: BED_VID, video_seconds: 3, loop: true },
  bowls: { image: BOWLS_IMG, video: BOWLS_VID, video_seconds: 4, loop: false },
  toy_1: { image: ROPE_IMG, video: ROPE_VID, video_seconds: 3, loop: true },
  toy_2: { image: SQUEAK_IMG, video: SQUEAK_VID, video_seconds: 3, loop: true },
  treats: { image: null, video: TREAT_VID, video_seconds: null, loop: false },
};

function media(overrides: Partial<PetMediaInfo> = {}, items: unknown = RAW_ITEMS): PetMediaInfo {
  return {
    ...EMPTY_PET_MEDIA,
    status: 'partial',
    stateMedia: true,
    referenceImageUrl: IMAGE,
    videos: { idle: IDLE, sleeping: SLEEPING, playing: PLAYING },
    keyframes: { sleeping: KEY_SLEEPING },
    transitions: { idle_to_sleeping: u(20, 'its'), sleeping_to_idle: u(21, 'sti') },
    items: readItemMedia(items),
    ...overrides,
  };
}

const input = (m: PetMediaInfo, over: Partial<PlaybackInput> = {}): PlaybackInput => ({ media: m, wanted: 'idle', petState: 'idle', ...over });

beforeEach(() => resetToyTurnForTests());

describe('readItemMedia (media.items)', () => {
  it('reads the contract and normalises lengths / loop flags', () => {
    const items = readItemMedia(RAW_ITEMS);
    expect(items.bed).toEqual({ image: BED_IMG, video: BED_VID, videoMs: 3000, loop: true });
    expect(items.bowls).toEqual({ image: BOWLS_IMG, video: BOWLS_VID, videoMs: 4000, loop: false });
    expect(items.treats).toEqual({ image: null, video: TREAT_VID, videoMs: null, loop: false });
  });

  it('missing / malformed → no items (the normal scene); unknown keys and empty entries are dropped', () => {
    expect(readItemMedia(undefined)).toEqual({});
    expect(readItemMedia([])).toEqual({});
    expect(readItemMedia({ collar: { image: BED_IMG }, bed: { image: '', video: null }, toy_1: 'x' })).toEqual({});
    // Without a loop flag: bed / toys loop, bowls / treats play once.
    expect(readItemMedia({ toy_2: { video: SQUEAK_VID }, bowls: { image: BOWLS_IMG } })).toEqual({
      toy_2: { image: null, video: SQUEAK_VID, videoMs: null, loop: true },
      bowls: { image: BOWLS_IMG, video: null, videoMs: null, loop: false },
    });
  });

  it('normalizePetMedia carries the items (older server: none)', () => {
    expect(normalizePetMedia(makeMedia()).items).toEqual({});
    const withItems = normalizePetMedia({ ...makeMedia(), items: { bed: RAW_ITEMS.bed } });
    expect(withItems.items.bed?.video).toBe(BED_VID);
  });
});

describe('toys', () => {
  it('one toy owned → always that toy; both → alternating per session turn; none → null', () => {
    const both = readItemMedia(RAW_ITEMS);
    expect(pickToy(readItemMedia({ toy_2: RAW_ITEMS.toy_2 }), 0)).toBe('toy_2');
    expect(pickToy(readItemMedia({ toy_1: RAW_ITEMS.toy_1 }), 1)).toBe('toy_1');
    expect(pickToy(both, 0)).toBe('toy_1');
    expect(pickToy(both, 1)).toBe('toy_2');
    expect(pickToy({}, 0)).toBeNull();
    expect(currentToyTurn()).toBe(0);
    expect(pickToy(both)).toBe('toy_1');
    advanceToyTurn();
    expect(pickToy(both)).toBe('toy_2');
    advanceToyTurn();
    expect(pickToy(both)).toBe('toy_1');
  });

  it('hasPlayingMedia / toyLoop follow the chosen toy and the display mode', () => {
    const m = media();
    expect(hasPlayingMedia(m)).toBe(true);
    expect(hasPlayingMedia(media({}, {}))).toBe(false);
    expect(toyLoop(m.items, 'toy_2', 'video')).toEqual({ url: SQUEAK_VID, image: SQUEAK_IMG });
    expect(toyLoop(m.items, 'toy_1', 'picture')).toEqual({ url: null, image: ROPE_IMG });
    expect(toyLoop(m.items, null, 'video')).toBeNull();
  });
});

describe('sceneItemFor', () => {
  it('bed → sleeping, toy → playing, nothing for other states', () => {
    const items = readItemMedia(RAW_ITEMS);
    expect(sceneItemFor(items, 'sleeping', null, 'video')).toEqual({ key: 'bed', video: BED_VID, image: BED_IMG });
    expect(sceneItemFor(items, 'playing', 'toy_2', 'video')).toEqual({ key: 'toy_2', video: SQUEAK_VID, image: SQUEAK_IMG });
    expect(sceneItemFor(items, 'idle', 'toy_1', 'video')).toBeNull();
    expect(sceneItemFor(items, 'hungry', 'toy_1', 'video')).toBeNull();
  });

  it('picture mode → the image; a failing video → the image; neither → null', () => {
    const items = readItemMedia(RAW_ITEMS);
    expect(sceneItemFor(items, 'sleeping', null, 'picture')).toEqual({ key: 'bed', video: null, image: BED_IMG });
    expect(sceneItemFor(items, 'sleeping', null, 'video', (url) => url !== BED_VID)).toEqual({ key: 'bed', video: null, image: BED_IMG });
    const videoOnly = readItemMedia({ bed: { video: BED_VID } });
    expect(sceneItemFor(videoOnly, 'sleeping', null, 'video', () => false)).toBeNull();
  });
});

describe('steadyItem with items (statePlayer)', () => {
  it('owned + ready bed replaces the sleeping loop (looped, marked as the item)', () => {
    expect(steadyItem(input(media(), { wanted: 'sleeping', petState: 'sleeping' }))).toEqual({
      kind: 'clip', url: BED_VID, key: mediaKey(BED_VID), state: 'sleeping', loop: true, transition: null, item: 'bed',
    });
  });

  it('no bed → the normal sleeping loop', () => {
    expect(steadyItem(input(media({}, {}), { wanted: 'sleeping', petState: 'sleeping' }))).toMatchObject({ kind: 'clip', url: SLEEPING, state: 'sleeping' });
  });

  it('picture mode → the bed image instead of the sleeping key image', () => {
    expect(steadyItem(input(media(), { wanted: 'sleeping', petState: 'sleeping', mode: 'picture' }))).toEqual({
      kind: 'image', url: BED_IMG, key: mediaKey(BED_IMG), state: 'sleeping', item: 'bed',
    });
  });

  it('a failing bed clip → the bed image, never a blank stage', () => {
    expect(steadyItem(input(media(), { wanted: 'sleeping', petState: 'sleeping', canPlayVideo: (url) => url !== BED_VID }))).toMatchObject({ kind: 'image', url: BED_IMG, item: 'bed' });
  });

  it('older set: a tired dog shown with the sleeping substitute lies on its bed too', () => {
    const older = media({ stateMedia: false, keyframes: {} });
    expect(steadyItem(input(older, { wanted: 'low_energy', petState: 'low_energy' }))).toMatchObject({ kind: 'clip', url: BED_VID, item: 'bed' });
    // A hungry dog (idle substitute) keeps the normal scene.
    expect(steadyItem(input(older, { wanted: 'hungry', petState: 'hungry' }))).toMatchObject({ kind: 'clip', url: IDLE });
  });

  it('free plan without a sleeping clip still gets the bed (S8: the item adds its own content)', () => {
    const free = media({ stateMedia: false, videos: { idle: IDLE }, keyframes: {} });
    expect(steadyItem(input(free, { wanted: 'sleeping', petState: 'sleeping' }))).toMatchObject({ kind: 'clip', url: BED_VID });
  });

  it('playing → the session toy; both toys alternate by the given toy', () => {
    const m = media();
    expect(steadyItem(input(m, { wanted: 'playing', petState: 'playing', toy: 'toy_1' }))).toMatchObject({ url: ROPE_VID, item: 'toy_1' });
    expect(steadyItem(input(m, { wanted: 'playing', petState: 'playing', toy: 'toy_2' }))).toMatchObject({ url: SQUEAK_VID, item: 'toy_2' });
    // Default: the current turn's toy.
    expect(steadyItem(input(m, { wanted: 'playing', petState: 'playing' }))).toMatchObject({ item: 'toy_1' });
    advanceToyTurn();
    expect(steadyItem(input(m, { wanted: 'playing', petState: 'playing' }))).toMatchObject({ item: 'toy_2' });
    // toy null = no toy → the normal playing loop.
    expect(steadyItem(input(m, { wanted: 'playing', petState: 'playing', toy: null }))).toMatchObject({ url: PLAYING });
  });

  it('a behaviour scene with its own clip is never replaced', () => {
    const m = media({ videos: { idle: IDLE, sleeping: SLEEPING, accident: u(30, 'acc') } });
    expect(steadyItem(input(m, { wanted: 'accident', petState: 'sleeping' }))).toMatchObject({ state: 'accident' });
  });

  it('no stored transition into / out of an item scene (crossfade instead)', () => {
    const m = media();
    expect(nextPlayback(input(m, { wanted: 'sleeping', petState: 'sleeping' }), 'idle').current).toMatchObject({ url: BED_VID, transition: null });
    expect(nextPlayback(input(m, { wanted: 'idle', petState: 'idle' }), 'sleeping')).toEqual({ current: expect.objectContaining({ url: IDLE, transition: null }), preload: null });
    // Without the bed the stored transition still plays.
    expect(nextPlayback(input(media({}, {}), { wanted: 'sleeping', petState: 'sleeping' }), 'idle').current).toMatchObject({ transition: 'idle_to_sleeping' });
  });
});

describe('itemMomentPlayback (bowls / treats once)', () => {
  const m = media();

  it('video: the clip once, keyed per moment, ended by its length + grace', () => {
    const a = itemMomentPlayback(m.items, { key: 'bowls', instance: 1 }, 'video', IMAGE);
    const b = itemMomentPlayback(m.items, { key: 'bowls', instance: 2 }, 'video', IMAGE);
    expect(a?.current).toMatchObject({ kind: 'clip', url: BOWLS_VID, loop: false });
    expect(a?.current.key).not.toBe(b?.current.key);
    expect(a?.still).toBe(BOWLS_IMG);
    expect(a?.maxMs).toBe(4000 + ITEM_CLIP_GRACE_MS);
  });

  it('picture mode → the item image for a short while', () => {
    expect(itemMomentPlayback(m.items, { key: 'bowls', instance: 1 }, 'picture', IMAGE)).toEqual({
      current: { kind: 'image', url: BOWLS_IMG, key: mediaKey(BOWLS_IMG), state: null },
      still: BOWLS_IMG,
      maxMs: ITEM_PICTURE_MS,
    });
  });

  it('treats without an image: still = the reference image; picture mode → nothing (hearts)', () => {
    expect(itemMomentPlayback(m.items, { key: 'treats', instance: 1 }, 'video', IMAGE)).toMatchObject({ still: IMAGE, maxMs: ITEM_CLIP_DEFAULT_MS + ITEM_CLIP_GRACE_MS });
    expect(itemMomentPlayback(m.items, { key: 'treats', instance: 1 }, 'picture', IMAGE)).toBeNull();
  });

  it('not owned → the answer clip (older contract) or nothing', () => {
    expect(itemMomentPlayback({}, { key: 'bowls', instance: 1 }, 'video', IMAGE)).toBeNull();
    expect(itemMomentPlayback({}, { key: 'treats', instance: 1 }, 'video', IMAGE, { fallbackClip: TREAT_VID })?.current).toMatchObject({ url: TREAT_VID });
  });

  it('a failing clip → its image', () => {
    expect(itemMomentPlayback(m.items, { key: 'bowls', instance: 1 }, 'video', IMAGE, { canPlayVideo: () => false })?.current).toMatchObject({ kind: 'image', url: BOWLS_IMG });
  });
});

describe('game decks', () => {
  it('cuddles: the toy loop stands in for playing; picture mode → the toy image', () => {
    const m = media();
    expect(cuddleDeck(m, 'video', 'toy_2').clips[0]).toMatchObject({ url: SQUEAK_VID, loop: true, startStill: SQUEAK_IMG });
    expect(cuddleDeck(m, 'picture', 'toy_1').clips[0]).toMatchObject({ url: null, picture: ROPE_IMG });
    expect(cuddleDeck(media({}, {}), 'video').clips[0]).toMatchObject({ url: PLAYING, startStill: IMAGE });
  });

  it('ball without the phase group: the toy loop for every role; WITH the group the phase clips stay', () => {
    const m = media();
    const plain = ballDeck(m, 'video', 'toy_1');
    expect(plain.clips.map((c) => c.url)).toEqual([ROPE_VID, ROPE_VID, ROPE_VID]);
    const phased = media({ phaseGroups: ['ball'], phases: { ball_waiting: u(40, 'bw'), ball_running: u(41, 'br'), ball_fetching: u(42, 'bf') } });
    const deck = ballDeck(phased, 'video', 'toy_1');
    expect(deck.clips.map((c) => c.url)).toEqual([u(40, 'bw'), u(41, 'br'), u(42, 'bf')]);
    expect(deck.clips[0].startStill).toBe(IMAGE);
  });

  it('wand without the phase group: the toy loop; with it the phase clips stay', () => {
    const m = media();
    expect(wandDeck(m, 'video', 'toy_2').clips.every((c) => c.url === SQUEAK_VID)).toBe(true);
    const phased = media({ phaseGroups: ['wand'], phases: { wand_stalking: u(50, 'ws'), wand_pouncing: u(51, 'wp'), wand_catching: u(52, 'wc') } });
    expect(wandDeck(phased, 'video', 'toy_2').clips.some((c) => c.url === SQUEAK_VID)).toBe(false);
  });
});
