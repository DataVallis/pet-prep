import { fireEvent, screen } from '@testing-library/react-native';

/**
 * David 2026-10-23: album, »?« (care guide) and the exit live in the HUD settings sheet —
 * open it with the gear and press the row (same testIDs as the old top-bar buttons).
 */
export function pressHudSettingsRow(testID: 'hud-album-open' | 'hud-care-guide-open' | 'hud-logout'): void {
  fireEvent.press(screen.getByTestId('hud-settings-open'));
  fireEvent.press(screen.getByTestId(testID));
}

/** Opens the settings sheet and returns the row (null when it is not offered). */
export function findHudSettingsRow(testID: 'hud-album-open' | 'hud-care-guide-open' | 'hud-logout') {
  fireEvent.press(screen.getByTestId('hud-settings-open'));
  return screen.queryByTestId(testID);
}
