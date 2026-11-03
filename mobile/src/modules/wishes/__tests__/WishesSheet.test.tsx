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
    // A server without designs: no design is sent (M6-W03).
    expect(createWish).toHaveBeenCalledWith('bed', null);
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

describe('WishesSheet — designs (M6-W03)', () => {
  const DESIGNS = [
    { key: 'bed_round', name: 'Okrogla postelja', image: 'https://api.petprep.si/d/1.png' },
    { key: 'bed_box', name: 'Postelja škatla', image: null },
  ];
  const WITH_DESIGNS = {
    ...CATALOGUE,
    items: [
      { key: 'bed', kind: 'durable', variant: 'dog_bed', status: 'available', designs: DESIGNS },
      { key: 'toy_2', kind: 'durable', variant: 'dog_squeaky', status: 'owned', media_ready: true, design: 'sq_blue', designs: [{ key: 'sq_blue', name: 'Modra piskajoča', image: 'https://api.petprep.si/d/9.png' }] },
      { key: 'toy_1', kind: 'durable', variant: 'dog_rope', status: 'owned', media_ready: false, design: 'rope_red', designs: [{ key: 'rope_red', name: 'Rdeča vrv', image: null }] },
    ],
  };

  it('an item with designs opens its pictures; pick one → »Prosi starša« sends that design', async () => {
    getChildWishes.mockResolvedValue(WITH_DESIGNS);
    createWish.mockResolvedValue({ wish: { id: 41, item_key: 'bed', status: 'open', created_at: 'x' }, open_count: 2 });
    renderWithQuery(<WishesSheet visible onClose={jest.fn()} nowMs={NOW} />);
    await flush();
    fireEvent.press(screen.getByTestId('wish-ask-bed'));
    expect(createWish).not.toHaveBeenCalled();
    expect(screen.getByTestId('wish-designs-bed')).toBeTruthy();
    // Big picture cards, never a price; a missing picture → the placeholder card with its name.
    expect(screen.getByTestId('wish-design-bed-bed_round-image')).toBeTruthy();
    expect(screen.getByTestId('wish-design-bed-bed_box-placeholder')).toBeTruthy();
    expect(screen.getByText('Postelja škatla')).toBeTruthy();
    expect(screen.queryByText(/€|\d+,\d\d/)).toBeNull();
    // Nothing chosen yet → the button waits.
    expect(screen.getByTestId('wish-design-ask-bed')).toBeDisabled();
    fireEvent.press(screen.getByTestId('wish-design-bed-bed_box'));
    expect(screen.getByTestId('wish-design-ask-bed')).not.toBeDisabled();
    fireEvent.press(screen.getByTestId('wish-design-ask-bed'));
    await flush();
    expect(createWish).toHaveBeenCalledWith('bed', 'bed_box');
    expect(await screen.findByTestId('wishes-feedback')).toHaveTextContent('Prošnja je poslana! Starš jo bo videl.');
    // Back on the list.
    expect(screen.queryByTestId('wish-designs-bed')).toBeNull();
  });

  it('»Nazaj« returns to the list without sending', async () => {
    getChildWishes.mockResolvedValue(WITH_DESIGNS);
    renderWithQuery(<WishesSheet visible onClose={jest.fn()} nowMs={NOW} />);
    await flush();
    fireEvent.press(screen.getByTestId('wish-ask-bed'));
    fireEvent.press(screen.getByTestId('wish-designs-back'));
    expect(screen.getByTestId('wish-row-bed')).toBeTruthy();
    expect(createWish).not.toHaveBeenCalled();
  });

  it('owned items show their design (picture + name) with »Že ima« / »Na poti …«', async () => {
    getChildWishes.mockResolvedValue(WITH_DESIGNS);
    renderWithQuery(<WishesSheet visible onClose={jest.fn()} nowMs={NOW} />);
    await flush();
    expect(screen.getByTestId('wish-row-design-toy_2', { includeHiddenElements: true })).toBeTruthy();
    expect(screen.getByText('Modra piskajoča')).toBeTruthy();
    expect(screen.getByTestId('wish-status-toy_2')).toHaveTextContent('Že ima');
    // No picture → the item icon, the design name still shows.
    expect(screen.queryByTestId('wish-row-design-toy_1', { includeHiddenElements: true })).toBeNull();
    expect(screen.getByText('Rdeča vrv')).toBeTruthy();
    expect(screen.getByTestId('wish-status-toy_1')).toHaveTextContent('Na poti …');
  });

  it('422 invalid_design → a friendly »pick again« text and fresh designs', async () => {
    getChildWishes.mockResolvedValue(WITH_DESIGNS);
    createWish.mockRejectedValue(new ApiError('x', 422, { reason: 'invalid_design' }));
    renderWithQuery(<WishesSheet visible onClose={jest.fn()} nowMs={NOW} />);
    await flush();
    fireEvent.press(screen.getByTestId('wish-ask-bed'));
    fireEvent.press(screen.getByTestId('wish-design-bed-bed_round'));
    fireEvent.press(screen.getByTestId('wish-design-ask-bed'));
    await flush();
    expect(await screen.findByTestId('wishes-feedback')).toHaveTextContent('Te izbire ni več. Izberi znova.');
    expect(getChildWishes.mock.calls.length).toBeGreaterThan(1);
    expect(screen.getByTestId('wish-design-ask-bed')).toBeDisabled();
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
