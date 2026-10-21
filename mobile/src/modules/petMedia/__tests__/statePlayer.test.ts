/**
 * M5-T08 player state machine (APP_REFINEMENT_SPEC §0 rule 1 + §7): which item shows for a
 * state, the key-image fallback (never a clip of another state), the transition played once
 * with its target loop preloaded, picture mode, and the transition reducer.
 */
import { EMPTY_PET_MEDIA, normalizePetMedia, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import {
  NO_TRANSITION,
  canStartTransition,
  nextPlayback,
  onTransitionEnd,
  onWantedChange,
  poseImageUrl,
  settleTransition,
  steadyItem,
  transitionKey,
  type PlaybackInput,
} from '@/modules/petMedia/statePlayer';
import { makeMedia } from '@/test-utils/fixtures';

const u = (id: number, v: string) => `https://api.petprep.si/api/media/${id}?expires=1&v=${v}&signature=x`;
const IMAGE = u(1, 'img');
const IDLE = u(2, 'idle');
const SLEEPING = u(3, 'sleep');
const KEY_SLEEPING = u(4, 'ksleep');
const KEY_HUNGRY = u(5, 'khungry');
const KEY_SICK = u(6, 'ksick');
const IDLE_TO_SLEEPING = u(7, 'its');
const SLEEPING_TO_IDLE = u(8, 'sti');

function stateMedia(overrides: Partial<PetMediaInfo> = {}): PetMediaInfo {
  return {
    ...EMPTY_PET_MEDIA,
    status: 'partial',
    stateMedia: true,
    referenceImageUrl: IMAGE,
    videos: { idle: IDLE, sleeping: SLEEPING },
    keyframes: { sleeping: KEY_SLEEPING, hungry: KEY_HUNGRY, sick: KEY_SICK },
    transitions: { idle_to_sleeping: IDLE_TO_SLEEPING, sleeping_to_idle: SLEEPING_TO_IDLE },
    loops: ['idle', 'sleeping'],
    states: ['idle', 'sleeping', 'hungry', 'sick'],
    ...overrides,
  };
}

const input = (media: PetMediaInfo, wanted: PlaybackInput['wanted'], extra: Partial<PlaybackInput> = {}): PlaybackInput => ({
  media,
  wanted,
  petState: wanted === null || wanted === 'accident' || wanted === 'chewing' || wanted === 'scratching' ? 'idle' : wanted,
  ...extra,
});

describe('normalizePetMedia (M5-T08 fields)', () => {
  it('reads key images, transitions, loops and the state_media flag; drops idle / unknown keys', () => {
    const m = normalizePetMedia(
      makeMedia({
        keyframes: { sleeping: KEY_SLEEPING, idle: 'x', flying: 'y' },
        transitions: { idle_to_sleeping: IDLE_TO_SLEEPING, idle_to_flying: 'z', nope: 'w' },
        loops: ['idle', 'flying' as never],
        state_media: true,
      }),
    );
    expect(m.keyframes).toEqual({ sleeping: KEY_SLEEPING });
    expect(m.transitions).toEqual({ idle_to_sleeping: IDLE_TO_SLEEPING });
    expect(m.loops).toEqual(['idle']);
    expect(m.stateMedia).toBe(true);
  });

  it('an older server (no fields) → empty + the older rules', () => {
    const m = normalizePetMedia({ status: 'ready', reference_image_url: IMAGE, videos: { idle: IDLE }, states: ['idle'] });
    expect(m).toMatchObject({ keyframes: {}, transitions: {}, loops: [], stateMedia: false });
  });
});

describe('steadyItem — state → media, never the other way round (§0 rule 1)', () => {
  it('plays the state loop when it is stored', () => {
    expect(steadyItem(input(stateMedia(), 'sleeping'))).toMatchObject({ kind: 'clip', url: SLEEPING, state: 'sleeping', loop: true, transition: null });
  });

  it('a missing clip shows the state key image — not the idle or sleeping clip', () => {
    expect(steadyItem(input(stateMedia(), 'hungry'))).toMatchObject({ kind: 'image', url: KEY_HUNGRY, state: 'hungry' });
    // The vet visit: sick key image, never the sleeping substitute of the older set.
    expect(steadyItem(input(stateMedia(), 'sick'))).toMatchObject({ kind: 'image', url: KEY_SICK, state: 'sick' });
  });

  it('no clip and no key image yet → the still reference image (never another state\'s clip)', () => {
    const item = steadyItem(input(stateMedia(), 'low_energy'));
    expect(item).toMatchObject({ kind: 'image', url: IMAGE });
  });

  it('a clip the player cannot play now (failed) falls back to the key image', () => {
    const item = steadyItem(input(stateMedia(), 'sleeping', { canPlayVideo: (url) => url !== SLEEPING }));
    expect(item).toMatchObject({ kind: 'image', url: KEY_SLEEPING });
  });

  it('a behaviour scene without its clip shows the pet state the server reports', () => {
    const item = steadyItem({ media: stateMedia(), wanted: 'accident', petState: 'sleeping' });
    expect(item).toMatchObject({ kind: 'clip', url: SLEEPING, state: 'sleeping' });
  });

  it('locked (wanted null) → the reference image', () => {
    expect(steadyItem(input(stateMedia(), null))).toMatchObject({ kind: 'image', url: IMAGE });
  });

  it('older set (state_media false) keeps the substitute chain sick → sleeping', () => {
    const older = stateMedia({ stateMedia: false, keyframes: {} });
    expect(steadyItem(input(older, 'sick'))).toMatchObject({ kind: 'clip', url: SLEEPING, state: 'sleeping' });
  });

  it('picture mode (M5-T11) shows the key image of the state, idle = reference image', () => {
    expect(steadyItem(input(stateMedia(), 'sleeping', { mode: 'picture' }))).toMatchObject({ kind: 'image', url: KEY_SLEEPING });
    expect(steadyItem(input(stateMedia(), 'idle', { mode: 'picture' }))).toMatchObject({ kind: 'image', url: IMAGE });
    expect(poseImageUrl(stateMedia(), 'playing')).toBe(IMAGE);
  });

  it('nothing at all → placeholder', () => {
    expect(steadyItem(input({ ...EMPTY_PET_MEDIA, stateMedia: true }, 'idle'))).toEqual({ kind: 'placeholder' });
  });
});

describe('nextPlayback — transition once, then the loop', () => {
  it('idle → sleeping plays idle_to_sleeping once with the sleeping loop preloaded', () => {
    const p = nextPlayback(input(stateMedia(), 'sleeping'), 'idle');
    expect(p.current).toMatchObject({ kind: 'clip', url: IDLE_TO_SLEEPING, loop: false, transition: 'idle_to_sleeping', state: 'sleeping' });
    expect(p.preload).toMatchObject({ kind: 'clip', url: SLEEPING, loop: true });
  });

  it('after the transition ended → only the loop', () => {
    const p = nextPlayback(input(stateMedia(), 'sleeping'), 'idle', true);
    expect(p).toEqual({ current: expect.objectContaining({ url: SLEEPING, loop: true }), preload: null });
  });

  it('no transition on the first render, without a stored clip, or into a still', () => {
    expect(nextPlayback(input(stateMedia(), 'sleeping'), null).current).toMatchObject({ url: SLEEPING });
    expect(nextPlayback(input(stateMedia({ transitions: {} }), 'sleeping'), 'idle').current).toMatchObject({ url: SLEEPING, loop: true });
    // sleeping → idle with the idle loop missing: no transition into a picture.
    const noIdle = stateMedia({ videos: { sleeping: SLEEPING } });
    expect(nextPlayback(input(noIdle, 'idle'), 'sleeping').current).toMatchObject({ kind: 'image', url: IMAGE });
  });

  it('picture mode never plays a transition', () => {
    expect(nextPlayback(input(stateMedia(), 'sleeping', { mode: 'picture' }), 'idle').current).toMatchObject({ kind: 'image', url: KEY_SLEEPING });
  });

  it('transitionKey names the clip', () => {
    expect(transitionKey('sleeping', 'idle')).toBe('sleeping_to_idle');
  });
});

describe('transition reducer', () => {
  const has = (from: string, to: string) => `${from}_to_${to}` in stateMedia().transitions;

  it('first state settles without a transition; a change with a clip starts it; its end settles on the target', () => {
    let s = onWantedChange(NO_TRANSITION, 'idle', has);
    expect(s).toEqual({ shown: 'idle', pending: null });
    expect(onWantedChange(s, 'idle', has)).toBe(s); // unchanged → same object

    s = onWantedChange(s, 'sleeping', has);
    expect(s).toEqual({ shown: 'idle', pending: { from: 'idle', to: 'sleeping' } });
    expect(onWantedChange(s, 'sleeping', has)).toBe(s);

    s = onTransitionEnd(s);
    expect(s).toEqual({ shown: 'sleeping', pending: null });
    expect(onTransitionEnd(s)).toBe(s);
  });

  it('a change without a clip switches straight away; a change mid-transition settles without another one', () => {
    let s = onWantedChange({ shown: 'idle', pending: null }, 'hungry', has);
    expect(s).toEqual({ shown: 'hungry', pending: null });

    s = onWantedChange({ shown: 'idle', pending: { from: 'idle', to: 'sleeping' } }, 'hungry', has);
    expect(s).toEqual({ shown: 'hungry', pending: null });
  });

  it('a lock (wanted null) resets', () => {
    expect(onWantedChange({ shown: 'idle', pending: { from: 'idle', to: 'sleeping' } }, null, has)).toBe(NO_TRANSITION);
  });
});

describe('M5-T08b — a late transition never plays minutes later (QA minor 5)', () => {
  // The sleeping loop is not stored yet: the sleeping key image shows.
  const noSleepLoop = stateMedia({ videos: { idle: IDLE } });
  const can = (media: PetMediaInfo) => (from: Parameters<typeof canStartTransition>[1], to: Parameters<typeof canStartTransition>[1]) =>
    canStartTransition(input(media, to), from);

  it('only a transition into the wanted state\'s own loop can start', () => {
    expect(canStartTransition(input(stateMedia(), 'sleeping'), 'idle')).toBe(true);
    expect(canStartTransition(input(noSleepLoop, 'sleeping'), 'idle')).toBe(false);
    expect(canStartTransition(input(stateMedia({ transitions: {} }), 'sleeping'), 'idle')).toBe(false);
    expect(canStartTransition(input(stateMedia(), 'sleeping', { canPlayVideo: (url) => url !== IDLE_TO_SLEEPING }), 'idle')).toBe(false);
  });

  it('idle → sleeping while the sleeping loop is missing settles at once; the loop arriving later plays no transition', () => {
    let s = onWantedChange({ shown: 'idle', pending: null }, 'sleeping', can(noSleepLoop));
    expect(s).toEqual({ shown: 'sleeping', pending: null });
    expect(steadyItem(input(noSleepLoop, 'sleeping'))).toMatchObject({ kind: 'image', url: KEY_SLEEPING });

    // Minutes later the sleeping loop is stored: wanted is unchanged → still no transition.
    s = onWantedChange(s, 'sleeping', can(stateMedia()));
    expect(s).toEqual({ shown: 'sleeping', pending: null });
    expect(s.pending).toBeNull();
  });

  it('a pending transition the player no longer picks (its clip failed) is settled on the target', () => {
    const pending = { shown: 'idle' as const, pending: { from: 'idle' as const, to: 'sleeping' as const } };
    const failed = input(stateMedia(), 'sleeping', { canPlayVideo: (url) => url !== IDLE_TO_SLEEPING });
    expect(settleTransition(pending, nextPlayback(failed, 'idle'))).toEqual({ shown: 'sleeping', pending: null });

    const playing = nextPlayback(input(stateMedia(), 'sleeping'), 'idle');
    expect(settleTransition(pending, playing)).toBe(pending);
    expect(settleTransition(NO_TRANSITION, playing)).toBe(NO_TRANSITION);
  });
});
