import { act, fireEvent, render, screen } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';

import { api } from '@/api/client';
import {
  LANGUAGE_STORE_KEY,
  currentLanguage,
  currentLanguageTag,
  i18n,
  loadSavedLanguage,
  normaliseLanguage,
  pickDeviceLanguage,
  setLanguage,
  t,
} from '@/i18n';
import { strings } from '@/i18n/strings';
import { CHILD_PIN_STRINGS } from '@/screens/ChildPinLoginScreen';
import { SIGNUP_STRINGS } from '@/modules/auth/signup';
import StartScreen from '@/screens/StartScreen';

const setItem = SecureStore.setItemAsync as jest.Mock;
const getItem = SecureStore.getItemAsync as jest.Mock;

afterEach(async () => {
  await act(async () => {
    await i18n.changeLanguage('sl');
  });
  jest.clearAllMocks();
});

describe('language resolution', () => {
  it('normalises tags and ignores unsupported languages', () => {
    expect(normaliseLanguage('sl-SI')).toBe('sl');
    expect(normaliseLanguage('EN_us')).toBe('en');
    expect(normaliseLanguage('de-DE')).toBeNull();
    expect(normaliseLanguage('')).toBeNull();
    expect(normaliseLanguage(null)).toBeNull();
  });

  it('takes the first supported device language, else English (the default)', () => {
    expect(pickDeviceLanguage(['de-AT', 'sl-SI', 'en-GB'])).toBe('sl');
    expect(pickDeviceLanguage(['en-US', 'sl-SI'])).toBe('en');
    expect(pickDeviceLanguage(['hr-HR', 'de-DE'])).toBe('en');
    expect(pickDeviceLanguage([])).toBe('en');
  });

  it('starts in the device language (test device: Slovenian)', () => {
    expect(currentLanguage()).toBe('sl');
    expect(currentLanguageTag()).toBe('sl-SI');
  });
});

describe('saved choice', () => {
  it('setLanguage switches and remembers the choice on the device', async () => {
    await act(async () => {
      await setLanguage('en');
    });
    expect(currentLanguage()).toBe('en');
    expect(setItem).toHaveBeenCalledWith(LANGUAGE_STORE_KEY, 'en');
  });

  it('loadSavedLanguage applies a saved choice and ignores garbage', async () => {
    getItem.mockResolvedValueOnce('en');
    await act(async () => {
      await expect(loadSavedLanguage()).resolves.toBe('en');
    });

    await act(async () => {
      await i18n.changeLanguage('sl');
    });
    getItem.mockResolvedValueOnce('klingon');
    await expect(loadSavedLanguage()).resolves.toBe('sl');
  });

  it('a failing store keeps the device language', async () => {
    getItem.mockRejectedValueOnce(new Error('keychain locked'));
    await expect(loadSavedLanguage()).resolves.toBe('sl');
  });
});

describe('strings()', () => {
  it('reads every value in the current language at access time', async () => {
    const S = strings('auth', 'start');
    expect(S.child).toBe('Sem otrok');
    await act(async () => {
      await i18n.changeLanguage('en');
    });
    expect(S.child).toBe("I'm a kid");
  });

  it('resolves nested sections, interpolating functions and nested extras', async () => {
    expect(CHILD_PIN_STRINGS.rateLimited('2 min')).toBe('Preveč poskusov. Počakaj še 2 min, potem poskusi znova.');
    expect(CHILD_PIN_STRINGS.digitsEntered(3)).toBe('Vpisanih 3 od 6 številk');
    expect(SIGNUP_STRINGS.errors.tooManyAttemptsMinutes(4)).toBe('Preveč poskusov registracije. Poskusite znova čez 4 min.');
    expect(SIGNUP_STRINGS.errors.offline).toBe('Ni povezave s strežnikom. Preverite internet in poskusite znova.');
    await act(async () => {
      await i18n.changeLanguage('en');
    });
    expect(CHILD_PIN_STRINGS.digitsEntered(3)).toBe('3 of 6 digits entered');
    expect(SIGNUP_STRINGS.errors.passwordMismatch).toBe("The passwords don't match.");
  });

  it('is enumerable and read-only like the old constant objects', () => {
    const S = strings('auth', 'splash');
    expect(Object.keys(S).sort()).toEqual(['logout', 'offlineBody', 'offlineTitle', 'restoring', 'retry']);
    expect(Object.values(S)).toContain('Nalagam …');
    expect('retry' in S).toBe(true);
    try {
      (S as unknown as Record<string, string>).retry = 'x';
    } catch {
      // strict mode throws; either way the value stays the translation
    }
    expect(S.retry).toBe('Poskusi znova');
  });

  it('t() translates outside React', () => {
    expect(t('auth:splash.retry')).toBe('Poskusi znova');
  });
});

describe('language switch', () => {
  it('the start screen switches to English and back', async () => {
    render(<StartScreen />);
    expect(screen.getByText('Kdo se prijavlja?')).toBeTruthy();

    await act(async () => {
      fireEvent.press(screen.getByTestId('language-switch-en'));
    });
    expect(screen.getByText("Who's signing in?")).toBeTruthy();
    expect(screen.getByText("I'm a parent")).toBeTruthy();
    expect(screen.getByTestId('language-switch-en').props.accessibilityState).toEqual({ selected: true });

    await act(async () => {
      fireEvent.press(screen.getByTestId('language-switch-sl'));
    });
    expect(screen.getByText('Sem starš')).toBeTruthy();
  });
});

describe('API language', () => {
  it('sends Accept-Language with the app language', async () => {
    const fetchMock = jest.fn().mockResolvedValue({
      ok: true,
      status: 200,
      headers: { get: () => null },
      json: () => Promise.resolve({ id: 1 }),
    });
    globalThis.fetch = fetchMock as unknown as typeof fetch;

    await api.getUser();
    expect((fetchMock.mock.calls[0][1] as { headers: Record<string, string> }).headers['Accept-Language']).toBe('sl-SI');

    await act(async () => {
      await i18n.changeLanguage('en');
    });
    await api.getUser();
    expect((fetchMock.mock.calls[1][1] as { headers: Record<string, string> }).headers['Accept-Language']).toBe('en-GB');
  });
});
