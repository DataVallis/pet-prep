#!/usr/bin/env node
/**
 * Care knowledge export (M6-K0, David 2026-10-31) — build-time data for the care pages on the
 * marketing website (repo DataVallis/pet-prep-website: /care/…, SL /skrb/…).
 *
 * Reads  docs/research/care/foods/<species>/<slug>.json + sources-<species>.md
 * Writes docs/research/care-knowledge.json (stable, sorted)
 *
 * Only locales whose `verification[L].verified_hash` equals the current content hash are
 * published; a food with no verified locale is left out. Quotes are NOT exported (the site
 * paraphrases and links the source). Format: docs/research/care/README.md.
 *
 *   node scripts/export-care-knowledge.mjs              validate + write the export
 *   node scripts/export-care-knowledge.mjs --check      validate only (non-zero on errors)
 *   node scripts/export-care-knowledge.mjs --hashes F   print "<locale> <hash> <state>" for one file
 */
import { createHash } from 'node:crypto';
import { readFileSync, readdirSync, writeFileSync, existsSync } from 'node:fs';
import { join, dirname, basename } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const CARE = join(ROOT, 'docs/research/care');
const OUT = join(ROOT, 'docs/research/care-knowledge.json');

export const LEVELS = ['toxic', 'dangerous', 'not_recommended', 'small_amounts'];
const KEYS = ['slug', 'species', 'name', 'question', 'url_slug', 'level', 'answer', 'why', 'signs', 'what_to_do', 'serving',
  'child', 'faq', 'sources', 'related', 'updated', 'verification'];
const SLUG = /^[a-z0-9]+(-[a-z0-9]+)*$/;
const words = (s) => s.trim().split(/\s+/).length;

export function contentHash(f, L) {
  const v = [f.slug, f.species, f.level, f.name[L], f.question?.[L] ?? null, f.answer[L], f.why[L], f.signs?.[L] ?? null, f.what_to_do[L],
    f.serving?.[L] ?? null, f.child[L], (f.faq ?? []).map((x) => [x.q[L], x.a[L]]), f.sources];
  return createHash('sha256').update(JSON.stringify(v), 'utf8').digest('hex');
}

/** Parses the markdown table of a sources file: | ID | Tier | Publisher | Title | URL | Notes | */
export function parseSources(md) {
  const out = {};
  for (const line of md.split('\n')) {
    const c = line.split('|').map((s) => s.trim());
    if (c.length < 7 || !/^K[A-Z]\d+$/.test(c[1])) continue;
    out[c[1]] = { id: c[1], tier: c[2].charAt(0), publisher: c[3], title: c[4], url: c[5] };
  }
  return out;
}

export function validate(f, file, sources) {
  const e = [];
  const err = (m) => e.push(`${file}: ${m}`);
  for (const k of Object.keys(f)) if (!KEYS.includes(k)) err(`unknown key "${k}"`);
  if (!SLUG.test(f.slug ?? '')) err('bad slug');
  if (basename(file, '.json') !== f.slug) err('slug must equal the file name');
  if (basename(dirname(file)) !== f.species) err('species must equal the folder name');
  if (!LEVELS.includes(f.level)) err(`level must be one of ${LEVELS.join(', ')}`);
  const locales = Object.keys(f.name ?? {});
  if (!locales.includes('en')) err('name.en required');
  const maps = ['name', 'question', 'url_slug', 'answer', 'why', 'what_to_do', 'child'];
  for (const m of maps) {
    if (!f[m] || typeof f[m] !== 'object') { err(`${m} required`); continue; }
    if (Object.keys(f[m]).sort().join() !== [...locales].sort().join()) err(`${m}: locales differ from name`);
    for (const L of locales) if (typeof f[m][L] !== 'string' || !f[m][L].trim()) err(`${m}.${L} empty`);
  }
  for (const L of locales) {
    if (f.answer?.[L] && words(f.answer[L]) > 60) err(`answer.${L} over 60 words`);
    if (f.child?.[L] && words(f.child[L]) > 30) err(`child.${L} over 30 words`);
    if (f.url_slug?.[L] && !SLUG.test(f.url_slug[L])) err(`url_slug.${L} not ASCII slug`);
  }
  if (f.level === 'small_amounts' ? f.signs !== null && f.signs !== undefined && !f.signs : !f.signs) {
    if (f.level !== 'small_amounts') err('signs required unless level is small_amounts');
  }
  if (f.signs) for (const L of locales) if (!Array.isArray(f.signs[L]) || !f.signs[L].length) err(`signs.${L} must be a non-empty list`);
  if (f.serving != null) for (const L of locales) if (!f.serving[L]) err(`serving.${L} empty`);
  if (!Array.isArray(f.faq) || f.faq.length > 4) err('faq must be a list of 0–4');
  for (const [i, q] of (f.faq ?? []).entries()) for (const L of locales) if (!q.q?.[L] || !q.a?.[L]) err(`faq[${i}].${L} empty`);
  if (!Array.isArray(f.sources) || !f.sources.length) err('sources required');
  let ab = false;
  for (const s of f.sources ?? []) {
    if (!sources[s.id]) err(`source ${s.id} not in sources-${f.species}.md`);
    else if ('AB'.includes(sources[s.id].tier)) ab = true;
    if (!s.quote || typeof s.quote !== 'string') err(`source ${s.id}: quote required`);
    if (Object.keys(s).some((k) => k !== 'id' && k !== 'quote')) err(`source ${s.id}: only id + quote`);
  }
  if (f.sources?.length && !ab) err('at least one tier A or B source required');
  if (!Array.isArray(f.related)) err('related must be a list');
  if (!/^\d{4}-\d{2}-\d{2}$/.test(f.updated ?? '')) err('updated must be YYYY-MM-DD');
  for (const L of locales) {
    const v = f.verification?.[L];
    if (!v || !['draft', 'verified'].includes(v.status)) err(`verification.${L} missing or bad status`);
    else if (v.status === 'verified' && (!v.verified_by || !v.verified_hash)) err(`verification.${L}: verified needs verified_by + verified_hash`);
  }
  return e;
}

export function load() {
  const species = readdirSync(join(CARE, 'foods')).filter((d) => !d.startsWith('.')).sort();
  const sources = {};
  const foods = [];
  for (const sp of species) {
    const sf = join(CARE, `sources-${sp}.md`);
    sources[sp] = existsSync(sf) ? parseSources(readFileSync(sf, 'utf8')) : {};
    for (const fn of readdirSync(join(CARE, 'foods', sp)).filter((n) => n.endsWith('.json')).sort()) {
      const file = join(CARE, 'foods', sp, fn);
      foods.push({ file, data: JSON.parse(readFileSync(file, 'utf8')) });
    }
  }
  return { species, sources, foods };
}

export function build({ species, sources, foods }) {
  const errors = [];
  const out = [];
  const used = new Set();
  const bySpecies = Object.fromEntries(species.map((s) => [s, new Set()]));
  for (const { data } of foods) bySpecies[data.species]?.add(data.slug);
  for (const { file, data: f } of foods) {
    const rel = file.slice(ROOT.length + 1);
    errors.push(...validate(f, rel, sources[f.species] ?? {}));
    for (const r of f.related ?? []) if (!bySpecies[f.species]?.has(r)) errors.push(`${rel}: related "${r}" has no file`);
    const locales = Object.keys(f.name ?? {}).filter((L) => f.verification?.[L]?.status === 'verified'
      && f.verification[L].verified_hash === contentHash(f, L));
    if (!locales.length) continue;
    const pick = (m) => (m == null ? null : Object.fromEntries(locales.map((L) => [L, m[L]])));
    for (const s of f.sources) used.add(`${f.species}:${s.id}`);
    out.push({
      slug: f.slug, species: f.species, level: f.level, locales,
      name: pick(f.name), question: pick(f.question), url_slug: pick(f.url_slug), answer: pick(f.answer), why: pick(f.why),
      signs: pick(f.signs), what_to_do: pick(f.what_to_do), serving: pick(f.serving), child: pick(f.child),
      faq: (f.faq ?? []).map((x) => ({ q: pick(x.q), a: pick(x.a) })),
      source_ids: f.sources.map((s) => s.id), related: f.related, updated: f.updated,
    });
  }
  const src = [...used].sort().map((k) => { const [sp, id] = k.split(':'); return { species: sp, ...sources[sp][id] }; });
  return { errors, data: { schema_version: 1, generator: 'scripts/export-care-knowledge.mjs', levels: LEVELS, species, foods: out, sources: src } };
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2);
  if (args[0] === '--hashes') {
    const f = JSON.parse(readFileSync(args[1], 'utf8'));
    for (const L of Object.keys(f.name)) {
      const h = contentHash(f, L);
      const v = f.verification?.[L]?.verified_hash;
      console.log(L, h, !v ? 'no-verified_hash' : v === h ? 'verified_hash-ok' : 'verified_hash-MISMATCH');
    }
    process.exit(0);
  }
  const { errors, data } = build(load());
  if (errors.length) { console.error(errors.join('\n')); process.exit(1); }
  if (args[0] !== '--check') writeFileSync(OUT, `${JSON.stringify(data, null, 2)}\n`);
  console.log(`care-knowledge: ${data.foods.length} published food entries, ${data.sources.length} sources${args[0] === '--check' ? ' (check only)' : ''}`);
}
