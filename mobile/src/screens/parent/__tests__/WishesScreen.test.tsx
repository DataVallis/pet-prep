/** M6-W05 parent »Želje«: store prices, »Nakup še ni na voljo«, bundle offer, buy → redeem, »Ne zdaj«, »Komu?«, old server. */
import { act, fireEvent, screen } from '@testing-library/react-native';
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
    expect(screen.queryByTestId('wish-bundle-31')).toBeNull();
  });

  it('store prices → »Kupi za …« and »Vse tri za …«; buying redeems with the wish and the transaction id', async () => {
    getParentWishes.mockResolvedValue(BODY);
    getProducts.mockResolvedValue([product('petprep_wish_bowls', '5,99 €'), product('petprep_wish_bundle_welcome', '15,99 €')]);
    purchaseStoreProduct.mockResolvedValue({ productIdentifier: 'petprep_wish_bowls', customerInfo: {}, transaction: { transactionIdentifier: 'tx-9' } });
    redeemPetItem.mockResolvedValue({ status: 'assigned', items: [], open_count: 0 });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByText('Kupi za 5,99 €')).toBeTruthy();
    expect(screen.getByText('Vse tri za 15,99 €')).toBeTruthy();
    fireEvent.press(screen.getByTestId('wish-buy-31'));
    await flush();
    expect(redeemPetItem).toHaveBeenCalledWith(12, { product_id: 'petprep_wish_bowls', wish_id: 31 }, 'tx-9', expect.anything());
    expect(screen.getByText('Kupljeno — stvar je dodana.')).toBeTruthy();
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
    await flush();
    expect(purchaseStoreProduct).not.toHaveBeenCalled();
    expect(screen.getByText('Ljubljenček to stvar že ima. Nič ni bilo zaračunano.')).toBeTruthy();
  });

  it('QA #416 m4: a wish handled by another parent meanwhile → no purchase', async () => {
    getParentWishes.mockResolvedValueOnce(BODY);
    getProducts.mockResolvedValue([product('petprep_wish_bowls', '5,99 €')]);
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    getParentWishes.mockResolvedValue({ ...BODY, wishes: [] });
    fireEvent.press(screen.getByTestId('wish-buy-31'));
    await flush();
    expect(purchaseStoreProduct).not.toHaveBeenCalled();
    expect(screen.getByText('To prošnjo je že obravnaval drug starš. Nič ni bilo zaračunano.')).toBeTruthy();
  });

  it('QA #416 M2: a purchase still pending from earlier is redeemed for its own pet / wish when the screen opens', async () => {
    const { savePendingPurchase } = jest.requireActual<typeof import('@/modules/wishes/pendingPurchases')>('@/modules/wishes/pendingPurchases');
    await savePendingPurchase({ parentId: 1, petId: 12, wishId: 31, productId: 'petprep_wish_bowls', transactionId: 'tx-old', species: 'dog' });
    getParentWishes.mockResolvedValue(BODY);
    getProducts.mockResolvedValue([]);
    redeemPetItem.mockResolvedValue({ status: 'assigned', items: [], open_count: 0 });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    expect(redeemPetItem).toHaveBeenCalledWith(12, { product_id: 'petprep_wish_bowls', wish_id: 31 }, 'tx-old', expect.anything());
  });

  it('a cancelled store purchase never redeems', async () => {
    getParentWishes.mockResolvedValue(BODY);
    getProducts.mockResolvedValue([product('petprep_wish_bowls', '5,99 €')]);
    purchaseStoreProduct.mockRejectedValue({ code: '1', userCancelled: true });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('wish-buy-31'));
    await flush();
    expect(redeemPetItem).not.toHaveBeenCalled();
    expect(screen.getByTestId('wishes-message')).toBeTruthy();
  });

  it('»Ne zdaj« declines the wish', async () => {
    getParentWishes.mockResolvedValue(BODY);
    getProducts.mockResolvedValue([]);
    declineWish.mockResolvedValue({ wish: { id: 31, status: 'declined' }, open_count: 0 });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('wish-decline-31'));
    await flush();
    expect(declineWish).toHaveBeenCalledWith(31);
    expect(screen.getByText('Prošnja je zavrnjena. Otrok vidi »Mogoče kdaj drugič«.')).toBeTruthy();
  });

  it('QA #416 m5: »Ne zdaj« on a wish another parent handled → clear text + refetch', async () => {
    getParentWishes.mockResolvedValue(BODY);
    getProducts.mockResolvedValue([]);
    declineWish.mockRejectedValue(new ApiError('x', 422, { reason: 'wish_not_open' }));
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    const before = getParentWishes.mock.calls.length;
    fireEvent.press(screen.getByTestId('wish-decline-31'));
    await flush();
    expect(screen.getByText('To prošnjo je že obravnaval drug starš.')).toBeTruthy();
    expect(getParentWishes.mock.calls.length).toBeGreaterThan(before);
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
    await flush();
    expect(purchaseStoreProduct).not.toHaveBeenCalled();
    expect(redeemPetItem).toHaveBeenCalledWith(12, { product_id: 'petprep_wish_bed', wish_id: null }, null, expect.anything());
  });

  it('old server (404 without reason) → "not available yet", no retry button', async () => {
    getParentWishes.mockRejectedValue(new ApiError('Not Found', 404, { message: 'Not Found' }));
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByText('Želje na strežniku še niso na voljo.')).toBeTruthy();
  });
});
