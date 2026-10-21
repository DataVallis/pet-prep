/**
 * M5-T05 — the care guide on the child HUD: opens by itself the first time a pet is shown on
 * this device, not again once closed, always from the »?« button; no button for an older server.
 */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';

import { api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { setCareGuideStoreForTests } from '@/modules/careGuide/careGuideSeen';
import { useAppStore } from '@/store/appStore';
import { makeCareGuide, makeLiveChildState, makePet } from '@/test-utils/fixtures';
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

const getChildPet = api.getChildPet as jest.Mock;
let data: Map<string, string>;

beforeEach(() => {
  jest.clearAllMocks();
  data = new Map();
  setCareGuideStoreForTests({
    getItemAsync: (k: string) => Promise.resolve(data.get(k) ?? null),
    setItemAsync: (k: string, v: string) => {
      data.set(k, v);
      return Promise.resolve();
    },
  });
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({
    token: 'child-token',
    user: { id: 2, name: 'Maja', email: null, role: 'child' },
    pet: makePet({ id: 7 }),
    awaitingContract: false,
  });
  useAppStore.getState().setWsStatus('connected');
});

afterEach(() => setCareGuideStoreForTests(null));

async function renderHud(state: ReturnType<typeof makeLiveChildState>) {
  getChildPet.mockResolvedValue(state);
  const utils = renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('action-feed');
  return utils;
}

it('opens by itself on the first launch for this pet, then stays closed', async () => {
  const state = makeLiveChildState({ care_guide: makeCareGuide() });
  const first = await renderHud(state);
  expect(await screen.findByTestId('care-guide')).toBeTruthy();
  expect(screen.getByTestId('care-guide-title').props.children).toBe('Kako skrbim za kužka');
  expect(screen.getByTestId('care-guide-walk')).toBeTruthy();

  fireEvent.press(screen.getByTestId('care-guide-close'));
  await waitFor(() => expect(screen.queryByTestId('care-guide')).toBeNull());
  first.unmount();

  // Second launch: remembered for pet 7 — no automatic card; the »?« button opens it.
  await renderHud(state);
  await act(async () => {
    await Promise.resolve();
  });
  expect(screen.queryByTestId('care-guide')).toBeNull();
  fireEvent.press(screen.getByTestId('hud-care-guide-open'));
  expect(await screen.findByTestId('care-guide')).toBeTruthy();
});

it('a cat gets the kitty guide (no walk, wand play instead)', async () => {
  await renderHud(
    makeLiveChildState({
      pet: { breed_type: 'domestic_cat', species: 'cat' },
      care_guide: makeCareGuide({ species: 'cat', steps: null, play: { sessions_per_day: 2, min_gap_minutes: 120 } }),
    }),
  );
  expect(await screen.findByTestId('care-guide')).toBeTruthy();
  expect(screen.getByTestId('care-guide-title').props.children).toBe('Kako skrbim za muco');
  expect(screen.queryByTestId('care-guide-walk')).toBeNull();
  expect(screen.getByTestId('care-guide-play')).toBeTruthy();
});

it('an older server (no care_guide) shows no »?« button and no card', async () => {
  await renderHud(makeLiveChildState());
  expect(screen.queryByTestId('hud-care-guide-open')).toBeNull();
  expect(screen.queryByTestId('care-guide')).toBeNull();
});
