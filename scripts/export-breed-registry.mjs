#!/usr/bin/env node
/**
 * Breed registry export (M5-R11, David 2026-10-09) — build-time data for the
 * "Register pasem" pages of the marketing website (repo DataVallis/pet-prep-website).
 *
 * ONE source of truth: this script only READS the research and the game config
 * and writes a stable JSON file; nothing is typed in twice.
 *
 *   docs/research/dog-data/data.json        facts (only entries with a source_id) and
 *                                           the game numbers David confirmed (`decision`)
 *   docs/research/dog-data/sources.md       the source table (id, tier, publisher, title, URL)
 *   backend/config/breed_suitability.php    "Primerno za" / "Upoštevajte" tags per breed
 *   mobile/src/i18n/locales/{en,sl}/pet.json the app's wording of those tags (breedSuitability)
 *
 * Usage:
 *   node scripts/export-breed-registry.mjs            write docs/research/breed-registry.json
 *   node scripts/export-breed-registry.mjs --out F    write F instead
 *   node scripts/export-breed-registry.mjs --check    exit 1 when the committed file is stale
 *   node scripts/export-breed-registry.mjs --stdout   print the JSON
 *
 * Rules (enforced here and in scripts/tests/export-breed-registry.test.mjs):
 *  - a FACT is exported only from a data.json entry with a non-null `source_id`
 *    (UNSOURCED proposals never become facts) — exportFact() throws otherwise;
 *  - quotes are never exported (breed standards are copyrighted; the site paraphrases);
 *  - health items carry a key + source, never the value text (no cancer percentages);
 *  - GAME values (section `game`) are PetPrep rules: each comes from a recorded David
 *    decision (`decision`) or a sourced value, with its ref; step goals are derived
 *    (minutes × steps per minute) and checked against the step goals in data.json;
 *  - deterministic output: fixed key order, no timestamps; `inputs` holds the SHA-256
 *    of every input file, so a stale copy on the website is easy to spot.
 *
 * Where each game number comes from (data.json paths; seeded by BreedStageParamsSeeder):
 *  - steps per exercise minute ........ general_by_size.exercise.steps_conversion (S45)
 *  - puppy / young minutes per month .. proposed_game_parameters.walk_minutes_puppy (decision)
 *  - meals per day by stage ........... proposed_game_parameters.feed_windows (decision)
 *  - young / adult stage start ........ proposed_game_parameters.arrival_age_months (decision:
 *                                       arrival = first month of the stage)
 *  - Border Collie .................... border_collie.exercise.adult (S5, "> 120" → 120),
 *                                       proposed_game_parameters.senior_exercise_minutes,
 *                                       proposed_game_parameters.arrival_age_months (senior),
 *                                       border_collie.trainability.learning_multiplier
 *  - Labrador / Golden ................ proposed_game_parameters.<breed>.{exercise_minutes_adult,
 *                                       exercise_minutes_senior, stage_boundaries_months,
 *                                       learning_multiplier}
 *  - mixed breed (free plan) .......... medium_mixed_breed.exercise.adult_game_target,
 *                                       proposed_game_parameters.senior_exercise_minutes,
 *                                       proposed_game_parameters.arrival_age_months (senior),
 *                                       medium_mixed_breed.trainability.{learning_multiplier,
 *                                       individual_variation}
 *
 * No dependencies (Node ≥ 20). The PHP config is read by a small parser for PHP
 * literals (parsePhpReturn) that throws on anything that is not a literal; the test
 * cross-checks it against `php -r` and the seeded rows when PHP is available.
 */
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, isAbsolute, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
export const SCHEMA_VERSION = 1;
export const DEFAULT_OUT = 'docs/research/breed-registry.json';
export const INPUTS = {
  data: 'docs/research/dog-data/data.json',
  sources: 'docs/research/dog-data/sources.md',
  suitability: 'backend/config/breed_suitability.php',
  labels_en: 'mobile/src/i18n/locales/en/pet.json',
  labels_sl: 'mobile/src/i18n/locales/sl/pet.json',
};

// ─── PHP literal parser ──────────────────────────────────────────────────────

/**
 * Parses `<?php … return <literal>;` where <literal> is built from arrays
 * (`[ … ]`), strings, numbers, true / false / null. Comments are skipped.
 * Anything else (function calls, constants, env(), concatenation) throws, so a
 * config change that this export cannot read safely fails loudly.
 * A PHP list becomes a JS array, an array with keys a plain object (key order kept).
 */
export function parsePhpReturn(source) {
  let i = 0;
  const n = source.length;
  const fail = (msg) => {
    const line = source.slice(0, i).split('\n').length;
    throw new Error(`breed_suitability.php: ${msg} (line ${line})`);
  };

  const skip = () => {
    for (;;) {
      while (i < n && /\s/.test(source[i])) i++;
      if (source.startsWith('//', i) || (source[i] === '#' && source[i + 1] !== '[')) {
        while (i < n && source[i] !== '\n') i++;
      } else if (source.startsWith('/*', i)) {
        const end = source.indexOf('*/', i + 2);
        if (end < 0) fail('unterminated comment');
        i = end + 2;
      } else {
        return;
      }
    }
  };

  const expect = (tok) => {
    skip();
    if (!source.startsWith(tok, i)) fail(`expected "${tok}"`);
    i += tok.length;
  };

  const parseString = () => {
    const quote = source[i++];
    let out = '';
    while (i < n && source[i] !== quote) {
      const c = source[i++];
      if (c === '\\') {
        const e = source[i++];
        if (quote === "'") {
          out += e === "'" || e === '\\' ? e : `\\${e}`;
        } else {
          const map = { n: '\n', t: '\t', r: '\r', '"': '"', '\\': '\\', $: '$' };
          if (!(e in map)) fail(`unsupported escape \\${e}`);
          out += map[e];
        }
      } else {
        if (quote === '"' && c === '$') fail('string interpolation is not supported');
        out += c;
      }
    }
    if (source[i] !== quote) fail('unterminated string');
    i++;
    return out;
  };

  const parseValue = () => {
    skip();
    const c = source[i];
    if (c === '[') return parseArray();
    if (c === "'" || c === '"') return parseString();
    const num = /^-?\d+(\.\d+)?/.exec(source.slice(i, i + 40));
    if (num) {
      i += num[0].length;
      return Number(num[0]);
    }
    for (const [word, value] of [['true', true], ['false', false], ['null', null]]) {
      if (source.slice(i, i + word.length).toLowerCase() === word && !/\w/.test(source[i + word.length] ?? '')) {
        i += word.length;
        return value;
      }
    }
    return fail(`unsupported token "${source.slice(i, i + 20)}"`);
  };

  const parseArray = () => {
    i++; // [
    const items = [];
    let keyed = null;
    for (;;) {
      skip();
      if (source[i] === ']') {
        i++;
        break;
      }
      const first = parseValue();
      skip();
      let entry;
      if (source.startsWith('=>', i)) {
        i += 2;
        if (typeof first !== 'string' && typeof first !== 'number') fail('array key must be a string or number');
        entry = { key: String(first), value: parseValue() };
        if (keyed === false) fail('mixed list / keyed array');
        keyed = true;
      } else {
        entry = { value: first };
        if (keyed === true) fail('mixed list / keyed array');
        keyed = false;
      }
      items.push(entry);
      skip();
      if (source[i] === ',') {
        i++;
      } else if (source[i] !== ']') {
        fail('expected "," or "]"');
      }
    }
    if (!keyed) return items.map((e) => e.value);
    const obj = {};
    for (const e of items) {
      if (Object.hasOwn(obj, e.key)) fail(`duplicate key "${e.key}"`);
      obj[e.key] = e.value;
    }
    return obj;
  };

  expect('<?php');
  expect('return');
  const value = parseValue();
  expect(';');
  skip();
  if (i !== n) fail('unexpected content after return statement');
  return value;
}

// ─── sources.md ──────────────────────────────────────────────────────────────

/** Rows `| S1 | A | Publisher | Title | URL | Notes |` → { S1: {id, tier, publisher, title, url} }. */
export function parseSourcesTable(markdown) {
  const out = {};
  for (const line of markdown.split('\n')) {
    if (!/^\| S\d+ \|/.test(line)) continue;
    const cells = line.split('|').slice(1, -1).map((c) => c.trim());
    if (cells.length !== 6) throw new Error(`sources.md: expected 6 columns, got ${cells.length}: ${line.slice(0, 60)}`);
    const [id, tier, publisher, title, url] = cells;
    if (!/^https?:\/\/\S+$/.test(url)) throw new Error(`sources.md: ${id} has no URL`);
    if (out[id]) throw new Error(`sources.md: duplicate ${id}`);
    out[id] = { id, tier, publisher, title, url };
  }
  return out;
}

// ─── data.json helpers ───────────────────────────────────────────────────────

function at(data, path) {
  let node = data;
  for (const seg of path.split('.')) {
    if (node === null || typeof node !== 'object' || !Object.hasOwn(node, seg)) {
      throw new Error(`data.json: missing ${path}`);
    }
    node = node[seg];
  }
  return node;
}

const splitIds = (s) => s.split(',').map((x) => x.trim()).filter(Boolean);

/** A sourced entry → {ref, source_ids, confidence, value}. Throws on UNSOURCED. */
function sourced(data, ref) {
  const e = at(data, ref);
  if (typeof e?.source_id !== 'string' || e.source_id === '') {
    throw new Error(`data.json: ${ref} has no source_id — an UNSOURCED value is never a fact`);
  }
  if (e.value === null || e.value === undefined) throw new Error(`data.json: ${ref} has no value`);
  return { ref, source_ids: splitIds(e.source_id), confidence: e.confidence ?? null, value: e.value };
}

/** A game value: the entry must carry David's decision or a source. */
function gameEntry(data, ref) {
  const e = at(data, ref);
  const decision = typeof e?.decision === 'string' ? e.decision : null;
  const sourceIds = typeof e?.source_id === 'string' ? splitIds(e.source_id) : [];
  if (!decision && sourceIds.length === 0) throw new Error(`data.json: game value ${ref} has neither a decision nor a source`);
  const basis = [...new Set([...sourceIds, ...(Array.isArray(e.derived_from) ? e.derived_from : [])])].sort(bySourceId);
  return { entry: e, meta: { ref, basis_source_ids: basis, decision } };
}

const bySourceId = (a, b) => Number(a.slice(1)) - Number(b.slice(1));

/** "12–15 months" → [12, 15]; "> 120" → 120; "2–3" → [2, 3]. */
function parseRange(text, ref) {
  const m = /(\d+(?:\.\d+)?)\s*[–-]\s*(\d+(?:\.\d+)?)/.exec(String(text));
  if (!m) throw new Error(`data.json: cannot read a range from ${ref}: ${text}`);
  return [Number(m[1]), Number(m[2])];
}
function parseMoreThan(text, ref) {
  const m = /^>\s*(\d+(?:\.\d+)?)$/.exec(String(text).trim());
  if (!m) throw new Error(`data.json: expected "> N" in ${ref}: ${text}`);
  return Number(m[1]);
}
const lc = (s) => String(s).trim().toLowerCase();

const fact = (s, extra) => ({ ref: s.ref, source_ids: s.source_ids, confidence: s.confidence, ...extra });

function normalizeSexRange(value, ref) {
  if (Array.isArray(value)) return { all: value };
  const sex = (v) => (Array.isArray(v) ? v : typeof v === 'number' ? [v, v] : null);
  const out = { male: sex(value.male), female: sex(value.female) };
  if (!out.male) throw new Error(`data.json: ${ref} has no male range`);
  if (out.female === null && typeof value.female === 'string') out.female_note = lc(value.female);
  return out;
}

// ─── breed specs ─────────────────────────────────────────────────────────────

/**
 * What is exported per breed. Only refs listed here are read, so a new data.json
 * field never appears on the website by accident. Order = BreedConfigsSeeder sort_order.
 */
const BREEDS = [
  {
    id: 'border_collie',
    height: ['height.fci_ideal', 'height.akc_range'],
    weight: [['adult_weight.akc', 'range'], ['adult_weight.pdsa', 'range']],
    lifespan: [['lifespan.median_uk', 'median'], ['lifespan.rkc', 'more_than']],
    exercise: [['exercise.adult', 'more_than']],
    coat: ['appearance.coat_varieties'],
    grooming: [],
    shedding: [],
    food_motivation: null,
    health: [],
  },
  {
    id: 'labrador_retriever',
    height: ['height.fci_ideal', 'height.rkc_ideal', 'height.akc_range'],
    weight: [['adult_weight.akc', 'range'], ['adult_weight.pdsa', 'range'], ['adult_weight.uk_measured_mean', 'mean']],
    lifespan: [['lifespan.median_uk', 'median'], ['lifespan.median_uk_vetcompass_2018', 'median'], ['lifespan.rkc', 'more_than']],
    exercise: [['exercise.adult', 'more_than'], ['@proposed_game_parameters.labrador_retriever.exercise_minutes_adult', 'at_least']],
    coat: ['suitability.rkc_coat_length'],
    grooming: ['suitability.rkc_grooming', 'suitability.woodgreen_grooming'],
    shedding: ['suitability.rkc_shedding'],
    food_motivation: 'behaviour.food_motivation',
    health: [['weight_gain', 'behaviour.obesity_tendency']],
  },
  {
    id: 'golden_retriever',
    height: ['height.fci_range', 'height.rkc_range', 'height.akc_range'],
    weight: [['adult_weight.akc', 'range'], ['adult_weight.pdsa', 'range']],
    lifespan: [['lifespan.median_uk', 'median'], ['lifespan.median_uk_vetcompass_2012', 'median'], ['lifespan.rkc', 'more_than']],
    exercise: [['exercise.adult', 'more_than'], ['@proposed_game_parameters.golden_retriever.exercise_minutes_adult', 'at_least']],
    coat: ['suitability.rkc_coat_length'],
    grooming: ['suitability.rkc_grooming', 'suitability.woodgreen_grooming'],
    shedding: ['suitability.rkc_shedding', 'suitability.woodgreen_shedding'],
    food_motivation: 'behaviour.food_motivation',
    health: [
      ['hip_elbow_dysplasia_eye_conditions', 'health.joints_eyes'],
      ['cancer_risk', 'health.cancer'],
      ['weight_gain', 'behaviour.obesity_tendency'],
    ],
  },
];

/** Game refs per breed (see the header for the rationale). */
const GAME = {
  border_collie: {
    adult_minutes: { ref: 'border_collie.exercise.adult', read: (e, ref) => parseMoreThan(e.value, ref) },
    senior_minutes: { ref: 'proposed_game_parameters.senior_exercise_minutes', read: (e) => e.value.border_collie },
    senior_from: { ref: 'proposed_game_parameters.arrival_age_months', read: (e) => e.value.senior.border_collie },
    learning: { ref: 'border_collie.trainability.learning_multiplier', read: (e) => e.value },
    step_goal_check: 'proposed_game_parameters.step_goal_border_collie_adult',
  },
  labrador_retriever: {
    adult_minutes: { ref: 'proposed_game_parameters.labrador_retriever.exercise_minutes_adult', read: (e) => e.value },
    senior_minutes: { ref: 'proposed_game_parameters.labrador_retriever.exercise_minutes_senior', read: (e) => e.value },
    senior_from: { ref: 'proposed_game_parameters.labrador_retriever.stage_boundaries_months', read: (e) => e.value.senior },
    learning: { ref: 'proposed_game_parameters.labrador_retriever.learning_multiplier', read: (e) => e.value },
    step_goal_check: 'proposed_game_parameters.labrador_retriever.step_goal_adult',
    senior_steps_check: 'proposed_game_parameters.labrador_retriever.exercise_minutes_senior',
  },
  golden_retriever: {
    adult_minutes: { ref: 'proposed_game_parameters.golden_retriever.exercise_minutes_adult', read: (e) => e.value },
    senior_minutes: { ref: 'proposed_game_parameters.golden_retriever.exercise_minutes_senior', read: (e) => e.value },
    senior_from: { ref: 'proposed_game_parameters.golden_retriever.stage_boundaries_months', read: (e) => e.value.senior },
    learning: { ref: 'proposed_game_parameters.golden_retriever.learning_multiplier', read: (e) => e.value },
    step_goal_check: 'proposed_game_parameters.golden_retriever.step_goal_adult',
    senior_steps_check: 'proposed_game_parameters.golden_retriever.exercise_minutes_senior',
  },
  medium_mixed_breed: {
    adult_minutes: { ref: 'medium_mixed_breed.exercise.adult_game_target', read: (e) => e.value },
    senior_minutes: { ref: 'proposed_game_parameters.senior_exercise_minutes', read: (e) => e.value.medium_mixed_breed },
    senior_from: { ref: 'proposed_game_parameters.arrival_age_months', read: (e) => e.value.senior.medium_mixed_breed },
    learning: { ref: 'medium_mixed_breed.trainability.learning_multiplier', read: (e) => e.value },
    step_goal_check: 'proposed_game_parameters.step_goal_mixed_adult',
  },
};

// ─── build ───────────────────────────────────────────────────────────────────

function readInput(root, rel) {
  return readFileSync(resolve(root, rel), 'utf8');
}

function sha256(text) {
  return createHash('sha256').update(text).digest('hex');
}

function exportFacts(data, spec) {
  const p = (rel) => (rel.startsWith('@') ? rel.slice(1) : `${spec.id}.${rel}`);
  const identity = sourced(data, p('identity'));
  const id = String(identity.value);
  const num = (re) => {
    const m = re.exec(id);
    if (!m) throw new Error(`data.json: cannot read ${re} from ${spec.id}.identity`);
    return Number(m[1]);
  };

  const size = sourced(data, p('size_class'));
  const growth = sourced(data, p('growth.adult_weight_reached'));
  const coren = sourced(data, p('trainability.coren_rank'));

  return {
    identity: fact(identity, {
      fci_number: num(/FCI No\. (\d+)/),
      fci_group: num(/Group (\d+)/),
      fci_section: num(/Section (\d+)/),
      origin: /Great Britain/.test(id) ? 'GB' : (() => { throw new Error(`unknown origin in ${spec.id}.identity`); })(),
    }),
    size_class: fact(size, { value: lc(size.value) }),
    height_cm: spec.height.map((rel) => {
      const s = sourced(data, p(rel));
      return fact(s, normalizeSexRange(s.value, s.ref));
    }),
    weight_kg: spec.weight.map(([rel, kind]) => {
      const s = sourced(data, p(rel));
      return fact(s, { kind, ...normalizeSexRange(s.value, s.ref) });
    }),
    growth_end_months: fact(growth, { value: parseRange(growth.value, growth.ref) }),
    lifespan_years: spec.lifespan.map(([rel, kind]) => {
      const s = sourced(data, p(rel));
      const value = kind === 'more_than' ? parseMoreThan(s.value, s.ref) : Number(s.value);
      if (!Number.isFinite(value)) throw new Error(`data.json: ${s.ref} is not a number`);
      return fact(s, { kind, value });
    }),
    exercise_minutes_per_day: spec.exercise.map(([rel, kind]) => {
      const s = sourced(data, p(rel));
      const value = kind === 'more_than' ? parseMoreThan(s.value, s.ref) : Number(s.value);
      if (!Number.isFinite(value)) throw new Error(`data.json: ${s.ref} is not a number`);
      return fact(s, { kind, value });
    }),
    coat: spec.coat.map((rel) => {
      const s = sourced(data, p(rel));
      return fact(s, { value: (Array.isArray(s.value) ? s.value : [s.value]).map(lc) });
    }),
    grooming: spec.grooming.map((rel) => {
      const s = sourced(data, p(rel));
      return fact(s, { value: lc(s.value) });
    }),
    shedding: spec.shedding.map((rel) => {
      const s = sourced(data, p(rel));
      return fact(s, { value: lc(s.value) });
    }),
    food_motivated: spec.food_motivation ? fact(sourced(data, p(spec.food_motivation)), {}) : null,
    coren_rank: fact(coren, { value: Number(coren.value) }),
  };
}

function exportGeneral(data) {
  const meals = [
    ['8_12_weeks', 'puppy_8_12_weeks', 'exact'],
    ['3_6_months', 'puppy_3_6_months', 'exact'],
    ['6_12_months', 'puppy_6_12_months', 'exact'],
    ['adult', 'adult', 'at_least'],
    ['senior', 'senior', 'smaller_meals'],
  ].map(([key, stage, kind]) => {
    const s = sourced(data, `general_by_size.feeding_meals_per_day.${key}`);
    const value = typeof s.value === 'number' ? [s.value, s.value] : parseRange(s.value, s.ref);
    return fact(s, { stage, kind, value });
  });

  const puppy = sourced(data, 'general_by_size.life_stages.puppy');
  const young = sourced(data, 'general_by_size.life_stages.young_adult');
  const senior = sourced(data, 'general_by_size.life_stages.senior');
  const seniorShare = /last (\d+)%/.exec(String(senior.value));
  if (!seniorShare) throw new Error('data.json: cannot read the senior share');
  const youngYears = /(\d+)\s*[–-]\s*(\d+)\s*years/.exec(String(young.value));
  if (!youngYears) throw new Error('data.json: cannot read the young-adult end');

  return {
    meals_per_day: meals,
    life_stages: {
      puppy_until_months: fact(puppy, { value: parseRange(puppy.value, puppy.ref) }),
      young_adult_until_years: fact(young, { value: [Number(youngYears[1]), Number(youngYears[2])] }),
      senior_last_share_of_lifespan: fact(senior, { value: Number(seniorShare[1]) / 100 }),
    },
  };
}

function exportGame(data) {
  const conv = String(at(data, 'meta.conventions.game_time'));
  if (!/^1 real week = 1 month of dog age/.test(conv)) throw new Error('data.json: game_time convention changed');

  const spm = gameEntry(data, 'general_by_size.exercise.steps_conversion');
  const stepsPerMinute = spm.entry.value;
  if (!Number.isInteger(stepsPerMinute)) throw new Error('steps_conversion is not an integer');

  const walk = gameEntry(data, 'proposed_game_parameters.walk_minutes_puppy');
  const perMonth = [...new Set(Object.entries(walk.entry.value).map(([m, min]) => min / Number(m)))];
  if (perMonth.length !== 1 || !Number.isInteger(perMonth[0])) throw new Error('walk_minutes_puppy is not "N minutes × age in months"');

  const feed = gameEntry(data, 'proposed_game_parameters.feed_windows');
  const fv = feed.entry.value;
  const arrival = gameEntry(data, 'proposed_game_parameters.arrival_age_months');

  const general = {
    real_weeks_per_dog_month: { value: 1, ref: 'meta.conventions.game_time', basis_source_ids: [], decision: null },
    steps_per_exercise_minute: { value: stepsPerMinute, ...spm.meta },
    puppy_exercise_minutes_per_age_month: { value: perMonth[0], ...walk.meta },
    meals_per_day: {
      value: [
        { stage: 'puppy', from_months: 2, until_months: 3, meals: fv['2_3_months'] },
        { stage: 'puppy', from_months: 3, until_months: 6, meals: fv['3_6_months'] },
        { stage: 'puppy', from_months: 6, until_months: null, meals: fv['6_plus_months'] },
        { stage: 'young', from_months: null, until_months: null, meals: fv['6_plus_months'] },
        { stage: 'adult', from_months: null, until_months: null, meals: fv['6_plus_months'] },
        { stage: 'senior', from_months: null, until_months: null, meals: fv.senior },
      ],
      ...feed.meta,
    },
    puppy_arrival_age_months: { value: arrival.entry.value.puppy, ...arrival.meta },
    young_from_months: { value: arrival.entry.value.young, ...arrival.meta },
    adult_from_months: { value: arrival.entry.value.adult, ...arrival.meta },
  };
  for (const [k, v] of Object.entries(general.meals_per_day.value)) {
    if (!Number.isInteger(v.meals)) throw new Error(`feed_windows: meals missing for row ${k}`);
  }

  const perBreed = {};
  for (const [breed, g] of Object.entries(GAME)) {
    const read = (spec) => {
      const { entry, meta } = gameEntry(data, spec.ref);
      const value = spec.read(entry, spec.ref);
      if (typeof value !== 'number' || !Number.isFinite(value)) throw new Error(`game value ${spec.ref} for ${breed} is not a number`);
      return { value, ...meta };
    };
    const adult = read(g.adult_minutes);
    const senior = read(g.senior_minutes);
    const adultSteps = adult.value * stepsPerMinute;
    const seniorSteps = senior.value * stepsPerMinute;
    // Consistency with the step goals recorded in data.json.
    const goal = at(data, g.step_goal_check).value;
    if (goal !== adultSteps) throw new Error(`${g.step_goal_check} = ${goal}, but ${adult.value} min × ${stepsPerMinute} = ${adultSteps}`);
    if (g.senior_steps_check) {
      const s = at(data, g.senior_steps_check).steps;
      if (s !== seniorSteps) throw new Error(`${g.senior_steps_check}.steps = ${s}, expected ${seniorSteps}`);
    }
    const capMonth = Math.ceil(adult.value / perMonth[0]);
    const puppySteps = [];
    for (let m = general.puppy_arrival_age_months.value; m <= capMonth; m++) {
      puppySteps.push({ age_months: m, steps: Math.min(m * perMonth[0], adult.value) * stepsPerMinute });
    }
    perBreed[breed] = {
      adult_exercise_minutes: adult,
      senior_exercise_minutes: senior,
      adult_step_goal: adultSteps,
      senior_step_goal: seniorSteps,
      growing_step_goal_by_age_months: puppySteps,
      senior_from_months: read(g.senior_from),
      learning_multiplier: read(g.learning),
    };
  }
  const variation = gameEntry(data, 'medium_mixed_breed.trainability.individual_variation');
  perBreed.medium_mixed_breed.individual_learning_variation = { value: variation.entry.value, ...variation.meta };

  return { general, breeds: perBreed };
}

function exportSuitability(php, data, sources) {
  if (!php || typeof php.vocabulary !== 'object' || typeof php.breeds !== 'object') {
    throw new Error('breed_suitability.php: expected vocabulary + breeds');
  }
  const vocabulary = {};
  for (const tag of Object.keys(php.vocabulary).sort()) {
    const kind = php.vocabulary[tag];
    if (kind !== 'suits' && kind !== 'consider') throw new Error(`breed_suitability.php: tag ${tag} has kind ${kind}`);
    vocabulary[tag] = kind;
  }
  const perBreed = {};
  for (const { id } of BREEDS) {
    const b = php.breeds[id] ?? { suits: [], consider: [] };
    const out = {};
    for (const kind of ['suits', 'consider']) {
      out[kind] = (b[kind] ?? []).map((t) => {
        if (vocabulary[t.tag] !== kind) throw new Error(`breed_suitability.php: ${id} ${kind} tag ${t.tag} is not a ${kind} tag`);
        const ids = [...t.source_ids].sort(bySourceId);
        for (const s of ids) if (!sources[s]) throw new Error(`breed_suitability.php: ${id} ${t.tag} cites unknown ${s}`);
        for (const ref of t.refs) {
          const e = at(data, ref);
          if (typeof e?.source_id !== 'string') throw new Error(`breed_suitability.php: ${id} ${t.tag} ref ${ref} is unsourced`);
        }
        return { tag: t.tag, source_ids: ids, refs: [...t.refs] };
      });
    }
    perBreed[id] = out;
  }
  return { vocabulary, breeds: perBreed };
}

function exportLabels(json, locale, vocabulary) {
  const s = json.breedSuitability;
  if (!s) throw new Error(`pet.json (${locale}): no breedSuitability`);
  const labels = { suits_title: s.suitsTitle, consider_title: s.considerTitle, tags: {} };
  for (const tag of Object.keys(vocabulary)) {
    const text = s[vocabulary[tag]]?.[tag];
    if (typeof text !== 'string' || text === '') throw new Error(`pet.json (${locale}): no label for ${tag}`);
    labels.tags[tag] = text;
  }
  return labels;
}

/** Collects every source id cited anywhere below `node`. */
function collectSourceIds(node, into = new Set()) {
  if (Array.isArray(node)) {
    node.forEach((x) => collectSourceIds(x, into));
  } else if (node && typeof node === 'object') {
    for (const [k, v] of Object.entries(node)) {
      if ((k === 'source_ids' || k === 'basis_source_ids') && Array.isArray(v)) v.forEach((s) => into.add(s));
      else collectSourceIds(v, into);
    }
  }
  return into;
}

/** Builds the registry object from the input texts (pure; used by the test). */
export function buildRegistry(texts) {
  const data = JSON.parse(texts.data);
  const sources = parseSourcesTable(texts.sources);
  const php = parsePhpReturn(texts.suitability);
  const suitability = exportSuitability(php, data, sources);
  const general = exportGeneral(data);
  const game = exportGame(data);

  const breeds = BREEDS.map((spec) => {
    const facts = exportFacts(data, spec);
    const health = spec.health.map(([key, rel]) => {
      const s = sourced(data, `${spec.id}.${rel}`);
      return { key, ref: s.ref, source_ids: s.source_ids, confidence: s.confidence };
    });
    const entry = {
      id: spec.id,
      species: 'dog',
      facts,
      health,
      suitability: suitability.breeds[spec.id],
      game: game.breeds[spec.id],
    };
    entry.source_ids = [...collectSourceIds([entry, general, game.general])].sort(bySourceId);
    return entry;
  });

  const mixed = { id: 'medium_mixed_breed', game: game.breeds.medium_mixed_breed };
  mixed.source_ids = [...collectSourceIds([mixed, game.general])].sort(bySourceId);

  const used = [...collectSourceIds([breeds, mixed, general, game.general])].sort(bySourceId);
  const sourceList = used.map((id) => {
    if (!sources[id]) throw new Error(`unknown source ${id}`);
    return sources[id];
  });

  const inputs = {};
  for (const [k, rel] of Object.entries(INPUTS)) inputs[k] = { path: rel, sha256: sha256(texts[k]) };

  return {
    schema_version: SCHEMA_VERSION,
    generator: 'scripts/export-breed-registry.mjs (DataVallis/pet-prep)',
    note: 'Facts carry source_ids (sources[]); game.* values are PetPrep game rules (David decisions), not veterinary advice. Health items are for information only, not vet-reviewed. Do not edit by hand — re-run the export.',
    data_compiled: data.meta.compiled,
    inputs,
    suitability_vocabulary: suitability.vocabulary,
    suitability_labels: {
      en: exportLabels(JSON.parse(texts.labels_en), 'en', suitability.vocabulary),
      sl: exportLabels(JSON.parse(texts.labels_sl), 'sl', suitability.vocabulary),
    },
    general,
    game_general: game.general,
    breeds,
    mixed_breed: mixed,
    sources: sourceList,
  };
}

export function readInputs(root = ROOT) {
  const texts = {};
  for (const [k, rel] of Object.entries(INPUTS)) texts[k] = readInput(root, rel);
  return texts;
}

/** Serialised registry: 2-space JSON + trailing newline (stable diffs). */
export function renderRegistry(root = ROOT) {
  return `${JSON.stringify(buildRegistry(readInputs(root)), null, 2)}\n`;
}

function main(argv) {
  const args = argv.slice(2);
  const outIdx = args.indexOf('--out');
  const outRel = outIdx >= 0 ? args[outIdx + 1] : DEFAULT_OUT;
  if (outIdx >= 0 && !outRel) throw new Error('--out needs a path');
  const out = isAbsolute(outRel) ? outRel : resolve(ROOT, outRel);
  const json = renderRegistry();
  if (args.includes('--stdout')) {
    process.stdout.write(json);
    return 0;
  }
  if (args.includes('--check')) {
    let current = '';
    try {
      current = readFileSync(out, 'utf8');
    } catch {
      // missing → stale
    }
    if (current !== json) {
      console.error(`export-breed-registry: ${outRel} is stale — run: node scripts/export-breed-registry.mjs`);
      return 1;
    }
    console.log(`export-breed-registry: ${outRel} is up to date`);
    return 0;
  }
  writeFileSync(out, json);
  const reg = JSON.parse(json);
  console.log(`export-breed-registry: wrote ${outRel} (${reg.breeds.length} breeds, ${reg.sources.length} sources)`);
  return 0;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    process.exitCode = main(process.argv);
  } catch (err) {
    console.error(`export-breed-registry: ${err.message}`);
    process.exitCode = 1;
  }
}
