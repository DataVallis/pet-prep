// Tests for scripts/export-breed-registry.mjs (M5-R11, schema 2: species → breeds).
// Run: node --test scripts/tests/export-breed-registry.test.mjs
// PHP cross-checks run when `php` is on PATH (and, for the seeded game numbers,
// when backend/vendor/autoload.php exists); otherwise they are skipped.
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { test } from 'node:test';
import {
  DEFAULT_OUT,
  INPUTS,
  PORTRAIT_MANIFEST,
  PORTRAITS_DIR,
  RESERVED_SLUGS,
  ROOT,
  buildRegistry,
  parsePhpReturn,
  parseSourcesTable,
  portraitCopies,
  readInputs,
  readPortraitManifest,
  renderRegistry,
  slugify,
} from '../export-breed-registry.mjs';

const hasPhp = spawnSync('php', ['-v']).status === 0;
const hasVendor = existsSync(resolve(ROOT, 'backend/vendor/autoload.php'));
const registry = JSON.parse(renderRegistry());
const data = {
  '': JSON.parse(readFileSync(resolve(ROOT, INPUTS.dog_data), 'utf8')),
  'cat-data:': JSON.parse(readFileSync(resolve(ROOT, INPUTS.cat_data), 'utf8')),
};
/** A data.json entry by an exported ref ("x.y" dog, "cat-data:x.y" cat; "a + b" = both). */
const entries = (ref) =>
  ref.split(' + ').map((r) => {
    const prefix = r.startsWith('cat-data:') ? 'cat-data:' : '';
    return r.slice(prefix.length).split('.').reduce((n, k) => n[k], data[prefix]);
  });

/** Every object below `node` that cites sources. */
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
  assert.equal(a, renderRegistry());
  assert.doesNotMatch(a, /generated_at|"timestamp"/);
  assert.equal(readFileSync(resolve(ROOT, DEFAULT_OUT), 'utf8'), a, `${DEFAULT_OUT} is stale — run: node scripts/export-breed-registry.mjs`);
});

test('species → breeds model: slugs, availability, groups', () => {
  assert.deepEqual(registry.species.map((s) => s.id), ['dog', 'cat']);
  for (const sp of registry.species) {
    const breeds = registry.breeds.filter((b) => b.species === sp.id);
    assert.equal(sp.breed_count, breeds.length);
    for (const l of ['en', 'sl']) {
      assert.match(sp.slug[l], /^[a-z0-9-]+$/);
      const slugs = breeds.map((b) => b.slug[l]);
      assert.equal(new Set(slugs).size, slugs.length, `${sp.id} ${l} slugs unique`);
      for (const s of slugs) assert.ok(!RESERVED_SLUGS.includes(s));
    }
    for (const b of breeds) {
      assert.ok(['in_app', 'coming_soon', 'info_only'].includes(b.availability));
      for (const f of b.facts) assert.ok(sp.fact_groups.includes(f.group), `${b.id} ${f.field} group`);
      for (const k of Object.keys(b.facets)) assert.ok(sp.facets.includes(k), `${b.id} facet ${k}`);
    }
  }
  const byId = Object.fromEntries(registry.breeds.map((b) => [b.id, b]));
  assert.equal(byId.border_collie.availability, 'in_app');
  assert.equal(byId.labrador_retriever.slug.sl, 'labradorec');
  assert.equal(byId.golden_retriever.slug.sl, 'zlati-prinasalec');
  assert.deepEqual(byId.french_bulldog.slug, { en: 'french-bulldog', sl: 'francoski-buldog' });
  assert.equal(byId.french_bulldog.availability, 'coming_soon');
  assert.deepEqual(byId.german_shepherd.slug, { en: 'german-shepherd-dog', sl: 'nemski-ovcar' }); // EN = RKC name
  assert.equal(byId.german_shepherd.availability, 'coming_soon');
  assert.deepEqual(byId.cavalier_king_charles_spaniel.slug, { en: 'cavalier-king-charles-spaniel', sl: 'kavalir-king-charles-spanjel' });
  assert.equal(byId.cavalier_king_charles_spaniel.availability, 'coming_soon');
  assert.deepEqual(byId.beagle.slug, { en: 'beagle', sl: 'bigl' });
  assert.equal(byId.beagle.availability, 'coming_soon');
  assert.deepEqual(byId.standard_poodle.slug, { en: 'poodle-standard', sl: 'veliki-koder' });
  assert.equal(byId.standard_poodle.availability, 'coming_soon');
  assert.deepEqual(byId.dachshund.slug, { en: 'dachshund', sl: 'jazbecar' });
  assert.equal(byId.dachshund.availability, 'coming_soon');
  assert.deepEqual(byId.australian_shepherd.slug, { en: 'australian-shepherd', sl: 'avstralski-ovcar' });
  assert.equal(byId.australian_shepherd.availability, 'coming_soon');
  assert.deepEqual(byId.havanese.slug, { en: 'havanese', sl: 'havanski-bison' });
  assert.equal(byId.havanese.availability, 'coming_soon');
  assert.deepEqual(byId.west_highland_white_terrier.slug, { en: 'west-highland-white-terrier', sl: 'zahodnoskotski-beli-terier' });
  assert.equal(byId.west_highland_white_terrier.availability, 'coming_soon');
  assert.deepEqual(byId.bernese_mountain_dog.slug, { en: 'bernese-mountain-dog', sl: 'bernski-plansarski-pes' });
  assert.equal(byId.bernese_mountain_dog.availability, 'coming_soon');
  assert.equal(byId.maine_coon.availability, 'coming_soon'); // cats are hidden in the app
  assert.equal(slugify('Zlati prinašalec'), 'zlati-prinasalec');
});

test('every fact, health item and tag has a known source; facts come from sourced entries', () => {
  const sources = new Map(registry.sources.map((s) => [s.id, s]));
  for (const s of registry.sources) {
    assert.match(s.url, /^https?:\/\//, `${s.id} url`);
    assert.ok(s.publisher && s.title, `${s.id} publisher/title`);
  }
  const factLists = [
    ...registry.breeds.map((b) => ({ id: b.id, items: [...b.facts, ...b.health], tags: [...b.suitability.suits, ...b.suitability.consider], all: b })),
    ...registry.species.map((s) => ({ id: s.id, items: s.general_facts, tags: [], all: null })),
  ];
  for (const { id, items, tags } of factLists) {
    for (const item of [...items, ...tags]) {
      assert.ok(item.source_ids.length > 0, `${id} ${item.field ?? item.key ?? item.tag} has sources`);
      for (const s of item.source_ids) assert.ok(sources.has(s), `${id} cites ${s}, not in sources[]`);
    }
    for (const item of items) {
      for (const e of entries(item.ref)) {
        assert.equal(typeof e.source_id, 'string', `${item.ref} is sourced`);
        assert.doesNotMatch(String(e.notes ?? ''), /^UNSOURCED/, `${item.ref} is not an UNSOURCED proposal`);
      }
    }
  }
  for (const b of registry.breeds) {
    for (const { node } of cited(b)) {
      for (const s of [...(node.source_ids ?? []), ...(node.basis_source_ids ?? [])]) assert.ok(b.source_ids.includes(s), `${b.id} source_ids misses ${s}`);
    }
    for (const s of cited(registry.species.find((x) => x.id === b.species).general_facts).flatMap(({ node }) => node.source_ids)) {
      assert.ok(b.source_ids.includes(s), `${b.id} source_ids misses species fact source ${s}`);
    }
  }
});

test('no quotes, no hypoallergenic, no statistics in health items', () => {
  const json = JSON.stringify(registry);
  assert.doesNotMatch(json, /"quote"/);
  assert.doesNotMatch(json.toLowerCase(), /hypoallergen/);
  for (const b of registry.breeds) for (const h of b.health) assert.deepEqual(Object.keys(h).sort(), ['confidence', 'key', 'ref', 'source_ids']);
});

test('game values carry a decision or a source; dog step goals = minutes × 100', () => {
  const expected = {
    border_collie: { adult: 12000, senior: 9000, seniorFrom: 118, learning: 2 },
    labrador_retriever: { adult: 9000, senior: 6800, seniorFrom: 118, learning: 1.8 },
    golden_retriever: { adult: 12000, senior: 9000, seniorFrom: 119, learning: 1.9 },
    french_bulldog: { adult: 6000, senior: 4500, seniorFrom: 88, learning: 0.7 },
    german_shepherd: { adult: 12000, senior: 9000, seniorFrom: 93, learning: 1.9 },
    cavalier_king_charles_spaniel: { adult: 6000, senior: 4500, seniorFrom: 90, learning: 1 },
    beagle: { adult: 6000, senior: 4500, seniorFrom: 102, learning: 0.5 },
    standard_poodle: { adult: 6000, senior: 4500, seniorFrom: 108, learning: 2 },
    dachshund: { adult: 6000, senior: 4500, seniorFrom: 108, learning: 1 },
    australian_shepherd: { adult: 12000, senior: 9000, seniorFrom: 90, learning: 1 },
    havanese: { adult: 3000, senior: 2300, seniorFrom: 108, learning: 1 },
    west_highland_white_terrier: { adult: 6000, senior: 4500, seniorFrom: 121, learning: 1 },
    bernese_mountain_dog: { adult: 6000, senior: 4500, seniorFrom: 76, learning: 1.5 },
  };
  for (const b of registry.breeds) {
    const g = b.game;
    assert.ok(g, `${b.id} has game rules`);
    for (const v of g.values) assert.ok(v.decision || v.basis_source_ids.length > 0, `${b.id} ${v.ref}`);
    assert.deepEqual(g.stages.map((s) => s.stage), ['puppy', 'young', 'adult', 'senior']);
    const e = expected[b.id];
    if (!e) continue;
    assert.deepEqual(g.adult_activity, { kind: 'steps', value: e.adult });
    assert.equal(g.stages[3].activity.value, e.senior);
    assert.equal(g.stages[3].starts.month, e.seniorFrom);
    assert.equal(g.rules.find((r) => r.key === 'learning').params.multiplier, e.learning);
    assert.equal(g.stages[0].activity.cap, e.adult);
  }
  const mc = registry.breeds.find((b) => b.id === 'maine_coon').game;
  assert.deepEqual(mc.adult_activity, { kind: 'play_sessions', value: 2 });
  assert.equal(mc.stages[0].starts.arrival_months, 3);
  assert.equal(mc.rules.find((r) => r.key === 'grooming_rule').params.per_week, 3);
  assert.deepEqual(registry.species[0].free_plan.adult_activity, { kind: 'steps', value: 6000 });
});

test('French Bulldog (M5-R10-03): "up to 1 hour", origin France, puppy cap at 6 months, no statistics', () => {
  const fb = registry.breeds.find((b) => b.id === 'french_bulldog');
  const ex = fb.facts.find((f) => f.field === 'exercise');
  assert.deepEqual([ex.value, ex.qualifier, ex.source_ids], [60, 'up_to', ['S78']]);
  assert.equal(fb.facets.exercise, 'under_1h'); // "up to 1 hour" is not "1–2 hours"
  assert.equal(fb.facets.size, 'small');
  assert.deepEqual(fb.facts.find((f) => f.field === 'fci_standard').value, { number: 101, group: 9, section: 11, origin: 'FR' });
  assert.deepEqual(fb.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(fb.game.stages[1].activity, { kind: 'steps', value: 6000 });
  assert.deepEqual(fb.suitability.consider.map((t) => t.tag), ['brachycephalic_breathing']);
  assert.deepEqual(fb.health.map((h) => h.key), ['flat_face_breathing', 'heat_stroke_risk', 'skin_fold_ear_problems', 'merle_colour_risk']);
  // The median age at death of a very young 2013 population is not a lifespan; no odds ratio or % anywhere.
  const json = JSON.stringify(fb);
  assert.doesNotMatch(json, /vetcompass_2013_deaths|rfg_grades|boas_diagnosed_prevalence|30\.89|42\.14/);
  assert.deepEqual(fb.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value]), [['median', 9.8], ['more_than', 10]]);
});

test('German Shepherd (M5-R10-04): more than 2 hours, origin Germany, puppy cap at 12 months, no statistics', () => {
  const gs = registry.breeds.find((b) => b.id === 'german_shepherd');
  const ex = gs.facts.find((f) => f.field === 'exercise');
  assert.deepEqual([ex.value, ex.qualifier, ex.source_ids], [120, 'more_than', ['S97']]);
  assert.equal(gs.facets.exercise, 'over_2h');
  assert.equal(gs.facets.size, 'large');
  assert.deepEqual(gs.facts.find((f) => f.field === 'fci_standard').value, { number: 166, group: 1, section: 1, origin: 'DE' });
  assert.deepEqual(gs.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(gs.game.stages[2].activity, { kind: 'steps', value: 12000 });
  assert.ok(gs.suitability.consider.map((t) => t.tag).includes('hips_hind_legs'));
  assert.deepEqual(gs.health.map((h) => h.key), ['hind_leg_conformation', 'hip_elbow_dysplasia', 'degenerative_myelopathy']);
  const json = JSON.stringify(gs);
  assert.doesNotMatch(json, /causes_of_death|common_disorders|16\.3|14\.9|5\.18|4\.76/);
  assert.deepEqual(gs.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value]), [['median', 10.3], ['more_than', 10]]);
});

test('Cavalier King Charles Spaniel (M5-R10-05): up to 1 hour, origin Great Britain, heart / spine chip, no statistics', () => {
  const ck = registry.breeds.find((b) => b.id === 'cavalier_king_charles_spaniel');
  const ex = ck.facts.find((f) => f.field === 'exercise');
  assert.deepEqual([ex.value, ex.qualifier, ex.source_ids], [60, 'up_to', ['S105']]);
  assert.equal(ck.facets.exercise, 'under_1h'); // "up to 1 hour" — same facet as the French Bulldog
  assert.equal(ck.facets.size, 'small');
  assert.deepEqual(ck.facts.find((f) => f.field === 'fci_standard').value, { number: 136, group: 9, section: 7, origin: 'GB' });
  assert.deepEqual(ck.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(ck.game.stages[2].activity, { kind: 'steps', value: 6000 });
  assert.ok(ck.suitability.consider.map((t) => t.tag).includes('heart_and_spine'));
  assert.deepEqual(ck.suitability.suits.map((t) => t.tag), ['apartment', 'family_pet', 'children']);
  assert.deepEqual(ck.health.map((h) => h.key), ['heart_valve_disease', 'chiari_syringomyelia', 'eye_conditions']);
  const json = JSON.stringify(ck);
  assert.doesNotMatch(json, /common_disorders|uk_measured_median|30\.9|10\.5/);
  assert.deepEqual(ck.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value]), [['median', 9.99], ['more_than', 12]]);
});

test('Beagle (M5-R10-06): up to 1 hour (RKC), PDSA weight, FCI group 6, no statistics or measured weight', () => {
  const bg = registry.breeds.find((b) => b.id === 'beagle');
  const ex = bg.facts.filter((f) => f.field === 'exercise');
  assert.deepEqual(ex.map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S113']]]); // the PDSA 90 min stays research only
  assert.equal(bg.facets.exercise, 'under_1h');
  assert.equal(bg.facets.size, 'small');
  assert.deepEqual(bg.facts.find((f) => f.field === 'fci_standard').value, { number: 161, group: 6, section: 1, origin: 'GB' });
  assert.deepEqual(bg.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[[9, 11], ['S115']]]);
  assert.deepEqual(bg.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(bg.game.stages[3].activity, { kind: 'steps', value: 4500 });
  assert.deepEqual(bg.suitability.suits.map((t) => t.tag), ['family_pet']);
  assert.deepEqual(bg.suitability.consider.map((t) => t.tag), ['sheds', 'chews_when_bored']);
  assert.deepEqual(bg.health.map((h) => h.key), ['weight_gain', 'epilepsy', 'back_disc_disease']);
  const json = JSON.stringify(bg);
  assert.doesNotMatch(json, /common_disorders|uk_measured_median|24\.27|17\.78|18\.19|19\.70/);
  assert.deepEqual(bg.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value]), [['median', 11.28], ['more_than', 12]]);
});

test('Standard Poodle (M5-R10-07): up to 1 hour, PDSA weight by sex, FCI group 9 France, no pooled lifespan, never hypoallergenic', () => {
  const sp = registry.breeds.find((b) => b.id === 'standard_poodle');
  const ex = sp.facts.filter((f) => f.field === 'exercise');
  assert.deepEqual(ex.map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S120']]]);
  assert.deepEqual(sp.facets, { size: 'medium', exercise: 'under_1h', grooming: 'daily' }); // RKC "Every day" → daily
  assert.deepEqual(sp.facts.find((f) => f.field === 'fci_standard').value, { number: 172, group: 9, section: 2, origin: 'FR' });
  assert.deepEqual(sp.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [30, 35], female: [21, 32] }, ['S122']]]);
  assert.deepEqual(sp.facts.filter((f) => f.field === 'shedding').map((f) => f.value), ['no']);
  assert.deepEqual(sp.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(sp.game.stages[3].activity, { kind: 'steps', value: 4500 });
  assert.deepEqual(sp.suitability.suits.map((t) => t.tag), ['children', 'large_home', 'other_pets', 'low_shedding']);
  assert.deepEqual(sp.suitability.consider.map((t) => t.tag), ['frequent_grooming']);
  assert.deepEqual(sp.health.map((h) => h.key), ['pra_eye_disease', 'hip_dysplasia', 'bloat_gdv', 'epilepsy']);
  // Only the RKC lower bound — the pooled "Poodle" 14.0 y (all varieties, S123) is research only.
  assert.deepEqual(sp.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value]), [['more_than', 12]]);
  const json = JSON.stringify(sp);
  assert.doesNotMatch(json, /mcmillan_poodle_pooled|hypoallergenic/i);
});

test('every suitability tag has the app wording in EN and SL', () => {
  for (const locale of ['en', 'sl']) {
    for (const tag of Object.keys(registry.suitability_vocabulary)) assert.ok(registry.suitability_labels[locale].tags[tag], `${locale} ${tag}`);
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

test('sources table parser reads every row of both sources.md files', () => {
  for (const key of ['dog_sources', 'cat_sources']) {
    const md = readFileSync(resolve(ROOT, INPUTS[key]), 'utf8');
    const ids = [...md.matchAll(/^\| ([A-Z]\d+) \|/gm)].map((m) => m[1]);
    assert.deepEqual(Object.keys(parseSourcesTable(md)), ids);
  }
});

test('an UNSOURCED value can never become a fact', () => {
  const texts = readInputs();
  const dog = JSON.parse(texts.dog_data);
  dog.labrador_retriever.size_class.source_id = null;
  assert.throws(() => buildRegistry({ ...texts, dog_data: JSON.stringify(dog) }), /UNSOURCED/);
  const cat = JSON.parse(texts.cat_data);
  cat.maine_coon.lifespan.expectancy_at_birth.source_id = null;
  assert.throws(() => buildRegistry({ ...texts, cat_data: JSON.stringify(cat) }), /UNSOURCED/);
});

test('JS parse of breed_suitability.php equals PHP itself', { skip: !hasPhp && 'php not installed' }, () => {
  const file = resolve(ROOT, INPUTS.suitability);
  const viaPhp = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require $argv[1]);', file], { encoding: 'utf8' }));
  assert.deepEqual(parsePhpReturn(readFileSync(file, 'utf8')), viaPhp);
});

test('game numbers equal the seeded breed_stage_params rows', { skip: !(hasPhp && hasVendor) && 'php or backend/vendor missing' }, () => {
  const code = `require $argv[1]; echo json_encode(Database\\Seeders\\BreedStageParamsSeeder::allRows());`;
  const rows = JSON.parse(execFileSync('php', ['-r', code, resolve(ROOT, 'backend/vendor/autoload.php')], { encoding: 'utf8', cwd: resolve(ROOT, 'backend') }));
  const row = (id, stage, key, from = 0) => {
    // breed_configs slug = the register's EN slug (german_shepherd → german-shepherd-dog, M5-R10-04).
    const slug = registry.breeds.find((b) => b.id === id)?.slug.en ?? id.replaceAll('_', '-');
    const r = rows.find((x) => x.breed_slug === slug && x.stage === stage && x.key === key && x.age_from_months === from);
    assert.ok(r, `seeded row ${slug} ${stage} ${key} ${from}`);
    return r.value;
  };
  for (const b of registry.breeds) {
    const g = b.game;
    const [puppy, young, adult, senior] = g.stages;
    assert.equal(row(b.id, 'puppy', 'arrival_age_months'), puppy.starts.arrival_months, `${b.id} arrival`);
    assert.equal(row(b.id, 'young', 'starts_at_months'), young.starts.month, `${b.id} young`);
    assert.equal(row(b.id, 'adult', 'starts_at_months'), adult.starts.month, `${b.id} adult`);
    assert.equal(row(b.id, 'senior', 'starts_at_months'), senior.starts.month, `${b.id} senior`);
    assert.equal(row(b.id, 'young', 'meals_per_day'), young.meals[0].meals);
    assert.equal(row(b.id, 'adult', 'meals_per_day'), adult.meals[0].meals);
    assert.equal(row(b.id, 'senior', 'meals_per_day'), senior.meals[0].meals);
    for (const m of puppy.meals.slice(1)) assert.equal(row(b.id, 'puppy', 'meals_per_day', m.from_months), m.meals);
    if (b.species === 'dog') {
      assert.equal(row(b.id, 'adult', 'exercise_minutes_per_day') * row(b.id, 'all', 'steps_per_exercise_minute'), g.adult_activity.value, `${b.id} adult steps`);
      assert.equal(row(b.id, 'senior', 'exercise_minutes_per_day') * 100, senior.activity.value, `${b.id} senior steps`);
      assert.equal(row(b.id, 'all', 'training_learning_multiplier'), g.rules.find((r) => r.key === 'learning').params.multiplier);
      assert.equal(row(b.id, 'puppy', 'exercise_minutes_per_age_month') * 100, puppy.activity.per_month);
    } else {
      assert.equal(row(b.id, 'puppy', 'play_sessions_per_day'), puppy.activity.value);
      assert.equal(row(b.id, 'adult', 'play_sessions_per_day'), adult.activity.value);
      const litter = g.rules.find((r) => r.key === 'litter_rule').params;
      assert.equal(row(b.id, 'adult', 'litter_uses_per_day'), litter.uses);
      assert.equal(row(b.id, 'all', 'litter_scoop_deadline_hours'), litter.scoop_hours);
      assert.equal(row(b.id, 'all', 'litter_full_change_days'), litter.change_days);
      assert.equal(row(b.id, 'all', 'play_min_gap_minutes'), g.rules.find((r) => r.key === 'play_instead_of_steps').params.gap_minutes);
      const groom = g.rules.find((r) => r.key === 'grooming_rule');
      if (groom) assert.equal(row(b.id, 'all', 'grooming_sessions_per_week'), groom.params.per_week);
    }
  }
  // Free plans (no page, but their numbers are shown).
  const mutt = rows.find((x) => x.breed_slug === 'mutt' && x.stage === 'adult' && x.key === 'exercise_minutes_per_day');
  assert.equal(mutt.value * 100, registry.species[0].free_plan.adult_activity.value);
  const cat = rows.find((x) => x.breed_slug === 'domestic-cat' && x.stage === 'adult' && x.key === 'play_sessions_per_day');
  assert.equal(cat.value, registry.species[1].free_plan.adult_activity.value);
});

// ─── breed portraits (M5-R11, David 2026-10-10: AI photos, labelled) ───

const manifestOf = (portraits) => JSON.stringify({ schema_version: 1, label: 'AI-generated photo', portraits });
const portraitEntry = (over = {}) => ({
  breed: 'labrador_retriever',
  species: 'dog',
  file: 'dog/labrador-retriever.webp',
  width: 1024,
  height: 1024,
  kind: 'ai_photo',
  profile: 'nano_banana_pro',
  prompt_hash: 'sha256:abc',
  generated_at: '2026-10-10T12:00:00+00:00',
  cost_usd: 0.15,
  ...over,
});

test('portraits: every breed has a portrait key; the committed manifest (if any) points at existing files', () => {
  for (const b of registry.breeds) assert.ok('portrait' in b, `${b.id} has portrait`);
  const manifest = resolve(ROOT, PORTRAIT_MANIFEST);
  assert.equal(registry.inputs.portraits.path, PORTRAIT_MANIFEST);
  if (!existsSync(manifest)) {
    assert.equal(registry.inputs.portraits.sha256, null);
    for (const b of registry.breeds) assert.equal(b.portrait, null);
    return;
  }
  for (const [, p] of readPortraitManifest(readFileSync(manifest, 'utf8'))) {
    assert.ok(existsSync(resolve(ROOT, PORTRAITS_DIR, p.file)), `${p.file} exists`);
  }
});

test('portraits: manifest entries become { file, width, height, kind }, missing ones stay null', () => {
  const texts = readInputs();
  const reg = buildRegistry({ ...texts, portraits: manifestOf([portraitEntry(), portraitEntry({ breed: 'maine_coon', species: 'cat', file: 'cat/maine-coon.webp', width: 1200, height: 1200 })]) });
  const byId = Object.fromEntries(reg.breeds.map((b) => [b.id, b]));
  assert.deepEqual(byId.labrador_retriever.portrait, { file: 'labrador-retriever.webp', width: 1024, height: 1024, kind: 'ai_photo' });
  assert.deepEqual(byId.maine_coon.portrait, { file: 'maine-coon.webp', width: 1200, height: 1200, kind: 'ai_photo' });
  assert.equal(byId.border_collie.portrait, null);
  assert.match(reg.inputs.portraits.sha256, /^[0-9a-f]{64}$/);
  // Deterministic: no generated_at / prompt hash / cost leaks into the export.
  assert.doesNotMatch(JSON.stringify(reg.breeds), /generated_at|prompt_hash|cost_usd/);

  const copies = portraitCopies(reg, '/site/public');
  assert.deepEqual(copies.map((c) => [c.breed, c.from, c.to]), [
    ['labrador_retriever', resolve(ROOT, PORTRAITS_DIR, 'dog/labrador-retriever.webp'), '/site/public/animals/dogs/labrador-retriever.webp'],
    ['maine_coon', resolve(ROOT, PORTRAITS_DIR, 'cat/maine-coon.webp'), '/site/public/animals/cats/maine-coon.webp'],
  ]);
});

test('portraits: an older ai_illustration manifest entry is still read and passed through', () => {
  const texts = readInputs();
  const reg = buildRegistry({ ...texts, portraits: manifestOf([portraitEntry({ kind: 'ai_illustration' })]) });
  const lab = reg.breeds.find((b) => b.id === 'labrador_retriever');
  assert.equal(lab.portrait.kind, 'ai_illustration');
});

test('portraits: a wrong manifest fails loudly', () => {
  const texts = readInputs();
  const build = (entries) => () => buildRegistry({ ...texts, portraits: manifestOf(entries) });
  assert.throws(build([portraitEntry({ species: 'cat' })]), /species cat, expected dog/);
  assert.throws(build([portraitEntry({ file: 'dog/labrador.webp' })]), /file must be dog\/labrador-retriever/);
  assert.throws(build([portraitEntry({ file: 'cat/labrador-retriever.webp' })]), /file must be/);
  assert.throws(build([portraitEntry({ kind: 'photo' })]), /unknown kind photo/);
  assert.throws(build([portraitEntry({ width: 0 })]), /width must be a positive integer/);
  assert.throws(build([portraitEntry({ breed: 'poodle' })]), /poodle is not a breed of the register/);
  assert.throws(build([portraitEntry(), portraitEntry()]), /duplicate/);
  assert.throws(() => buildRegistry({ ...texts, portraits: '{"portraits": []}' }), /schema_version 1/);
});

test('portraits: --copy-portraits writes the registry and copies into <dir>/animals/<species slug>/', () => {
  const dir = mkdtempSync(join(tmpdir(), 'portraits-'));
  try {
    const out = join(dir, 'registry.json');
    const res = spawnSync(process.execPath, [resolve(ROOT, 'scripts/export-breed-registry.mjs'), '--out', out, '--copy-portraits', dir], { encoding: 'utf8' });
    assert.equal(res.status, 0, res.stderr);
    assert.equal(readFileSync(out, 'utf8'), renderRegistry());
    const n = registry.breeds.filter((b) => b.portrait).length;
    assert.match(res.stdout, new RegExp(`${n} portrait\\(s\\) copied`));
    for (const c of portraitCopies(registry, dir)) assert.ok(existsSync(c.to), `${c.to} copied`);
    const missing = spawnSync(process.execPath, [resolve(ROOT, 'scripts/export-breed-registry.mjs'), '--out', out, '--copy-portraits', join(dir, 'nope')], { encoding: 'utf8' });
    assert.equal(missing.status, 1);
    assert.match(missing.stderr, /does not exist/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('Dachshund (M5-R10-08): up to 1 hour, RKC weight, FCI group 4 Germany without a section, back chip, no miniature lifespan', () => {
  const d = registry.breeds.find((b) => b.id === 'dachshund');
  const ex = d.facts.filter((f) => f.field === 'exercise');
  assert.deepEqual(ex.map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S125']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 148, group: 4, section: null, origin: 'DE' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[[9, 12], ['S126']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'shedding').map((f) => f.value), ['yes']);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3].activity, { kind: 'steps', value: 4500 });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['children']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['back_spine', 'sheds', 'needs_mental_stimulation']);
  assert.deepEqual(d.health.map((h) => h.key), ['back_disc_disease']);
  // Only the RKC lower bound — the Miniature Dachshund's 14.0 y (S130) is research only.
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value]), [['more_than', 12]]);
  const json = JSON.stringify(d);
  assert.doesNotMatch(json, /mcmillan_miniature|10-12 times|hypoallergenic/i);
});

test('Australian Shepherd (M5-R10-09): more than 2 hours, PDSA weight, FCI group 1 USA, herding chips, merle-breeding line, no welfare chip', () => {
  const d = registry.breeds.find((b) => b.id === 'australian_shepherd');
  const ex = d.facts.filter((f) => f.field === 'exercise');
  assert.deepEqual(ex.map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S133']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 342, group: 1, section: 1, origin: 'US' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[[18, 29], ['S134']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value]), [['more_than', 10]]);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3].activity, { kind: 'steps', value: 9000 });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family', 'family_pet', 'large_home']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'needs_mental_stimulation', 'may_herd_children', 'chews_when_bored', 'sheds', 'frequent_grooming']);
  assert.deepEqual(d.health.map((h) => h.key), ['hip_elbow_dysplasia', 'inherited_eye_disease', 'drug_sensitivity_mdr1', 'merle_to_merle_breeding']);
  const json = JSON.stringify(d);
  // Merle is standard here: never the "not a standard colour" line; no McMillan placeholder, no statistics.
  assert.doesNotMatch(json, /merle_colour_risk|mcmillan_2024|hypoallergenic|odds ratio/i);
});

test('Havanese (M5-R10-10): up to 30 minutes, PDSA weight, FCI group 9 Cuba, low shedding (never hypoallergenic), no Coren rank, no welfare chip', () => {
  const d = registry.breeds.find((b) => b.id === 'havanese');
  const ex = d.facts.filter((f) => f.field === 'exercise');
  assert.deepEqual(ex.map((f) => [f.value, f.qualifier, f.source_ids]), [[30, 'up_to', ['S138']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 250, group: 9, section: 1, origin: 'CU' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[[3, 6], ['S140']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value]), [['more_than', 12]]);
  // 10 min × age reaches the 30-minute cap at 3 months; senior 23 min (22.5 half up).
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 3000, cap_month: 3 });
  assert.deepEqual(d.game.stages[3].activity, { kind: 'steps', value: 2300 });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['apartment', 'family_pet', 'children', 'low_shedding']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['frequent_grooming']);
  assert.deepEqual(d.health.map((h) => h.key), ['kneecap_luxation', 'pra_and_eyelashes', 'liver_shunt']);
  const json = JSON.stringify(d);
  assert.doesNotMatch(json, /merle|mcmillan_2024|hypoallergenic|odds ratio|23-28 kg/i);
});

test('every dog breed has a Coren rank fact except the explicitly unranked Havanese', () => {
  for (const b of registry.breeds.filter((x) => x.species === 'dog')) {
    const has = b.facts.some((f) => f.field === 'coren_rank');
    assert.equal(has, b.id !== 'havanese', `${b.id} coren_rank fact`);
  }
});

test('West Highland White Terrier (M5-R10-11): up to 1 hour, PDSA weight, FCI group 3 GB, VetCompass median, Coren 47, sensitive_skin chip, white only', () => {
  const d = registry.breeds.find((b) => b.id === 'west_highland_white_terrier');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S144']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 85, group: 3, section: 2, origin: 'GB' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[[6, 9], ['S147']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[[28, 28], ['S142']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 13.4, ['S148']], ['more_than', 12, ['S144']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 47);
  // 10 min × age reaches the 60-minute cap at 6 months; senior 45 min from 121 months.
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3].activity, { kind: 'steps', value: 4500 });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['apartment', 'family_pet', 'children']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['sensitive_skin', 'sheds', 'frequent_grooming', 'chews_when_bored']);
  assert.deepEqual(d.health.map((h) => h.key), ['skin_allergies', 'westie_lung', 'jaw_bone_disorder', 'kneecap_luxation', 'dry_eye']);
  const json = JSON.stringify(d);
  // No VetCompass percentages / CIs, no McMillan placeholder, never "hypoallergenic".
  assert.doesNotMatch(json, /mcmillan_2024|hypoallergenic|odds ratio|95% CI|legg_perthes|wheaten/i);
});

test('Bernese Mountain Dog (M5-R10-12): up to 1 hour, PDSA weight by sex, FCI group 2 CH, RKC "under 10 years" only, Coren 22, shorter_lifespan chip', () => {
  const d = registry.breeds.find((b) => b.id === 'bernese_mountain_dog');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S152']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 45, group: 2, section: 3, origin: 'CH' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [48, 63], female: [42, 53] }, ['S154']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [64, 70], female: [58, 66] }, ['S150']]]);
  // New qualifier less_than (RKC "Lifespan: Under 10 years"); the Swiss median (S156) stays in research.
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['less_than', 10, ['S152']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'growth_end').value, [18, 24]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 22);
  assert.deepEqual(d.facets, { size: 'large', exercise: 'under_1h', grooming: 'several_weekly' });
  // 10 min × age reaches the 60-minute cap at 6 months; senior 45 min from 76 months.
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3].activity, { kind: 'steps', value: 4500 });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['family_pet', 'large_home']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['shorter_lifespan', 'sheds', 'frequent_grooming']);
  assert.deepEqual(d.health.map((h) => h.key), ['cancer_risk', 'hip_elbow_dysplasia', 'bloat_gdv']);
  const json = JSON.stringify(d);
  // No study percentages, no tier-C McMillan value, never "hypoallergenic".
  assert.doesNotMatch(json, /mcmillan_2024|median_ch|hypoallergenic|odds ratio|95% CI|58\.3|10\.1|8\.4/i);
});
