/**
 * M5-R06-09 — payments for a cat: the paywall, the banner, the purchase entry and the
 * paid-challenge deletion warning follow the species of the pets they talk about. A dog
 * family keeps exactly the dog texts; a cat → "muca" / "cat"; dogs + cats → neutral
 * "ljubljenček" / "pet". Parents are addressed formally ("vi").
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';

import { ApiError, api, type ParentDashboardResponse } from '@/api/client';
import { i18n } from '@/i18n';
import ChallengeBanner from '@/components/parent/ChallengeBanner';
import ChallengeBuyButton from '@/components/parent/ChallengeBuyButton';
import DeletionConfirmForm from '@/components/parent/DeletionConfirmForm';
import { purchasesRowSubtitle } from '@/components/parent/PurchasesRow';
import { paidDeletionGroup } from '@/modules/account/account';
import { familyFromDashboard } from '@/modules/family/family';
import { petGroup } from '@/modules/species/species';
import ChallengeScreen from '@/screens/parent/ChallengeScreen';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { useAppStore } from '@/store/appStore';

const mockPurchase = jest.fn();
const mockRestore = jest.fn();

jest.mock('@/modules/purchases/usePurchases', () => {
  const actual = jest.requireActual<typeof import('@/modules/purchases/usePurchases')>('@/modules/purchases/usePurchases');
  return {
    ...actual,
    useOfferings: () => ({
      data: { current: { availablePackages: [{ product: { identifier: 'petprep_challenge_12w', priceString: '49,99 €' } }] } },
      isPending: false,
      refetch: jest.fn(),
    }),
    usePurchasePackage: () => ({ mutate: mockPurchase, isPending: false }),
    useRestorePurchases: () => ({ mutate: mockRestore, isPending: false }),
  };
});

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getBilling: jest.fn(), activateChallenge: jest.fn() } };
});

const getBilling = api.getBilling as jest.Mock;
const activateChallenge = api.activateChallenge as jest.Mock;

const WAITING = { type: 'challenge', status: 'payment_required', trial_ends_at: null, paid_at: null, payments_enforced: true, display_type: 'challenge' };
const PAID = { type: 'challenge', status: 'paid', trial_ends_at: null, paid_at: '2026-10-01T10:00:00+02:00', payments_enforced: true, display_type: 'challenge' };

type PetSpec = { id: number; species: 'dog' | 'cat'; breed: string; plan?: Record<string, unknown>; born?: boolean };

function family(pets: PetSpec[]) {
  const dashboard = makeScoredDashboard(
    [makeScoredChild()],
    pets.map((p) =>
      makeFamilyPet({
        id: p.id,
        species: p.species,
        breed_type: p.breed,
        caretakers: [{ child_id: 2, contract_signed: true }],
        plan: p.plan ?? WAITING,
        ...(p.born === false ? { born_at: null } : {}),
      } as never),
    ),
  );
  const overview = familyFromDashboard(dashboard as unknown as ParentDashboardResponse);
  if (!overview) throw new Error('family');
  return overview;
}

const billingPet = (petId: number, status: 'payment_required' | 'paid' = 'payment_required', extra: Record<string, unknown> = {}) => ({
  pet_id: petId,
  plan: 'challenge',
  status,
  trial_ends_at: null,
  paid_at: null,
  trial_available: false,
  deletion_loses_purchase: false,
  ...extra,
});

const MAINE_COON: PetSpec = { id: 7, species: 'cat', breed: 'maine_coon' };
const COLLIE: PetSpec = { id: 8, species: 'dog', breed: 'border_collie' };

async function flush() {
  for (let i = 0; i < 3; i += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

beforeEach(() => {
  jest.clearAllMocks();
  useAppStore.setState({ authToken: 'tok', bootStatus: 'ready', user: { id: 1, name: 'Starš', email: 'p@x.si', role: 'parent' } as never });
});

afterEach(async () => {
  await i18n.changeLanguage('sl');
});

describe('petGroup', () => {
  it('no cat → dog, only cats → cat, both → mixed', () => {
    expect(petGroup([])).toBe('dog');
    expect(petGroup([{ species: 'dog' }, { breed_type: 'mutt' }])).toBe('dog');
    expect(petGroup([{ species: 'cat' }, { breed_type: 'domestic_cat' }])).toBe('cat');
    expect(petGroup([{ species: 'cat' }, { species: 'dog' }])).toBe('mixed');
  });
});

describe('ChallengeScreen for a cat (M5-R06-09)', () => {
  it('a waiting Maine Coon: the whole screen speaks about the cat', async () => {
    getBilling.mockResolvedValue({ credits_available: 0, pets: [billingPet(7)] });
    renderWithQuery(<ChallengeScreen family={family([MAINE_COON])} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByText('Izziv v 12 tednih pokaže, ali je otrok pripravljen na pravo mačko. En nakup velja za eno muco.')).toBeTruthy();
    expect(screen.getByText('Personalizirana AI muca z videom za vsa stanja')).toBeTruthy();
    expect(screen.getByText(/^Enkratni nakup 49,99 € za eno muco\./)).toBeTruthy();
    expect(screen.getByText('Muce, ki čakajo na izziv')).toBeTruthy();
    expect(screen.getByTestId('challenge-pet-7')).toHaveTextContent(/Igra je ustavljena\. Muca čaka na varnem, napredek ostane\./);
    expect(screen.getByTestId('challenge-screen')).not.toHaveTextContent(/\b(psa|pes|psi|psov|mešanček)\b/i);
  });

  it('an unborn cat: "Še ni rojena … muca zaživi"', async () => {
    getBilling.mockResolvedValue({ credits_available: 0, pets: [billingPet(7)] });
    renderWithQuery(<ChallengeScreen family={family([{ ...MAINE_COON, born: false }])} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('challenge-pet-7')).toHaveTextContent(/Še ni rojena\. Kupite izziv zdaj in muca zaživi/);
  });

  it('422 refused names the cat', async () => {
    getBilling.mockResolvedValue({ credits_available: 1, pets: [billingPet(7)] });
    activateChallenge.mockRejectedValueOnce(new ApiError('x', 422, { reason: 'not_needed' }));
    renderWithQuery(<ChallengeScreen family={family([MAINE_COON])} onBack={jest.fn()} />);
    await flush();
    await act(async () => {
      fireEvent.press(screen.getByTestId('challenge-use-credit-7'));
    });
    await flush();
    expect(screen.getByTestId('challenge-message')).toHaveTextContent('Ta muca izziva ne potrebuje (brezplačni načrt ali je že plačan).');

    fireEvent.press(screen.getByTestId('challenge-restore'));
    await act(async () => {
      mockRestore.mock.calls[0][1].onSuccess({ status: 'restored' });
    });
    expect(screen.getByTestId('challenge-message')).toBeTruthy();
  });

  it('all paid (cats only) → "Nobena muca ne čaka na nakup"', async () => {
    getBilling.mockResolvedValue({ credits_available: 0, pets: [billingPet(7, 'paid')] });
    renderWithQuery(<ChallengeScreen family={family([{ ...MAINE_COON, plan: PAID }])} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('challenge-all-paid')).toHaveTextContent('Nobena muca ne čaka na nakup. Vsi izzivi so odklenjeni.');
  });

  it('a dog family keeps exactly the dog texts', async () => {
    getBilling.mockResolvedValue({ credits_available: 0, pets: [billingPet(8)] });
    renderWithQuery(<ChallengeScreen family={family([COLLIE])} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByText(i18n.t('paywall:challenge.intro'))).toBeTruthy();
    expect(screen.getByText('Psi, ki čakajo na izziv')).toBeTruthy();
    expect(screen.getByText(i18n.t('paywall:challenge.paused'))).toBeTruthy();
  });

  it('a dog and a cat waiting: neutral screen texts, each pet its own species', async () => {
    getBilling.mockResolvedValue({ credits_available: 0, pets: [billingPet(7), billingPet(8)] });
    renderWithQuery(<ChallengeScreen family={family([MAINE_COON, COLLIE])} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByText('Ljubljenčki, ki čakajo na izziv')).toBeTruthy();
    expect(screen.getByText(/Brezplačni pasmi \(mešanček in domača mačka\) sta brezplačni za vedno\./)).toBeTruthy();
    expect(screen.getByTestId('challenge-pet-7')).toHaveTextContent(/Muca čaka na varnem/);
    expect(screen.getByTestId('challenge-pet-8')).toHaveTextContent(/Pes čaka na varnem/);
  });

  it('renders the cat texts in English ("the cat", "Cats waiting …")', async () => {
    await act(async () => {
      await i18n.changeLanguage('en');
    });
    getBilling.mockResolvedValue({ credits_available: 0, pets: [billingPet(7)] });
    renderWithQuery(<ChallengeScreen family={family([MAINE_COON])} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByText('Cats waiting for the challenge')).toBeTruthy();
    expect(screen.getByText('The game is paused. The cat is safe and progress is kept.')).toBeTruthy();
    expect(screen.getByTestId('challenge-screen')).not.toHaveTextContent(/\b(dog|dogs|puppy|mixed breed)\b/i);
  });
});

describe('banner, purchase entry and deletion for a cat (M5-R06-09)', () => {
  it('banner: "muca čaka na varnem"; dogs + cats → "vsak ljubljenček"; a dog → the dog text', () => {
    const { unmount } = render(<ChallengeBanner family={family([MAINE_COON])} onOpen={jest.fn()} />);
    expect(screen.getByTestId('challenge-banner-paused')).toHaveTextContent(/Do takrat muca čaka na varnem/);
    unmount();
    const mixed = render(<ChallengeBanner family={family([MAINE_COON, COLLIE])} onOpen={jest.fn()} />);
    expect(screen.getByTestId('challenge-banner-paused')).toHaveTextContent(/Do takrat vsak ljubljenček čaka na varnem/);
    mixed.unmount();
    render(<ChallengeBanner family={family([COLLIE])} onOpen={jest.fn()} />);
    expect(screen.getByTestId('challenge-banner-paused')).toHaveTextContent(/Do takrat pes čaka na varnem/);
  });

  it('Nadzor row: cats, dogs + cats, and all paid', () => {
    expect(purchasesRowSubtitle(family([MAINE_COON]))).toBe('1 muca čaka na nakup');
    expect(purchasesRowSubtitle(family([MAINE_COON, { ...MAINE_COON, id: 9 }]))).toBe('2 muci čakata na nakup');
    expect(purchasesRowSubtitle(family([MAINE_COON, COLLIE]))).toBe('2 ljubljenčka čakata na nakup');
    expect(purchasesRowSubtitle(family([COLLIE]))).toBe('1 pes čaka na nakup');
    expect(purchasesRowSubtitle(family([{ ...MAINE_COON, plan: PAID }]))).toBe(
      'Nobena muca ne čaka na nakup. Tukaj lahko obnovite tudi nakupe na novem telefonu.',
    );
  });

  it('buy button: the screen reader names the cat', () => {
    render(<ChallengeBuyButton onPress={jest.fn()} childName="Mia" species="cat" testID="buy" />);
    expect(screen.getByTestId('buy').props.accessibilityLabel).toBe('Kupite 12-tedenski izziv za muco, za katero skrbi Mia');
    render(<ChallengeBuyButton onPress={jest.fn()} childName="Mia" species="dog" testID="buy-dog" />);
    expect(screen.getByTestId('buy-dog').props.accessibilityLabel).toBe('Kupite 12-tedenski izziv za psa, za katerega skrbi Mia');
  });

  it('deletion warning follows the removed pets whose purchase is lost', () => {
    const f = family([MAINE_COON, COLLIE]);
    const billing = { pets: [billingPet(7, 'paid', { deletion_loses_purchase: true }), billingPet(8, 'paid')] };
    expect(paidDeletionGroup([7, 8], f, billing)).toBe('cat'); // only the cat's purchase is lost
    expect(paidDeletionGroup([8], f, billing)).toBe('dog'); // stale billing → the removed pets
    expect(paidDeletionGroup([7, 8], f, { pets: [] })).toBe('mixed');
    expect(paidDeletionGroup([], null, undefined)).toBe('dog');

    render(
      <DeletionConfirmForm consequences={[]} submitLabel="Izbriši" pending={false} error={null} onCancel={jest.fn()} onSubmit={jest.fn()} paidChallenge paidPetGroup="cat" testID="del" />,
    );
    expect(screen.getByTestId('del-paid-warning')).toHaveTextContent(
      'Za to muco je bil kupljen 12-tedenski izziv. Če jo izbrišete, je nakup porabljen — za novo muco boste morali izziv kupiti znova.',
    );
  });

  it('deletion warning without a species keeps the dog text', () => {
    render(<DeletionConfirmForm consequences={[]} submitLabel="Izbriši" pending={false} error={null} onCancel={jest.fn()} onSubmit={jest.fn()} paidChallenge testID="del" />);
    expect(screen.getByTestId('del-paid-warning')).toHaveTextContent(i18n.t('account:deletionForm.paidWarning'));
  });
});
