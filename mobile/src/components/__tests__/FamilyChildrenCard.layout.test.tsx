/**
 * David 2026-11-16 (»Nadzor in družina«, »preveč informacij«): each child is collapsed by
 * default — basics + sign-in actions; »Več nastavitev« reveals the settings and, last,
 * »Izbriši profil«. »Dodaj otroka« sits above the list, outside every child.
 */
import { fireEvent, screen, within } from '@testing-library/react-native';
import type { ReactTestInstance } from 'react-test-renderer';

import FamilyChildrenCard from '@/components/FamilyChildrenCard';
import { familyFromDashboard, type FamilyOverview } from '@/modules/family/family';
import { makeFamilyPet, makeScoredChild, makeScoredDashboard } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, updateChildSettings: jest.fn(), getParentDashboard: jest.fn() } };
});

function family(): FamilyOverview {
  return familyFromDashboard(
    makeScoredDashboard(
      [
        makeScoredChild({ id: 2, name: 'Maja', pet_id: 7 }),
        makeScoredChild({ id: 5, name: 'Taja', pet_id: 8, adapted_mode: true, media_display: 'picture' } as never),
      ],
      [
        makeFamilyPet({ id: 7, caretakers: [{ child_id: 2, contract_signed: true }] }),
        makeFamilyPet({ id: 8, caretakers: [{ child_id: 5, contract_signed: true }] }),
      ],
    ) as never,
  ) as FamilyOverview;
}

function renderCard() {
  return renderWithQuery(<FamilyChildrenCard family={family()} onAddChild={jest.fn()} onChildPin={jest.fn()} />);
}

/** testIDs of the host descendants in render order. */
function orderedTestIds(root: ReactTestInstance): string[] {
  const ids: string[] = [];
  const walk = (node: ReactTestInstance) => {
    const id: unknown = node.props.testID;
    if (typeof node.type === 'string' && typeof id === 'string' && !ids.includes(id)) ids.push(id);
    node.children.forEach((c) => {
      if (typeof c !== 'string') walk(c);
    });
  };
  walk(root);
  return ids;
}

describe('FamilyChildrenCard — collapsible child cards (David 2026-11-16)', () => {
  it('collapsed: basics and sign-in actions only, no settings and no delete', () => {
    renderCard();
    const maja = within(screen.getByTestId('family-child-2'));
    expect(maja.getByText('Maja')).toBeTruthy();
    expect(maja.getByTestId('family-child-devices-2')).toBeTruthy();
    expect(maja.getByTestId('child-pin-2')).toBeTruthy();
    expect(maja.getByTestId('child-revoke-2')).toBeTruthy();
    expect(maja.getByText('Več nastavitev')).toBeTruthy();

    expect(screen.queryByTestId('child-settings-2')).toBeNull();
    expect(screen.queryByTestId('adapted-mode-switch-2')).toBeNull();
    expect(screen.queryByTestId('easy-gestures-switch-2')).toBeNull();
    expect(screen.queryByTestId('game-feedback-switch-2')).toBeNull();
    expect(screen.queryByTestId('media-display-switch-2')).toBeNull();
    expect(screen.queryByTestId('child-delete-2')).toBeNull();
  });

  it('a one-line summary names only the settings that differ from the default', () => {
    renderCard();
    expect(screen.queryByTestId('family-child-summary-2')).toBeNull();
    expect(screen.getByTestId('family-child-summary-5').props.children).toBe('Prilagojen način · Prikaz: slika');
  });

  it('»Več nastavitev« reveals the settings and delete last; a11y state follows', () => {
    renderCard();
    const toggle = screen.getByTestId('child-settings-toggle-2');
    expect(toggle.props.accessibilityLabel).toBe('Več nastavitev za Maja');
    expect(toggle.props.accessibilityRole).toBe('button');
    expect(toggle.props.accessibilityState).toEqual({ expanded: false });

    fireEvent.press(toggle);
    expect(screen.getByTestId('child-settings-toggle-2').props.accessibilityState).toEqual({ expanded: true });
    const order = orderedTestIds(screen.getByTestId('child-settings-2'));
    const at = (id: string) => order.indexOf(id);
    for (const id of ['adapted-mode-switch-2', 'easy-gestures-switch-2', 'game-feedback-switch-2', 'media-display-switch-2', 'child-delete-2']) {
      expect(at(id)).toBeGreaterThanOrEqual(0);
    }
    expect(at('adapted-mode-switch-2')).toBeLessThan(at('easy-gestures-switch-2'));
    expect(at('easy-gestures-switch-2')).toBeLessThan(at('game-feedback-switch-2'));
    expect(at('game-feedback-switch-2')).toBeLessThan(at('media-display-switch-2'));
    expect(at('media-display-switch-2')).toBeLessThan(at('child-delete-2'));
    // Only this child opened.
    expect(screen.queryByTestId('child-settings-5')).toBeNull();

    fireEvent.press(screen.getByTestId('child-settings-toggle-2'));
    expect(screen.queryByTestId('child-settings-2')).toBeNull();
    expect(screen.getByTestId('child-settings-toggle-2').props.accessibilityState).toEqual({ expanded: false });
  });

  it('the adapted-mode consent is visible right under the switch before turning it on (not behind »Več o tem«)', () => {
    renderCard();
    fireEvent.press(screen.getByTestId('child-settings-toggle-2'));
    expect(screen.getByTestId('adapted-mode-switch-2').props.value).toBe(false);
    const box = screen.getByTestId('adapted-mode-2');
    const order = orderedTestIds(box);
    expect(within(box).getByTestId('adapted-mode-consent-2').props.children).toMatch(/^Z vklopom privolite/);
    expect(order.indexOf('adapted-mode-consent-2')).toBeLessThan(order.indexOf('adapted-mode-info-2'));
    expect(screen.queryByTestId('adapted-mode-info-2-body')).toBeNull();
    expect(screen.getByTestId('adapted-mode-info-2').props.accessibilityState).toEqual({ expanded: false });
    fireEvent.press(screen.getByTestId('adapted-mode-info-2'));
    expect(screen.getByTestId('adapted-mode-info-2-body')).toBeTruthy();
    expect(screen.getByTestId('adapted-mode-info-2').props.accessibilityState).toEqual({ expanded: true });
  });

  it('»Dodaj otroka« is above the list and outside every child card', () => {
    const onAddChild = jest.fn();
    renderWithQuery(<FamilyChildrenCard family={family()} onAddChild={onAddChild} onChildPin={jest.fn()} />);
    const order = orderedTestIds(screen.getByTestId('family-children'));
    expect(order.indexOf('family-add-child')).toBeGreaterThanOrEqual(0);
    expect(order.indexOf('family-add-child')).toBeLessThan(order.indexOf('family-child-2'));
    expect(order.indexOf('family-add-child')).toBeLessThan(order.indexOf('family-child-5'));
    expect(within(screen.getByTestId('family-child-2')).queryByTestId('family-add-child')).toBeNull();
    expect(within(screen.getByTestId('family-child-5')).queryByTestId('family-add-child')).toBeNull();
    fireEvent.press(screen.getByTestId('family-add-child'));
    expect(onAddChild).toHaveBeenCalledTimes(1);
  });
});
