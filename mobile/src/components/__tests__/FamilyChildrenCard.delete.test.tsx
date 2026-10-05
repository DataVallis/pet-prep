/**
 * M2-08: "Izbriši profil" per child — consequences (sole pet deleted / shared pet kept),
 * password + "IZBRIŠI", request, family refresh, errors.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import FamilyChildrenCard, { FAMILY_STRINGS } from '@/components/FamilyChildrenCard';
import { familyFromDashboard, type FamilyOverview } from '@/modules/family/family';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, deleteChild: jest.fn(), revokeChildTokens: jest.fn(), getParentDashboard: jest.fn() } };
});

const deleteChild = api.deleteChild as jest.Mock;

// Maja (2): own pet 7 + shared pet 8 with Luka (5).
const FAMILY = familyFromDashboard(
  makeScoredDashboard(
    [makeScoredChild({ id: 2, name: 'Maja', pet_id: 7 }), makeScoredChild({ id: 5, name: 'Luka', pet_id: 8 })],
    [
      makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] }),
      makeFamilyPet({
        id: 8,
        caretakers: [
          { child_id: 2, contract_signed: true },
          { child_id: 5, contract_signed: true },
        ],
      }),
    ],
  ) as never,
) as FamilyOverview;

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

function renderCard() {
  return renderWithQuery(<FamilyChildrenCard family={FAMILY} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
}

function fillAndSubmit(childId: number, password: string) {
  fireEvent.changeText(screen.getByTestId(`child-delete-form-${childId}-password`), password);
  fireEvent.changeText(screen.getByTestId(`child-delete-form-${childId}-confirm`), 'IZBRIŠI');
  fireEvent.press(screen.getByTestId(`child-delete-form-${childId}-submit`));
}

describe('FamilyChildrenCard — delete a child profile', () => {
  beforeEach(() => jest.clearAllMocks());

  it('explains what goes: the own pet is deleted, the shared pet stays', () => {
    renderCard();
    fireEvent.press(screen.getByTestId('child-delete-2'));

    const [profile, deleted, kept] = FAMILY_STRINGS.deleteConsequences('Maja', 1, 1);
    expect(screen.getByText(`• ${profile}`)).toBeTruthy();
    expect(screen.getByText(`• ${deleted}`)).toBeTruthy();
    expect(screen.getByText(`• ${kept}`)).toBeTruthy();
  });

  it('a child with only a shared pet: nothing about a deleted pet', () => {
    renderCard();
    fireEvent.press(screen.getByTestId('child-delete-5'));

    const lines = FAMILY_STRINGS.deleteConsequences('Luka', 0, 1);
    expect(lines).toHaveLength(2);
    expect(screen.getByText(`• ${lines[1]}`)).toBeTruthy();
  });

  it('sends the password, shows the confirmation and closes the form', async () => {
    deleteChild.mockResolvedValue({ status: 'deleted', child_id: 2, pets_deleted: 1, pets_kept: 1 });
    renderCard();
    fireEvent.press(screen.getByTestId('child-delete-2'));

    fillAndSubmit(2, 'Varno1Geslo');
    await flush();

    expect(deleteChild).toHaveBeenCalledWith(2, 'Varno1Geslo');
    expect(screen.getByTestId('child-deleted-notice').props.children).toBe(FAMILY_STRINGS.deleted('Maja'));
    expect(screen.queryByTestId('child-delete-form-2')).toBeNull();
  });

  it('keeps the form open with the reason on a wrong password', async () => {
    deleteChild.mockRejectedValue(new ApiError('The password is not correct.', 422, { reason: 'invalid_password' }));
    renderCard();
    fireEvent.press(screen.getByTestId('child-delete-2'));

    fillAndSubmit(2, 'napačno');
    await flush();

    expect(screen.getByTestId('child-delete-form-2-error').props.children).toBe(FAMILY_STRINGS.deleteErrors.invalid_password);
    expect(screen.queryByTestId('child-deleted-notice')).toBeNull();
  });

  it('a child already gone (404) and offline get their own messages', async () => {
    deleteChild.mockRejectedValueOnce(new ApiError('No such child in your family.', 404, { reason: 'child_not_found' }));
    renderCard();
    fireEvent.press(screen.getByTestId('child-delete-5'));
    fillAndSubmit(5, 'x');
    await flush();
    expect(screen.getByTestId('child-delete-form-5-error').props.children).toBe(FAMILY_STRINGS.deleteErrors.not_found);

    deleteChild.mockRejectedValueOnce(new TypeError('Network request failed'));
    fireEvent.press(screen.getByTestId('child-delete-form-5-submit'));
    await flush();
    expect(screen.getByTestId('child-delete-form-5-error').props.children).toBe(FAMILY_STRINGS.deleteErrors.offline);
  });

  it('cancel deletes nothing; only one child\'s delete form is open at a time', () => {
    renderCard();
    fireEvent.press(screen.getByTestId('child-delete-2'));
    fireEvent.press(screen.getByTestId('child-delete-form-2-cancel'));
    expect(screen.queryByTestId('child-delete-form-2')).toBeNull();

    fireEvent.press(screen.getByTestId('child-delete-2'));
    fireEvent.press(screen.getByTestId('child-delete-5'));
    expect(screen.queryByTestId('child-delete-form-2')).toBeNull();
    expect(screen.getByTestId('child-delete-form-5')).toBeTruthy();
    expect(deleteChild).not.toHaveBeenCalled();
  });
});
