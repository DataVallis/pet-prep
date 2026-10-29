/**
 * Parent »Različica aplikacije« (David 2026-10-29) and the child's read-only line.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';

import AppVersionCard from '@/components/parent/AppVersionCard';
import ChildSettingsSheet from '@/components/ChildSettingsSheet';
import { setUpdatesIdentityForTests } from '@/modules/appVersion/appVersionSource';
import { setUpdatesApiForTests, type UpdatesApi } from '@/modules/appVersion/updateCheck';
import { setClipboardProbeForTests } from '@/modules/pairing/pinClipboard';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('expo-constants', () => ({
  __esModule: true,
  ExecutionEnvironment: { Bare: 'bare', Standalone: 'standalone', StoreClient: 'storeClient' },
  default: { expoConfig: { name: 'PetPrep', slug: 'petprep', version: '3.1.9', extra: { appVersion: '3.1.9', gitSha: 'abc1234' } }, platform: {} },
}));

const mockSetString = jest.fn(async (_text: string) => true);
jest.mock('expo-clipboard', () => ({
  setStringAsync: (text: string) => mockSetString(text),
  getStringAsync: jest.fn(async () => ''),
  hasStringAsync: jest.fn(async () => false),
}));

const OTA = {
  isEnabled: true,
  isEmbeddedLaunch: false,
  updateId: '019a3f2c-6bf5-7d16-8a3e-123456789abc',
  createdAt: new Date('2026-10-29T12:17:00Z'),
  channel: 'production',
  runtimeVersion: 'rt-1',
};

function fakeUpdates(overrides: Partial<UpdatesApi> = {}): UpdatesApi {
  return {
    isEnabled: true,
    checkForUpdateAsync: jest.fn(async () => ({ isAvailable: false })),
    fetchUpdateAsync: jest.fn(async () => ({ isNew: false })),
    reloadAsync: jest.fn(async () => undefined),
    ...overrides,
  };
}

const commit = process.env.EXPO_PUBLIC_GIT_COMMIT;
beforeEach(() => {
  process.env.EXPO_PUBLIC_GIT_COMMIT = '6bf527d';
  setUpdatesIdentityForTests(OTA);
});
afterEach(() => {
  if (commit === undefined) delete process.env.EXPO_PUBLIC_GIT_COMMIT;
  else process.env.EXPO_PUBLIC_GIT_COMMIT = commit;
  setUpdatesIdentityForTests(null);
  setUpdatesApiForTests('real');
  setClipboardProbeForTests(null);
  mockSetString.mockClear();
});

describe('AppVersionCard (parent)', () => {
  it('shows the OTA line in the family zone; the check is off in dev / Expo Go', () => {
    render(<AppVersionCard timeZone="Europe/Ljubljana" />);
    expect(screen.getByTestId('app-version-line')).toHaveTextContent('3.1.9 · posodobitev 6bf527d · 29. 10. 13:17');
    expect(screen.getByTestId('app-version-check')).toBeDisabled();
    expect(screen.getByTestId('app-version-status')).toHaveTextContent('Preverjanje posodobitev deluje samo v objavljeni aplikaciji.');
  });

  it('embedded bundle → »vgrajena različica«', () => {
    setUpdatesIdentityForTests({ isEnabled: true, isEmbeddedLaunch: true, updateId: OTA.updateId });
    delete process.env.EXPO_PUBLIC_GIT_COMMIT;
    render(<AppVersionCard timeZone={null} />);
    expect(screen.getByTestId('app-version-line')).toHaveTextContent('3.1.9 · vgrajena različica · abc1234');
  });

  it('tap copies the full details when the clipboard exists', async () => {
    setClipboardProbeForTests(() => true);
    render(<AppVersionCard timeZone="Europe/Ljubljana" />);
    await act(async () => {
      fireEvent.press(screen.getByTestId('app-version-row'));
    });
    const copied = mockSetString.mock.calls[0][0];
    expect(copied).toContain('Različica: 3.1.9');
    expect(copied).toContain(`Posodobitev: ${OTA.updateId}`);
    expect(copied).toContain('Commit: 6bf527d');
    expect(copied).toContain('Kanal: production');
    expect(copied).toContain('Runtime: rt-1');
    expect(screen.getByTestId('app-version-copy-status')).toHaveTextContent('Podrobnosti so kopirane.');
  });

  it('without a clipboard module the row is plain text', () => {
    setClipboardProbeForTests(() => false);
    render(<AppVersionCard timeZone={null} />);
    expect(screen.queryByTestId('app-version-copy-status')).toBeNull();
  });

  it('no update → »Imate najnovejšo različico.«', async () => {
    setUpdatesApiForTests(fakeUpdates());
    render(<AppVersionCard timeZone={null} />);
    await act(async () => {
      fireEvent.press(screen.getByTestId('app-version-check'));
    });
    expect(screen.getByTestId('app-version-status')).toHaveTextContent('Imate najnovejšo različico.');
  });

  it('update downloaded → ready + »Znova zaženi zdaj« (reload only on press)', async () => {
    const updates = fakeUpdates({
      checkForUpdateAsync: jest.fn(async () => ({ isAvailable: true })),
      fetchUpdateAsync: jest.fn(async () => ({ isNew: true })),
    });
    setUpdatesApiForTests(updates);
    render(<AppVersionCard timeZone={null} />);
    await act(async () => {
      fireEvent.press(screen.getByTestId('app-version-check'));
    });
    expect(screen.getByTestId('app-version-status')).toHaveTextContent(/Posodobitev je pripravljena\./);
    expect(updates.reloadAsync).not.toHaveBeenCalled();
    await act(async () => {
      fireEvent.press(screen.getByTestId('app-version-restart'));
    });
    expect(updates.reloadAsync).toHaveBeenCalledTimes(1);
  });

  it('a failing check → friendly error text', async () => {
    setUpdatesApiForTests(
      fakeUpdates({
        checkForUpdateAsync: jest.fn(async () => {
          throw new Error('ERR_UPDATES_CHECK');
        }),
      }),
    );
    render(<AppVersionCard timeZone={null} />);
    await act(async () => {
      fireEvent.press(screen.getByTestId('app-version-check'));
    });
    expect(screen.getByTestId('app-version-status')).toHaveTextContent('Posodobitev ni bilo mogoče preveriti. Preverite povezavo in poskusite znova.');
  });
});

describe('ChildSettingsSheet version line (child)', () => {
  it('a muted read-only line at the bottom — no button, no copy', () => {
    renderWithQuery(<ChildSettingsSheet visible onClose={jest.fn()} mediaDisplay="video" timeZone="Europe/Ljubljana" />);
    const line = screen.getByTestId('child-app-version');
    expect(line).toHaveTextContent('3.1.9 · posodobitev 6bf527d · 29. 10. 13:17');
    expect(screen.queryByTestId('app-version-check')).toBeNull();
    expect(screen.queryByTestId('app-version-row')).toBeNull();
  });
});
