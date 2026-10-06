/**
 * M5-R02 media: premium plays `media.videos[scene]`; without it (free tier) the existing
 * `pet_state` fallback chain plays and the HUD draws the graphic. Scene videos never
 * become album tiles; a lock always wins over a scene.
 */
import { buildAlbumItems } from '@/modules/petMedia/album';
import { normalizePetMedia, selectMediaSource, videoChain, videoStateFor } from '@/modules/petMedia/petMedia';

const url = (name: string) => `https://api.petprep.si/api/media/${name}?expires=1&v=${name}&signature=s`;

describe('behaviour scene media', () => {
  const premium = normalizePetMedia({
    status: 'ready',
    reference_image_url: url('ref'),
    videos: { idle: url('idle'), sleeping: url('sleep'), sick: url('sick'), accident: url('acc'), chewing: url('chew') },
    current_video_url: url('sick'),
    states: ['idle', 'sleeping', 'sick', 'accident', 'chewing'],
    expires_at: null,
  });
  const free = normalizePetMedia({
    status: 'ready',
    reference_image_url: url('ref'),
    videos: { idle: url('idle'), sleeping: url('sleep') },
    current_video_url: url('idle'),
    states: ['idle', 'sleeping'],
    expires_at: null,
  });

  it('keeps scene videos and states from the payload', () => {
    expect(premium.videos.accident).toBe(url('acc'));
    expect(premium.states).toContain('chewing');
  });

  it('videoStateFor: scene when not locked; locks win', () => {
    expect(videoStateFor('sick', null, 'accident')).toBe('accident');
    expect(videoStateFor('sick', 'ill', 'accident')).toBe('sick');
    expect(videoStateFor('sick', 'hard_stopped', 'chewing')).toBe('sleeping');
    expect(videoStateFor('idle', 'game_over', 'chewing')).toBeNull();
    expect(videoStateFor('idle', null, null)).toBe('idle');
  });

  it('scene chain = scene, then the pet_state chain', () => {
    expect(videoChain('accident', 'sick')).toEqual(['accident', 'sick', 'sleeping', 'idle']);
    expect(videoChain('chewing', 'sleeping')).toEqual(['chewing', 'sleeping', 'idle']);
  });

  it('premium plays the scene video', () => {
    expect(selectMediaSource(premium, 'chewing', { sceneFallback: 'sick' })).toMatchObject({
      kind: 'video',
      state: 'chewing',
      url: url('chew'),
    });
  });

  it('free tier falls back to the pet_state chain (no scene video)', () => {
    expect(selectMediaSource(free, 'accident', { sceneFallback: 'sick' })).toMatchObject({ kind: 'video', state: 'sleeping' });
  });

  it('a failed scene video falls back too', () => {
    const noScene = (u: string) => u !== url('acc');
    expect(selectMediaSource(premium, 'accident', { sceneFallback: 'sick', canPlayVideo: noScene })).toMatchObject({
      state: 'sick',
    });
  });

  it('scene videos are not album tiles', () => {
    const ids = buildAlbumItems(premium).map((i) => i.id);
    expect(ids).not.toContain('accident');
    expect(ids).not.toContain('chewing');
  });
});
