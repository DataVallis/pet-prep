/**
 * M5-F01 — visible purchase entry: when the parent app shows "12-week challenge — buy"
 * (overview card, child detail) and the "Purchases / challenge" row in Nadzor.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import ChildOverviewCard from '@/components/parent/ChildOverviewCard';
import PurchasesRow, { purchasesRowSubtitle } from '@/components/parent/PurchasesRow';
import { normalizePet, type FamilyOverview, type FamilyPet } from '@/modules/family/family';
import {
  canBuyChallenge,
  isBillingPetPurchasable,
  isOfferablePet,
  petsAwaitingPurchase,
  showPurchasesRow,
} from '@/modules/plan/purchaseEntry';
import ChallengeBanner from '@/components/parent/ChallengeBanner';
import type { BillingPet } from '@/api/client';
import { i18n } from '@/i18n';
import { makeFamilyPet, makeScoredChild } from '@/test-utils/fixtures';

type RawPlan = ReturnType<typeof makeFamilyPet>['plan'];

const TRIAL: RawPlan = { type: 'challenge', status: 'trial', trial_ends_at: '2026-10-12T10:00:00+02:00', paid_at: null, payments_enforced: true };
const LOCKED: RawPlan = { type: 'challenge', status: 'payment_required', trial_ends_at: '2026-10-05T10:00:00+02:00', paid_at: null, payments_enforced: true };
const PAID: RawPlan = { type: 'challenge', status: 'paid', trial_ends_at: null, paid_at: '2026-10-01T10:00:00+02:00', payments_enforced: true };
/** What the server really sends for an unborn challenge dog: `trial` without an end (starts at signing). */
const UNBORN_TRIAL: RawPlan = { type: 'challenge', status: 'trial', trial_ends_at: null, paid_at: null, payments_enforced: true };
const FREE: RawPlan = { type: 'free', status: null, trial_ends_at: null, paid_at: null, payments_enforced: true };

function pet(plan: RawPlan, overrides: Partial<ReturnType<typeof makeFamilyPet>> = {}): FamilyPet {
  return normalizePet(makeFamilyPet({ breed_type: 'border_collie', plan, ...overrides }));
}

function family(pets: FamilyPet[]): FamilyOverview {
  return { id: 1, timezone: 'Europe/Ljubljana', parents: [], children: [], pets };
}

describe('canBuyChallenge (M5-F01)', () => {
  it('trial and payment_required challenge → buy', () => {
    expect(canBuyChallenge(pet(TRIAL))).toBe(true);
    expect(canBuyChallenge(pet(LOCKED))).toBe(true);
  });

  it('paid / grandfathered, not started yet, free plan → no buy', () => {
    expect(canBuyChallenge(pet(PAID))).toBe(false);
    expect(canBuyChallenge(pet(UNBORN_TRIAL, { born_at: null }))).toBe(false);
    // Same payload once born (kill switch / unknown end) → buyable.
    expect(canBuyChallenge(pet(UNBORN_TRIAL))).toBe(true);
    expect(canBuyChallenge(pet(FREE))).toBe(false);
  });

  it('a mutt never gets a buy button, even with an old challenge payload', () => {
    expect(canBuyChallenge(pet(TRIAL, { breed_type: 'mutt' }))).toBe(false);
    expect(canBuyChallenge(pet(FREE, { breed_type: 'mutt' }))).toBe(false);
  });

  it('game over or no pet → no buy', () => {
    expect(canBuyChallenge(pet(TRIAL, { is_game_over: true }))).toBe(false);
    expect(canBuyChallenge(null)).toBe(false);
  });

  it('payments kill switch (trial without end) still offers the purchase', () => {
    expect(canBuyChallenge(pet({ ...TRIAL, status: 'trial', payments_enforced: false }))).toBe(true);
  });

  it('a missing plan (older server) reads as paid → no buy', () => {
    expect(canBuyChallenge(normalizePet({ ...makeFamilyPet({ breed_type: 'border_collie' }), plan: undefined } as unknown as ReturnType<typeof makeFamilyPet>))).toBe(false);
  });
});

describe('Nadzor row (M5-F01)', () => {
  it('lists only purchasable pets and hides for a mutt-only family', () => {
    const f = family([pet(TRIAL, { id: 1 }), pet(PAID, { id: 2 }), pet(LOCKED, { id: 3 })]);
    expect(petsAwaitingPurchase(f).map((p) => p.id)).toEqual([1, 3]);
    // An unborn dog is not counted (its trial starts at the contract).
    expect(petsAwaitingPurchase(family([pet(UNBORN_TRIAL, { id: 4, born_at: null })]))).toEqual([]);
    expect(showPurchasesRow(f)).toBe(true);
    expect(showPurchasesRow(family([pet(FREE, { breed_type: 'mutt' })]))).toBe(false);
    expect(showPurchasesRow(family([pet(PAID)]))).toBe(true);
    expect(showPurchasesRow(null)).toBe(false);
  });

  it('subtitle counts waiting dogs (sl plurals) or says all are paid', async () => {
    expect(purchasesRowSubtitle(family([pet(TRIAL, { id: 1 })]))).toBe('1 pes čaka na nakup');
    expect(purchasesRowSubtitle(family([pet(TRIAL, { id: 1 }), pet(LOCKED, { id: 2 })]))).toBe('2 psa čakata na nakup');
    expect(purchasesRowSubtitle(family([pet(PAID)]))).toMatch(/^Noben pes ne čaka na nakup/);
    await i18n.changeLanguage('en');
    try {
      expect(purchasesRowSubtitle(family([pet(TRIAL, { id: 1 }), pet(LOCKED, { id: 2 })]))).toBe('2 dogs are waiting for a purchase');
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('the row opens the paywall', () => {
    const onPress = jest.fn();
    render(<PurchasesRow family={family([pet(TRIAL)])} onPress={onPress} />);
    expect(screen.getByText('Nakupi / izziv')).toBeTruthy();
    fireEvent.press(screen.getByTestId('controls-purchases'));
    expect(onPress).toHaveBeenCalledTimes(1);
  });
});

describe('ChildOverviewCard buy button (M5-F01)', () => {
  function renderCard(plan: RawPlan, breed: 'border_collie' | 'mutt' = 'border_collie') {
    const child = makeScoredChild();
    const onOpenChallenge = jest.fn();
    render(
      <ChildOverviewCard
        child={child}
        pet={pet(plan, { breed_type: breed })}
        timezone="Europe/Ljubljana"
        onOpen={jest.fn()}
        onChildPin={jest.fn()}
        onOpenChallenge={onOpenChallenge}
      />,
    );
    return { id: child.id, onOpenChallenge };
  }

  it('trial: the badge stays and a clear buy button opens the paywall', () => {
    const { id, onOpenChallenge } = renderCard(TRIAL);
    expect(screen.getByTestId(`child-plan-${id}`)).toBeTruthy();
    expect(screen.getByText('12-tedenski izziv — kupi')).toBeTruthy();
    fireEvent.press(screen.getByTestId(`child-buy-${id}`));
    expect(onOpenChallenge).toHaveBeenCalledTimes(1);
  });

  it('paid challenge or mutt: no buy button', () => {
    const paid = renderCard(PAID);
    expect(screen.queryByTestId(`child-buy-${paid.id}`)).toBeNull();
    screen.unmount();
    const mutt = renderCard(FREE, 'mutt');
    expect(screen.queryByTestId(`child-buy-${mutt.id}`)).toBeNull();
  });
});

describe('shared purchase rule — paywall list and banner (M5-F01 QA)', () => {
  const billing = (petId: number, status: BillingPet['status']): BillingPet => ({
    pet_id: petId,
    plan: 'challenge',
    status,
    trial_ends_at: null,
    paid_at: null,
    trial_available: true,
    deletion_loses_purchase: false,
  });

  it('isOfferablePet: never a mutt or a game-over pet', () => {
    expect(isOfferablePet(pet(TRIAL))).toBe(true);
    expect(isOfferablePet(pet(TRIAL, { breed_type: 'mutt' }))).toBe(false);
    expect(isOfferablePet(pet(TRIAL, { is_game_over: true }))).toBe(false);
    expect(isOfferablePet(null)).toBe(false);
  });

  it('paywall lists a challenge dog but never a mutt, a game-over pet or one missing from the family', () => {
    const f = family([pet(TRIAL, { id: 1 }), pet(LOCKED, { id: 2, breed_type: 'mutt' }), pet(LOCKED, { id: 3, is_game_over: true })]);
    expect(isBillingPetPurchasable(billing(1, 'trial'), f)).toBe(true);
    expect(isBillingPetPurchasable(billing(1, 'paid'), f)).toBe(false);
    expect(isBillingPetPurchasable(billing(2, 'payment_required'), f)).toBe(false);
    expect(isBillingPetPurchasable(billing(3, 'payment_required'), f)).toBe(false);
    expect(isBillingPetPurchasable(billing(9, 'trial'), f)).toBe(false);
    expect(isBillingPetPurchasable(billing(1, 'trial'), null)).toBe(false);
  });

  it('the banner never asks to buy for a mutt', () => {
    const { unmount } = render(<ChallengeBanner family={family([pet(LOCKED, { breed_type: 'mutt' })])} onOpen={jest.fn()} />);
    expect(screen.queryByTestId('challenge-banner-paused')).toBeNull();
    unmount();
    render(<ChallengeBanner family={family([pet(LOCKED)])} onOpen={jest.fn()} />);
    expect(screen.getByTestId('challenge-banner-paused')).toBeTruthy();
  });
});
