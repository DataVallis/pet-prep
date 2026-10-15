// Tests for scripts/breeds-index.mjs (docs/breeds index, David 2026-10-15).
// Run: node --test scripts/tests/breeds-index.test.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { test } from 'node:test';
import { END, INDEX, REGISTRY, ROOT, START, breedFiles, currentIndex, renderIndex, renderTable, statusOf } from '../breeds-index.mjs';

const registry = JSON.parse(readFileSync(resolve(ROOT, REGISTRY), 'utf8'));

test('the committed docs/breeds/README.md index is up to date (deterministic)', () => {
  const a = currentIndex();
  assert.equal(a, currentIndex());
  assert.equal(readFileSync(resolve(ROOT, INDEX), 'utf8'), a, `${INDEX} is stale — run: node scripts/breeds-index.mjs`);
});

test('lists every breed of the register once, by species and register order', () => {
  const table = renderTable(registry, {});
  const rows = table.split('\n').filter((l) => l.startsWith('| ') && l.includes('`'));
  assert.equal(rows.length, registry.breeds.length);
  for (const b of registry.breeds) assert.equal(rows.filter((r) => r.includes(`\`${b.id}\``)).length, 1, b.id);
  const firstCat = rows.findIndex((r) => r.includes('| mačka |'));
  assert.ok(firstCat === -1 || rows.slice(firstCat).every((r) => r.includes('| mačka |')), 'dogs first, then cats');
});

test('links a breed file and shows its status line; a file outside the register fails', () => {
  const slug = registry.breeds[1].slug.en;
  const table = renderTable(registry, { [`${slug}.md`]: '# X\n\n**Stanje:** 🛠 zgrajena | test\n' });
  assert.match(table, new RegExp(`\\[${slug}\\.md\\]\\(${slug}\\.md\\) \\| 🛠 zgrajena \\\\\\| test \\|`));
  assert.throws(() => renderTable(registry, { 'not-a-breed.md': '' }), /not an English slug/);
  assert.equal(statusOf('no status'), null);
});

test('replaces only the generated part of README.md', () => {
  const out = renderIndex(`intro\n${START}\nold\n${END}\noutro\n`, registry, {});
  assert.ok(out.startsWith('intro\n'));
  assert.ok(out.endsWith(`${END}\noutro\n`));
  assert.doesNotMatch(out, /\nold\n/);
  assert.throws(() => renderIndex('no markers', registry, {}), /markers missing/);
});

test('every breed file is named by an English register slug (templates start with _)', () => {
  const slugs = new Set(registry.breeds.map((b) => `${b.slug.en}.md`));
  for (const f of breedFiles()) assert.ok(slugs.has(f), f);
});
