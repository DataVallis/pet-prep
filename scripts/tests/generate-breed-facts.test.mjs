// Tests for scripts/generate-breed-facts.mjs (basic »Ali si vedel?« facts of every breed, M5-R09e).
// Run: node --test scripts/tests/generate-breed-facts.test.mjs
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { test } from 'node:test';
import {
  DOCS_DIR, REGISTRY, ROOT, SHIPPED_DIR, TITLE_MAX, VERIFIED_BY, WHY_MAX,
  contentHash, factsForBreed, generateAll, numbersIn,
} from '../generate-breed-facts.mjs';

const { files } = generateAll();
const registry = JSON.parse(readFileSync(resolve(ROOT, REGISTRY), 'utf8'));
const all = Object.entries(files).map(([name, content]) => [name, JSON.parse(content)]);
/** Words the templates turn into digits ("an hour" → 1, "two hours" → 2). */
const quoteNumbers = (q) => numbersIn(q.toLowerCase().replace(/\b(an|one) hour\b/g, '1 hour').replace(/\btwo hours\b/g, '2 hours'));

test('the committed files are what the generator writes (deterministic)', () => {
  assert.deepEqual(generateAll().files, files);
  for (const [name, content] of Object.entries(files)) {
    for (const dir of [DOCS_DIR, SHIPPED_DIR]) {
      const path = resolve(ROOT, dir, name);
      // A breed added to the register without its file is not an error here (the runbook step
      // adds it); a committed file that differs is.
      if (!existsSync(path)) continue;
      assert.equal(readFileSync(path, 'utf8'), content, `${dir}/${name} is stale — run: node scripts/generate-breed-facts.mjs`);
    }
  }
});

test('covers the register breeds and never touches hand-written files', () => {
  assert.ok(all.length >= registry.breeds.length - 5, `only ${all.length} of ${registry.breeds.length} breeds`);
  assert.ok(!('border_collie.json' in files) && !('maine_coon.json' in files), 'hand-written files are left alone');
  const dobermann = all.find(([n]) => n === 'dobermann.json')?.[1];
  assert.ok(dobermann && dobermann.facts.length >= 5, 'the Dobermann has its basic facts (David 2026-11-27)');
  assert.ok(dobermann.facts.some((f) => f.audience === 'child' && f.game_rule_key === 'daily_steps'));
});

test('every number of a sentence stands in its verbatim quote, in both languages', () => {
  for (const [name, file] of all) {
    for (const f of file.facts) {
      const inQuote = quoteNumbers(f.quote);
      const en = numbersIn(f.title.en);
      assert.ok(en.every((n) => inQuote.includes(n)), `${name} ${f.key}: "${f.title.en}" has a number the quote lacks: ${f.quote}`);
      assert.deepEqual(numbersIn(f.title.sl).sort(), [...en].sort(), `${f.key}: EN and SL numbers differ`);
    }
  }
});

test('every fact is a valid, verified import row', () => {
  const keys = new Set();
  for (const [name, file] of all) {
    assert.equal(`${file.breed}.json`, name);
    assert.deepEqual(Object.keys(file), ['breed', 'facts']);
    for (const f of file.facts) {
      assert.ok(f.key.startsWith(`${file.breed}-auto-`) && /^[a-z0-9_]+-[a-z0-9_-]+$/.test(f.key) && f.key.length <= 100, f.key);
      assert.ok(!keys.has(f.key), `duplicate key ${f.key}`);
      keys.add(f.key);
      assert.ok(['origin', 'body', 'exercise', 'grooming'].includes(f.category), `${f.key}: ${f.category}`); // never health / family
      assert.ok(['child', 'parent'].includes(f.audience));
      for (const lang of ['en', 'sl']) {
        assert.ok(f.title[lang].length > 0 && f.title[lang].length <= TITLE_MAX, `${f.key} title.${lang}`);
        assert.ok(f.why === null || (f.why[lang].length > 0 && f.why[lang].length <= WHY_MAX), `${f.key} why.${lang}`);
        assert.doesNotMatch(f.title[lang], / {2}|undefined|null|NaN/, f.key);
      }
      assert.equal(f.why === null, f.game_rule_key === null, `${f.key}: why and game_rule_key go together`);
      assert.match(f.source_url, /^https?:\/\/\S+$/);
      assert.ok(f.source_id && f.source_name && f.quote.trim() === f.quote && f.quote.length > 0);
      assert.equal(f.status, 'verified');
      assert.equal(f.verified_by, VERIFIED_BY);
      assert.equal(f.verified_hash, contentHash(file.breed, f));
      assert.ok(Number.isInteger(f.sort));
    }
  }
});

test('the content hash is the importer\'s (README): the hand-written files verify', () => {
  for (const name of ['border_collie.json', 'maine_coon.json']) {
    const file = JSON.parse(readFileSync(resolve(ROOT, DOCS_DIR, name), 'utf8'));
    for (const f of file.facts.filter((x) => x.verified_hash)) assert.equal(contentHash(file.breed, f), f.verified_hash, f.key);
  }
});

// ---- templates on a made-up register ------------------------------------------------------
const source = { id: 'S1', tier: 'B', publisher: 'Test Kennel Club', title: 'x', url: 'https://example.test/breed' };
function run(fact, entry, game = { adult_activity: { kind: 'steps', value: 12000 }, rules: [{ key: 'steps_rule', params: { minutes: 120, steps: 12000, per_minute: 100 } }, { key: 'grooming_rule', params: { per_week: 1 } }] }) {
  const breed = { id: 'testhound', species: 'dog', name: { en: 'Testhound', sl: 'Testni hrt' }, game, facts: [{ source_ids: ['S1'], ref: 'testhound.x', ...fact }] };
  return factsForBreed(breed, { sources: [source] }, { dog: { testhound: { x: { source_id: 'S1', ...entry } } }, cat: {} });
}

test('writes a fact only when the quote carries it', () => {
  const exercise = { field: 'exercise', unit: 'min_per_day', qualifier: 'more_than', value: 120 };
  const [ok] = run(exercise, { quote: 'Exercise: More than 2 hours per day' });
  assert.equal(ok.title.en, 'The Testhound needs more than 2 hours of exercise every day.');
  assert.equal(ok.title.sl, 'Testni hrt potrebuje vsak dan več kot 2 uri gibanja.');
  assert.equal(ok.why.sl, 'Zato je dnevni cilj tvojega odraslega psa 12.000 korakov.');
  assert.equal(ok.key, 'testhound-auto-exercise');
  // no quote, another number, another qualifier → nothing
  assert.deepEqual(run(exercise, { quote: null }), []);
  assert.deepEqual(run(exercise, { quote: 'Exercise: Up to 1 hour per day' }), []);
  assert.deepEqual(run(exercise, { quote: 'Up to 2 hours per day' }), []);
  // the quote names no qualifier → the sentence has none either
  assert.equal(run(exercise, { quote: '2 hours' })[0].title.en, 'The Testhound needs 2 hours of exercise every day.');
  // a game goal that is not the source's minutes (e.g. the Labrador's 9,000 steps) → no »that's why«
  const lab = run(exercise, { quote: 'Exercise: More than 2 hours per day' }, { adult_activity: { kind: 'steps', value: 9000 }, rules: [{ key: 'steps_rule', params: { minutes: 90, steps: 9000, per_minute: 100 } }] });
  assert.equal(lab[0].why, null);
  assert.equal(lab[0].game_rule_key, null);
  // a source not listed by the register fact → nothing
  assert.deepEqual(run({ ...exercise, source_ids: ['S2'] }, { quote: 'Exercise: More than 2 hours per day' }), []);
});

test('size, coat, grooming, origin, measurements and lifespan follow the quote', () => {
  assert.equal(run({ field: 'size', value: 'large' }, { quote: 'Size: Large' })[0].title.sl, 'Testni hrt je velika pasma psov.');
  assert.deepEqual(run({ field: 'size', value: 'medium' }, { quote: 'Size: Medium-Large' }), []);
  assert.deepEqual(run({ field: 'size', value: 'medium' }, { quote: 'Section 1 Large and medium sized Terriers.' }), []);
  assert.equal(run({ field: 'coat', value: ['short'] }, { quote: 'Coat length: Short' })[0].title.sl, 'Testni hrt ima kratko dlako.');
  assert.deepEqual(run({ field: 'coat', value: ['short', 'long'] }, { quote: 'Short or long' }), []);

  const weekly = run({ field: 'grooming_frequency', value: 'once_a_week' }, { quote: 'Grooming: Once a week' })[0];
  assert.equal(weekly.why.sl, 'Zato v aplikaciji svojega psa češeš enkrat na teden.');
  assert.deepEqual(run({ field: 'grooming_frequency', value: 'once_a_week' }, { quote: 'at minimum a weekly brush' }), []);
  // the source says more than once, the game brushes once → the fact without a »that's why«
  assert.equal(run({ field: 'grooming_frequency', value: 'more_than_once_a_week' }, { quote: 'Grooming: More than once a week' })[0].why, null);

  assert.equal(run({ field: 'fci_standard', value: { origin: 'DE' } }, { quote: 'Country of origin: GERMANY' })[0].title.sl, 'Testni hrt izvira iz Nemčije.');
  assert.deepEqual(run({ field: 'fci_standard', value: { origin: 'DE' } }, { quote: 'Group 2, Section 1' }), []);
  assert.deepEqual(run({ field: 'fci_standard', value: { origin: 'BE-FR' } }, { quote: 'BELGIUM / FRANCE' }), []);

  const height = { field: 'height', unit: 'cm', value: { male: [68, 72], female: [63, 68] } };
  assert.equal(run(height, { quote: 'Height at the withers: Males: 68 – 72 cm.' })[0].title.sl, 'Samec pasme testni hrt je v vihru visok 68–72 cm.');
  assert.equal(run(height, { quote: 'Males: 68–72 cm. Females: 63–68 cm.' })[0].title.en, 'In the Testhound, males are 68–72 cm tall at the withers and females 63–68 cm.');
  assert.deepEqual(run(height, { quote: 'Males 70 cm.' }), []);
  assert.deepEqual(run(height, { quote: 'Not exceeding 72 cm in dogs, from 68 cm' }), []);
  assert.deepEqual(run({ field: 'height', unit: 'cm', value: [20, 27] }, { quote: 'Standard 20-27cm, Miniature 13-18cm' }), []);
  assert.equal(run({ field: 'weight', unit: 'kg', value: [8.5, 10.5] }, { quote: '8.5kg – 10.5kg.' })[0].title.sl, 'Testni hrt tehta 8,5–10,5 kg.');
  assert.deepEqual(run({ field: 'weight', unit: 'kg', qualifier: 'about', value: [8, 10] }, { quote: '8-10 kg' }), []);

  assert.equal(run({ field: 'lifespan', unit: 'years', qualifier: 'more_than', value: 10 }, { quote: 'Lifespan: Over 10 years' })[0].title.sl, 'Testni hrt običajno živi več kot 10 let.');
  const short = run({ field: 'lifespan', unit: 'years', qualifier: 'span', value: [6, 8] }, { quote: 'the average lifespan is just 6–8 years.' })[0];
  assert.equal(short.audience, 'parent', 'a short life is not shown to the child');
  assert.deepEqual(run({ field: 'lifespan', unit: 'years', qualifier: 'median', value: 9.2 }, { quote: 'Testhound 9.2 6.2-11.0 37' }), []);
  assert.deepEqual(run({ field: 'coren_rank', value: 5 }, { quote: '5 | Testhound' }), [], 'no Coren rank (Wikipedia source)');
});
