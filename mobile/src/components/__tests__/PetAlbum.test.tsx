/**
 * "Moj kuža" album viewer: grid of entitled media, one player, navigation, mute and the
 * expired-URL refetch path.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';
import { BackHandler } from 'react-native';

import PetAlbum from '@/components/PetAlbum';
import { ALBUM_REFRESH_COOLDOWN_MS, ALBUM_STRINGS } from '@/modules/petMedia/album';
import { normalizePetMedia, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import { makeMedia } from '@/test-utils/fixtures';
import { liveVideoPlayers, resetMockVideoPlayers, sourceUri } from '@/test-utils/videoPlayers';

type BackPressHandler = Parameters<typeof BackHandler.addEventListener>[1];
/** Simulates the Android hardware back button on a captured handler. */
const pressBack = (h: BackPressHandler | null | undefined) => h?.({} as Parameters<BackPressHandler>[0]);
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
  const onClose = jest.fn();
  const onMediaExpired = jest.fn();
  const utils = render(<PetAlbum media={media} onClose={onClose} onMediaExpired={onMediaExpired} />);
  return { ...utils, onClose, onMediaExpired };
}

beforeEach(() => resetMockVideoPlayers());

describe('PetAlbum grid', () => {
  it('lists the photo and only entitled states; missing ones greyed, no player in the grid', () => {
    renderAlbum();

    expect(screen.getByText(ALBUM_STRINGS.title)).toBeTruthy();
    expect(screen.getByTestId('album-item-photo')).toBeTruthy();
    expect(screen.getByText('Miruje')).toBeTruthy();
    expect(screen.getByText('Spi')).toBeTruthy();
    // hungry is entitled but not stored yet
    expect(screen.getByTestId('album-item-hungry')).toBeTruthy();
    expect(screen.getByText(ALBUM_STRINGS.missing)).toBeTruthy();
    // not entitled → not shown at all
    expect(screen.queryByText('Se igra')).toBeNull();
    expect(screen.queryByText('Bolan')).toBeNull();
    expect(liveVideoPlayers()).toHaveLength(0);
  });

  it('a missing tile cannot be opened', () => {
    renderAlbum();
    fireEvent.press(screen.getByTestId('album-item-hungry'));
    expect(screen.queryByTestId('album-viewer')).toBeNull();
  });

  it('shows a gentle message when there is nothing yet', () => {
    renderAlbum(normalizePetMedia(makeMedia({ states: [] })));
    expect(screen.getByTestId('album-empty')).toBeTruthy();
  });

  it('closes with X', () => {
    const { onClose } = renderAlbum();
    fireEvent.press(screen.getByLabelText(ALBUM_STRINGS.close));
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});

describe('PetAlbum viewer', () => {
  it('plays the selected video full screen: looping, muted, cached', () => {
    renderAlbum();
    fireEvent.press(screen.getByTestId('album-item-sleeping'));

    expect(screen.getByTestId('album-viewer')).toBeTruthy();
    expect(screen.getByTestId('album-video')).toBeTruthy();
    const players = liveVideoPlayers();
    expect(players).toHaveLength(1);
    expect(players[0].source).toEqual({ uri: SLEEP, useCaching: true });
    expect(players[0]).toMatchObject({ loop: true, muted: true, playing: true });
    expect(screen.getByTestId('album-position').props.children).toBe('3 / 3');
  });

  it('toggles the sound', () => {
    renderAlbum();
    fireEvent.press(screen.getByTestId('album-item-idle'));
    fireEvent.press(screen.getByLabelText(ALBUM_STRINGS.unmute));
    expect(liveVideoPlayers()[0].muted).toBe(false);
    fireEvent.press(screen.getByLabelText(ALBUM_STRINGS.mute));
    expect(liveVideoPlayers()[0].muted).toBe(true);
  });

  it('arrows move between playable items (wrapping) with only one live player', () => {
    renderAlbum();
    fireEvent.press(screen.getByTestId('album-item-idle'));
    expect(sourceUri(liveVideoPlayers()[0])).toBe(IDLE);

    fireEvent.press(screen.getByTestId('album-next'));
    expect(liveVideoPlayers()).toHaveLength(1);
    expect(sourceUri(liveVideoPlayers()[0])).toBe(SLEEP);

    // sleeping → (hungry is missing, skipped) → photo
    fireEvent.press(screen.getByTestId('album-next'));
    expect(liveVideoPlayers()).toHaveLength(0);
    expect(screen.getByTestId('album-photo')).toBeTruthy();
    expect(screen.queryByTestId('album-mute')).toBeNull();

    fireEvent.press(screen.getByTestId('album-prev'));
    expect(sourceUri(liveVideoPlayers()[0])).toBe(SLEEP);
  });

  it('back returns to the grid and releases the player', () => {
    renderAlbum();
    fireEvent.press(screen.getByTestId('album-item-idle'));
    fireEvent.press(screen.getByTestId('album-back'));
    expect(screen.getByTestId('album-grid')).toBeTruthy();
    expect(liveVideoPlayers()).toHaveLength(0);
  });

  it('expired URL: asks once for fresh URLs, then plays the re-signed one', () => {
    const { rerender, onMediaExpired, onClose } = renderAlbum();
    fireEvent.press(screen.getByTestId('album-item-idle'));

    act(() => liveVideoPlayers()[0].emit('statusChange', { status: 'error' }));
    expect(onMediaExpired).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('album-video-unavailable')).toBeTruthy();
    expect(liveVideoPlayers()).toHaveLength(1); // the source-less placeholder player, not playing
    expect(liveVideoPlayers()[0].playing).toBe(false);

    // Refetch delivered a re-signed URL for the same file.
    const fresh = url(2, 'idle', 'b');
    rerender(<PetAlbum media={mediaWith({ videos: { idle: fresh, sleeping: SLEEP } })} onClose={onClose} onMediaExpired={onMediaExpired} />);
    expect(screen.getByTestId('album-video')).toBeTruthy();
    expect(sourceUri(liveVideoPlayers()[0])).toBe(fresh);

    // It fails as well → no refetch loop, just the gentle message.
    act(() => liveVideoPlayers()[0].emit('statusChange', { status: 'error' }));
    expect(onMediaExpired).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('album-video-unavailable')).toBeTruthy();
  });

  it('a re-signed URL alone does not restart a playing video', () => {
    const { rerender, onMediaExpired, onClose } = renderAlbum();
    fireEvent.press(screen.getByTestId('album-item-idle'));
    const first = liveVideoPlayers()[0];

    rerender(<PetAlbum media={mediaWith({ videos: { idle: url(2, 'idle', 'c'), sleeping: SLEEP } })} onClose={onClose} onMediaExpired={onMediaExpired} />);
    expect(liveVideoPlayers()).toEqual([first]);
    expect(sourceUri(first)).toBe(IDLE);
  });

  it('expired photo: asks for fresh URLs', () => {
    const { onMediaExpired } = renderAlbum();
    fireEvent.press(screen.getByTestId('album-item-photo'));
    fireEvent(screen.getByTestId('album-photo'), 'error');
    expect(onMediaExpired).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('album-photo-unavailable')).toBeTruthy();
  });

  it('goes back to the grid when the open item disappears', () => {
    const { rerender, onMediaExpired, onClose } = renderAlbum();
    fireEvent.press(screen.getByTestId('album-item-sleeping'));
    rerender(<PetAlbum media={mediaWith({ videos: { idle: IDLE } })} onClose={onClose} onMediaExpired={onMediaExpired} />);
    expect(screen.getByTestId('album-grid')).toBeTruthy();
  });
});

describe('PetAlbum — PR #31 review', () => {
  afterEach(() => {
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  function failCurrent() {
    act(() => liveVideoPlayers()[0].emit('statusChange', { status: 'error' }));
  }

  function reopen(id: string) {
    fireEvent.press(screen.getByTestId('album-back'));
    fireEvent.press(screen.getByTestId(`album-item-${id}`));
  }

  it('allows another refresh for the same item after the 5-min cooldown', () => {
    jest.useFakeTimers({ now: Date.parse('2026-10-05T10:00:00Z') });
    const { onMediaExpired } = renderAlbum();
    fireEvent.press(screen.getByTestId('album-item-idle'));
    failCurrent();
    expect(onMediaExpired).toHaveBeenCalledTimes(1);

    reopen('idle');
    failCurrent();
    expect(onMediaExpired).toHaveBeenCalledTimes(1); // within the cooldown

    act(() => jest.advanceTimersByTime(ALBUM_REFRESH_COOLDOWN_MS));
    reopen('idle');
    failCurrent();
    expect(onMediaExpired).toHaveBeenCalledTimes(2);
  });

  it('"Poskusi znova" remounts the item with the newest URL', () => {
    const { rerender, onMediaExpired, onClose } = renderAlbum();
    fireEvent.press(screen.getByTestId('album-item-idle'));
    failCurrent();
    expect(screen.getByTestId('album-video-unavailable')).toBeTruthy();

    // Fresh URLs arrived meanwhile but were the same signature bucket → still failed; retry anyway.
    rerender(<PetAlbum media={mediaWith()} onClose={onClose} onMediaExpired={onMediaExpired} />);
    fireEvent.press(screen.getByTestId('album-video-unavailable-retry'));
    expect(screen.getByTestId('album-video')).toBeTruthy();
    expect(sourceUri(liveVideoPlayers()[0])).toBe(IDLE);
  });

  it('refreshes 1 min before media.expiresAt while open', () => {
    jest.useFakeTimers({ now: Date.parse('2026-10-05T10:00:00Z') });
    const { onMediaExpired } = renderAlbum(mediaWith({ expires_at: '2026-10-05T10:05:00Z' }));
    act(() => jest.advanceTimersByTime(4 * 60_000 - 1));
    expect(onMediaExpired).not.toHaveBeenCalled();
    act(() => jest.advanceTimersByTime(1));
    expect(onMediaExpired).toHaveBeenCalledTimes(1);
  });

  it('Android back: viewer → grid, grid → close', () => {
    let handler: BackPressHandler | null = null;
    jest.spyOn(BackHandler, 'addEventListener').mockImplementation((_event, h) => {
      handler = h;
      return { remove: jest.fn() };
    });
    const { onClose } = renderAlbum();
    fireEvent.press(screen.getByTestId('album-item-idle'));

    let consumed: boolean | null | undefined = false;
    act(() => {
      consumed = pressBack(handler);
    });
    expect(consumed).toBe(true);
    expect(screen.getByTestId('album-grid')).toBeTruthy();
    expect(onClose).not.toHaveBeenCalled();

    act(() => {
      pressBack(handler);
    });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('custom title (parent) and a11y hints on the tiles', () => {
    render(<PetAlbum media={mediaWith()} onClose={jest.fn()} title={ALBUM_STRINGS.parentTitle} />);
    expect(screen.getByText('Posnetki kužka')).toBeTruthy();
    expect(screen.getByTestId('album-item-idle').props.accessibilityHint).toBe('video');
    expect(screen.getByTestId('album-item-photo').props.accessibilityHint).toBe('fotografija');
  });
});
