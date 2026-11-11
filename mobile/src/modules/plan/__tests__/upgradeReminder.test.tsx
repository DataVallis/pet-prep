/**
 * M6-U02 (FREE_PET_UPGRADE_SPEC U4 / U5 / D1): »mešanček + izziv« in the picker (new builds) and
 * the day-7 reminder card + push on the parent side.
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';

import { clientFeatures } from '@/api/client';
import PetPickerStep from '@/components/parent/PetPickerStep';
import UpgradeReminderCards, { upgradeReminderText } from '@/components/parent/UpgradeReminderCards';
import { i18n } from '@/i18n';
import { normalizePet, type FamilyOverview, type FamilyPet } from '@/modules/family/family';
import {
  choiceWithPlan,
  choiceWithSpecies,
  INITIAL_PICKER_CHOICE,
  PICKER_STRINGS as PICKER,
  readBreedCatalogue,
  type BreedCatalogue,
} from '@/modules/petProfile/picker';
import { canBuyChallenge, isUnpaidFreeBreedChallenge } from '@/modules/plan/purchaseEntry';
import {
  dismissedReminderPets,
  readUpgradeReminder,
  setUpgradeReminderStoreForTests,
  upgradeReminderCards,
  UPGRADE_REMINDER_DISMISSED_KEY,
} from '@/modules/plan/upgradeReminder';
import { isUrgentPush, parsePushData } from '@/modules/push/pushData';
import { makeFamilyPet, makeScoredChild } from '@/test-utils/fixtures';

type RawPlan = ReturnType<typeof makeFamilyPet>['plan'];
const FREE: RawPlan = { type: 'free', status: null, trial_ends_at: null, paid_at: null, payments_enforced: true, display_type: 'free', challenge_started_at: null };
const LOCKED: RawPlan = { type: 'challenge', status: 'payment_required', trial_ends_at: null, paid_at: null, payments_enforced: true, display_type: 'challenge', challenge_started_at: null };
const REMINDER = { sent_at: '2026-11-09T18:00:00+01:00', child_id: 11 };

function freePet(overrides: Partial<ReturnType<typeof makeFamilyPet>> = {}): FamilyPet {
  return normalizePet(
    makeFamilyPet({ id: 7, breed_type: 'mutt', plan: FREE, name: 'Pika', upgrade: { available: true, reason: null }, upgrade_reminder: REMINDER, ...overrides }),
  );
}

function family(pets: FamilyPet[]): FamilyOverview {
  const child = makeScoredChild({ id: 11, name: 'Maja', pet_id: pets[0]?.id ?? null });
  return { id: 1, timezone: 'Europe/Ljubljana', parents: [], children: [child as unknown as FamilyOverview['children'][number]], pets };
}

function memoryStore(initial: Record<string, string> = {}) {
  const data = { ...initial };
  return {
    data,
    getItemAsync: jest.fn(async (k: string) => data[k] ?? null),
    setItemAsync: jest.fn(async (k: string, v: string) => {
      data[k] = v;
    }),
  };
}

beforeEach(async () => {
  await i18n.changeLanguage('sl');
});

afterEach(() => setUpgradeReminderStoreForTests(null));

describe('U4: »mešanček + izziv«', () => {
  it('the build declares free_breed_challenge (with and without the cat UI)', () => {
    expect(clientFeatures(true)).toContain('free_breed_challenge');
    expect(clientFeatures(false)).toContain('free_breed_challenge');
  });

  function catalogue(muttChallenge: boolean): BreedCatalogue {
    return readBreedCatalogue({
      species: ['dog'],
      breeds: [
        { breed: 'mutt', slug: 'mutt', species: 'dog', premium: false, free_plan_allowed: true, challenge_allowed: muttChallenge, label_key: 'breeds.mutt', search_keywords: [], sort_order: 0 },
        { breed: 'border_collie', slug: 'border_collie', species: 'dog', premium: true, free_plan_allowed: false, challenge_allowed: true, label_key: 'breeds.border_collie', search_keywords: [], sort_order: 10 },
      ],
    }) as BreedCatalogue;
  }

  function renderPicker(c: BreedCatalogue) {
    render(<PetPickerStep childName="Maja" catalogue={c} initial={INITIAL_PICKER_CHOICE} onConfirm={jest.fn()} onBack={jest.fn()} previewCount={Number.MAX_SAFE_INTEGER} />);
  }

  it('new server: the mutt is pickable on the challenge but never preselected (QA #983) — a tap chooses it, then the hint', () => {
    renderPicker(catalogue(true));
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    // Switching to the challenge preselects the first PAID breed, never the mutt (no accidental 49,99 € mutt).
    expect(screen.getByTestId('breed-option-border_collie').props.accessibilityState).toMatchObject({ checked: true });
    expect(screen.getByTestId('breed-option-mutt').props.accessibilityState).toMatchObject({ checked: false, disabled: false });
    expect(screen.queryByTestId('free-breed-challenge-hint')).toBeNull();

    // Only an explicit tap picks the mutt on the challenge.
    fireEvent.press(screen.getByTestId('breed-option-mutt'));
    expect(screen.getByTestId('breed-option-mutt').props.accessibilityState).toMatchObject({ checked: true, disabled: false });
    expect(screen.getByTestId('free-breed-challenge-hint')).toBeTruthy();
    expect(screen.getByText('Začnite brezplačno, izziv lahko dodate kadarkoli.')).toBeTruthy();
    expect(PICKER.freeBreedChallengeHint).toBe('Začnite brezplačno, izziv lahko dodate kadarkoli.');

    // Back to a paid breed: no hint.
    fireEvent.press(screen.getByTestId('breed-option-border_collie'));
    expect(screen.queryByTestId('free-breed-challenge-hint')).toBeNull();
  });

  it('choiceWithPlan / choiceWithSpecies never preselect the free breed on the challenge', () => {
    const c = catalogue(true).breeds;
    // free plan (mutt) → challenge: the first paid breed
    const free = choiceWithPlan({ ...INITIAL_PICKER_CHOICE, species: 'dog' }, 'free', [], c);
    expect(free.breed).toBe('mutt');
    expect(choiceWithPlan(free, 'challenge', [], c).breed).toBe('border_collie');
    // the mutt tapped while the challenge is chosen stays (also when the challenge is tapped again)
    const tapped = { ...choiceWithPlan(free, 'challenge', [], c), breed: 'mutt' as const };
    expect(choiceWithPlan(tapped, 'challenge', [], c).breed).toBe('mutt');
    // the only paid breed refused by the server: the mutt is not chosen silently
    expect(choiceWithPlan(free, 'challenge', ['border_collie'], c).breed).toBeNull();
    // species change on the challenge → the first paid breed of that species
    expect(choiceWithSpecies({ ...tapped, species: 'cat' }, 'dog', [], c).breed).toBe('border_collie');
  });

  it('older server (challenge_allowed false for the mutt): unchanged — the mutt is locked on the challenge', () => {
    renderPicker(catalogue(false));
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    expect(screen.getByTestId('breed-option-mutt').props.accessibilityState).toMatchObject({ disabled: true });
    expect(screen.getByTestId('breed-option-border_collie').props.accessibilityState).toMatchObject({ checked: true });
    expect(screen.queryByTestId('free-breed-challenge-hint')).toBeNull();
  });

  it('an unpaid mutt challenge waits for the purchase like a paid breed (buy button)', () => {
    const p = normalizePet(makeFamilyPet({ breed_type: 'mutt', plan: LOCKED }));
    expect(isUnpaidFreeBreedChallenge(p)).toBe(true);
    expect(canBuyChallenge(p)).toBe(true);
    expect(isUnpaidFreeBreedChallenge(normalizePet(makeFamilyPet({ breed_type: 'border_collie', plan: LOCKED })))).toBe(false);
    expect(isUnpaidFreeBreedChallenge(normalizePet(makeFamilyPet({ breed_type: 'mutt', plan: FREE })))).toBe(false);
  });
});

describe('U5: the day-7 reminder', () => {
  it('reads the server field; anything malformed or missing → no card', () => {
    expect(readUpgradeReminder(REMINDER)).toEqual({ sentAt: REMINDER.sent_at, childId: 11 });
    expect(readUpgradeReminder({ sent_at: REMINDER.sent_at, child_id: null })).toEqual({ sentAt: REMINDER.sent_at, childId: null });
    expect(readUpgradeReminder(undefined)).toBeNull();
    expect(readUpgradeReminder({ child_id: 1 })).toBeNull();
  });

  it('a card per marked, still upgradeable free pet; not after the purchase, not when dismissed', () => {
    expect(upgradeReminderCards(family([freePet()]), [])).toHaveLength(1);
    expect(upgradeReminderCards(family([freePet()]), [7])).toHaveLength(0);
    expect(upgradeReminderCards(family([freePet({ upgrade_reminder: null })]), [])).toHaveLength(0);
    // bought meanwhile (the server already dropped `upgrade`): no card even with a stale field
    expect(upgradeReminderCards(family([freePet({ plan: LOCKED, upgrade: null })]), [])).toHaveLength(0);
    expect(upgradeReminderCards(family([freePet({ is_game_over: true })]), [])).toHaveLength(0);
    expect(upgradeReminderCards(null, [])).toHaveLength(0);
  });

  it('text: SL / EN, dog / cat, with and without the nickname / name', async () => {
    expect(upgradeReminderText({ childName: 'Maja', petName: 'Pika', species: 'dog' })).toBe('Maja že 7 dni skrbi za kužka Pika. Je čas za pravi preizkus?');
    expect(upgradeReminderText({ childName: 'Maja', petName: 'Pika', species: 'cat' })).toBe('Maja že 7 dni skrbi za muco Pika. Je čas za pravi preizkus?');
    expect(upgradeReminderText({ childName: null, petName: null, species: 'dog' })).toBe('Vaš otrok že 7 dni skrbi za kužka. Je čas za pravi preizkus?');
    await i18n.changeLanguage('en');
    expect(upgradeReminderText({ childName: 'Maja', petName: 'Pika', species: 'dog' })).toBe('Maja has been looking after Pika for 7 days. Time for the real test?');
    expect(upgradeReminderText({ childName: 'Maja', petName: null, species: 'cat' })).toBe('Maja has been looking after the cat for 7 days. Time for the real test?');
  });

  it('the card opens the upgrade for that pet and can be dismissed on this phone', async () => {
    const store = memoryStore();
    setUpgradeReminderStoreForTests(store);
    const onUpgrade = jest.fn();
    render(<UpgradeReminderCards family={family([freePet()])} onUpgrade={onUpgrade} />);

    expect(await screen.findByTestId('upgrade-reminder-7')).toBeTruthy();
    expect(screen.getByText('Maja že 7 dni skrbi za kužka Pika. Je čas za pravi preizkus?')).toBeTruthy();
    expect(screen.queryByText(/€/)).toBeNull();
    fireEvent.press(screen.getByTestId('upgrade-reminder-open-7'));
    expect(onUpgrade).toHaveBeenCalledWith(7);

    fireEvent.press(screen.getByTestId('upgrade-reminder-dismiss-7'));
    expect(screen.queryByTestId('upgrade-reminder-7')).toBeNull();
    await waitFor(() => expect(store.data[UPGRADE_REMINDER_DISMISSED_KEY]).toBe('[7]'));
    expect(await dismissedReminderPets()).toEqual([7]);
  });

  it('a dismissed card stays hidden on the next render; an unreadable store shows it', async () => {
    setUpgradeReminderStoreForTests(memoryStore({ [UPGRADE_REMINDER_DISMISSED_KEY]: '[7]' }));
    const first = render(<UpgradeReminderCards family={family([freePet()])} onUpgrade={jest.fn()} />);
    await waitFor(() => expect(screen.queryByTestId('upgrade-reminder-7')).toBeNull());
    first.unmount();

    setUpgradeReminderStoreForTests({ getItemAsync: async () => 'not json', setItemAsync: async () => undefined });
    render(<UpgradeReminderCards family={family([freePet()])} onUpgrade={jest.fn()} />);
    expect(await screen.findByTestId('upgrade-reminder-7')).toBeTruthy();
  });

  it('push type upgrade_reminder: parsed with its pet, never urgent', () => {
    expect(parsePushData({ type: 'upgrade_reminder', pet_id: 7 })).toEqual({ type: 'upgrade_reminder', petId: 7 });
    expect(parsePushData({ type: 'upgrade_reminder' })).toBeNull();
    expect(isUrgentPush('upgrade_reminder')).toBe(false);
  });
});
