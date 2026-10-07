/**
 * M1-18: the child HUD in English — dock labels, hints with English times, number
 * grouping and a refusal toast; a live language switch re-renders the HUD.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import { i18n } from '@/i18n';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { useAppStore } from '@/store/appStore';
import { makeLiveChildState, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      getChildPet: jest.fn(),
      getChildPetGrowth: jest.fn(),
      feedPet: jest.fn(),
      waterPet: jest.fn(),
      cleanPet: jest.fn(),
      syncSteps: jest.fn(),
      logout: jest.fn(),
    },
  };
});
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(20_000);

const getChildPet = api.getChildPet as jest.Mock;
const feedPet = api.feedPet as jest.Mock;

describe('ChildHudScreen in English', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({
      token: 'child-token',
      user: { id: 2, name: 'Maja', email: null, role: 'child' },
      pet: makePet({ id: 7, born_at: '2026-10-01T08:00:00Z' }),
      awaitingContract: false,
    });
    useAppStore.getState().setWsStatus('connected');
  });

  afterEach(async () => {
    await act(async () => {
      await i18n.changeLanguage('sl');
    });
  });

  it('dock, hints, steps and badge are English', async () => {
    await act(async () => {
      await i18n.changeLanguage('en');
    });
    getChildPet.mockResolvedValue(makeLiveChildState());
    renderWithQuery(<ChildHudScreen />);
    await screen.findByTestId('action-feed');

    expect(screen.getByText('Feed')).toBeTruthy();
    expect(screen.getByText('Walk')).toBeTruthy();
    expect(screen.getByText('at 17:00')).toBeTruthy();
    expect(screen.getByText('1,250/4,000')).toBeTruthy();
    expect(screen.getByText('Mixed breed')).toBeTruthy();
    expect(screen.getByText('LIVE')).toBeTruthy();
    expect(screen.getByTestId('metric-hunger').props.accessibilityLabel).toBe('Food 60%');
  });

  it('a refused feed explains itself in simple English', async () => {
    await act(async () => {
      await i18n.changeLanguage('en');
    });
    feedPet.mockRejectedValueOnce(
      new ApiError('refused', 422, {
        status: 'refused',
        reason: 'outside_feed_window',
        next_allowed_at: '2026-10-04T17:00:00+02:00',
        state: makeLiveChildState(),
      }),
    );
    getChildPet.mockResolvedValue(makeLiveChildState({ feeding: { can_feed: true } }));
    renderWithQuery(<ChildHudScreen />);
    await screen.findByTestId('action-feed');
    fireEvent.press(screen.getByTestId('action-feed'));

    expect(await screen.findByText('The next meal is at 17:00. If your pup gets very hungry, you can give it an emergency meal.')).toBeTruthy();
  });

  it('switching the language re-renders the HUD', async () => {
    getChildPet.mockResolvedValue(makeLiveChildState());
    renderWithQuery(<ChildHudScreen />);
    await screen.findByTestId('action-feed');
    expect(screen.getByText('Hrani')).toBeTruthy();
    expect(screen.getByText('ob 17:00')).toBeTruthy();

    await act(async () => {
      await i18n.changeLanguage('en');
    });
    expect(screen.getByText('Feed')).toBeTruthy();
    expect(screen.getByText('at 17:00')).toBeTruthy();
    expect(screen.queryByText('Hrani')).toBeNull();
  });
});
