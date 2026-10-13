/**
 * M5-R09c »Ali si vedel?« in the child HUD (DID_YOU_KNOW_SPEC §3): the book button (raspberry dot
 * while unread) only for a pedigree pet with approved facts; the card opens by itself only in a
 * calm moment after a care success and once the server agreed (`POST …/shown`); a 422 refusal
 * keeps the chip, never an error; the card marks the fact read; »Moja pasma« searches and opens
 * the same card.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { ApiError, api, type ChildPetState } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { useAppStore } from '@/store/appStore';
import { i18n } from '@/i18n';
import {
  makeBreedFact,
  makeBreedFacts,
  makeFactOfTheDay,
  makeLiveChildState,
  makeMedia,
  makePet,
} from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { configure } from '@testing-library/react-native';

configure({ defaultIncludeHiddenElements: true });

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
      syncSteps: jest.fn(),
      logout: jest.fn(),
      getBreedFacts: jest.fn(),
      getFactOfTheDay: jest.fn(),
      recordFactShown: jest.fn(),
      markBreedFactRead: jest.fn(),
    },
  };
});
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(30_000);

const getChildPet = api.getChildPet as jest.Mock;
const waterPet = api.waterPet as jest.Mock;
const getBreedFacts = api.getBreedFacts as jest.Mock;
const getFactOfTheDay = api.getFactOfTheDay as jest.Mock;
const recordFactShown = api.recordFactShown as jest.Mock;
const markBreedFactRead = api.markBreedFactRead as jest.Mock;

const FACT_1 = makeBreedFact({ id: 1 });
const FACT_2 = makeBreedFact({
  id: 2,
  key: 'border_collie-002',
  category: 'temperament',
  title: 'Značaj: zelo pameten in učljiv pes.',
  why: null,
  source_name: 'FCI',
});

/** A calm border collie: all meters ≥ 50 %, water allowed, no mess, not asleep. */
function collieState(o: Parameters<typeof makeLiveChildState>[0] = {}): ChildPetState {
  return makeLiveChildState({
    ...o,
    pet: {
      breed_type: 'border_collie',
      hunger_level: 80,
      thirst_level: 60,
      energy_level: 70,
      hygiene_level: 90,
      media: makeMedia({ reference_image_url: 'https://media.test/collie.png' }),
      ...o.pet,
    },
    water: { can_water: true, ...o.water },
  });
}

function signIn() {
  useAppStore.getState().signIn({
    token: 'child-token',
    user: { id: 2, name: 'Maja', email: null, role: 'child' },
    pet: makePet({ id: 7, breed_type: 'border_collie' }),
    awaitingContract: false,
  });
  useAppStore.getState().setWsStatus('connected');
}

async function flush(ms = 0) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

async function renderHud(state: ChildPetState = collieState()) {
  getChildPet.mockResolvedValue(state);
  const utils = renderWithQuery(<ChildHudScreen />);
  await flush(0);
  await flush(0);
  await screen.findByTestId('action-feed');
  await flush(0);
  return utils;
}

/** Water the pet (a care success) and let the settle wait pass. */
async function water(state: ChildPetState = collieState()) {
  waterPet.mockResolvedValueOnce({ status: 'accepted', state });
  fireEvent.press(screen.getByTestId('action-water'));
  await flush(0);
  await flush(0);
  await flush(2_000);
}

beforeEach(() => {
  jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
  jest.setSystemTime(new Date('2026-10-04T10:00:00Z'));
  jest.clearAllMocks();
  useAppStore.setState(useAppStore.getInitialState(), true);
  signIn();
  getBreedFacts.mockResolvedValue(makeBreedFacts([FACT_1, FACT_2]));
  getFactOfTheDay.mockResolvedValue(makeFactOfTheDay({ total_count: 2 }));
  recordFactShown.mockResolvedValue({ status: 'recorded', reason: null, state: makeFactOfTheDay({ shown_today: true, shown_this_week: 1, total_count: 2 }) });
  markBreedFactRead.mockResolvedValue({ status: 'read', fact: { ...FACT_1, read: true, read_at: '2026-10-04T10:00:15Z' } });
});

afterEach(async () => {
  jest.useRealTimers();
  await i18n.changeLanguage('sl');
});

describe('HUD — »Ali si vedel?« entry points', () => {
  it('pedigree pet with facts: book button with the raspberry dot while a fact is unread', async () => {
    await renderHud();
    const book = screen.getByTestId('hud-facts-open');
    expect(book.props.accessibilityLabel).toBe('Ali si vedel? Zanimivosti o tvoji pasmi, čakajo nove zanimivosti');
    expect(screen.getByTestId('hud-facts-unread')).toBeTruthy();
    expect(getBreedFacts).toHaveBeenCalledWith('border_collie', expect.anything());
  });

  it('every fact read → book without the dot', async () => {
    getBreedFacts.mockResolvedValue(makeBreedFacts([{ ...FACT_1, read: true, read_at: '2026-10-01T10:00:00Z' }]));
    getFactOfTheDay.mockResolvedValue(makeFactOfTheDay({ fact: null, may_auto_show: false, reason: 'no_fact' }));
    await renderHud();
    expect(screen.getByTestId('hud-facts-open')).toBeTruthy();
    expect(screen.queryByTestId('hud-facts-unread')).toBeNull();
    expect(screen.queryByTestId('hud-fact-chip')).toBeNull();
  });

  it('mutt: no request, no book, no chip, no sheet', async () => {
    await renderHud(collieState({ pet: { breed_type: 'mutt' } }));
    expect(getBreedFacts).not.toHaveBeenCalled();
    expect(getFactOfTheDay).not.toHaveBeenCalled();
    expect(screen.queryByTestId('hud-facts-open')).toBeNull();
    expect(screen.queryByTestId('hud-fact-chip')).toBeNull();
  });

  it('domestic cat: nothing', async () => {
    await renderHud(collieState({ pet: { breed_type: 'domestic_cat', species: 'cat' } }));
    expect(getBreedFacts).not.toHaveBeenCalled();
    expect(screen.queryByTestId('hud-facts-open')).toBeNull();
  });

  it('a breed without approved facts (empty list): nothing empty ever shows', async () => {
    getBreedFacts.mockResolvedValue(makeBreedFacts([]));
    await renderHud();
    expect(screen.queryByTestId('hud-facts-open')).toBeNull();
    expect(screen.queryByTestId('hud-fact-chip')).toBeNull();
    expect(getFactOfTheDay).not.toHaveBeenCalled();
  });

  it('the list fails to load: no book (no broken entry)', async () => {
    getBreedFacts.mockRejectedValue(new TypeError('Network request failed'));
    await renderHud();
    expect(screen.queryByTestId('hud-facts-open')).toBeNull();
  });
});

describe('HUD — calm moment', () => {
  it('right after opening (no care yet): the chip, never the sheet', async () => {
    await renderHud();
    await flush(15_000);
    expect(screen.getByTestId('hud-fact-chip')).toHaveTextContent('Nekaj novega o tvoji pasmi');
    expect(recordFactShown).not.toHaveBeenCalled();
    expect(screen.queryByTestId('fact-sheet')).toBeNull();
  });

  it('a care success in the first 10 s waits; after 10 s the server agrees → the sheet opens and the fact is read', async () => {
    await renderHud();
    await water();
    // Still inside the first 10 s after opening.
    expect(recordFactShown).not.toHaveBeenCalled();
    await flush(10_000);
    expect(recordFactShown).toHaveBeenCalledTimes(1);
    await flush(0);
    expect(screen.getByTestId('fact-sheet')).toBeTruthy();
    expect(screen.getByText('ALI SI VEDEL?')).toBeTruthy();
  });

  it('calm: sheet content, read mark, »Super!« closes; no second automatic sheet that day', async () => {
    await renderHud();
    await flush(11_000);
    await water();
    await flush(0);
    expect(screen.getByTestId('fact-sheet-title')).toHaveTextContent(FACT_1.title);
    expect(screen.getByTestId('fact-sheet-meta')).toHaveTextContent('Border collie · Gibanje');
    expect(screen.getByTestId('fact-sheet-why')).toHaveTextContent(FACT_1.why ?? '');
    expect(screen.getByTestId('fact-sheet-source')).toHaveTextContent('Vir: The Royal Kennel Club');
    // Marked read as soon as the card shows (optimistic) → this one counts.
    expect(screen.getByTestId('fact-sheet-progress').props.accessibilityLabel).toBe('Prebrano 1 od 2');
    expect(screen.getByTestId('fact-sheet').props.role).toBe('dialog');
    expect(screen.getByTestId('fact-sheet-content').props.accessibilityLabel).toBe(
      `${FACT_1.title}. ${FACT_1.why} Vir: The Royal Kennel Club.`,
    );
    expect(markBreedFactRead).toHaveBeenCalledWith(1);

    fireEvent.press(screen.getByTestId('fact-sheet-great'));
    await flush(0);
    expect(screen.queryByTestId('fact-sheet')).toBeNull();

    await water();
    await flush(5_000);
    expect(recordFactShown).toHaveBeenCalledTimes(1);
  });

  it('server refuses (422 refused) → no sheet, no error, the chip stays', async () => {
    recordFactShown.mockRejectedValue(
      new ApiError('refused', 422, { status: 'refused', reason: 'week_cap', state: makeFactOfTheDay({ may_auto_show: false, reason: 'week_cap' }) }),
    );
    await renderHud();
    await flush(11_000);
    await water();
    await flush(0);
    expect(recordFactShown).toHaveBeenCalledTimes(1);
    expect(screen.queryByTestId('fact-sheet')).toBeNull();
    expect(screen.getByTestId('hud-fact-chip')).toBeTruthy();
    // The child only sees the usual water toast, never a refusal text.
    expect(screen.getByTestId('hud-toast')).not.toHaveTextContent(/week_cap|refused/);

    fireEvent.press(screen.getByTestId('hud-fact-chip'));
    await flush(0);
    expect(screen.getByTestId('fact-sheet-title')).toHaveTextContent(FACT_1.title);
  });

  it.each<[string, Parameters<typeof collieState>[0]]>([
    ['a metric under 50 %', { pet: { thirst_level: 40 } }],
    ['an open mess', { pet: { needs_cleaning: true } }],
    ['quiet hours (asleep)', { pet: { pet_state: 'sleeping' } }],
  ])('%s after the care → no sheet', async (_name, after) => {
    await renderHud();
    await flush(11_000);
    await water(collieState(after));
    await flush(5_000);
    expect(recordFactShown).not.toHaveBeenCalled();
    expect(screen.queryByTestId('fact-sheet')).toBeNull();
  });

  it('a lock → no sheet and no chip', async () => {
    await renderHud(collieState({ lock: { is_locked: true, reason: 'hard_stopped' } }));
    await flush(15_000);
    expect(screen.queryByTestId('hud-fact-chip')).toBeNull();
    expect(recordFactShown).not.toHaveBeenCalled();
  });

  it('an overlay (album / school / play / cat game) open → no sheet', async () => {
    await renderHud();
    await flush(11_000);
    act(() => useAppStore.getState().setPlayOverlay('pick'));
    await water();
    await flush(5_000);
    expect(recordFactShown).not.toHaveBeenCalled();
  });

  it('the server does not allow it today → neither chip nor sheet', async () => {
    getFactOfTheDay.mockResolvedValue(makeFactOfTheDay({ may_auto_show: false, reason: 'too_soon' }));
    await renderHud();
    await flush(11_000);
    await water();
    await flush(5_000);
    expect(recordFactShown).not.toHaveBeenCalled();
    expect(screen.queryByTestId('hud-fact-chip')).toBeNull();
  });
});

describe('HUD — »Moja pasma«', () => {
  it('book → guide: progress, »Novo«, diacritic-insensitive search, tap opens the card, back closes', async () => {
    await renderHud();
    fireEvent.press(screen.getByTestId('hud-facts-open'));
    await flush(0);
    expect(screen.getByTestId('breed-guide-breed')).toHaveTextContent('Border collie');
    expect(screen.getByTestId('breed-guide-progress')).toHaveTextContent('Prebrano: 0 od 2');
    expect(screen.getByTestId('breed-guide-new-1')).toHaveTextContent('Novo');
    // The chip waits while the guide is open.
    expect(screen.queryByTestId('hud-fact-chip')).toBeNull();

    fireEvent.changeText(screen.getByTestId('breed-guide-search'), 'znacaj');
    expect(screen.queryByTestId('breed-guide-fact-1')).toBeNull();
    expect(screen.getByTestId('breed-guide-fact-2')).toBeTruthy();

    fireEvent.changeText(screen.getByTestId('breed-guide-search'), 'zmaj');
    expect(screen.getByTestId('breed-guide-empty')).toBeTruthy();
    fireEvent.press(screen.getByTestId('breed-guide-search-clear'));

    fireEvent.press(screen.getByTestId('breed-guide-category-temperament'));
    expect(screen.queryByTestId('breed-guide-fact-1')).toBeNull();
    fireEvent.press(screen.getByTestId('breed-guide-category-all'));

    markBreedFactRead.mockResolvedValueOnce({ status: 'read', fact: { ...FACT_2, read: true, read_at: '2026-10-04T10:01:00Z' } });
    fireEvent.press(screen.getByTestId('breed-guide-fact-2'));
    await flush(0);
    expect(screen.getByTestId('fact-sheet-title')).toHaveTextContent(FACT_2.title);
    expect(screen.queryByTestId('fact-sheet-why')).toBeNull();
    expect(markBreedFactRead).toHaveBeenCalledWith(2);
    // Optimistic: »Novo« goes at once.
    expect(screen.queryByTestId('breed-guide-new-2')).toBeNull();
    expect(screen.getByTestId('breed-guide-progress')).toHaveTextContent('Prebrano: 1 od 2');

    fireEvent.press(screen.getByTestId('fact-sheet-great'));
    await flush(0);
    fireEvent.press(screen.getByTestId('breed-guide-close'));
    await flush(0);
    expect(screen.queryByTestId('breed-guide')).toBeNull();
  });

  it('»Več o moji pasmi« on the card opens the guide', async () => {
    await renderHud();
    fireEvent.press(screen.getByTestId('hud-fact-chip'));
    await flush(0);
    fireEvent.press(screen.getByTestId('fact-sheet-more'));
    await flush(0);
    expect(screen.queryByTestId('fact-sheet')).toBeNull();
    expect(screen.getByTestId('breed-guide')).toBeTruthy();
  });

  it('English texts', async () => {
    await i18n.changeLanguage('en');
    await renderHud();
    expect(screen.getByTestId('hud-fact-chip')).toHaveTextContent('Something new about your breed');
    fireEvent.press(screen.getByTestId('hud-fact-chip'));
    await flush(0);
    expect(screen.getByText('DID YOU KNOW?')).toBeTruthy();
    expect(screen.getByText('Great!')).toBeTruthy();
    expect(screen.getByText('More about my breed')).toBeTruthy();
  });
});
