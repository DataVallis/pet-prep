/**
 * "Album rasti" inside the "Moj kuža" album (M5-R04 part 2): the "Kako je kuža rasel"
 * strip (≥ 2 pictures), captions, "Zdaj", the full-screen viewer with navigation between
 * growth pictures only, and the expired-URL refetch.
 */
import { act, fireEvent, render, screen, within } from '@testing-library/react-native';

import PetAlbum from '@/components/PetAlbum';
import { ALBUM_REFRESH_LEAD_MS } from '@/modules/petMedia/album';
import { GROWTH_STRINGS, readGrowth, type GrowthAlbum } from '@/modules/petMedia/growth';
import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import { makeGrowth, makeGrowthEntry, makeMedia, makeTwoStageGrowth } from '@/test-utils/fixtures';
import { resetMockVideoPlayers } from '@/test-utils/videoPlayers';

const IMG = 'https://api.petprep.si/api/media/1?expires=1&v=img&signature=a';
const MEDIA = normalizePetMedia(makeMedia({ status: 'ready', reference_image_url: IMG, videos: {}, states: [] }));
const TZ = 'Europe/Ljubljana';

function renderAlbum(growth: GrowthAlbum | null) {
  const onGrowthExpired = jest.fn();
  const onMediaExpired = jest.fn();
  const utils = render(
    <PetAlbum media={MEDIA} onClose={jest.fn()} onMediaExpired={onMediaExpired} growth={growth} timeZone={TZ} onGrowthExpired={onGrowthExpired} />,
  );
  return { ...utils, onGrowthExpired, onMediaExpired };
}

beforeEach(() => resetMockVideoPlayers());

describe('PetAlbum — growth section', () => {
  it('hidden with 0 or 1 picture (nothing to compare) and without data', () => {
    const { unmount } = renderAlbum(readGrowth(makeGrowth([])));
    expect(screen.queryByTestId('album-growth')).toBeNull();
    unmount();
    const one = renderAlbum(readGrowth(makeGrowth([makeGrowthEntry({ is_current: true })])));
    expect(screen.queryByTestId('album-growth')).toBeNull();
    expect(screen.queryByText(GROWTH_STRINGS.section)).toBeNull();
    one.unmount();
    renderAlbum(null);
    expect(screen.queryByTestId('album-growth')).toBeNull();
    // The rest of the album is unaffected.
    expect(screen.getByTestId('album-item-photo')).toBeTruthy();
  });

  it('shown with ≥ 2 pictures: stage, age, family-local date, current marked "Zdaj"', () => {
    renderAlbum(readGrowth(makeTwoStageGrowth()));
    expect(screen.getByText(GROWTH_STRINGS.section)).toBeTruthy();
    const first = within(screen.getByTestId('album-growth-1'));
    expect(first.getByText('Mladiček')).toBeTruthy();
    expect(first.getByText('2 meseca')).toBeTruthy();
    expect(first.getByText('4. 10. 2026')).toBeTruthy();
    expect(screen.queryByTestId('album-growth-1-current')).toBeNull();

    const second = within(screen.getByTestId('album-growth-2'));
    expect(second.getByText('Mlad pes')).toBeTruthy();
    expect(second.getByText('9 mesecev')).toBeTruthy();
    expect(second.getByText('24. 11. 2026')).toBeTruthy();
    expect(screen.getByTestId('album-growth-2-current')).toBeTruthy();
    expect(screen.getByText(GROWTH_STRINGS.current)).toBeTruthy();
    expect(screen.getByTestId('album-growth-2').props.accessibilityLabel).toBe('Mlad pes, 9 mesecev, 24. 11. 2026, Zdaj');
  });

  it('a legacy picture (no stage, no age) shows no stage label', () => {
    renderAlbum(
      readGrowth(
        makeGrowth([
          makeGrowthEntry({ generation: 1, life_stage: null, age_months: null }),
          makeGrowthEntry({ generation: 2, life_stage: 'young', age_months: 9, is_current: true }),
        ]),
      ),
    );
    expect(screen.queryByTestId('album-growth-1-stage')).toBeNull();
    expect(screen.queryByTestId('album-growth-1-age')).toBeNull();
    expect(screen.getByTestId('album-growth-1-date')).toBeTruthy();
  });

  it('tap opens the full-screen viewer; arrows move between growth pictures only (wrapping)', () => {
    renderAlbum(readGrowth(makeTwoStageGrowth()));
    fireEvent.press(screen.getByTestId('album-growth-1'));

    expect(screen.getByTestId('album-viewer')).toBeTruthy();
    expect(screen.getByTestId('album-photo').props.source).toEqual({ uri: makeGrowthEntry({ generation: 1 }).image_url });
    expect(screen.getByText('Mladiček · 2 meseca')).toBeTruthy();
    expect(screen.getByTestId('album-position').props.children).toBe('1 / 2');
    expect(screen.getByTestId('album-detail').props.children).toBe('4. 10. 2026');

    fireEvent.press(screen.getByTestId('album-next'));
    expect(screen.getByText('Mlad pes · 9 mesecev')).toBeTruthy();
    expect(screen.getByTestId('album-detail').props.children).toBe('24. 11. 2026 · Zdaj');
    expect(screen.getByTestId('album-position').props.children).toBe('2 / 2');

    fireEvent.press(screen.getByTestId('album-next'));
    expect(screen.getByTestId('album-position').props.children).toBe('1 / 2');

    fireEvent.press(screen.getByTestId('album-back'));
    expect(screen.getByTestId('album-growth')).toBeTruthy();
  });

  it('a media tile still opens the album list (no growth detail line)', () => {
    renderAlbum(readGrowth(makeTwoStageGrowth()));
    fireEvent.press(screen.getByTestId('album-item-photo'));
    expect(screen.getByTestId('album-position').props.children).toBe('1 / 1');
    expect(screen.queryByTestId('album-detail')).toBeNull();
  });

  it('an expired growth URL asks for a fresh growth album (not the media)', () => {
    const { onGrowthExpired, onMediaExpired } = renderAlbum(readGrowth(makeTwoStageGrowth(null)));
    fireEvent(screen.getByTestId('album-growth-1-image'), 'error');
    expect(onGrowthExpired).toHaveBeenCalledTimes(1);
    expect(onMediaExpired).not.toHaveBeenCalled();
  });

  it('refetches the growth album 1 min before its URLs expire while open', () => {
    jest.useFakeTimers();
    try {
      const expiresAt = new Date(Date.now() + 10 * 60_000).toISOString();
      const { onGrowthExpired } = renderAlbum(readGrowth(makeTwoStageGrowth(expiresAt)));
      act(() => {
        jest.advanceTimersByTime(10 * 60_000 - ALBUM_REFRESH_LEAD_MS - 1_000);
      });
      expect(onGrowthExpired).not.toHaveBeenCalled();
      act(() => {
        jest.advanceTimersByTime(2_000);
      });
      expect(onGrowthExpired).toHaveBeenCalledTimes(1);
    } finally {
      jest.useRealTimers();
    }
  });
});
