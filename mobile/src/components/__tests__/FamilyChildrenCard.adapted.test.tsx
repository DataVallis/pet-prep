/**
 * M5-T12 »Prilagojen način«: a parent-only switch per child; only the boolean is sent, the
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

function family(adapted: boolean): FamilyOverview {
  return familyFromDashboard(
    makeScoredDashboard(
      [makeScoredChild({ id: 2, name: 'Maja', pet_id: 7, adapted_mode: adapted })],
      [makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] })],
    ) as never,
  ) as FamilyOverview;
}

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

describe('FamilyChildrenCard — adapted mode', () => {
  beforeEach(() => jest.clearAllMocks());

  it('shows the switch off with the "we don\'t ask why" hint, turns it on with only the boolean', async () => {
    updateChildSettings.mockResolvedValue({ child_id: 2, adapted_mode: true });
    renderWithQuery(<FamilyChildrenCard family={family(false)} onAddChild={jest.fn()} onChildPin={jest.fn()} />);

    const sw = screen.getByTestId('adapted-mode-switch-2');
    expect(sw.props.value).toBe(false);
    expect(screen.getByText('Prilagojen način')).toBeTruthy();
    expect(screen.getByText(/Ne sprašujemo in ne shranjujemo, zakaj/)).toBeTruthy();
    expect(screen.queryByTestId('adapted-mode-native-2')).toBeNull();
    expect(screen.queryByTestId('adapted-mode-sources-2')).toBeNull();
    // The parent's explicit consent text (David 2026-10-21) sits under the switch.
    expect(screen.getByTestId('adapted-mode-consent-2').props.children).toBe(
      'Z vklopom privolite, da hranimo samo to, da je prilagojen način vklopljen, in pri posameznem dnevu, da so šteli potiski. Ko ga izklopite, iz zgodovine odstranimo vse oznake potiskov (ti dnevi štejejo kot koraki); točke, ocena skrbi in vpisani sprehodi ostanejo enaki.',
    );

    fireEvent(sw, 'valueChange', true);
    await flush();
    expect(updateChildSettings).toHaveBeenCalledWith(2, { adapted_mode: true });
    expect(Object.keys(updateChildSettings.mock.calls[0][1] as object)).toEqual(['adapted_mode']);
  });

  it('on: says that pushes need the new app version', () => {
    renderWithQuery(<FamilyChildrenCard family={family(true)} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
    expect(screen.getByTestId('adapted-mode-switch-2').props.value).toBe(true);
    expect(screen.getByTestId('adapted-mode-native-2')).toBeTruthy();
  });

  it('on (David 2026-10-26): says clearly that on iPhone only an Apple Watch counts pushes, Android needs Health Connect, and leads to »Vpiši sprehod«', () => {
    const onLogWalk = jest.fn();
    const fam = family(true);
    renderWithQuery(<FamilyChildrenCard family={fam} onAddChild={jest.fn()} onChildPin={jest.fn()} onLogWalk={onLogWalk} />);
    expect(screen.getByTestId('adapted-mode-sources-2')).toBeTruthy();
    expect(screen.getByText('Kdo šteje potiske')).toBeTruthy();
    expect(screen.getByText(/^Na iPhonu potiske šteje samo Apple Watch \(v aplikaciji Zdravje: Podatki o zdravju → Invalidski voziček = Da\)/)).toBeTruthy();
    expect(screen.getByText(/^Na Androidu potrebujete napravo ali aplikacijo, ki zapisuje potiske invalidskega vozička v Health Connect/)).toBeTruthy();
    expect(screen.getByText(/^Brez ure lahko vpišete minute sprehoda/)).toBeTruthy();
    fireEvent.press(screen.getByTestId('adapted-mode-log-walk-2'));
    expect(onLogWalk).toHaveBeenCalledWith(fam.children[0]);
  });

  it('right after turning it on the note appears (switch shows the requested value)', async () => {
    updateChildSettings.mockReturnValue(new Promise(() => undefined));
    renderWithQuery(<FamilyChildrenCard family={family(false)} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
    fireEvent(screen.getByTestId('adapted-mode-switch-2'), 'valueChange', true);
    await flush();
    expect(screen.getByTestId('adapted-mode-sources-2')).toBeTruthy();
    // No onLogWalk → no button.
    expect(screen.queryByTestId('adapted-mode-log-walk-2')).toBeNull();
  });

  it('a failure shows an error and keeps the server value', async () => {
    updateChildSettings.mockRejectedValue(new ApiError('Server Error', 500, {}));
    renderWithQuery(<FamilyChildrenCard family={family(false)} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
    fireEvent(screen.getByTestId('adapted-mode-switch-2'), 'valueChange', true);
    await flush();
    expect(screen.getByTestId('adapted-mode-error-2')).toBeTruthy();
    expect(screen.getByTestId('adapted-mode-switch-2').props.value).toBe(false);
  });
});
