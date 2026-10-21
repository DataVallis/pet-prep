/**
 * M5-T11 »Prikaz: Video / Slika«: the parent sets the child's display; only that field is
 * sent, a failure shows an error and keeps the server value.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import FamilyChildrenCard from '@/components/FamilyChildrenCard';
import { familyFromDashboard, type FamilyOverview } from '@/modules/family/family';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, updateChildSettings: jest.fn(), getParentDashboard: jest.fn() } };
});

const updateChildSettings = api.updateChildSettings as jest.Mock;

function family(display: unknown): FamilyOverview {
  return familyFromDashboard(
    makeScoredDashboard(
      [makeScoredChild({ id: 2, name: 'Maja', pet_id: 7, media_display: display } as never)],
      [makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] })],
    ) as never,
  ) as FamilyOverview;
}

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

describe('FamilyChildrenCard — Prikaz', () => {
  beforeEach(() => jest.clearAllMocks());

  it('shows video by default (also for an older server) and sets picture with only that field', async () => {
    updateChildSettings.mockResolvedValue({ child_id: 2, adapted_mode: false, media_display: 'picture' });
    renderWithQuery(<FamilyChildrenCard family={family(undefined)} onAddChild={jest.fn()} onChildPin={jest.fn()} />);

    expect(screen.getByText('Prikaz')).toBeTruthy();
    expect(screen.getByTestId('media-display-switch-2-video').props.accessibilityState.selected).toBe(true);
    expect(screen.getByText(/porabi manj mobilnih podatkov/)).toBeTruthy();

    fireEvent.press(screen.getByTestId('media-display-switch-2-picture'));
    await flush();
    expect(updateChildSettings).toHaveBeenCalledWith(2, { media_display: 'picture' });
  });

  it('shows the server value picture', () => {
    renderWithQuery(<FamilyChildrenCard family={family('picture')} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
    expect(screen.getByTestId('media-display-switch-2-picture').props.accessibilityState.selected).toBe(true);
  });

  it('a failure shows an error and keeps the server value', async () => {
    updateChildSettings.mockRejectedValue(new ApiError('Server Error', 500, {}));
    renderWithQuery(<FamilyChildrenCard family={family('video')} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
    fireEvent.press(screen.getByTestId('media-display-switch-2-picture'));
    await flush();
    expect(screen.getByTestId('media-display-error-2')).toBeTruthy();
    expect(screen.getByTestId('media-display-switch-2-video').props.accessibilityState.selected).toBe(true);
  });
});
