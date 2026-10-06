/**
 * M3-02: app config for push — expo-notifications plugin present, APNs environment
 * per EAS profile (sandbox only for development builds).
 */
import appJson from '../../../app.json';
import { apnsMode, resolveGoogleServicesFile, withPushMode } from '../../../app.config';

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
