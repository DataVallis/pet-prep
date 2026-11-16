/**
 * `FamilyChildrenCard` keeps each child's settings and »Izbriši profil« behind »Več nastavitev«
 * (David 2026-11-16). Tests open it before touching a switch / the delete button.
 */
import { fireEvent, screen } from '@testing-library/react-native';

export function openChildSettings(childId: number): void {
  fireEvent.press(screen.getByTestId(`child-settings-toggle-${childId}`));
}
