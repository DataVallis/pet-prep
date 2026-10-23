/**
 * HUD settings sheet: the child can change the app language from the HUD (David 2026-10-12)
 * and »Prikaz: Video / Slika« (M5-T11, APP_REFINEMENT_SPEC §1) — gear button → sheet.
 */
import { act, fireEvent, screen, waitFor, within } from '@testing-library/react-native';

import { api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { useAppStore } from '@/store/appStore';
import { currentLanguage, i18n } from '@/i18n';
import { setCareGuideStoreForTests } from '@/modules/careGuide/careGuideSeen';
import { makeCareGuide, makeLiveChildState, makeMedia, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { liveVideoPlayers } from '@/test-utils/videoPlayers';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      getChildPet: jest.fn(),
      getChildPetGrowth: jest.fn(),
      syncSteps: jest.fn(),
      logout: jest.fn(),
      updateChildDisplay: jest.fn(),
    },
  };
});
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(20_000);

beforeEach(() => {
  jest.clearAllMocks();
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({
    token: 'child-token',
    user: { id: 2, name: 'Maja', email: null, role: 'child' },
    pet: makePet({ id: 7 }),
    awaitingContract: false,
  });
  useAppStore.getState().setWsStatus('connected');
});

afterEach(async () => {
  setCareGuideStoreForTests(null);
  await i18n.changeLanguage('sl');
});

it('David 2026-10-23: the top bar keeps only name, LIVE and the gear; album, »?« and the exit are settings rows', async () => {
  // Care guide already seen for pet 7 → it doesn't open by itself.
  setCareGuideStoreForTests({ getItemAsync: () => Promise.resolve('[7]'), setItemAsync: () => Promise.resolve() });
  const base = makeLiveChildState({ care_guide: makeCareGuide() });
  const media = makeMedia({ reference_image_url: 'https://cdn.test/ref.png' });
  (api.getChildPet as jest.Mock).mockResolvedValue({ ...base, pet: { ...base.pet, media } });
  renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('action-feed');

  const header = screen.getByTestId('hud-header');
  for (const id of ['hud-album-open', 'hud-care-guide-open', 'hud-logout']) {
    expect(within(header).queryByTestId(id)).toBeNull();
  }
  expect(within(header).getByTestId('hud-settings-open')).toBeTruthy();

  fireEvent.press(screen.getByTestId('hud-settings-open'));
  const sheet = screen.getByTestId('hud-settings-sheet');
  const order = within(sheet)
    .getAllByRole('button')
    .map((b) => b.props.testID as string | undefined)
    .filter((id): id is string => id === 'hud-album-open' || id === 'hud-care-guide-open' || id === 'hud-logout');
  expect(order).toEqual(['hud-album-open', 'hud-care-guide-open', 'hud-logout']);
  expect(screen.getByTestId('hud-album-open').props.accessibilityLabel).toBe('Odpri album');
  expect(screen.getByTestId('hud-care-guide-open').props.accessibilityLabel).toBe('Kako skrbim');
  expect(screen.getByTestId('hud-logout').props.accessibilityLabel).toBe('Odjava');

  // The album is an in-screen layer: the sheet closes and the album opens at once.
  fireEvent.press(screen.getByTestId('hud-album-open'));
  expect(screen.queryByTestId('hud-settings-sheet')).toBeNull();
  expect(useAppStore.getState().isAlbumVisible).toBe(true);
});

it('the gear button opens the settings sheet; picking English switches the HUD language', async () => {
  (api.getChildPet as jest.Mock).mockResolvedValue(makeLiveChildState());
  renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('action-feed');

  expect(screen.queryByTestId('hud-settings-sheet')).toBeNull();
  const open = screen.getByTestId('hud-settings-open');
  expect(open.props.accessibilityLabel).toBe('Nastavitve');
  fireEvent.press(open);
  expect(screen.getByTestId('hud-settings-sheet')).toBeTruthy();

  fireEvent.press(screen.getByTestId('child-language-en'));
  await screen.findByLabelText('Settings');
  expect(currentLanguage()).toBe('en');

  fireEvent.press(screen.getByTestId('hud-settings-sheet-close'));
  expect(screen.queryByTestId('hud-settings-sheet')).toBeNull();
});

/** A paid pet with M5-T08 state media: idle loop + key images. */
function stateMediaState(display: 'video' | 'picture') {
  const base = makeLiveChildState();
  const media = makeMedia({
    status: 'ready',
    state_media: true,
    reference_image_url: 'https://api.test/api/media/1?v=1',
    videos: { idle: 'https://api.test/api/media/2?v=1' },
    keyframes: { sleeping: 'https://api.test/api/media/3?v=1' },
  });
  return { ...base, pet: { ...base.pet, media }, settings: { media_display: display } };
}

it('Prikaz: Slika — the HUD shows the key image, no video player; saved on the server', async () => {
  (api.getChildPet as jest.Mock).mockResolvedValue(stateMediaState('video'));
  (api.updateChildDisplay as jest.Mock).mockResolvedValue({ media_display: 'picture' });
  renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('action-feed');
  expect(liveVideoPlayers().length).toBeGreaterThan(0);

  fireEvent.press(screen.getByTestId('hud-settings-open'));
  expect(screen.getByTestId('child-media-display-video').props.accessibilityState.selected).toBe(true);
  expect(screen.getByTestId('child-media-display-hint').props.children).toBe('Video pokaže, kako se tvoj ljubljenček premika.');

  (api.getChildPet as jest.Mock).mockResolvedValue(stateMediaState('picture'));
  await act(async () => {
    fireEvent.press(screen.getByTestId('child-media-display-picture'));
  });

  expect(api.updateChildDisplay).toHaveBeenCalledWith({ media_display: 'picture' });
  await waitFor(() => expect(screen.getByTestId('child-media-display-picture').props.accessibilityState.selected).toBe(true));
  expect(screen.getByTestId('child-media-display-hint').props.children).toContain('manj mobilnih podatkov');
  expect(screen.getByTestId('hud-pet-media-image').props.source).toEqual({ uri: 'https://api.test/api/media/1?v=1' });
  expect(screen.queryByTestId('hud-pet-media-video-loading')).toBeNull();
  expect(screen.queryByTestId('hud-pet-media-video-visible')).toBeNull();
  await waitFor(() => expect(liveVideoPlayers()).toHaveLength(0));
});

it('a failed save puts the previous value back and says so', async () => {
  (api.getChildPet as jest.Mock).mockResolvedValue(stateMediaState('video'));
  (api.updateChildDisplay as jest.Mock).mockRejectedValue(new Error('offline'));
  renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('action-feed');

  fireEvent.press(screen.getByTestId('hud-settings-open'));
  await act(async () => {
    fireEvent.press(screen.getByTestId('child-media-display-picture'));
  });

  await screen.findByTestId('child-media-display-error');
  expect(screen.getByTestId('child-media-display-video').props.accessibilityState.selected).toBe(true);
});
