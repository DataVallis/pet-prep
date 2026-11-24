/**
 * M5-T20 care beat (David 2026-11-24): a beat only when the action plays a bowls / treat CLIP,
 * never longer than CARE_BEAT_MAX_MS, and the hook's single timer ends it at `endsAt`.
 */
import { act, renderHook } from '@testing-library/react-native';

import {
  beatActive,
  beatRemainingMs,
  CARE_BEAT_MAX_MS,
  careBeatFor,
  startBeat,
  treatBeatFor,
  useCareBeat,
} from '@/modules/childPet/careBeat';
import { readItemMedia } from '@/modules/petMedia/itemMedia';

const IMG = 'https://api.petprep.si/api/media/1?v=img';
const BOWLS_IMG = 'https://api.petprep.si/api/media/12?v=bowlimg';
const BOWLS_VID = 'https://api.petprep.si/api/media/13?v=bowlvid';
const DRINK_VID = 'https://api.petprep.si/api/media/15?v=drinkvid';
const TREAT_VID = 'https://api.petprep.si/api/media/14?v=treatvid';

function media(raw: unknown) {
  return { items: readItemMedia(raw), referenceImageUrl: IMG };
}
const BOWLS = { bowls: { image: BOWLS_IMG, video: BOWLS_VID, video_seconds: 3, loop: false } };
const BOWLS_DRINK = { bowls: { ...BOWLS.bowls, drink_video: DRINK_VID, drink_video_seconds: 3 } };

describe('careBeatFor', () => {
  it('feed with owned bowls in video mode → beat', () => {
    expect(careBeatFor('feed', media(BOWLS), 'video')).toBe(true);
  });
  it('water only with a drinking clip', () => {
    expect(careBeatFor('water', media(BOWLS), 'video')).toBe(false);
    expect(careBeatFor('water', media(BOWLS_DRINK), 'video')).toBe(true);
  });
  it('picture mode → never (the image moment is no clip)', () => {
    expect(careBeatFor('feed', media(BOWLS_DRINK), 'picture')).toBe(false);
    expect(careBeatFor('water', media(BOWLS_DRINK), 'picture')).toBe(false);
  });
  it('without bowls → never', () => {
    expect(careBeatFor('feed', media({}), 'video')).toBe(false);
    expect(careBeatFor('water', media({}), 'video')).toBe(false);
  });
  it('take out / clean / walk / resolve_chewing → never', () => {
    for (const a of ['take_out', 'clean', 'walk', 'resolve_chewing']) expect(careBeatFor(a, media(BOWLS_DRINK), 'video')).toBe(false);
  });
});

describe('treatBeatFor', () => {
  it('the treat clip or the answer clip in video mode; never in picture mode or without a clip', () => {
    expect(treatBeatFor(media({ treats: { image: null, video: TREAT_VID, video_seconds: 2, loop: false } }), 'video', null)).toBe(true);
    expect(treatBeatFor(media({}), 'video', TREAT_VID)).toBe(true);
    expect(treatBeatFor(media({}), 'video', null)).toBe(false);
    expect(treatBeatFor(media({}), 'picture', TREAT_VID)).toBe(false);
  });
});

describe('beat state', () => {
  it('lasts CARE_BEAT_MAX_MS (5 s) at most', () => {
    expect(CARE_BEAT_MAX_MS).toBe(5_000);
    const beat = startBeat(1_000);
    expect(beat.endsAt).toBe(6_000);
    expect(beatActive(beat, 5_999)).toBe(true);
    expect(beatActive(beat, 6_000)).toBe(false);
    expect(beatActive(null, 0)).toBe(false);
    expect(beatRemainingMs(beat, 2_000)).toBe(4_000);
    expect(beatRemainingMs(beat, 9_000)).toBe(0);
    expect(beatRemainingMs(null, 0)).toBe(0);
  });
});

describe('useCareBeat', () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it('busy from start until end()', () => {
    const { result } = renderHook(() => useCareBeat());
    expect(result.current.busy).toBe(false);
    act(() => result.current.start());
    expect(result.current.busy).toBe(true);
    expect(result.current.isBusy()).toBe(true);
    act(() => result.current.end());
    expect(result.current.busy).toBe(false);
    expect(result.current.isBusy()).toBe(false);
  });

  it('ends by itself after 5 s at the latest (a stuck clip)', () => {
    const { result } = renderHook(() => useCareBeat());
    act(() => result.current.start());
    act(() => jest.advanceTimersByTime(CARE_BEAT_MAX_MS - 1));
    expect(result.current.busy).toBe(true);
    act(() => jest.advanceTimersByTime(1));
    expect(result.current.busy).toBe(false);
    expect(result.current.isBusy()).toBe(false);
  });

  it('a new beat restarts the 5 s (one timer)', () => {
    const { result } = renderHook(() => useCareBeat());
    act(() => result.current.start());
    act(() => jest.advanceTimersByTime(3_000));
    act(() => result.current.start());
    act(() => jest.advanceTimersByTime(3_000));
    expect(result.current.busy).toBe(true);
    act(() => jest.advanceTimersByTime(2_000));
    expect(result.current.busy).toBe(false);
  });
});
