/**
 * M3-11 / M3-09 child + deletion side: `payment_required` lock (kind, no prices) and the
 * paid-challenge acknowledgement before a deletion (PAYMENTS_SPEC P3, P5).
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import DeletionConfirmForm from '@/components/parent/DeletionConfirmForm';
import { classifyDeletionError, deletionLosesPurchase } from '@/modules/account/account';
import { lockMessage } from '@/modules/childPet/actionMessages';
import { lockFromFlags, lockStateFromView } from '@/modules/childPet/childPetView';
import { readPetPlan } from '@/modules/plan/plan';
import { isTranslucentLock, lockedCopy } from '@/screens/LockedScreen';
import { lockStateFromPet } from '@/store/appStore';
import { ApiError } from '@/api/client';

const paused = { type: 'challenge', status: 'payment_required', trial_ends_at: '2026-10-08T10:00:00+02:00', paid_at: null };

describe('payment_required lock (child)', () => {
  it('comes after hard stop and before illness in the server priority', () => {
    const base = {
      is_game_over: false,
      is_active: true,
      is_hard_stopped: false,
      awaiting_contract: false,
      is_ill: true,
      illness_until: null,
      plan: readPetPlan(paused),
    };
    expect(lockFromFlags(base as never).reason).toBe('payment_required');
    expect(lockFromFlags({ ...base, is_hard_stopped: true } as never).reason).toBe('hard_stopped');
    expect(lockStateFromView({ lock: { is_locked: true, reason: 'payment_required', until: null } } as never)).toBe('payment_required');
    expect(
      lockStateFromPet({ is_game_over: false, is_active: true, is_hard_stopped: false, illness_until: null, plan: paused } as never),
    ).toBe('payment_required');
    // An old server without `plan` never locks anybody.
    expect(lockStateFromPet({ is_game_over: false, is_active: true, illness_until: null } as never)).toBe('none');
  });

  it('kind copy, no prices; the dog stays visible behind the veil', () => {
    const copy = lockedCopy('payment_required', { until: null, timezone: null });
    expect(copy.title).toBeTruthy();
    expect(`${copy.title} ${copy.body}`).not.toMatch(/€|\d/);
    expect(isTranslucentLock('payment_required')).toBe(true);
    expect(lockMessage('payment_required', null, null)).toBeTruthy();
  });
});

describe('paid challenge acknowledgement before deletion (P5)', () => {
  it('billing decides which deletions lose a purchase', () => {
    const billing = { pets: [{ pet_id: 7, deletion_loses_purchase: true }, { pet_id: 8, deletion_loses_purchase: false }] };
    expect(deletionLosesPurchase([7], billing)).toBe(true);
    expect(deletionLosesPurchase([8], billing)).toBe(false);
    expect(deletionLosesPurchase([7], undefined)).toBe(false);
    expect(classifyDeletionError(new ApiError('x', 422, { reason: 'paid_challenge_ack_required' }))).toBe('paid_challenge');
  });

  it('the red button needs the extra checkbox; the acknowledgement is passed on', () => {
    const onSubmit = jest.fn();
    render(
      <DeletionConfirmForm
        consequences={['x']}
        submitLabel="Izbriši"
        pending={false}
        error={null}
        onCancel={jest.fn()}
        onSubmit={onSubmit}
        paidChallenge
        testID="f"
      />,
    );
    expect(screen.getByTestId('f-paid-warning')).toBeTruthy();
    fireEvent.changeText(screen.getByTestId('f-password'), 'Varno1Geslo');
    fireEvent.changeText(screen.getByTestId('f-confirm'), 'IZBRIŠI');
    expect(screen.getByTestId('f-submit').props.accessibilityState).toEqual({ disabled: true });
    fireEvent.press(screen.getByTestId('f-paid-ack'));
    expect(screen.getByTestId('f-submit').props.accessibilityState).toEqual({ disabled: false });
    fireEvent.press(screen.getByTestId('f-submit'));
    expect(onSubmit).toHaveBeenCalledWith('Varno1Geslo', true);
  });
});
