/**
 * M6-U01 (FREE_PET_UPGRADE_SPEC §4 / §5.3, David 2026-11-10) — upgrade an existing free pet
 * (mutt / domestic cat) to the 12-week challenge: readers, the shared purchase rule, the
 * »Začni 12-tedenski izziv z [ime]« entry, the legacy note (D3), the paywall's upgrade copy,
 * the unused-purchase prompt, the child's one-time message and the »pred izzivom« day label.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import type { ParentDashboardResponse } from '@/api/client';
import { i18n } from '@/i18n';
import ChallengeUpgradeEntry, { slovenianWithS, upgradeButtonLabel } from '@/components/parent/ChallengeUpgradeEntry';
import UnusedPurchasePrompt from '@/components/parent/UnusedPurchasePrompt';
import { familyFromDashboard, type FamilyOverview } from '@/modules/family/family';
import { readDayRows } from '@/modules/family/scoring';
import { challengeStartedAt, readPetPlan, readPetUpgrade } from '@/modules/plan/plan';
import {
  canBuyChallenge,
  canUpgradePet,
  isBillingPetUpgradeable,
  isOfferablePet,
  showUnusedPurchasePrompt,
  upgradeNote,
} from '@/modules/plan/purchaseEntry';
import {
  challengeStartedMessage,
  markChallengeStartAnnounced,
  setChallengeStartedStoreForTests,
  shouldAnnounceChallengeStart,
  wasChallengeStartAnnounced,
} from '@/modules/plan/challengeStarted';
import { profileSheetRows } from '@/modules/petProfile/profileSheet';
import { readPetProfile } from '@/modules/petProfile/petProfile';
import { readBilling } from '@/modules/purchases/billing';
import ChallengeScreen from '@/screens/parent/ChallengeScreen';
import { isPreChallenge } from '@/screens/parent/ChildDetailScreen';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { useAppStore } from '@/store/appStore';

const mockPurchase = jest.fn();
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
    useRestorePurchases: () => ({ mutate: jest.fn(), isPending: false }),
  };
});

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getBilling: jest.fn(), activateChallenge: jest.fn() } };
});

const getBilling = api.getBilling as jest.Mock;
const activateChallenge = api.activateChallenge as jest.Mock;

const FREE_PLAN = { type: 'free', status: null, trial_ends_at: null, paid_at: null, payments_enforced: true, display_type: 'free' };
const UPGRADEABLE = { available: true, reason: null };
const LEGACY = { available: false, reason: 'legacy_profile' };

function familyWith(petExtra: Record<string, unknown>, breed = 'mutt'): FamilyOverview {
  const dashboard = makeScoredDashboard(
    [makeScoredChild()],
    [makeFamilyPet({ id: 7, breed_type: breed, name: 'Luna', caretakers: [{ child_id: 2, contract_signed: true }], plan: FREE_PLAN, ...petExtra } as never)],
  );
  return familyFromDashboard(dashboard as unknown as ParentDashboardResponse) as FamilyOverview;
}

const billingFree = (upgrade: unknown, credits = 0) =>
  readBilling({
    credits_available: credits,
    pets: [{ pet_id: 7, plan: 'free', status: null, trial_ends_at: null, paid_at: null, trial_available: null, deletion_loses_purchase: false, challenge_started_at: null, upgrade }],
  });

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
  mockPackage = { product: { identifier: 'petprep_challenge_12w', priceString: '49,99 €' } };
});

afterEach(async () => {
  await i18n.changeLanguage('sl');
});

describe('readers', () => {
  it('reads the server upgrade flag; an older server / a challenge pet → null', () => {
    expect(readPetUpgrade(UPGRADEABLE)).toEqual({ available: true, reason: null });
    expect(readPetUpgrade(LEGACY)).toEqual({ available: false, reason: 'legacy_profile' });
    expect(readPetUpgrade({ available: false, reason: 'whatever' })).toEqual({ available: false, reason: null });
    expect(readPetUpgrade(undefined)).toBeNull();
    expect(readPetUpgrade({ reason: 'legacy_profile' })).toBeNull();
    expect(familyWith({}).pets[0].upgrade).toBeNull(); // older server: nothing offered
  });

  it('carries challenge_started_at only on an upgraded challenge plan (other plans unchanged)', () => {
    const upgraded = readPetPlan({ type: 'challenge', status: 'paid', paid_at: 'p', challenge_started_at: '2026-10-07T12:00:00+02:00' });
    expect(challengeStartedAt(upgraded)).toBe('2026-10-07T12:00:00+02:00');
    expect(readPetPlan({ type: 'challenge', status: 'paid', challenge_started_at: null })).not.toHaveProperty('challenge_started_at');
    expect(challengeStartedAt(readPetPlan({ type: 'free', challenge_started_at: '2026-10-07T12:00:00+02:00' }))).toBeNull();
  });

  it('marks the days before the upgrade (report rows, timeline)', () => {
    const [pre, day] = readDayRows([{ date: '2026-10-05', pre_challenge: true }, { date: '2026-10-07', pre_challenge: false }]);
    expect(pre.pre_challenge).toBe(true);
    expect(day).not.toHaveProperty('pre_challenge');
    expect(isPreChallenge('2026-10-06T10:00:00Z', '2026-10-07T10:00:00Z')).toBe(true);
    expect(isPreChallenge('2026-10-07T10:00:01Z', '2026-10-07T10:00:00Z')).toBe(false);
    expect(isPreChallenge('2026-10-06T10:00:00Z', null)).toBe(false);
  });
});

describe('shared purchase rule', () => {
  it('a free pet is offered only when the server allows the upgrade (not a buy button)', () => {
    const up = familyWith({ upgrade: UPGRADEABLE }).pets[0];
    expect(canUpgradePet(up)).toBe(true);
    expect(isOfferablePet(up)).toBe(true);
    expect(canBuyChallenge(up)).toBe(false); // its own button, not »kupi«
    const legacy = familyWith({ upgrade: LEGACY }).pets[0];
    expect(canUpgradePet(legacy)).toBe(false);
    expect(isOfferablePet(legacy)).toBe(false);
    expect(upgradeNote(legacy)).toBe('legacy_profile');
    const over = familyWith({ upgrade: UPGRADEABLE, is_game_over: true }).pets[0];
    expect(canUpgradePet(over)).toBe(false);
    expect(upgradeNote(over)).toBeNull();
    // A free domestic cat the same way.
    const cat = familyWith({ upgrade: UPGRADEABLE, species: 'cat' }, 'domestic_cat').pets[0];
    expect(canUpgradePet(cat)).toBe(true);
  });

  it('the unused-purchase prompt shows only with a credit AND a pet it can go to', () => {
    const f = familyWith({ upgrade: UPGRADEABLE });
    expect(showUnusedPurchasePrompt(billingFree(UPGRADEABLE, 1), f)).toBe(true);
    expect(showUnusedPurchasePrompt(billingFree(UPGRADEABLE, 0), f)).toBe(false);
    expect(showUnusedPurchasePrompt(billingFree(LEGACY, 1), familyWith({ upgrade: LEGACY }))).toBe(false);
    expect(showUnusedPurchasePrompt(undefined, f)).toBe(false);
    expect(isBillingPetUpgradeable(billingFree(UPGRADEABLE).pets[0], f)).toBe(true);
  });
});

describe('»Začni 12-tedenski izziv z [ime]«', () => {
  it('uses the pet name with the right Slovenian preposition (s / z); English "with"', async () => {
    expect(slovenianWithS('Pika')).toBe(true);
    expect(slovenianWithS('Luna')).toBe(false);
    expect(upgradeButtonLabel('Luna')).toBe('Začni 12-tedenski izziv z Luna');
    expect(upgradeButtonLabel('Pika')).toBe('Začni 12-tedenski izziv s Pika');
    expect(upgradeButtonLabel(null)).toBe('Začni 12-tedenski izziv');
    await act(async () => {
      await i18n.changeLanguage('en');
    });
    expect(upgradeButtonLabel('Pika')).toBe('Start the 12-week challenge with Pika');
  });

  it('opens the paywall for that pet; a legacy pet gets the honest note instead (D3); an older server nothing', () => {
    const onUpgrade = jest.fn();
    render(<ChallengeUpgradeEntry pet={familyWith({ upgrade: UPGRADEABLE }).pets[0]} childName="Maja" onUpgrade={onUpgrade} testID="up" />);
    fireEvent.press(screen.getByTestId('up'));
    expect(onUpgrade).toHaveBeenCalledWith(7);
    expect(screen.getByText('Začni 12-tedenski izziv z Luna')).toBeTruthy();
    screen.unmount();

    render(<ChallengeUpgradeEntry pet={familyWith({ upgrade: LEGACY }).pets[0]} childName="Maja" onUpgrade={onUpgrade} testID="up" />);
    expect(screen.queryByTestId('up')).toBeNull();
    expect(screen.getByTestId('up-legacy')).toBeTruthy();
    expect(screen.getByText(/ni mogoče nadgraditi/)).toBeTruthy();
    screen.unmount();

    render(<ChallengeUpgradeEntry pet={familyWith({}).pets[0]} childName="Maja" onUpgrade={onUpgrade} testID="up" />);
    expect(screen.queryByTestId('up')).toBeNull();
    expect(screen.queryByTestId('up-legacy')).toBeNull();
  });
});

describe('paywall for one free pet', () => {
  it('explains the upgrade (same pet, what is added, starts now, price, no subscription) and buys for that pet', async () => {
    getBilling.mockResolvedValue({
      credits_available: 0,
      pets: [{ pet_id: 7, plan: 'free', status: null, trial_ends_at: null, paid_at: null, trial_available: null, deletion_loses_purchase: false, challenge_started_at: null, upgrade: UPGRADEABLE }],
    });
    renderWithQuery(<ChallengeScreen family={familyWith({ upgrade: UPGRADEABLE })} onBack={jest.fn()} focusPetId={7} />);
    await flush();

    expect(screen.getByTestId('challenge-upgrade')).toBeTruthy();
    expect(screen.getByText(/Luna ostane isti/)).toBeTruthy();
    expect(screen.getByText(/takoj ob nakupu/)).toBeTruthy();
    expect(screen.getByText(/Enkratni nakup 49,99 €\. Brez naročnine/)).toBeTruthy();
    fireEvent.press(screen.getByTestId('challenge-buy-7'));
    expect(mockPurchase).toHaveBeenCalledWith({ pkg: mockPackage, target: { petId: 7, baselineCredits: 0 } }, expect.anything());
  });

  it('with an unused purchase: »Uporabi kupljen izziv« upgrades the pet (activate)', async () => {
    getBilling.mockResolvedValue({
      credits_available: 1,
      pets: [{ pet_id: 7, plan: 'free', status: null, trial_ends_at: null, paid_at: null, trial_available: null, deletion_loses_purchase: false, challenge_started_at: null, upgrade: UPGRADEABLE }],
    });
    activateChallenge.mockResolvedValueOnce({ status: 'activated' });
    renderWithQuery(<ChallengeScreen family={familyWith({ upgrade: UPGRADEABLE })} onBack={jest.fn()} />);
    await flush();
    fireEvent.press(screen.getByTestId('challenge-use-credit-7'));
    await flush();
    expect(activateChallenge).toHaveBeenCalledWith(7);
    expect(screen.getByTestId('challenge-message')).toHaveTextContent(/izziv se je začel/);
  });

  it('never lists a free pet without a credit unless opened for it', async () => {
    getBilling.mockResolvedValue({
      credits_available: 0,
      pets: [{ pet_id: 7, plan: 'free', status: null, trial_ends_at: null, paid_at: null, trial_available: null, deletion_loses_purchase: false, challenge_started_at: null, upgrade: UPGRADEABLE }],
    });
    renderWithQuery(<ChallengeScreen family={familyWith({ upgrade: UPGRADEABLE })} onBack={jest.fn()} />);
    await flush();
    expect(screen.queryByTestId('challenge-pet-7')).toBeNull();
    expect(screen.queryByTestId('challenge-upgrade')).toBeNull();
  });
});

describe('unused purchase prompt (§5.3)', () => {
  it('»Imate neporabljen nakup — izberite ljubljenčka« opens the paywall', async () => {
    getBilling.mockResolvedValue({
      credits_available: 1,
      pets: [{ pet_id: 7, plan: 'free', status: null, trial_ends_at: null, paid_at: null, trial_available: null, deletion_loses_purchase: false, challenge_started_at: null, upgrade: UPGRADEABLE }],
    });
    const onChoose = jest.fn();
    renderWithQuery(<UnusedPurchasePrompt family={familyWith({ upgrade: UPGRADEABLE })} onChoose={onChoose} />);
    await flush();
    expect(screen.getByText('Imate neporabljen nakup — izberite ljubljenčka')).toBeTruthy();
    fireEvent.press(screen.getByTestId('unused-purchase-choose'));
    expect(onChoose).toHaveBeenCalledTimes(1);
  });
});

describe('child: »Izziv se je začel!« (no price)', () => {
  const NOW = Date.parse('2026-10-08T10:00:00Z');

  it('says it once per (pet, upgrade), only for a recent upgrade, never over a lock', async () => {
    let saved: string | null = null;
    setChallengeStartedStoreForTests({ getItemAsync: async () => saved, setItemAsync: async (_k, v) => void (saved = v) });
    const at = '2026-10-07T12:00:00+02:00';
    expect(shouldAnnounceChallengeStart(at, false, false, NOW)).toBe(true);
    expect(shouldAnnounceChallengeStart(at, false, true, NOW)).toBe(false);
    expect(shouldAnnounceChallengeStart(at, false, false, NOW + 30 * 86_400_000)).toBe(false);
    expect(shouldAnnounceChallengeStart(null, false, false, NOW)).toBe(false);

    expect(await wasChallengeStartAnnounced(7, at)).toBe(false);
    await markChallengeStartAnnounced(7, at);
    expect(await wasChallengeStartAnnounced(7, at)).toBe(true);
    // K7: a new purchase after a refund is a new challenge → said again.
    expect(await wasChallengeStartAnnounced(7, '2026-11-02T10:00:00+01:00')).toBe(false);
    setChallengeStartedStoreForTests(null);

    expect(challengeStartedMessage(0)).toBe('Izziv se je začel! Teden 1 od 12');
    expect(challengeStartedMessage(0)).not.toMatch(/€|kup|nakup/i);
    await act(async () => {
      await i18n.changeLanguage('en');
    });
    expect(challengeStartedMessage(2)).toBe('The challenge has started! Week 3 of 12');
  });

  it('»O tvojem kužku« gets »Izziv: Teden N od 12« only for an upgraded pet', () => {
    const profile = readPetProfile({ origin: 'bought', age_months: 5, arrival_age_months: 2, life_stage: 'puppy', legacy: false });
    if (profile === null) throw new Error('profile');
    expect(profileSheetRows('Mešanček', profile).map((r) => r.key)).not.toContain('challenge');
    const rows = profileSheetRows('Mešanček', profile, 0);
    expect(rows.find((r) => r.key === 'challenge')).toEqual({ key: 'challenge', label: 'Izziv', value: 'Teden 1 od 12' });
  });
});
