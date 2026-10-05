/**
 * Build identity (which code is running): `extra.appVersion` / `extra.gitSha` set by
 * `app.config.ts` at config time, read through expo-constants. Shown as a small muted
 * label "v1.10.2 · abc1234" so testers can always say which build they use.
 */

import Constants from 'expo-constants';

export interface BuildInfo {
  version: string;
  sha: string;
}

function str(value: unknown): string | null {
  return typeof value === 'string' && value.trim().length > 0 ? value.trim() : null;
}

/** Read the build identity from an Expo config's `version` / `extra` (never throws). */
export function buildInfoFrom(config: { version?: string; extra?: Record<string, unknown> | null } | null | undefined): BuildInfo {
  const extra = config?.extra ?? {};
  return {
    version: str(extra.appVersion) ?? str(config?.version) ?? '0.0.0',
    sha: str(extra.gitSha) ?? 'dev',
  };
}

export function getBuildInfo(): BuildInfo {
  return buildInfoFrom(Constants.expoConfig);
}

/** "v1.10.2 · abc1234" */
export function formatBuildLabel(info: BuildInfo): string {
  return `v${info.version} · ${info.sha}`;
}
