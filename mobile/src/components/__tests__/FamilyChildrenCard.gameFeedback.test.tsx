/**
 * GAMES_SPEC §7.6 »Zvoki in vibracija v igrah«: a parent-only switch per child, ON by default
 * (also when an older server sends nothing); only the boolean is sent, a failure shows an error
 * and the switch keeps the server value.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import FamilyChildrenCard from '@/components/FamilyChildrenCard';
import { normalizeChildState } from '@/modules/childPet/childPetView';
import { familyFromDashboard, type FamilyOverview } from '@/modules/family/family';
import { makeFamilyPet, makeLiveChildState, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, updateChildSettings: jest.fn(), getParentDashboard: jest.fn() } };
});

const updateChildSettings = api.updateChildSettings as jest.Mock;

function family(value: boolean | undefined): FamilyOverview {
  const child = makeScoredChild({ id: 2, name: 'Maja', pet_id: 7 }) as unknown as Record<string, unknown>;
  if (value === undefined) delete child.game_feedback;
  else child.game_feedback = value;
  return familyFromDashboard(
    makeScoredDashboard([child as never], [makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] })]) as never,
  ) as FamilyOverview;
}

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

describe('FamilyChildrenCard — game sounds and vibration (GAMES_SPEC §7.6)', () => {
  beforeEach(() => jest.clearAllMocks());

  it('on by default (older server: no field); turning it off sends only the boolean', async () => {
    updateChildSettings.mockResolvedValue({ child_id: 2, adapted_mode: false, media_display: 'video', game_feedback: false });
    renderWithQuery(<FamilyChildrenCard family={family(undefined)} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
    const sw = screen.getByTestId('game-feedback-switch-2');
    expect(sw.props.value).toBe(true);
    expect(screen.getByText('Zvoki in vibracija v igrah')).toBeTruthy();
    expect(sw.props.accessibilityLabel).toBe('Zvoki in vibracija v igrah, Maja');
    fireEvent(sw, 'valueChange', false);
    await flush();
    expect(updateChildSettings).toHaveBeenCalledWith(2, { game_feedback: false });
    expect(Object.keys(updateChildSettings.mock.calls[0][1] as object)).toEqual(['game_feedback']);
  });

  it('off from the server; turning it on sends true', async () => {
    updateChildSettings.mockResolvedValue({ child_id: 2, adapted_mode: false, media_display: 'video', game_feedback: true });
    renderWithQuery(<FamilyChildrenCard family={family(false)} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
    expect(screen.getByTestId('game-feedback-switch-2').props.value).toBe(false);
    fireEvent(screen.getByTestId('game-feedback-switch-2'), 'valueChange', true);
    await flush();
    expect(updateChildSettings).toHaveBeenCalledWith(2, { game_feedback: true });
  });

  it('a failure shows an error and keeps the server value', async () => {
    updateChildSettings.mockRejectedValue(new ApiError('Server Error', 500, {}));
    renderWithQuery(<FamilyChildrenCard family={family(true)} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
    fireEvent(screen.getByTestId('game-feedback-switch-2'), 'valueChange', false);
    await flush();
    expect(screen.getByTestId('game-feedback-error-2')).toBeTruthy();
    expect(screen.getByTestId('game-feedback-switch-2').props.value).toBe(true);
  });

  it('the child view reads settings.game_feedback (missing → on)', () => {
    expect(normalizeChildState(makeLiveChildState()).settings.game_feedback).toBe(true);
    expect(normalizeChildState(makeLiveChildState({ settings: { game_feedback: false } })).settings.game_feedback).toBe(false);
  });
});
