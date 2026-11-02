// node --test scripts/tests/ci-pest-shard.test.mjs — the Pest shard planner (D28b).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFileSync, spawnSync } from 'node:child_process';

const SCRIPT = join(import.meta.dirname, '..', 'ci-pest-shard.mjs');
const REAL_BACKEND = join(import.meta.dirname, '..', '..', 'backend');

function fixture(durations) {
  const dir = mkdtempSync(join(tmpdir(), 'shard-'));
  mkdirSync(join(dir, 'tests', 'Feature', 'Sub'), { recursive: true });
  mkdirSync(join(dir, 'tests', 'Unit'), { recursive: true });
  for (const f of ['tests/Feature/ATest.php', 'tests/Feature/BTest.php', 'tests/Feature/Sub/CTest.php',
    'tests/Feature/DTest.php', 'tests/Unit/UTest.php', 'tests/Feature/helpers.php']) writeFileSync(join(dir, f), '<?php');
  writeFileSync(join(dir, 'phpunit.xml'), '<phpunit>\n    <testsuites>\n        <testsuite name="Feature"><directory>tests/Feature</directory></testsuite>\n    </testsuites>\n    <php><env name="X" value="1"/></php>\n</phpunit>\n');
  if (durations) writeFileSync(join(dir, 'tests', 'durations.json'), JSON.stringify({ files: durations }));
  return dir;
}
const list = (dir, i, n) => execFileSync('node', [SCRIPT, String(i), String(n), '--backend', dir, '--list'], { encoding: 'utf8' }).split('\n').filter(Boolean);

test('every test file is in exactly one shard, helpers excluded', () => {
  const dir = fixture({ 'tests/Feature/ATest.php': 100, 'tests/Feature/BTest.php': 50 });
  for (const n of [1, 2, 3, 5]) {
    const all = Array.from({ length: n }, (_, i) => list(dir, i + 1, n)).flat();
    assert.deepEqual([...all].sort(), ['tests/Feature/ATest.php', 'tests/Feature/BTest.php', 'tests/Feature/DTest.php',
      'tests/Feature/Sub/CTest.php', 'tests/Unit/UTest.php'], `n=${n}`);
  }
});

test('balances by measured weight (heaviest file alone)', () => {
  const dir = fixture({ 'tests/Feature/ATest.php': 100, 'tests/Feature/BTest.php': 10, 'tests/Feature/DTest.php': 10,
    'tests/Feature/Sub/CTest.php': 10, 'tests/Unit/UTest.php': 10 });
  assert.deepEqual(list(dir, 1, 2), ['tests/Feature/ATest.php']);
  assert.equal(list(dir, 2, 2).length, 4);
});

test('deterministic and works without durations.json', () => {
  const dir = fixture(null);
  assert.deepEqual(list(dir, 1, 3), list(dir, 1, 3));
  assert.equal([1, 2, 3].map((i) => list(dir, i, 3).length).reduce((a, b) => a + b), 5);
});

test('writes phpunit.shard.xml with only the shard files, keeps the rest of phpunit.xml', () => {
  const dir = fixture(null);
  execFileSync('node', [SCRIPT, '1', '2', '--backend', dir]);
  const xml = readFileSync(join(dir, 'phpunit.shard.xml'), 'utf8');
  const files = [...xml.matchAll(/<file>([^<]+)<\/file>/g)].map((m) => m[1]);
  assert.deepEqual(files.sort(), list(dir, 1, 2).sort());
  assert.match(xml, /<env name="X" value="1"\/>/);
  assert.doesNotMatch(xml, /<directory>tests\/Feature<\/directory>/);
});

test('bad arguments → exit 2', () => {
  for (const a of [['0', '3'], ['4', '3'], ['x', '3'], []]) {
    assert.equal(spawnSync('node', [SCRIPT, ...a, '--backend', fixture(null), '--list']).status, 2, a.join(' '));
  }
});

test('--update-durations sums junit testcases per file', () => {
  const dir = fixture(null);
  const junit = join(dir, 'j.xml');
  writeFileSync(junit, '<testsuites><testcase name="a" file="/x/backend/tests/Feature/ATest.php::a" time="1.25"/>'
    + '<testcase name="b" file="/x/backend/tests/Feature/ATest.php::b" time="0.75"/>'
    + '<testcase name="c" file="/x/backend/tests/Unit/UTest.php::c" time="3"/></testsuites>');
  execFileSync('node', [SCRIPT, '--update-durations', junit, '--backend', dir]);
  assert.deepEqual(JSON.parse(readFileSync(join(dir, 'tests', 'durations.json'), 'utf8')).files,
    { 'tests/Feature/ATest.php': 2, 'tests/Unit/UTest.php': 3 });
});

test('the real backend: durations.json parses and the planner covers every test file', () => {
  const durFile = join(REAL_BACKEND, 'tests', 'durations.json');
  assert.equal(typeof JSON.parse(readFileSync(durFile, 'utf8')).files, 'object');
  const n = 6;
  const all = Array.from({ length: n }, (_, i) => list(REAL_BACKEND, i + 1, n)).flat();
  assert.equal(new Set(all).size, all.length, 'a file in two shards');
});
