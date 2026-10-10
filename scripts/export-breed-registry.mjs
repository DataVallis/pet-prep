#!/usr/bin/env node
/**
 * Animal & breed registry export (M5-R11, David 2026-10-09 / 2026-10-10) — build-time data for
 * the register on the marketing website (repo DataVallis/pet-prep-website: /animals,
 * /animals/<species>, /animals/<species>/<breed>; SL /zivali/…).
 *
 * ONE source of truth: this script only READS research, game config and app strings and writes
 * one stable JSON file; nothing is typed in twice.
 *
 *   docs/research/dog-data/data.json, sources.md   dog facts (S…) + David's game decisions
 *   docs/research/cat-data/data.json, sources.md   cat facts (C…) + David's game decisions
 *   backend/config/breed_suitability.php           "Primerno za" / "Upoštevajte" tags
 *   mobile/src/i18n/locales/{en,sl}/pet.json       the app's wording of those tags
 *   mobile/src/i18n/locales/{en,sl}/family.json    the app's breed names (→ names + URL slugs)
 *
 * Model (schema 2, David 2026-10-10: "the register must scale to ~300 breeds and many species"):
 *   species[]  id, URL slug + names (EN/SL), status, free plan, which fact groups / filters /
 *              comparison rows it has, species-wide facts (meals, life stages, litter …)
 *   breeds[]   species, availability (in_app | coming_soon | info_only), names, slugs,
 *              synonyms, facets (filters), facts[] (generic items), compare map, health,
 *              suitability tags, game rules, source ids
 *   sources[]  every cited source (S… dogs, C… cats) with publisher, title and URL
 *
 * `availability` lives HERE (SPECIES[].breeds below), next to the list of breeds in the register,
 * because membership and app status change together in this repo (a breed ships with a backend
 * enum + seeder; info_only breeds exist only as research). The website only renders it.
 *
 * Fact items are generic so the website can render any species without species-specific layout:
 *   { group, field, kind: quantity | category | statement, value, unit?, qualifier?, context?,
 *     note?, source_ids, ref, confidence }
 *
 * Rules (enforced here and in scripts/tests/export-breed-registry.test.mjs):
 *  - a FACT comes only from a data.json entry with a non-null source_id (UNSOURCED never);
 *  - quotes are never exported (breed standards are copyrighted; the site paraphrases);
 *  - health items carry a key + source, never the value text (no cancer percentages);
 *  - GAME values come from a recorded David decision (`decision`) or a sourced value; dog step
 *    goals are minutes × steps per minute and checked against data.json; the test compares
 *    every game number with BreedStageParamsSeeder::rows() / catRows() when PHP is available;
 *  - deterministic output: fixed order, no timestamps; `inputs` holds SHA-256 of every input.
 *
 * Where each game number comes from (data.json paths):
 *  dogs  steps per minute ......... dog general_by_size.exercise.steps_conversion (S45)
 *        puppy minutes per month .. dog proposed_game_parameters.walk_minutes_puppy (decision)
 *        meals by stage ........... dog proposed_game_parameters.feed_windows (decision)
 *        stage starts / arrival ... dog proposed_game_parameters.arrival_age_months (+ per breed)
 *        per breed ................ see DOG_GAME below
 *  cats  stage starts / arrival ... cat general.arrival_age.* (+ maine_coon.arrival_age_kitten)
 *        meals by stage ........... cat general.meals_per_day.*
 *        play sessions ............ cat general.play.game_sessions_{kitten,adult}, game_min_gap
 *        litter ................... cat general.litter.game_uses_per_day_*, game_scoop_deadline,
 *                                   full_change
 *        grooming (Maine Coon) .... cat maine_coon.grooming.game_sessions_per_week
 *
 * Breed portraits (M5-R11, David 2026-10-10 — optional input):
 *   docs/research/breed-portraits/manifest.json      written by `php artisan breeds:portraits`
 *   docs/research/breed-portraits/<species>/<slug>.webp   AI-generated photos
 * Each breed gets `portrait: { file, width, height, kind }` (null when the manifest has none).
 * `kind` is "ai_photo" (David 2026-10-10: realistic photos): the website MUST label it "AI-generated photo".
 * The older "ai_illustration" (first portraits, same day) is still accepted and passed through; the
 * website labels it "AI-generated illustration".
 * `file` is the basename; the website serves it from public/animals/<species.slug.en>/<file>
 * (e.g. public/animals/dogs/border-collie.webp). Copy the files there with
 *   node scripts/export-breed-registry.mjs --copy-portraits ../pet-prep-website/public
 * (writes the registry as usual, then copies every portrait of the export into
 * <dir>/animals/<species slug>/). The registry JSON goes to src/content/registry/registry.json.
 *
 * Usage: node scripts/export-breed-registry.mjs [--out F | --check | --stdout] [--copy-portraits DIR]
 * No dependencies (Node ≥ 20).
 */
import { createHash } from 'node:crypto';
import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, dirname, isAbsolute, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
export const SCHEMA_VERSION = 2;
export const DEFAULT_OUT = 'docs/research/breed-registry.json';
export const INPUTS = {
  dog_data: 'docs/research/dog-data/data.json',
  dog_sources: 'docs/research/dog-data/sources.md',
  cat_data: 'docs/research/cat-data/data.json',
  cat_sources: 'docs/research/cat-data/sources.md',
  suitability: 'backend/config/breed_suitability.php',
  labels_en: 'mobile/src/i18n/locales/en/pet.json',
  labels_sl: 'mobile/src/i18n/locales/sl/pet.json',
  family_en: 'mobile/src/i18n/locales/en/family.json',
  family_sl: 'mobile/src/i18n/locales/sl/family.json',
};

/** Optional input: AI breed portraits (`php artisan breeds:portraits`); missing → every portrait null. */
export const PORTRAITS_DIR = 'docs/research/breed-portraits';
export const PORTRAIT_MANIFEST = `${PORTRAITS_DIR}/manifest.json`;
/** `ai_photo` = current; `ai_illustration` = older manifests, still read. */
export const PORTRAIT_KINDS = ['ai_photo', 'ai_illustration'];

/** URL words that a breed slug may never take (the comparison page lives next to the breeds). */
export const RESERVED_SLUGS = ['compare', 'primerjava'];


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

/** Rows `| S1 | A | Publisher | Title | URL … | Notes |` → { S1: {id, tier, publisher, title, url} }. */
export function parseSourcesTable(markdown) {
  const out = {};
  for (const line of markdown.split('\n')) {
    if (!/^\| [A-Z]\d+ \|/.test(line)) continue;
    const cells = line.split('|').slice(1, -1).map((c) => c.trim());
    if (cells.length !== 6) throw new Error(`sources.md: expected 6 columns, got ${cells.length}: ${line.slice(0, 60)}`);
    const [id, tier, publisher, title, urlCell] = cells;
    const url = /https?:\/\/[^\s)]+/.exec(urlCell)?.[0];
    if (!url) throw new Error(`sources.md: ${id} has no URL`);
    if (out[id]) throw new Error(`sources.md: duplicate ${id}`);
    out[id] = { id, tier, publisher, title, url };
  }
  return out;
}

// ─── helpers ─────────────────────────────────────────────────────────────────

function at(data, path, file) {
  let node = data;
  for (const seg of path.split('.')) {
    if (node === null || typeof node !== 'object' || !Object.hasOwn(node, seg)) {
      throw new Error(`${file}: missing ${path}`);
    }
    node = node[seg];
  }
  return node;
}

const splitIds = (s) => s.split(',').map((x) => x.trim()).filter(Boolean);

/** S2 < S10 < C1: dogs (S) first, then cats (C), numeric inside a prefix. */
export const bySourceId = (a, b) => {
  const pa = a[0] === 'S' ? 0 : 1;
  const pb = b[0] === 'S' ? 0 : 1;
  return pa - pb || Number(a.slice(1)) - Number(b.slice(1));
};

/** "12–15 months" → [12, 15]. */
function parseRange(text, ref) {
  const m = /(\d+(?:\.\d+)?)\s*[–-]\s*(\d+(?:\.\d+)?)/.exec(String(text));
  if (!m) throw new Error(`cannot read a range from ${ref}: ${text}`);
  return [Number(m[1]), Number(m[2])];
}
/** "≤ 60" → 60 (an "up to" value). */
function parseUpTo(text, ref) {
  const m = /^≤\s*(\d+(?:\.\d+)?)$/.exec(String(text).trim());
  if (!m) throw new Error(`expected "≤ N" in ${ref}: ${text}`);
  return Number(m[1]);
}
/** "> 120" → 120. */
function parseMoreThan(text, ref) {
  const m = /^>\s*(\d+(?:\.\d+)?)$/.exec(String(text).trim());
  if (!m) throw new Error(`expected "> N" in ${ref}: ${text}`);
  return Number(m[1]);
}
const code = (s) => String(s).trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');

/** "Zlati prinašalec" → "zlati-prinasalec". */
export function slugify(name) {
  return name
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
}

/**
 * Reader for one species' data.json: `fact()` turns a SOURCED entry into a generic fact item
 * (throws on UNSOURCED), `game()` reads a game value that has a decision or a source.
 */
function reader(data, file, prefix) {
  const entry = (ref) => at(data, ref, file);
  const sourced = (ref) => {
    const e = entry(ref);
    if (typeof e?.source_id !== 'string' || e.source_id === '') {
      throw new Error(`${file}: ${ref} has no source_id — an UNSOURCED value is never a fact`);
    }
    if (e.value === null || e.value === undefined) throw new Error(`${file}: ${ref} has no value`);
    return e;
  };
  const fact = (ref, item, transform = (v) => v) => {
    const e = sourced(ref);
    return {
      ...item,
      value: transform(e.value, ref),
      source_ids: splitIds(e.source_id),
      ref: `${prefix}${ref}`,
      confidence: e.confidence ?? null,
    };
  };
  const game = (ref, read = (e) => e.value) => {
    const e = entry(ref);
    const decision = typeof e?.decision === 'string' ? e.decision : null;
    const ids = typeof e?.source_id === 'string' ? splitIds(e.source_id) : [];
    if (!decision && ids.length === 0) throw new Error(`${file}: game value ${ref} has neither a decision nor a source`);
    const value = read(e, ref);
    const basis = [...new Set([...ids, ...(Array.isArray(e.derived_from) ? e.derived_from : [])])].sort(bySourceId);
    return { value, meta: { ref: `${prefix}${ref}`, basis_source_ids: basis, decision } };
  };
  return { entry, sourced, fact, game };
}

/** {male:[a,b], female:[c,d]} / [a,b] / {male:53, female:"slightly less"} → quantity value. */
function sexValue(v, ref) {
  if (Array.isArray(v)) return v;
  const r = (x) => (Array.isArray(x) ? x : typeof x === 'number' ? [x, x] : null);
  const out = { male: r(v.male), female: r(v.female) };
  if (!out.male) throw new Error(`${ref}: no male value`);
  return out;
}

// ─── species and breeds in the register ──────────────────────────────────────

/**
 * The register. Order of `breeds` = PetPrep's order (the breeds of the app first, then the
 * rollout order of ROADMAP M5-R10). `availability`:
 *   in_app      playable in the store release of the app
 *   coming_soon built in the app, not in a store release yet (or hidden behind a flag)
 *   info_only   in the register with sourced facts, not playable in the app
 */
const SPECIES = [
  {
    id: 'dog',
    slug: { en: 'dogs', sl: 'psi' },
    name: { en: { one: 'Dog', many: 'Dogs' }, sl: { one: 'Pes', many: 'Psi' } },
    free_plan: 'mutt',
    fact_groups: ['exercise', 'grooming', 'feeding', 'lifespan', 'stages', 'size', 'training'],
    facets: ['size', 'exercise', 'grooming'],
    compare_fields: ['size', 'weight', 'exercise', 'grooming_frequency', 'shedding', 'lifespan', 'game_activity'],
    breeds: [
      { id: 'border_collie', availability: 'in_app', synonyms: { en: [], sl: [] } },
      { id: 'labrador_retriever', availability: 'coming_soon', synonyms: { en: ['Labrador', 'Lab'], sl: ['labradorski prinašalec', 'labrador'] } },
      { id: 'golden_retriever', availability: 'coming_soon', synonyms: { en: ['Golden'], sl: ['golden', 'golden retriver'] } },
      { id: 'french_bulldog', availability: 'coming_soon', synonyms: { en: ['Frenchie'], sl: ['frenchie', 'francoski buldog', 'french bulldog'] } },
      { id: 'german_shepherd', availability: 'coming_soon', synonyms: { en: ['Alsatian', 'GSD'], sl: ['nemški ovčar', 'nemski ovcar', 'german shepherd'] } },
      { id: 'cavalier_king_charles_spaniel', availability: 'coming_soon', synonyms: { en: ['Cavalier', 'CKCS', 'Cavalier King Charles'], sl: ['kavalir king charles španjel', 'kavalir king charles spanjel', 'kavalir', 'cavalier'] } },
      { id: 'beagle', availability: 'coming_soon', synonyms: { en: [], sl: ['bigl', 'beagle'] } },
      { id: 'standard_poodle', availability: 'coming_soon', synonyms: { en: ['Standard Poodle', 'Poodle'], sl: ['koder', 'veliki pudelj', 'pudelj', 'standardni pudelj', 'poodle'] } },
    ],
  },
  {
    id: 'cat',
    slug: { en: 'cats', sl: 'macke' },
    name: { en: { one: 'Cat', many: 'Cats' }, sl: { one: 'Mačka', many: 'Mačke' } },
    free_plan: 'domestic_cat',
    fact_groups: ['play', 'litter', 'scratching', 'grooming', 'feeding', 'water', 'lifespan', 'stages', 'size'],
    facets: [],
    compare_fields: ['weight', 'grooming_sources_differ', 'lifespan', 'game_activity'],
    // Cats are hidden in the app (PETPREP_CATS_ENABLED=false) → coming soon.
    breeds: [{ id: 'maine_coon', availability: 'coming_soon', synonyms: { en: [], sl: [] } }],
  },
];

// ─── dogs ────────────────────────────────────────────────────────────────────

/** FCI country of origin as written at the end of a breed's `identity` value → ISO code (the website words it). */
const FCI_ORIGINS = { 'Great Britain': 'GB', France: 'FR', Germany: 'DE' };

/** Per-breed fact refs (dog data.json); only these are read, so nothing appears by accident. */
const DOG_FACTS = {
  border_collie: {
    height: ['height.fci_ideal', 'height.akc_range'],
    weight: [['adult_weight.akc', null], ['adult_weight.pdsa', null]],
    lifespan: [['lifespan.median_uk', 'median'], ['lifespan.rkc', 'more_than']],
    exercise: [['exercise.adult', 'more_than']],
    coat: ['appearance.coat_varieties'],
    grooming: [],
    shedding: [],
    food_motivation: null,
    health: [],
  },
  labrador_retriever: {
    height: ['height.fci_ideal', 'height.rkc_ideal', 'height.akc_range'],
    weight: [['adult_weight.akc', null], ['adult_weight.pdsa', null], ['adult_weight.uk_measured_mean', 'mean']],
    lifespan: [['lifespan.median_uk', 'median'], ['lifespan.median_uk_vetcompass_2018', 'median'], ['lifespan.rkc', 'more_than']],
    exercise: [['exercise.adult', 'more_than'], ['@proposed_game_parameters.labrador_retriever.exercise_minutes_adult', 'at_least']],
    coat: ['suitability.rkc_coat_length'],
    grooming: [['suitability.rkc_grooming', 'grooming_frequency'], ['suitability.woodgreen_grooming', 'grooming_level']],
    shedding: ['suitability.rkc_shedding'],
    food_motivation: 'behaviour.food_motivation',
    health: [['weight_gain', 'behaviour.obesity_tendency']],
  },
  golden_retriever: {
    height: ['height.fci_range', 'height.rkc_range', 'height.akc_range'],
    weight: [['adult_weight.akc', null], ['adult_weight.pdsa', null]],
    lifespan: [['lifespan.median_uk', 'median'], ['lifespan.median_uk_vetcompass_2012', 'median'], ['lifespan.rkc', 'more_than']],
    exercise: [['exercise.adult', 'more_than'], ['@proposed_game_parameters.golden_retriever.exercise_minutes_adult', 'at_least']],
    coat: ['suitability.rkc_coat_length'],
    grooming: [['suitability.rkc_grooming', 'grooming_frequency'], ['suitability.woodgreen_grooming', 'grooming_level']],
    shedding: ['suitability.rkc_shedding', 'suitability.woodgreen_shedding'],
    food_motivation: 'behaviour.food_motivation',
    health: [
      ['hip_elbow_dysplasia_eye_conditions', 'health.joints_eyes'],
      ['cancer_risk', 'health.cancer'],
      ['weight_gain', 'behaviour.obesity_tendency'],
    ],
  },
  // M5-R10-03. Not exported: lifespan.vetcompass_2013_deaths (median age at death of a very young
  // population — not a life expectancy), any BOAS / heatstroke percentage or odds ratio (research only).
  french_bulldog: {
    height: ['height.fci_range'],
    weight: [['adult_weight.fci', null], ['adult_weight.rkc_ideal', 'ideal'], ['adult_weight.pdsa', null], ['adult_weight.uk_measured_mean', 'mean']],
    lifespan: [['lifespan.median_uk', 'median'], ['lifespan.rkc', 'more_than']],
    exercise: [['exercise.adult', 'up_to']],
    coat: ['suitability.rkc_coat_length'],
    grooming: [['suitability.rkc_grooming', 'grooming_frequency'], ['suitability.woodgreen_grooming', 'grooming_level']],
    shedding: ['suitability.rkc_shedding', 'suitability.woodgreen_shedding'],
    food_motivation: null,
    health: [
      ['flat_face_breathing', 'health.brachycephaly_boas'],
      ['heat_stroke_risk', 'health.heat_stroke'],
      ['skin_fold_ear_problems', 'health.other_conditions'],
      ['merle_colour_risk', 'health.merle_colour'],
    ],
  },
  // M5-R10-04. Not exported: causes-of-death / disorder percentages (VetCompass S101 / S102 — research only).
  german_shepherd: {
    height: ['height.fci_range'],
    weight: [['adult_weight.fci', null], ['adult_weight.pdsa', null], ['adult_weight.uk_measured_median', 'median']],
    lifespan: [['lifespan.median_uk', 'median'], ['lifespan.rkc', 'more_than']],
    exercise: [['exercise.adult', 'more_than']],
    coat: ['suitability.rkc_coat_length'],
    grooming: [['suitability.rkc_grooming', 'grooming_frequency']],
    shedding: ['suitability.rkc_shedding'],
    food_motivation: null,
    health: [
      ['hind_leg_conformation', 'health.hind_conformation'],
      ['hip_elbow_dysplasia', 'health.hip_elbow_dysplasia'],
      ['degenerative_myelopathy', 'health.degenerative_myelopathy'],
    ],
  },
  // M5-R10-05. Not exported: adult_weight.uk_measured_median (10.5 kg, no sex split — would read as a
  // target weight), disorder percentages (Summers 2015, S109 — research only).
  cavalier_king_charles_spaniel: {
    height: ['height.pdsa_average'],
    weight: [['adult_weight.fci', null], ['adult_weight.rkc', null], ['adult_weight.pdsa', null]],
    lifespan: [['lifespan.median_uk', 'median'], ['lifespan.rkc', 'more_than']],
    exercise: [['exercise.adult', 'up_to']],
    coat: ['suitability.rkc_coat_length'],
    grooming: [['suitability.rkc_grooming', 'grooming_frequency']],
    shedding: ['suitability.rkc_shedding'],
    food_motivation: null,
    health: [
      ['heart_valve_disease', 'health.heart_mvd'],
      ['chiari_syringomyelia', 'health.syringomyelia'],
      ['eye_conditions', 'health.eyes'],
    ],
  },
  // M5-R10-06. Not exported: adult_weight.uk_measured_median (18.19 kg, measured pet dogs with
  // much obesity — would read as a target weight), disorder percentages (O'Neill 2025, S116 —
  // research only), the PDSA 90-minute exercise alternative (S115, the RKC value is used).
  beagle: {
    height: ['height.fci'],
    weight: [['adult_weight.pdsa', null]],
    lifespan: [['lifespan.median_uk', 'median'], ['lifespan.rkc', 'more_than']],
    exercise: [['exercise.adult', 'up_to']],
    coat: ['suitability.rkc_coat_length'],
    grooming: [['suitability.rkc_grooming', 'grooming_frequency']],
    shedding: ['suitability.rkc_shedding'],
    food_motivation: null,
    health: [
      ['weight_gain', 'health.obesity'],
      ['epilepsy', 'health.epilepsy'],
      ['back_disc_disease', 'health.back_ivdd'],
    ],
  },
  // M5-R10-07. Not exported: height.rkc (minimum only, "> 38"), lifespan.pdsa (a range),
  // lifespan.mcmillan_poodle_pooled (14.0 y pools all Poodle varieties — would read as the
  // Standard's median). Never "hypoallergenic": shedding is the RKC "Sheds: No" field.
  standard_poodle: {
    height: ['height.fci'],
    weight: [['adult_weight.pdsa', null]],
    lifespan: [['lifespan.rkc', 'more_than']],
    exercise: [['exercise.adult', 'up_to']],
    coat: ['suitability.rkc_coat_length'],
    grooming: [['suitability.rkc_grooming', 'grooming_frequency']],
    shedding: ['suitability.rkc_shedding'],
    food_motivation: null,
    health: [
      ['pra_eye_disease', 'health.eyes_pra'],
      ['hip_dysplasia', 'health.hips'],
      ['bloat_gdv', 'health.bloat_gdv'],
      ['epilepsy', 'health.epilepsy'],
    ],
  },
};

/** Per-breed game refs (dog data.json). */
const DOG_GAME = {
  border_collie: {
    adult_minutes: ['border_collie.exercise.adult', (e, ref) => parseMoreThan(e.value, ref)],
    senior_minutes: ['proposed_game_parameters.senior_exercise_minutes', (e) => e.value.border_collie],
    senior_from: ['proposed_game_parameters.arrival_age_months', (e) => e.value.senior.border_collie],
    learning: ['border_collie.trainability.learning_multiplier'],
    step_goal_check: 'proposed_game_parameters.step_goal_border_collie_adult',
  },
  labrador_retriever: {
    adult_minutes: ['proposed_game_parameters.labrador_retriever.exercise_minutes_adult'],
    senior_minutes: ['proposed_game_parameters.labrador_retriever.exercise_minutes_senior'],
    senior_from: ['proposed_game_parameters.labrador_retriever.stage_boundaries_months', (e) => e.value.senior],
    learning: ['proposed_game_parameters.labrador_retriever.learning_multiplier'],
    step_goal_check: 'proposed_game_parameters.labrador_retriever.step_goal_adult',
    senior_steps_check: 'proposed_game_parameters.labrador_retriever.exercise_minutes_senior',
  },
  golden_retriever: {
    adult_minutes: ['proposed_game_parameters.golden_retriever.exercise_minutes_adult'],
    senior_minutes: ['proposed_game_parameters.golden_retriever.exercise_minutes_senior'],
    senior_from: ['proposed_game_parameters.golden_retriever.stage_boundaries_months', (e) => e.value.senior],
    learning: ['proposed_game_parameters.golden_retriever.learning_multiplier'],
    step_goal_check: 'proposed_game_parameters.golden_retriever.step_goal_adult',
    senior_steps_check: 'proposed_game_parameters.golden_retriever.exercise_minutes_senior',
  },
  french_bulldog: {
    adult_minutes: ['proposed_game_parameters.french_bulldog.exercise_minutes_adult'],
    senior_minutes: ['proposed_game_parameters.french_bulldog.exercise_minutes_senior'],
    senior_from: ['proposed_game_parameters.french_bulldog.stage_boundaries_months', (e) => e.value.senior],
    learning: ['proposed_game_parameters.french_bulldog.learning_multiplier'],
    step_goal_check: 'proposed_game_parameters.french_bulldog.step_goal_adult',
    senior_steps_check: 'proposed_game_parameters.french_bulldog.exercise_minutes_senior',
  },
  german_shepherd: {
    adult_minutes: ['proposed_game_parameters.german_shepherd.exercise_minutes_adult'],
    senior_minutes: ['proposed_game_parameters.german_shepherd.exercise_minutes_senior'],
    senior_from: ['proposed_game_parameters.german_shepherd.stage_boundaries_months', (e) => e.value.senior],
    learning: ['proposed_game_parameters.german_shepherd.learning_multiplier'],
    step_goal_check: 'proposed_game_parameters.german_shepherd.step_goal_adult',
    senior_steps_check: 'proposed_game_parameters.german_shepherd.exercise_minutes_senior',
  },
  cavalier_king_charles_spaniel: {
    adult_minutes: ['proposed_game_parameters.cavalier_king_charles_spaniel.exercise_minutes_adult'],
    senior_minutes: ['proposed_game_parameters.cavalier_king_charles_spaniel.exercise_minutes_senior'],
    senior_from: ['proposed_game_parameters.cavalier_king_charles_spaniel.stage_boundaries_months', (e) => e.value.senior],
    learning: ['proposed_game_parameters.cavalier_king_charles_spaniel.learning_multiplier'],
    step_goal_check: 'proposed_game_parameters.cavalier_king_charles_spaniel.step_goal_adult',
    senior_steps_check: 'proposed_game_parameters.cavalier_king_charles_spaniel.exercise_minutes_senior',
  },
  beagle: {
    adult_minutes: ['proposed_game_parameters.beagle.exercise_minutes_adult'],
    senior_minutes: ['proposed_game_parameters.beagle.exercise_minutes_senior'],
    senior_from: ['proposed_game_parameters.beagle.stage_boundaries_months', (e) => e.value.senior],
    learning: ['proposed_game_parameters.beagle.learning_multiplier'],
    step_goal_check: 'proposed_game_parameters.beagle.step_goal_adult',
    senior_steps_check: 'proposed_game_parameters.beagle.exercise_minutes_senior',
  },
  standard_poodle: {
    adult_minutes: ['proposed_game_parameters.standard_poodle.exercise_minutes_adult'],
    senior_minutes: ['proposed_game_parameters.standard_poodle.exercise_minutes_senior'],
    senior_from: ['proposed_game_parameters.standard_poodle.stage_boundaries_months', (e) => e.value.senior],
    learning: ['proposed_game_parameters.standard_poodle.learning_multiplier'],
    step_goal_check: 'proposed_game_parameters.standard_poodle.step_goal_adult',
    senior_steps_check: 'proposed_game_parameters.standard_poodle.exercise_minutes_senior',
  },
  mutt: {
    adult_minutes: ['medium_mixed_breed.exercise.adult_game_target'],
    senior_minutes: ['proposed_game_parameters.senior_exercise_minutes', (e) => e.value.medium_mixed_breed],
    senior_from: ['proposed_game_parameters.arrival_age_months', (e) => e.value.senior.medium_mixed_breed],
    learning: ['medium_mixed_breed.trainability.learning_multiplier'],
    step_goal_check: 'proposed_game_parameters.step_goal_mixed_adult',
  },
};

function dogBreedFacts(R, id) {
  const spec = DOG_FACTS[id];
  if (!spec) throw new Error(`no DOG_FACTS for ${id}`);
  const p = (rel) => (rel.startsWith('@') ? rel.slice(1) : `${id}.${rel}`);
  const facts = [];

  facts.push(R.fact(p('size_class'), { group: 'size', field: 'size', kind: 'category' }, (v) => code(v)));
  for (const rel of spec.height) facts.push(R.fact(p(rel), { group: 'size', field: 'height', kind: 'quantity', unit: 'cm' }, sexValue));
  for (const [rel, q] of spec.weight) {
    facts.push(R.fact(p(rel), { group: 'size', field: 'weight', kind: 'quantity', unit: 'kg', ...(q ? { qualifier: q } : {}) }, (v, ref) => (typeof v.male === 'number' ? { male: [v.male, v.male], female: [v.female, v.female] } : sexValue(v, ref))));
  }
  facts.push(R.fact(p('growth.adult_weight_reached'), { group: 'stages', field: 'growth_end', kind: 'quantity', unit: 'months', qualifier: 'about', note: 'size_class_guidance' }, parseRange));
  for (const [rel, q] of spec.lifespan) {
    facts.push(R.fact(p(rel), { group: 'lifespan', field: 'lifespan', kind: 'quantity', unit: 'years', qualifier: q }, (v, ref) => (q === 'more_than' ? parseMoreThan(v, ref) : Number(v))));
  }
  for (const [rel, q] of spec.exercise) {
    facts.push(R.fact(p(rel), { group: 'exercise', field: 'exercise', kind: 'quantity', unit: 'min_per_day', qualifier: q }, (v, ref) => (q === 'more_than' ? parseMoreThan(v, ref) : q === 'up_to' ? parseUpTo(v, ref) : Number(v))));
  }
  for (const rel of spec.coat) {
    facts.push(R.fact(p(rel), { group: 'grooming', field: 'coat', kind: 'category' }, (v) => (Array.isArray(v) ? v : [v]).map(code)));
  }
  for (const [rel, field] of spec.grooming) facts.push(R.fact(p(rel), { group: 'grooming', field, kind: 'category' }, code));
  for (const rel of spec.shedding) facts.push(R.fact(p(rel), { group: 'grooming', field: 'shedding', kind: 'category' }, code));
  if (spec.food_motivation) facts.push(R.fact(p(spec.food_motivation), { group: 'feeding', field: 'food_motivated', kind: 'statement' }, () => true));
  facts.push(R.fact(p('trainability.coren_rank'), { group: 'training', field: 'coren_rank', kind: 'statement' }, Number));
  facts.push(
    R.fact(p('identity'), { group: 'training', field: 'fci_standard', kind: 'statement' }, (v, ref) => {
      const s = String(v);
      const num = (re) => {
        const m = re.exec(s);
        if (!m) throw new Error(`cannot read ${re} from ${ref}`);
        return Number(m[1]);
      };
      const origin = Object.entries(FCI_ORIGINS).find(([name]) => s.endsWith(`, ${name}`))?.[1];
      if (!origin) throw new Error(`unknown origin in ${ref} (add it to FCI_ORIGINS)`);
      return { number: num(/FCI No\. (\d+)/), group: num(/Group (\d+)/), section: num(/Section (\d+)/), origin };
    }),
  );
  const health = spec.health.map(([key, rel]) => {
    const f = R.fact(p(rel), { key }, () => null);
    delete f.value;
    return f;
  });
  return { facts, health };
}

/** Species-wide dog facts (shown on every dog page in their group). */
function dogGeneralFacts(R) {
  const meals = [
    ['8_12_weeks', 'puppy_8_12_weeks', 'exact'],
    ['3_6_months', 'puppy_3_6_months', 'exact'],
    ['6_12_months', 'puppy_6_12_months', 'exact'],
    ['adult', 'adult', 'at_least'],
    ['senior', 'senior', 'smaller_meals'],
  ].map(([key, context, qualifier]) =>
    R.fact(`general_by_size.feeding_meals_per_day.${key}`, { group: 'feeding', field: 'meals', kind: 'quantity', unit: 'meals_per_day', qualifier, context }, (v, ref) => (typeof v === 'number' ? v : parseRange(v, ref))),
  );
  const stages = [
    R.fact('general_by_size.life_stages.puppy', { group: 'stages', field: 'stage', kind: 'quantity', unit: 'months', qualifier: 'until_about', context: 'puppy' }, parseRange),
    R.fact('general_by_size.life_stages.young_adult', { group: 'stages', field: 'stage', kind: 'quantity', unit: 'years', qualifier: 'until', context: 'young_adult' }, (v, ref) => {
      const m = /(\d+)\s*[–-]\s*(\d+)\s*years/.exec(String(v));
      if (!m) throw new Error(`cannot read the young-adult end from ${ref}`);
      return [Number(m[1]), Number(m[2])];
    }),
    R.fact('general_by_size.life_stages.senior', { group: 'stages', field: 'stage', kind: 'quantity', unit: 'share_of_lifespan', qualifier: 'last', context: 'senior' }, (v, ref) => {
      const m = /last (\d+)%/.exec(String(v));
      if (!m) throw new Error(`cannot read the senior share from ${ref}`);
      return Number(m[1]) / 100;
    }),
  ];
  return [...meals, ...stages];
}

/** Meal steps of the game from the arrival month on: [{from_months|null, meals}]. */
function mealSteps(table, arrival) {
  const sorted = [...table].sort((a, b) => a.from - b.from);
  let active = sorted.filter((t) => t.from <= arrival).at(-1);
  if (!active) throw new Error(`no meal rule at arrival month ${arrival}`);
  const out = [{ from_months: null, meals: active.meals }];
  for (const t of sorted) if (t.from > arrival && t.meals !== out.at(-1).meals) out.push({ from_months: t.from, meals: t.meals });
  return out;
}

function dogGame(R) {
  const conv = String(R.entry('meta.conventions.game_time'));
  if (!/^1 real week = 1 month of dog age/.test(conv)) throw new Error('dog data.json: game_time convention changed');
  const spm = R.game('general_by_size.exercise.steps_conversion');
  if (!Number.isInteger(spm.value)) throw new Error('steps_conversion is not an integer');
  const walk = R.game('proposed_game_parameters.walk_minutes_puppy');
  const perMonth = [...new Set(Object.entries(walk.value).map(([m, min]) => min / Number(m)))];
  if (perMonth.length !== 1 || !Number.isInteger(perMonth[0])) throw new Error('walk_minutes_puppy is not "N minutes × age in months"');
  const feed = R.game('proposed_game_parameters.feed_windows');
  const arrival = R.game('proposed_game_parameters.arrival_age_months');
  const fv = feed.value;
  const general = {
    steps_per_exercise_minute: { value: spm.value, ...spm.meta },
    puppy_exercise_minutes_per_age_month: { value: perMonth[0], ...walk.meta },
    meals: { value: { puppy: [{ from: 0, meals: fv['2_3_months'] }, { from: 3, meals: fv['3_6_months'] }, { from: 6, meals: fv['6_plus_months'] }], young: fv['6_plus_months'], adult: fv['6_plus_months'], senior: fv.senior }, ...feed.meta },
    arrival: { value: { puppy: arrival.value.puppy, young: arrival.value.young, adult: arrival.value.adult }, ...arrival.meta },
  };
  for (const v of [general.meals.value.puppy.map((x) => x.meals), general.meals.value.young, general.meals.value.senior].flat()) {
    if (!Number.isInteger(v)) throw new Error('feed_windows: meal count missing');
  }

  const forBreed = (id) => {
    const g = DOG_GAME[id];
    const read = ([ref, fn]) => {
      const r = R.game(ref, fn);
      if (typeof r.value !== 'number' || !Number.isFinite(r.value)) throw new Error(`game value ${ref} for ${id} is not a number`);
      return r;
    };
    const adult = read(g.adult_minutes);
    const senior = read(g.senior_minutes);
    const seniorFrom = read(g.senior_from);
    const learning = read(g.learning);
    const adultSteps = adult.value * spm.value;
    const seniorSteps = senior.value * spm.value;
    const goal = R.entry(g.step_goal_check).value;
    if (goal !== adultSteps) throw new Error(`${g.step_goal_check} = ${goal}, but ${adult.value} min × ${spm.value} = ${adultSteps}`);
    if (g.senior_steps_check && R.entry(g.senior_steps_check).steps !== seniorSteps) throw new Error(`${g.senior_steps_check}.steps ≠ ${seniorSteps}`);
    const stepsPerMonth = perMonth[0] * spm.value;
    const capMonth = Math.ceil(adult.value / perMonth[0]);
    const a = general.arrival.value;
    const youngSteps = Math.min(a.young * stepsPerMonth, adultSteps);
    const values = [general.steps_per_exercise_minute, general.puppy_exercise_minutes_per_age_month, general.meals, general.arrival].map(({ value, ...m }) => m);
    values.push(adult.meta, senior.meta, seniorFrom.meta, learning.meta);
    return {
      stages: [
        { stage: 'puppy', starts: { arrival_months: a.puppy }, meals: mealSteps(general.meals.value.puppy, a.puppy), activity: { kind: 'steps_growing', per_month: stepsPerMonth, first: Math.min(a.puppy * stepsPerMonth, adultSteps), cap: adultSteps, cap_month: capMonth } },
        { stage: 'young', starts: { month: a.young }, meals: [{ from_months: null, meals: general.meals.value.young }], activity: youngSteps === adultSteps ? { kind: 'steps', value: adultSteps } : { kind: 'steps_range', from: youngSteps, to: adultSteps } },
        { stage: 'adult', starts: { month: a.adult }, meals: [{ from_months: null, meals: general.meals.value.adult }], activity: { kind: 'steps', value: adultSteps } },
        { stage: 'senior', starts: { month: seniorFrom.value }, meals: [{ from_months: null, meals: general.meals.value.senior }], activity: { kind: 'steps', value: seniorSteps } },
      ],
      adult_activity: { kind: 'steps', value: adultSteps },
      rules: [
        { key: 'steps_rule', params: { minutes: adult.value, steps: adultSteps, per_minute: spm.value } },
        { key: 'learning', params: { multiplier: learning.value } },
        { key: 'senior_share', params: { months: seniorFrom.value } },
        { key: 'walk_sensor', params: {} },
      ],
      values,
      basis_source_ids: [...new Set(values.flatMap((v) => v.basis_source_ids))].sort(bySourceId),
    };
  };
  return { forBreed };
}

function dogFacets(facts) {
  const first = (field) => facts.find((f) => f.field === field);
  const facets = {};
  const size = first('size');
  if (size) facets.size = size.value;
  const ex = first('exercise');
  // "up to N" (≤ N) falls into the bucket below N: up to 1 hour → under_1h ("Up to 1 hour").
  const atOrAbove = (n) => (ex.qualifier === 'up_to' ? ex.value > n : ex.value >= n);
  if (ex) facets.exercise = atOrAbove(120) ? 'over_2h' : atOrAbove(60) ? 'h1_2' : 'under_1h';
  const gr = first('grooming_frequency');
  if (gr) facets.grooming = { once_a_week: 'weekly', more_than_once_a_week: 'several_weekly', daily: 'daily', every_day: 'daily' }[gr.value] ?? 'other';
  return facets;
}

// ─── cats ────────────────────────────────────────────────────────────────────

function catGeneralFacts(R) {
  const meals = [
    ['kitten_6_12_weeks', 'kitten_6_12_weeks'],
    ['kitten_3_6_months', 'kitten_3_6_months'],
    ['kitten_6_12_months', 'kitten_6_12_months'],
    ['adult', 'adult'],
    ['senior', 'senior'],
  ].map(([key, context]) => R.fact(`general.meals_per_day.${key}`, { group: 'feeding', field: 'meals', kind: 'quantity', unit: 'meals_per_day', qualifier: 'exact', context }, Number));
  const stage = (key, context, unit, qualifier, fn) => R.fact(`general.life_stages.${key}`, { group: 'stages', field: 'stage', kind: 'quantity', unit, qualifier, context }, fn);
  return [
    R.fact('general.play.sessions', { group: 'play', field: 'play_sessions', kind: 'statement' }, (v, ref) => {
      const m = /(\d+)\s*[–-]\s*(\d+)\s*×\s*(\d+)\s*[–-]\s*(\d+)\s*min/.exec(String(v));
      if (!m) throw new Error(`cannot read play sessions from ${ref}`);
      return { sessions: [Number(m[1]), Number(m[2])], minutes: [Number(m[3]), Number(m[4])] };
    }),
    R.fact('general.play.kittens_more', { group: 'play', field: 'kittens_play_more', kind: 'statement' }, () => true),
    R.fact('general.litter.scoop_frequency', { group: 'litter', field: 'litter_scoop', kind: 'quantity', unit: 'times_per_day' }, parseRange),
    R.fact('general.litter.full_change', { group: 'litter', field: 'litter_full_change', kind: 'quantity', unit: 'days', qualifier: 'every' }, Number),
    R.fact('general.litter.adult_pee', { group: 'litter', field: 'litter_pee', kind: 'quantity', unit: 'times_per_day', context: 'adult' }, parseRange),
    R.fact('general.litter.adult_poo', { group: 'litter', field: 'litter_poo', kind: 'quantity', unit: 'times_per_day', context: 'adult' }, parseRange),
    R.fact('general.scratching.natural', { group: 'scratching', field: 'scratching_natural', kind: 'statement' }, () => true),
    R.fact('general.water.fresh_daily', { group: 'water', field: 'water_fresh', kind: 'statement' }, () => true),
    R.fact('general.water.need', { group: 'water', field: 'water_need', kind: 'quantity', unit: 'ml_per_kg_day' }, parseRange),
    ...meals,
    stage('kitten', 'kitten', 'months', 'until', (v, ref) => {
      const m = /(\d+)\s*months/.exec(String(v));
      if (!m) throw new Error(`cannot read ${ref}`);
      return Number(m[1]);
    }),
    stage('young_adult', 'young_adult', 'years', 'span', parseRange),
    stage('mature_adult', 'mature_adult', 'years', 'span', parseRange),
    stage('senior', 'senior', 'years', 'from', (v, ref) => {
      const m = /^(\d+)\s*years/.exec(String(v));
      if (!m) throw new Error(`cannot read ${ref}`);
      return Number(m[1]);
    }),
    R.fact('general.sleep.adult', { group: 'stages', field: 'sleep', kind: 'quantity', unit: 'hours_per_day', context: 'adult' }, parseRange),
  ];
}

function catBreedFacts(R, id) {
  if (id !== 'maine_coon') throw new Error(`no cat fact spec for ${id}`);
  const male = R.fact('maine_coon.adult_weight.tica_male', {}, parseRange);
  const female = R.fact('maine_coon.adult_weight.tica_female', {}, parseRange);
  if (male.source_ids.join() !== female.source_ids.join()) throw new Error('Maine Coon weights cite different sources');
  return {
    facts: [
      { group: 'size', field: 'weight', kind: 'quantity', unit: 'kg', value: { male: male.value, female: female.value }, source_ids: male.source_ids, ref: `${male.ref} + ${female.ref}`, confidence: male.confidence },
      R.fact('maine_coon.growth_end', { group: 'stages', field: 'growth_end', kind: 'quantity', unit: 'months', qualifier: 'about' }, parseRange),
      R.fact('maine_coon.lifespan.expectancy_at_birth', { group: 'lifespan', field: 'lifespan', kind: 'quantity', unit: 'years', qualifier: 'expectancy' }, Number),
      R.fact('maine_coon.grooming.range', { group: 'grooming', field: 'grooming_sources_differ', kind: 'statement' }, () => ({ from: 'daily', to: 'weekly' })),
    ],
    health: [],
  };
}

function catGame(R) {
  const conv = String(R.entry('meta.conventions.game_time'));
  if (!/^1 real week = 1 month of cat age/.test(conv)) throw new Error('cat data.json: game_time convention changed');
  const n = (ref) => {
    const r = R.game(ref);
    if (typeof r.value !== 'number') throw new Error(`cat game value ${ref} is not a number`);
    return r;
  };
  const meal = (k) => n(`general.meals_per_day.${k}`);
  const m4 = meal('kitten_6_12_weeks');
  const m3 = meal('kitten_3_6_months');
  const m2 = meal('kitten_6_12_months');
  const mA = meal('adult');
  const mS = meal('senior');
  const young = n('general.arrival_age.young');
  const adult = n('general.arrival_age.adult');
  const senior = n('general.arrival_age.senior');
  const playK = n('general.play.game_sessions_kitten');
  const playA = n('general.play.game_sessions_adult');
  const gap = n('general.play.game_min_gap');
  const usesA = n('general.litter.game_uses_per_day_adult');
  const scoop = n('general.litter.game_scoop_deadline');
  const change = n('general.litter.full_change');
  const kittenTable = [{ from: 0, meals: m4.value }, { from: 3, meals: m3.value }, { from: 6, meals: m2.value }];
  const shared = [m4, m3, m2, mA, mS, young, adult, senior, playK, playA, gap, usesA, scoop, change];

  const forBreed = (id) => {
    const arrival = id === 'maine_coon' ? n('maine_coon.arrival_age_kitten') : n('general.arrival_age.kitten_min');
    const extra = [];
    const rules = [
      { key: 'play_instead_of_steps', params: { sessions: playA.value, gap_minutes: gap.value } },
      { key: 'litter_rule', params: { uses: usesA.value, scoop_hours: scoop.value, change_days: change.value } },
      { key: 'scratching_after_missed_play', params: {} },
    ];
    if (id === 'maine_coon') {
      const groom = n('maine_coon.grooming.game_sessions_per_week');
      extra.push(groom);
      rules.push({ key: 'grooming_rule', params: { per_week: groom.value } });
    }
    const values = [...shared, arrival, ...extra].map((r) => r.meta);
    return {
      stages: [
        { stage: 'puppy', starts: { arrival_months: arrival.value }, meals: mealSteps(kittenTable, arrival.value), activity: { kind: 'play_sessions', value: playK.value } },
        { stage: 'young', starts: { month: young.value }, meals: [{ from_months: null, meals: mA.value }], activity: { kind: 'play_sessions', value: playA.value } },
        { stage: 'adult', starts: { month: adult.value }, meals: [{ from_months: null, meals: mA.value }], activity: { kind: 'play_sessions', value: playA.value } },
        { stage: 'senior', starts: { month: senior.value }, meals: [{ from_months: null, meals: mS.value }], activity: { kind: 'play_sessions', value: playA.value } },
      ],
      adult_activity: { kind: 'play_sessions', value: playA.value },
      rules,
      values,
      basis_source_ids: [...new Set(values.flatMap((v) => v.basis_source_ids))].sort(bySourceId),
    };
  };
  return { forBreed };
}

// ─── shared parts ────────────────────────────────────────────────────────────

function readInput(root, rel) {
  return readFileSync(resolve(root, rel), 'utf8');
}

function sha256(text) {
  return createHash('sha256').update(text).digest('hex');
}

function exportSuitability(php, readers, sources, breedIds) {
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
  for (const id of breedIds) {
    const b = php.breeds[id] ?? { suits: [], consider: [] };
    const out = {};
    for (const kind of ['suits', 'consider']) {
      out[kind] = (b[kind] ?? []).map((t) => {
        if (vocabulary[t.tag] !== kind) throw new Error(`breed_suitability.php: ${id} ${kind} tag ${t.tag} is not a ${kind} tag`);
        const ids = [...t.source_ids].sort(bySourceId);
        for (const s of ids) if (!sources[s]) throw new Error(`breed_suitability.php: ${id} ${t.tag} cites unknown ${s}`);
        for (const ref of t.refs) {
          if (typeof readers.dog.entry(ref)?.source_id !== 'string') throw new Error(`breed_suitability.php: ${id} ${t.tag} ref ${ref} is unsourced`);
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
  const dogData = JSON.parse(texts.dog_data);
  const catData = JSON.parse(texts.cat_data);
  const readers = { dog: reader(dogData, 'dog-data/data.json', ''), cat: reader(catData, 'cat-data/data.json', 'cat-data:') };
  const dogSources = parseSourcesTable(texts.dog_sources);
  const catSources = parseSourcesTable(texts.cat_sources);
  for (const id of Object.keys(catSources)) if (dogSources[id]) throw new Error(`source id ${id} in both tables`);
  const sources = { ...dogSources, ...catSources };
  const names = { en: JSON.parse(texts.family_en).breeds, sl: JSON.parse(texts.family_sl).breeds };
  const allBreedIds = SPECIES.flatMap((s) => s.breeds.map((b) => b.id));
  const suitability = exportSuitability(parsePhpReturn(texts.suitability), readers, sources, allBreedIds);
  const game = { dog: dogGame(readers.dog), cat: catGame(readers.cat) };
  const general = { dog: dogGeneralFacts(readers.dog), cat: catGeneralFacts(readers.cat) };

  const nameOf = (id) => {
    const n = { en: names.en?.[id], sl: names.sl?.[id] };
    if (!n.en || !n.sl) throw new Error(`family.json: no breed name for ${id} (en/sl)`);
    return n;
  };

  const portraits = readPortraitManifest(texts.portraits ?? null);

  const breeds = [];
  const species = SPECIES.map((sp) => {
    const seen = { en: new Set(), sl: new Set() };
    sp.breeds.forEach((spec, order) => {
      const { facts, health } = sp.id === 'dog' ? dogBreedFacts(readers.dog, spec.id) : catBreedFacts(readers.cat, spec.id);
      for (const f of facts) if (!sp.fact_groups.includes(f.group)) throw new Error(`${spec.id}: fact group ${f.group} is not a ${sp.id} group`);
      const name = nameOf(spec.id);
      const slug = { en: slugify(name.en), sl: slugify(name.sl) };
      for (const l of ['en', 'sl']) {
        if (seen[l].has(slug[l]) || RESERVED_SLUGS.includes(slug[l])) throw new Error(`${spec.id}: slug "${slug[l]}" (${l}) is taken or reserved`);
        seen[l].add(slug[l]);
      }
      if (!['in_app', 'coming_soon', 'info_only'].includes(spec.availability)) throw new Error(`${spec.id}: bad availability`);
      const compare = {};
      for (const field of sp.compare_fields) {
        if (field === 'game_activity') continue;
        const f = facts.find((x) => x.field === field);
        if (f) compare[field] = facts.indexOf(f);
      }
      const g = spec.availability === 'info_only' ? null : game[sp.id].forBreed(spec.id);
      const entry = {
        id: spec.id,
        species: sp.id,
        order,
        availability: spec.availability,
        name,
        slug,
        synonyms: spec.synonyms,
        facets: sp.id === 'dog' ? dogFacets(facts) : {},
        facts,
        compare,
        health,
        suitability: suitability.breeds[spec.id],
        game: g,
        portrait: portraitFor(portraits, sp.id, spec.id, slug.en),
      };
      entry.source_ids = [...collectSourceIds([entry, general[sp.id]])].sort(bySourceId);
      breeds.push(entry);
    });
    const ours = breeds.filter((b) => b.species === sp.id);
    const status = ours.some((b) => b.availability === 'in_app') ? 'available' : ours.some((b) => b.availability === 'coming_soon') ? 'coming_soon' : 'info_only';
    const free = game[sp.id].forBreed(sp.free_plan);
    return {
      id: sp.id,
      slug: sp.slug,
      name: sp.name,
      status,
      breed_count: ours.length,
      fact_groups: sp.fact_groups,
      facets: sp.facets,
      compare_fields: sp.compare_fields,
      general_facts: general[sp.id],
      free_plan: { id: sp.free_plan, name: nameOf(sp.free_plan), adult_activity: free.adult_activity, basis_source_ids: free.basis_source_ids },
    };
  });

  for (const id of portraits.keys()) {
    if (!breeds.some((b) => b.id === id)) throw new Error(`${PORTRAIT_MANIFEST}: ${id} is not a breed of the register`);
  }

  const used = [...collectSourceIds([breeds, species])].sort(bySourceId);
  const sourceList = used.map((id) => {
    if (!sources[id]) throw new Error(`unknown source ${id}`);
    return sources[id];
  });

  const inputs = {};
  for (const [k, rel] of Object.entries(INPUTS)) inputs[k] = { path: rel, sha256: sha256(texts[k]) };
  inputs.portraits = { path: PORTRAIT_MANIFEST, sha256: texts.portraits == null ? null : sha256(texts.portraits) };

  return {
    schema_version: SCHEMA_VERSION,
    generator: 'scripts/export-breed-registry.mjs (DataVallis/pet-prep)',
    note: 'Facts carry source_ids (sources[]); game.* values are PetPrep game rules (David decisions), not veterinary advice. Health items are for information only, not vet-reviewed. Do not edit by hand — re-run the export.',
    data_compiled: { dog: dogData.meta.compiled, cat: catData.meta.compiled },
    inputs,
    suitability_vocabulary: suitability.vocabulary,
    suitability_labels: {
      en: exportLabels(JSON.parse(texts.labels_en), 'en', suitability.vocabulary),
      sl: exportLabels(JSON.parse(texts.labels_sl), 'sl', suitability.vocabulary),
    },
    species,
    breeds,
    sources: sourceList,
  };
}

export function readInputs(root = ROOT) {
  const texts = {};
  for (const [k, rel] of Object.entries(INPUTS)) texts[k] = readInput(root, rel);
  texts.portraits = existsSync(resolve(root, PORTRAIT_MANIFEST)) ? readInput(root, PORTRAIT_MANIFEST) : null;
  return texts;
}

// ─── breed portraits ─────────────────────────────────────────────────────────

/** manifest.json (backend BreedPortraitService) → Map(breed id → entry); null text → empty. */
export function readPortraitManifest(text) {
  const out = new Map();
  if (text == null) return out;
  const m = JSON.parse(text);
  if (m?.schema_version !== 1 || !Array.isArray(m.portraits)) throw new Error(`${PORTRAIT_MANIFEST}: expected schema_version 1 with portraits[]`);
  for (const p of m.portraits) {
    if (typeof p?.breed !== 'string' || out.has(p.breed)) throw new Error(`${PORTRAIT_MANIFEST}: bad or duplicate breed ${p?.breed}`);
    out.set(p.breed, p);
  }
  return out;
}

/** The export's `portrait` for one breed: { file (basename), width, height, kind } or null. */
function portraitFor(portraits, speciesId, breedId, slugEn) {
  const p = portraits.get(breedId);
  if (!p) return null;
  const where = `${PORTRAIT_MANIFEST} ${breedId}`;
  if (p.species !== speciesId) throw new Error(`${where}: species ${p.species}, expected ${speciesId}`);
  if (!PORTRAIT_KINDS.includes(p.kind)) throw new Error(`${where}: unknown kind ${p.kind}`);
  const m = /^([a-z]+)\/([a-z0-9-]+)\.(webp|png|jpg)$/.exec(String(p.file));
  if (!m || m[1] !== speciesId || m[2] !== slugEn) throw new Error(`${where}: file must be ${speciesId}/${slugEn}.<webp|png|jpg>, got ${p.file}`);
  for (const k of ['width', 'height']) {
    if (!Number.isInteger(p[k]) || p[k] <= 0) throw new Error(`${where}: ${k} must be a positive integer`);
  }
  return { file: basename(p.file), width: p.width, height: p.height, kind: p.kind };
}

/**
 * Copies for `--copy-portraits DIR`: every breed with a portrait →
 * from docs/research/breed-portraits/<species>/<file> to DIR/animals/<species slug en>/<file>.
 */
export function portraitCopies(registry, publicDir, root = ROOT) {
  const slugOf = Object.fromEntries(registry.species.map((s) => [s.id, s.slug.en]));
  return registry.breeds
    .filter((b) => b.portrait)
    .map((b) => ({
      breed: b.id,
      from: resolve(root, PORTRAITS_DIR, b.species, b.portrait.file),
      to: resolve(publicDir, 'animals', slugOf[b.species], b.portrait.file),
    }));
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
  const withPortrait = reg.breeds.filter((b) => b.portrait).length;
  console.log(`export-breed-registry: wrote ${outRel} (${reg.species.length} species, ${reg.breeds.length} breeds, ${reg.sources.length} sources, ${withPortrait} portraits)`);
  const copyIdx = args.indexOf('--copy-portraits');
  if (copyIdx >= 0) {
    const dir = args[copyIdx + 1];
    if (!dir || dir.startsWith('--')) throw new Error('--copy-portraits needs the website public/ directory');
    const publicDir = isAbsolute(dir) ? dir : resolve(process.cwd(), dir);
    if (!existsSync(publicDir)) throw new Error(`--copy-portraits: ${publicDir} does not exist`);
    const copies = portraitCopies(reg, publicDir);
    for (const c of copies) {
      if (!existsSync(c.from)) throw new Error(`portrait file missing: ${c.from}`);
      mkdirSync(dirname(c.to), { recursive: true });
      copyFileSync(c.from, c.to);
      console.log(`export-breed-registry: copied ${c.breed} → ${c.to}`);
    }
    console.log(`export-breed-registry: ${copies.length} portrait(s) copied (label them "AI-generated photo" on the site)`);
  }
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
