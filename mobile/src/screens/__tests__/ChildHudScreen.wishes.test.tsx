/** M6-W04 HUD: »Želje« settings row + »Priboljšek« chip (only with treats; 422 limit text; hidden on an older server). */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { useAppStore } from '@/store/appStore';
import { i18n } from '@/i18n';
import { makeLiveChildState, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, getChildPet: jest.fn(), getChildPetGrowth: jest.fn(), syncSteps: jest.fn(), logout: jest.fn(), giveTreat: jest.fn(), getChildWishes: jest.fn() },
  };
});
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(20_000);

const withWishes = (left: number) => ({ ...makeLiveChildState(), wishes: { open_count: 0, items: [], treats: { left, given_today: 0, daily_limit: 2 } } });

beforeEach(async () => {
  jest.clearAllMocks();
  await i18n.changeLanguage('sl');
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({ token: 'child-token', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: makePet({ id: 7 }), awaitingContract: false });
  useAppStore.getState().setWsStatus('connected');
  (api.getChildWishes as jest.Mock).mockReturnValue(new Promise(() => undefined));
});

it('older server: no »Želje« row and no treat chip', async () => {
  (api.getChildPet as jest.Mock).mockResolvedValue(makeLiveChildState());
  renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('action-feed');
  expect(screen.queryByTestId('hud-treat')).toBeNull();
  fireEvent.press(screen.getByTestId('hud-settings-open'));
  expect(screen.queryByTestId('hud-wishes-open')).toBeNull();
});

it('with treats: the chip shows; the daily limit gives the friendly text', async () => {
  (api.getChildPet as jest.Mock).mockResolvedValue(withWishes(5));
  (api.giveTreat as jest.Mock).mockRejectedValue(new ApiError('x', 422, { reason: 'treat_limit_today', next_at: '2026-10-05T00:00:00+02:00' }));
  renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('hud-treat');
  fireEvent.press(screen.getByTestId('hud-treat'));
  await waitFor(() => expect(api.giveTreat).toHaveBeenCalledTimes(1));
  expect(await screen.findByText('Danes je dobil že dva priboljška, jutri spet.')).toBeTruthy();
});

it('no treats left: no chip, but the »Želje« row is there', async () => {
  (api.getChildPet as jest.Mock).mockResolvedValue(withWishes(0));
  renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('action-feed');
  expect(screen.queryByTestId('hud-treat')).toBeNull();
  fireEvent.press(screen.getByTestId('hud-settings-open'));
  expect(screen.getByTestId('hud-wishes-open')).toBeTruthy();
});

it('QA #416 m11: the state of a treat answer replaces the child pet cache (counter updates at once)', async () => {
  (api.getChildPet as jest.Mock).mockResolvedValue(withWishes(5));
  const after = withWishes(4);
  (api.giveTreat as jest.Mock).mockResolvedValue({ treats_left: 4, given_today: 1, daily_limit: 2, media: null, state: { ...after, server_time: '2026-10-04T12:01:00+02:00' } });
  renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('hud-treat');
  // The next poll would still say 5 — the cache must come from the answer, not a refetch.
  (api.getChildPet as jest.Mock).mockReturnValue(new Promise(() => undefined));
  fireEvent.press(screen.getByTestId('hud-treat'));
  await waitFor(() => expect(screen.getByTestId('hud-treat')).toHaveTextContent(/4/));
  expect(api.getChildPet).toHaveBeenCalledTimes(1);
});
