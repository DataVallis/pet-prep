#!/usr/bin/env node
/**
 * Record what the app users actually have (David 2026-10-13: "on the website it must show what is
 * really in the app — if we haven't shipped the update yet, it must not say it is already in the app").
 *
 * Writes docs/research/app-release.json = the breeds of the JS bundle of the last production release
 * (store build or OTA update). scripts/export-breed-registry.mjs derives every breed's
 * `availability` from it: released → in_app, built on main but not released → coming_soon.
 *
 *   node scripts/record-app-release.mjs --kind ota   --commit <sha>              # mobile-ota.yml, after a published update
 *   node scripts/record-app-release.mjs --kind store --commit <sha> [--version X] # after David's EAS production build
 *
 * `--commit` = the commit the release was built from (default HEAD); the breed list is read from
 * that commit's mobile/src/modules/species/species.ts (BREED_SPECIES), the version from its
 * mobile/app.json unless `--version` is given. Record a release only when BOTH platforms have it.
 */
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
export const APP_RELEASE = 'docs/research/app-release.json';
const SPECIES_TS = 'mobile/src/modules/species/species.ts';

/** BREED_SPECIES keys of species.ts source, in file order. */
export function breedsFromSpeciesTs(source) {
  const m = source.match(/export const BREED_SPECIES[^=]*=\s*\{([\s\S]*?)\n\};/);
  if (!m) throw new Error(`${SPECIES_TS}: BREED_SPECIES not found`);
  const ids = [...m[1].matchAll(/^\s*([a-z][a-z0-9_]*)\s*:\s*'(?:dog|cat|[a-z]+)'/gm)].map((x) => x[1]);
  if (ids.length === 0 || new Set(ids).size !== ids.length) throw new Error(`${SPECIES_TS}: empty or duplicate BREED_SPECIES`);
  return ids;
}

/** Validates a parsed app-release.json and returns the set of released breed ids. */
export function releasedBreeds(release) {
  const where = APP_RELEASE;
  if (!release || !['store', 'ota'].includes(release.kind)) throw new Error(`${where}: kind must be store or ota`);
  if (!/^[0-9a-f]{7,40}$/.test(release.commit ?? '')) throw new Error(`${where}: commit must be a git sha`);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(release.date ?? '')) throw new Error(`${where}: date must be YYYY-MM-DD`);
  if (!Array.isArray(release.breeds) || release.breeds.length === 0) throw new Error(`${where}: breeds must be a non-empty list`);
  const set = new Set(release.breeds);
  if (set.size !== release.breeds.length) throw new Error(`${where}: duplicate breed`);
  return set;
}

function git(args, root) {
  return execFileSync('git', args, { cwd: root, encoding: 'utf8' });
}

export function recordRelease({ kind, commit = 'HEAD', version = null, date = new Date().toISOString().slice(0, 10), root = ROOT }) {
  const sha = git(['rev-parse', '--verify', `${commit}^{commit}`], root).trim();
  const breeds = breedsFromSpeciesTs(git(['show', `${sha}:${SPECIES_TS}`], root));
  const appVersion = version ?? JSON.parse(git(['show', `${sha}:mobile/app.json`], root)).expo.version;
  const release = {
    note: 'What production app users have (store build or OTA update). Written by scripts/record-app-release.mjs — never by hand. The register export marks these breeds in_app; breeds on main but not here are coming_soon.',
    kind,
    version: appVersion,
    commit: sha,
    date,
    breeds,
  };
  releasedBreeds(release);
  writeFileSync(resolve(root, APP_RELEASE), `${JSON.stringify(release, null, 2)}\n`);
  return release;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2);
  const opt = (name) => {
    const i = args.indexOf(`--${name}`);
    return i === -1 ? undefined : args[i + 1];
  };
  const kind = opt('kind');
  if (!['store', 'ota'].includes(kind)) {
    console.error('usage: record-app-release.mjs --kind store|ota [--commit <sha>] [--version X] [--date YYYY-MM-DD]');
    process.exit(2);
  }
  const r = recordRelease({ kind, commit: opt('commit') ?? 'HEAD', version: opt('version') ?? null, date: opt('date') });
  console.log(`${APP_RELEASE}: ${r.kind} ${r.version} @ ${r.commit.slice(0, 8)} — ${r.breeds.length} breeds`);
}
