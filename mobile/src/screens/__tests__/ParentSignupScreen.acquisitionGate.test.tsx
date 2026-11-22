/**
 * M5-08 admin switch »Validation signals«: the sign-up picker shows only with the public
 * `validation_signals_signup: true` (mode »All«). Off / test families / missing / a failed request
 * hide it, sign-up still works and no acquisition fields are sent.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { api } from '@/api/client';
import { i18n } from '@/i18n';
import { setAcquisitionPrefsStoreForTests } from '@/modules/validation/acquisitionPrefs';
import ParentSignupScreen from '@/screens/ParentSignupScreen';
import { useAppStore } from '@/store/appStore';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, register: jest.fn(), getAppFeatures: jest.fn() } };
});

const register = api.register as jest.Mock;
const getAppFeatures = api.getAppFeatures as jest.Mock;
const setDone = jest.fn(async () => undefined);

const session = {
  token: 'tok',
  user: { id: 7, name: 'Mama Ana', email: 'ana@example.com', role: 'parent' },
  pet: null,
  awaiting_contract: null,
};

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

async function renderAndSubmit() {
  renderWithQuery(<ParentSignupScreen onBack={jest.fn()} onLogin={jest.fn()} />);
  await flush();
  return {
    send: async () => {
      fireEvent.changeText(screen.getByTestId('signup-name'), 'Mama Ana');
      fireEvent.changeText(screen.getByTestId('signup-email'), 'ana@example.com');
      fireEvent.changeText(screen.getByTestId('signup-password'), 'Varno1Geslo');
      fireEvent.changeText(screen.getByTestId('signup-password-repeat'), 'Varno1Geslo');
      fireEvent.press(screen.getByTestId('signup-terms'));
      fireEvent.press(screen.getByTestId('signup-submit'));
      await flush();
    },
  };
}

beforeEach(async () => {
  jest.clearAllMocks();
  useAppStore.setState(useAppStore.getInitialState(), true);
  await i18n.changeLanguage('sl');
  register.mockResolvedValue(session);
  setAcquisitionPrefsStoreForTests({ getItemAsync: async () => null, setItemAsync: setDone });
});

describe('sign-up picker gate', () => {
  it.each([
    ['mode Off / test families (flag false)', () => getAppFeatures.mockResolvedValue({ validation_signals_signup: false })],
    ['field missing', () => getAppFeatures.mockResolvedValue({})],
    ['request failed', () => getAppFeatures.mockRejectedValue(new TypeError('Network request failed'))],
  ])('%s → hidden, sign-up sends no acquisition fields and keeps the dashboard question open', async (_label, arrange) => {
    arrange();
    const { send } = await renderAndSubmit();
    expect(screen.queryByTestId('signup-acquisition')).toBeNull();
    await send();
    expect(register).toHaveBeenCalledTimes(1);
    expect(register.mock.calls[0][0]).not.toHaveProperty('acquisition_source');
    // Not asked here → not marked done (the once-only dashboard card may still ask).
    expect(setDone).not.toHaveBeenCalled();
  });

  it('mode All (flag true) → shown, the answer is sent and the question is marked done', async () => {
    getAppFeatures.mockResolvedValue({ validation_signals_signup: true });
    const { send } = await renderAndSubmit();
    expect(screen.getByTestId('signup-acquisition')).toBeTruthy();
    fireEvent.press(screen.getByTestId('signup-acquisition-tiktok'));
    await send();
    expect(register.mock.calls[0][0]).toMatchObject({ acquisition_source: 'tiktok' });
    expect(setDone).toHaveBeenCalled();
  });
});
