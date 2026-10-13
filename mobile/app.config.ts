/// <reference types="node" />
/**
 * Dynamic Expo config: `app.json` stays the source of truth (EAS edits it), this file
 * only adds the build identity so we always know which code is being tested
 * (shown as "v{version} · {sha}" on the start screen and in the parent's Nadzor):
 *
 * - `extra.gitSha` — `EAS_BUILD_GIT_COMMIT_HASH` on EAS Build (the build server gets an
 *   archive without `.git`), else `git rev-parse --short HEAD` (local `expo start` /
 *   local builds), else `dev`.
 * - `extra.appVersion` — `version` from app.json.
 *
 * Read in the app via `src/config/buildInfo.ts` (expo-constants).
 *
 * Push (M3-02): the `expo-notifications` plugin's iOS `mode` (aps-environment) follows
 * the EAS profile — `development` builds are signed for the APNs sandbox, preview
 * (ad hoc) and production (TestFlight / App Store) builds for production APNs.
 * Android needs Firebase's `google-services.json` for FCM: the EAS file env var
 * `GOOGLE_SERVICES_JSON` (path on the build server), else `./google-services.json` if
 * present locally (gitignored), else nothing (Android push then can't get a token).
 *
 * Google sign-in (M2-10c): the `@react-native-google-signin/google-signin` plugin (iOS URL
 * scheme in Info.plist) is added only when `EXPO_PUBLIC_GOOGLE_IOS_URL_SCHEME` is set
 * (`eas.json` env of every profile, or `mobile/.env`). Without it the build still works and
 * the app hides the Google button on iOS. Always WITH `iosUrlScheme` — the plugin without
 * options would switch to its Firebase mode. Apple sign-in: `expo-apple-authentication`
 * plugin in app.json (entitlement `com.apple.developer.applesignin`).
 */

import { execSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { env } from 'node:process';
import type { ConfigContext, ExpoConfig } from 'expo/config';

const SHA_LENGTH = 7;

export function resolveGitSha(
  vars: Readonly<Record<string, string | undefined>> = env,
  git: () => string = () => execSync('git rev-parse --short HEAD', { stdio: ['ignore', 'pipe', 'ignore'] }).toString(),
): string {
  const eas = vars.EAS_BUILD_GIT_COMMIT_HASH?.trim();
  if (eas) return eas.slice(0, SHA_LENGTH);
  try {
    const local = git().trim();
    return local.length > 0 ? local.slice(0, SHA_LENGTH) : 'dev';
  } catch {
    return 'dev';
  }
}

type PluginEntry = NonNullable<ExpoConfig['plugins']>[number];

/**
 * APNs environment for the build: sandbox only for EAS `development` / local dev builds.
 * `PETPREP_APNS_MODE` (set per profile in `eas.json` `env`) wins: `eas build` applies the
 * profile env both on the Mac and on the build server, while `EAS_BUILD_PROFILE` exists only
 * on the server — with the OTA fingerprint (M5-F10) the two config evaluations must match,
 * or EAS refuses the build ("Runtime version calculated on local machine not equal …").
 */
export function apnsMode(vars: Readonly<Record<string, string | undefined>> = env): 'development' | 'production' {
  const explicit = vars.PETPREP_APNS_MODE?.trim();
  if (explicit === 'development' || explicit === 'production') return explicit;
  const profile = vars.EAS_BUILD_PROFILE?.trim();
  return profile && profile !== 'development' ? 'production' : 'development';
}

/** Set `mode` on the expo-notifications plugin entry (other plugins unchanged). */
export function withPushMode(plugins: ExpoConfig['plugins'], mode: 'development' | 'production'): ExpoConfig['plugins'] {
  return plugins?.map((entry): PluginEntry => {
    if (entry === 'expo-notifications') return ['expo-notifications', { mode }];
    if (Array.isArray(entry) && entry[0] === 'expo-notifications') {
      const options = (entry[1] ?? {}) as Record<string, unknown>;
      return ['expo-notifications', { ...options, mode }];
    }
    return entry;
  });
}

const GOOGLE_SIGNIN_PLUGIN = '@react-native-google-signin/google-signin';

/** Add the Google sign-in plugin with the iOS URL scheme from the env (no scheme → unchanged). */
export function withGoogleSignInPlugin(
  plugins: ExpoConfig['plugins'],
  vars: Readonly<Record<string, string | undefined>> = env,
): ExpoConfig['plugins'] {
  const iosUrlScheme = vars.EXPO_PUBLIC_GOOGLE_IOS_URL_SCHEME?.trim();
  const rest = (plugins ?? []).filter((entry) => (Array.isArray(entry) ? entry[0] : entry) !== GOOGLE_SIGNIN_PLUGIN);
  if (!iosUrlScheme) return plugins === undefined ? undefined : rest;
  return [...rest, [GOOGLE_SIGNIN_PLUGIN, { iosUrlScheme }]];
}

/** Firebase config for Android push: EAS file env var, else a local file, else none. */
export function resolveGoogleServicesFile(
  vars: Readonly<Record<string, string | undefined>> = env,
  exists: (path: string) => boolean = (path) => existsSync(join(__dirname, path)),
): string | undefined {
  const fromEas = vars.GOOGLE_SERVICES_JSON?.trim();
  if (fromEas) return fromEas;
  return exists('google-services.json') ? './google-services.json' : undefined;
}

export default ({ config }: ConfigContext): ExpoConfig => {
  const googleServicesFile = resolveGoogleServicesFile();
  return {
    ...config,
    plugins: withGoogleSignInPlugin(withPushMode(config.plugins, apnsMode())),
    android: {
      ...config.android,
      ...(googleServicesFile ? { googleServicesFile } : {}),
    },
    name: config.name ?? 'PetPrep',
    slug: config.slug ?? 'petprep',
    extra: {
      ...config.extra,
      gitSha: resolveGitSha(),
      appVersion: config.version ?? '0.0.0',
    },
  };
};
