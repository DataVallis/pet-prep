/**
 * 2026-11-24 `pet-media` reports: the pure message builder (label, stage, spans, scrubbed
 * player text — never a URL) and the once-per-label-and-stage session dedupe.
 */
import { reportAppError } from '@/modules/errorReporting/reporter';
import {
  describeClip,
  formatSpan,
  linkLeftMs,
  petMediaErrorMessage,
  PetMediaError,
  reportPetMediaFailure,
  resetPetMediaReportsForTests,
  videoErrorStage,
  type ClipItem,
  type PetMediaFailure,
} from '@/modules/petMedia/mediaErrorReport';
import { NO_VIDEO_ERRORS, recordVideoError } from '@/modules/petMedia/petMedia';

jest.mock('@/modules/errorReporting/reporter', () => ({ reportAppError: jest.fn(() => true) }));
const reportMock = reportAppError as jest.MockedFunction<typeof reportAppError>;

const URL_A = 'https://api.petprep.si/api/media/42?expires=1800000000&v=abc&signature=deadbeef';

function clip(overrides: Partial<ClipItem> = {}): ClipItem {
  return { kind: 'clip', url: URL_A, key: 'https://api.petprep.si/api/media/42?v=abc', state: 'idle', loop: true, transition: null, ...overrides };
}

const NO_PHASES = { phases: {} };

function failure(overrides: Partial<PetMediaFailure> = {}): PetMediaFailure {
  return { label: 'item:toy_1', local: false, stage: 'refetch', layerAgeMs: 3_200, linkLeftMs: -720_000, playerMessage: null, ...overrides };
}

beforeEach(() => {
  resetPetMediaReportsForTests();
  reportMock.mockClear();
  reportMock.mockReturnValue(true);
});

describe('describeClip', () => {
  it('names state loops, transitions, bought items, item moments and phase clips', () => {
    expect(describeClip(clip({ state: 'playing' }), NO_PHASES)).toBe('state:playing');
    expect(describeClip(clip({ transition: 'idle_to_sleeping', loop: false, state: 'sleeping' }), NO_PHASES)).toBe('transition:idle_to_sleeping');
    expect(describeClip(clip({ state: 'playing', item: 'toy_1' }), NO_PHASES)).toBe('item:toy_1');
    expect(describeClip(clip({ state: null, loop: false, key: 'https://x/api/media/9?v=1#item-bowls-drink-3' }), NO_PHASES)).toBe('item:bowls-drink');
    expect(describeClip(clip({ state: null, loop: false, key: 'https://x/api/media/9?v=1#item-treats-12' }), NO_PHASES)).toBe('item:treats');
    expect(describeClip(clip({ state: null, loop: false, key: 'k#7' }), { phases: { school_sit_performing: URL_A } })).toBe('phase:school_sit_performing');
    expect(describeClip(clip({ state: null, loop: true }), NO_PHASES)).toBe('clip:loop');
  });

  it('never carries a URL, a host or an id', () => {
    const label = describeClip(clip({ transition: 'a/../b?x=1' }), NO_PHASES);
    expect(label).toBe('transition:abx1');
    expect(label).not.toMatch(/https|petprep|42|signature/);
  });
});

describe('videoErrorStage (mirrors recordVideoError)', () => {
  const key = 'https://api.petprep.si/api/media/42?v=abc';

  it('first error → refetch, second → cooldown, after the cool-down → given-up, then nothing', () => {
    const first = recordVideoError(NO_VIDEO_ERRORS, URL_A, true, 0);
    expect(videoErrorStage(NO_VIDEO_ERRORS, first.state, key, first.refetch)).toBe('refetch');
    const second = recordVideoError(first.state, URL_A, true, 1_000);
    expect(videoErrorStage(first.state, second.state, key, second.refetch)).toBe('cooldown');
    const during = recordVideoError(second.state, URL_A, true, 2_000);
    expect(videoErrorStage(second.state, during.state, key, during.refetch)).toBeNull();
    const last = recordVideoError(second.state, URL_A, true, 120_000);
    expect(videoErrorStage(second.state, last.state, key, last.refetch)).toBe('given-up');
    const after = recordVideoError(last.state, URL_A, true, 200_000);
    expect(videoErrorStage(last.state, after.state, key, after.refetch)).toBeNull();
  });

  it('no way to refetch → straight to the cool-down', () => {
    const first = recordVideoError(NO_VIDEO_ERRORS, URL_A, false, 0);
    expect(videoErrorStage(NO_VIDEO_ERRORS, first.state, key, first.refetch)).toBe('cooldown');
  });
});

describe('spans', () => {
  it('formats with at most three digits', () => {
    expect(formatSpan(0)).toBe('0s');
    expect(formatSpan(59_999)).toBe('59s');
    expect(formatSpan(12 * 60_000)).toBe('12m');
    expect(formatSpan(47 * 3_600_000)).toBe('47h');
    expect(formatSpan(3 * 86_400_000)).toBe('3d');
    expect(formatSpan(2_000 * 86_400_000)).toBe('>999d');
  });

  it('reads the signature time left from `expires` (unix seconds); none without it', () => {
    expect(linkLeftMs(URL_A, 1_800_000_000_000 - 60_000)).toBe(60_000);
    expect(linkLeftMs(URL_A, 1_800_000_000_000 + 5_000)).toBe(-5_000);
    expect(linkLeftMs('file:///cache/pet-media/abc.mp4', 0)).toBeNull();
    expect(linkLeftMs('https://x/y?noexpires=12', 0)).toBeNull();
  });
});

describe('petMediaErrorMessage', () => {
  it('uses the fixed format', () => {
    expect(petMediaErrorMessage(failure())).toBe('pet-media item:toy_1 src=remote stage=refetch layer=3s link=-12m player=-');
    expect(petMediaErrorMessage(failure({ local: true, stage: 'given-up', linkLeftMs: null, playerMessage: 'Decoder init failed' }))).toBe(
      'pet-media item:toy_1 src=local stage=given-up layer=3s link=none player=Decoder init failed',
    );
    expect(petMediaErrorMessage(failure({ linkLeftMs: 42 * 60_000 }))).toContain('link=+42m');
  });

  it('scrubs the player text: no URL query, no paths, no long numbers; clipped', () => {
    const message = petMediaErrorMessage(
      failure({ playerMessage: `Failed to load https://api.petprep.si/api/media/42?expires=1800000000&signature=deadbeef from /var/mobile/Containers/Data/abc/x.mp4 code 123456\n${'x'.repeat(400)}` }),
    );
    expect(message).not.toMatch(/signature|deadbeef|expires=|Containers|123456/);
    expect(message.length).toBeLessThan(400);
  });
});

describe('reportPetMediaFailure', () => {
  it('reports scope pet-media with a PetMediaError carrying the message', () => {
    expect(reportPetMediaFailure(failure())).toBe(true);
    expect(reportMock).toHaveBeenCalledTimes(1);
    const [scope, error] = reportMock.mock.calls[0] ?? [];
    expect(scope).toBe('pet-media');
    expect(error).toBeInstanceOf(PetMediaError);
    expect((error as Error).name).toBe('PetMediaError');
    expect((error as Error).message).toBe('pet-media item:toy_1 src=remote stage=refetch layer=3s link=-12m player=-');
  });

  it('one report per label + stage per session', () => {
    reportPetMediaFailure(failure());
    reportPetMediaFailure(failure({ layerAgeMs: 9_000 }));
    reportPetMediaFailure(failure({ stage: 'cooldown' }));
    reportPetMediaFailure(failure({ label: 'state:playing' }));
    expect(reportMock).toHaveBeenCalledTimes(3);
  });

  it('a report that was not queued (switch off / release only) may be tried again', () => {
    reportMock.mockReturnValueOnce(false);
    expect(reportPetMediaFailure(failure())).toBe(false);
    expect(reportPetMediaFailure(failure())).toBe(true);
    expect(reportMock).toHaveBeenCalledTimes(2);
  });

  it('never throws', () => {
    reportMock.mockImplementationOnce(() => {
      throw new Error('boom');
    });
    expect(reportPetMediaFailure(failure())).toBe(false);
  });
});
