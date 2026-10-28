/**
 * School smooth playback (2026-10-28, SCHOOL_SMOOTH_PLAYBACK.md §C): the server's times →
 * which clip role, its loop flag and instance; ignored cues keep the attention loop; fallbacks
 * for derived clips the server hasn't made yet; an old-shape schedule stays on the old path.
 */
import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import {
  RELEASE_CLIP_MS,
  hasSmoothTimes,
  schoolReleaseMs,
  schoolSegmentAt,
  schoolStageClips,
  schoolStageStill,
  schoolTimeline,
} from '@/modules/petMedia/phasePlayer';
import { readTrainingSession, type TrainingSession } from '@/modules/training/training';
import { makeMedia, makeSmoothTrainingSessionPayload, makeTrainingSessionPayload } from '@/test-utils/fixtures';

const url = (name: string) => `https://api.petprep.si/api/media/${name}?v=${name}&expires=1&signature=s`;

const ALL = ['attention', 'performing', 'success', 'attention_loop', 'success_loop', 'release'].map((p) => `school_sit_${p}`);

function media(phases: readonly string[] = ALL, seconds: Record<string, number> = { school_sit_performing: 3, school_sit_release: 2.5 }) {
  return normalizePetMedia(
    makeMedia({
      status: 'ready',
      reference_image_url: url('ref'),
      state_media: true,
      poses: { sit: url('pose-sit') },
      phases: Object.fromEntries(phases.map((p) => [p, url(p)])),
      phase_seconds: seconds,
      phase_groups: ['school'],
    }),
  );
}

const smooth = readTrainingSession(makeSmoothTrainingSessionPayload()) as TrainingSession;
const old = readTrainingSession(makeTrainingSessionPayload()) as TrainingSession;

describe('reader + detection', () => {
  it('keeps the derived phase keys and their lengths', () => {
    const m = media();
    expect(Object.keys(m.phases).sort()).toEqual([...ALL].sort());
    expect(m.phaseMs.school_sit_release).toBe(2_500);
  });

  it('reads perform_at / release_at for obeying trials; an old schedule has none', () => {
    expect(smooth.trials[0]).toMatchObject({ perform_at_ms: 2_500, obey_at_ms: 5_500, release_at_ms: 7_000 });
    expect(smooth.trials[1].perform_at_ms).toBeUndefined();
    expect(hasSmoothTimes(smooth)).toBe(true);
    expect(hasSmoothTimes(old)).toBe(false);
    expect(old.trials[0].perform_at_ms).toBeUndefined();
  });

  it('drops an inconsistent pair (perform after obey / release before window end / only one field)', () => {
    const bad = (patch: Record<string, unknown>) => {
      const p = makeSmoothTrainingSessionPayload();
      (p.trials as Record<string, unknown>[])[0] = { ...(p.trials as Record<string, unknown>[])[0], ...patch };
      return readTrainingSession(p) as TrainingSession;
    };
    expect(bad({ perform_at_ms: 6_000 }).trials[0].perform_at_ms).toBeUndefined();
    expect(bad({ release_at_ms: 6_000 }).trials[0].release_at_ms).toBeUndefined();
    expect(bad({ release_at_ms: null }).trials[0].perform_at_ms).toBeUndefined();
    // One obeying trial without the times → the whole session uses the old path.
    expect(hasSmoothTimes(bad({ release_at_ms: null }))).toBe(false);
  });
});

describe('schoolTimeline / schoolSegmentAt', () => {
  const timeline = schoolTimeline(smooth, 2_500);
  const at = (ms: number) => schoolSegmentAt(timeline, ms);

  it('attention loop → performing from frame 0 at perform_at → success loop at obey → release → attention', () => {
    expect(at(0)).toEqual({ role: 'attention', fromMs: 0, instance: 'attention:start' });
    expect(at(2_000).role).toBe('attention'); // the cue: still the attention loop (no restart)
    expect(at(2_000).instance).toBe('attention:start');
    expect(at(2_499).role).toBe('attention');
    expect(at(2_500)).toMatchObject({ role: 'performing', instance: '0:performing' });
    expect(at(5_499).role).toBe('performing');
    expect(at(5_500)).toMatchObject({ role: 'success', instance: '0:success' });
    expect(at(6_999).role).toBe('success');
    expect(at(7_000)).toMatchObject({ role: 'release', instance: '0:release' });
    expect(at(9_499).role).toBe('release');
    expect(at(9_500)).toMatchObject({ role: 'attention', instance: '0:attention' });
  });

  it('an ignored cue keeps the same attention loop running (no new instance, never a still)', () => {
    // Trial 1 (cue 10 200) is ignored; trial 2 performs at 15 200.
    for (let t = 9_500; t < 15_200; t += 100) expect(at(t)).toMatchObject({ role: 'attention', instance: '0:attention' });
    expect(at(15_200)).toMatchObject({ role: 'performing', instance: '2:performing' });
  });

  it('every phase change is a new instance (the player restarts from frame 0)', () => {
    const instances = timeline.map((s) => s.instance);
    expect(new Set(instances).size).toBe(instances.length);
    expect(timeline.map((s) => s.fromMs)).toEqual([...timeline.map((s) => s.fromMs)].sort((a, b) => a - b));
  });

  it('no release clip → hard cut from the success loop to attention at release_at', () => {
    const cut = schoolTimeline(smooth, null);
    expect(cut.some((s) => s.role === 'release')).toBe(false);
    expect(schoolSegmentAt(cut, 6_999).role).toBe('success');
    expect(schoolSegmentAt(cut, 7_000)).toMatchObject({ role: 'attention', instance: '0:attention' });
  });

  it('a release longer than the gap is cut by the next performing clip', () => {
    const long = schoolTimeline(smooth, 9_000); // 7 000 + 9 000 > the next perform (15 200)
    expect(schoolSegmentAt(long, 15_100).role).toBe('release');
    expect(schoolSegmentAt(long, 15_200).role).toBe('performing');
  });
});

describe('schoolStageClips (fallbacks, max 4 players)', () => {
  it('all derived clips: attention_loop + success_loop loop, performing + release once', () => {
    expect(schoolStageClips(media(), 'sit')).toEqual([
      { role: 'attention', key: 'school_sit_attention_loop', url: url('school_sit_attention_loop'), loop: true },
      { role: 'performing', key: 'school_sit_performing', url: url('school_sit_performing'), loop: false },
      { role: 'success', key: 'school_sit_success_loop', url: url('school_sit_success_loop'), loop: true },
      { role: 'release', key: 'school_sit_release', url: url('school_sit_release'), loop: false },
    ]);
  });

  it('server not backfilled: attention loops, success holds its last frame, no release player', () => {
    const m = media(['school_sit_attention', 'school_sit_performing', 'school_sit_success']);
    expect(schoolStageClips(m, 'sit')).toEqual([
      { role: 'attention', key: 'school_sit_attention', url: url('school_sit_attention'), loop: true },
      { role: 'performing', key: 'school_sit_performing', url: url('school_sit_performing'), loop: false },
      { role: 'success', key: 'school_sit_success', url: url('school_sit_success'), loop: false },
    ]);
    expect(schoolReleaseMs(m, 'sit')).toBeNull();
  });

  it('release length from phase_seconds, else the server default', () => {
    expect(schoolReleaseMs(media(), 'sit')).toBe(2_500);
    expect(schoolReleaseMs(media(ALL, { school_sit_release: 2 }), 'sit')).toBe(2_000);
    expect(schoolReleaseMs(media(ALL, {}), 'sit')).toBe(RELEASE_CLIP_MS);
  });

  it('another command has no clips here', () => {
    expect(schoolStageClips(media(), 'come')).toEqual([]);
  });
});

describe('schoolStageStill', () => {
  const m = media();
  it('under a clip: its START frame (never the end pose)', () => {
    expect(schoolStageStill(m, 'sit', 'attention', true)).toBe(url('ref'));
    expect(schoolStageStill(m, 'sit', 'performing', true)).toBe(url('ref'));
    expect(schoolStageStill(m, 'sit', 'success', true)).toBe(url('pose-sit'));
    expect(schoolStageStill(m, 'sit', 'release', true)).toBe(url('pose-sit'));
  });
  it('picture / no clip: standing for attention + release, the pose for performing + success', () => {
    expect(schoolStageStill(m, 'sit', 'attention', false)).toBe(url('ref'));
    expect(schoolStageStill(m, 'sit', 'performing', false)).toBe(url('pose-sit'));
    expect(schoolStageStill(m, 'sit', 'success', false)).toBe(url('pose-sit'));
    expect(schoolStageStill(m, 'sit', 'release', false)).toBe(url('ref'));
  });
});
