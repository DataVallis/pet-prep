/** M6-W04 child »Želje«: no prices, »Prosi starša«, states, limit, refusals; treat error texts. */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import { i18n } from '@/i18n';
import WishesSheet from '@/modules/wishes/WishesSheet';
import { treatErrorText } from '@/modules/wishes/TreatChip';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getChildWishes: jest.fn(), createWish: jest.fn() } };
});

const getChildWishes = api.getChildWishes as jest.Mock;
const createWish = api.createWish as jest.Mock;
const NOW = () => Date.parse('2026-10-28T10:00:00Z');

const CATALOGUE = {
  pet_id: 12,
  species: 'dog',
  open_count: 1,
  open_limit: 3,
  can_wish: true,
  items: [
    { key: 'bed', kind: 'durable', variant: 'dog_bed', status: 'available' },
    { key: 'bowls', kind: 'durable', variant: 'dog_bowls', status: 'requested', wish_id: 31 },
    { key: 'toy_1', kind: 'durable', variant: 'dog_rope', status: 'declined_recently', retry_at: '2026-11-03T08:00:00Z' },
    { key: 'toy_2', kind: 'durable', variant: 'dog_squeaky', status: 'owned', media_ready: false },
    { key: 'treats', kind: 'consumable', variant: 'dog_treats', status: 'available', treats_left: 5 },
  ],
};

// The first render loads the RevenueCat mock + i18n bundles (~5 s cold on CI runners).
jest.setTimeout(20_000);

async function flush() {
  for (let i = 0; i < 4; i += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

beforeEach(async () => {
  jest.clearAllMocks();
  await i18n.changeLanguage('sl');
});

describe('WishesSheet', () => {
  it('shows names and states, never a price', async () => {
    getChildWishes.mockResolvedValue(CATALOGUE);
    renderWithQuery(<WishesSheet visible onClose={jest.fn()} nowMs={NOW} />);
    await flush();
    expect(await screen.findByText('Pasja postelja')).toBeTruthy();
    expect(screen.getByTestId('wish-ask-bed')).toBeTruthy();
    expect(screen.getByTestId('wish-status-bowls')).toHaveTextContent('Prošnja poslana');
    expect(screen.getByTestId('wish-status-toy_1')).toHaveTextContent('Mogoče kdaj drugič');
    expect(screen.getByTestId('wish-status-toy_2')).toHaveTextContent('Na poti …');
    expect(screen.getByTestId('wish-status-treats')).toHaveTextContent('Še 5 priboljškov');
    expect(screen.queryByText(/€|\d+,\d\d/)).toBeNull();
  });

  it('»Prosi starša« sends the wish and the row turns »Prošnja poslana«', async () => {
    getChildWishes.mockResolvedValue(CATALOGUE);
    createWish.mockResolvedValue({ wish: { id: 40, item_key: 'bed', status: 'open', created_at: 'x' }, open_count: 2 });
    renderWithQuery(<WishesSheet visible onClose={jest.fn()} nowMs={NOW} />);
    await flush();
    getChildWishes.mockResolvedValue({ ...CATALOGUE, open_count: 2, items: CATALOGUE.items.map((i) => (i.key === 'bed' ? { ...i, status: 'requested', wish_id: 40 } : i)) });
    fireEvent.press(screen.getByTestId('wish-ask-bed'));
    await flush();
    expect(createWish).toHaveBeenCalledWith('bed');
    expect(await screen.findByTestId('wishes-feedback')).toHaveTextContent('Prošnja je poslana! Starš jo bo videl.');
    await waitFor(() => expect(screen.getByTestId('wish-status-bed')).toHaveTextContent('Prošnja poslana'));
  });

  it('3 open wishes: message + the ask button is off', async () => {
    getChildWishes.mockResolvedValue({ ...CATALOGUE, open_count: 3 });
    renderWithQuery(<WishesSheet visible onClose={jest.fn()} nowMs={NOW} />);
    await flush();
    expect(await screen.findByTestId('wishes-limit')).toBeTruthy();
    expect(screen.getByTestId('wish-ask-bed')).toBeDisabled();
  });

  it('a refusal shows a friendly text', async () => {
    getChildWishes.mockResolvedValue(CATALOGUE);
    createWish.mockRejectedValue(new ApiError('x', 422, { reason: 'wish_limit_reached' }));
    renderWithQuery(<WishesSheet visible onClose={jest.fn()} nowMs={NOW} />);
    await flush();
    fireEvent.press(screen.getByTestId('wish-ask-bed'));
    await flush();
    expect(await screen.findByTestId('wishes-feedback')).toHaveTextContent('Odprte imaš že 3 prošnje. Počakaj, da starš odgovori.');
  });

  it('old server (404 without reason) → "not here yet"', async () => {
    getChildWishes.mockRejectedValue(new ApiError('Not Found', 404, { message: 'Not Found' }));
    renderWithQuery(<WishesSheet visible onClose={jest.fn()} nowMs={NOW} />);
    await flush();
    expect(await screen.findByText('Želj tukaj še ni.')).toBeTruthy();
  });
});

describe('treatErrorText', () => {
  it('maps 422 reasons and 423 locks', async () => {
    await i18n.changeLanguage('sl');
    expect(treatErrorText(new ApiError('x', 422, { reason: 'treat_limit_today' }), null)).toBe('Danes je dobil že dva priboljška, jutri spet.');
    expect(treatErrorText(new ApiError('x', 422, { reason: 'no_treats' }), null)).toBe('Priboljškov ni več.');
    expect(treatErrorText(new ApiError('x', 422, { reason: 'pet_sleeping' }), null)).toMatch(/spi/);
    const locked = treatErrorText(new ApiError('x', 423, { reason: 'hard_stopped', locked_until: null }), null);
    expect(locked.length).toBeGreaterThan(0);
    expect(locked).not.toBe('Ni uspelo. Poskusi znova.');
  });
});
