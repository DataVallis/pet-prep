/**
 * David 2026-10-12: the child can change the app language from the HUD (globe button →
 * sheet with the language switch), not only on the start screen before signing in.
 */
import { fireEvent, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { useAppStore } from '@/store/appStore';
import { currentLanguage, i18n } from '@/i18n';
import { makeLiveChildState, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, getChildPet: jest.fn(), getChildPetGrowth: jest.fn(), syncSteps: jest.fn(), logout: jest.fn() },
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
  await i18n.changeLanguage('sl');
});

it('the globe button opens the language sheet; picking English switches the HUD language', async () => {
  (api.getChildPet as jest.Mock).mockResolvedValue(makeLiveChildState());
  renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('action-feed');

  expect(screen.queryByTestId('hud-language-sheet')).toBeNull();
  const open = screen.getByTestId('hud-language-open');
  expect(open.props.accessibilityLabel).toBe('Spremeni jezik');
  fireEvent.press(open);
  expect(screen.getByTestId('hud-language-sheet')).toBeTruthy();

  fireEvent.press(screen.getByTestId('child-language-en'));
  await screen.findByLabelText('Change language');
  expect(currentLanguage()).toBe('en');

  fireEvent.press(screen.getByTestId('hud-language-sheet-close'));
  expect(screen.queryByTestId('hud-language-sheet')).toBeNull();
});
