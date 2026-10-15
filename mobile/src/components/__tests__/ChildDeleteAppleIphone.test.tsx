/**
 * Regression of David's iPhone B (2026-10-14): parent account created with Sign in with Apple
 * on another iPhone, signed in with Apple here; deleting a child showed "this phone can't use
 * your sign-in method" and the button stayed disabled. With the real default provider check
 * the form now offers »Potrdi z Apple« and sends the Apple proof; contradictory methods
 * ("no password, nothing linked") are re-read from `GET /api/user` before deciding.
 */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';

import { api } from '@/api/client';
import FamilyChildrenCard, { FAMILY_STRINGS } from '@/components/FamilyChildrenCard';
import { DELETION_FORM_STRINGS } from '@/components/parent/DeletionConfirmForm';
import { obtainProviderCredential } from '@/modules/auth/socialSdk';
import { familyFromDashboard, type FamilyOverview } from '@/modules/family/family';
import { useAppStore } from '@/store/appStore';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, deleteChild: jest.fn(), getUser: jest.fn(), getParentDashboard: jest.fn() } };
});

// Only the native sign-in is faked — the provider CHECK is the real one (that was the bug).
jest.mock('@/modules/auth/socialSdk', () => ({
  ...jest.requireActual<typeof import('@/modules/auth/socialSdk')>('@/modules/auth/socialSdk'),
  obtainProviderCredential: jest.fn(),
}));

const FAMILY = familyFromDashboard(
  makeScoredDashboard([makeScoredChild({ id: 2, name: 'Da', pet_id: 7 })], [makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] })]) as never,
) as FamilyOverview;

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

function signInAppleParent(linked: string[]) {
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({
    token: 'parent-token',
    user: { id: 1, name: 'David', email: 'privaterelay@example.com', role: 'parent', has_password: false, linked_providers: linked },
    pet: null,
    signInMethod: 'apple',
  });
}

beforeEach(() => jest.clearAllMocks());

it('Apple-only parent on an iPhone: »Potrdi z Apple« is offered and deletes the child', async () => {
  signInAppleParent(['apple']);
  (obtainProviderCredential as jest.Mock).mockResolvedValueOnce({
    kind: 'credential',
    credential: { provider: 'apple', idToken: 'apple.jwt', nonce: 'raw', authorizationCode: 'code' },
  });
  (api.deleteChild as jest.Mock).mockResolvedValueOnce({ status: 'deleted', child_id: 2, pets_deleted: 1, pets_kept: 0 });
  renderWithQuery(<FamilyChildrenCard family={FAMILY} onAddChild={jest.fn()} onChildPin={jest.fn()} />);

  fireEvent.press(screen.getByTestId('child-delete-2'));
  expect(screen.queryByText(DELETION_FORM_STRINGS.reauth.unavailable)).toBeNull();
  expect(screen.getByText(DELETION_FORM_STRINGS.reauth.apple)).toBeTruthy();

  fireEvent.changeText(screen.getByTestId('child-delete-form-2-confirm'), 'IZBRIŠI');
  expect(screen.getByTestId('child-delete-form-2-submit').props.accessibilityState).toEqual({ disabled: false });
  fireEvent.press(screen.getByTestId('child-delete-form-2-submit'));
  await flush();

  expect(api.deleteChild).toHaveBeenCalledWith(2, { provider: 'apple', id_token: 'apple.jwt', nonce: 'raw', authorization_code: 'code' }, 'IZBRIŠI', false);
  expect(screen.getByTestId('child-deleted-notice').props.children).toBe(FAMILY_STRINGS.deleted('Da'));
  // No extra /api/user round trip when the server already told us.
  expect(api.getUser).not.toHaveBeenCalled();
});

it('"no password, nothing linked" is re-read from /api/user before deciding', async () => {
  signInAppleParent([]);
  (api.getUser as jest.Mock).mockResolvedValueOnce({ id: 1, name: 'David', email: null, role: 'parent', pet: null, has_password: false, linked_providers: ['apple'] });
  renderWithQuery(<FamilyChildrenCard family={FAMILY} onAddChild={jest.fn()} onChildPin={jest.fn()} />);

  fireEvent.press(screen.getByTestId('child-delete-2'));
  // While checking nothing can be sent.
  expect(screen.getByTestId('child-delete-form-2-submit').props.accessibilityState).toEqual({ disabled: true });

  await waitFor(() => expect(screen.getByText(DELETION_FORM_STRINGS.reauth.apple)).toBeTruthy());
  expect(api.getUser).toHaveBeenCalledTimes(1);
  expect(useAppStore.getState().authMethods).toEqual({ hasPassword: false, linkedProviders: ['apple'] });
});
