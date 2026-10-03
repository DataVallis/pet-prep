/**
 * M0-10: seeded demo logins must never appear outside development builds.
 */
import { render } from '@testing-library/react-native';

import PairingScreen from '../PairingScreen';

type DevGlobal = typeof globalThis & { __DEV__: boolean };

describe('PairingScreen demo logins', () => {
  const g = globalThis as DevGlobal;
  const original = g.__DEV__;

  afterEach(() => {
    g.__DEV__ = original;
  });

  it('shows the 1-tap demo logins in development builds', () => {
    g.__DEV__ = true;
    const { queryByText } = render(<PairingScreen />);
    expect(queryByText('HITRO TESTIRANJE (1 KLIK):')).not.toBeNull();
  });

  it('hides the demo logins and test emails in release builds', () => {
    g.__DEV__ = false;
    const { queryByText, queryByPlaceholderText } = render(<PairingScreen />);
    expect(queryByText('HITRO TESTIRANJE (1 KLIK):')).toBeNull();
    expect(queryByText('Otrok (HUD)')).toBeNull();
    expect(queryByPlaceholderText(/test\.com/)).toBeNull();
  });
});
