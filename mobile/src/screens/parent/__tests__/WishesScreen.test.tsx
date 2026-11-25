/** M6-W05 parent »Želje«: store prices, »Nakup še ni na voljo«, the bundle section (David 2026-11-25), buy → redeem, »Ne zdaj«, »Komu?«, old server. */
import { act, fireEvent, screen, waitFor, within } from '@testing-library/react-native';
import Purchases from 'react-native-purchases';

import { ApiError, api } from '@/api/client';
import { i18n } from '@/i18n';
import { resetPurchasesForTests } from '@/modules/purchases/purchases';
import WishesScreen from '@/screens/parent/WishesScreen';
import { useAppStore } from '@/store/appStore';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getParentWishes: jest.fn(), declineWish: jest.fn(), redeemPetItem: jest.fn() } };
});
jest.mock('@/config/env', () => {
  const actual = jest.requireActual<typeof import('@/config/env')>('@/config/env');
  return { ...actual, ENV: { ...actual.ENV, REVENUECAT_IOS_KEY: 'appl_test', REVENUECAT_ANDROID_KEY: 'goog_test' } };
});
jest.mock('expo-constants', () => {
  const actual = jest.requireActual<typeof import('expo-constants')>('expo-constants');
  return { ...actual, __esModule: true, default: { ...actual.default, executionEnvironment: 'bare' } };
});

const getParentWishes = api.getParentWishes as jest.Mock;
const declineWish = api.declineWish as jest.Mock;
const redeemPetItem = api.redeemPetItem as jest.Mock;
const getProducts = Purchases.getProducts as jest.Mock;
const purchaseStoreProduct = Purchases.purchaseStoreProduct as jest.Mock;

const WISH = { id: 31, pet_id: 12, pet_name: 'Suki', species: 'dog', child_id: 7, child_name: 'Ana', item_key: 'bowls', variant: 'dog_bowls', product_id: 'petprep_wish_bowls', status: 'open', created_at: '2026-10-27T08:00:00Z', bundle_product_id: 'petprep_wish_bundle_welcome' };
const BODY = {
  open_count: 1,
  wishes: [WISH],
  pets: [{ pet_id: 12, pet_name: 'Suki', species: 'dog', bundle_eligible: true, items: [{ key: 'bed', variant: 'dog_bed', product_id: 'petprep_wish_bed', owned: false, media_ready: false }] }],
  unassigned_credits: [],
};
const product = (identifier: string, priceString: string) => ({ identifier, priceString });
/** A store product with the numeric price + currency (what »Posamezno skupaj …« reads). */
const priced = (identifier: string, price: number) => ({ identifier, price, currencyCode: 'EUR', priceString: `${price.toFixed(2).replace('.', ',')}\u00a0€` });

// The first render loads the RevenueCat mock + i18n bundles (~5 s cold on CI runners).
jest.setTimeout(20_000);

async function flush() {
  for (let i = 0; i < 5; i += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

beforeEach(async () => {
  jest.clearAllMocks();
  resetPurchasesForTests();
  await i18n.changeLanguage('sl');
  useAppStore.setState({ authToken: 'tok', bootStatus: 'ready', user: { id: 1, name: 'Starš', email: 'p@x.si', role: 'parent' } as never });
});

describe('WishesScreen', () => {
  it('store has no products yet → »Nakup še ni na voljo«, button off, no bundle', async () => {
    getParentWishes.mockResolvedValue(BODY);
    getProducts.mockResolvedValue([]);
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByText('Skledi za hrano in vodo')).toBeTruthy();
    expect(screen.getByTestId('wish-buy-31')).toBeDisabled();
    expect(screen.getAllByText('Nakup še ni na voljo').length).toBeGreaterThan(0);
    expect(screen.queryByTestId('bundle-section')).toBeNull();
    expect(screen.queryByTestId('bundle-buy-12')).toBeNull();
  });

  it('store prices → »Kupi za …« in the wish card, the bundle in its OWN section; buying redeems with the wish and the transaction id', async () => {
    getParentWishes.mockResolvedValue(BODY);
    getProducts.mockResolvedValue([product('petprep_wish_bowls', '5,99 €'), product('petprep_wish_bundle_welcome', '15,99 €')]);
    purchaseStoreProduct.mockResolvedValue({ productIdentifier: 'petprep_wish_bowls', customerInfo: {}, transaction: { transactionIdentifier: 'tx-9' } });
    redeemPetItem.mockResolvedValue({ status: 'assigned', items: [], open_count: 0 });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByText('Kupi za 5,99 €')).toBeTruthy();
    // David 2026-11-25: the bundle is its own section, not a button inside the wish card.
    expect(screen.getByTestId('bundle-section')).toHaveTextContent('Paket »Dobrodošlica«');
    expect(within(screen.getByTestId('wish-31')).queryByTestId('bundle-buy-12')).toBeNull();
    expect(within(screen.getByTestId('bundle-12')).getByText('Kupi paket za 15,99 €')).toBeTruthy();
    expect(screen.getByTestId('bundle-12-contents')).toHaveTextContent('Paket za Suki: postelja, skledi in vrv za vlečenje');
    // Not all three single prices in the store → no saving line.
    expect(screen.queryByTestId('bundle-12-separately')).toBeNull();
    expect(screen.queryByText(/Vse tri/)).toBeNull();
    fireEvent.press(screen.getByTestId('wish-buy-31'));
    expect(await screen.findByText('Kupljeno — stvar je dodana.')).toBeTruthy();
    expect(redeemPetItem).toHaveBeenCalledWith(12, { product_id: 'petprep_wish_bowls', wish_id: 31 }, 'tx-9', expect.anything());
  });

  it('bundle section for a cat: basket, bowls and plush mouse; »Posamezno skupaj …« only from all three store prices', async () => {
    const catWish = { ...WISH, id: 41, pet_id: 20, pet_name: 'Mici', species: 'cat', item_key: 'bed', variant: 'cat_basket', product_id: 'petprep_wish_bed' };
    const catPet = {
      pet_id: 20,
      pet_name: 'Mici',
      species: 'cat',
      bundle_eligible: true,
      items: [
        { key: 'bed', variant: 'cat_basket', product_id: 'petprep_wish_bed', owned: false, media_ready: false },
        { key: 'bowls', variant: 'cat_bowls', product_id: 'petprep_wish_bowls', owned: false, media_ready: false },
        { key: 'toy_1', variant: 'cat_mouse', product_id: 'petprep_wish_toy_1', owned: false, media_ready: false },
      ],
    };
    getParentWishes.mockResolvedValue({ ...BODY, wishes: [catWish], pets: [catPet] });
    getProducts.mockResolvedValue([priced('petprep_wish_bed', 5.99), priced('petprep_wish_bowls', 5.99), priced('petprep_wish_toy_1', 5.99), priced('petprep_wish_bundle_welcome', 15.99)]);
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('bundle-20-contents')).toHaveTextContent('Paket za Mici: košara, skledi in plišasta miška');
    expect(screen.getByTestId('bundle-20-separately')).toHaveTextContent('Posamezno skupaj 17,97\u00a0€');
    expect(screen.getByTestId('bundle-buy-20')).toHaveTextContent('Kupi paket za 15,99\u00a0€');
    expect(screen.getByLabelText('Kupi paket »Dobrodošlica« (košara, skledi in plišasta miška) za Mici za 15,99\u00a0€')).toBeTruthy();
    expect(screen.queryByText(/postelja/)).toBeNull();
    expect(screen.queryByText(/Vse tri/)).toBeNull();
  });

  it('bundle section in English names the dog items; no bundle_product_id on the wish → no section', async () => {
    await i18n.changeLanguage('en');
    getParentWishes.mockResolvedValue(BODY);
    getProducts.mockResolvedValue([priced('petprep_wish_bed', 5.99), priced('petprep_wish_bowls', 5.99), priced('petprep_wish_toy_1', 5.99), priced('petprep_wish_bundle_welcome', 15.99)]);
    const view = renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('bundle-section')).toHaveTextContent('Welcome pack');
    expect(screen.getByTestId('bundle-12-contents')).toHaveTextContent('Pack for Suki: bed, bowls and rope tug toy');
    expect(screen.getByTestId('bundle-12-separately')).toHaveTextContent('Separately 17,97\u00a0€ in total');
    expect(screen.queryByText(/All three/)).toBeNull();
    view.unmount();
    getParentWishes.mockResolvedValue({ ...BODY, wishes: [{ ...WISH, bundle_product_id: null }] });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    expect(screen.queryByTestId('bundle-section')).toBeNull();
  });

  it('QA #416 m4: re-checks fresh data before the store sheet; already owned → clear text, nothing charged', async () => {
    getParentWishes.mockResolvedValueOnce({ ...BODY, wishes: [] });
    getProducts.mockResolvedValue([product('petprep_wish_bed', '5,99 €')]);
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('surprise-pet-12'));
    // Meanwhile another parent bought the bed.
    getParentWishes.mockResolvedValue({ ...BODY, wishes: [], pets: [{ ...BODY.pets[0], bundle_eligible: false, items: [{ ...BODY.pets[0].items[0], owned: true }] }] });
    fireEvent.press(screen.getByTestId('surprise-buy-12-bed'));
    expect(await screen.findByText('Ljubljenček to stvar že ima. Nič ni bilo zaračunano.')).toBeTruthy();
    expect(purchaseStoreProduct).not.toHaveBeenCalled();
  });

  it('QA #416 m4: a wish handled by another parent meanwhile → no purchase', async () => {
    getParentWishes.mockResolvedValueOnce(BODY);
    getProducts.mockResolvedValue([product('petprep_wish_bowls', '5,99 €')]);
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    getParentWishes.mockResolvedValue({ ...BODY, wishes: [] });
    fireEvent.press(screen.getByTestId('wish-buy-31'));
    expect(await screen.findByText('To prošnjo je že obravnaval drug starš. Nič ni bilo zaračunano.')).toBeTruthy();
    expect(purchaseStoreProduct).not.toHaveBeenCalled();
  });

  it('QA #416 M2: a purchase still pending from earlier is redeemed for its own pet / wish when the screen opens', async () => {
    const { savePendingPurchase } = jest.requireActual<typeof import('@/modules/wishes/pendingPurchases')>('@/modules/wishes/pendingPurchases');
    await savePendingPurchase({ parentId: 1, petId: 12, wishId: 31, productId: 'petprep_wish_bowls', transactionId: 'tx-old', species: 'dog' });
    getParentWishes.mockResolvedValue(BODY);
    getProducts.mockResolvedValue([]);
    redeemPetItem.mockResolvedValue({ status: 'assigned', items: [], open_count: 0 });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await waitFor(() => expect(redeemPetItem).toHaveBeenCalledWith(12, { product_id: 'petprep_wish_bowls', wish_id: 31 }, 'tx-old', expect.anything()));
  });

  it('a cancelled store purchase never redeems', async () => {
    getParentWishes.mockResolvedValue(BODY);
    getProducts.mockResolvedValue([product('petprep_wish_bowls', '5,99 €')]);
    purchaseStoreProduct.mockRejectedValue({ code: '1', userCancelled: true });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('wish-buy-31'));
    expect(await screen.findByTestId('wishes-message')).toBeTruthy();
    expect(redeemPetItem).not.toHaveBeenCalled();
  });

  it('»Ne zdaj« declines the wish', async () => {
    getParentWishes.mockResolvedValue(BODY);
    getProducts.mockResolvedValue([]);
    declineWish.mockResolvedValue({ wish: { id: 31, status: 'declined' }, open_count: 0 });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('wish-decline-31'));
    expect(await screen.findByText('Prošnja je zavrnjena. Otrok vidi »Mogoče kdaj drugič«.')).toBeTruthy();
    expect(declineWish).toHaveBeenCalledWith(31);
  });

  it('QA #416 m5: »Ne zdaj« on a wish another parent handled → clear text + refetch', async () => {
    getParentWishes.mockResolvedValue(BODY);
    getProducts.mockResolvedValue([]);
    declineWish.mockRejectedValue(new ApiError('x', 422, { reason: 'wish_not_open' }));
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    const before = getParentWishes.mock.calls.length;
    fireEvent.press(screen.getByTestId('wish-decline-31'));
    expect(await screen.findByText('To prošnjo je že obravnaval drug starš.')).toBeTruthy();
    await waitFor(() => expect(getParentWishes.mock.calls.length).toBeGreaterThan(before));
  });

  it('surprise: pick the pet first, then the item list shows', async () => {
    getParentWishes.mockResolvedValue({ ...BODY, wishes: [] });
    getProducts.mockResolvedValue([product('petprep_wish_bed', '5,99 €')]);
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    expect(screen.queryByTestId('surprise-items-12')).toBeNull();
    fireEvent.press(screen.getByTestId('surprise-pet-12'));
    expect(screen.getByTestId('surprise-items-12')).toBeTruthy();
    expect(screen.getByTestId('surprise-buy-12-bed')).not.toBeDisabled();
  });

  it('»Komu?« assigns an unassigned credit without a new purchase', async () => {
    getParentWishes.mockResolvedValue({ ...BODY, wishes: [], unassigned_credits: [{ product_id: 'petprep_wish_bed', count: 1 }] });
    getProducts.mockResolvedValue([]);
    redeemPetItem.mockResolvedValue({ status: 'assigned', items: [], open_count: 0 });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('wishes-assign-petprep_wish_bed'));
    fireEvent.press(screen.getByTestId('wishes-assign-petprep_wish_bed-12'));
    await waitFor(() => expect(redeemPetItem).toHaveBeenCalledWith(12, { product_id: 'petprep_wish_bed', wish_id: null }, null, expect.anything()));
    expect(purchaseStoreProduct).not.toHaveBeenCalled();
  });

  it('old server (404 without reason) → "not available yet", no retry button', async () => {
    getParentWishes.mockRejectedValue(new ApiError('Not Found', 404, { message: 'Not Found' }));
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByText('Želje na strežniku še niso na voljo.')).toBeTruthy();
  });
});

describe('WishesScreen — designs (M6-W03)', () => {
  const BED_DESIGNS = [
    { key: 'bed_round', name: 'Okrogla postelja', image: 'https://api.petprep.si/d/1.png' },
    { key: 'bed_box', name: 'Postelja škatla', image: null },
  ];
  const BOWL_DESIGNS = [
    { key: 'bowls_steel', name: 'Jeklene skledi', image: 'https://api.petprep.si/d/4.png' },
    { key: 'bowls_clay', name: 'Glinene skledi', image: null },
  ];
  const PET = {
    pet_id: 12,
    pet_name: 'Suki',
    species: 'dog',
    bundle_eligible: true,
    items: [
      { key: 'bed', variant: 'dog_bed', product_id: 'petprep_wish_bed', owned: false, media_ready: false, designs: BED_DESIGNS },
      { key: 'bowls', variant: 'dog_bowls', product_id: 'petprep_wish_bowls', owned: false, media_ready: false, designs: BOWL_DESIGNS },
    ],
  };
  const ROPE_DESIGNS = [
    { key: 'rope_red', name: 'Rdeče-bela vrv', image: null },
    { key: 'rope_blue', name: 'Modri obroč', image: null },
  ];
  const DESIGN_WISH = { ...WISH, design: 'bowls_clay' };
  const DESIGN_BODY = { ...BODY, wishes: [DESIGN_WISH], pets: [PET] };

  it('a wish shows the requested design (placeholder without a picture) and buys exactly that design', async () => {
    getParentWishes.mockResolvedValue(DESIGN_BODY);
    getProducts.mockResolvedValue([product('petprep_wish_bowls', '5,99 €'), product('petprep_wish_bundle_welcome', '15,99 €')]);
    purchaseStoreProduct.mockResolvedValue({ productIdentifier: 'petprep_wish_bowls', customerInfo: {}, transaction: { transactionIdentifier: 'tx-d' } });
    redeemPetItem.mockResolvedValue({ status: 'assigned', items: [], open_count: 0 });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('wish-design-name-31')).toHaveTextContent('Želi: Glinene skledi');
    expect(screen.getByText('Kupi za 5,99 €')).toBeTruthy();
    fireEvent.press(screen.getByTestId('wish-buy-31'));
    expect(await screen.findByText('Kupljeno — stvar je dodana.')).toBeTruthy();
    expect(redeemPetItem).toHaveBeenCalledWith(12, { product_id: 'petprep_wish_bowls', wish_id: 31, design: 'bowls_clay' }, 'tx-d', expect.anything());
  });

  it('the bundle from a wish shows all three items, each preselected with the look its open wish asks for', async () => {
    const pet = {
      ...PET,
      items: [...PET.items, { key: 'toy_1', variant: 'dog_rope', product_id: 'petprep_wish_toy_1', owned: false, media_ready: false, designs: ROPE_DESIGNS }],
    };
    const wishes = [
      DESIGN_WISH,
      { ...WISH, id: 32, item_key: 'bed', variant: 'dog_bed', product_id: 'petprep_wish_bed', design: 'bed_box' },
      { ...WISH, id: 33, item_key: 'toy_1', variant: 'dog_rope', product_id: 'petprep_wish_toy_1', design: 'rope_blue' },
    ];
    getParentWishes.mockResolvedValue({ ...BODY, open_count: 3, wishes, pets: [pet] });
    getProducts.mockResolvedValue([product('petprep_wish_bowls', '5,99 €'), product('petprep_wish_bundle_welcome', '15,99 €')]);
    purchaseStoreProduct.mockResolvedValue({ productIdentifier: 'petprep_wish_bundle_welcome', customerInfo: {}, transaction: { transactionIdentifier: 'tx-b' } });
    redeemPetItem.mockResolvedValue({ status: 'assigned', items: [], open_count: 0 });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('bundle-buy-12'));
    expect(purchaseStoreProduct).not.toHaveBeenCalled();
    expect(screen.getByTestId('design-choice-bundle-12')).toBeTruthy();
    // All three — the bowls of the wish it resolves included.
    expect(screen.getByText('Izberite videz: Pasja postelja')).toBeTruthy();
    expect(screen.getByText('Izberite videz: Skledi za hrano in vodo')).toBeTruthy();
    expect(screen.getByText('Izberite videz: Vrv za vlečenje')).toBeTruthy();
    // Preselected = what the child is waiting for, not the first design.
    expect(screen.getByTestId('design-choice-bundle-12-bed-bed_box')).toBeSelected();
    expect(screen.getByTestId('design-choice-bundle-12-bed-bed_round')).not.toBeSelected();
    expect(screen.getByTestId('design-choice-bundle-12-bowls-bowls_clay')).toBeSelected();
    expect(screen.getByTestId('design-choice-bundle-12-toy_1-rope_blue')).toBeSelected();
    // The parent may still change any look.
    fireEvent.press(screen.getByTestId('design-choice-bundle-12-bowls-bowls_steel'));
    fireEvent.press(screen.getByTestId('design-choice-confirm-bundle-12'));
    expect(await screen.findByText('Kupljeno — stvar je dodana.')).toBeTruthy();
    expect(redeemPetItem).toHaveBeenCalledWith(
      12,
      { product_id: 'petprep_wish_bundle_welcome', wish_id: 31, designs: { bed: 'bed_box', bowls: 'bowls_steel', toy_1: 'rope_blue' } },
      'tx-b',
      expect.anything(),
    );
  });

  it('»Kupi brez prošnje« bundle shares the picker: all three, open wishes preselected, the rest first design', async () => {
    getParentWishes.mockResolvedValue(DESIGN_BODY);
    getProducts.mockResolvedValue([product('petprep_wish_bundle_welcome', '15,99 €')]);
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('surprise-pet-12'));
    fireEvent.press(screen.getByTestId('surprise-buy-12-welcome_bundle'));
    expect(screen.getByTestId('design-choice-surprise-12-welcome_bundle-bowls-bowls_clay')).toBeSelected();
    expect(screen.getByTestId('design-choice-surprise-12-welcome_bundle-bed-bed_round')).toBeSelected();
  });

  it('surprise: pet → item → design (first preselected, placeholder card without a picture)', async () => {
    getParentWishes.mockResolvedValue({ ...DESIGN_BODY, wishes: [] });
    getProducts.mockResolvedValue([product('petprep_wish_bed', '5,99 €')]);
    purchaseStoreProduct.mockResolvedValue({ productIdentifier: 'petprep_wish_bed', customerInfo: {}, transaction: { transactionIdentifier: 'tx-s' } });
    redeemPetItem.mockResolvedValue({ status: 'assigned', items: [], open_count: 0 });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('surprise-pet-12'));
    fireEvent.press(screen.getByTestId('surprise-buy-12-bed'));
    expect(purchaseStoreProduct).not.toHaveBeenCalled();
    expect(screen.getByTestId('design-choice-surprise-12-bed-bed-bed_round-image')).toBeTruthy();
    expect(screen.getByTestId('design-choice-surprise-12-bed-bed-bed_box-placeholder')).toBeTruthy();
    fireEvent.press(screen.getByTestId('design-choice-surprise-12-bed-bed-bed_box'));
    fireEvent.press(screen.getByTestId('design-choice-confirm-surprise-12-bed'));
    expect(await screen.findByText('Kupljeno — stvar je dodana.')).toBeTruthy();
    expect(redeemPetItem).toHaveBeenCalledWith(12, { product_id: 'petprep_wish_bed', wish_id: null, design: 'bed_box' }, 'tx-s', expect.anything());
  });

  it('»Komu?« for an item with designs asks for the design, then assigns it', async () => {
    getParentWishes.mockResolvedValue({ ...DESIGN_BODY, wishes: [], unassigned_credits: [{ product_id: 'petprep_wish_bed', count: 1 }] });
    getProducts.mockResolvedValue([]);
    redeemPetItem.mockResolvedValue({ status: 'assigned', items: [], open_count: 0 });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('wishes-assign-petprep_wish_bed'));
    fireEvent.press(screen.getByTestId('wishes-assign-petprep_wish_bed-12'));
    expect(redeemPetItem).not.toHaveBeenCalled();
    fireEvent.press(screen.getByTestId('design-choice-confirm-credit-petprep_wish_bed'));
    await waitFor(() => expect(redeemPetItem).toHaveBeenCalledWith(12, { product_id: 'petprep_wish_bed', wish_id: null, design: 'bed_round' }, null, expect.anything()));
  });
});
