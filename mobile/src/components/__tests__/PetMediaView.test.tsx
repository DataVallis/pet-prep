/**
 * M4-03 app side: PetMediaView plays the state video (loop, muted), crossfades on a
 * state change without dropping the old frame early, keeps the player when only the
 * signature changes, refetches once on an expired URL, shows pending / failed calmly,
 * pauses in the background and releases every player.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';
import { Animated, AppState, type AppStateStatus } from 'react-native';

import PetMediaView, { CROSSFADE_MS, PET_MEDIA_STRINGS, READY_FALLBACK_MS } from '@/components/PetMediaView';
import { EMPTY_PET_MEDIA, FAILED_RETRY_MS, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import { liveVideoPlayers, mockVideoPlayers, playerUris, resetMockVideoPlayers, sourceUri } from '@/test-utils/videoPlayers';
import type { PetState } from '@/types';

const BASE = 'https://api.petprep.si/api/media';
const url = (id: number, v: string, sig = 's1', expires = 1_000) => `${BASE}/${id}?expires=${expires}&v=${v}&signature=${sig}`;

const IMAGE = url(1, 'img');
const IDLE = url(2, 'idle');
const SLEEPING = url(3, 'sleep');
const HUNGRY = url(4, 'hungry');

function media(overrides: Partial<PetMediaInfo> = {}): PetMediaInfo {
  return {
    ...EMPTY_PET_MEDIA,
    status: 'ready',
    referenceImageUrl: IMAGE,
    videos: { idle: IDLE, sleeping: SLEEPING, hungry: HUNGRY },
    states: ['idle', 'sleeping', 'hungry'],
    ...overrides,
  };
}

type Props = Partial<React.ComponentProps<typeof PetMediaView>>;

function renderMedia(props: Props = {}) {
  const all: React.ComponentProps<typeof PetMediaView> = {
    media: media(),
    petState: 'idle' as PetState,
    breed: 'mutt',
    ...props,
  };
  const utils = render(<PetMediaView {...all} />);
  return {
    ...utils,
    update: (next: Props) => utils.rerender(<PetMediaView {...all} {...next} />),
  };
}

/** Let the new layer render its first frame, then finish the crossfade. */
async function showFirstFrame(phaseTestId = 'pet-media-video-loading') {
  fireEvent(screen.getByTestId(phaseTestId), 'firstFrameRender');
  await act(async () => {
    jest.advanceTimersByTime(CROSSFADE_MS + 50);
  });
}

let appStateListeners: Array<(state: AppStateStatus) => void> = [];

/**
 * Controlled Animated.timing: completes exactly after `duration` ms of fake time;
 * `stopAnimation()` on the value ends it unfinished (like the real driver). Makes the
 * 300 ms crossfade window testable.
 */
type EndCallback = Animated.EndCallback | undefined;
const running = new Map<Animated.Value, { timer: ReturnType<typeof setTimeout>; cb: EndCallback }>();

function stopRunning(value: Animated.Value): void {
  const r = running.get(value);
  if (!r) return;
  running.delete(value);
  clearTimeout(r.timer);
  r.cb?.({ finished: false });
}

function mockAnimatedTiming(): void {
  jest.spyOn(Animated, 'timing').mockImplementation((value, config) => {
    const v = value as Animated.Value;
    const toValue = config.toValue as number;
    return {
      start: (cb?: Animated.EndCallback) => {
        stopRunning(v);
        const timer = setTimeout(() => {
          running.delete(v);
          v.setValue(toValue);
          cb?.({ finished: true });
        }, config.duration ?? 0);
        running.set(v, { timer, cb });
      },
      stop: () => stopRunning(v),
      reset: () => stopRunning(v),
    };
  });
  const original = Animated.Value.prototype.stopAnimation;
  jest.spyOn(Animated.Value.prototype, 'stopAnimation').mockImplementation(function (this: Animated.Value, callback) {
    stopRunning(this);
    return original.call(this, callback);
  });
}

beforeEach(() => {
  jest.useFakeTimers();
  running.clear();
  mockAnimatedTiming();
  resetMockVideoPlayers();
  appStateListeners = [];
  jest.spyOn(AppState, 'addEventListener').mockImplementation((_type, listener) => {
    appStateListeners.push(listener as (state: AppStateStatus) => void);
    return { remove: () => undefined } as ReturnType<typeof AppState.addEventListener>;
  });
});

afterEach(() => {
  jest.useRealTimers();
  jest.restoreAllMocks();
});

describe('source selection', () => {
  it('plays the video of the current state — looping, muted, not keeping the screen on', async () => {
    renderMedia({ petState: 'hungry' });
    expect(mockVideoPlayers).toHaveLength(1);
    const [player] = mockVideoPlayers;
    expect(sourceUri(player)).toBe(HUNGRY);
    expect(player.source).toEqual({ uri: HUNGRY, useCaching: true });
    expect(player.loop).toBe(true);
    expect(player.muted).toBe(true);
    expect((player as unknown as { keepScreenOnWhilePlaying: boolean }).keepScreenOnWhilePlaying).toBe(false);
    expect(player.play).toHaveBeenCalled();
    // The reference image is underneath until the first frame.
    expect(screen.getByTestId('pet-media-image')).toBeTruthy();
    await showFirstFrame();
    expect(screen.getByTestId('pet-media-video-visible')).toBeTruthy();
  });

  it('falls back to idle for a state without a video or calmer substitute (basic entitlement)', () => {
    renderMedia({ petState: 'playing' });
    expect(sourceUri(mockVideoPlayers[0])).toBe(IDLE);
  });

  it('2026-10-06: free mutt (idle + sleeping) at the vet plays the sleeping video and reports the substitute', () => {
    const onVideoStateChange = jest.fn();
    const mutt = media({ videos: { idle: IDLE, sleeping: SLEEPING }, states: ['idle', 'sleeping'] });
    const { update } = renderMedia({ media: mutt, petState: 'idle', onVideoStateChange });
    expect(onVideoStateChange).toHaveBeenLastCalledWith('idle');
    update({ media: mutt, petState: 'idle', lockReason: 'ill', onVideoStateChange });
    expect(sourceUri(mockVideoPlayers[1])).toBe(SLEEPING);
    expect(onVideoStateChange).toHaveBeenLastCalledWith('sleeping');
    // Premium / generated later: the real sick video is reported as such.
    update({ media: media({ videos: { idle: IDLE, sleeping: SLEEPING, sick: url(5, 'sick') } }), petState: 'idle', lockReason: 'ill', onVideoStateChange });
    expect(onVideoStateChange).toHaveBeenLastCalledWith('sick');
    // Game over: no video at all.
    update({ media: mutt, petState: 'idle', lockReason: 'game_over', onVideoStateChange });
    expect(onVideoStateChange).toHaveBeenLastCalledWith(null);
  });

  it('no videos → the reference image, no player', () => {
    renderMedia({ media: media({ videos: {}, status: 'partial' }) });
    expect(mockVideoPlayers).toHaveLength(0);
    expect(screen.getByTestId('pet-media-image').props.source).toEqual({ uri: IMAGE });
  });

  it('nothing stored → breed placeholder', () => {
    renderMedia({ media: { ...EMPTY_PET_MEDIA, status: 'disabled' }, breed: 'border_collie' });
    expect(mockVideoPlayers).toHaveLength(0);
    expect(screen.getByTestId('pet-media-placeholder')).toBeTruthy();
    expect(screen.getByText(PET_MEDIA_STRINGS.breeds.border_collie)).toBeTruthy();
  });

  it('game over → no video, only the image', () => {
    renderMedia({ lockReason: 'game_over' });
    expect(mockVideoPlayers).toHaveLength(0);
    expect(screen.getByTestId('pet-media-image')).toBeTruthy();
  });

  it('vet visit plays the sick video, a hard stop the sleeping one', () => {
    const { update } = renderMedia({ petState: 'playing', lockReason: 'hard_stopped' });
    expect(sourceUri(mockVideoPlayers[0])).toBe(SLEEPING);
    update({ petState: 'playing', lockReason: 'ill', media: media({ videos: { idle: IDLE, sick: url(5, 'sick') } }) });
    expect(sourceUri(mockVideoPlayers[1])).toBe(url(5, 'sick'));
  });
});

describe('state change crossfade', () => {
  it('keeps the old video until the new one has a frame, then releases it', async () => {
    const { update } = renderMedia({ petState: 'idle' });
    await showFirstFrame();
    const idlePlayer = mockVideoPlayers[0];

    update({ petState: 'sleeping' });
    expect(mockVideoPlayers).toHaveLength(2);
    const sleepPlayer = mockVideoPlayers[1];
    expect(sourceUri(sleepPlayer)).toBe(SLEEPING);
    // Old frame stays on screen while the new one loads.
    expect(screen.getByTestId('pet-media-video-visible')).toBeTruthy();
    expect(screen.getByTestId('pet-media-video-loading')).toBeTruthy();
    expect(idlePlayer.released).toBe(false);

    await showFirstFrame();
    expect(idlePlayer.released).toBe(true);
    expect(liveVideoPlayers()).toEqual([sleepPlayer]);
    expect(screen.getAllByTestId('pet-media-video-visible')).toHaveLength(1);
  });

  it('shows the new layer after readyToPlay even without a first-frame callback', async () => {
    const { update } = renderMedia({ petState: 'idle' });
    await showFirstFrame();
    update({ petState: 'hungry' });
    act(() => {
      mockVideoPlayers[1].emit('statusChange', { status: 'readyToPlay' });
    });
    await act(async () => {
      jest.advanceTimersByTime(READY_FALLBACK_MS + CROSSFADE_MS + 50);
    });
    expect(liveVideoPlayers()).toEqual([mockVideoPlayers[1]]);
  });

  it('M1: back to the previous video during the 300 ms fade-in keeps it, fades the newer one out', async () => {
    const { update } = renderMedia({ petState: 'idle' });
    await showFirstFrame();
    const idlePlayer = mockVideoPlayers[0];

    update({ petState: 'sleeping' });
    const sleepPlayer = mockVideoPlayers[1];
    fireEvent(screen.getByTestId('pet-media-video-loading'), 'firstFrameRender');
    // Inside the fade window: sleeping is half-way in.
    await act(async () => {
      jest.advanceTimersByTime(CROSSFADE_MS / 2);
    });
    expect(idlePlayer.released).toBe(false);

    update({ petState: 'idle' });
    expect(screen.getByTestId('pet-media-video-leaving')).toBeTruthy();
    // Past the moment the interrupted fade-in would have finished (and removed idle).
    await act(async () => {
      jest.advanceTimersByTime(CROSSFADE_MS + 50);
    });
    expect(idlePlayer.released).toBe(false);
    expect(sleepPlayer.released).toBe(true);
    expect(liveVideoPlayers()).toEqual([idlePlayer]);
    expect(screen.getAllByTestId('pet-media-video-visible')).toHaveLength(1);
    // Idle is back at full opacity.
    const opacityOf = (testId: string): unknown => {
      let node: ReturnType<typeof screen.getByTestId> | null = screen.getByTestId(testId).parent;
      while (node) {
        const flat = [node.props.style].flat(5) as Array<Record<string, unknown> | null | undefined>;
        const found = flat.find((st) => st != null && typeof st === 'object' && 'opacity' in st);
        if (found) return found.opacity;
        node = node.parent;
      }
      return undefined;
    };
    expect(opacityOf('pet-media-video-visible')).toBe(1);
  });

  it('M1: back to a video that is fading out revives it', async () => {
    const { update } = renderMedia({ petState: 'idle' });
    await showFirstFrame();
    update({ lockReason: 'game_over' });
    await act(async () => {
      jest.advanceTimersByTime(CROSSFADE_MS / 2);
    });
    update({ lockReason: null });
    await act(async () => {
      jest.advanceTimersByTime(CROSSFADE_MS + 50);
    });
    expect(mockVideoPlayers).toHaveLength(1);
    expect(mockVideoPlayers[0].released).toBe(false);
    expect(screen.getByTestId('pet-media-video-visible')).toBeTruthy();
  });

  it('m7: videoEnabled=false shows the still image, no player', () => {
    renderMedia({ videoEnabled: false });
    expect(mockVideoPlayers).toHaveLength(0);
    expect(screen.getByTestId('pet-media-image')).toBeTruthy();
  });

  it('a quick change back drops the never-shown layer', async () => {
    const { update } = renderMedia({ petState: 'idle' });
    await showFirstFrame();
    update({ petState: 'sleeping' });
    update({ petState: 'idle' });
    expect(mockVideoPlayers[1].released).toBe(true);
    expect(liveVideoPlayers()).toEqual([mockVideoPlayers[0]]);
  });

  it('video → image (lock) fades the video out and releases it', async () => {
    const { update } = renderMedia({ petState: 'idle' });
    await showFirstFrame();
    update({ lockReason: 'game_over' });
    expect(screen.getByTestId('pet-media-video-leaving')).toBeTruthy();
    await act(async () => {
      jest.advanceTimersByTime(CROSSFADE_MS + 50);
    });
    expect(liveVideoPlayers()).toHaveLength(0);
  });
});

describe('URL stability', () => {
  it('a re-signed URL of the same video does not recreate or restart the player', async () => {
    const { update } = renderMedia({ petState: 'idle' });
    await showFirstFrame();
    const player = mockVideoPlayers[0];
    const playCalls = player.play.mock.calls.length;

    update({ media: media({ videos: { idle: url(2, 'idle', 's2', 2_800), sleeping: SLEEPING } }) });
    update({ media: media({ videos: { idle: url(2, 'idle', 's3', 4_600), sleeping: SLEEPING } }) });

    expect(mockVideoPlayers).toHaveLength(1);
    expect(player.replace).not.toHaveBeenCalled();
    expect(player.replaceAsync).not.toHaveBeenCalled();
    expect(player.play.mock.calls.length).toBe(playCalls);
    expect(player.released).toBe(false);
  });

  it('a new file (regenerated video, new v) crossfades to a new player', () => {
    const { update } = renderMedia({ petState: 'idle' });
    update({ media: media({ videos: { idle: url(2, 'idle-g2') } }) });
    expect(mockVideoPlayers).toHaveLength(2);
    expect(sourceUri(mockVideoPlayers[1])).toBe(url(2, 'idle-g2'));
  });

  it('a re-signed image URL keeps the rendered image', () => {
    const { update } = renderMedia({ media: media({ videos: {} }) });
    update({ media: media({ videos: {}, referenceImageUrl: url(1, 'img', 'other', 9_000) }) });
    expect(screen.getByTestId('pet-media-image').props.source).toEqual({ uri: IMAGE });
  });
});

describe('player errors', () => {
  it('error → keeps the last frame, refetches once → retry with the re-signed URL replaces it', async () => {
    const onMediaExpired = jest.fn();
    const { update } = renderMedia({ petState: 'idle', onMediaExpired });
    await showFirstFrame();
    const first = mockVideoPlayers[0];

    act(() => {
      first.emit('statusChange', { status: 'error', error: { message: '403' } });
    });
    expect(onMediaExpired).toHaveBeenCalledTimes(1);
    // m5: the frozen last frame stays (paused) while we wait for a new URL.
    expect(screen.getByTestId('pet-media-video-errored')).toBeTruthy();
    expect(first.released).toBe(false);
    expect(first.playing).toBe(false);

    // A poll with the same (failed) URL does not reload it.
    update({ media: media() });
    expect(mockVideoPlayers).toHaveLength(1);

    // The refetch brings a freshly signed URL → one retry, the frozen frame stays until it is ready.
    const freshUrl = url(2, 'idle', 'fresh', 5_000);
    update({ media: media({ videos: { idle: freshUrl, sleeping: SLEEPING } }) });
    expect(playerUris()).toEqual([IDLE, freshUrl]);
    expect(first.released).toBe(false);
    await showFirstFrame();
    expect(first.released).toBe(true);
    expect(liveVideoPlayers()).toEqual([mockVideoPlayers[1]]);
  });

  it('second error → image for 60 s, then one more try, then image for good', async () => {
    const onMediaExpired = jest.fn();
    const { update } = renderMedia({ petState: 'idle', onMediaExpired });
    act(() => {
      mockVideoPlayers[0].emit('statusChange', { status: 'error' });
    });
    // Never shown → dropped at once.
    expect(liveVideoPlayers()).toHaveLength(0);
    const fresh = media({ videos: { idle: url(2, 'idle', 'fresh', 5_000) } });
    update({ media: fresh });
    act(() => {
      mockVideoPlayers[1].emit('statusChange', { status: 'error' });
    });
    expect(onMediaExpired).toHaveBeenCalledTimes(1);
    expect(liveVideoPlayers()).toHaveLength(0);
    expect(screen.getByTestId('pet-media-image')).toBeTruthy();
    expect(screen.queryByText(/napak|error/i)).toBeNull();

    // m6: after the cool-down the same media is tried once more.
    await act(async () => {
      jest.advanceTimersByTime(FAILED_RETRY_MS + 50);
    });
    expect(mockVideoPlayers).toHaveLength(3);
    act(() => {
      mockVideoPlayers[2].emit('statusChange', { status: 'error' });
    });
    await act(async () => {
      jest.advanceTimersByTime(10 * FAILED_RETRY_MS);
    });
    expect(mockVideoPlayers).toHaveLength(3);
    expect(liveVideoPlayers()).toHaveLength(0);
    expect(onMediaExpired).toHaveBeenCalledTimes(1);
  });

  it('a failed shown video with no other video fades out to the image', async () => {
    renderMedia({ petState: 'idle' }); // no refetch handler → straight to the cool-down
    await showFirstFrame();
    act(() => {
      mockVideoPlayers[0].emit('statusChange', { status: 'error' });
    });
    await act(async () => {
      jest.advanceTimersByTime(CROSSFADE_MS + 50);
    });
    expect(liveVideoPlayers()).toHaveLength(0);
    expect(screen.getByTestId('pet-media-image')).toBeTruthy();
  });

  it('m4: a status reached before subscribing is honoured (error / readyToPlay)', async () => {
    // Simulate the native side being faster than our effect: patch the status in setup.
    const videoModule = jest.requireMock<typeof import('expo-video')>('expo-video');
    const real = videoModule.useVideoPlayer;
    const spy = jest.spyOn(videoModule, 'useVideoPlayer').mockImplementation((source, setup) =>
      real(source, (p) => {
        setup?.(p);
        (p as unknown as { status: string }).status = 'readyToPlay';
      }),
    );
    renderMedia({ petState: 'idle' });
    await act(async () => {
      jest.advanceTimersByTime(READY_FALLBACK_MS + CROSSFADE_MS + 50);
    });
    expect(screen.getByTestId('pet-media-video-visible')).toBeTruthy();

    spy.mockImplementation((source, setup) =>
      real(source, (p) => {
        setup?.(p);
        (p as unknown as { status: string }).status = 'error';
      }),
    );
    const onMediaExpired = jest.fn();
    renderMedia({ petState: 'sleeping', onMediaExpired, media: media({ videos: { sleeping: SLEEPING } }) });
    expect(onMediaExpired).toHaveBeenCalledTimes(1);
    expect(liveVideoPlayers().filter((p) => sourceUri(p) === SLEEPING)).toHaveLength(0);
  });
});

describe('pending / failed', () => {
  it('pending shows the gentle hint over the placeholder', () => {
    renderMedia({ media: { ...EMPTY_PET_MEDIA, status: 'pending' } });
    expect(screen.getByText(PET_MEDIA_STRINGS.pending)).toBeTruthy();
    expect(screen.getByTestId('pet-media-placeholder')).toBeTruthy();
  });

  it('pending with an image shows the image and the hint', () => {
    renderMedia({ media: media({ status: 'pending', videos: {} }) });
    expect(screen.getByTestId('pet-media-image')).toBeTruthy();
    expect(screen.getByTestId('pet-media-pending')).toBeTruthy();
  });

  it('failed shows no error to the child — image or placeholder only', () => {
    const { update } = renderMedia({ media: { ...EMPTY_PET_MEDIA, status: 'failed' } });
    expect(screen.getByTestId('pet-media-placeholder')).toBeTruthy();
    expect(screen.queryByTestId('pet-media-pending')).toBeNull();
    update({ media: media({ status: 'failed', videos: {} }) });
    expect(screen.getByTestId('pet-media-image')).toBeTruthy();
    expect(screen.queryByTestId('pet-media-pending')).toBeNull();
  });

  it('a broken image falls back to the placeholder', () => {
    renderMedia({ media: media({ videos: {} }) });
    fireEvent(screen.getByTestId('pet-media-image'), 'error');
    expect(screen.getByTestId('pet-media-placeholder')).toBeTruthy();
  });

  it('custom placeholder (HUD avatar) replaces the default one', () => {
    const { Text } = jest.requireActual<typeof import('react-native')>('react-native');
    renderMedia({ media: EMPTY_PET_MEDIA, placeholder: <Text>avatar</Text> });
    expect(screen.getByText('avatar')).toBeTruthy();
  });
});

describe('battery', () => {
  it('pauses in the background and resumes in the foreground', async () => {
    renderMedia({ petState: 'idle' });
    await showFirstFrame();
    const player = mockVideoPlayers[0];
    expect(player.playing).toBe(true);

    act(() => appStateListeners.forEach((l) => l('background')));
    expect(player.pause).toHaveBeenCalled();
    expect(player.playing).toBe(false);

    act(() => appStateListeners.forEach((l) => l('active')));
    expect(player.playing).toBe(true);
  });

  it('pauses while the screen is not focused (active=false)', () => {
    const { update } = renderMedia({ petState: 'idle', active: false });
    const player = mockVideoPlayers[0];
    expect(player.playing).toBe(false);
    update({ active: true });
    expect(player.playing).toBe(true);
    update({ active: false });
    expect(player.playing).toBe(false);
  });

  it('only one player in steady state; all released on unmount', async () => {
    const { update, unmount } = renderMedia({ petState: 'idle' });
    await showFirstFrame();
    update({ petState: 'hungry' });
    await showFirstFrame();
    update({ petState: 'sleeping' });
    await showFirstFrame();
    expect(liveVideoPlayers()).toHaveLength(1);
    unmount();
    expect(liveVideoPlayers()).toHaveLength(0);
  });
});
