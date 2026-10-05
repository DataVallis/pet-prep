/**
 * M4-03 app side: media payload normalisation, media identity of signed URLs, pet
 * state → video state, the fallback chain and the expired-URL recovery reducer.
 */
import {
  EMPTY_PET_MEDIA,
  NO_VIDEO_ERRORS,
  canPlayUrl,
  isMediaPending,
  mediaKey,
  normalizePetMedia,
  recordVideoError,
  recordVideoReady,
  sameMedia,
  selectMediaSource,
  toBreedType,
  videoStateFor,
  type PetMediaInfo,
} from '@/modules/petMedia/petMedia';
import { makeMedia } from '@/test-utils/fixtures';

const BASE = 'https://api.petprep.si/api/media';
const signed = (id: number, v: string, expires = 1_000, sig = 'abc') =>
  `${BASE}/${id}?expires=${expires}&v=${v}&signature=${sig}`;

function info(overrides: Partial<PetMediaInfo> = {}): PetMediaInfo {
  return { ...EMPTY_PET_MEDIA, status: 'ready', ...overrides };
}

describe('mediaKey', () => {
  it('drops expires and signature, keeps path and the file hash v', () => {
    expect(mediaKey(signed(5, 'aa11'))).toBe(`${BASE}/5?v=aa11`);
    expect(mediaKey(`${BASE}/5?signature=x&v=aa11&expires=9`)).toBe(`${BASE}/5?v=aa11`);
  });

  it('a re-signed URL of the same file is the same media, a new file is not', () => {
    expect(sameMedia(signed(5, 'aa11', 1_000, 'a'), signed(5, 'aa11', 2_800, 'b'))).toBe(true);
    expect(sameMedia(signed(5, 'aa11'), signed(5, 'bb22'))).toBe(false);
    expect(sameMedia(signed(5, 'aa11'), signed(6, 'aa11'))).toBe(false);
    expect(sameMedia(null, null)).toBe(true);
    expect(sameMedia(signed(5, 'aa11'), null)).toBe(false);
  });

  it('URLs without a query or v keep the path only', () => {
    expect(mediaKey(`${BASE}/5`)).toBe(`${BASE}/5`);
    expect(mediaKey(`${BASE}/5?expires=1&signature=s`)).toBe(`${BASE}/5`);
    expect(mediaKey(`${BASE}/5?v=1#frag`)).toBe(`${BASE}/5?v=1`);
  });
});

describe('normalizePetMedia', () => {
  it('reads the M4-05 payload and ignores unknown states / empty URLs', () => {
    const media = normalizePetMedia(
      makeMedia({
        status: 'partial',
        reference_image_url: signed(1, 'img'),
        videos: { idle: signed(2, 'i'), sleeping: signed(3, 's'), flying: 'x', hungry: '' },
        current_video_url: signed(2, 'i'),
        states: ['idle', 'sleeping'],
        expires_at: '2026-10-05T12:00:00+00:00',
      }),
    );
    expect(media).toEqual({
      status: 'partial',
      referenceImageUrl: signed(1, 'img'),
      videos: { idle: signed(2, 'i'), sleeping: signed(3, 's') },
      currentVideoUrl: signed(2, 'i'),
      states: ['idle', 'sleeping'],
      expiresAt: '2026-10-05T12:00:00+00:00',
    });
  });

  it('falls back to the legacy top-level fields when media is missing', () => {
    expect(normalizePetMedia(undefined)).toBe(EMPTY_PET_MEDIA);
    expect(normalizePetMedia(null, { media_status: 'pending' }).status).toBe('pending');
    const legacy = normalizePetMedia(undefined, { current_video_url: 'https://x/v.mp4', reference_image_url: 'https://x/i.jpg' });
    expect(legacy).toMatchObject({ status: 'partial', currentVideoUrl: 'https://x/v.mp4', referenceImageUrl: 'https://x/i.jpg' });
  });

  it('a malformed media object never throws', () => {
    expect(normalizePetMedia({ status: 'weird', videos: [] })).toEqual({ ...EMPTY_PET_MEDIA });
    expect(normalizePetMedia(['nope'])).toBe(EMPTY_PET_MEDIA);
  });
});

describe('videoStateFor', () => {
  it('uses the server pet_state (quiet hours already arrive as sleeping)', () => {
    expect(videoStateFor('idle')).toBe('idle');
    expect(videoStateFor('sleeping')).toBe('sleeping');
    expect(videoStateFor('hungry')).toBe('hungry');
    expect(videoStateFor('low_energy')).toBe('low_energy');
    expect(videoStateFor('playing')).toBe('playing');
    expect(videoStateFor('sick')).toBe('sick');
  });

  it('locks override it: vet → sick, hard stop → sleeping, game over / inactive / unborn → no video', () => {
    expect(videoStateFor('playing', 'ill')).toBe('sick');
    expect(videoStateFor('playing', 'hard_stopped')).toBe('sleeping');
    expect(videoStateFor('idle', 'game_over')).toBeNull();
    expect(videoStateFor('idle', 'inactive')).toBeNull();
    expect(videoStateFor('idle', 'contract_required')).toBeNull();
  });
});

describe('selectMediaSource — fallback chain', () => {
  const all = info({
    referenceImageUrl: signed(1, 'img'),
    videos: { idle: signed(2, 'i'), sleeping: signed(3, 's'), hungry: signed(4, 'h') },
  });

  it('plays the video of the wanted state', () => {
    expect(selectMediaSource(all, 'hungry')).toEqual({
      kind: 'video',
      state: 'hungry',
      url: signed(4, 'h'),
      key: mediaKey(signed(4, 'h')),
    });
  });

  it('falls back to idle when the state has no video (basic entitlement)', () => {
    expect(selectMediaSource(all, 'sick')).toMatchObject({ kind: 'video', state: 'idle', url: signed(2, 'i') });
  });

  it('uses the server current_video_url only when the payload has no videos map', () => {
    const legacy = info({ currentVideoUrl: 'https://x/legacy.mp4', referenceImageUrl: 'https://x/i.jpg' });
    expect(selectMediaSource(legacy, 'hungry')).toMatchObject({ kind: 'video', state: null, url: 'https://x/legacy.mp4' });
    // With a videos map the server pick is never used on top of it.
    const withMap = info({ videos: { sleeping: signed(3, 's') }, currentVideoUrl: 'https://x/other.mp4', referenceImageUrl: 'https://x/i.jpg' });
    expect(selectMediaSource(withMap, 'hungry')).toMatchObject({ kind: 'image', url: 'https://x/i.jpg' });
  });

  it('→ reference image → placeholder', () => {
    expect(selectMediaSource(info({ referenceImageUrl: signed(1, 'img') }), 'idle')).toMatchObject({ kind: 'image' });
    expect(selectMediaSource(info(), 'idle')).toEqual({ kind: 'placeholder' });
  });

  it('no video while locked (wanted = null) — image instead', () => {
    expect(selectMediaSource(all, null)).toMatchObject({ kind: 'image', url: signed(1, 'img') });
  });

  it('skips videos the player may not load (failed / waiting for a re-signed URL)', () => {
    const blocked = new Set([mediaKey(signed(4, 'h'))]);
    const canPlayVideo = (url: string) => !blocked.has(mediaKey(url));
    expect(selectMediaSource(all, 'hungry', { canPlayVideo })).toMatchObject({ kind: 'video', state: 'idle' });
    blocked.add(mediaKey(signed(2, 'i')));
    expect(selectMediaSource(all, 'hungry', { canPlayVideo })).toMatchObject({ kind: 'image' });
  });
});

describe('expired URL recovery', () => {
  const first = signed(2, 'i', 1_000, 'old');
  const resigned = signed(2, 'i', 2_800, 'new');

  it('first error → refetch once and wait for a re-signed URL', () => {
    const { state, refetch } = recordVideoError(NO_VIDEO_ERRORS, first, true);
    expect(refetch).toBe(true);
    expect(canPlayUrl(state, first)).toBe(false); // same URL: still waiting
    expect(canPlayUrl(state, resigned)).toBe(true); // the refetch brought a new one
  });

  it('second error → failed for good, no second refetch', () => {
    const once = recordVideoError(NO_VIDEO_ERRORS, first, true).state;
    const twice = recordVideoError(once, resigned, true);
    expect(twice.refetch).toBe(false);
    expect(canPlayUrl(twice.state, resigned)).toBe(false);
    expect(canPlayUrl(twice.state, signed(2, 'i', 9_999, 'newer'))).toBe(false);
    // A different file (new generation) is a new chance.
    expect(canPlayUrl(twice.state, signed(2, 'v2'))).toBe(true);
    expect(recordVideoError(twice.state, resigned, true).refetch).toBe(false);
  });

  it('without a refetch handler the first error already falls back', () => {
    const { state, refetch } = recordVideoError(NO_VIDEO_ERRORS, first, false);
    expect(refetch).toBe(false);
    expect(canPlayUrl(state, resigned)).toBe(false);
  });

  it('a successful retry re-arms the single refetch for the next expiry', () => {
    const once = recordVideoError(NO_VIDEO_ERRORS, first, true).state;
    const ready = recordVideoReady(once, resigned);
    expect(ready.retrying).toEqual({});
    expect(recordVideoError(ready, resigned, true).refetch).toBe(true);
    expect(recordVideoReady(NO_VIDEO_ERRORS, first)).toBe(NO_VIDEO_ERRORS);
  });
});

it('pending hint and breed narrowing', () => {
  expect(isMediaPending(info({ status: 'pending' }))).toBe(true);
  expect(isMediaPending(info({ status: 'failed' }))).toBe(false);
  expect(toBreedType('border_collie')).toBe('border_collie');
  expect(toBreedType('poodle')).toBe('mutt');
  expect(toBreedType(null)).toBe('mutt');
});
