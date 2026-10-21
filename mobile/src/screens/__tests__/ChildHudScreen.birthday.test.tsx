/**
 * M5-T13 — the first birthday on the child HUD: a party hat over the pet the whole day (never
 * over a lock), the card with the safe cake and the tip opens by itself once per pet, then
 * from the hat; nothing on any other day or for an older server.
 */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';

import { api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { setBirthdayStoreForTests } from '@/modules/birthday/birthdaySeen';
import { useAppStore } from '@/store/appStore';
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

const FSI = '⁨';
const PDI = '⁩';
const getChildPet = api.getChildPet as jest.Mock;
const TODAY = { date: '2026-10-04', is_today: true, celebrated: true };
let data: Map<string, string>;

beforeEach(() => {
  jest.clearAllMocks();
  data = new Map();
  setBirthdayStoreForTests({
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

afterEach(() => setBirthdayStoreForTests(null));

async function renderHud(state: ReturnType<typeof makeLiveChildState>) {
  getChildPet.mockResolvedValue(state);
  const utils = renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('action-feed');
  return utils;
}

it('shows the hat and opens the card once per pet on the birthday', async () => {
  const state = makeLiveChildState({ pet: { name: 'Luna', birthday: TODAY } });
  const first = await renderHud(state);

  expect(screen.getByTestId('birthday-hat')).toBeTruthy();
  expect(await screen.findByTestId('birthday-card')).toBeTruthy();
  expect(screen.getByTestId('birthday-card-title').props.children).toBe(`Vse najboljše, ${FSI}Luna${PDI}!`);
  expect(screen.getByTestId('birthday-card-age').props.children).toBe(`${FSI}Luna${PDI} je danes star 1 leto.`);
  expect(screen.getByTestId('birthday-card-cake').props.accessibilityLabel).toContain('brez čokolade');
  expect(screen.getByTestId('birthday-card-source').props.children).toContain('FDA');

  fireEvent.press(screen.getByTestId('birthday-card-close'));
  await waitFor(() => expect(screen.queryByTestId('birthday-card')).toBeNull());
  expect(JSON.parse(data.get('petprep.birthday_seen') ?? '[]')).toEqual([7]);
  first.unmount();

  // Second launch the same day: the hat stays, the card waits for a tap on it.
  await renderHud(state);
  await act(async () => {
    await Promise.resolve();
  });
  expect(screen.getByTestId('birthday-hat')).toBeTruthy();
  expect(screen.queryByTestId('birthday-card')).toBeNull();
  fireEvent.press(screen.getByTestId('birthday-hat'));
  expect(await screen.findByTestId('birthday-card')).toBeTruthy();
});

it('a kitty gets the cat cake and tip', async () => {
  await renderHud(makeLiveChildState({ pet: { breed_type: 'domestic_cat', species: 'cat', birthday: TODAY } }));
  expect((await screen.findByTestId('birthday-card-title')).props.children).toBe('Vse najboljše, muca!');
  expect(screen.getByTestId('birthday-card-cake').props.accessibilityLabel).toContain('brez mleka');
});

it('shows nothing on another day, for an older server or over a lock', async () => {
  const other = await renderHud(makeLiveChildState({ pet: { birthday: { date: '2026-12-16', is_today: false, celebrated: false } } }));
  expect(screen.queryByTestId('birthday-hat')).toBeNull();
  other.unmount();

  const old = await renderHud(makeLiveChildState({ pet: { birthday: null } }));
  expect(screen.queryByTestId('birthday-hat')).toBeNull();
  old.unmount();

  getChildPet.mockResolvedValue(makeLiveChildState({ pet: { birthday: TODAY }, lock: { is_locked: true, reason: 'hard_stopped', until: null } }));
  renderWithQuery(<ChildHudScreen />);
  await act(async () => {
    await Promise.resolve();
  });
  expect(screen.queryByTestId('birthday-hat')).toBeNull();
  expect(screen.queryByTestId('birthday-card')).toBeNull();
});
