/**
 * M6-W03 (David 2026-11-04): the album shows the bought items — »Spi« = the bed clip with the bed
 * image as its poster, every owned item its own tile, picture mode → images; a failing item image
 * falls back to the placeholder like every album picture.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import PetAlbum from '@/components/PetAlbum';
import { normalizePetMedia, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import type { PetMedia } from '@/types';
import { makeMedia } from '@/test-utils/fixtures';
import { liveVideoPlayers, resetMockVideoPlayers } from '@/test-utils/videoPlayers';

const url = (path: string, v: string) => `https://api.petprep.si/api/media/${path}?expires=1&v=${v}&signature=s`;
const IMG = url('1', 'img');
const IDLE = url('2', 'idle');
const SLEEP = url('3', 'sleep');
const BED_IMG = url('items/41', 'bedimg');
const BED_VID = url('items/42', 'bedvid');
const TOY_IMG = url('items/61', 'toyimg');
const TOY_VID = url('items/62', 'toyvid');

function mediaWith(items: PetMedia['items']): PetMediaInfo {
  return normalizePetMedia(
    makeMedia({ status: 'ready', reference_image_url: IMG, videos: { idle: IDLE, sleeping: SLEEP }, states: ['idle', 'sleeping'], items }),
  );
}

const ITEMS: PetMedia['items'] = {
  bed: { image: BED_IMG, video: BED_VID, video_seconds: 3, loop: true },
  toy_1: { image: TOY_IMG, video: TOY_VID, video_seconds: 3, loop: true },
};

beforeEach(() => resetMockVideoPlayers());

describe('PetAlbum — bought items', () => {
  it('»Spi« shows the bed (poster = bed image) and the toy has its own labelled tile', () => {
    render(<PetAlbum media={mediaWith(ITEMS)} onClose={jest.fn()} onMediaExpired={jest.fn()} />);
    expect(screen.getByTestId('album-item-sleeping-poster-image').props.source).toEqual({ uri: BED_IMG });
    expect(screen.getByTestId('album-item-sleeping-caption')).toHaveTextContent('Spi');
    expect(screen.getByTestId('album-item-toy_1-poster-image').props.source).toEqual({ uri: TOY_IMG });
    expect(screen.getByTestId('album-item-toy_1-caption')).toHaveTextContent('Igra se z igračo');
    expect(screen.queryByTestId('album-item-bed')).toBeNull();
    // Idle keeps the reference photo as its poster.
    expect(screen.getByTestId('album-item-idle-poster-image').props.source).toEqual({ uri: IMG });
  });

  it('the viewer plays the bed clip for »Spi« with the bed image as poster', () => {
    render(<PetAlbum media={mediaWith(ITEMS)} onClose={jest.fn()} onMediaExpired={jest.fn()} />);
    fireEvent.press(screen.getByTestId('album-item-sleeping'));
    expect(screen.getByTestId('album-video-poster-image').props.source).toEqual({ uri: BED_IMG });
    expect(liveVideoPlayers()).toHaveLength(1);
  });

  it('picture mode → item tiles are photos (no play badge) and open as a photo', () => {
    render(<PetAlbum media={mediaWith(ITEMS)} mode="picture" onClose={jest.fn()} onMediaExpired={jest.fn()} />);
    expect(screen.getByTestId('album-item-sleeping-image').props.source).toEqual({ uri: BED_IMG });
    expect(screen.queryByTestId('album-item-sleeping-play')).toBeNull();
    expect(screen.getByTestId('album-item-toy_1-image').props.source).toEqual({ uri: TOY_IMG });
    fireEvent.press(screen.getByTestId('album-item-toy_1'));
    expect(screen.getByTestId('album-photo').props.source).toEqual({ uri: TOY_IMG });
    expect(liveVideoPlayers()).toHaveLength(0);
  });

  it('a failing item image → placeholder + one refetch request, never a crash', () => {
    const onMediaExpired = jest.fn();
    render(<PetAlbum media={mediaWith(ITEMS)} mode="picture" onClose={jest.fn()} onMediaExpired={onMediaExpired} />);
    fireEvent(screen.getByTestId('album-item-toy_1-image'), 'error');
    expect(screen.getByTestId('album-item-toy_1-placeholder')).toBeTruthy();
    expect(onMediaExpired).toHaveBeenCalledTimes(1);
  });
});
