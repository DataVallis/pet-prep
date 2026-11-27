/**
 * »What you get« before a purchase (David 2026-11-27, tester feedback: the cat's feather wand was
 * bought thinking the child would play with it). Wish card, »Kupi brez prošnje« and the bundle
 * show what the item really does; an owned item shows none.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';
import Purchases from 'react-native-purchases';

import { api } from '@/api/client';
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
const getProducts = Purchases.getProducts as jest.Mock;

const CAT_WISH = {
  id: 51,
  pet_id: 20,
  pet_name: 'Mici',
  species: 'cat',
  child_id: 7,
  child_name: 'Ana',
  item_key: 'toy_2',
  variant: 'cat_feather',
  product_id: 'petprep_wish_toy_2',
  status: 'open',
  created_at: '2026-11-27T08:00:00Z',
  bundle_product_id: null,
};
const item = (key: string, variant: string, owned = false) => ({ key, variant, product_id: `petprep_wish_${key === 'treats' ? 'treats_10' : key}`, owned, media_ready: owned });
const CAT_PET = {
  pet_id: 20,
  pet_name: 'Mici',
  species: 'cat',
  bundle_eligible: true,
  bundle_product_id: 'petprep_wish_bundle_welcome',
  items: [item('bed', 'cat_basket', true), item('toy_2', 'cat_feather'), item('treats', 'cat_treats')],
};
const DOG_PET = { pet_id: 12, pet_name: 'Suki', species: 'dog', bundle_eligible: true, bundle_product_id: 'petprep_wish_bundle_welcome', items: [item('toy_1', 'dog_rope')] };

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
  getProducts.mockResolvedValue([]);
});

describe('WishesScreen — what you get', () => {
  it('the cat feather wand wish says the cat plays with it by itself and the child’s wand game works the same without it', async () => {
    getParentWishes.mockResolvedValue({ open_count: 1, wishes: [CAT_WISH], pets: [CAT_PET], unassigned_credits: [] });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    const about = screen.getByTestId('wish-about-51');
    expect(about).toHaveTextContent(/Muca se s to palico igra sama, ko je razigrana/);
    expect(about).toHaveTextContent(/vsakodnevna igra »Palica s peresom« deluje enako tudi brez nakupa/);
  });

  it('»Kupi brez prošnje«: a description per item to buy, none for an owned one; the bundle per species', async () => {
    getParentWishes.mockResolvedValue({ open_count: 0, wishes: [], pets: [CAT_PET, DOG_PET], unassigned_credits: [] });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('surprise-pet-20'));
    expect(screen.queryByTestId('surprise-about-20-bed')).toBeNull();
    expect(screen.getByTestId('surprise-about-20-toy_2')).toHaveTextContent(/Ni palica za otroka/);
    expect(screen.getByTestId('surprise-about-20-treats')).toHaveTextContent('10 priboljškov, ki jih otrok podari z gumbom »Priboljšek« — največ 2 na dan, ne med spanjem. Muca ga poje; lakote in ocene skrbi to ne spremeni.');
    expect(screen.getByTestId('surprise-bundle-20-about')).toHaveTextContent(/Muco vidite v novi košari/);
    fireEvent.press(screen.getByTestId('surprise-pet-12'));
    expect(screen.getByTestId('surprise-about-12-toy_1')).toHaveTextContent('Kuža se z vrvjo igra sam, ko je razigran — tako ga vidite v aplikaciji. Otrokova igra z žogo deluje enako tudi brez nje.');
    expect(screen.getByTestId('surprise-bundle-12-about')).toHaveTextContent(/Kužka vidite v novi postelji/);
  });

  it('English', async () => {
    await i18n.changeLanguage('en');
    getParentWishes.mockResolvedValue({ open_count: 1, wishes: [CAT_WISH], pets: [CAT_PET], unassigned_credits: [] });
    renderWithQuery(<WishesScreen family={null} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('wish-about-51')).toHaveTextContent(/It is not a wand for your child: the daily "Feather wand" game works the same without buying it\./);
  });
});
