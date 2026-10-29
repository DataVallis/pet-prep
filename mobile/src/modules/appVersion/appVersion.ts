/**
 * App version line (David 2026-10-29): which store build AND which JS bundle this phone runs.
 *
 * With `updates.checkAutomatically: ON_LOAD` + `fallbackToCacheTimeout: 0` an OTA update
 * downloads in the background and runs only from the NEXT cold start, so "is the new code on
 * my phone yet?" can't be told from the outside. This module turns the raw identity
 * (`appVersionSource.ts` — expo-constants + expo-updates constants, read lazily when a
 * settings screen opens, never at boot) into:
 * - a short line »3.1.9 (42) · posodobitev 6bf527d · 29. 10. 13:17« (embedded bundle →
 *   »3.1.9 (42) · vgrajena različica«) for the parent's Nadzor and the child's settings sheet;
 * - full details (version, build, runtime, update id, commit, channel) the parent copies.
 *
 * The commit is the one `docs/research/app-release.json` records for a production OTA
 * (`scripts/mobile-ota.sh` exports `EXPO_PUBLIC_GIT_COMMIT`), so David can compare the two.
 * Pure: no native calls here; text comes from `common:appVersion` (current language).
 */

import { t } from '@/i18n';
import { clockText, localParts } from '@/modules/childPet/familyTime';

/** Raw identity, as read from the runtime (any field may be missing in Jest / Expo Go / web). */
export interface AppVersionSources {
  /** `expoConfig.extra.appVersion` / `expoConfig.version` (app.json `version`). */
  version: string | null;
  /** Native build number (iOS `CFBundleVersion` / Android `versionCode`). */
  buildNumber: string | null;
  runtimeVersion: string | null;
  /** `Updates.isEnabled` — false in dev, Expo Go, without the module. */
  updatesEnabled: boolean;
  /** `Updates.isEmbeddedLaunch` — the bundle shipped inside the store build is running. */
  isEmbeddedLaunch: boolean;
  updateId: string | null;
  createdAt: Date | null;
  channel: string | null;
  /** Git commit of the running bundle (`EXPO_PUBLIC_GIT_COMMIT` of the OTA, else `extra.gitSha`). */
  commit: string | null;
}

export interface AppVersionInfo {
  version: string;
  build: string | null;
  runtimeVersion: string | null;
  /** `update` = an OTA update is running; `embedded` = the store build's own bundle (or dev / Expo Go). */
  source: 'embedded' | 'update';
  /** Full lowercase UUID of the running update (embedded bundles have one too). */
  updateId: string | null;
  /** First 8 characters of `updateId`. */
  updateIdShort: string | null;
  /** ISO 8601 creation time of the running update, null when unknown. */
  createdAt: string | null;
  channel: string | null;
  /** 7-character commit, null when unknown ("dev", missing, not a hex sha). */
  commit: string | null;
}

export const UPDATE_ID_SHORT_LENGTH = 8;
export const COMMIT_LENGTH = 7;
const HEX_SHA = /^[0-9a-f]{7,40}$/i;

function text(value: string | null | undefined): string | null {
  if (typeof value !== 'string') return null;
  const trimmed = value.trim();
  return trimmed.length > 0 ? trimmed : null;
}

/** A 7-character lowercase sha, or null for "dev" / garbage / nothing. */
export function shortCommit(value: string | null | undefined): string | null {
  const raw = text(value);
  return raw && HEX_SHA.test(raw) ? raw.slice(0, COMMIT_LENGTH).toLowerCase() : null;
}

function isoOf(date: Date | null): string | null {
  if (!(date instanceof Date)) return null;
  const ms = date.getTime();
  return Number.isNaN(ms) ? null : new Date(ms).toISOString();
}

/** Normalise the raw sources (never throws). */
export function appVersionFrom(sources: AppVersionSources): AppVersionInfo {
  const updateId = text(sources.updateId)?.toLowerCase() ?? null;
  const isUpdate = sources.updatesEnabled && !sources.isEmbeddedLaunch && updateId !== null;
  return {
    version: text(sources.version) ?? '0.0.0',
    build: text(sources.buildNumber),
    runtimeVersion: text(sources.runtimeVersion),
    source: isUpdate ? 'update' : 'embedded',
    updateId,
    updateIdShort: updateId ? updateId.slice(0, UPDATE_ID_SHORT_LENGTH) : null,
    createdAt: isoOf(sources.createdAt),
    channel: text(sources.channel),
    commit: shortCommit(sources.commit),
  };
}

/** »29. 10. 13:17« in `timeZone` (family zone; null → the ISO string's own clock), or null. */
export function updateTimeText(iso: string | null, timeZone: string | null): string | null {
  if (!iso) return null;
  const parts = localParts(iso, timeZone);
  if (!parts) return null;
  const [, month, day] = parts.date.split('-');
  return t('common:appVersion.dateTime', { day: Number(day), month: Number(month), time: clockText(parts.time) });
}

/** »3.1.9 (42)« / »3.1.9« */
export function versionText(info: AppVersionInfo): string {
  return info.build ? `${info.version} (${info.build})` : info.version;
}

/**
 * The short line: »3.1.9 (42) · posodobitev 6bf527d · 29. 10. 13:17« for an OTA (the update id's
 * first 8 characters when the commit is unknown; no time when unknown), »3.1.9 (42) · vgrajena
 * različica · abc1234« for the store build's own bundle (commit only when known).
 */
export function formatAppVersionLine(info: AppVersionInfo, timeZone: string | null): string {
  const parts = [versionText(info)];
  if (info.source === 'update') {
    const id = info.commit ?? info.updateIdShort;
    parts.push(id ? t('common:appVersion.update', { id }) : t('common:appVersion.updateUnknown'));
    const when = updateTimeText(info.createdAt, timeZone);
    if (when) parts.push(when);
  } else {
    parts.push(t('common:appVersion.embedded'));
    if (info.commit) parts.push(info.commit);
  }
  return parts.join(' · ');
}

/** Every known field, one per line, for the clipboard (missing fields are left out). */
export function formatAppVersionDetails(info: AppVersionInfo, timeZone: string | null): string {
  const L = (label: string, value: string): string => `${label}: ${value}`;
  const lines = [L(t('common:appVersion.details.version'), info.version)];
  if (info.build) lines.push(L(t('common:appVersion.details.build'), info.build));
  if (info.runtimeVersion) lines.push(L(t('common:appVersion.details.runtime'), info.runtimeVersion));
  const bundle = info.source === 'update' ? t('common:appVersion.details.bundleUpdate') : t('common:appVersion.details.bundleEmbedded');
  lines.push(L(t('common:appVersion.details.bundle'), bundle));
  if (info.updateId) lines.push(L(t('common:appVersion.details.updateId'), info.updateId));
  if (info.createdAt) {
    const local = updateTimeText(info.createdAt, timeZone);
    lines.push(L(t('common:appVersion.details.createdAt'), local ? `${local} (${info.createdAt})` : info.createdAt));
  }
  if (info.commit) lines.push(L(t('common:appVersion.details.commit'), info.commit));
  if (info.channel) lines.push(L(t('common:appVersion.details.channel'), info.channel));
  return lines.join('\n');
}
