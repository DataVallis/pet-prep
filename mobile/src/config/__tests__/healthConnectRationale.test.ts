/**
 * Health Connect privacy-policy link (Google Play health declaration): the rationale intents
 * must open HealthPermissionsRationaleActivity (shows the privacy policy), not MainActivity.
 * Expo runs manifest mods in reverse plugin order, so our plugin must be listed BEFORE
 * react-native-health-connect (verified with `expo prebuild`, 2026-10-19).
 */
import appJson from '../../../app.json';

// eslint-disable-next-line @typescript-eslint/no-require-imports
const plugin = require('../../../plugins/withHealthConnectPrivacyRationale.js') as {
  applyRationaleActivity: (manifest: Manifest) => Manifest;
  activitySource: (pkg: string) => string;
  PRIVACY_URL_EN: string;
  PRIVACY_URL_SL: string;
};

type Node = { $: Record<string, string>; 'intent-filter'?: { action?: { $: Record<string, string> }[] }[] };
type Manifest = { manifest: { application: { $: Record<string, string>; activity: Node[]; 'activity-alias'?: Node[] }[] } };

const RATIONALE = 'androidx.health.ACTION_SHOW_PERMISSIONS_RATIONALE';

function healthConnectManifest(): Manifest {
  return {
    manifest: {
      application: [
        {
          $: { 'android:name': '.MainApplication' },
          activity: [
            {
              $: { 'android:name': '.MainActivity' },
              'intent-filter': [
                { action: [{ $: { 'android:name': 'android.intent.action.MAIN' } }] },
                { action: [{ $: { 'android:name': RATIONALE } }] },
              ],
            },
          ],
          'activity-alias': [{ $: { 'android:name': 'ViewPermissionUsageActivity', 'android:targetActivity': '.MainActivity' } }],
        },
      ],
    },
  };
}

const actions = (node: Node) => (node['intent-filter'] ?? []).flatMap((f) => (f.action ?? []).map((a) => a.$['android:name']));

describe('withHealthConnectPrivacyRationale', () => {
  it('moves both Health Connect entry points from MainActivity to the rationale activity', () => {
    const app = plugin.applyRationaleActivity(healthConnectManifest()).manifest.application[0]!;
    const main = app.activity.find((a) => a.$['android:name'] === '.MainActivity')!;
    const rationale = app.activity.find((a) => a.$['android:name'] === '.HealthPermissionsRationaleActivity')!;

    expect(actions(main)).toEqual(['android.intent.action.MAIN']);
    expect(actions(rationale)).toEqual([RATIONALE]);
    expect(rationale.$['android:exported']).toBe('true');
    expect(app['activity-alias']![0]!.$['android:targetActivity']).toBe('.HealthPermissionsRationaleActivity');
  });

  it('is idempotent (one rationale activity)', () => {
    const once = plugin.applyRationaleActivity(healthConnectManifest());
    const twice = plugin.applyRationaleActivity(once).manifest.application[0]!;
    expect(twice.activity.filter((a) => a.$['android:name'] === '.HealthPermissionsRationaleActivity')).toHaveLength(1);
  });

  it('opens the Slovenian or English privacy policy', () => {
    const source = plugin.activitySource('com.example.app');
    expect(source).toContain('package com.example.app');
    expect(source).toContain(plugin.PRIVACY_URL_SL);
    expect(source).toContain(plugin.PRIVACY_URL_EN);
  });

  it('is listed before react-native-health-connect so its manifest mod runs after it', () => {
    const names = appJson.expo.plugins.map((p) => (Array.isArray(p) ? p[0] : p));
    expect(names.indexOf('./plugins/withHealthConnectPrivacyRationale.js')).toBeGreaterThanOrEqual(0);
    expect(names.indexOf('./plugins/withHealthConnectPrivacyRationale.js')).toBeLessThan(names.indexOf('react-native-health-connect'));
  });
});
