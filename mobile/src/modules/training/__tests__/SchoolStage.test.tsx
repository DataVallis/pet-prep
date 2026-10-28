/**
 * School stage player (smooth playback 2026-10-28): all clips mounted once, paused at frame 0
 * and hidden; ready only when every player rendered (or failed); a phase change is an instant
 * switch (no fade, currentTime 0 + play, the previous pauses) — never a new player mid-session;
 * picture mode mounts nothing; everything is released on unmount.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';
import { StyleSheet } from 'react-native';

import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import { schoolSegmentAt, schoolTimeline, type SchoolSegment } from '@/modules/petMedia/phasePlayer';
import SchoolStage, { STAGE_READY_FALLBACK_MS } from '@/modules/training/SchoolStage';
import { readTrainingSession, type TrainingSession } from '@/modules/training/training';
import { makeMedia, makeSmoothTrainingSessionPayload } from '@/test-utils/fixtures';
import { liveVideoPlayers, mockVideoPlayers, resetMockVideoPlayers, sourceUri, type MockVideoPlayer } from '@/test-utils/videoPlayers';

const url = (name: string) => `https://api.petprep.si/api/media/${name}?v=${name}&expires=1&signature=s`;
const DERIVED = ['attention_loop', 'performing', 'success_loop', 'release'].map((p) => `school_sit_${p}`);

function media(phases: readonly string[] = DERIVED) {
  return normalizePetMedia(
    makeMedia({
      status: 'ready',
      reference_image_url: url('ref'),
      state_media: true,
      poses: { sit: url('pose-sit') },
      phases: Object.fromEntries(phases.map((p) => [p, url(p)])),
      phase_seconds: { school_sit_performing: 3, school_sit_release: 2.5 },
      phase_groups: ['school'],
    }),
  );
}

const session = readTrainingSession(makeSmoothTrainingSessionPayload()) as TrainingSession;
const timeline = schoolTimeline(session, 2_500);
const seg = (ms: number): SchoolSegment => schoolSegmentAt(timeline, ms);

function playerFor(key: string): MockVideoPlayer {
  const p = mockVideoPlayers.find((x) => sourceUri(x) === url(key));
  if (p === undefined) throw new Error(`no player for ${key}`);
  return p;
}

/** The opacity of a role's player wrapper (`…-shown` / `…-hidden`). */
function opacityOf(testID: string): number | undefined {
  const wrapper = screen.queryByTestId(`${testID}-shown`) ?? screen.getByTestId(`${testID}-hidden`);
  return (StyleSheet.flatten(wrapper.props.style) as { opacity?: number }).opacity;
}

function renderStage(props: Partial<Parameters<typeof SchoolStage>[0]> = {}) {
  const onReady = jest.fn();
  const view = render(<SchoolStage media={media()} command="sit" segment={null} mode="video" onReady={onReady} {...props} />);
  const rerender = (next: Partial<Parameters<typeof SchoolStage>[0]>) =>
    view.rerender(<SchoolStage media={media()} command="sit" segment={null} mode="video" onReady={onReady} {...props} {...next} />);
  return { ...view, onReady, rerender };
}

function renderAllFrames() {
  act(() => {
    for (const role of ['attention', 'performing', 'success', 'release']) {
      fireEvent(screen.getByTestId(`school-stage-${role}`), 'firstFrameRender');
    }
  });
}

describe('SchoolStage', () => {
  beforeEach(() => {
    resetMockVideoPlayers();
    jest.useFakeTimers();
  });
  afterEach(() => {
    jest.useRealTimers();
  });

  it('mounts every clip of the command once, paused at frame 0, hidden — ready only when all rendered', () => {
    const { onReady } = renderStage();
    expect(mockVideoPlayers.map(sourceUri)).toEqual(DERIVED.map(url));
    for (const p of mockVideoPlayers) {
      expect(p).toMatchObject({ playing: false, currentTime: 0, muted: true });
    }
    expect(playerFor('school_sit_attention_loop').loop).toBe(true);
    expect(playerFor('school_sit_success_loop').loop).toBe(true);
    expect(playerFor('school_sit_performing').loop).toBe(false);
    expect(playerFor('school_sit_release').loop).toBe(false);
    for (const role of ['attention', 'performing', 'success', 'release']) expect(opacityOf(`school-stage-${role}`)).toBe(0);
    // The still under the (not yet started) stage = the attention clip's start frame (standing).
    expect(screen.getByTestId('school-stage-still').props.source).toEqual({ uri: url('ref') });

    act(() => fireEvent(screen.getByTestId('school-stage-attention'), 'firstFrameRender'));
    act(() => fireEvent(screen.getByTestId('school-stage-performing'), 'firstFrameRender'));
    act(() => fireEvent(screen.getByTestId('school-stage-success'), 'firstFrameRender'));
    expect(onReady).not.toHaveBeenCalled();
    // The last one is only readyToPlay (a hidden view may never report its first frame).
    act(() => playerFor('school_sit_release').emit('statusChange', { status: 'readyToPlay' }));
    act(() => jest.advanceTimersByTime(STAGE_READY_FALLBACK_MS - 1));
    expect(onReady).not.toHaveBeenCalled();
    act(() => jest.advanceTimersByTime(1));
    expect(onReady).toHaveBeenCalledTimes(1);
    renderAllFrames();
    expect(onReady).toHaveBeenCalledTimes(1);
  });

  it('a failed clip still lets the session start (its role shows the picture)', () => {
    const { onReady, rerender } = renderStage();
    act(() => {
      for (const role of ['attention', 'success', 'release']) fireEvent(screen.getByTestId(`school-stage-${role}`), 'firstFrameRender');
    });
    act(() => playerFor('school_sit_performing').emit('statusChange', { status: 'error' }));
    expect(onReady).toHaveBeenCalledTimes(1);
    rerender({ segment: seg(3_000) }); // performing
    expect(screen.getByTestId('school-stage-still').props.source).toEqual({ uri: url('pose-sit') });
    for (const role of ['attention', 'performing', 'success', 'release']) expect(opacityOf(`school-stage-${role}`)).toBe(0);
  });

  it('phase change = instant switch: currentTime 0 + play, previous paused, no new player', () => {
    const { rerender } = renderStage();
    renderAllFrames();
    const created = mockVideoPlayers.length;
    const attention = playerFor('school_sit_attention_loop');
    const performing = playerFor('school_sit_performing');
    const success = playerFor('school_sit_success_loop');
    const release = playerFor('school_sit_release');

    rerender({ segment: seg(0) });
    expect(attention.play).toHaveBeenCalledTimes(1);
    expect(opacityOf('school-stage-attention')).toBe(1);

    attention.currentTime = 1.7; // it has been looping
    rerender({ segment: seg(2_000) }); // the cue: same attention instance → no restart
    expect(attention.currentTime).toBe(1.7);

    performing.currentTime = 0.4; // (would be stale from a previous trial)
    rerender({ segment: seg(2_500) });
    expect(performing.currentTime).toBe(0);
    expect(performing.playing).toBe(true);
    expect(attention.playing).toBe(false);
    expect(opacityOf('school-stage-performing')).toBe(1);
    expect(opacityOf('school-stage-attention')).toBe(0);
    // Under the performing clip: its START frame (standing), never the end pose.
    expect(screen.getByTestId('school-stage-still').props.source).toEqual({ uri: url('ref') });

    rerender({ segment: seg(5_500) });
    expect(success.playing).toBe(true);
    expect(performing.playing).toBe(false);
    expect(screen.getByTestId('school-stage-still').props.source).toEqual({ uri: url('pose-sit') });

    rerender({ segment: seg(7_000) });
    expect(release).toMatchObject({ playing: true, currentTime: 0 });
    expect(success.playing).toBe(false);

    attention.currentTime = 2.2;
    rerender({ segment: seg(9_500) });
    expect(attention).toMatchObject({ playing: true, currentTime: 0 });
    expect(release.playing).toBe(false);

    // Through the whole session: the same 4 players, nothing mounted or released mid-session.
    for (let t = 9_500; t <= session.duration_ms; t += 100) rerender({ segment: seg(t) });
    expect(mockVideoPlayers.length).toBe(created);
    expect(liveVideoPlayers()).toHaveLength(4);
    // No animated opacity (instant switch): plain numbers only.
    expect([0, 1]).toContain(opacityOf('school-stage-attention'));
  });

  it('old pets (no derived clips): attention loops, success holds its last frame, no release player', () => {
    renderStage({ media: media(['school_sit_attention', 'school_sit_performing', 'school_sit_success']) });
    expect(mockVideoPlayers).toHaveLength(3);
    expect(playerFor('school_sit_attention').loop).toBe(true);
    expect(playerFor('school_sit_success').loop).toBe(false);
  });

  it('picture mode mounts no player and is ready at once; switching to picture releases the players', () => {
    const picture = renderStage({ mode: 'picture', segment: seg(3_000) });
    expect(mockVideoPlayers).toHaveLength(0);
    expect(picture.onReady).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('school-stage-still').props.source).toEqual({ uri: url('pose-sit') });
    picture.unmount();

    const { rerender } = renderStage();
    expect(liveVideoPlayers()).toHaveLength(4);
    rerender({ mode: 'picture', segment: seg(0) }); // the 12 s timeout
    expect(liveVideoPlayers()).toHaveLength(0);
  });

  it('releases every player when the stage closes', () => {
    const { unmount, rerender } = renderStage();
    renderAllFrames();
    rerender({ segment: seg(3_000) });
    expect(playerFor('school_sit_performing').playing).toBe(true);
    unmount();
    expect(liveVideoPlayers()).toHaveLength(0);
  });
});
