// M6-K0: care knowledge export — every food file valid, only verified locales published,
// committed docs/research/care-knowledge.json up to date.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { build, load, contentHash, validate, parseSources } from '../export-care-knowledge.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

test('every food file is valid', () => {
  const { errors } = build(load());
  assert.deepEqual(errors, []);
});

test('committed export is up to date', () => {
  const out = join(ROOT, 'docs/research/care-knowledge.json');
  assert.ok(existsSync(out), 'run: node scripts/export-care-knowledge.mjs');
  const { data } = build(load());
  assert.equal(readFileSync(out, 'utf8'), `${JSON.stringify(data, null, 2)}\n`, 'run: node scripts/export-care-knowledge.mjs');
});

test('quotes are never exported', () => {
  const { data } = build(load());
  assert.ok(!JSON.stringify(data).includes('"quote"'));
});

const sources = parseSources('| ID | Tier |\n|---|---|\n| KD1 | A | FDA | T | https://x.test | |\n| KD2 | C | Blog | T | https://y.test | |');
const base = () => ({
  slug: 'chocolate', species: 'dog', name: { en: 'Chocolate', sl: 'Čokolada' },
  question: { en: 'Can dogs eat chocolate?', sl: 'Ali lahko psi jedo čokolado?' },
  url_slug: { en: 'chocolate', sl: 'cokolada' }, level: 'toxic',
  answer: { en: 'No.', sl: 'Ne.' }, why: { en: 'w', sl: 'w' }, signs: { en: ['a'], sl: ['a'] },
  what_to_do: { en: 'Call', sl: 'Pokliči' }, serving: null, child: { en: 'c', sl: 'c' }, faq: [],
  sources: [{ id: 'KD1', quote: 'q' }], related: [], updated: '2026-10-31',
  verification: { en: { status: 'draft', verified_by: null, verified_hash: null }, sl: { status: 'draft', verified_by: null, verified_hash: null } },
});
const FILE = 'docs/research/care/foods/dog/chocolate.json';

test('valid fixture passes; unknown key, bad level and C-only sources fail', () => {
  assert.deepEqual(validate(base(), FILE, sources), []);
  assert.ok(validate({ ...base(), extra: 1 }, FILE, sources).some((e) => e.includes('unknown key')));
  assert.ok(validate({ ...base(), level: 'deadly' }, FILE, sources).some((e) => e.includes('level')));
  assert.ok(validate({ ...base(), sources: [{ id: 'KD2', quote: 'q' }] }, FILE, sources).some((e) => e.includes('tier A or B')));
  assert.ok(validate({ ...base(), question: { en: 'x' } }, FILE, sources).some((e) => e.includes('locales differ')));
});

test('hash changes with the text, publishes only a verified locale', () => {
  const f = base();
  const h = contentHash(f, 'en');
  assert.notEqual(h, contentHash({ ...f, answer: { ...f.answer, en: 'No!' } }, 'en'));
  assert.equal(h, contentHash({ ...f, verification: {} }, 'en'), 'verification is not part of the hash');
  f.verification.en = { status: 'verified', verified_by: 't', verified_hash: h };
  const species = ['dog'];
  const r = build({ species, sources: { dog: sources }, foods: [{ file: join(ROOT, FILE), data: f }] });
  assert.deepEqual(r.data.foods[0].locales, ['en']);
  f.answer.en = 'Changed after verification.';
  const r2 = build({ species, sources: { dog: sources }, foods: [{ file: join(ROOT, FILE), data: f }] });
  assert.equal(r2.data.foods.length, 0, 'edited text is unpublished until verified again');
});
