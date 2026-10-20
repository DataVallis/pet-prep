/**
 * Local copy of pet media on the phone (David 2026-10-20).
 *
 * Media URLs are signed and re-signed every ~30 min (`expires` + `signature`), so a
 * player / image cache keyed by the URL downloads the same file again whenever the
 * signature changes. This module keeps one copy per FILE instead: the name comes from
 * the media path + `v` (`mediaKey`), which changes only when the server stores a new
 * file. Flow:
 * - `localMediaUri(url)` — the `file://` copy when it exists, otherwise the remote URL;
 *   in that case the file is downloaded in the background (at most 2 at a time,
 *   one per file), so the NEXT player / image uses the copy. A source is never
 *   swapped under a running player.
 * - `forgetLocalMedia(url)` — a copy that failed to play is deleted (the caller then
 *   falls back to the remote URL).
 * - Size cap: oldest copies beyond `MAX_CACHE_BYTES` are deleted; older versions of
 *   the same media are deleted when a new one lands. The OS may also clear the cache
 *   folder (it is `Paths.cache`). `clearMediaCache()` on logout.
 * Only our own `/api/media/…` URLs are cached. Without the native module (Expo Go, web,
 * a binary built without it) everything just stays remote — the old behaviour.
 */

import { requireOptionalNativeModule } from 'expo';

import { mediaKey } from '@/modules/petMedia/petMedia';

/** Upper bound for all local copies (a full paid set is ~7–49 MB per pet). */
export const MAX_CACHE_BYTES = 300 * 1024 * 1024;
/** Parallel background downloads. */
export const MAX_PARALLEL_DOWNLOADS = 2;

const DIR_NAME = 'pet-media';

/** Kind of file — gives the local copy an extension (AVPlayer on iOS needs one for local files). */
export type MediaKind = 'video' | 'image';
const EXTENSION: Record<MediaKind, string> = { video: '.mp4', image: '.jpg' };
const PART_SUFFIX = '.part';

export interface CachedFileInfo {
  name: string;
  size: number;
  modified: number;
}

/** The file operations this module needs — real (expo-file-system) or a test fake. */
export interface MediaFileStore {
  exists(name: string): boolean;
  uri(name: string): string;
  /** Download `url` to `name` (complete file or a rejection); resolves with its size. */
  download(url: string, name: string): Promise<number>;
  remove(name: string): void;
  list(): CachedFileInfo[];
  clear(): void;
}

type FileSystemModule = typeof import('expo-file-system');

function baseName(uri: string): string {
  const clean = uri.endsWith('/') ? uri.slice(0, -1) : uri;
  return decodeURIComponent(clean.slice(clean.lastIndexOf('/') + 1));
}

function expoStore(): MediaFileStore | null {
  if (requireOptionalNativeModule('FileSystem') == null) return null;
  // Lazy: Jest and builds without the native module never load it.
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const fs = require('expo-file-system') as FileSystemModule;
  const dir = new fs.Directory(fs.Paths.cache, DIR_NAME);
  const ensureDir = () => {
    if (!dir.exists) dir.create({ intermediates: true, idempotent: true });
  };
  const file = (name: string) => new fs.File(dir, name);
  return {
    exists: (name) => file(name).exists,
    uri: (name) => file(name).uri,
    async download(url, name) {
      ensureDir();
      // Android may leave a partial file on failure: download beside, move when done.
      const part = file(name + PART_SUFFIX);
      if (part.exists) part.delete();
      try {
        const done = await fs.File.downloadFileAsync(url, part, { idempotent: true });
        const size = done.size ?? 0;
        if (size <= 0) throw new Error('empty download');
        await done.move(file(name), { overwrite: true });
        return size;
      } catch (error) {
        if (part.exists) part.delete();
        throw error;
      }
    },
    remove(name) {
      const f = file(name);
      if (f.exists) f.delete();
    },
    list() {
      if (!dir.exists) return [];
      return dir
        .list()
        .filter((entry): entry is InstanceType<FileSystemModule['File']> => entry instanceof fs.File)
        .map((f) => ({ name: baseName(f.uri), size: f.size ?? 0, modified: f.modificationTime ?? 0 }));
    },
    clear() {
      if (dir.exists) dir.delete();
    },
  };
}

let store: MediaFileStore | null | undefined;

function currentStore(): MediaFileStore | null {
  if (store === undefined) {
    try {
      store = expoStore();
    } catch {
      store = null;
    }
  }
  return store;
}

/** Tests: a fake store (null = no native module); `undefined` = back to the real one. */
export function setMediaFileStoreForTests(fake: MediaFileStore | null | undefined): void {
  store = fake;
  inFlight.clear();
  queue.length = 0;
  running = 0;
}

/**
 * File name for a media URL: `media_123_<v>` / `media_history_45_<v>`; null for a URL
 * that isn't ours or has no version (never cached).
 */
export function cacheNameFor(url: string): string | null {
  const key = mediaKey(url);
  const match = /\/api\/media\/((?:history\/)?\d+)\?v=([A-Za-z0-9]{1,40})$/.exec(key);
  if (!match) return null;
  return `media_${match[1].replace('/', '_')}_${match[2]}`;
}

function fileNameFor(url: string, kind: MediaKind): string | null {
  const base = cacheNameFor(url);
  return base === null ? null : base + EXTENSION[kind];
}

/** `media_123_` — every version of one media shares it. */
function mediaPrefix(name: string): string {
  return name.slice(0, name.lastIndexOf('_') + 1);
}

const inFlight = new Set<string>();
const queue: { url: string; name: string }[] = [];
let running = 0;
/** Bumped by clearMediaCache(): a download that finishes after a logout is deleted. */
let generation = 0;

function pump(): void {
  const s = currentStore();
  while (s && running < MAX_PARALLEL_DOWNLOADS && queue.length > 0) {
    const job = queue.shift();
    if (!job) break;
    running += 1;
    const startedIn = generation;
    s.download(job.url, job.name)
      .then(() => {
        if (startedIn !== generation) s.remove(job.name);
        else prune(s, job.name);
      })
      .catch(() => undefined) // stays remote; the next use tries again
      .finally(() => {
        running -= 1;
        inFlight.delete(job.name);
        pump();
      });
  }
}

/** After a download: drop older versions of that media, then the oldest copies over the cap. */
function prune(s: MediaFileStore, keep: string): void {
  try {
    const prefix = mediaPrefix(keep);
    const files = s.list().filter((f) => !f.name.endsWith(PART_SUFFIX));
    for (const f of files) {
      if (f.name !== keep && f.name.startsWith(prefix)) s.remove(f.name);
    }
    const left = s
      .list()
      .filter((f) => !f.name.endsWith(PART_SUFFIX))
      .sort((a, b) => a.modified - b.modified);
    let total = left.reduce((sum, f) => sum + f.size, 0);
    for (const f of left) {
      if (total <= MAX_CACHE_BYTES) break;
      if (f.name === keep) continue;
      s.remove(f.name);
      total -= f.size;
    }
  } catch {
    // A failed clean-up never breaks playback.
  }
}

/**
 * The local copy of this media file when there is one; otherwise `url` itself, and the
 * file is downloaded in the background for next time. Null stays null.
 */
export function localMediaUri(url: string | null, kind: MediaKind): string | null {
  if (url === null) return null;
  const s = currentStore();
  const name = fileNameFor(url, kind);
  if (!s || !name) return url;
  try {
    if (s.exists(name)) return s.uri(name);
  } catch {
    return url;
  }
  if (!inFlight.has(name)) {
    inFlight.add(name);
    queue.push({ url, name });
    pump();
  }
  return url;
}

/** Is this URI a local copy made by this module? */
export function isLocalMediaUri(uri: string | null): boolean {
  return uri !== null && uri.startsWith('file:');
}

/** A copy that failed to play / decode: delete it, the next use goes remote again. */
export function forgetLocalMedia(url: string | null, kind: MediaKind): void {
  if (url === null) return;
  const s = currentStore();
  const name = fileNameFor(url, kind);
  if (!s || !name) return;
  try {
    s.remove(name);
  } catch {
    // ignore
  }
}

/** Delete every local copy (logout). Never throws. */
export function clearMediaCache(): void {
  const s = currentStore();
  generation += 1;
  queue.length = 0;
  inFlight.clear();
  try {
    s?.clear();
  } catch {
    // ignore
  }
}
