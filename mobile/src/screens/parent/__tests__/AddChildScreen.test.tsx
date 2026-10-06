/**
 * M2-02: parent "Dodaj otroka" (nickname → pet choice → PIN for that child) and
 * "Nova koda za prijavo" (re-login PIN for a paired child).
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import AddChildScreen, { ADD_CHILD_STRINGS as S } from '@/screens/parent/AddChildScreen';
import { PICKER_STRINGS as PICKER } from '@/modules/petProfile/picker';
import { useAppStore } from '@/store/appStore';
import { makeFamilyChild, makeFamilyDashboard, makeFamilyPet, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { maybeAskForPush } from '@/modules/push/pushPrompt';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      createChild: jest.fn(),
      generatePin: jest.fn(),
      getParentDashboard: jest.fn(),
      getUser: jest.fn(),
    },
  };
});

// M3-02: adding a child is the moment to ask the parent about alarms.
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

const createChild = api.createChild as jest.Mock;
const generatePin = api.generatePin as jest.Mock;
const getParentDashboard = api.getParentDashboard as jest.Mock;
const getUser = api.getUser as jest.Mock;

const NOW = new Date('2026-10-03T10:00:00Z');

/** A paired child (pet 7, signed in on one device) — "Nova koda za prijavo". */
const PAIRED_CHILD = makeFamilyChild({ id: 2, name: 'Luka', pet_id: 7, devices: 1, contract_signed: true });

function pinResponse(pin: string, extra: { child_id?: number; pet_id?: number | null; mode?: string; minutes?: number } = {}) {
  return {
    pin,
    expires_at: new Date(Date.now() + (extra.minutes ?? 15) * 60_000).toISOString(),
    expires_in_minutes: 15,
    child_id: extra.child_id ?? 2,
    pet_id: extra.pet_id ?? null,
    mode: extra.mode ?? 'relogin',
  };
}

/**
 * Let pending promises settle under fake timers. TanStack Query batches its
 * notifications with setTimeout(0), so a zero-length timer advance is needed too.
 */
async function flush() {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(0);
  });
}

/** M5-R04 "Izberi kužka": pick origin + age (breed stays the free mutt unless given) and confirm. */
async function pickDog(origin: 'bought' | 'adopted' = 'bought', age: 'puppy' | 'young' | 'adult' | 'senior' = 'puppy') {
  fireEvent.press(screen.getByTestId(`origin-option-${origin}`));
  fireEvent.press(screen.getByTestId(`age-option-${age}`));
  fireEvent.press(screen.getByTestId('dog-picker-confirm'));
  await flush();
}

function renderRelogin() {
  return renderWithQuery(<AddChildScreen onBack={jest.fn()} child={PAIRED_CHILD} />);
}

describe('AddChildScreen', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    // Drop queued mock*Once values too, so a failing test can't leak into the next one.
    [createChild, generatePin, getParentDashboard, getUser].forEach((m) => m.mockReset());
    jest.useFakeTimers();
    jest.setSystemTime(NOW);
    getParentDashboard.mockResolvedValue(makeFamilyDashboard([PAIRED_CHILD], [makeFamilyPet({ caretakers: [{ child_id: 2, contract_signed: true }] })]));
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  describe('new child: profile → pet → PIN', () => {
    it('asks only for a nickname and an optional birth year, with the privacy note', async () => {
      renderWithQuery(<AddChildScreen onBack={jest.fn()} />);

      expect(screen.getByText(S.privacy)).toBeTruthy();
      expect(screen.getByLabelText(S.nicknameLabel)).toBeTruthy();
      expect(screen.getByLabelText(S.birthYearLabel)).toBeTruthy();
      expect(screen.queryByPlaceholderText(/e-pošt/i)).toBeNull();

      fireEvent.press(screen.getByText(S.next)); // empty nickname → disabled
      expect(createChild).not.toHaveBeenCalled();
    });

    it('rejects a birth year outside the last 18 years before calling the API', async () => {
      renderWithQuery(<AddChildScreen onBack={jest.fn()} />);
      fireEvent.changeText(screen.getByTestId('child-nickname'), 'Maja');
      fireEvent.changeText(screen.getByTestId('child-birth-year'), '1990');
      fireEvent.press(screen.getByText(S.next));

      expect(screen.getByTestId('add-child-error')).toHaveTextContent(S.birthYearInvalid(2008, 2026));
      expect(createChild).not.toHaveBeenCalled();
    });

    it('new pet: creates the profile, then asks for a PIN with child_id and no pet_id', async () => {
      createChild.mockResolvedValueOnce({ child: { id: 5, display_name: 'Maja Mala', birth_year: 2016, family_id: 1, pet_id: null, devices: 0 } });
      generatePin.mockResolvedValueOnce(pinResponse('734912', { child_id: 5, mode: 'new_pet' }));
      renderWithQuery(<AddChildScreen onBack={jest.fn()} />);

      fireEvent.changeText(screen.getByTestId('child-nickname'), '  Maja   Mala ');
      fireEvent.changeText(screen.getByTestId('child-birth-year'), '2016');
      fireEvent.press(screen.getByText(S.next));
      await flush();

      expect(createChild).toHaveBeenCalledWith({ display_name: 'Maja Mala', birth_year: 2016 });
      expect(screen.getByText(S.petTitle('Maja Mala'))).toBeTruthy();
      expect(maybeAskForPush).toHaveBeenCalledWith('parent');

      fireEvent.press(screen.getByTestId('pet-option-new'));
      await flush();
      expect(generatePin).not.toHaveBeenCalled(); // "Izberi kužka" comes first (M5-R04)
      await pickDog('adopted', 'young');
      expect(generatePin).toHaveBeenCalledWith({
        child_id: 5,
        pet_id: null,
        profile: { breed: 'mutt', origin: 'adopted', age_stage: 'young' },
      });
      expect(screen.getByText('734 912')).toBeTruthy();
      expect(screen.getByText(S.pinFor('Maja Mala'))).toBeTruthy();
      expect(screen.getByText(S.steps.new_pet[2])).toBeTruthy();
    });

    it('join pet: lists only active pets with their caretakers and sends pet_id', async () => {
      getParentDashboard.mockResolvedValue(
        makeFamilyDashboard(
          [PAIRED_CHILD],
          [
            makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] }),
            makeFamilyPet({ id: 8, is_game_over: true, is_active: false }),
          ],
        ),
      );
      createChild.mockResolvedValueOnce({ child: { id: 5, display_name: 'Maja', birth_year: null, family_id: 1, pet_id: null, devices: 0 } });
      generatePin.mockResolvedValueOnce(pinResponse('111222', { child_id: 5, pet_id: 7, mode: 'join_pet' }));
      renderWithQuery(<AddChildScreen onBack={jest.fn()} />);

      fireEvent.changeText(screen.getByTestId('child-nickname'), 'Maja');
      fireEvent.press(screen.getByText(S.next));
      await flush();

      expect(createChild).toHaveBeenCalledWith({ display_name: 'Maja', birth_year: null });
      // The pet step reads the family's pets from the dashboard query.
      expect(await screen.findByText(S.joinPet('Mešanček'))).toBeTruthy();
      expect(screen.getByText(S.joinPetHint('Luka'))).toBeTruthy();
      expect(screen.queryByTestId('pet-option-8')).toBeNull();

      fireEvent.press(screen.getByTestId('pet-option-7'));
      await flush();
      // Joining a shared pet (caretaker flow) never shows the picker and sends no profile.
      expect(screen.queryByTestId('dog-picker')).toBeNull();
      expect(generatePin).toHaveBeenCalledWith({ child_id: 5, pet_id: 7 });
      expect(screen.getByText('111 222')).toBeTruthy();
      expect(screen.getByText(S.steps.join_pet[2])).toBeTruthy();
    });

    it.each([
      [new ApiError('Too many', 422, { reason: 'too_many_children' }), S.createErrors.too_many_children],
      [new ApiError('Invalid', 422, { errors: { display_name: ['x'] } }), S.createErrors.invalid_name],
      [new TypeError('Network request failed'), S.createErrors.offline],
    ])('create failure %p → explains and stays on the form', async (error, message) => {
      createChild.mockRejectedValueOnce(error);
      renderWithQuery(<AddChildScreen onBack={jest.fn()} />);
      fireEvent.changeText(screen.getByTestId('child-nickname'), 'Maja');
      fireEvent.press(screen.getByText(S.next));
      await flush();

      expect(screen.getByTestId('add-child-error')).toHaveTextContent(message);
      expect(screen.getByTestId('child-nickname')).toBeTruthy();
    });

    it('existing child without a pet starts at the pet choice', async () => {
      const child = makeFamilyChild({ id: 5, name: 'Maja' });
      renderWithQuery(<AddChildScreen onBack={jest.fn()} child={child} />);
      expect(screen.getByText(S.petTitle('Maja'))).toBeTruthy();
      expect(createChild).not.toHaveBeenCalled();
    });
  });

  describe('PIN step', () => {
    it('re-login: PIN for the paired child right away (child_id only), shown large and grouped', async () => {
      let resolvePin: (value: ReturnType<typeof pinResponse>) => void = () => undefined;
      generatePin.mockReturnValueOnce(new Promise((resolve) => (resolvePin = resolve)));
      renderRelogin();

      expect(screen.getByText(S.titleRelogin)).toBeTruthy();
      expect(await screen.findByText(S.generating)).toBeTruthy();
      expect(generatePin).toHaveBeenCalledWith({ child_id: 2, pet_id: null });
      resolvePin(pinResponse('734912'));
      await flush();
      expect(screen.getByText('734 912')).toBeTruthy();
      expect(generatePin).toHaveBeenCalledTimes(1);
      expect(screen.getByText(S.reloginNote)).toBeTruthy();
    });

    it('counts down live and switches to "expired" after 15 minutes', async () => {
      generatePin.mockResolvedValueOnce(pinResponse('734912'));
      renderRelogin();
      await flush();

      expect(screen.getByTestId('pin-countdown')).toHaveTextContent(S.validFor('15:00'));
      act(() => {
        jest.advanceTimersByTime(1_000);
      });
      expect(screen.getByTestId('pin-countdown')).toHaveTextContent(S.validFor('14:59'));
      act(() => {
        jest.advanceTimersByTime(14 * 60_000 + 59_000);
      });
      expect(screen.queryByTestId('pin-countdown')).toBeNull();
      expect(screen.getByText(S.expired)).toBeTruthy();
    });

    it('"Nova koda" replaces the PIN for the same child and restarts the countdown', async () => {
      generatePin.mockResolvedValueOnce(pinResponse('734912'));
      renderRelogin();
      await flush();
      act(() => {
        jest.advanceTimersByTime(5 * 60_000);
      });

      generatePin.mockResolvedValueOnce(pinResponse('004501'));
      fireEvent.press(screen.getByText(S.newCode));
      await flush();
      expect(screen.getByText('004 501')).toBeTruthy();
      expect(screen.getByTestId('pin-countdown')).toHaveTextContent(S.validFor('15:00'));
      expect(generatePin).toHaveBeenNthCalledWith(2, { child_id: 2, pet_id: null });
    });

    it('429 keeps the still-valid PIN, explains the wait and blocks "Nova koda" until Retry-After', async () => {
      generatePin.mockResolvedValueOnce(pinResponse('734912'));
      renderRelogin();
      await flush();

      generatePin.mockRejectedValueOnce(new ApiError('Too Many Attempts.', 429, null, 30));
      fireEvent.press(screen.getByText(S.newCode));
      await flush();

      expect(screen.getByTestId('pin-error')).toHaveTextContent(S.errors.rate_limited(30));
      expect(screen.getByText('734 912')).toBeTruthy();

      fireEvent.press(screen.getByText(S.newCode));
      expect(generatePin).toHaveBeenCalledTimes(2); // disabled during cooldown

      act(() => {
        jest.advanceTimersByTime(30_000);
      });
      expect(screen.queryByTestId('pin-error')).toBeNull();
      generatePin.mockResolvedValueOnce(pinResponse('111222'));
      fireEvent.press(screen.getByText(S.newCode));
      await flush();
      expect(screen.getByText('111 222')).toBeTruthy();
    });

    it('offline first PIN → message and retry', async () => {
      generatePin.mockRejectedValueOnce(new TypeError('Network request failed'));
      renderRelogin();
      await flush();

      expect(screen.getByTestId('pin-error')).toHaveTextContent(S.errors.offline);
      expect(screen.queryByTestId('pairing-pin')).toBeNull();

      generatePin.mockResolvedValueOnce(pinResponse('734912'));
      fireEvent.press(screen.getByText(S.retry));
      await flush();
      expect(screen.getByText('734 912')).toBeTruthy();
    });

    it.each([
      [new ApiError('x', 403), S.errors.forbidden],
      [new ApiError('x', 404, { reason: 'child_not_found' }), S.errors.child_not_found],
      [new ApiError('x', 422, { reason: 'pet_not_joinable' }), S.errors.pet_not_joinable],
      [new ApiError('x', 422, { reason: 'already_paired' }), S.errors.already_paired],
      [new ApiError('x', 500), S.errors.server],
    ])('maps %p to a parent-friendly message', async (error, message) => {
      generatePin.mockRejectedValueOnce(error);
      renderRelogin();
      await flush();

      expect(screen.getByTestId('pin-error')).toHaveTextContent(message);
    });
  });

  describe('waiting for the child', () => {
    it('re-login: polls until the child shows one more device → "Otrok je povezan!", then stops', async () => {
      generatePin.mockResolvedValueOnce(pinResponse('734912'));
      const onBack = jest.fn();
      renderWithQuery(<AddChildScreen onBack={onBack} child={PAIRED_CHILD} />);
      await flush();
      const callsBefore = getParentDashboard.mock.calls.length;

      getParentDashboard.mockResolvedValue(makeFamilyDashboard([{ ...PAIRED_CHILD, devices: 2 }], [makeFamilyPet()]));
      await act(async () => {
        await jest.advanceTimersByTimeAsync(5_000);
      });

      expect(getParentDashboard.mock.calls.length).toBeGreaterThan(callsBefore);
      expect(screen.getByTestId('add-child-paired')).toBeTruthy();
      expect(screen.getByText(S.pairedBody.relogin('Luka'))).toBeTruthy();
      expect(getUser).not.toHaveBeenCalled(); // the parent's session pet doesn't change

      const callsAtPairing = getParentDashboard.mock.calls.length;
      await act(async () => {
        await jest.advanceTimersByTimeAsync(60_000);
      });
      expect(getParentDashboard.mock.calls.length).toBe(callsAtPairing);

      fireEvent.press(screen.getByText(S.toDashboard));
      expect(onBack).toHaveBeenCalled();
    });

    it('first pairing: done when the child shows a pet; the session pet is refreshed', async () => {
      const child = makeFamilyChild({ id: 5, name: 'Maja' });
      getParentDashboard.mockResolvedValue(makeFamilyDashboard([child]));
      generatePin.mockResolvedValueOnce(pinResponse('734912', { child_id: 5, mode: 'new_pet' }));
      const pet = makePet();
      getUser.mockResolvedValue({ id: 1, name: 'Starš', email: 'p@x.si', role: 'parent', pet });
      renderWithQuery(<AddChildScreen onBack={jest.fn()} child={child} />);
      await flush();
      fireEvent.press(screen.getByTestId('pet-option-new'));
      await flush();
      await pickDog();

      getParentDashboard.mockResolvedValue(makeFamilyDashboard([{ ...child, pet_id: 7, devices: 1 }], [makeFamilyPet()]));
      await act(async () => {
        await jest.advanceTimersByTimeAsync(5_000);
      });

      expect(screen.getByText(S.pairedBody.new_pet('Maja'))).toBeTruthy();
      await flush();
      expect(useAppStore.getState().pet).toEqual(pet);
    });

    it('stops polling once the PIN has expired', async () => {
      generatePin.mockResolvedValueOnce(pinResponse('734912', { minutes: 1 }));
      renderRelogin();
      await flush();

      await act(async () => {
        await jest.advanceTimersByTimeAsync(61_000);
      });
      expect(screen.getByText(S.expired)).toBeTruthy();
      const callsAtExpiry = getParentDashboard.mock.calls.length;

      await act(async () => {
        await jest.advanceTimersByTimeAsync(60_000);
      });
      expect(getParentDashboard.mock.calls.length).toBe(callsAtExpiry);
    });

    it('stops polling when the screen is left (unmount)', async () => {
      generatePin.mockResolvedValueOnce(pinResponse('734912'));
      const { unmount } = renderRelogin();
      await flush();
      await act(async () => {
        await jest.advanceTimersByTimeAsync(10_000);
      });
      const callsBeforeUnmount = getParentDashboard.mock.calls.length;
      expect(callsBeforeUnmount).toBeGreaterThan(1); // it was polling

      unmount();
      await act(async () => {
        await jest.advanceTimersByTimeAsync(60_000);
      });
      expect(getParentDashboard.mock.calls.length).toBe(callsBeforeUnmount);
    });
  });
  describe('"Izberi kužka" picker (M5-R04)', () => {
    const NEW_CHILD = makeFamilyChild({ id: 5, name: 'Maja' });

    beforeEach(() => {
      generatePin.mockResolvedValue(pinResponse('734912', { child_id: 5, mode: 'new_pet' }));
    });

    async function openPicker() {
      renderWithQuery(<AddChildScreen onBack={jest.fn()} child={NEW_CHILD} />);
      await flush();
      fireEvent.press(screen.getByTestId('pet-option-new'));
      await flush();
    }

    it('shows breed, origin and age with honest one-line descriptions; confirm needs origin + age', async () => {
      await openPicker();
      expect(screen.getByText(PICKER.title('Maja'))).toBeTruthy();
      // Confirmed numbers for the selected breed (mutt): puppy 2.000 → 6.000, young / adult 6.000, senior 4.500.
      expect(screen.getByText(PICKER.ageHints.mutt.puppy)).toBeTruthy();
      expect(screen.getByText(PICKER.ageHints.mutt.young)).toHaveTextContent(/6\.000/);
      expect(screen.getByText(PICKER.ageHints.mutt.senior)).toHaveTextContent(/4\.500/);
      expect(screen.getByText(PICKER.ageHints.mutt.puppy)).toHaveTextContent(/2\.000.*6\.000/);
      expect(screen.getByText(PICKER.originHints.adopted)).toBeTruthy();

      fireEvent.press(screen.getByTestId('dog-picker-confirm'));
      fireEvent.press(screen.getByTestId('origin-option-bought'));
      fireEvent.press(screen.getByTestId('dog-picker-confirm'));
      await flush();
      expect(generatePin).not.toHaveBeenCalled(); // age still missing — never a partial set

      fireEvent.press(screen.getByTestId('age-option-senior'));
      fireEvent.press(screen.getByTestId('dog-picker-confirm'));
      await flush();
      expect(generatePin).toHaveBeenCalledTimes(1);
      expect(generatePin).toHaveBeenCalledWith({
        child_id: 5,
        pet_id: null,
        profile: { breed: 'mutt', origin: 'bought', age_stage: 'senior' },
      });
    });

    it('a premium breed is visible but locked: tapping explains, the mutt stays selected', async () => {
      await openPicker();
      const collie = screen.getByTestId('breed-option-border_collie');
      expect(collie.props.accessibilityState).toEqual({ checked: false, disabled: true });

      fireEvent.press(collie);
      expect(screen.getByTestId('breed-locked-note')).toHaveTextContent(PICKER.breedLockedNote);
      expect(screen.getByTestId('breed-option-mutt').props.accessibilityState).toEqual({ checked: true, disabled: false });

      await pickDog('bought', 'puppy');
      expect(generatePin).toHaveBeenCalledWith(expect.objectContaining({ profile: { breed: 'mutt', origin: 'bought', age_stage: 'puppy' } }));
    });

    // A paid breed can't be chosen in the UI yet (locked client-side), so the server-refusal round trip
    // is exercised with a 422 validation error; breed_locked for the free mutt has its own test below.
    it('422 invalid_profile → explained, "Izberi drugega kužka" returns to the picker, choice kept', async () => {
      generatePin.mockRejectedValueOnce(new ApiError('invalid', 422, { message: 'invalid', errors: { origin: ['The origin field is required.'] } }));
      await openPicker();
      await pickDog('adopted', 'adult');

      expect(screen.getByTestId('pin-error')).toHaveTextContent(S.errors.invalid_profile);
      expect(screen.queryByText(S.newCode)).toBeNull(); // no "Nova koda" while the choice is refused
      expect(screen.queryByText(S.retry)).toBeNull();
      fireEvent.press(screen.getByTestId('pin-change-dog'));
      await flush();

      expect(screen.getByTestId('dog-picker-notice')).toHaveTextContent(S.errors.invalid_profile);
      expect(screen.getByTestId('origin-option-adopted').props.accessibilityState.checked).toBe(true);
      expect(screen.getByTestId('age-option-adult').props.accessibilityState.checked).toBe(true);

      generatePin.mockResolvedValueOnce(pinResponse('555666', { child_id: 5, mode: 'new_pet' }));
      fireEvent.press(screen.getByTestId('dog-picker-confirm'));
      await flush();
      expect(generatePin).toHaveBeenLastCalledWith({
        child_id: 5,
        pet_id: null,
        profile: { breed: 'mutt', origin: 'adopted', age_stage: 'adult' },
      });
      expect(screen.getByText('555 666')).toBeTruthy();
    });

    it('breed_locked for the free mutt → support message, no loop back to the picker', async () => {
      generatePin.mockRejectedValueOnce(new ApiError('locked', 422, { reason: 'breed_locked', message: 'locked' }));
      await openPicker();
      await pickDog('bought', 'adult');

      expect(screen.getByTestId('pin-error')).toHaveTextContent(S.muttLocked);
      expect(screen.queryByTestId('pin-change-dog')).toBeNull();
      // Retrying is allowed (the server config may be fixed meanwhile) — it never loops back to the picker.
      generatePin.mockResolvedValueOnce(pinResponse('777888', { child_id: 5, mode: 'new_pet' }));
      fireEvent.press(screen.getByText(S.retry));
      await flush();
      expect(generatePin).toHaveBeenLastCalledWith({
        child_id: 5,
        pet_id: null,
        profile: { breed: 'mutt', origin: 'bought', age_stage: 'adult' },
      });
      expect(screen.getByText('777 888')).toBeTruthy();
    });

    it('existing child without a pet: Nov pes → picker → PIN with the profile', async () => {
      await openPicker();
      expect(screen.getByTestId('dog-picker')).toBeTruthy();
      expect(generatePin).not.toHaveBeenCalled();
      await pickDog('adopted', 'puppy');
      expect(generatePin).toHaveBeenCalledWith({
        child_id: 5,
        pet_id: null,
        profile: { breed: 'mutt', origin: 'adopted', age_stage: 'puppy' },
      });
    });

    it('"Spremeni kužka" before the child connects returns to the picker; confirming asks for a new PIN', async () => {
      await openPicker();
      await pickDog('bought', 'puppy');
      expect(screen.getByText('734 912')).toBeTruthy();

      fireEvent.press(screen.getByTestId('pin-edit-dog'));
      await flush();
      expect(screen.getByTestId('origin-option-bought').props.accessibilityState.checked).toBe(true);
      fireEvent.press(screen.getByTestId('age-option-senior'));
      fireEvent.press(screen.getByTestId('dog-picker-confirm'));
      await flush();
      expect(generatePin).toHaveBeenLastCalledWith({
        child_id: 5,
        pet_id: null,
        profile: { breed: 'mutt', origin: 'bought', age_stage: 'senior' },
      });
    });

    it('"Spremeni kužka" + the same choice reuses the still-valid PIN (no new request)', async () => {
      await openPicker();
      await pickDog('bought', 'puppy');
      expect(generatePin).toHaveBeenCalledTimes(1);

      fireEvent.press(screen.getByTestId('pin-edit-dog'));
      await flush();
      fireEvent.press(screen.getByTestId('dog-picker-confirm'));
      await flush();

      expect(generatePin).toHaveBeenCalledTimes(1);
      expect(screen.getByText('734 912')).toBeTruthy();
      expect(screen.queryByTestId('pin-previous-choice')).toBeNull();
    });

    it('"Spremeni kužka" + a 429 for the new choice keeps showing the still-valid PIN, marked as the previous choice', async () => {
      await openPicker();
      await pickDog('bought', 'puppy');
      expect(screen.getByText('734 912')).toBeTruthy();

      fireEvent.press(screen.getByTestId('pin-edit-dog'));
      await flush();
      generatePin.mockRejectedValueOnce(new ApiError('Too Many Attempts.', 429, null, 30));
      fireEvent.press(screen.getByTestId('age-option-adult'));
      fireEvent.press(screen.getByTestId('dog-picker-confirm'));
      await flush();

      expect(screen.getByText('734 912')).toBeTruthy();
      expect(screen.getByTestId('pin-previous-choice')).toHaveTextContent(S.previousChoicePin);
      expect(screen.getByTestId('pin-error')).toHaveTextContent(S.errors.rate_limited(30));

      // After the wait, "Nova koda" asks for the new choice and the note disappears.
      generatePin.mockResolvedValueOnce(pinResponse('888999', { child_id: 5, mode: 'new_pet' }));
      await act(async () => {
        await jest.advanceTimersByTimeAsync(31_000);
      });
      fireEvent.press(screen.getByText(S.newCode));
      await flush();
      expect(generatePin).toHaveBeenLastCalledWith({
        child_id: 5,
        pet_id: null,
        profile: { breed: 'mutt', origin: 'bought', age_stage: 'adult' },
      });
      expect(screen.getByText('888 999')).toBeTruthy();
      expect(screen.queryByTestId('pin-previous-choice')).toBeNull();
    });

    it('"Nazaj" on the dog step returns to new pet / join, keeping the choice; no PIN requested', async () => {
      await openPicker();
      fireEvent.press(screen.getByTestId('origin-option-adopted'));
      fireEvent.press(screen.getByTestId('dog-picker-back'));
      await flush();

      expect(screen.queryByTestId('dog-picker')).toBeNull();
      expect(screen.getByTestId('pet-option-new')).toBeTruthy();
      expect(generatePin).not.toHaveBeenCalled();

      fireEvent.press(screen.getByTestId('pet-option-new'));
      await flush();
      expect(screen.getByTestId('origin-option-adopted').props.accessibilityState.checked).toBe(true);
    });

    it('join / re-login PIN has no "Spremeni kužka"', async () => {
      generatePin.mockResolvedValueOnce(pinResponse('734912'));
      renderRelogin();
      await flush();
      expect(screen.queryByTestId('pin-edit-dog')).toBeNull();
    });

    it('"Nova koda" for a new pet resends the full profile', async () => {
      generatePin.mockResolvedValueOnce(pinResponse('111111', { child_id: 5, mode: 'new_pet' }));
      generatePin.mockResolvedValueOnce(pinResponse('222222', { child_id: 5, mode: 'new_pet' }));
      await openPicker();
      await pickDog('bought', 'young');

      fireEvent.press(screen.getByText(S.newCode));
      await flush();
      expect(generatePin).toHaveBeenNthCalledWith(2, {
        child_id: 5,
        pet_id: null,
        profile: { breed: 'mutt', origin: 'bought', age_stage: 'young' },
      });
    });

    it('re-login of a paired child never shows the picker', async () => {
      generatePin.mockResolvedValueOnce(pinResponse('734912'));
      renderRelogin();
      await flush();
      expect(screen.queryByTestId('dog-picker')).toBeNull();
      expect(generatePin).toHaveBeenCalledWith({ child_id: 2, pet_id: null });
    });
  });
});
