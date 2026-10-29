/**
 * Reads the raw app identity for `appVersion.ts` — synchronously, only when a settings screen
 * renders the version line (never on the boot path). Constants only: no update check, no
 * download, no reload (those live in `updateCheck.ts`, behind the parent's button).
 *
 * - version: `expoConfig.extra.appVersion` / `expoConfig.version` (app.json `version`);
 * - build: the NATIVE build number (`Constants.platform.ios.buildNumber` / Android `versionCode`) —
 *   expo-application is deliberately not added: a new native module changes the fingerprint
 *   runtime and would need a new store build before this line could ship by OTA;
 * - commit: `process.env.EXPO_PUBLIC_GIT_COMMIT` (inlined by `scripts/mobile-ota.sh` into the OTA
 *   bundle — the commit `docs/research/app-release.json` records), else `extra.gitSha`
 *   (`app.config.ts`: the EAS build commit / local git);
 * - expo-updates constants via a lazy `require` after probing the native module (Jest, web and
 *   binaries without it → embedded / unknown; never throws).
 */

import { requireOptionalNativeModule } from 'expo';
import Constants from 'expo-constants';
import { Platform } from 'react-native';

import type { AppVersionSources } from './appVersion';

/** The expo-updates constants this module reads (own type: the boot guard forbids importing the package statically). */
export interface UpdatesIdentity {
  isEnabled?: boolean;
  isEmbeddedLaunch?: boolean;
  updateId?: string | null;
  createdAt?: Date | null;
  channel?: string | null;
  runtimeVersion?: string | null;
}

function str(value: unknown): string | null {
  if (typeof value === 'number' && Number.isFinite(value)) return String(value);
  return typeof value === 'string' && value.trim().length > 0 ? value.trim() : null;
}

function loadUpdatesIdentity(): UpdatesIdentity {
  try {
    if (requireOptionalNativeModule('ExpoUpdates') == null) return {};
    return require('expo-updates') as UpdatesIdentity;
  } catch {
    return {};
  }
}

let identityOverride: UpdatesIdentity | null = null;

/** Tests: pretend expo-updates reports this identity (null = the real lookup). */
export function setUpdatesIdentityForTests(next: UpdatesIdentity | null): void {
  identityOverride = next;
}

function nativeBuildNumber(): string | null {
  try {
    const platform = Constants.platform;
    if (Platform.OS === 'ios') return str(platform?.ios?.buildNumber);
    if (Platform.OS === 'android') return str(platform?.android?.versionCode);
    return null;
  } catch {
    return null;
  }
}

/** The raw identity of the running app (never throws). */
export function readAppVersionSources(): AppVersionSources {
  const config = Constants.expoConfig;
  const extra = (config?.extra ?? {}) as Record<string, unknown>;
  const updates = identityOverride ?? loadUpdatesIdentity();
  return {
    version: str(extra.appVersion) ?? str(config?.version),
    buildNumber: nativeBuildNumber(),
    runtimeVersion: str(updates.runtimeVersion),
    updatesEnabled: updates.isEnabled === true,
    isEmbeddedLaunch: updates.isEmbeddedLaunch !== false,
    updateId: str(updates.updateId),
    createdAt: updates.createdAt instanceof Date ? updates.createdAt : null,
    channel: str(updates.channel),
    commit: str(process.env.EXPO_PUBLIC_GIT_COMMIT) ?? str(extra.gitSha),
  };
}
