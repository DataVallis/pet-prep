/**
 * M5-T17 »Lažje kretnje«: a parent-only switch per child; only the boolean is sent, the
 * family is refreshed, a failure shows an error and the switch keeps the server value.
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

function family(on: boolean): FamilyOverview {
  return familyFromDashboard(
    makeScoredDashboard(
      [makeScoredChild({ id: 2, name: 'Maja', pet_id: 7, easy_gestures: on })],
      [makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] })],
    ) as never,
  ) as FamilyOverview;
}

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

describe('FamilyChildrenCard — easier gestures (M5-T17)', () => {
  beforeEach(() => jest.clearAllMocks());

  it('shows the switch off with its help text; turning it on sends only the boolean', async () => {
    updateChildSettings.mockResolvedValue({ child_id: 2, adapted_mode: false, media_display: 'video', easy_gestures: true });
    renderWithQuery(<FamilyChildrenCard family={family(false)} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
    const sw = screen.getByTestId('easy-gestures-switch-2');
    expect(sw.props.value).toBe(false);
    expect(screen.getByText('Lažje kretnje')).toBeTruthy();
    expect(screen.getByText('Namesto potegov s prstom otrok drži prst ali pritisne gumb. Za otroke, ki jim potegi delajo težave.')).toBeTruthy();
    expect(sw.props.accessibilityLabel).toBe('Lažje kretnje, Maja');

    fireEvent(sw, 'valueChange', true);
    await flush();
    expect(updateChildSettings).toHaveBeenCalledWith(2, { easy_gestures: true });
    expect(Object.keys(updateChildSettings.mock.calls[0][1] as object)).toEqual(['easy_gestures']);
  });

  it('on from the server; turning it off sends false', async () => {
    updateChildSettings.mockResolvedValue({ child_id: 2, adapted_mode: false, media_display: 'video', easy_gestures: false });
    renderWithQuery(<FamilyChildrenCard family={family(true)} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
    expect(screen.getByTestId('easy-gestures-switch-2').props.value).toBe(true);
    fireEvent(screen.getByTestId('easy-gestures-switch-2'), 'valueChange', false);
    await flush();
    expect(updateChildSettings).toHaveBeenCalledWith(2, { easy_gestures: false });
  });

  it('a failure shows an error and keeps the server value', async () => {
    updateChildSettings.mockRejectedValue(new ApiError('Server Error', 500, {}));
    renderWithQuery(<FamilyChildrenCard family={family(false)} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
    fireEvent(screen.getByTestId('easy-gestures-switch-2'), 'valueChange', true);
    await flush();
    expect(screen.getByTestId('easy-gestures-error-2')).toBeTruthy();
    expect(screen.getByTestId('easy-gestures-switch-2').props.value).toBe(false);
  });
});
