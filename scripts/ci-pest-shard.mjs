#!/usr/bin/env node
// Pest shard plan for CI (D28b) — splits the backend test files into N balanced shards.
//
// Pest 3.8 has no --shard, so each CI matrix job runs `php artisan test --parallel
// --configuration=phpunit.shard.xml` with its own file list. Balance comes from the
// measured per-file seconds in backend/tests/durations.json (longest-processing-time
// greedy, deterministic); a file missing there (a new test) weighs the median.
// Every test file lands in exactly one shard (the harness checks the union).
//
// Usage: node scripts/ci-pest-shard.mjs <shard> <total> [--backend DIR] [--list]
//   writes DIR/phpunit.shard.xml (same <php>/<source> as phpunit.xml, <testsuites> replaced)
//   --list: print the shard's files (one per line) instead of writing the config.
import { readFileSync, writeFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, relative } from 'node:path';

const args = process.argv.slice(2);
const shard = Number(args[0]);
const total = Number(args[1]);
const bi = args.indexOf('--backend');
const backend = bi >= 0 ? args[bi + 1] : join(import.meta.dirname, '..', 'backend');
const listOnly = args.includes('--list');

if (!Number.isInteger(shard) || !Number.isInteger(total) || total < 1 || shard < 1 || shard > total) {
  console.error('usage: ci-pest-shard.mjs <shard 1..N> <N> [--backend DIR] [--list]');
  process.exit(2);
}

function walk(dir) {
  const out = [];
  for (const name of readdirSync(dir).sort()) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) out.push(...walk(p));
    else if (name.endsWith('Test.php')) out.push(relative(backend, p).split('\\').join('/'));
  }
  return out;
}

export function plan(files, durations, n) {
  const known = files.map((f) => durations[f]).filter((v) => typeof v === 'number' && v > 0).sort((a, b) => a - b);
  const median = known.length ? known[Math.floor(known.length / 2)] : 1;
  const weighted = files
    .map((f) => ({ f, w: typeof durations[f] === 'number' && durations[f] > 0 ? durations[f] : median }))
    .sort((a, b) => b.w - a.w || a.f.localeCompare(b.f));
  const bins = Array.from({ length: n }, () => ({ w: 0, files: [] }));
  for (const item of weighted) {
    let best = 0;
    for (let i = 1; i < n; i++) if (bins[i].w < bins[best].w) best = i;
    bins[best].files.push(item.f);
    bins[best].w += item.w;
  }
  for (const b of bins) b.files.sort();
  return bins;
}

const files = ['tests/Unit', 'tests/Feature']
  .filter((d) => existsSync(join(backend, d)))
  .flatMap((d) => walk(join(backend, d)));
const durFile = join(backend, 'tests', 'durations.json');
const durations = existsSync(durFile) ? JSON.parse(readFileSync(durFile, 'utf8')).files ?? {} : {};
const bins = plan(files, durations, total);
const mine = bins[shard - 1];

if (listOnly) {
  process.stdout.write(mine.files.map((f) => `${f}\n`).join(''));
  process.exit(0);
}

const xml = readFileSync(join(backend, 'phpunit.xml'), 'utf8');
const suite = (name, prefix) => {
  const fs = mine.files.filter((f) => f.startsWith(prefix));
  return fs.length
    ? `        <testsuite name="${name}">\n${fs.map((f) => `            <file>${f}</file>`).join('\n')}\n        </testsuite>\n`
    : '';
};
const suites = `<testsuites>\n${suite('Unit', 'tests/Unit/')}${suite('Feature', 'tests/Feature/')}    </testsuites>`;
if (!/<testsuites>[\s\S]*?<\/testsuites>/.test(xml)) {
  console.error('phpunit.xml has no <testsuites> block');
  process.exit(1);
}
writeFileSync(join(backend, 'phpunit.shard.xml'), xml.replace(/<testsuites>[\s\S]*?<\/testsuites>/, suites));
console.log(`shard ${shard}/${total}: ${mine.files.length} of ${files.length} files, ~${Math.round(mine.w)} s measured weight`);
