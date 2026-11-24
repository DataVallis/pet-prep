/**
 * 2026-11-24 `pet-media` reports: a failing player in PetMediaView reports what played
 * (state loop / bought toy), local vs remote, the stage of the retry chain and the player's
 * message — never the URL — once per label + stage per session.
 */
import { act, render } from '@testing-library/react-native';

import PetMediaView from '@/components/PetMediaView';
import { reportAppError } from '@/modules/errorReporting/reporter';
import { resetPetMediaReportsForTests } from '@/modules/petMedia/mediaErrorReport';
import { normalizePetMedia, FAILED_RETRY_MS, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import type { PetMedia, PetState } from '@/types';
import { makeMedia } from '@/test-utils/fixtures';
import { mockVideoPlayers, resetMockVideoPlayers } from '@/test-utils/videoPlayers';

jest.mock('@/modules/errorReporting/reporter', () => ({ reportAppError: jest.fn(() => true) }));
const reportMock = reportAppError as jest.MockedFunction<typeof reportAppError>;

const NOW = Date.UTC(2026, 10, 24, 10, 0, 0);
const EXPIRES = Math.floor(NOW / 1000) - 12 * 60; // signature expired 12 min ago
const url = (path: string, v: string, sig = 's1') => `https://api.petprep.si/api/media/${path}?expires=${EXPIRES}&v=${v}&signature=${sig}`;
const IMG = url('1', 'img');
const IDLE = url('2', 'idle');
const PLAYING = url('5', 'play');
const TOY_IMG = url('items/61', 'toyimg');
const TOY_VID = url('items/62', 'toyvid');

function mediaWith(items: PetMedia['items'] = {}): PetMediaInfo {
  return normalizePetMedia(
    makeMedia({ status: 'ready', reference_image_url: IMG, videos: { idle: IDLE, playing: PLAYING }, states: ['idle', 'playing'], items }),
  );
}

function messages(): string[] {
  return reportMock.mock.calls.map(([, error]) => (error as Error).message);
}

beforeEach(() => {
  jest.useFakeTimers({ now: NOW });
  resetMockVideoPlayers();
  resetPetMediaReportsForTests();
  reportMock.mockClear();
});

afterEach(() => {
  jest.useRealTimers();
});

describe('PetMediaView → pet-media reports', () => {
  it('state loop: first error → refetch report with the player message, never the URL', () => {
    const onMediaExpired = jest.fn();
    render(<PetMediaView media={mediaWith()} petState={'idle' as PetState} breed="mutt" onMediaExpired={onMediaExpired} />);
    act(() => {
      jest.advanceTimersByTime(2_000);
      mockVideoPlayers[0]?.emit('statusChange', { status: 'error', error: { message: `HTTP 403 for ${IDLE}` } });
    });

    expect(onMediaExpired).toHaveBeenCalledTimes(1);
    expect(reportMock).toHaveBeenCalledTimes(1);
    expect(reportMock.mock.calls[0]?.[0]).toBe('pet-media');
    const [message] = messages();
    expect(message).toMatch(/^pet-media state:idle src=remote stage=refetch layer=2s link=-12m player=HTTP 403 for /);
    expect(message).not.toMatch(/signature|s1|expires=/);
  });

  it('bought toy: refetch → cooldown → given-up, each reported once; a remount does not repeat them', () => {
    const items: PetMedia['items'] = { toy_1: { image: TOY_IMG, video: TOY_VID, video_seconds: 3, loop: true } };
    const props = { petState: 'idle' as PetState, mood: 'playing' as const, toy: 'toy_1' as const, breed: 'mutt' as const, onMediaExpired: jest.fn() };
    const view = render(<PetMediaView media={mediaWith(items)} {...props} />);
    const fail = () =>
      act(() => {
        const live = mockVideoPlayers.filter((p) => !p.released);
        live[live.length - 1]?.emit('statusChange', { status: 'error' });
      });

    fail();
    // The re-signed URL fails too → cool-down.
    const resigned: PetMedia['items'] = { toy_1: { image: TOY_IMG, video: url('items/62', 'toyvid', 's2'), video_seconds: 3, loop: true } };
    view.rerender(<PetMediaView media={mediaWith(resigned)} {...props} />);
    fail();
    act(() => {
      jest.advanceTimersByTime(FAILED_RETRY_MS + 50);
    });
    fail();

    expect(messages().map((m) => m.split(' ').slice(0, 4).join(' '))).toEqual([
      'pet-media item:toy_1 src=remote stage=refetch',
      'pet-media item:toy_1 src=remote stage=cooldown',
      'pet-media item:toy_1 src=remote stage=given-up',
    ]);
    expect(messages().every((m) => m.endsWith('player=-'))).toBe(true);

    // A new mount (new error history) fails the same way: nothing new is reported this session.
    view.unmount();
    render(<PetMediaView media={mediaWith(items)} {...props} />);
    fail();
    expect(reportMock).toHaveBeenCalledTimes(3);
  });
});
