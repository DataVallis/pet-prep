// Tests for scripts/export-breed-registry.mjs (M5-R11).
// Run: node --test scripts/tests/export-breed-registry.test.mjs
// PHP cross-checks run when `php` is on PATH (and, for the seeded game numbers,
// when backend/vendor/autoload.php exists); otherwise they are skipped.
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { test } from 'node:test';
import {
  DEFAULT_OUT,
  INPUTS,
  ROOT,
  buildRegistry,
  parsePhpReturn,
  parseSourcesTable,
  readInputs,
  renderRegistry,
} from '../export-breed-registry.mjs';

const hasPhp = spawnSync('php', ['-v']).status === 0;
const hasVendor = existsSync(resolve(ROOT, 'backend/vendor/autoload.php'));
const registry = JSON.parse(renderRegistry());

/** Every object below `node` that cites sources (facts, health, tags, game values). */
function cited(node, path = '$', out = []) {
  if (Array.isArray(node)) node.forEach((x, i) => cited(x, `${path}[${i}]`, out));
  else if (node && typeof node === 'object') {
    if ('source_ids' in node || 'basis_source_ids' in node) out.push({ path, node });
    for (const [k, v] of Object.entries(node)) if (k !== 'source_ids' && k !== 'basis_source_ids') cited(v, `${path}.${k}`, out);
  }
  return out;
}

test('export is deterministic and the committed file is up to date', () => {
  const a = renderRegistry();
  const b = renderRegistry();
  assert.equal(a, b);
  assert.doesNotMatch(a, /generated_at|"timestamp"/);
  const committed = readFileSync(resolve(ROOT, DEFAULT_OUT), 'utf8');
  assert.equal(committed, a, `${DEFAULT_OUT} is stale — run: node scripts/export-breed-registry.mjs`);
});

test('every fact, health item and tag has a known source with a URL', () => {
  const sources = new Map(registry.sources.map((s) => [s.id, s]));
  for (const s of registry.sources) {
    assert.match(s.url, /^https?:\/\//, `${s.id} url`);
    assert.ok(s.publisher && s.title, `${s.id} publisher/title`);
  }
  const data = JSON.parse(readFileSync(resolve(ROOT, INPUTS.data), 'utf8'));
  const at = (p) => p.split('.').reduce((n, k) => n[k], data);
  for (const breed of registry.breeds) {
    const facts = cited({ facts: breed.facts, health: breed.health, suitability: breed.suitability });
    assert.ok(facts.length > 10, `${breed.id} has facts`);
    for (const { path, node } of facts) {
      assert.ok(Array.isArray(node.source_ids) && node.source_ids.length > 0, `${breed.id} ${path} has source_ids`);
      for (const id of node.source_ids) assert.ok(sources.has(id), `${breed.id} ${path} cites ${id}, which is not in sources[]`);
      if (node.ref) {
        // A fact's ref points at a sourced data.json entry (never UNSOURCED).
        const entry = at(node.ref);
        assert.equal(typeof entry.source_id, 'string', `${node.ref} is sourced`);
        assert.doesNotMatch(String(entry.notes ?? ''), /^UNSOURCED/, `${node.ref} is not an UNSOURCED proposal`);
      }
    }
    // The page's source list covers everything it cites.
    for (const { node } of cited(breed)) {
      for (const id of [...(node.source_ids ?? []), ...(node.basis_source_ids ?? [])]) {
        assert.ok(breed.source_ids.includes(id), `${breed.id} source_ids misses ${id}`);
      }
    }
  }
  for (const { path, node } of cited(registry.general)) {
    assert.ok(node.source_ids.length > 0, `general ${path} has sources`);
  }
});

test('no quotes, no hypoallergenic, no statistics in health items', () => {
  const json = JSON.stringify(registry);
  assert.doesNotMatch(json, /"quote"/);
  assert.doesNotMatch(json.toLowerCase(), /hypoallergen/);
  for (const breed of registry.breeds) {
    for (const h of breed.health) {
      assert.deepEqual(Object.keys(h).sort(), ['confidence', 'key', 'ref', 'source_ids']);
    }
  }
});

test('game values carry a decision or a source; step goals = minutes × steps per minute', () => {
  const spm = registry.game_general.steps_per_exercise_minute.value;
  assert.equal(spm, 100);
  const expected = {
    border_collie: { adult: 12000, senior: 9000, seniorFrom: 118, learning: 2 },
    labrador_retriever: { adult: 9000, senior: 6800, seniorFrom: 118, learning: 1.8 },
    golden_retriever: { adult: 12000, senior: 9000, seniorFrom: 119, learning: 1.9 },
  };
  for (const breed of registry.breeds) {
    const g = breed.game;
    for (const v of [g.adult_exercise_minutes, g.senior_exercise_minutes, g.senior_from_months, g.learning_multiplier]) {
      assert.ok(v.decision || v.basis_source_ids.length > 0, `${breed.id} ${v.ref}`);
    }
    assert.equal(g.adult_step_goal, g.adult_exercise_minutes.value * spm);
    assert.equal(g.senior_step_goal, g.senior_exercise_minutes.value * spm);
    assert.equal(g.adult_step_goal, expected[breed.id].adult);
    assert.equal(g.senior_step_goal, expected[breed.id].senior);
    assert.equal(g.senior_from_months.value, expected[breed.id].seniorFrom);
    assert.equal(g.learning_multiplier.value, expected[breed.id].learning);
    assert.equal(g.growing_step_goal_by_age_months.at(-1).steps, g.adult_step_goal);
  }
  assert.equal(registry.mixed_breed.game.adult_step_goal, 6000);
  assert.equal(registry.mixed_breed.game.learning_multiplier.value, 1);
});

test('every suitability tag has the app wording in EN and SL', () => {
  for (const locale of ['en', 'sl']) {
    for (const tag of Object.keys(registry.suitability_vocabulary)) {
      assert.ok(registry.suitability_labels[locale].tags[tag], `${locale} ${tag}`);
    }
  }
  assert.equal(registry.suitability_labels.sl.suits_title, 'Primerno za:');
  assert.equal(registry.suitability_labels.sl.consider_title, 'Upoštevajte:');
});

test('PHP literal parser: comments, nesting, lists and refusal of non-literals', () => {
  const v = parsePhpReturn(`<?php
    /* block */ return [
      'a' => 'x', // line
      # hash comment
      'b' => [1, 2.5, true, null, "q\\"t"],
      'c' => ['k' => 'it\\'s'],
    ];`);
  assert.deepEqual(v, { a: 'x', b: [1, 2.5, true, null, 'q"t'], c: { k: "it's" } });
  assert.throws(() => parsePhpReturn("<?php return ['a' => env('X')];"), /unsupported token/);
  assert.throws(() => parsePhpReturn("<?php return ['a' => 'x' . 'y'];"), /expected/);
  assert.throws(() => parsePhpReturn('<?php return ["a" => "$x"];'), /interpolation/);
  assert.throws(() => parsePhpReturn("<?php return ['a' => 1, 2];"), /mixed/);
});

test('sources table parser reads every row of sources.md', () => {
  const md = readFileSync(resolve(ROOT, INPUTS.sources), 'utf8');
  const parsed = parseSourcesTable(md);
  const ids = [...md.matchAll(/^\| (S\d+) \|/gm)].map((m) => m[1]);
  assert.deepEqual(Object.keys(parsed), ids);
});

test('an UNSOURCED value can never become a fact', () => {
  const texts = readInputs();
  const data = JSON.parse(texts.data);
  data.labrador_retriever.size_class.source_id = null;
  assert.throws(() => buildRegistry({ ...texts, data: JSON.stringify(data) }), /UNSOURCED/);
});

test('JS parse of breed_suitability.php equals PHP itself', { skip: !hasPhp && 'php not installed' }, () => {
  const file = resolve(ROOT, INPUTS.suitability);
  const viaPhp = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require $argv[1]);', file], { encoding: 'utf8' }));
  assert.deepEqual(parsePhpReturn(readFileSync(file, 'utf8')), viaPhp);
});

test('game numbers equal the seeded breed_stage_params rows', { skip: !(hasPhp && hasVendor) && 'php or backend/vendor missing' }, () => {
  const code = `require $argv[1]; echo json_encode(Database\\Seeders\\BreedStageParamsSeeder::rows());`;
  const rows = JSON.parse(
    execFileSync('php', ['-r', code, resolve(ROOT, 'backend/vendor/autoload.php')], { encoding: 'utf8', cwd: resolve(ROOT, 'backend') }),
  );
  const slug = { border_collie: 'border-collie', labrador_retriever: 'labrador-retriever', golden_retriever: 'golden-retriever', medium_mixed_breed: 'mutt' };
  const row = (breed, stage, key, from = 0) => {
    const r = rows.find((x) => x.breed_slug === slug[breed] && x.stage === stage && x.key === key && x.age_from_months === from);
    assert.ok(r, `seeded row ${breed} ${stage} ${key} ${from}`);
    return r.value;
  };
  const gg = registry.game_general;
  for (const g of [...registry.breeds, registry.mixed_breed]) {
    const id = g.id;
    assert.equal(row(id, 'adult', 'exercise_minutes_per_day'), g.game.adult_exercise_minutes.value, `${id} adult minutes`);
    assert.equal(row(id, 'senior', 'exercise_minutes_per_day'), g.game.senior_exercise_minutes.value, `${id} senior minutes`);
    assert.equal(row(id, 'senior', 'starts_at_months'), g.game.senior_from_months.value, `${id} senior from`);
    assert.equal(row(id, 'all', 'training_learning_multiplier'), g.game.learning_multiplier.value, `${id} learning`);
    assert.equal(row(id, 'young', 'starts_at_months'), gg.young_from_months.value);
    assert.equal(row(id, 'adult', 'starts_at_months'), gg.adult_from_months.value);
    assert.equal(row(id, 'all', 'steps_per_exercise_minute'), gg.steps_per_exercise_minute.value);
    assert.equal(row(id, 'puppy', 'exercise_minutes_per_age_month'), gg.puppy_exercise_minutes_per_age_month.value);
    assert.equal(row(id, 'puppy', 'arrival_age_months'), gg.puppy_arrival_age_months.value);
    const meals = gg.meals_per_day.value;
    assert.equal(row(id, 'puppy', 'meals_per_day', 0), meals[0].meals);
    assert.equal(row(id, 'puppy', 'meals_per_day', 3), meals[1].meals);
    assert.equal(row(id, 'puppy', 'meals_per_day', 6), meals[2].meals);
    assert.equal(row(id, 'young', 'meals_per_day'), meals[3].meals);
    assert.equal(row(id, 'adult', 'meals_per_day'), meals[4].meals);
    assert.equal(row(id, 'senior', 'meals_per_day'), meals[5].meals);
  }
});
