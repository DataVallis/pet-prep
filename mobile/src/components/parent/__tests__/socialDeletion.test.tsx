/**
 * M2-10c: deletions for a parent who signed in with Google / Apple (no password) — the
 * password field is replaced by "Potrdi z Googlom / Apple", which re-runs the provider
 * sign-in (fresh token; Apple with its raw nonce) and sends `{provider, id_token, nonce?}`
 * instead of a password. The confirmation word stays required.
 */
import { act, cleanup, fireEvent, screen } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import FamilyChildrenCard from '@/components/FamilyChildrenCard';
import AccountCard, { ACCOUNT_STRINGS } from '@/components/parent/AccountCard';
import DeletionConfirmForm, { DELETION_FORM_STRINGS } from '@/components/parent/DeletionConfirmForm';
import { SocialProviderError, obtainProviderCredential } from '@/modules/auth/socialSdk';
import { familyFromDashboard, type FamilyOverview } from '@/modules/family/family';
import { useAppStore } from '@/store/appStore';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { FAMILY_STRINGS } from '@/components/FamilyChildrenCard';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, deleteAccount: jest.fn(), deleteChild: jest.fn(), logout: jest.fn(), getParentDashboard: jest.fn() },
  };
});

jest.mock('@/modules/auth/socialSdk', () => ({
  ...jest.requireActual<typeof import('@/modules/auth/socialSdk')>('@/modules/auth/socialSdk'),
  obtainProviderCredential: jest.fn(),
  // This "device" can run both providers; the per-platform rule is tested in authMethods.test.ts.
  canOfferProvider: jest.fn(() => true),
}));

const deleteAccount = api.deleteAccount as jest.Mock;
const deleteChild = api.deleteChild as jest.Mock;
const obtain = obtainProviderCredential as jest.Mock;

const FAMILY = familyFromDashboard(
  makeScoredDashboard(
    [makeScoredChild({ id: 2, name: 'Maja', pet_id: 7 })],
    [makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] })],
  ) as never,
) as FamilyOverview;

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

/** Unmount first, then reset the store — a store update on a mounted form is an act() warning. */
function resetSession() {
  cleanup();
  useAppStore.setState({ signInMethod: 'password', authMethods: null });
}

describe('account deletion — parent signed in with Google', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState({ signInMethod: 'password', authMethods: { hasPassword: false, linkedProviders: ['google'] } });
  });
  afterEach(resetSession);

  it('no password field; the confirmation word unlocks "Potrdi z Googlom", which re-signs in and sends the token', async () => {
    obtain.mockResolvedValueOnce({ kind: 'credential', credential: { provider: 'google', idToken: 'fresh.google.jwt', name: 'Ana' } });
    deleteAccount.mockRejectedValueOnce(new ApiError('stop here', 500, null));
    renderWithQuery(<AccountCard family={null} />);

    fireEvent.press(screen.getByTestId('account-delete'));
    expect(screen.queryByTestId('account-delete-form-password')).toBeNull();
    expect(screen.getByText(DELETION_FORM_STRINGS.reauth.googleHint)).toBeTruthy();
    expect(screen.getByText(DELETION_FORM_STRINGS.reauth.google)).toBeTruthy();

    const submit = () => screen.getByTestId('account-delete-form-submit');
    expect(submit().props.accessibilityState).toEqual({ disabled: true });
    fireEvent.changeText(screen.getByTestId('account-delete-form-confirm'), 'IZBRIŠI');
    expect(submit().props.accessibilityState).toEqual({ disabled: false });

    fireEvent.press(submit());
    await flush();

    expect(obtain).toHaveBeenCalledWith('google');
    expect(deleteAccount).toHaveBeenCalledWith({ provider: 'google', id_token: 'fresh.google.jwt' }, 'IZBRIŠI', false);
  });

  it('a stale / foreign token (422 reauth_required) keeps the form open with a clear message', async () => {
    obtain.mockResolvedValueOnce({ kind: 'credential', credential: { provider: 'google', idToken: 'old.jwt' } });
    deleteAccount.mockRejectedValueOnce(new ApiError('reauth', 422, { message: 'reauth', reason: 'reauth_required' }));
    renderWithQuery(<AccountCard family={null} />);

    fireEvent.press(screen.getByTestId('account-delete'));
    fireEvent.changeText(screen.getByTestId('account-delete-form-confirm'), 'IZBRIŠI');
    fireEvent.press(screen.getByTestId('account-delete-form-submit'));
    await flush();

    expect(screen.getByTestId('account-delete-form-error').props.children).toBe(ACCOUNT_STRINGS.deleteErrors.reauth_required);
    expect(screen.getByTestId('account-delete-form')).toBeTruthy();
  });

  it('closing the Google sheet sends nothing and shows no error', async () => {
    obtain.mockResolvedValueOnce({ kind: 'cancelled' });
    renderWithQuery(<AccountCard family={null} />);

    fireEvent.press(screen.getByTestId('account-delete'));
    fireEvent.changeText(screen.getByTestId('account-delete-form-confirm'), 'IZBRIŠI');
    fireEvent.press(screen.getByTestId('account-delete-form-submit'));
    await flush();

    expect(deleteAccount).not.toHaveBeenCalled();
    expect(screen.queryByTestId('account-delete-form-error')).toBeNull();
  });
});

describe('DeletionConfirmForm — Apple re-auth', () => {
  beforeEach(() => jest.clearAllMocks());

  it('sends the Apple token with its raw nonce (+ the paid-challenge ack)', async () => {
    const onSubmit = jest.fn();
    const obtainProof = jest.fn(async () => ({ provider: 'apple' as const, id_token: 'apple.jwt', nonce: 'raw-nonce' }));
    renderWithQuery(
      <DeletionConfirmForm
        consequences={['x']}
        submitLabel="Izbriši"
        pending={false}
        error={null}
        onCancel={jest.fn()}
        onSubmit={onSubmit}
        paidChallenge
        signInMethod="apple"
        obtainProof={obtainProof}
        testID="f"
      />,
    );

    expect(screen.getByText(DELETION_FORM_STRINGS.reauth.appleHint)).toBeTruthy();
    fireEvent.changeText(screen.getByTestId('f-confirm'), 'IZBRIŠI');
    expect(screen.getByTestId('f-submit').props.accessibilityState).toEqual({ disabled: true });
    fireEvent.press(screen.getByTestId('f-paid-ack'));
    fireEvent.press(screen.getByTestId('f-submit'));
    await flush();

    expect(obtainProof).toHaveBeenCalledWith('apple');
    expect(onSubmit).toHaveBeenCalledWith({ provider: 'apple', id_token: 'apple.jwt', nonce: 'raw-nonce' }, true);
  });

  it('a failing provider shows "couldn\'t confirm" and submits nothing', async () => {
    const onSubmit = jest.fn();
    renderWithQuery(
      <DeletionConfirmForm
        consequences={['x']}
        submitLabel="Izbriši"
        pending={false}
        error={null}
        onCancel={jest.fn()}
        onSubmit={onSubmit}
        signInMethod="google"
        obtainProof={jest.fn(async () => Promise.reject(new SocialProviderError('failed')))}
        testID="f"
      />,
    );
    fireEvent.changeText(screen.getByTestId('f-confirm'), 'IZBRIŠI');
    fireEvent.press(screen.getByTestId('f-submit'));
    await flush();

    expect(onSubmit).not.toHaveBeenCalled();
    expect(screen.getByTestId('f-error').props.children).toBe(DELETION_FORM_STRINGS.reauth.failed);
  });

  it('a password parent keeps the password field (unchanged M2-08 behaviour)', () => {
    renderWithQuery(
      <DeletionConfirmForm consequences={['x']} submitLabel="Izbriši" pending={false} error={null} onCancel={jest.fn()} onSubmit={jest.fn()} signInMethod="password" testID="f" />,
    );
    expect(screen.getByTestId('f-password')).toBeTruthy();
    expect(screen.queryByTestId('f-reauth-hint')).toBeNull();
  });
});

describe('child deletion — parent signed in with Apple', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    // Old cached session without the server fields → the remembered method decides.
    useAppStore.setState({ signInMethod: 'apple', authMethods: null });
  });
  afterEach(resetSession);

  it('sends {provider, id_token, nonce, authorization_code} instead of the password', async () => {
    obtain.mockResolvedValueOnce({
      kind: 'credential',
      credential: { provider: 'apple', idToken: 'apple.jwt', nonce: 'raw-nonce', authorizationCode: 'apple-code' },
    });
    deleteChild.mockResolvedValueOnce({ status: 'deleted', child_id: 2, pets_deleted: 1, pets_kept: 0 });
    renderWithQuery(<FamilyChildrenCard family={FAMILY} onAddChild={jest.fn()} onChildPin={jest.fn()} />);

    fireEvent.press(screen.getByTestId('child-delete-2'));
    expect(screen.queryByTestId('child-delete-form-2-password')).toBeNull();
    fireEvent.changeText(screen.getByTestId('child-delete-form-2-confirm'), 'IZBRIŠI');
    fireEvent.press(screen.getByTestId('child-delete-form-2-submit'));
    await flush();

    expect(obtain).toHaveBeenCalledWith('apple');
    expect(deleteChild).toHaveBeenCalledWith(
      2,
      { provider: 'apple', id_token: 'apple.jwt', nonce: 'raw-nonce', authorization_code: 'apple-code' },
      'IZBRIŠI',
      false,
    );
    expect(screen.getByTestId('child-deleted-notice').props.children).toBe(FAMILY_STRINGS.deleted('Maja'));
  });

  it('provider unavailable on the server → its own message, form stays', async () => {
    obtain.mockResolvedValueOnce({ kind: 'credential', credential: { provider: 'apple', idToken: 'apple.jwt', nonce: 'n' } });
    deleteChild.mockRejectedValueOnce(new ApiError('down', 503, { message: 'down', reason: 'provider_unavailable' }));
    renderWithQuery(<FamilyChildrenCard family={FAMILY} onAddChild={jest.fn()} onChildPin={jest.fn()} />);

    fireEvent.press(screen.getByTestId('child-delete-2'));
    fireEvent.changeText(screen.getByTestId('child-delete-form-2-confirm'), 'IZBRIŠI');
    fireEvent.press(screen.getByTestId('child-delete-form-2-submit'));
    await flush();

    expect(screen.getByTestId('child-delete-form-2-error').props.children).toBe(FAMILY_STRINGS.deleteErrors.provider_unavailable);
  });
});

describe('which identity the form asks for comes from the server (QA m1)', () => {
  const base = { consequences: ['x'], submitLabel: 'Izbriši', pending: false, error: null, onCancel: jest.fn(), testID: 'f' };
  beforeEach(() => jest.clearAllMocks());
  afterEach(resetSession);

  it('has_password wins over a remembered social sign-in → the password field', () => {
    useAppStore.setState({ signInMethod: 'google', authMethods: { hasPassword: true, linkedProviders: [] } });
    renderWithQuery(<DeletionConfirmForm {...base} onSubmit={jest.fn()} />);
    expect(screen.getByTestId('f-password')).toBeTruthy();
    expect(screen.queryByTestId('f-reauth-hint')).toBeNull();
  });

  it('a remembered password sign-in but the server says Google only → Google re-auth', () => {
    useAppStore.setState({ signInMethod: 'password', authMethods: { hasPassword: false, linkedProviders: ['google'] } });
    renderWithQuery(<DeletionConfirmForm {...base} onSubmit={jest.fn()} />);
    expect(screen.queryByTestId('f-password')).toBeNull();
    expect(screen.getByText(DELETION_FORM_STRINGS.reauth.google)).toBeTruthy();
  });

  it('Google + Apple linked and both runnable → one button each; the pressed one is used', async () => {
    const onSubmit = jest.fn();
    const obtainProof = jest.fn(async () => ({ provider: 'apple' as const, id_token: 'a', nonce: 'n', authorization_code: 'c' }));
    renderWithQuery(
      <DeletionConfirmForm {...base} onSubmit={onSubmit} authMethods={{ hasPassword: false, linkedProviders: ['google', 'apple'] }} obtainProof={obtainProof} />,
    );
    expect(screen.getByText(DELETION_FORM_STRINGS.reauth.anyHint)).toBeTruthy();
    expect(screen.queryByTestId('f-submit')).toBeNull();
    expect(screen.getByTestId('f-submit-google').props.accessibilityState).toEqual({ disabled: true });

    fireEvent.changeText(screen.getByTestId('f-confirm'), 'IZBRIŠI');
    fireEvent.press(screen.getByTestId('f-submit-apple'));
    await flush();

    expect(obtainProof).toHaveBeenCalledWith('apple');
    expect(onSubmit).toHaveBeenCalledWith({ provider: 'apple', id_token: 'a', nonce: 'n', authorization_code: 'c' }, false);
  });

  it('only the linked providers this device can run are offered (Apple not on Android)', () => {
    renderWithQuery(
      <DeletionConfirmForm
        {...base}
        onSubmit={jest.fn()}
        authMethods={{ hasPassword: false, linkedProviders: ['google', 'apple'] }}
        canOffer={(p) => p === 'google'}
      />,
    );
    expect(screen.getByTestId('f-submit')).toBeTruthy();
    expect(screen.getByText(DELETION_FORM_STRINGS.reauth.google)).toBeTruthy();
    expect(screen.queryByText(DELETION_FORM_STRINGS.reauth.apple)).toBeNull();
  });

  it('Apple-only account on Android: explains, no way to submit, nothing crashes', () => {
    const onSubmit = jest.fn();
    renderWithQuery(
      <DeletionConfirmForm {...base} onSubmit={onSubmit} authMethods={{ hasPassword: false, linkedProviders: ['apple'] }} canOffer={() => false} />,
    );
    expect(screen.getByText(DELETION_FORM_STRINGS.reauth.unavailable)).toBeTruthy();
    expect(screen.queryByTestId('f-password')).toBeNull();
    fireEvent.changeText(screen.getByTestId('f-confirm'), 'IZBRIŠI');
    expect(screen.getByTestId('f-submit').props.accessibilityState).toEqual({ disabled: true });
    fireEvent.press(screen.getByTestId('f-submit'));
    expect(onSubmit).not.toHaveBeenCalled();
  });
});
