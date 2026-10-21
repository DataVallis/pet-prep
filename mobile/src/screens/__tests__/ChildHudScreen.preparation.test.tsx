/**
 * M5-T06 — pet preparation on the child HUD: »Pripravljamo: Luna … to lahko traja nekaj
 * minut« with Curio while the server says `preparing`; never over a lock; and
 * »Luna je pripravljen!« once per pet when the shown preparation ends.
 */
import { act, screen, waitFor } from '@testing-library/react-native';

import { api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { setReadyAnnouncedStoreForTests } from '@/modules/preparation/readyAnnounced';
import { useAppStore } from '@/store/appStore';
import { makeLiveChildState, makePet, makePreparation, makePreparing } from '@/test-utils/fixtures';
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
let data: Map<string, string>;

beforeEach(() => {
  jest.clearAllMocks();
  data = new Map();
  setReadyAnnouncedStoreForTests({
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

afterEach(() => setReadyAnnouncedStoreForTests(null));

async function renderHud(state: ReturnType<typeof makeLiveChildState>) {
  getChildPet.mockResolvedValue(state);
  const utils = renderWithQuery(<ChildHudScreen />);
  // The HUD is hidden from screen readers under the set-up screen: wait for either.
  await waitFor(() => expect(screen.queryByTestId('pet-preparation') ?? screen.queryByTestId('action-feed')).toBeTruthy());
  return utils;
}

it('shows »Pripravljamo: Luna« with Curio while the first things are on their way', async () => {
  await renderHud(makeLiveChildState({ pet: { name: 'Luna', preparation: makePreparing() } }));

  expect(await screen.findByTestId('pet-preparation')).toBeTruthy();
  expect(screen.getByTestId('pet-preparation-title').props.children).toBe(`Pripravljamo: ${FSI}Luna${PDI}`);
  expect(screen.getByTestId('pet-preparation-body').props.children).toBe('To lahko traja nekaj minut.');
  expect(screen.getByTestId('pet-preparation-curio')).toBeTruthy();
  // The old »Kuža se pripravlja…« pill is not shown on top of it.
  expect(screen.queryByTestId('hud-pet-media-pending')).toBeNull();
  // The parent exit stays reachable.
  expect(screen.getByTestId('pet-preparation-parent-exit')).toBeTruthy();
  // The HUD under it is hidden from screen readers.
  expect(screen.getByTestId('hud-content', { includeHiddenElements: true }).props.importantForAccessibility).toBe('no-hide-descendants');
});

it('falls back to the species noun without a name', async () => {
  await renderHud(makeLiveChildState({ pet: { preparation: makePreparing(['image', 'idle']) } }));
  expect((await screen.findByTestId('pet-preparation-title')).props.children).toBe('Pripravljamo tvojega kužka');
});

it('never covers a lock, nor shows when the server says ready', async () => {
  const first = await renderHud(makeLiveChildState({ pet: { preparation: makePreparing() }, lock: { is_locked: true, reason: 'hard_stopped', until: null } }));
  expect(screen.queryByTestId('pet-preparation')).toBeNull();
  first.unmount();

  await renderHud(makeLiveChildState({ pet: { preparation: makePreparation() } }));
  expect(screen.queryByTestId('pet-preparation')).toBeNull();
});

it('says »Luna je pripravljen!« once per pet when the shown preparation ended', async () => {
  const readyAt = '2026-10-04T09:58:00+00:00'; // 2 min before the state's server_time
  const state = makeLiveChildState({ pet: { name: 'Luna', preparation: makePreparation({ ready_at: readyAt }) } });
  const first = await renderHud(state);

  await waitFor(() => expect(screen.getByText(`${FSI}Luna${PDI} je pripravljen!`)).toBeTruthy());
  expect(JSON.parse(data.get('petprep.pet_ready_announced') ?? '[]')).toEqual([7]);
  first.unmount();

  await renderHud(state);
  await act(async () => {
    await Promise.resolve();
  });
  expect(screen.queryByText(`${FSI}Luna${PDI} je pripravljen!`)).toBeNull();
});

it('says nothing for a pet that never showed the preparation', async () => {
  await renderHud(makeLiveChildState({ pet: { name: 'Luna', preparation: makePreparation() } }));
  await act(async () => {
    await Promise.resolve();
  });
  expect(screen.queryByText(`${FSI}Luna${PDI} je pripravljen!`)).toBeNull();
  expect(data.size).toBe(0);
});
