#!/usr/bin/env node
// Generates the basic »Ali si vedel?« facts of every register breed (M5-R09e, David 2026-11-27:
// »za vse pasme rabimo facts … na strani mamo za vsako pasmo polno podatkov«).
//
//   node scripts/generate-breed-facts.mjs            # write docs/research/breed-facts + the shipped copy
//   node scripts/generate-breed-facts.mjs --check    # exit 1 when a committed file is stale
//
// Source: docs/research/breed-registry.json (the same sourced values the website register shows)
// + the verbatim quote of each value's `ref` in docs/research/{dog,cat}-data/data.json.
//
// Rules (docs/research/breed-facts/README.md §Generated facts):
//  * One fact per registry value, written from a fixed EN + SL sentence template.
//  * A fact is written ONLY when the quote itself carries what the sentence says: every number
//    of the sentence must stand in the quote (with its unit where the template needs it), and a
//    category word (size, coat, frequency, country) must stand in the quote. Otherwise the fact
//    is skipped — never written as a draft, never guessed.
//  * Keys are stable: `<breed>-auto-<field>`. `verified_by` = this script; `verified_hash` =
//    the README content hash of the generated text.
//  * Breeds with hand-written facts (any key that is not `-auto-`) are left untouched.
//  * No health facts, no Coren rank (Wikipedia, tier C), no medians (they mislead).
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
export const REGISTRY = 'docs/research/breed-registry.json';
export const DOCS_DIR = 'docs/research/breed-facts';
export const SHIPPED_DIR = 'backend/database/data/breed-facts';
export const VERIFIED_BY = 'generate-breed-facts.mjs (numbers and terms checked against the quote)';
export const TITLE_MAX = 120;
export const WHY_MAX = 140;

const read = (p) => JSON.parse(readFileSync(resolve(ROOT, p), 'utf8'));

/** README content hash — exactly JSON.stringify of the listed fields. */
export function contentHash(breed, f) {
  const v = [breed, f.category, f.audience, f.title.en, f.title.sl, f.why ? f.why.en : null, f.why ? f.why.sl : null,
    f.source_id, f.source_name, f.source_url, f.quote, f.game_rule_key ?? null];
  return createHash('sha256').update(JSON.stringify(v), 'utf8').digest('hex');
}

/** Resolves a registry `ref` (`dobermann.height.fci`, `cat-data:bengal.grooming.range`). */
export function resolveRef(ref, data) {
  let src = data.dog;
  let path = ref;
  if (ref.startsWith('cat-data:')) { src = data.cat; path = ref.slice('cat-data:'.length); }
  let o = src;
  for (const part of path.split('.')) {
    if (o === null || typeof o !== 'object' || !(part in o)) return null;
    o = o[part];
  }
  return o && typeof o === 'object' ? o : null;
}

// ---- quote checks -------------------------------------------------------------------------
const norm = (s) => String(s).toLowerCase().replace(/[‒-―]/g, '-').replace(/ /g, ' ').replace(/(\d),(\d)/g, '$1.$2');
/** Every number as it stands in the text ("3,5" → 3.5). */
export const numbersIn = (s) => (norm(s).match(/\d+(?:\.\d+)?/g) ?? []).map(Number);
const hasNumber = (quote, n) => numbersIn(quote).includes(Number(n));
const hasAll = (quote, ns) => ns.every((n) => hasNumber(quote, n));
const hasWord = (quote, re) => re.test(norm(quote));
/** "68–72" or "39" for an equal pair. */
const range = ([a, b]) => (a === b ? fmt(a) : `${fmt(a)}–${fmt(b)}`);
const fmt = (n) => String(n);
const fmtSl = (n) => String(n).replace('.', ',');
const rangeSl = ([a, b]) => (a === b ? fmtSl(a) : `${fmtSl(a)}–${fmtSl(b)}`);
const isPair = (v) => Array.isArray(v) && v.length === 2 && v.every((n) => typeof n === 'number');
/** »pasme doberman«, but »pasme Chesapeake Bay retriver« (a name with a proper noun keeps its capitals). */
const lcFirst = (s) => (/\p{Lu}/u.test(s.slice(1)) ? s : s.charAt(0).toLowerCase() + s.slice(1));

// ---- vocabularies ---------------------------------------------------------------------------
const SIZE = {
  toy: { en: 'toy-sized', sl: 'zelo majhna', re: /\btoy\b/ },
  small: { en: 'small', sl: 'majhna', re: /\bsmall\b/ },
  small_medium: { en: 'small to medium-sized', sl: 'majhna do srednje velika', re: /small.*medium/ },
  medium: { en: 'medium-sized', sl: 'srednje velika', re: /\bmedium\b/ },
  medium_large: { en: 'medium to large', sl: 'srednje velika do velika', re: /medium.*large/ },
  large: { en: 'large', sl: 'velika', re: /\blarge\b/ },
  giant: { en: 'giant', sl: 'zelo velika', re: /\bgiant\b/ },
};
const COAT = {
  short: { en: 'short', sl: 'kratko', re: /\bshort\b/ },
  medium: { en: 'medium-length', sl: 'srednje dolgo', re: /\bmedium\b/ },
  long: { en: 'long', sl: 'dolgo', re: /\blong\b/ },
};
const GROOMING = {
  once_a_week: { en: 'once a week', sl: 'enkrat na teden', re: /once[ -]a[ -]week|\bweekly\b/, not: /more than once|at least|minimum|twice|daily|every day/, perWeek: (n) => n === 1 },
  more_than_once_a_week: { en: 'more than once a week', sl: 'več kot enkrat na teden', re: /more than once a week/, perWeek: (n) => n >= 2 },
  at_least_once_a_week: { en: 'at least once a week', sl: 'vsaj enkrat na teden', re: /at least once a week|at least weekly/, perWeek: (n) => n >= 1 },
  every_day: { en: 'every day', sl: 'vsak dan', re: /every day|\bdaily\b/, perWeek: (n) => n === 7 },
};
/** FCI origin code → names; `re` must match the quote (the FCI writes countries in capitals). */
const COUNTRY = {
  GB: { en: 'Great Britain', sl: 'Velike Britanije', re: /great britain/ },
  DE: { en: 'Germany', sl: 'Nemčije', re: /\bgermany\b/ },
  FR: { en: 'France', sl: 'Francije', re: /\bfrance\b/ },
  IT: { en: 'Italy', sl: 'Italije', re: /\bitaly\b/ },
  BE: { en: 'Belgium', sl: 'Belgije', re: /\bbelgium\b/ },
  IE: { en: 'Ireland', sl: 'Irske', re: /\bireland\b/ },
  CH: { en: 'Switzerland', sl: 'Švice', re: /\bswitzerland\b/ },
  JP: { en: 'Japan', sl: 'Japonske', re: /\bjapan\b/ },
  RU: { en: 'Russia', sl: 'Rusije', re: /\brussia\b/ },
  HU: { en: 'Hungary', sl: 'Madžarske', re: /\bhungary\b/ },
  CA: { en: 'Canada', sl: 'Kanade', re: /\bcanada\b/ },
  AU: { en: 'Australia', sl: 'Avstralije', re: /\baustralia\b/ },
  MX: { en: 'Mexico', sl: 'Mehike', re: /\bmexico\b/ },
  CN: { en: 'China', sl: 'Kitajske', re: /\bchina\b/ },
  CU: { en: 'Cuba', sl: 'Kube', re: /\bcuba\b/ },
  ZA: { en: 'South Africa', sl: 'Južne Afrike', re: /south africa/ },
  PT: { en: 'Portugal', sl: 'Portugalske', re: /\bportugal\b/ },
  HR: { en: 'Croatia', sl: 'Hrvaške', re: /\bcroatia\b/ },
  TR: { en: 'Turkey', sl: 'Turčije', re: /\bturkey\b|t[uü]rkiye/ },
  MG: { en: 'Madagascar', sl: 'Madagaskarja', re: /\bmadagascar\b/ },
  AF: { en: 'Afghanistan', sl: 'Afganistana', re: /\bafghanistan\b/ },
  NO: { en: 'Norway', sl: 'Norveške', re: /\bnorway\b/ },
  FI: { en: 'Finland', sl: 'Finske', re: /\bfinland\b/ },
  US: { en: 'the United States', sl: 'ZDA', re: /united states|u\.s\.a|\busa\b/ },
};
const QUALIFIER = {
  up_to: { en: 'up to', sl: 'do', re: /up to|maximum|no more than/ },
  more_than: { en: 'more than', sl: 'več kot', re: /more than|over / },
  at_least: { en: 'at least', sl: 'vsaj', re: /at least|minimum/ },
  about: { en: 'about', sl: 'približno', re: /around|about|approx|roughly/ },
};
/** Slovenian amount after the qualifier (»do« takes the genitive, the others the accusative). */
function durationSl(qualifier, hours, minutes) {
  if (hours === 1) return qualifier === 'up_to' ? '1 ure' : '1 uro';
  if (hours === 2) return qualifier === 'up_to' ? '2 ur' : '2 uri';
  // »potrebuje 30 minut gibanja« — the same form after every qualifier
  return `${minutes} minut`;
}
const timesEn = (n) => (n === 1 ? 'once' : n === 2 ? 'twice' : `${n} times`);
const timesSl = (n) => (n === 1 ? 'enkrat' : n === 2 ? 'dvakrat' : `${n}-krat`);

// ---- templates ------------------------------------------------------------------------------
// Each returns null (skip) or { category, title: {en, sl}, why?, game_rule_key? }.
// `b` = { en, sl, slLc, species, noun: {en, sl, slGenPl}, game }.
const TEMPLATES = {
  exercise(f, quote, b) {
    const qualifier = QUALIFIER[f.qualifier];
    if (!qualifier || f.unit !== 'min_per_day' || typeof f.value !== 'number') return null;
    // The qualifier is written only when the quote says it; a quote with another one is skipped.
    const said = Object.keys(QUALIFIER).filter((k) => QUALIFIER[k].re.test(norm(quote)));
    if (said.length > 1 || (said.length === 1 && said[0] !== f.qualifier)) return null;
    const q = said.length === 1 ? qualifier : { en: '', sl: '' };
    const kind = said.length === 1 ? f.qualifier : 'plain';
    const n = norm(quote).replace(/\b(an|one) hour\b/g, '1 hour').replace(/\btwo hours\b/g, '2 hours');
    let en; let sl;
    if (f.value === 60 && /\b1 hour\b/.test(n)) { en = '1 hour'; sl = durationSl(kind, 1); }
    else if (f.value === 120 && /\b2 hours\b/.test(n)) { en = '2 hours'; sl = durationSl(kind, 2); }
    else if (new RegExp(`\\b${f.value} ?min`).test(n)) { en = `${f.value} minutes`; sl = durationSl(kind, null, f.value); }
    else return null;
    const fact = {
      category: 'exercise',
      title: {
        en: `The ${b.en} needs ${q.en} ${en} of exercise every day.`.replace(/ +/g, ' '),
        sl: `${b.sl} potrebuje vsak dan ${q.sl} ${sl} gibanja.`.replace(/ +/g, ' '),
      },
    };
    const steps = b.game?.adult_activity?.kind === 'steps' ? b.game.adult_activity.value : null;
    const rule = b.game?.rules?.find((r) => r.key === 'steps_rule')?.params;
    // Only when the game goal is exactly the source's minutes (a goal David set apart, e.g. the Labrador's, gets no »that's why«).
    if (b.species === 'dog' && Number.isInteger(steps) && rule?.minutes === f.value && rule.minutes * rule.per_minute === steps) {
      fact.why = {
        en: `That's why your adult dog's daily goal is ${steps.toLocaleString('en-US')} steps.`,
        sl: `Zato je dnevni cilj tvojega odraslega psa ${steps.toLocaleString('de-DE')} korakov.`,
      };
      fact.game_rule_key = 'daily_steps';
    }
    return fact;
  },
  grooming_frequency(f, quote, b) {
    const g = GROOMING[f.value];
    if (!g || !hasWord(quote, g.re) || (g.not && hasWord(quote, g.not))) return null;
    const fact = {
      category: 'grooming',
      title: { en: `The ${b.en}'s coat needs grooming ${g.en}.`, sl: `${b.sl} potrebuje nego dlake ${g.sl}.` },
    };
    const perWeek = b.game?.rules?.find((r) => r.key === 'grooming_rule')?.params?.per_week;
    if (Number.isInteger(perWeek) && perWeek > 0 && g.perWeek(perWeek)) { // only when the game rule says the same as the source
      fact.why = {
        en: `That's why you brush your ${b.noun.en} ${timesEn(perWeek)} a week in the app.`,
        sl: `Zato v aplikaciji ${b.noun.slAcc} češeš ${timesSl(perWeek)} na teden.`,
      };
      fact.game_rule_key = 'grooming_per_week';
    }
    return fact;
  },
  size(f, quote, b) {
    const s = SIZE[f.value];
    if (!s || !hasWord(quote, s.re)) return null;
    // "Size: Medium-Large" must not read as plain "medium" or "large".
    const words = ['toy', 'small', 'medium', 'large', 'giant'].filter((w) => new RegExp(`\\b${w}\\b`).test(norm(quote)));
    if (!f.value.includes('_') && words.length !== 1) return null;
    return {
      category: 'body',
      title: { en: `The ${b.en} is a ${s.en} ${b.noun.en} breed.`, sl: `${b.sl} je ${s.sl} pasma ${b.noun.slGenPl}.` },
    };
  },
  fci_standard(f, quote, b) {
    const c = COUNTRY[f.value?.origin];
    if (!c || !hasWord(quote, c.re)) return null;
    return { category: 'origin', title: { en: `The ${b.en} comes from ${c.en}.`, sl: `${b.sl} izvira iz ${c.sl}.` } };
  },
  coat(f, quote, b) {
    if (!Array.isArray(f.value) || f.value.length !== 1) return null;
    const c = COAT[f.value[0]];
    if (!c || !hasWord(quote, c.re)) return null;
    return { category: 'body', title: { en: `The ${b.en} has a ${c.en} coat.`, sl: `${b.sl} ima ${c.sl} dlako.` } };
  },
  height(f, quote, b) {
    if (b.species !== 'dog') return null;
    return wrap('body', measure(f, quote, b, {
      unit: 'cm', re: /\d ?cm\b/,
      both: (m, w, ms, ws) => ({ en: `In the ${b.en}, males are ${m} cm tall at the withers and females ${w} cm.`, sl: `Samci pasme ${b.slLc} so v vihru visoki ${ms} cm, samice ${ws} cm.` }),
      male: (m, ms) => ({ en: `In the ${b.en}, males are ${m} cm tall at the withers.`, sl: `Samec pasme ${b.slLc} je v vihru visok ${ms} cm.` }),
      any: (m, ms) => ({ en: `The ${b.en} is ${m} cm tall at the withers.`, sl: `Psi pasme ${b.slLc} so v vihru visoki ${ms} cm.` }),
    }));
  },
  weight(f, quote, b) {
    if (f.qualifier) return null; // about / mean / median / up to / ideal: not a plain range
    return wrap('body', measure(f, quote, b, {
      unit: 'kg', re: /\d ?kgs?\b/,
      both: (m, w, ms, ws) => ({ en: `In the ${b.en}, males weigh ${m} kg and females ${w} kg.`, sl: `Samci pasme ${b.slLc} tehtajo ${ms} kg, samice ${ws} kg.` }),
      male: (m, ms) => ({ en: `In the ${b.en}, males weigh ${m} kg.`, sl: `Samec pasme ${b.slLc} tehta ${ms} kg.` }),
      any: (m, ms) => ({ en: `The ${b.en} weighs ${m} kg.`, sl: `${b.sl} tehta ${ms} kg.` }),
    }));
  },
  lifespan(f, quote, b) {
    if (f.unit !== 'years' || !/year|lifespan|life span|live/.test(norm(quote))) return null;
    let en; let sl; let upper;
    if (f.qualifier === 'more_than' && Number.isInteger(f.value) && hasNumber(quote, f.value) && /over|more than/.test(norm(quote))) {
      en = `more than ${f.value} years`; sl = `več kot ${f.value} let`; upper = f.value + 1;
    } else if (f.qualifier === 'span' && isPair(f.value) && f.value.every(Number.isInteger) && f.value[0] < f.value[1] && hasAll(quote, f.value)) {
      en = `${f.value[0]} to ${f.value[1]} years`; sl = `od ${f.value[0]} do ${f.value[1]} let`; upper = f.value[1];
    } else return null;
    if (upper < 5) return null; // »let« only fits 5 and more
    return {
      category: 'body',
      // A short life is not something to pop up on a child's screen about their own pet.
      audience: upper <= 10 && f.qualifier === 'span' ? 'parent' : 'child',
      title: { en: `The ${b.en} usually lives ${en}.`, sl: `${b.sl} običajno živi ${sl}.` },
    };
  },
  growth_end(f, quote, b) {
    if (b.species !== 'dog' || f.qualifier !== 'about' || f.unit !== 'months' || !isPair(f.value) || !hasAll(quote, f.value)
      || !/month/.test(norm(quote)) || f.value[1] < 5) return null;
    return {
      category: 'body',
      title: {
        en: `A dog the size of the ${b.en} reaches its adult weight at about ${range(f.value)} months.`,
        sl: `Pes takšne velikosti, kot je ${b.slLc}, doseže odraslo težo pri približno ${rangeSl(f.value)} mesecih.`,
      },
    };
  },
};

const wrap = (category, title) => (title ? { category, title } : null);

/** Height / weight: sexes when the quote carries both, the male line alone, or one range for all. */
function measure(f, quote, b, t) {
  if (f.unit !== t.unit || !t.re.test(norm(quote))) return null;
  const v = f.value;
  const male = /\bmales?\b|\bdogs?\b/.test(norm(quote));
  const female = /\bfemales?\b|\bbitch(es)?\b/.test(norm(quote));
  // A limit ("not exceeding", "up to", "ideal", "approximately") is not a plain measurement.
  if (/not exceed|maximum|up to|under |ideal|approx|about|around|average/.test(norm(quote))) return null;
  // A quote that lists varieties ("Standard 20-27cm, Miniature 13-18cm") is not one measurement.
  if (/\b(standard|miniature|toy|variety|varieties)\b/.test(norm(quote))) return null;
  if (isPair(v)) {
    if (male || female || v[0] >= v[1] || !hasAll(quote, v)) return null;
    return t.any(range(v), rangeSl(v));
  }
  if (!v || !isPair(v.male) || !isPair(v.female) || v.male[0] >= v.male[1]) return null;
  const same = v.male[0] === v.female[0] && v.male[1] === v.female[1];
  if (same) {
    if (male || female || !hasAll(quote, v.male)) return null;
    return t.any(range(v.male), rangeSl(v.male));
  }
  if (!male || !hasAll(quote, v.male)) return null;
  if (female && v.female[0] < v.female[1] && hasAll(quote, v.female)) return t.both(range(v.male), range(v.female), rangeSl(v.male), rangeSl(v.female));
  if (female) return null; // the quote names females but not with the register's numbers
  return t.male(range(v.male), rangeSl(v.male));
}

/** Display order: what the child does in the game first. */
export const ORDER = ['exercise', 'grooming_frequency', 'size', 'fci_standard', 'coat', 'height', 'weight', 'lifespan', 'growth_end'];
const NOUN = {
  dog: { en: 'dog', sl: 'pes', slAcc: 'svojega psa', slGenPl: 'psov' },
  cat: { en: 'cat', sl: 'mačka', slAcc: 'svojo muco', slGenPl: 'mačk' },
};

/** The generated facts of one register breed (pure). */
export function factsForBreed(breed, registry, data) {
  const sources = new Map(registry.sources.map((s) => [s.id, s]));
  const b = {
    en: breed.name.en, sl: breed.name.sl, slLc: lcFirst(breed.name.sl), species: breed.species,
    noun: NOUN[breed.species], game: breed.game,
  };
  const out = [];
  for (const field of ORDER) {
    const f = breed.facts.find((x) => x.field === field);
    if (!f || !b.noun) continue;
    const entry = resolveRef(f.ref, data);
    const quote = typeof entry?.quote === 'string' ? entry.quote.trim() : '';
    const source = sources.get(entry?.source_id);
    if (!quote || !source || !f.source_ids.includes(source.id) || !/^https?:\/\/\S+$/i.test(source.url ?? '')) continue;
    const t = TEMPLATES[field](f, quote, b);
    if (!t) continue;
    if (!t.title.en || !t.title.sl || t.title.en.length > TITLE_MAX || t.title.sl.length > TITLE_MAX) continue;
    if (t.why && (t.why.en.length > WHY_MAX || t.why.sl.length > WHY_MAX)) { delete t.why; delete t.game_rule_key; }
    const fact = {
      key: `${breed.id}-auto-${field}`,
      category: t.category,
      audience: t.audience ?? 'child',
      title: t.title,
      why: t.why ?? null,
      source_id: source.id,
      source_name: source.publisher,
      source_url: source.url,
      quote,
      game_rule_key: t.game_rule_key ?? null,
      sort: (ORDER.indexOf(field) + 1) * 10,
      status: 'verified',
      verified_by: VERIFIED_BY,
    };
    fact.verified_hash = contentHash(breed.id, fact);
    out.push(fact);
  }
  return out;
}

const isGenerated = (file) => file.facts.every((f) => typeof f.key === 'string' && f.key.includes('-auto-'));

/** { '<breed>.json': content } for every breed the generator owns. */
export function generateAll() {
  const registry = read(REGISTRY);
  const data = { dog: read(registry.inputs.dog_data.path), cat: read(registry.inputs.cat_data.path) };
  const files = {};
  const skipped = [];
  for (const breed of registry.breeds) {
    const name = `${breed.id}.json`;
    const path = resolve(ROOT, DOCS_DIR, name);
    if (existsSync(path) && !isGenerated(JSON.parse(readFileSync(path, 'utf8')))) { skipped.push(`${breed.id} (hand-written)`); continue; }
    const facts = factsForBreed(breed, registry, data);
    if (facts.length === 0) { skipped.push(`${breed.id} (no fact passes the quote check)`); continue; }
    files[name] = `${JSON.stringify({ breed: breed.id, facts }, null, 2)}\n`;
  }
  return { files, skipped };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const check = process.argv.includes('--check');
  const { files, skipped } = generateAll();
  let stale = 0;
  let facts = 0;
  for (const [name, content] of Object.entries(files)) {
    facts += JSON.parse(content).facts.length;
    for (const dir of [DOCS_DIR, SHIPPED_DIR]) {
      const path = resolve(ROOT, dir, name);
      const current = existsSync(path) ? readFileSync(path, 'utf8') : null;
      if (current === content) continue;
      stale += 1;
      if (!check) writeFileSync(path, content);
    }
  }
  // A generated file whose breed no longer yields facts is reported, never deleted (keys are permanent).
  const orphans = readdirSync(resolve(ROOT, DOCS_DIR)).filter((n) => n.endsWith('.json') && !(n in files)
    && isGenerated(JSON.parse(readFileSync(resolve(ROOT, DOCS_DIR, n), 'utf8'))));
  console.log(`${Object.keys(files).length} breeds, ${facts} facts${skipped.length ? `; skipped: ${skipped.join(', ')}` : ''}`);
  if (orphans.length) console.log(`generated files without facts now (kept): ${orphans.join(', ')}`);
  if (check && stale) { console.error(`${stale} file(s) stale — run: node scripts/generate-breed-facts.mjs`); process.exit(1); }
  if (!check) console.log(stale ? `wrote ${stale} file(s)` : 'up to date');
}
