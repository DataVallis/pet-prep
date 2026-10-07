/**
 * "Moj kuža" tiles (device feedback 2026-10-07): video tiles were empty dark boxes with a
 * green play circle under their label. Now every tile has a picture (videos: the reference
 * photo they were generated from), a brand placeholder while loading / on error, one bottom
 * caption pill and — for videos — a small play badge. The viewer shows the poster until the
 * video's first frame is ready.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';
import { StyleSheet } from 'react-native';

import PetAlbum, { tileSize } from '@/components/PetAlbum';
import { i18n } from '@/i18n';
import { normalizePetMedia, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import { makeMedia } from '@/test-utils/fixtures';
import { liveVideoPlayers, resetMockVideoPlayers } from '@/test-utils/videoPlayers';

const url = (id: number, v: string, sig = 'a') => `https://api.petprep.si/api/media/${id}?expires=1&v=${v}&signature=${sig}`;
const IMG = url(1, 'img');
const IDLE = url(2, 'idle');
const SLEEP = url(3, 'sleep');

function mediaWith(overrides: Parameters<typeof makeMedia>[0] = {}): PetMediaInfo {
  return normalizePetMedia(
    makeMedia({
      status: 'partial',
      reference_image_url: IMG,
      videos: { idle: IDLE, sleeping: SLEEP },
      states: ['idle', 'sleeping', 'hungry'],
      ...overrides,
    }),
  );
}

function renderAlbum(media = mediaWith()) {
  const onMediaExpired = jest.fn();
  render(<PetAlbum media={media} onClose={jest.fn()} onMediaExpired={onMediaExpired} />);
  return { onMediaExpired };
}

beforeEach(() => resetMockVideoPlayers());
afterEach(async () => {
  await i18n.changeLanguage('sl');
});

describe('video tiles', () => {
  it('show the reference photo as poster, a play badge and a caption pill — no player in the grid', () => {
    renderAlbum();
    for (const id of ['idle', 'sleeping']) {
      expect(screen.getByTestId(`album-item-${id}-poster-image`).props.source).toEqual({ uri: IMG });
      expect(screen.getByTestId(`album-item-${id}-play`)).toBeTruthy();
      expect(screen.getByTestId(`album-item-${id}-caption`)).toBeTruthy();
    }
    expect(screen.getByTestId('album-item-idle-caption')).toHaveTextContent('Miruje');
    // Same pill as the photo tile.
    expect(screen.getByTestId('album-item-photo-caption')).toHaveTextContent('Fotografija');
    expect(screen.queryByTestId('album-item-photo-play')).toBeNull();
    expect(liveVideoPlayers()).toHaveLength(0);
  });

  it('the play badge is a separate corner badge, not under the label (no overlap)', () => {
    renderAlbum();
    const badge = screen.getByTestId('album-item-idle-play');
    expect(StyleSheet.flatten(badge.props.style)).toMatchObject({ position: 'absolute', top: 10, right: 10 });
  });

  it('loading: brand placeholder until the poster has loaded', () => {
    renderAlbum();
    expect(screen.getByTestId('album-item-idle-poster-placeholder')).toBeTruthy();
    fireEvent(screen.getByTestId('album-item-idle-poster-image'), 'load');
    expect(screen.queryByTestId('album-item-idle-poster-placeholder')).toBeNull();
  });

  it('error: the placeholder comes back (no black box) and fresh URLs are requested', () => {
    const { onMediaExpired } = renderAlbum();
    fireEvent(screen.getByTestId('album-item-idle-poster-image'), 'error');
    expect(screen.getByTestId('album-item-idle-poster-placeholder')).toBeTruthy();
    expect(screen.queryByTestId('album-item-idle-poster-image')).toBeNull();
    expect(onMediaExpired).toHaveBeenCalledTimes(1);
  });

  it('no reference photo: a tasteful placeholder, still with the badge and the label', () => {
    renderAlbum(mediaWith({ reference_image_url: null }));
    expect(screen.queryByTestId('album-item-photo')).toBeNull();
    expect(screen.getByTestId('album-item-idle-poster-placeholder')).toBeTruthy();
    expect(screen.queryByTestId('album-item-idle-poster-image')).toBeNull();
    expect(screen.getByTestId('album-item-idle-play')).toBeTruthy();
    expect(screen.getByTestId('album-item-idle-caption')).toHaveTextContent('Miruje');
  });

  it('English labels in the same pill', async () => {
    await i18n.changeLanguage('en');
    renderAlbum();
    expect(screen.getByTestId('album-item-idle-caption')).toHaveTextContent('Resting');
    expect(screen.getByTestId('album-item-sleeping-caption')).toHaveTextContent('Sleeping');
  });
});

describe('photo and missing tiles', () => {
  it('photo tile: placeholder until loaded, then the photo; error → placeholder', () => {
    renderAlbum();
    expect(screen.getByTestId('album-item-photo-image').props.source).toEqual({ uri: IMG });
    expect(screen.getByTestId('album-item-photo-placeholder')).toBeTruthy();
    fireEvent(screen.getByTestId('album-item-photo-image'), 'load');
    expect(screen.queryByTestId('album-item-photo-placeholder')).toBeNull();
  });

  it('missing tile keeps the greyed "Še ni posnetka" look without a poster', () => {
    renderAlbum();
    expect(screen.queryByTestId('album-item-hungry-poster-image')).toBeNull();
    expect(screen.getByTestId('album-item-hungry')).toHaveTextContent('LačenŠe ni posnetka');
  });
});

describe('tile size', () => {
  it('two columns with a 12 pt gap, portrait 4:5, from the measured grid width', () => {
    expect(tileSize(343)).toEqual({ width: 165, height: 206 }); // 375 pt phone, 16 pt padding
    expect(tileSize(0)).toBeNull();
  });

  it('tiles get explicit sizes once the grid is measured (no collapsed height → no overlap)', () => {
    renderAlbum();
    fireEvent(screen.getByTestId('album-tiles'), 'layout', { nativeEvent: { layout: { width: 343, height: 600, x: 0, y: 0 } } });
    for (const id of ['photo', 'idle', 'sleeping', 'hungry']) {
      expect(StyleSheet.flatten(screen.getByTestId(`album-item-${id}`).props.style)).toMatchObject({ width: 165, height: 206 });
    }
  });
});

describe('viewer', () => {
  it('shows the poster over the player until the video is ready', () => {
    renderAlbum();
    fireEvent.press(screen.getByTestId('album-item-idle'));
    expect(screen.getByTestId('album-video')).toBeTruthy();
    expect(screen.getByTestId('album-video-poster-image').props.source).toEqual({ uri: IMG });
    const player = liveVideoPlayers()[0];
    act(() => player.emit('statusChange', { status: 'readyToPlay' }));
    expect(screen.queryByTestId('album-video-poster')).toBeNull();
  });

  it('first frame also reveals the video (devices late with the status)', () => {
    renderAlbum();
    fireEvent.press(screen.getByTestId('album-item-sleeping'));
    expect(screen.getByTestId('album-video-poster')).toBeTruthy();
    fireEvent(screen.getByTestId('album-video'), 'firstFrameRender');
    expect(screen.queryByTestId('album-video-poster')).toBeNull();
  });
});
