/**
 * M5-R08 — the pet's name in the child HUD: the header title ("Luna · Border collie") and the
 * album title when the parent set one; exactly the old header (breed) without one, for a dog
 * and a cat; a `pet_renamed` broadcast updates the title live.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { setTextSpecies } from '@/i18n';
import { useAppStore } from '@/store/appStore';
import {
  makeBroadcast,
  makeLitterState,
  makeLiveChildState,
  makeMedia,
  makePet,
  makeScratchingState,
  makeWandState,
} from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import type { PetUpdatedBroadcast } from '@/types';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, getChildPet: jest.fn(), getChildPetGrowth: jest.fn(), syncSteps: jest.fn(), logout: jest.fn() },
  };
});

const mockSocket: { handler: ((event: PetUpdatedBroadcast) => void) | null } = { handler: null };
jest.mock('@/hooks/usePetWebSocket', () => ({
  usePetWebSocket: jest.fn((_petId: number | null, handler?: (event: PetUpdatedBroadcast) => void) => {
    mockSocket.handler = handler ?? null;
  }),
}));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(30_000);

const getChildPet = api.getChildPet as jest.Mock;

async function flush(ms = 0) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

type Overrides = Parameters<typeof makeLiveChildState>[0];

function dogState(o: Overrides = {}) {
  return makeLiveChildState({ ...o, pet: { breed_type: 'border_collie', ...o.pet } });
}

function catState(o: Overrides = {}) {
  return makeLiveChildState({
    wand: makeWandState(),
    litter: makeLitterState(),
    grooming: null,
    scratching: makeScratchingState({ active: null, blocked_reason: 'scratching_not_needed', can_start: false }),
    ...o,
    pet: { breed_type: 'domestic_cat', species: 'cat', ...o.pet },
  });
}

async function renderHud(state: ReturnType<typeof makeLiveChildState>) {
  getChildPet.mockResolvedValue(state);
  const utils = renderWithQuery(<ChildHudScreen />);
  await flush();
  await flush();
  expect(screen.getByTestId('action-feed', { includeHiddenElements: true })).toBeTruthy();
  return utils;
}

const headerText = () => screen.getByTestId('hud-profile-open');
/** The name in the title is wrapped in FSI … PDI (bidi isolation). */
const iso = (name: string) => `\u2068${name}\u2069`;

describe('ChildHudScreen — pet name (M5-R08)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    jest.setSystemTime(new Date('2026-10-04T12:00:00+02:00'));
    mockSocket.handler = null;
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({
      token: 'child-token',
      user: { id: 2, name: 'Maja', email: null, role: 'child' },
      pet: makePet({ id: 7 }),
      awaitingContract: false,
    });
    useAppStore.getState().setWsStatus('connected');
  });

  afterEach(() => {
    jest.useRealTimers();
    setTextSpecies(null);
  });

  it('a dog without a name: the breed title exactly as before, no name element', async () => {
    await renderHud(dogState());
    expect(screen.queryByTestId('hud-pet-name')).toBeNull();
    expect(screen.getByText('Border collie')).toBeTruthy();
    expect(headerText().props.accessibilityLabel).toMatch(/^Border collie, /);
  });

  it('a cat without a name: the breed title exactly as before', async () => {
    useAppStore.getState().signIn({
      token: 'child-token',
      user: { id: 2, name: 'Maja', email: null, role: 'child' },
      pet: makePet({ id: 7, breed_type: 'domestic_cat', species: 'cat' }),
      awaitingContract: false,
    });
    await renderHud(catState());
    expect(screen.queryByTestId('hud-pet-name')).toBeNull();
    expect(screen.getByText('Domača mačka')).toBeTruthy();
  });

  it('a named dog: the name is the title, the breed stays beside it; screen reader starts with the name', async () => {
    await renderHud(dogState({ pet: { name: 'Luna' } }));
    expect(screen.getByTestId('hud-pet-name')).toHaveTextContent(`${iso('Luna')} · Border collie`);
    expect(headerText().props.accessibilityLabel).toMatch(/^Luna, Border collie, /);
  });

  it('a named cat too', async () => {
    useAppStore.getState().signIn({
      token: 'child-token',
      user: { id: 2, name: 'Maja', email: null, role: 'child' },
      pet: makePet({ id: 7, breed_type: 'domestic_cat', species: 'cat' }),
      awaitingContract: false,
    });
    await renderHud(catState({ pet: { name: 'Muri' } }));
    expect(screen.getByTestId('hud-pet-name')).toHaveTextContent(`${iso('Muri')} · Domača mačka`);
  });

  it('the album is titled with the name', async () => {
    const media = makeMedia({ reference_image_url: 'https://cdn.test/ref.png' });
    await renderHud(dogState({ pet: { name: 'Luna', media } }));
    fireEvent.press(screen.getByTestId('hud-album-open'));
    await flush();
    expect(screen.getByTestId('hud-album')).toHaveTextContent(/Luna/);
  });

  it('a `pet_renamed` broadcast updates the title live, and a cleared name brings the breed back', async () => {
    await renderHud(dogState());
    expect(screen.queryByTestId('hud-pet-name')).toBeNull();
    expect(mockSocket.handler).not.toBeNull();

    // The refetch the event triggers hangs: the title comes from the broadcast itself.
    let answer: (state: ReturnType<typeof dogState>) => void = () => undefined;
    getChildPet.mockReturnValue(new Promise((resolve) => (answer = resolve)));
    await act(async () => {
      mockSocket.handler?.(makeBroadcast({ breed_type: 'border_collie', event_type: 'pet_renamed', name: 'Luna', emitted_at: '2026-10-04T10:00:08.000+00:00' }));
    });
    await flush();
    expect(screen.getByTestId('hud-pet-name')).toHaveTextContent(`${iso('Luna')} · Border collie`);
    // … and the server's state agrees.
    await act(async () => answer(dogState({ pet: { name: 'Luna' } })));
    await flush();
    expect(screen.getByTestId('hud-pet-name')).toHaveTextContent(`${iso('Luna')} · Border collie`);

    getChildPet.mockResolvedValue(dogState());
    await act(async () => {
      mockSocket.handler?.(
        makeBroadcast({ breed_type: 'border_collie', event_type: 'pet_renamed', name: null, emitted_at: '2026-10-04T10:00:09.000+00:00' }),
      );
    });
    await flush();
    expect(screen.queryByTestId('hud-pet-name')).toBeNull();
    expect(screen.getByText('Border collie')).toBeTruthy();
  });
});
