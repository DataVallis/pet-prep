/**
 * M3-02: app config for push — expo-notifications plugin present, APNs environment
 * per EAS profile (sandbox only for development builds).
 */
import appJson from '../../../app.json';
import easJson from '../../../eas.json';
import { apnsMode, resolveGoogleServicesFile, withGoogleSignInPlugin, withPushMode } from '../../../app.config';

describe('expo-notifications config (app.json / app.config.ts)', () => {
  it('declares the plugin with the default Android channel and an EAS project id', () => {
    const entry = appJson.expo.plugins.find((p) => Array.isArray(p) && p[0] === 'expo-notifications');
    expect(entry).toEqual(['expo-notifications', expect.objectContaining({ defaultChannel: 'default' })]);
    expect(appJson.expo.extra.eas.projectId).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('uses the APNs sandbox only for development builds', () => {
    expect(apnsMode({})).toBe('development');
    expect(apnsMode({ EAS_BUILD_PROFILE: 'development' })).toBe('development');
    expect(apnsMode({ EAS_BUILD_PROFILE: 'preview' })).toBe('production');
    expect(apnsMode({ EAS_BUILD_PROFILE: 'production' })).toBe('production');
  });

  it('M5-F10: PETPREP_APNS_MODE from eas.json wins, so the Mac and the build server resolve the same config', () => {
    expect(apnsMode({ PETPREP_APNS_MODE: 'production' })).toBe('production');
    expect(apnsMode({ PETPREP_APNS_MODE: 'development', EAS_BUILD_PROFILE: 'production' })).toBe('development');
    expect(apnsMode({ PETPREP_APNS_MODE: 'bogus', EAS_BUILD_PROFILE: 'production' })).toBe('production');
    const profiles = easJson.build as Record<string, { env?: Record<string, string> }>;
    expect(profiles.development.env?.PETPREP_APNS_MODE).toBe('development');
    expect(profiles.preview.env?.PETPREP_APNS_MODE).toBe('production');
    expect(profiles.production.env?.PETPREP_APNS_MODE).toBe('production');
  });

  it('sets mode on the plugin entry and leaves other plugins alone', () => {
    const plugins = withPushMode(
      [['expo-secure-store', { faceIDPermission: 'x' }], ['expo-notifications', { color: '#1A7A55' }]],
      'production',
    );
    expect(plugins).toEqual([
      ['expo-secure-store', { faceIDPermission: 'x' }],
      ['expo-notifications', { color: '#1A7A55', mode: 'production' }],
    ]);
    expect(withPushMode(['expo-notifications'], 'development')).toEqual([['expo-notifications', { mode: 'development' }]]);
  });

  it('takes the Firebase config from the EAS file env var, else a local file, else none', () => {
    expect(resolveGoogleServicesFile({ GOOGLE_SERVICES_JSON: '/eas/secret/gs.json' }, () => true)).toBe('/eas/secret/gs.json');
    expect(resolveGoogleServicesFile({}, () => true)).toBe('./google-services.json');
    expect(resolveGoogleServicesFile({}, () => false)).toBeUndefined();
  });
});

describe('M2-10c: social sign-in config', () => {
  const SCHEME = 'com.googleusercontent.apps.1029107925972-fm90sk3l07phkqbkfcnjli2jddp4bqfu';

  it('adds the Google sign-in plugin (always with iosUrlScheme) only when the scheme env is set', () => {
    expect(withGoogleSignInPlugin(['expo-font'], {})).toEqual(['expo-font']);
    expect(withGoogleSignInPlugin(['expo-font'], { EXPO_PUBLIC_GOOGLE_IOS_URL_SCHEME: '  ' })).toEqual(['expo-font']);
    expect(withGoogleSignInPlugin(['expo-font'], { EXPO_PUBLIC_GOOGLE_IOS_URL_SCHEME: SCHEME })).toEqual([
      'expo-font',
      ['@react-native-google-signin/google-signin', { iosUrlScheme: SCHEME }],
    ]);
    // Never twice, and never the option-less (Firebase) form.
    expect(
      withGoogleSignInPlugin(['@react-native-google-signin/google-signin', 'expo-font'], { EXPO_PUBLIC_GOOGLE_IOS_URL_SCHEME: SCHEME }),
    ).toEqual(['expo-font', ['@react-native-google-signin/google-signin', { iosUrlScheme: SCHEME }]]);
  });

  it('Apple sign-in plugin in app.json; Google client IDs in every EAS profile', () => {
    expect(appJson.expo.plugins).toContain('expo-apple-authentication');
    const profiles = easJson.build as Record<string, { env?: Record<string, string> }>;
    for (const name of ['development', 'preview', 'production']) {
      const env = profiles[name].env ?? {};
      expect(env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID).toMatch(/\.apps\.googleusercontent\.com$/);
      expect(env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID).toMatch(/\.apps\.googleusercontent\.com$/);
      // The scheme is the reversed iOS client ID.
      expect(env.EXPO_PUBLIC_GOOGLE_IOS_URL_SCHEME).toBe(
        `com.googleusercontent.apps.${env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID.replace('.apps.googleusercontent.com', '')}`,
      );
    }
  });
});

