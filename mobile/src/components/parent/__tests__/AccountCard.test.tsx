/**
 * M2-08: "Račun" in Nadzor — export through the share sheet, account deletion with
 * consequences, password + "IZBRIŠI", then sign-out to the StartScreen.
 */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';
import { Share } from 'react-native';

import { ApiError, api } from '@/api/client';
import AccountCard, { ACCOUNT_STRINGS } from '@/components/parent/AccountCard';
import { familyFromDashboard, type FamilyOverview } from '@/modules/family/family';
import AppNavigator from '@/navigation/AppNavigator';
import { START_STRINGS } from '@/screens/StartScreen';
import { useAppStore } from '@/store/appStore';
import { i18n } from '@/i18n';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      getUser: jest.fn(),
      logout: jest.fn(),
      deleteAccount: jest.fn(),
      exportFamilyData: jest.fn(),
    },
  };
});

// The parent app itself is covered elsewhere: here the dashboard is just the card.
jest.mock('@/screens/parent/ParentDashboardScreen', () => {
  const { default: Card } = jest.requireActual<typeof import('@/components/parent/AccountCard')>(
    '@/components/parent/AccountCard',
  );
  return { __esModule: true, default: () => <Card family={null} /> };
});

// Renders the real AppNavigator in one test — slow under a loaded full run.
jest.setTimeout(20_000);

const deleteAccount = api.deleteAccount as jest.Mock;
const exportFamilyData = api.exportFamilyData as jest.Mock;
const apiLogout = api.logout as jest.Mock;
const getUser = api.getUser as jest.Mock;
const getItem = SecureStore.getItemAsync as jest.Mock;
const deleteItem = SecureStore.deleteItemAsync as jest.Mock;

const FAMILY = familyFromDashboard(
  makeScoredDashboard(
    [makeScoredChild({ id: 2, name: 'Maja' }), makeScoredChild({ id: 5, name: 'Luka', pet_id: 8 })],
    [makeFamilyPet({ id: 7 }), makeFamilyPet({ id: 8 })],
  ) as never,
) as FamilyOverview;

const WITH_SECOND_PARENT: FamilyOverview = {
  ...FAMILY,
  parents: [
    { id: 1, name: 'Mama', is_me: true },
    { id: 3, name: 'Oče', is_me: false },
  ],
};

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

function openDeleteForm() {
  fireEvent.press(screen.getByTestId('account-delete'));
  return {
    password: screen.getByTestId('account-delete-form-password'),
    confirm: screen.getByTestId('account-delete-form-confirm'),
    submit: screen.getByTestId('account-delete-form-submit'),
  };
}

describe('AccountCard — export', () => {
  beforeEach(() => jest.clearAllMocks());

  it('shares the family JSON through the share sheet', async () => {
    const shareSpy = jest.spyOn(Share, 'share').mockResolvedValue({ action: Share.sharedAction });
    exportFamilyData.mockResolvedValue({ format: 'petprep.family-export', version: 1, generated_at: '2026-10-05T10:00:00+00:00' });
    renderWithQuery(<AccountCard family={FAMILY} />);

    fireEvent.press(screen.getByText(ACCOUNT_STRINGS.exportButton));
    await flush();

    expect(exportFamilyData).toHaveBeenCalledTimes(1);
    expect(shareSpy).toHaveBeenCalledWith(
      expect.objectContaining({ title: 'petprep-izvoz-2026-10-05.json', message: expect.stringContaining('petprep.family-export') }),
      expect.anything(),
    );
    expect(screen.getByTestId('account-export-result').props.children).toBe(ACCOUNT_STRINGS.exportDone);
  });

  it('a too large export is explained instead of failing in the share sheet', async () => {
    const shareSpy = jest.spyOn(Share, 'share').mockClear();
    exportFamilyData.mockResolvedValue({
      format: 'petprep.family-export',
      version: 1,
      generated_at: '2026-10-05T10:00:00+00:00',
      pets: [{ activities: 'x'.repeat(450 * 1024) }],
    });
    renderWithQuery(<AccountCard family={FAMILY} />);

    fireEvent.press(screen.getByTestId('account-export'));
    await flush();

    expect(shareSpy).not.toHaveBeenCalled();
    expect(screen.getByText(ACCOUNT_STRINGS.exportErrors.too_large_to_share)).toBeTruthy();
  });

  it('explains the hourly limit on 429', async () => {
    exportFamilyData.mockRejectedValue(new ApiError('Too Many Attempts.', 429, null, 3000));
    renderWithQuery(<AccountCard family={FAMILY} />);

    fireEvent.press(screen.getByTestId('account-export'));
    await flush();

    expect(screen.getByText(ACCOUNT_STRINGS.exportErrors.throttled)).toBeTruthy();
  });
});

describe('AccountCard — delete account', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  it('as the only parent: says the whole family incl. children and pets goes', () => {
    renderWithQuery(<AccountCard family={FAMILY} />);
    openDeleteForm();

    for (const line of ACCOUNT_STRINGS.lastParent(2, 2)) {
      expect(screen.getByText(`• ${line}`)).toBeTruthy();
    }
    expect(screen.getByText(/2 otroška profila in 2 psa/)).toBeTruthy();
  });

  it('with another parent: only this account, the family stays', () => {
    renderWithQuery(<AccountCard family={WITH_SECOND_PARENT} />);
    openDeleteForm();

    expect(screen.getByText(`• ${ACCOUNT_STRINGS.otherParentStays[1]}`)).toBeTruthy();
    expect(screen.queryByText(`• ${ACCOUNT_STRINGS.lastParent(2, 2)[0]}`)).toBeNull();
  });

  it('unlocks the button only with the password AND the typed word', () => {
    renderWithQuery(<AccountCard family={FAMILY} />);
    const form = openDeleteForm();

    expect(form.submit.props.accessibilityState).toEqual({ disabled: true });
    fireEvent.changeText(form.password, 'Varno1Geslo');
    expect(screen.getByTestId('account-delete-form-submit').props.accessibilityState).toEqual({ disabled: true });
    fireEvent.changeText(form.confirm, 'izbrisi');
    expect(screen.getByTestId('account-delete-form-submit').props.accessibilityState).toEqual({ disabled: true });
    fireEvent.changeText(form.confirm, 'IZBRIŠI');
    expect(screen.getByTestId('account-delete-form-submit').props.accessibilityState).toEqual({ disabled: false });

    fireEvent.press(screen.getByTestId('account-delete-form-cancel'));
    expect(screen.queryByTestId('account-delete-form')).toBeNull();
    expect(deleteAccount).not.toHaveBeenCalled();
  });

  it('a wrong password keeps the parent signed in and says so', async () => {
    useAppStore.setState({ authToken: 't', user: { id: 1, name: 'Mama', email: 'm@x.si', role: 'parent' } });
    deleteAccount.mockRejectedValue(new ApiError('The password is not correct.', 422, { reason: 'invalid_password' }));
    renderWithQuery(<AccountCard family={FAMILY} />);
    const form = openDeleteForm();

    fireEvent.changeText(form.password, 'napačno');
    fireEvent.changeText(form.confirm, 'IZBRIŠI');
    fireEvent.press(screen.getByTestId('account-delete-form-submit'));
    await flush();

    expect(deleteAccount).toHaveBeenCalledWith('napačno', 'IZBRIŠI', false);
    expect(screen.getByTestId('account-delete-form-error').props.children).toBe(ACCOUNT_STRINGS.deleteErrors.invalid_password);
    expect(useAppStore.getState().user?.id).toBe(1);
    expect(deleteItem).not.toHaveBeenCalled();
  });

  it('no answer after sending (offline / timeout): says the outcome is unknown, stays on the screen', async () => {
    useAppStore.setState({ authToken: 't', user: { id: 1, name: 'Mama', email: 'm@x.si', role: 'parent' } });
    deleteAccount.mockRejectedValue(new TypeError('Network request failed'));
    renderWithQuery(<AccountCard family={FAMILY} />);
    const form = openDeleteForm();

    fireEvent.changeText(form.password, 'Varno1Geslo');
    fireEvent.changeText(form.confirm, 'IZBRIŠI');
    fireEvent.press(screen.getByTestId('account-delete-form-submit'));
    await flush();

    expect(screen.getByTestId('account-delete-form-error').props.children).toBe(
      'Ni znano, ali je bil izbris izveden — preverite s ponovno prijavo.',
    );
    expect(deleteItem).not.toHaveBeenCalled();
  });

  it('success signs out to the StartScreen (no server revoke — the tokens are gone)', async () => {
    getItem.mockResolvedValue('parent-token');
    getUser.mockResolvedValue({ id: 1, name: 'Mama', email: 'm@x.si', role: 'parent', pet: null });
    deleteAccount.mockResolvedValue({
      status: 'deleted',
      scope: 'family',
      family_deleted: true,
      parents_deleted: 1,
      children_deleted: 0,
      pets_deleted: 0,
    });
    renderWithQuery(<AppNavigator />);

    await waitFor(() => expect(screen.getByTestId('account-card')).toBeTruthy());
    const form = openDeleteForm();
    fireEvent.changeText(form.password, 'Varno1Geslo');
    fireEvent.changeText(form.confirm, 'IZBRIŠI');
    fireEvent.press(screen.getByTestId('account-delete-form-submit'));

    expect(await screen.findByText(START_STRINGS.subtitle)).toBeTruthy();
    expect(deleteAccount).toHaveBeenCalledWith('Varno1Geslo', 'IZBRIŠI', false);
    expect(apiLogout).not.toHaveBeenCalled();
    expect(deleteItem).toHaveBeenCalled();
    expect(useAppStore.getState().user).toBeNull();
  });
});

describe('AccountCard — in English (M1-18)', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await act(async () => {
      await i18n.changeLanguage('en');
    });
  });
  afterEach(async () => {
    await act(async () => {
      await i18n.changeLanguage('sl');
    });
  });

  it('asks for "DELETE" and sends it with the password', async () => {
    deleteAccount.mockRejectedValue(new ApiError('Invalid', 422, { reason: 'invalid_password' }));
    renderWithQuery(<AccountCard family={FAMILY} />);
    expect(screen.getByText('Account')).toBeTruthy();
    const form = openDeleteForm();

    expect(screen.getByText(/your account, 2 child profiles and 2 dogs/)).toBeTruthy();
    expect(screen.getByText('To confirm, type DELETE')).toBeTruthy();
    fireEvent.changeText(form.password, 'Safe1Password');
    fireEvent.changeText(form.confirm, 'IZBRIŠI');
    expect(screen.getByTestId('account-delete-form-submit').props.accessibilityState).toEqual({ disabled: true });
    fireEvent.changeText(form.confirm, 'delete');
    fireEvent.press(screen.getByTestId('account-delete-form-submit'));
    await flush();

    expect(deleteAccount).toHaveBeenCalledWith('Safe1Password', 'DELETE', false);
    expect(screen.getByTestId('account-delete-form-error').props.children).toBe('The password is incorrect.');
  });
});
