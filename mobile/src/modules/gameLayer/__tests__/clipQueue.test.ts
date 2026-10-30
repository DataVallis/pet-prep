import { act, renderHook } from '@testing-library/react-native';

import {
  CLIP_END_GRACE_MS,
  CROSSFADE_MAX_MS,
  clipEnded,
  createClipQueue,
  nextClipTickAt,
  requestClip,
  setMasked,
  switchKind,
  tickClips,
  type ClipSpec,
} from '@/modules/gameLayer/clipQueue';
import { useClipQueue } from '@/modules/gameLayer/useClipQueue';

const hub: ClipSpec = { role: 'hub', loop: true, startPose: 'stand', endPose: 'stand', durationMs: 0, endGraceMs: 0 };
const out: ClipSpec = { role: 'out', loop: false, startPose: 'stand', endPose: 'empty', durationMs: 3_000, endGraceMs: CLIP_END_GRACE_MS };
const back: ClipSpec = { role: 'back', loop: false, startPose: 'empty', endPose: 'stand', durationMs: 3_000, endGraceMs: CLIP_END_GRACE_MS };
const sit: ClipSpec = { role: 'sit', loop: true, startPose: 'sit', endPose: 'sit', durationMs: 0, endGraceMs: 0 };
const SPECS = [hub, out, back, sit];

describe('ClipQueue chain rules (GAMES_SPEC §3.2)', () => {
  it('switch kinds: crossfade only from a loop, instant on the same frame, everything else a cut', () => {
    expect(switchKind(hub, out)).toBe('crossfade');
    expect(switchKind(out, back)).toBe('instant');
    expect(switchKind(back, hub)).toBe('instant');
    expect(switchKind(hub, back)).toBe('cut');
    expect(switchKind(sit, hub)).toBe('cut');
  });

  it('a loop is left at once with a crossfade of at most 200 ms', () => {
    const q = requestClip(createClipQueue(SPECS, 'hub', 0), 'out', 500);
    expect(q.current).toBe('out');
    expect(q.instance).toBe(1);
    expect(q.startedAt).toBe(500);
    expect(q.lastSwitch?.kind).toBe('crossfade');
    expect(q.lastSwitch?.ms).toBeGreaterThan(0);
    expect(q.lastSwitch?.ms).toBeLessThanOrEqual(CROSSFADE_MAX_MS);
  });

  it('never cuts a clip mid-way: the request waits for the end (the newest one wins)', () => {
    let q = requestClip(createClipQueue(SPECS, 'hub', 0), 'out', 0);
    q = requestClip(q, 'hub', 1_000);
    expect(q.current).toBe('out');
    expect(q.pending).toBe('hub');
    q = requestClip(q, 'back', 1_500);
    expect(q.pending).toBe('back');
    q = clipEnded(q, 'out', q.instance, 3_000);
    expect(q.current).toBe('back');
    expect(q.lastSwitch).toEqual({ from: 'out', kind: 'instant', ms: 0 });
  });

  it('an ended once clip holds its last frame until something is asked for', () => {
    let q = requestClip(createClipQueue(SPECS, 'hub', 0), 'out', 0);
    q = clipEnded(q, 'out', q.instance, 3_000);
    expect(q.current).toBe('out');
    expect(q.ended).toBe(true);
    q = requestClip(q, 'back', 4_200);
    expect(q.current).toBe('back');
    expect(q.startedAt).toBe(4_200);
  });

  it('a pose change is a cut — only while the feedback mask is up', () => {
    let q = createClipQueue(SPECS, 'sit', 0);
    q = requestClip(q, 'hub', 100);
    expect(q.current).toBe('sit');
    expect(q.pending).toBe('hub');
    q = setMasked(q, true, 900);
    expect(q.current).toBe('hub');
    expect(q.lastSwitch?.kind).toBe('cut');
    expect(q.lastSwitch?.ms).toBe(0);
  });

  it('ignores the end event of an older instance or of another role', () => {
    let q = requestClip(createClipQueue(SPECS, 'hub', 0), 'out', 0);
    expect(clipEnded(q, 'out', q.instance - 1, 3_000)).toBe(q);
    expect(clipEnded(q, 'hub', q.instance, 3_000)).toBe(q);
    q = clipEnded(q, 'out', q.instance, 3_000);
    expect(q.ended).toBe(true);
  });

  it('the clock ends a once clip at its length + grace when the player never reports it', () => {
    let q = requestClip(createClipQueue(SPECS, 'hub', 0), 'out', 0);
    expect(nextClipTickAt(q)).toBe(3_000 + CLIP_END_GRACE_MS);
    expect(tickClips(q, 3_000)).toBe(q);
    q = tickClips(q, 3_000 + CLIP_END_GRACE_MS);
    expect(q.ended).toBe(true);
    expect(nextClipTickAt(q)).toBeNull();
  });

  it('asking for the loop that already plays drops a waiting request', () => {
    let q = createClipQueue(SPECS, 'sit', 0);
    q = requestClip(q, 'hub', 0);
    expect(q.pending).toBe('hub');
    q = requestClip(q, 'sit', 10);
    expect(q.pending).toBeNull();
  });

  it('an unknown role changes nothing; an unknown initial role is a programming error', () => {
    const q = createClipQueue(SPECS, 'hub', 0);
    expect(requestClip(q, 'nope', 1)).toBe(q);
    expect(() => createClipQueue(SPECS, 'nope', 0)).toThrow();
  });
});

describe('useClipQueue (fake timers)', () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it('runs the clock safety and the mask on the game clock', () => {
    let clock = 0;
    const { result } = renderHook(() => useClipQueue(() => clock));
    expect(result.current.state).toBeNull();
    act(() => result.current.start(SPECS, 'hub'));
    act(() => result.current.request('out'));
    expect(result.current.state?.current).toBe('out');
    act(() => result.current.request('back'));
    // Mid-clip: still `out`.
    clock = 2_000;
    act(() => {
      jest.advanceTimersByTime(2_000);
    });
    expect(result.current.state?.current).toBe('out');
    // No end event from the player → the clock ends it at 3 s + grace and `back` follows.
    clock = 3_000 + CLIP_END_GRACE_MS;
    act(() => {
      jest.advanceTimersByTime(1_000 + CLIP_END_GRACE_MS);
    });
    expect(result.current.state?.current).toBe('back');
    // The player's own end event is enough too.
    const instance = result.current.state?.instance ?? -1;
    act(() => result.current.ended('back', instance));
    expect(result.current.state?.ended).toBe(true);
    // Back in the hub on the same frame.
    act(() => result.current.request('hub'));
    expect(result.current.state?.current).toBe('hub');
    // A pose change waits for the feedback mask.
    act(() => result.current.request('sit'));
    expect(result.current.state?.current).toBe('hub');
    act(() => result.current.masked(true));
    expect(result.current.state?.current).toBe('sit');
  });
});
