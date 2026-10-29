/**
 * App version line (David 2026-10-29): store version + build, embedded bundle vs OTA update,
 * update id / commit / time — pure formatting, safe with missing fields.
 */
import i18n from '@/i18n';
import {
  appVersionFrom,
  formatAppVersionDetails,
  formatAppVersionLine,
  shortCommit,
  updateTimeText,
  type AppVersionSources,
} from '../appVersion';
import { readAppVersionSources, setUpdatesIdentityForTests } from '../appVersionSource';

const UPDATE_ID = '019A3F2C-6BF5-7D16-8A3E-123456789ABC';

function sources(overrides: Partial<AppVersionSources> = {}): AppVersionSources {
  return {
    version: '3.1.9',
    buildNumber: '42',
    runtimeVersion: 'a1b2c3d4e5f6',
    updatesEnabled: true,
    isEmbeddedLaunch: false,
    updateId: UPDATE_ID,
    // 12:17 UTC = 13:17 in Ljubljana (CET, after the 2026-10-25 change).
    createdAt: new Date('2026-10-29T12:17:00Z'),
    channel: 'production',
    commit: '6bf527d162bd75f076dc10313501e9d63c026449',
    ...overrides,
  };
}

const ZONE = 'Europe/Ljubljana';

afterEach(async () => {
  setUpdatesIdentityForTests(null);
  await i18n.changeLanguage('sl');
});

describe('appVersionFrom', () => {
  it('an OTA update: short id, short commit, ISO time', () => {
    expect(appVersionFrom(sources())).toEqual({
      version: '3.1.9',
      build: '42',
      runtimeVersion: 'a1b2c3d4e5f6',
      source: 'update',
      updateId: UPDATE_ID.toLowerCase(),
      updateIdShort: '019a3f2c',
      createdAt: '2026-10-29T12:17:00.000Z',
      channel: 'production',
      commit: '6bf527d',
    });
  });

  it('the embedded bundle (also when updates are off or the id is missing)', () => {
    expect(appVersionFrom(sources({ isEmbeddedLaunch: true })).source).toBe('embedded');
    expect(appVersionFrom(sources({ updatesEnabled: false })).source).toBe('embedded');
    expect(appVersionFrom(sources({ updateId: null })).source).toBe('embedded');
  });

  it('missing / broken fields fall back safely', () => {
    const info = appVersionFrom(
      sources({ version: null, buildNumber: ' ', runtimeVersion: null, channel: '', commit: 'dev', createdAt: new Date('nope'), updateId: null }),
    );
    expect(info).toMatchObject({ version: '0.0.0', build: null, runtimeVersion: null, channel: null, commit: null, createdAt: null, updateIdShort: null });
  });

  it('shortCommit keeps only hex shas', () => {
    expect(shortCommit('ABCDEF1234')).toBe('abcdef1');
    expect(shortCommit('dev')).toBeNull();
    expect(shortCommit('abc')).toBeNull();
    expect(shortCommit(undefined)).toBeNull();
  });
});

describe('formatAppVersionLine', () => {
  it('OTA: »3.1.9 (42) · posodobitev 6bf527d · 29. 10. 13:17« in the family zone', () => {
    expect(formatAppVersionLine(appVersionFrom(sources()), ZONE)).toBe('3.1.9 (42) · posodobitev 6bf527d · 29. 10. 13:17');
  });

  it('OTA without a commit shows the update id (8 chars); without a time, no time', () => {
    expect(formatAppVersionLine(appVersionFrom(sources({ commit: null, createdAt: null })), ZONE)).toBe('3.1.9 (42) · posodobitev 019a3f2c');
  });

  it('embedded bundle: »vgrajena različica«, commit only when known, no build when unknown', () => {
    expect(formatAppVersionLine(appVersionFrom(sources({ isEmbeddedLaunch: true })), ZONE)).toBe('3.1.9 (42) · vgrajena različica · 6bf527d');
    expect(formatAppVersionLine(appVersionFrom(sources({ isEmbeddedLaunch: true, commit: null, buildNumber: null })), ZONE)).toBe(
      '3.1.9 · vgrajena različica',
    );
  });

  it('English', async () => {
    await i18n.changeLanguage('en');
    expect(formatAppVersionLine(appVersionFrom(sources()), ZONE)).toBe('3.1.9 (42) · update 6bf527d · 29/10 13:17');
    expect(formatAppVersionLine(appVersionFrom(sources({ isEmbeddedLaunch: true, commit: null })), ZONE)).toBe('3.1.9 (42) · built-in version');
  });

  it('no zone → the ISO clock (UTC here)', () => {
    expect(updateTimeText('2026-10-29T12:17:00.000Z', null)).toBe('29. 10. 12:17');
    expect(updateTimeText(null, ZONE)).toBeNull();
  });
});

describe('formatAppVersionDetails', () => {
  it('lists every known field for the clipboard', () => {
    expect(formatAppVersionDetails(appVersionFrom(sources()), ZONE).split('\n')).toEqual([
      'Različica: 3.1.9',
      'Gradnja: 42',
      'Runtime: a1b2c3d4e5f6',
      'Koda: posodobitev (OTA)',
      `Posodobitev: ${UPDATE_ID.toLowerCase()}`,
      'Objavljena: 29. 10. 13:17 (2026-10-29T12:17:00.000Z)',
      'Commit: 6bf527d',
      'Kanal: production',
    ]);
  });

  it('leaves missing fields out', () => {
    const info = appVersionFrom({
      version: '3.1.9',
      buildNumber: null,
      runtimeVersion: null,
      updatesEnabled: false,
      isEmbeddedLaunch: true,
      updateId: null,
      createdAt: null,
      channel: null,
      commit: null,
    });
    expect(formatAppVersionDetails(info, ZONE)).toBe('Različica: 3.1.9\nKoda: vgrajena različica');
  });
});

describe('readAppVersionSources', () => {
  const env = process.env.EXPO_PUBLIC_GIT_COMMIT;
  afterEach(() => {
    if (env === undefined) delete process.env.EXPO_PUBLIC_GIT_COMMIT;
    else process.env.EXPO_PUBLIC_GIT_COMMIT = env;
  });

  it('reads expo-updates constants and prefers the OTA commit from EXPO_PUBLIC_GIT_COMMIT', () => {
    process.env.EXPO_PUBLIC_GIT_COMMIT = 'f00ba12';
    const createdAt = new Date('2026-10-29T12:17:00Z');
    setUpdatesIdentityForTests({ isEnabled: true, isEmbeddedLaunch: false, updateId: UPDATE_ID, createdAt, channel: 'production', runtimeVersion: 'rt' });
    expect(readAppVersionSources()).toMatchObject({
      updatesEnabled: true,
      isEmbeddedLaunch: false,
      updateId: UPDATE_ID,
      createdAt,
      channel: 'production',
      runtimeVersion: 'rt',
      commit: 'f00ba12',
    });
  });

  it('without expo-updates: embedded, nothing throws', () => {
    delete process.env.EXPO_PUBLIC_GIT_COMMIT;
    setUpdatesIdentityForTests({});
    const read = readAppVersionSources();
    expect(read).toMatchObject({ updatesEnabled: false, isEmbeddedLaunch: true, updateId: null, createdAt: null, channel: null });
    expect(appVersionFrom(read).source).toBe('embedded');
  });
});
