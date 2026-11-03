/** M6-W03: the stage's one-shot item moment (bowls after a meal, the treat clip) — start, end, timeout. */
import { act, renderHook } from '@testing-library/react-native';

import { ITEM_CLIP_GRACE_MS, ITEM_PICTURE_MS, readItemMedia } from '@/modules/petMedia/itemMedia';
import { EMPTY_PET_MEDIA, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import type { MediaMode } from '@/modules/petMedia/statePlayer';
import { useItemMoment } from '@/modules/petMedia/useItemMoment';

const BOWLS_IMG = 'https://api.petprep.si/api/media/12?v=bi&signature=x';
const BOWLS_VID = 'https://api.petprep.si/api/media/13?v=bv&signature=x';
const TREAT_VID = 'https://api.petprep.si/api/media/14?v=tv&signature=x';

const withBowls: PetMediaInfo = {
  ...EMPTY_PET_MEDIA,
  referenceImageUrl: 'https://api.petprep.si/api/media/1?v=img',
  items: readItemMedia({ bowls: { image: BOWLS_IMG, video: BOWLS_VID, video_seconds: 3, loop: false } }),
};

beforeEach(() => jest.useFakeTimers());
afterEach(() => jest.useRealTimers());

function setup(media: PetMediaInfo, mode: MediaMode = 'video') {
  return renderHook(({ m, md }: { m: PetMediaInfo; md: MediaMode }) => useItemMoment(m, md), { initialProps: { m: media, md: mode } });
}

describe('useItemMoment', () => {
  it('owned bowls: the clip plays once as the stage phase, then the normal stage (end or timeout)', () => {
    const { result } = setup(withBowls);
    expect(result.current.phase).toBeNull();
    let started = false;
    act(() => {
      started = result.current.play('bowls');
    });
    expect(started).toBe(true);
    expect(result.current.phase?.current).toMatchObject({ kind: 'clip', url: BOWLS_VID, loop: false });
    expect(result.current.phase?.still).toBe(BOWLS_IMG);
    // The clip reports its end (PetMediaView onPhaseEnd).
    act(() => result.current.end());
    expect(result.current.phase).toBeNull();
    // A clip that never ends is given up after its length + grace.
    act(() => {
      result.current.play('bowls');
    });
    act(() => {
      jest.advanceTimersByTime(3000 + ITEM_CLIP_GRACE_MS);
    });
    expect(result.current.phase).toBeNull();
  });

  it('picture mode: the image for a short while', () => {
    const { result } = setup(withBowls, 'picture');
    act(() => {
      result.current.play('bowls');
    });
    expect(result.current.phase?.current).toMatchObject({ kind: 'image', url: BOWLS_IMG });
    act(() => {
      jest.advanceTimersByTime(ITEM_PICTURE_MS);
    });
    expect(result.current.phase).toBeNull();
  });

  it('nothing owned → false (the host keeps its plain feedback); the treat answer clip is a fallback', () => {
    const { result } = setup(EMPTY_PET_MEDIA);
    let started = true;
    act(() => {
      started = result.current.play('bowls');
    });
    expect(started).toBe(false);
    expect(result.current.phase).toBeNull();
    act(() => {
      started = result.current.play('treats', TREAT_VID);
    });
    expect(started).toBe(true);
    expect(result.current.phase?.current).toMatchObject({ url: TREAT_VID });
  });
});
