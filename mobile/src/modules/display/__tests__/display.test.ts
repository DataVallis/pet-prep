import { mediaModeOf, readMediaDisplay } from '@/modules/display/display';
import { withMediaDisplay } from '@/hooks/queries/useChildSettings';
import { normalizeChildState } from '@/modules/childPet/childPetView';
import { makeLiveChildState } from '@/test-utils/fixtures';

describe('M5-T11 media display', () => {
  it('reads only picture as picture; anything else (older server) is video', () => {
    expect(readMediaDisplay('picture')).toBe('picture');
    expect(readMediaDisplay('video')).toBe('video');
    expect(readMediaDisplay(undefined)).toBe('video');
    expect(readMediaDisplay('gif')).toBe('video');
  });

  it('the child state carries settings.media_display (missing → video)', () => {
    const raw = makeLiveChildState();
    expect(normalizeChildState(raw).settings.media_display).toBe('video');
    expect(normalizeChildState({ ...raw, settings: { media_display: 'picture' } } as never).settings.media_display).toBe('picture');
    const { settings: _drop, ...older } = raw as typeof raw & { settings?: unknown };
    expect(normalizeChildState(older as never).settings.media_display).toBe('video');
  });

  it('maps the view to the PetMediaView mode', () => {
    const view = normalizeChildState({ ...makeLiveChildState(), settings: { media_display: 'picture' } } as never);
    expect(mediaModeOf(view)).toBe('picture');
    expect(mediaModeOf(null)).toBe('video');
    expect(withMediaDisplay(view, 'picture')).toBe(view);
    expect(mediaModeOf(withMediaDisplay(view, 'video'))).toBe('video');
  });
});
