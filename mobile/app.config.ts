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
 */

import { execSync } from 'node:child_process';
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

export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name: config.name ?? 'PetPrep',
  slug: config.slug ?? 'petprep',
  extra: {
    ...config.extra,
    gitSha: resolveGitSha(),
    appVersion: config.version ?? '0.0.0',
  },
});
