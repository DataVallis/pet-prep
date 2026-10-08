/**
 * M3-09 paywall + plan UI (PAYMENTS_SPEC P1–P7): billing states, buy / use credit /
 * restore outcomes, banners, badges, English rendering.
 */
import { act, fireEvent, screen, render } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import { i18n } from '@/i18n';
import ChallengeBanner from '@/components/parent/ChallengeBanner';
import PlanBadge from '@/components/parent/PlanBadge';
import { familyFromDashboard } from '@/modules/family/family';
import { needsPurchase, planBadge, planBanner, readPetPlan, shownAsFree, trialDaysLeft } from '@/modules/plan/plan';
import ChallengeScreen from '@/screens/parent/ChallengeScreen';
import { challengePackage } from '@/modules/purchases';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import type { ParentDashboardResponse } from '@/api/client';
import { useAppStore } from '@/store/appStore';

const mockPurchase = jest.fn();
const mockRestore = jest.fn();
let mockPackage: { product: { identifier: string; priceString: string } } | null = null;

jest.mock('@/modules/purchases/usePurchases', () => {
  const actual = jest.requireActual<typeof import('@/modules/purchases/usePurchases')>('@/modules/purchases/usePurchases');
  return {
    ...actual,
    useOfferings: () => ({
      data: mockPackage ? { current: { availablePackages: [mockPackage] } } : undefined,
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

const NOW = Date.parse('2026-10-10T10:00:00Z');

const trialPlan = (endsInHours: number) => ({
  type: 'challenge',
  status: 'trial',
  trial_ends_at: new Date(NOW + endsInHours * 3_600_000).toISOString(),
  paid_at: null,
});
const billingPet = (status: 'trial' | 'payment_required' | 'paid', extra: Record<string, unknown> = {}) => ({
  pet_id: 7,
  plan: 'challenge',
  status,
  trial_ends_at: '2026-10-14T12:00:00+02:00',
  paid_at: null,
  trial_available: true,
  deletion_loses_purchase: false,
  ...extra,
});

function family(plan: Record<string, unknown>, petExtra: Record<string, unknown> = {}) {
  const dashboard = makeScoredDashboard(
    [makeScoredChild()],
    [makeFamilyPet({ id: 7, breed_type: 'border_collie', caretakers: [{ child_id: 2, contract_signed: true }], plan, ...petExtra } as never)],
  );
  return familyFromDashboard(dashboard as unknown as ParentDashboardResponse);
}

async function flush() {
  for (let i = 0; i < 3; i += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

beforeEach(() => {
  jest.clearAllMocks();
  // Billing is a parent-only query.
  useAppStore.setState({ authToken: 'tok', bootStatus: 'ready', user: { id: 1, name: 'Starš', email: 'p@x.si', role: 'parent' } as never });
  mockPackage = { product: { identifier: 'petprep_challenge_12w', priceString: '49,99 €' } };
});

afterEach(async () => {
  await i18n.changeLanguage('sl');
});

describe('plan helpers', () => {
  it('reads plans safely; an old server (no plan) is a paid challenge — nothing locked', () => {
    expect(readPetPlan(undefined)).toEqual({ type: 'challenge', status: 'paid', trial_ends_at: null, paid_at: null });
    expect(readPetPlan({ type: 'free', status: 'trial', trial_ends_at: 'x' })).toEqual({
      type: 'free',
      status: null,
      trial_ends_at: null,
      paid_at: null,
      display_type: 'free',
    });
  });

  it('M5-F02: a grandfathered mutt (display_type free) is "Brezplačno", never "Plačano"; its real plan stays', () => {
    const grandfathered = readPetPlan({ type: 'challenge', status: 'paid', paid_at: '2026-10-01T10:00:00+02:00', display_type: 'free' });
    expect(grandfathered).toMatchObject({ type: 'challenge', status: 'paid', display_type: 'free' });
    expect(shownAsFree(grandfathered)).toBe(true);
    expect(planBadge(grandfathered, NOW)).toEqual({ tone: 'neutral', label: 'Brezplačno' });
    // Nothing to buy: not a purchase candidate, no banner.
    expect(needsPurchase(grandfathered)).toBe(false);
    expect(planBanner(grandfathered, NOW)).toBeNull();

    // A purchased (or premium) challenge stays "Plačano"; an older server without display_type too.
    expect(planBadge(readPetPlan({ type: 'challenge', status: 'paid', display_type: 'challenge' }), NOW).label).toBe('Plačano');
    expect(planBadge(readPetPlan({ type: 'challenge', status: 'paid' }), NOW).label).toBe('Plačano');
    // Unknown display_type → the real type.
    expect(readPetPlan({ type: 'challenge', status: 'paid', display_type: 'gold' }).display_type).toBe('challenge');
    // A free pet is always shown free.
    expect(readPetPlan({ type: 'free', display_type: 'challenge' }).display_type).toBe('free');
  });

  it('M5-F02: the badge of a grandfathered mutt reads "Brezplačno" with its accessible label', () => {
    render(<PlanBadge plan={readPetPlan({ type: 'challenge', status: 'paid', display_type: 'free' })} testID="badge" />);
    expect(screen.getByTestId('badge').props.accessibilityLabel).toBe('Načrt: Brezplačno');
  });

  it('a dog that still runs a pre-M3-13 trial: countdown to the pause, never the word "preizkus" (M3-13)', () => {
    const plan = readPetPlan(trialPlan(3 * 24 + 1));
    expect(trialDaysLeft(plan, NOW)).toBe(4);
    expect(planBadge(plan, NOW)).toEqual({ tone: 'info', label: 'Ni kupljeno: ustavi se čez 4 dni' });
    expect(planBanner(plan, NOW)).toBeNull();
    expect(planBanner(readPetPlan(trialPlan(5)), NOW)).toBe('pause_soon');
    expect(planBadge(readPetPlan(trialPlan(5)), NOW)).toEqual({ tone: 'warn', label: 'Ni kupljeno: ustavi se v 24 urah' });
    expect(planBadge(readPetPlan({ type: 'free' }), NOW).label).toBe('Brezplačno');
  });

  it('a new challenge dog waits for the purchase from the start (born or not)', () => {
    const waiting = readPetPlan({ type: 'challenge', status: 'payment_required', trial_ends_at: null });
    expect(needsPurchase(waiting)).toBe(true);
    expect(planBanner(waiting, NOW)).toBe('payment_required');
    expect(planBadge(waiting, NOW)).toEqual({ tone: 'danger', label: 'Čaka na nakup' });
  });

  it('no badge or banner text mentions a trial, in Slovenian or English', async () => {
    const plans = [trialPlan(80), trialPlan(5), { type: 'challenge', status: 'payment_required' }, { type: 'challenge', status: null }];
    for (const lang of ['sl', 'en']) {
      await i18n.changeLanguage(lang);
      for (const raw of plans) {
        expect(planBadge(readPetPlan(raw), NOW).label).not.toMatch(/preizkus|trial/i);
      }
      const bannerTexts = [
        i18n.t('paywall:banner.paymentRequiredTitle'),
        i18n.t('paywall:banner.paymentRequired', { names: 'Luka' }),
        i18n.t('paywall:banner.pauseSoonTitle'),
        i18n.t('paywall:banner.pauseSoon', { names: 'Luka' }),
      ];
      for (const text of bannerTexts) {
        expect(text).not.toMatch(/preizkus|trial/i);
      }
      expect(i18n.t('paywall:challenge.honest', { price: '49,99 €' })).not.toMatch(/preizkus|trial|7/i);
      expect(i18n.t('pet:picker.plans.challenge.title', { price: '49,99 €' })).not.toMatch(/preizkus|trial|7 d/i);
      expect(i18n.t('pet:picker.plans.challenge.hint')).not.toMatch(/preizkus|trial|7 d/i);
    }
  });

  it('kill switch: with payments not enforced an unpaid challenge shows no countdown or banner', () => {
    const plan = readPetPlan({ ...trialPlan(-5), payments_enforced: false });
    expect(plan).toEqual({ type: 'challenge', status: 'trial', trial_ends_at: null, paid_at: null, display_type: 'challenge' });
    expect(planBanner(plan, NOW)).toBeNull();
    expect(planBadge(plan, NOW).label).toBe('Izziv še ni kupljen');
  });

  it('only the exact challenge product is ever bought (never another package)', () => {
    const other = { product: { identifier: 'petprep_tokens_10', priceString: '4,99 €' } };
    expect(challengePackage({ current: { availablePackages: [other] } } as never)).toBeNull();
    expect(challengePackage({ current: { availablePackages: [other, mockPackage] } } as never)).toBe(mockPackage);
  });

  it('a pressable badge opens the paywall', () => {
    const onPress = jest.fn();
    render(<PlanBadge plan={readPetPlan(trialPlan(48))} onPress={onPress} testID="badge" />);
    fireEvent.press(screen.getByTestId('badge'));
    expect(onPress).toHaveBeenCalled();
  });

  it('the badge renders with an accessible label', () => {
    render(<PlanBadge plan={readPetPlan({ type: 'challenge', status: 'paid' })} testID="badge" />);
    expect(screen.getByTestId('badge').props.accessibilityLabel).toBe('Načrt: Plačano');
  });
});

describe('ChallengeBanner', () => {
  it('paused game wins and opens the paywall', () => {
    const onOpen = jest.fn();
    render(<ChallengeBanner family={family({ type: 'challenge', status: 'payment_required' })} onOpen={onOpen} />);
    expect(screen.getByTestId('challenge-banner-paused')).toHaveTextContent(/Luka/);
    fireEvent.press(screen.getByTestId('challenge-banner-open'));
    expect(onOpen).toHaveBeenCalled();
  });

  it('an unborn challenge dog waiting for the purchase gets the "waiting" banner (M3-13)', () => {
    render(
      <ChallengeBanner family={family({ type: 'challenge', status: 'payment_required' }, { born_at: null })} onOpen={jest.fn()} />,
    );
    expect(screen.getByTestId('challenge-banner-paused')).toHaveTextContent(/Čaka na nakup/);
    expect(screen.getByTestId('challenge-banner-paused')).not.toHaveTextContent(/preizkus/i);
  });

  it('nothing for a paid or free dog', () => {
    const { toJSON } = render(<ChallengeBanner family={family({ type: 'free' })} onOpen={jest.fn()} />);
    expect(toJSON()).toBeNull();
  });
});

describe('ChallengeScreen', () => {
  it('lists the dog on trial with the store price; buying targets that dog', async () => {
    getBilling.mockResolvedValue({ credits_available: 0, pets: [billingPet('trial')] });
    renderWithQuery(<ChallengeScreen family={family(trialPlan(48))} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('challenge-pet-7')).toBeTruthy();
    expect(screen.getByText('Kupi za 49,99 €')).toBeTruthy();

    fireEvent.press(screen.getByTestId('challenge-buy-7'));
    expect(mockPurchase).toHaveBeenCalledWith(
      { pkg: mockPackage, target: { petId: 7, baselineCredits: 0 } },
      expect.objectContaining({ onSuccess: expect.any(Function) }),
    );
    await act(async () => {
      mockPurchase.mock.calls[0][1].onSuccess({ status: 'pending' });
    });
    expect(screen.getByTestId('challenge-message')).toHaveTextContent('Nakup čaka na odobritev. Odklene se takoj, ko je odobren.');
  });

  it('a paused dog says the game is paused; without an offering the buy button is disabled', async () => {
    mockPackage = null;
    getBilling.mockResolvedValue({ credits_available: 0, pets: [billingPet('payment_required')] });
    renderWithQuery(<ChallengeScreen family={family({ type: 'challenge', status: 'payment_required' })} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('challenge-pet-7')).toHaveTextContent(/Igra je ustavljena|ustavljena/);
    expect(screen.getByTestId('challenge-buy-7').props.accessibilityState).toEqual(expect.objectContaining({ disabled: true }));
    expect(screen.getByTestId('challenge-store-state')).toBeTruthy();
  });

  it('an unused credit is used without buying; 409 no_credit is explained', async () => {
    getBilling.mockResolvedValue({ credits_available: 1, pets: [billingPet('payment_required')] });
    activateChallenge.mockRejectedValueOnce(new ApiError('no credit', 409, { reason: 'no_credit' }));
    renderWithQuery(<ChallengeScreen family={family({ type: 'challenge', status: 'payment_required' })} onBack={jest.fn()} />);
    await flush();
    await act(async () => {
      fireEvent.press(screen.getByTestId('challenge-use-credit-7'));
    });
    await flush();
    expect(activateChallenge).toHaveBeenCalledWith(7);
    expect(mockPurchase).not.toHaveBeenCalled();
    expect(screen.getByTestId('challenge-message')).toBeTruthy();
  });

  it('M3-13: an unborn dog is listed to buy first — it starts once the contract is signed', async () => {
    getBilling.mockResolvedValue({ credits_available: 0, pets: [billingPet('payment_required', { trial_ends_at: null, trial_available: false })] });
    renderWithQuery(
      <ChallengeScreen family={family({ type: 'challenge', status: 'payment_required' }, { born_at: null })} onBack={jest.fn()} />,
    );
    await flush();
    expect(screen.getByTestId('challenge-pet-7')).toHaveTextContent(/Še ni rojen/);
    expect(screen.getByTestId('challenge-pet-7')).not.toHaveTextContent(/ustavljena|preizkus/);
    expect(screen.getByTestId('challenge-buy-7')).toBeTruthy();
  });

  it('kill switch off: an unborn unpaid dog (status trial) just reads "not bought yet"', async () => {
    getBilling.mockResolvedValue({ credits_available: 0, pets: [billingPet('trial', { trial_ends_at: null, trial_available: null })] });
    renderWithQuery(<ChallengeScreen family={family({ type: 'challenge', status: 'trial' }, { born_at: null })} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('challenge-pet-7')).toHaveTextContent(/Izziv še ni kupljen/);
    expect(screen.getByTestId('challenge-pet-7')).not.toHaveTextContent(/Še ni rojen|ustavljena/);
  });

  it('a dog that still runs a pre-M3-13 trial shows when the game pauses (no "preizkus")', async () => {
    getBilling.mockResolvedValue({ credits_available: 0, pets: [billingPet('trial')] });
    renderWithQuery(<ChallengeScreen family={family(trialPlan(48))} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('challenge-pet-7')).toHaveTextContent(/Brez nakupa se igra ustavi/);
    expect(screen.getByTestId('challenge-pet-7')).not.toHaveTextContent(/preizkus/i);
  });

  it('restore never assigns a credit by itself; the parent chooses the dog', async () => {
    getBilling.mockResolvedValue({ credits_available: 0, pets: [billingPet('trial'), billingPet('trial', { pet_id: 8 })] });
    renderWithQuery(<ChallengeScreen family={family(trialPlan(48))} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('challenge-restore'));
    await act(async () => {
      mockRestore.mock.calls[0][1].onSuccess({ status: 'restored' });
    });
    expect(activateChallenge).not.toHaveBeenCalled();
    expect(screen.getByTestId('challenge-message')).toBeTruthy();
  });

  it('all paid → nothing to buy', async () => {
    getBilling.mockResolvedValue({ credits_available: 0, pets: [billingPet('paid')] });
    renderWithQuery(<ChallengeScreen family={family({ type: 'challenge', status: 'paid' })} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByTestId('challenge-all-paid')).toBeTruthy();
    expect(screen.queryByTestId('challenge-buy-7')).toBeNull();
  });

  it('renders in English', async () => {
    await act(async () => {
      await i18n.changeLanguage('en');
    });
    getBilling.mockResolvedValue({ credits_available: 0, pets: [billingPet('trial')] });
    renderWithQuery(<ChallengeScreen family={family(trialPlan(48))} onBack={jest.fn()} />);
    await flush();
    expect(screen.getByText('12-week challenge')).toBeTruthy();
    expect(screen.getByText('Buy for 49,99 €')).toBeTruthy();
    expect(screen.getByText('Restore purchases')).toBeTruthy();
  });
});
