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
import { APP_RELEASE, breedsFromSpeciesTs, releasedBreeds } from '../record-app-release.mjs';

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

test('i18n inputs: only the strings the export reads are hashed (an unrelated app string never makes the register stale)', () => {
  const texts = readInputs();
  const base = JSON.stringify(buildRegistry(texts));
  const edit = (key, fn) => {
    const json = JSON.parse(texts[key]);
    fn(json);
    return { ...texts, [key]: JSON.stringify(json, null, 4) };
  };

  // Unrelated keys and reformatting / key order change nothing.
  for (const key of ['labels_en', 'labels_sl', 'family_en', 'family_sl']) {
    assert.equal(JSON.stringify(buildRegistry(edit(key, (j) => (j.__unrelated_test_key = 'any new app text')))), base, key);
    assert.equal(registry.inputs[key].path, INPUTS[key]);
    assert.match(registry.inputs[key].covers, /\S/);
    assert.match(registry.inputs[key].sha256, /^[0-9a-f]{64}$/);
  }
  // A breed name of another app screen (not in the register) changes nothing either.
  assert.equal(JSON.stringify(buildRegistry(edit('family_en', (j) => (j.breeds.__not_a_breed = 'X')))), base);

  // A string the export reads changes the hash (and the exported value).
  const tag = registry.suitability_vocabulary ? Object.keys(registry.suitability_labels.en.tags)[0] : null;
  assert.ok(tag);
  const relabelled = buildRegistry(
    edit('labels_en', (j) => {
      for (const kind of Object.values(j.breedSuitability)) if (kind && typeof kind === 'object' && tag in kind) kind[tag] = 'Changed label';
    }),
  );
  assert.notEqual(relabelled.inputs.labels_en.sha256, registry.inputs.labels_en.sha256);
  assert.equal(relabelled.suitability_labels.en.tags[tag], 'Changed label');
  const renamed = buildRegistry(edit('family_sl', (j) => (j.breeds.beagle = 'Bigl (test)')));
  assert.notEqual(renamed.inputs.family_sl.sha256, registry.inputs.family_sl.sha256);
  // Data inputs keep the whole-file hash.
  assert.equal(registry.inputs.dog_data.covers, undefined);
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
  assert.equal(byId.labrador_retriever.slug.sl, 'labradorec');
  assert.equal(byId.golden_retriever.slug.sl, 'zlati-prinasalec');
  assert.deepEqual(byId.french_bulldog.slug, { en: 'french-bulldog', sl: 'francoski-buldog' });
  assert.deepEqual(byId.german_shepherd.slug, { en: 'german-shepherd-dog', sl: 'nemski-ovcar' }); // EN = RKC name
  assert.deepEqual(byId.cavalier_king_charles_spaniel.slug, { en: 'cavalier-king-charles-spaniel', sl: 'kavalir-king-charles-spanjel' });
  assert.deepEqual(byId.beagle.slug, { en: 'beagle', sl: 'bigl' });
  assert.deepEqual(byId.standard_poodle.slug, { en: 'poodle-standard', sl: 'veliki-koder' });
  assert.deepEqual(byId.dachshund.slug, { en: 'dachshund', sl: 'jazbecar' });
  assert.deepEqual(byId.australian_shepherd.slug, { en: 'australian-shepherd', sl: 'avstralski-ovcar' });
  assert.deepEqual(byId.havanese.slug, { en: 'havanese', sl: 'havanski-bison' });
  assert.deepEqual(byId.west_highland_white_terrier.slug, { en: 'west-highland-white-terrier', sl: 'zahodnoskotski-beli-terier' });
  assert.deepEqual(byId.bernese_mountain_dog.slug, { en: 'bernese-mountain-dog', sl: 'bernski-plansarski-pes' });
  assert.deepEqual(byId.siberian_husky.slug, { en: 'siberian-husky', sl: 'sibirski-haski' });
  assert.deepEqual(byId.rottweiler.slug, { en: 'rottweiler', sl: 'rotvajler' });
  assert.deepEqual(byId.bulldog.slug, { en: 'bulldog', sl: 'angleski-buldog' });
  assert.deepEqual(byId.german_shorthaired_pointer.slug, { en: 'german-shorthaired-pointer', sl: 'nemski-kratkodlaki-pticar' });
  assert.deepEqual(byId.yorkshire_terrier.slug, { en: 'yorkshire-terrier', sl: 'yorkshirski-terier' });
  assert.deepEqual(byId.cane_corso.slug, { en: 'cane-corso', sl: 'cane-corso' });
  assert.deepEqual(byId.pembroke_welsh_corgi.slug, { en: 'pembroke-welsh-corgi', sl: 'welsh-corgi-pembroke' });
  assert.deepEqual(byId.dobermann.slug, { en: 'dobermann', sl: 'doberman' });
  assert.deepEqual(byId.boxer.slug, { en: 'boxer', sl: 'boksar' });
  assert.deepEqual(byId.miniature_schnauzer.slug, { en: 'miniature-schnauzer', sl: 'pritlikavi-snavcer' });
  assert.deepEqual(byId.shih_tzu.slug, { en: 'shih-tzu', sl: 'shih-tzu' });
  assert.deepEqual(byId.great_dane.slug, { en: 'great-dane', sl: 'nemska-doga' });
  assert.deepEqual(byId.pomeranian.slug, { en: 'pomeranian', sl: 'pomeranec' });
  assert.deepEqual(byId.boston_terrier.slug, { en: 'boston-terrier', sl: 'bostonski-terier' });
  assert.deepEqual(byId.miniature_american_shepherd.slug, { en: 'miniature-american-shepherd', sl: 'mali-ameriski-ovcar' });
  assert.deepEqual(byId.chihuahua.slug, { en: 'chihuahua', sl: 'civava' });
  assert.deepEqual(byId.english_springer_spaniel.slug, { en: 'english-springer-spaniel', sl: 'angleski-springer-spanjel' });
  assert.deepEqual(byId.shetland_sheepdog.slug, { en: 'shetland-sheepdog', sl: 'setlandski-ovcar' });
  assert.deepEqual(byId.brittany.slug, { en: 'brittany', sl: 'bretonski-pticar' });
  assert.deepEqual(byId.american_cocker_spaniel.slug, { en: 'american-cocker-spaniel', sl: 'ameriski-koker-spanjel' });
  assert.deepEqual(byId.belgian_malinois.slug, { en: 'belgian-malinois', sl: 'belgijski-ovcar-malinois' });
  assert.deepEqual(byId.basset_hound.slug, { en: 'basset-hound', sl: 'baset' });
  assert.deepEqual(byId.hungarian_vizsla.slug, { en: 'hungarian-vizsla', sl: 'madzarski-kratkodlaki-pticar' });
  assert.deepEqual(byId.english_cocker_spaniel.slug, { en: 'english-cocker-spaniel', sl: 'angleski-koker-spanjel' });
  assert.deepEqual(byId.maltese.slug, { en: 'maltese', sl: 'maltezan' });
  assert.deepEqual(byId.pug.slug, { en: 'pug', sl: 'mops' });
  assert.deepEqual(byId.rough_collie.slug, { en: 'rough-collie', sl: 'dolgodlaki-skotski-ovcar' });
  assert.deepEqual(byId.mastiff.slug, { en: 'mastiff', sl: 'angleski-mastif' });
  assert.deepEqual(byId.shiba_inu.slug, { en: 'shiba-inu', sl: 'siba-inu' });
  assert.deepEqual(byId.rhodesian_ridgeback.slug, { en: 'rhodesian-ridgeback', sl: 'rodezijski-ridgeback' });
  assert.deepEqual(byId.papillon.slug, { en: 'papillon', sl: 'papijon' });
  assert.deepEqual(byId.portuguese_water_dog.slug, { en: 'portuguese-water-dog', sl: 'portugalski-vodni-pes' });
  assert.deepEqual(byId.bichon_frise.slug, { en: 'bichon-frise', sl: 'kodrasti-bison' });
  assert.deepEqual(byId.newfoundland.slug, { en: 'newfoundland', sl: 'novofundlandec' });
  assert.deepEqual(byId.dalmatian.slug, { en: 'dalmatian', sl: 'dalmatinec' });
  assert.deepEqual(byId.australian_cattle_dog.slug, { en: 'australian-cattle-dog', sl: 'avstralski-govedar' });
  assert.deepEqual(byId.whippet.slug, { en: 'whippet', sl: 'mali-angleski-hrt' });
  assert.deepEqual(byId.british_shorthair.slug, { en: 'british-shorthair', sl: 'britanska-kratkodlaka-macka' });
  assert.deepEqual(byId.ragdoll.slug, { en: 'ragdoll', sl: 'ragdoll' });
  // M5-R10-16: "Siberian cat" in EN, so the slug never reads as the Siberian Husky's.
  assert.deepEqual(byId.siberian_cat.slug, { en: 'siberian-cat', sl: 'sibirska-macka' });
  assert.notEqual(byId.siberian_cat.slug.en, byId.siberian_husky.slug.en);
  // M5-R10-17
  assert.deepEqual(byId.norwegian_forest_cat.slug, { en: 'norwegian-forest-cat', sl: 'norveska-gozdna-macka' });
  // M5-R10-18
  assert.deepEqual(byId.bengal.slug, { en: 'bengal', sl: 'bengalska-macka' });
  assert.equal(slugify('Zlati prinašalec'), 'zlati-prinasalec');
});

// David 2026-10-13: the site shows what users really have — availability follows app-release.json.
test('availability is derived from the recorded app release', () => {
  const release = JSON.parse(readFileSync(resolve(ROOT, APP_RELEASE), 'utf8'));
  const released = releasedBreeds(release);
  const built = new Set(breedsFromSpeciesTs(readFileSync(resolve(ROOT, 'mobile/src/modules/species/species.ts'), 'utf8')));
  for (const b of registry.breeds) {
    if (b.availability === 'info_only') continue;
    assert.equal(b.availability, released.has(b.id) ? 'in_app' : 'coming_soon', `${b.id}`);
    // A breed can only be released if it is still in the app on main (no stale "in app").
    if (b.availability === 'in_app') assert.ok(built.has(b.id), `${b.id} released but not in BREED_SPECIES`);
  }
  for (const sp of registry.species) {
    const ours = registry.breeds.filter((b) => b.species === sp.id);
    assert.equal(sp.status === 'available', ours.some((b) => b.availability === 'in_app'), sp.id);
  }
});

test('a newly built breed that is not released yet exports as coming_soon', () => {
  const texts = readInputs();
  const release = JSON.parse(texts.app_release);
  const before = buildRegistry(texts).breeds.find((b) => b.id === 'border_collie');
  assert.equal(before.availability, release.breeds.includes('border_collie') ? 'in_app' : 'coming_soon');
  const unreleased = { ...release, breeds: release.breeds.filter((id) => id !== 'border_collie') };
  const after = buildRegistry({ ...texts, app_release: JSON.stringify(unreleased) });
  assert.equal(after.breeds.find((b) => b.id === 'border_collie').availability, 'coming_soon');
  assert.equal(after.breeds.find((b) => b.id === 'border_collie').game !== null, true, 'coming_soon keeps its game rules');
});

test('app-release.json is validated', () => {
  const texts = readInputs();
  const release = JSON.parse(texts.app_release);
  for (const bad of [{ kind: 'beta' }, { commit: 'main' }, { date: '12.10.2026' }, { breeds: [] }, { breeds: ['mutt', 'mutt'] }]) {
    assert.throws(() => buildRegistry({ ...texts, app_release: JSON.stringify({ ...release, ...bad }) }), /app-release\.json/);
  }
});

test('BREED_SPECIES parser reads the app breed list', () => {
  const src = "export const BREED_SPECIES: Readonly<Record<BreedType, Species>> = {\n  mutt: 'dog',\n  boxer: 'dog',\n  bengal: 'cat',\n};\n";
  assert.deepEqual(breedsFromSpeciesTs(src), ['mutt', 'boxer', 'bengal']);
  assert.throws(() => breedsFromSpeciesTs('export const OTHER = {};'), /BREED_SPECIES not found/);
  assert.throws(() => breedsFromSpeciesTs("export const BREED_SPECIES = {\n  mutt: 'dog',\n  mutt: 'dog',\n};\n"), /duplicate/);
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
    siberian_husky: { adult: 12000, senior: 9000, seniorFrom: 90, learning: 1 },
    rottweiler: { adult: 12000, senior: 9000, seniorFrom: 81, learning: 1.8 },
    bulldog: { adult: 6000, senior: 4500, seniorFrom: 88, learning: 0.5 },
    german_shorthaired_pointer: { adult: 12000, senior: 9000, seniorFrom: 121, learning: 1.5 },
    yorkshire_terrier: { adult: 3000, senior: 2300, seniorFrom: 122, learning: 1.2 },
    cane_corso: { adult: 12000, senior: 9000, seniorFrom: 84, learning: 1.0 },
    pembroke_welsh_corgi: { adult: 6000, senior: 4500, seniorFrom: 108, learning: 1.5 },
    dobermann: { adult: 12000, senior: 9000, seniorFrom: 83, learning: 1.9 },
    boxer: { adult: 12000, senior: 9000, seniorFrom: 102, learning: 1.0 },
    miniature_schnauzer: { adult: 6000, senior: 4500, seniorFrom: 104, learning: 1.5 },
    shih_tzu: { adult: 6000, senior: 4500, seniorFrom: 114, learning: 0.5 },
    great_dane: { adult: 12000, senior: 9000, seniorFrom: 54, learning: 1 },
    pomeranian: { adult: 3000, senior: 2300, seniorFrom: 108, learning: 1.5 },
    boston_terrier: { adult: 6000, senior: 4500, seniorFrom: 90, learning: 1 },
    miniature_american_shepherd: { adult: 6000, senior: 4500, seniorFrom: 108, learning: 1 },
    chihuahua: { adult: 3000, senior: 2300, seniorFrom: 108, learning: 0.7 },
    english_springer_spaniel: { adult: 12000, senior: 9000, seniorFrom: 122, learning: 1.5 },
    shetland_sheepdog: { adult: 6000, senior: 4500, seniorFrom: 90, learning: 1.8 },
    brittany: { adult: 12000, senior: 9000, seniorFrom: 90, learning: 1.5 },
    american_cocker_spaniel: { adult: 12000, senior: 9000, seniorFrom: 90, learning: 1.5 },
    belgian_malinois: { adult: 12000, senior: 9000, seniorFrom: 90, learning: 1.5 },
    basset_hound: { adult: 6000, senior: 4500, seniorFrom: 90, learning: 0.5 },
    hungarian_vizsla: { adult: 12000, senior: 9000, seniorFrom: 108, learning: 1.5 },
    english_cocker_spaniel: { adult: 6000, senior: 4500, seniorFrom: 103, learning: 1.5 },
    maltese: { adult: 3000, senior: 2300, seniorFrom: 108, learning: 0.7 },
    pug: { adult: 6000, senior: 4500, seniorFrom: 104, learning: 0.7 },
    rough_collie: { adult: 6000, senior: 4500, seniorFrom: 108, learning: 1.5 },
    mastiff: { adult: 6000, senior: 4500, seniorFrom: 81, learning: 0.5 },
    shiba_inu: { adult: 6000, senior: 4500, seniorFrom: 131, learning: 1 },
    rhodesian_ridgeback: { adult: 12000, senior: 9000, seniorFrom: 90, learning: 1 },
    papillon: { adult: 3000, senior: 2300, seniorFrom: 108, learning: 1.8 },
    portuguese_water_dog: { adult: 6000, senior: 4500, seniorFrom: 90, learning: 1 },
    bichon_frise: { adult: 3000, senior: 2300, seniorFrom: 108, learning: 1 },
    newfoundland: { adult: 6000, senior: 4500, seniorFrom: 99, learning: 1.2 },
    dalmatian: { adult: 12000, senior: 9000, seniorFrom: 119, learning: 1.2 },
    australian_cattle_dog: { adult: 12000, senior: 9000, seniorFrom: 126, learning: 1.8 },
    whippet: { adult: 6000, senior: 4500, seniorFrom: 121, learning: 1 },
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
  assert.deepEqual(fb.suitability.consider.map((t) => t.tag), ['brachycephalic_breathing', 'chases_small_pets']);
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
  const rows = JSON.parse(execFileSync('php', ['-r', code, resolve(ROOT, 'backend/vendor/autoload.php')], { encoding: 'utf8', cwd: resolve(ROOT, 'backend'), maxBuffer: 64 * 1024 * 1024 }));
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
      // M5-R13: both ways for dogs too — a seeded grooming row always has a register rule.
      const dogGroom = g.rules.find((r) => r.key === 'grooming_rule');
      if (dogGroom) assert.equal(row(b.id, 'all', 'grooming_sessions_per_week'), dogGroom.params.per_week, `${b.id} grooming`);
      else assert.ok(!rows.some((x) => x.breed_slug === b.slug.en && x.key === 'grooming_sessions_per_week'), `${b.slug.en}: grooming row without a register rule`);
    } else {
      assert.equal(row(b.id, 'puppy', 'play_sessions_per_day'), puppy.activity.value);
      assert.equal(row(b.id, 'adult', 'play_sessions_per_day'), adult.activity.value);
      const litter = g.rules.find((r) => r.key === 'litter_rule').params;
      assert.equal(row(b.id, 'adult', 'litter_uses_per_day'), litter.uses);
      assert.equal(row(b.id, 'all', 'litter_scoop_deadline_hours'), litter.scoop_hours);
      assert.equal(row(b.id, 'all', 'litter_full_change_days'), litter.change_days);
      assert.equal(row(b.id, 'all', 'play_min_gap_minutes'), g.rules.find((r) => r.key === 'play_instead_of_steps').params.gap_minutes);
      const groom = g.rules.find((r) => r.key === 'grooming_rule');
      // Both ways: a seeded grooming row always has a register rule (David 2026-10-11).
      if (groom) assert.equal(row(b.id, 'all', 'grooming_sessions_per_week'), groom.params.per_week);
      else {
        const slug = b.slug.en;
        assert.ok(!rows.some((x) => x.breed_slug === slug && x.key === 'grooming_sessions_per_week'), `${slug}: grooming row without a register rule`);
      }
    }
  }
  // Free plans (no page, but their numbers are shown).
  const mutt = rows.find((x) => x.breed_slug === 'mutt' && x.stage === 'adult' && x.key === 'exercise_minutes_per_day');
  assert.equal(mutt.value * 100, registry.species[0].free_plan.adult_activity.value);
  const cat = rows.find((x) => x.breed_slug === 'domestic-cat' && x.stage === 'adult' && x.key === 'play_sessions_per_day');
  assert.equal(cat.value, registry.species[1].free_plan.adult_activity.value);
});

test('the app\'s brushed-cat and brushed-dog lists equal the seeded grooming rows (David 2026-10-11 / 12: nothing forgotten)', { skip: !(hasPhp && hasVendor) && 'php or backend/vendor missing' }, () => {
  const code = `require $argv[1]; echo json_encode(Database\\Seeders\\BreedStageParamsSeeder::allRows());`;
  const rows = JSON.parse(execFileSync('php', ['-r', code, resolve(ROOT, 'backend/vendor/autoload.php')], { encoding: 'utf8', cwd: resolve(ROOT, 'backend'), maxBuffer: 64 * 1024 * 1024 }));
  // Seeded slugs → BreedType values (the German Shepherd's slug is german-shepherd-dog, the Poodle's poodle-standard).
  const slugToBreed = { 'german-shepherd-dog': 'german_shepherd', 'poodle-standard': 'standard_poodle' };
  const seeded = [...new Set(rows.filter((x) => x.key === 'grooming_sessions_per_week').map((x) => slugToBreed[x.breed_slug] ?? x.breed_slug.replaceAll('-', '_')))].sort();
  const species = readFileSync(resolve(ROOT, 'mobile/src/modules/species/species.ts'), 'utf8');
  const list = (name) => {
    const m = new RegExp(`${name}: readonly BreedType\\[\\] = \\[([^\\]]*)\\]`).exec(species);
    assert.ok(m, `${name} not found in mobile species.ts`);
    return [...m[1].matchAll(/'([a-z_]+)'/g)].map((x) => x[1]);
  };
  const cats = list('GROOMING_CAT_BREEDS').sort();
  const dogs = list('GROOMING_DOG_BREEDS').sort();
  assert.deepEqual([...cats, ...dogs].sort(), seeded);
  assert.deepEqual(cats, ['bengal', 'british_shorthair', 'maine_coon', 'norwegian_forest_cat', 'ragdoll', 'siberian_cat']);
  // M5-R13: every dog breed of the app except the mutt and the Border Collie (no grooming statement).
  assert.equal(dogs.length, 49);
  assert.ok(!dogs.includes('mutt') && !dogs.includes('border_collie'));
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
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['back_spine', 'sheds', 'needs_mental_stimulation', 'chases_small_pets']);
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

test('every dog breed has a Coren rank fact except the explicitly unranked Havanese, Cane Corso, Miniature American Shepherd, Shiba Inu and Portuguese Water Dog', () => {
  const unranked = ['havanese', 'cane_corso', 'miniature_american_shepherd', 'shiba_inu', 'portuguese_water_dog'];
  for (const b of registry.breeds.filter((x) => x.species === 'dog')) {
    const has = b.facts.some((f) => f.field === 'coren_rank');
    assert.equal(has, !unranked.includes(b.id), `${b.id} coren_rank fact`);
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
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['sensitive_skin', 'sheds', 'frequent_grooming', 'chews_when_bored', 'chases_small_pets']);
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

test('Siberian Husky (M5-R10-13): more than 2 hours, FCI weight by sex, FCI group 5 US, RKC "over 10 years", Coren 45, medium-large, secure_fencing chip', () => {
  const d = registry.breeds.find((b) => b.id === 'siberian_husky');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S160']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 270, group: 5, section: 1, origin: 'US' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [20.5, 28], female: [15.5, 23] }, ['S158']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [53.5, 60], female: [50.5, 56] }, ['S158']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 10, ['S160']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'growth_end').value, [12, 15]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 45);
  // RKC "Size: Medium-Large" → a new size value.
  assert.deepEqual(d.facets, { size: 'medium_large', exercise: 'over_2h', grooming: 'several_weekly' });
  // 10 min × age reaches the 120-minute cap at 12 months; senior 90 min from 90 months.
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3].activity, { kind: 'steps', value: 9000 });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family', 'large_home']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'secure_fencing', 'chews_when_bored', 'sheds', 'frequent_grooming', 'chases_small_pets']);
  assert.deepEqual(d.health.map((h) => h.key), ['hip_dysplasia', 'inherited_eye_disease', 'epilepsy']);
  const json = JSON.stringify(d);
  // No Finnish average, no screening percentages, never "hypoallergenic", never merle.
  assert.doesNotMatch(json, /finland|9\.75|mcmillan_2024|hypoallergenic|odds ratio|merle|brindle|85-95/i);
});

test('British Shorthair (M5-R10-14): paid cat, kitten at 3 months, CFA weight by sex, growth 3–5 years, C14 "British" life expectancy, weekly comb, grooming 1× per week (David 2026-10-11)', () => {
  const bsh = registry.breeds.find((b) => b.id === 'british_shorthair');
  assert.equal(bsh.species, 'cat');
  const fact = (field) => bsh.facts.find((f) => f.field === field);
  assert.deepEqual([fact('weight').value, fact('weight').source_ids], [{ male: [4.1, 7.7], female: [3.2, 5.4] }, ['C27']]);
  assert.deepEqual([fact('growth_end').value, fact('growth_end').source_ids], [[36, 60], ['C27', 'C28']]);
  assert.deepEqual([fact('lifespan').value, fact('lifespan').qualifier, fact('lifespan').source_ids], [9.58, 'expectancy', ['C14']]);
  assert.deepEqual([fact('grooming_frequency').value, fact('grooming_frequency').source_ids], ['at_least_once_a_week', ['C28']]);
  assert.deepEqual(bsh.health, []);
  assert.deepEqual(bsh.suitability, { suits: [], consider: [] });
  // Game: the general cat rules, a pedigree kitten at 3 months, no grooming routine (CAT_SPEC Q8).
  assert.equal(bsh.game.stages[0].starts.arrival_months, 3);
  assert.deepEqual(bsh.game.stages.map((s) => s.starts.month ?? null), [null, 12, 84, 120]);
  assert.deepEqual(bsh.game.adult_activity, { kind: 'play_sessions', value: 2 });
  assert.equal(bsh.game.rules.find((r) => r.key === 'grooming_rule').params.per_week, 1); // David 2026-10-11
  assert.ok(bsh.game.values.some((v) => v.ref === 'cat-data:british_shorthair.arrival_age_kitten' && /pravilo runbooka, M5-R10-14/.test(v.decision)));
  // The cited sources are in the register (C26, the standard, feeds only the AI appearance).
  for (const id of ['C14', 'C27', 'C28']) assert.ok(registry.sources.some((s) => s.id === id), id);
});

// ─── breeder checklist (M5-R11-06) ───────────────────────────────────────────

const checklistData = () => JSON.parse(readFileSync(resolve(ROOT, INPUTS.checklist_data), 'utf8'));

test('checklist: every breed has a status, every species a general list, every K source is a K-id', () => {
  for (const b of registry.breeds) {
    assert.ok(['listed', 'none_recommended', 'not_found', 'not_checked'].includes(b.breeder_checklist.status), b.id);
    for (const t of b.breeder_checklist.tests) {
      assert.ok(['minimum', 'best_practice', 'other_scheme'].includes(t.level), `${b.id}.${t.key}`);
      for (const s of t.source_ids) assert.ok(b.source_ids.includes(s), `${b.id} lists ${s}`);
    }
  }
  for (const sp of registry.species) {
    assert.ok(sp.buyer_checklist.length > 0, sp.id);
    for (const it of sp.buyer_checklist) for (const s of it.source_ids) assert.ok(registry.sources.some((x) => x.id === s), s);
  }
  for (const id of Object.keys(parseSourcesTable(readFileSync(resolve(ROOT, INPUTS.checklist_sources), 'utf8')))) assert.match(id, /^K\d+$/);
});

test('checklist: known answers (vet feedback examples, Westie, British Shorthair)', () => {
  const byId = Object.fromEntries(registry.breeds.map((b) => [b.id, b.breeder_checklist]));
  const keys = (id, level) => byId[id].tests.filter((t) => t.level === level).map((t) => t.key);
  assert.deepEqual(keys('cavalier_king_charles_spaniel', 'minimum'), ['dna_cc_de', 'dna_ef', 'heart_scheme']);
  assert.deepEqual(keys('cavalier_king_charles_spaniel', 'other_scheme'), ['cm_sm_mri']);
  assert.deepEqual(keys('german_shepherd', 'minimum'), ['elbow_scheme', 'hip_scheme']);
  assert.equal(byId.west_highland_white_terrier.status, 'none_recommended');
  assert.deepEqual(byId.west_highland_white_terrier.basis_source_ids, ['S144']);
  assert.equal(byId.british_shorthair.status, 'not_found');
  // two quotes of one source = one source id (microchip by 8 weeks + report within 7 days, both K4)
  const dogGeneral = Object.fromEntries(registry.species.find((s) => s.id === 'dog').buyer_checklist.map((i) => [i.key, i.source_ids]));
  assert.deepEqual(dogGeneral.microchip_registration, ['K4']);
  // national rules carry their country; universal items carry none (M5-R11-06b, international site)
  const dogItems = registry.species.find((s) => s.id === 'dog').buyer_checklist;
  assert.deepEqual(dogItems.filter((i) => i.country === 'SI').map((i) => i.key), ['min_age_puppy_si', 'microchip_registration', 'vaccination_records', 'rabies_vaccination', 'kzs_member_si', 'parents_dna_profile']);
  assert.ok(dogItems.filter((i) => !i.country).every((i) => !i.source_ids.some((s) => ['K3', 'K4', 'K5', 'K6', 'K8', 'K19'].includes(s))), 'universal items cite no Slovenian source');
  const catItems = registry.species.find((s) => s.id === 'cat').buyer_checklist;
  assert.deepEqual(catItems.filter((i) => i.country === 'SI').map((i) => i.key), ['kitten_min_age_si', 'zfds_member_si']);
  assert.ok(catItems.filter((i) => !i.country).every((i) => !i.source_ids.some((s) => ['K3', 'K15'].includes(s))), 'universal cat items cite no Slovenian source');
  assert.deepEqual(dogGeneral.rabies_vaccination, ['K5', 'K19']);
  // minimum first, then best practice, then other schemes
  for (const [id, c] of Object.entries(byId)) {
    const order = c.tests.map((t) => ['minimum', 'best_practice', 'other_scheme'].indexOf(t.level));
    assert.deepEqual(order, [...order].sort((a, b) => a - b), id);
  }
});

test('checklist: a breed without an entry exports as not_checked (a breed run adds it)', () => {
  const texts = readInputs();
  const d = checklistData();
  delete d.breeds.beagle;
  const reg = buildRegistry({ ...texts, checklist_data: JSON.stringify(d) });
  assert.deepEqual(reg.breeds.find((b) => b.id === 'beagle').breeder_checklist, { status: 'not_checked', tests: [], basis_source_ids: [] });
});

test('checklist: wrong data fails loudly', () => {
  const texts = readInputs();
  const build = (mutate) => () => {
    const d = checklistData();
    mutate(d);
    return buildRegistry({ ...texts, checklist_data: JSON.stringify(d) });
  };
  assert.throws(build((d) => { d.breeds.poodle = d.breeds.beagle; }), /poodle is not a breed of the register/);
  assert.throws(build((d) => { d.breeds.beagle.tests[0].source_id = 'K999'; }), /unknown source K999/);
  assert.throws(build((d) => { d.breeds.beagle.tests[0].quote = ''; }), /no quote/);
  assert.throws(build((d) => { d.breeds.beagle.tests[0].level = 'required'; }), /bad level required/);
  assert.throws(build((d) => { d.breeds.beagle.tests.push({ ...d.breeds.beagle.tests[0] }); }), /twice/);
  assert.throws(build((d) => { d.breeds.beagle.tests = []; }), /listed but has no tests/);
  assert.throws(build((d) => { d.breeds.british_shorthair.notes = ''; }), /needs notes/);
  assert.throws(build((d) => { d.general.dog[0].quotes = []; }), /has no source/);
  assert.throws(build((d) => { d.general.dog[0].quotes[0].quote = ' '; }), /no quote/);
  assert.throws(build((d) => { d.general.dog[0].quotes.push({ ...d.general.dog[0].quotes[0] }); }), /repeats a quote/);
  assert.throws(build((d) => { d.general.cat[0].key = d.general.dog[0].key; }), /duplicate key/);
  assert.throws(build((d) => { d.meta.levels = ['minimum']; }), /meta.levels/);
  assert.throws(build((d) => { d.general.dog[0].country = 'Slovenia'; }), /ISO 3166-1/);
  assert.throws(() => buildRegistry({ ...texts, checklist_sources: '| S1 | A | X | Y | https://x.y | z |' }), /in two tables/);
});

test('Ragdoll (M5-R10-15): paid cat, kitten at 3 months, CFA weight by sex, full size at about 4 years, C14 life expectancy, combing once or twice a week, health keys only', () => {
  const rag = registry.breeds.find((b) => b.id === 'ragdoll');
  assert.equal(rag.species, 'cat');
  const fact = (field) => rag.facts.find((f) => f.field === field);
  assert.deepEqual([fact('weight').value, fact('weight').source_ids], [{ male: [6.8, 9.1], female: [4.5, 6.8] }, ['C30']]);
  assert.deepEqual([fact('growth_end').value, fact('growth_end').qualifier, fact('growth_end').source_ids], [48, 'about', ['C30']]);
  assert.deepEqual([fact('lifespan').value, fact('lifespan').qualifier, fact('lifespan').source_ids], [10.31, 'expectancy', ['C14']]);
  assert.deepEqual([fact('grooming_frequency').value, fact('grooming_frequency').source_ids], ['at_least_once_or_twice_a_week', ['C32']]);
  assert.deepEqual(rag.health.map((h) => [h.key, h.source_ids]), [['heart_hcm', ['C32']], ['dental_weight_nail_care', ['C31']]]);
  assert.ok(rag.health.every((h) => !('value' in h)));
  assert.deepEqual(rag.suitability, { suits: [], consider: [] });
  // Game: the general cat rules, a pedigree kitten at 3 months, no grooming routine (CAT_SPEC Q8).
  assert.equal(rag.game.stages[0].starts.arrival_months, 3);
  assert.deepEqual(rag.game.stages.map((s) => s.starts.month ?? null), [null, 12, 84, 120]);
  assert.deepEqual(rag.game.adult_activity, { kind: 'play_sessions', value: 2 });
  assert.equal(rag.game.rules.find((r) => r.key === 'grooming_rule').params.per_week, 2); // David 2026-10-11
  assert.ok(rag.game.values.some((v) => v.ref === 'cat-data:ragdoll.arrival_age_kitten' && /pravilo runbooka, M5-R10-15/.test(v.decision)));
  // No health statistics and no median age at death of the C31 study in the register.
  const json = JSON.stringify(rag);
  assert.doesNotMatch(json, /12\.85|periodontal|odds ratio|hypoallergenic|van pattern/i);
  assert.doesNotMatch(json, /\d+(\.\d+)?\s?%/);
  for (const id of ['C14', 'C30', 'C31', 'C32']) assert.ok(registry.sources.some((s) => s.id === id), id);
});

test('Siberian (M5-R10-16): paid cat, kitten at 3 months, GCCF weight by sex, growth 4–5 years, lifespan 12–15 years, combing at least once a week, health keys only, never hypoallergenic', () => {
  const sib = registry.breeds.find((b) => b.id === 'siberian_cat');
  assert.equal(sib.species, 'cat');
  const fact = (field) => sib.facts.find((f) => f.field === field);
  assert.deepEqual([fact('weight').value, fact('weight').source_ids], [{ male: [7.7, 11.8], female: [5.9, 7.7] }, ['C34']]);
  assert.deepEqual([fact('growth_end').value, fact('growth_end').qualifier, fact('growth_end').source_ids], [[48, 60], 'about', ['C34']]);
  assert.deepEqual([fact('lifespan').value, fact('lifespan').qualifier, fact('lifespan').source_ids], [[12, 15], 'span', ['C34']]);
  assert.deepEqual([fact('grooming_frequency').value, fact('grooming_frequency').source_ids], ['at_least_once_a_week', ['C35']]);
  assert.deepEqual(sib.health.map((h) => [h.key, h.source_ids]), [['heart_hcm', ['C34']], ['dental_weight_care', ['C35']]]);
  assert.ok(sib.health.every((h) => !('value' in h)));
  assert.deepEqual(sib.suitability, { suits: [], consider: [] });
  // Game: the general cat rules, a pedigree kitten at 3 months, brushing 1× a week (PetMD C35, runbook rule).
  assert.equal(sib.game.stages[0].starts.arrival_months, 3);
  assert.deepEqual(sib.game.stages.map((s) => s.starts.month ?? null), [null, 12, 84, 120]);
  assert.deepEqual(sib.game.adult_activity, { kind: 'play_sessions', value: 2 });
  assert.equal(sib.game.rules.find((r) => r.key === 'grooming_rule').params.per_week, 1);
  assert.ok(sib.game.values.some((v) => v.ref === 'cat-data:siberian_cat.arrival_age_kitten' && /pravilo runbooka, M5-R10-16/.test(v.decision)));
  assert.ok(sib.game.values.some((v) => v.ref === 'cat-data:siberian_cat.grooming.game_sessions_per_week' && /pravilo runbooka, M5-R10-16/.test(v.decision)));
  // No health statistics, no allergy promise.
  const json = JSON.stringify(sib);
  assert.doesNotMatch(json, /odds ratio|allerg|neva masquerade|colourpoint/i);
  assert.doesNotMatch(json, /\d+(\.\d+)?\s?%/);
  for (const id of ['C34', 'C35']) assert.ok(registry.sources.some((s) => s.id === id), id);
});

test('Norwegian Forest Cat (M5-R10-17): paid cat, kitten at 3 months, GCCF weight by sex, growth 4–5 years, lifespan 14–16 years, combing at least once a week, health keys only', () => {
  const nfo = registry.breeds.find((b) => b.id === 'norwegian_forest_cat');
  assert.equal(nfo.species, 'cat');
  const fact = (field) => nfo.facts.find((f) => f.field === field);
  assert.deepEqual([fact('weight').value, fact('weight').source_ids], [{ male: [5.4, 7.3], female: [4.1, 5.4] }, ['C37']]);
  assert.deepEqual([fact('growth_end').value, fact('growth_end').qualifier, fact('growth_end').source_ids], [[48, 60], 'about', ['C37']]);
  assert.deepEqual([fact('lifespan').value, fact('lifespan').qualifier, fact('lifespan').source_ids], [[14, 16], 'span', ['C37']]);
  assert.deepEqual([fact('grooming_frequency').value, fact('grooming_frequency').source_ids], ['at_least_once_a_week', ['C38']]);
  assert.deepEqual(nfo.health.map((h) => [h.key, h.source_ids]), [['gsd_iv', ['C37']], ['heart_hcm', ['C38']], ['hip_dysplasia_weight', ['C38']]]);
  assert.ok(nfo.health.every((h) => !('value' in h)));
  assert.deepEqual(nfo.suitability, { suits: [], consider: [] });
  // Game: the general cat rules, a pedigree kitten at 3 months, brushing 1× a week (PetMD C38 / GCCF C37, runbook rule).
  assert.equal(nfo.game.stages[0].starts.arrival_months, 3);
  assert.deepEqual(nfo.game.stages.map((s) => s.starts.month ?? null), [null, 12, 84, 120]);
  assert.deepEqual(nfo.game.adult_activity, { kind: 'play_sessions', value: 2 });
  assert.equal(nfo.game.rules.find((r) => r.key === 'grooming_rule').params.per_week, 1);
  assert.ok(nfo.game.values.some((v) => v.ref === 'cat-data:norwegian_forest_cat.arrival_age_kitten' && /pravilo runbooka, M5-R10-17/.test(v.decision)));
  assert.ok(nfo.game.values.some((v) => v.ref === 'cat-data:norwegian_forest_cat.grooming.game_sessions_per_week' && /pravilo runbooka, M5-R10-17/.test(v.decision)));
  // No health statistics, no allergy promise.
  const json = JSON.stringify(nfo);
  assert.doesNotMatch(json, /odds ratio|allerg|colourpoint/i);
  assert.doesNotMatch(json, /\d+(\.\d+)?\s?%/);
  for (const id of nfo.source_ids) assert.ok(registry.sources.some((s) => s.id === id), id);
});

test('Bengal (M5-R10-18): paid cat, kitten at 3 months, PDSA average weight, growth up to 2 years, C14 life expectancy, brushing once a week, health keys only', () => {
  const ben = registry.breeds.find((b) => b.id === 'bengal');
  assert.equal(ben.species, 'cat');
  const fact = (field) => ben.facts.find((f) => f.field === field);
  assert.deepEqual([fact('weight').value, fact('weight').qualifier, fact('weight').source_ids], [5.5, 'about', ['C44']]);
  assert.deepEqual([fact('growth_end').value, fact('growth_end').qualifier, fact('growth_end').source_ids], [24, 'up_to', ['C46']]);
  assert.deepEqual([fact('lifespan').value, fact('lifespan').qualifier, fact('lifespan').source_ids], [8.51, 'expectancy', ['C14']]);
  assert.deepEqual([fact('grooming_frequency').value, fact('grooming_frequency').source_ids], ['once_a_week', ['C44']]);
  assert.deepEqual(ben.health.map((h) => [h.key, h.source_ids]), [['pra_pk_def', ['C45']], ['heart_hcm', ['C45']], ['kneecap_luxation', ['C44']], ['cancer_risk', ['C45']]]);
  assert.ok(ben.health.every((h) => !('value' in h)));
  assert.deepEqual(ben.suitability, { suits: [], consider: [] });
  // Game: the general cat rules, a pedigree kitten at 3 months, brushing 1× a week (PDSA C44 / PetMD C45, runbook rule).
  assert.equal(ben.game.stages[0].starts.arrival_months, 3);
  assert.deepEqual(ben.game.stages.map((s) => s.starts.month ?? null), [null, 12, 84, 120]);
  assert.deepEqual(ben.game.adult_activity, { kind: 'play_sessions', value: 2 });
  assert.equal(ben.game.rules.find((r) => r.key === 'grooming_rule').params.per_week, 1);
  assert.ok(ben.game.values.some((v) => v.ref === 'cat-data:bengal.arrival_age_kitten' && /pravilo runbooka, M5-R10-18/.test(v.decision)));
  assert.ok(ben.game.values.some((v) => v.ref === 'cat-data:bengal.grooming.game_sessions_per_week' && /pravilo runbooka, M5-R10-18/.test(v.decision)));
  // No health statistics, no allergy promise, never a wild cat.
  const json = JSON.stringify(ben);
  assert.doesNotMatch(json, /odds ratio|allerg|leopard|hybrid/i);
  assert.doesNotMatch(json, /\d+(\.\d+)?\s?%/);
  for (const id of ['C44', 'C45', 'C46']) assert.ok(registry.sources.some((s) => s.id === id), id);
  // Breeder checklist: GCCF Active Register DNA tests (K22).
  assert.equal(ben.breeder_checklist.status, 'listed');
  assert.deepEqual(ben.breeder_checklist.tests.map((t) => [t.key, t.level, t.source_ids]), [['dna_pra_b', 'minimum', ['K22']], ['dna_pk_def', 'minimum', ['K22']]]);
});

test('Rottweiler (M5-R10-19): more than 2 hours, FCI weight by sex, FCI group 2 DE, VetCompass median + RKC "under 10 years", Coren 9, experienced_owner chip', () => {
  const d = registry.breeds.find((b) => b.id === 'rottweiler');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S168']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 147, group: 2, section: 2, origin: 'DE' });
  // FCI gives one weight per sex (dogs 50 kg, bitches about 42 kg).
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [50, 50], female: [42, 42] }, ['S166']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [61, 68], female: [56, 63] }, ['S166']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 9, ['S171']], ['less_than', 10, ['S168']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'growth_end').value, [18, 24]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 9);
  assert.deepEqual(d.facets, { size: 'large', exercise: 'over_2h', grooming: 'weekly' });
  // 10 min × age reaches the 120-minute cap at 12 months; senior 90 min from 81 months.
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3].activity, { kind: 'steps', value: 9000 });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family', 'family_pet', 'large_home']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'experienced_owner', 'hips_hind_legs', 'shorter_lifespan', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['hip_elbow_dysplasia', 'laryngeal_paralysis_polyneuropathy', 'heart_sas', 'cancer_risk', 'inherited_eye_defects']);
  const json = JSON.stringify(d);
  // No VetCompass percentages, no conflicting PDSA band, never "hypoallergenic", never white markings.
  assert.doesNotMatch(json, /mcmillan_2024|lifespan\.pdsa|common_disorders|hypoallergenic|odds ratio|33\.0|7\.46|7\.06|white markings/i);
});

test('Bulldog (M5-R10-20): up to 1 hour, FCI weight by sex, FCI group 2 GB, McMillan median + RKC "under 10 years", Coren 77, flat-face and skin chips', () => {
  const d = registry.breeds.find((b) => b.id === 'bulldog');
  assert.deepEqual(d.name, { en: 'Bulldog', sl: 'Angleški buldog' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S177']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 149, group: 2, section: 2, origin: 'GB' });
  // FCI gives one weight per sex (males 25 kg, females 23 kg); no standard gives a height → PDSA average.
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [25, 25], female: [23, 23] }, ['S175']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[[31, 40], ['S180']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 9.8, ['S54']], ['less_than', 10, ['S177']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'growth_end').value, [12, 15]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 77);
  assert.deepEqual(d.facets, { size: 'medium', exercise: 'under_1h', grooming: 'weekly' });
  // 10 min × age reaches the 60-minute cap at 6 months; senior 45 min from 88 months.
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3].activity, { kind: 'steps', value: 4500 });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['family_pet', 'other_pets']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['brachycephalic_breathing', 'sensitive_skin', 'shorter_lifespan', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['flat_face_breathing', 'heat_stroke_risk', 'skin_fold_infections', 'eyelid_eye_problems', 'hip_elbow_joint_disease']);
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['dna_huu', 'minimum'], ['inbreeding_coi', 'minimum'], ['boas_grading', 'other_scheme']]);
  const json = JSON.stringify(d);
  // No VetCompass percentages, no hazard ratio, no "x14", no 7.2-year death median, never "hypoallergenic", never merle.
  assert.doesNotMatch(json, /vetcompass_2013|common_disorders|hypoallergenic|odds ratio|x14|HR 1\.8|7\.2|merle/i);
});

test('German Shorthaired Pointer (M5-R10-21): more than 2 hours, AKC weight by sex, FCI group 7 DE, McMillan median + PDSA "over 10 years", Coren 17, no welfare chip', () => {
  const d = registry.breeds.find((b) => b.id === 'german_shorthaired_pointer');
  assert.deepEqual(d.name, { en: 'German Shorthaired Pointer', sl: 'Nemški kratkodlaki ptičar' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S187']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 119, group: 7, section: 1, origin: 'DE' });
  // FCI gives no weight → the AKC standard (lb → kg); height from the AKC standard (in → cm).
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [24.9, 31.8], female: [20.4, 27.2] }, ['S186']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [58.4, 63.5], female: [53.3, 58.4] }, ['S186']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 13.4, ['S123']], ['more_than', 10, ['S188']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'growth_end').value, [12, 15]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 17);
  assert.deepEqual(d.facets, { size: 'large', exercise: 'over_2h', grooming: 'weekly' });
  // 10 min × age reaches the 120-minute cap at 12 months; senior 90 min from 121 months.
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 121 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 9000 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family', 'large_home']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['hip_dysplasia_arthritis', 'bloat_gdv', 'bleeding_disorder_vwd', 'heart_muscle_disease', 'itchy_skin_allergy', 'entropion_cherry_eye']);
  // No A/B source names tests for the parents → the general dog checklist only.
  assert.equal(d.breeder_checklist.status, 'not_found');
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a docked tail or a disqualifying colour, no CI / counts from the McMillan table.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|docked|lemon|3313|947|13\.3 - 13\.6/i);
});

test('Yorkshire Terrier (M5-R10-22): up to 30 minutes, FCI "up to 3.2 kg", FCI group 3 GB, VetCompass median + RKC "over 12 years", Coren 27, fragile_older_children chip', () => {
  const d = registry.breeds.find((b) => b.id === 'yorkshire_terrier');
  assert.deepEqual(d.name, { en: 'Yorkshire Terrier', sl: 'Yorkshirski terier' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[30, 'up_to', ['S192']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 86, group: 3, section: 4, origin: 'GB' });
  // FCI / RKC give only an upper limit → "up to 3.2 kg" (both sexes); no standard height → PDSA average 20 cm.
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.qualifier, f.source_ids]), [[[3.2, 3.2], 'up_to', ['S190']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[[20, 20], ['S194']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 13.56, ['S195']], ['more_than', 12, ['S192']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'growth_end').value, [8, 12]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 27);
  assert.deepEqual(d.facets, { size: 'small', exercise: 'under_1h', grooming: 'daily' });
  // 10 min × age reaches the 30-minute cap at 3 months; senior 23 min from 122 months.
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 3000, cap_month: 3 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 122 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 2300 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['apartment', 'low_shedding']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['fragile_older_children', 'frequent_grooming']);
  assert.equal(registry.suitability_labels.en.tags.fragile_older_children, 'fragile bones — better with older children');
  assert.equal(registry.suitability_labels.sl.tags.fragile_older_children, 'krhke kosti — bolje za starejše otroke');
  assert.deepEqual(d.health.map((h) => h.key), ['dental_disease_tooth_brushing', 'kneecap_luxation', 'windpipe_collapse', 'eye_problems_pra_lens_dry_eye', 'liver_shunt']);
  // RKC: "does not have any recommended pre-breeding health screening in place".
  assert.equal(d.breeder_checklist.status, 'none_recommended');
  // A source URL with parentheses must be percent-encoded (the sources.md parser stops at ')').
  assert.match(registry.sources.find((x) => x.id === 'S196').url, /%284%29\.pdf$/);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a docked tail or a non-standard colour, never the heavier pet-population weight.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|docked|merle|fawn|5\.06|11\.30/i);
});

test('Cane Corso (M5-R10-23): about 2 hours (PDSA, no RKC page), FCI weight by sex, FCI group 2 Italy, PDSA "under 10 years", unranked by Coren, not_for_small_children + drools chips', () => {
  const d = registry.breeds.find((b) => b.id === 'cane_corso');
  assert.deepEqual(d.name, { en: 'Cane Corso', sl: 'Cane corso' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'about', ['S199']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 343, group: 2, section: 2, origin: 'IT' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [45, 50], female: [40, 45] }, ['S197']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [64, 68], female: [60, 64] }, ['S197']]]);
  // Only the PDSA band — the international kennel / owner median (S201) stays background for the senior boundary.
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['less_than', 10, ['S199']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank'), undefined);
  assert.deepEqual(d.facts.find((f) => f.field === 'growth_end').value, [18, 24]);
  assert.deepEqual(d.facets, { size: 'large', exercise: 'over_2h', grooming: 'weekly' });
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 84 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 9000 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'not_for_small_children', 'experienced_owner', 'needs_mental_stimulation', 'shorter_lifespan', 'sheds', 'drools']);
  assert.equal(registry.suitability_labels.en.tags.not_for_small_children, 'large dog — not for homes with small children');
  assert.equal(registry.suitability_labels.sl.tags.not_for_small_children, 'velik pes — ni za dom z majhnimi otroki');
  assert.equal(registry.suitability_labels.en.tags.drools, 'drools — keep a cloth at hand');
  assert.equal(registry.suitability_labels.sl.tags.drools, 'se slini — imejte pri roki krpo');
  assert.deepEqual(d.health.map((h) => h.key), ['hip_elbow_dysplasia', 'bloat_gdv', 'eyelid_problems']);
  // No RKC list (breed not RKC-recognised): PDSA names the hip and elbow x-ray schemes → other_scheme.
  assert.equal(d.breeder_checklist.status, 'listed');
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['hip_scheme', 'other_scheme'], ['elbow_scheme', 'other_scheme']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never cropped ears / a docked tail, never the kennel-data median or a percentage.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|docked|cropped|9\.29|6\.98|merle/i);
});

test('Pembroke Welsh Corgi (M5-R10-24): up to 1 hour (RKC), FCI weight by sex, FCI group 1 Great Britain, RKC "over 12 years", Coren 11, herding / shedding / brushing chips', () => {
  const d = registry.breeds.find((b) => b.id === 'pembroke_welsh_corgi');
  assert.deepEqual(d.name, { en: 'Pembroke Welsh Corgi', sl: 'Welsh corgi pembroke' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S204']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 39, group: 1, section: 2, origin: 'GB' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [10, 12], female: [9, 11] }, ['S202']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [25, 30], female: [25, 30] }, ['S202']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 12, ['S204']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 11);
  assert.deepEqual(d.facts.find((f) => f.field === 'growth_end').value, [9, 12]);
  assert.deepEqual(d.facets, { size: 'small', exercise: 'under_1h', grooming: 'several_weekly' });
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 108 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits, []);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['may_herd_children', 'sheds', 'frequent_grooming']);
  assert.deepEqual(d.health.map((h) => h.key), ['hip_dysplasia_arthritis', 'back_disc_disease', 'epilepsy', 'bleeding_disorder_vwd', 'cataracts_lens_luxation']);
  // RKC Best Practice: the BVA/KC hip scheme.
  assert.equal(d.breeder_checklist.status, 'listed');
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['hip_scheme', 'best_practice']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a docked tail or a non-standard colour, never a percentage.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|docked|merle|\d %\)/i);
});

test('Dobermann (M5-R10-25): more than 2 hours (RKC), FCI weight by sex, FCI group 2 Germany, RKC "over 10 years", Coren 5, family / large home / not for small children chips', () => {
  const d = registry.breeds.find((b) => b.id === 'dobermann');
  assert.deepEqual(d.name, { en: 'Dobermann', sl: 'Doberman' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S209']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 143, group: 2, section: 1, origin: 'DE' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [40, 45], female: [32, 35] }, ['S207']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [68, 72], female: [63, 68] }, ['S207']]]);
  // Only the RKC band — never the small 2013 VetCompass median (basis of the provisional senior boundary).
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 10, ['S209']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 5);
  assert.deepEqual(d.facts.find((f) => f.field === 'growth_end').value, [15, 18]);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 83 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 9000 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family', 'family_pet', 'large_home']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'not_for_small_children', 'experienced_owner', 'needs_mental_stimulation', 'sheds', 'chases_small_pets']);
  assert.deepEqual(d.health.map((h) => h.key), ['hip_dysplasia_arthritis', 'wobbler_syndrome', 'heart_muscle_disease', 'bleeding_disorder_vwd', 'eye_phpv', 'bloat_gdv', 'certain_cancers', 'hypothyroidism']);
  // RKC Good Practice (minimum) vWD1; Best Practice eye test, DINGS2, hips.
  assert.equal(d.breeder_checklist.status, 'listed');
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['dna_vwd1', 'minimum'], ['eye_scheme', 'best_practice'], ['dna_dings2', 'best_practice'], ['hip_scheme', 'best_practice']]);
  const json = JSON.stringify(d);
  // The 2013 median appears only in the senior-boundary decision text (game basis), never as a register fact.
  assert.equal(d.facts.some((f) => f.value === 9.2), false);
  // Never "hypoallergenic", never cropped ears / a docked tail or a non-standard colour, never a percentage.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|docked|cropped|merle|\d %\)/i);
});

test('Boxer (M5-R10-26): more than 2 hours (RKC), RKC standard weight by sex, FCI group 2 section 2 Germany, McMillan median + RKC band, Coren 48, short-face chip, needs_company, brushing 1x a week', () => {
  const d = registry.breeds.find((b) => b.id === 'boxer');
  assert.deepEqual(d.name, { en: 'Boxer', sl: 'Boksar' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S215']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 144, group: 2, section: 2, origin: 'DE' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [30, 32], female: [25, 27] }, ['S217']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [57, 63], female: [53, 59] }, ['S217']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 11.3, ['S123']], ['more_than', 10, ['S215']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 48);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 102 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 9000 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family', 'family_pet', 'large_home']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'short_muzzle_breathing', 'not_for_small_children', 'experienced_owner', 'needs_company', 'chews_when_bored', 'sheds', 'chases_small_pets']);
  assert.deepEqual(d.health.map((h) => h.key), ['short_face_breathing', 'corneal_ulcers', 'skin_folds_allergies', 'heart_cardiomyopathy_aortic_stenosis', 'hip_dysplasia_arthritis', 'epilepsy', 'certain_cancers']);
  // RKC Best Practice only: elbow, eye and hip schemes.
  assert.equal(d.breeder_checklist.status, 'listed');
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['elbow_scheme', 'best_practice'], ['eye_scheme', 'best_practice'], ['hip_scheme', 'best_practice']]);
  const json = JSON.stringify(d);
  // The VetCompass 2023 median and the pet-population weights stay in research.
  assert.equal(d.facts.some((f) => f.value === 10.46), false);
  assert.doesNotMatch(json, /32\.99|27\.56|17\.74|10\.71/);
  // Never "hypoallergenic", never cropped ears / a docked tail or a non-standard colour, never a percentage.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|docked|cropped|merle|\d %\)/i);
});

test('Miniature Schnauzer (M5-R10-27): up to 1 hour (RKC), FCI weight and height for both sexes, FCI group 2 section 1 Germany, VetCompass median + RKC band, Coren 12, apartment / children / low shedding, brushing 3x a week', () => {
  const d = registry.breeds.find((b) => b.id === 'miniature_schnauzer');
  assert.deepEqual(d.name, { en: 'Miniature Schnauzer', sl: 'Pritlikavi šnavcer' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S223']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 183, group: 2, section: 1, origin: 'DE' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [4, 8], female: [4, 8] }, ['S221']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [30, 35], female: [30, 35] }, ['S221']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 11.6, ['S227']], ['more_than', 10, ['S223']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 12);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 104 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['apartment', 'children', 'low_shedding']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['frequent_grooming']);
  assert.deepEqual(d.health.map((h) => h.key), ['dental_disease_care', 'bladder_stones', 'eye_problems_pra_cataracts_dry_eye', 'skin_comedo_allergy']);
  // RKC Best Practice only: MAC DNA test and the eye scheme.
  assert.equal(d.breeder_checklist.status, 'listed');
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['dna_mac', 'best_practice'], ['eye_scheme', 'best_practice']]);
  const json = JSON.stringify(d);
  // The pet-population weights and the VetCompass percentages stay in research.
  assert.doesNotMatch(json, /10\.8 kg|9\.0 kg|17\.4|8\.3%/);
  // Never "hypoallergenic", never cropped ears / a docked tail or a non-standard colour, never a percentage.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|docked|cropped|merle|\d %\)/i);
});

test('Shih Tzu (M5-R10-28): up to 1 hour (RKC), FCI weight for both sexes, PDSA height, FCI group 9 section 5 Tibet (China), VetCompass median + RKC band, Coren 70, short-muzzle chip, brushing every day', () => {
  const d = registry.breeds.find((b) => b.id === 'shih_tzu');
  assert.deepEqual(d.name, { en: 'Shih Tzu', sl: 'Shih tzu' });
  assert.deepEqual(d.portrait, { file: 'shih-tzu.webp', width: 1024, height: 1024, kind: 'ai_photo' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S231']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 208, group: 9, section: 5, origin: 'CN-XZ' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [4.5, 8], female: [4.5, 8] }, ['S229']]]);
  // The FCI gives only an upper bound (not more than 27 cm) → the PDSA average is shown.
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[[27, 27], ['S233']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 12.7, ['S234']], ['more_than', 10, ['S231']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 70);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 114 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['apartment', 'family_pet', 'first_time_owner']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['frequent_grooming', 'short_muzzle_breathing']);
  assert.deepEqual(d.health.map((h) => h.key), ['shorter_face_boas', 'dental_disease_care', 'eye_problems_dry_eye_cataracts_pra_retina']);
  // RKC: no additional health screening schemes or DNA tests.
  // RKC "Sheds: No" vs PDSA seasonal shedding → no shedding fact.
  assert.equal(d.facts.some((f) => f.field === 'shedding'), false);
  assert.equal(d.breeder_checklist.status, 'none_recommended');
  assert.deepEqual(d.breeder_checklist.tests, []);
  const json = JSON.stringify(d);
  // The pet-population weight and the VetCompass percentages stay in research.
  assert.doesNotMatch(json, /7\.9 kg|11,082|9\.5%/);
  // Never "hypoallergenic", never merle, never a percentage.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|merle|\d %\)/i);
});

test('Great Dane (M5-R10-29): more than 2 hours (RKC), PDSA weight for both sexes, FCI height, FCI group 2 section 2 Germany, only the RKC lifespan band, Coren 48, welfare chips incl. the new eyelid_problems, brushing once a week', () => {
  const d = registry.breeds.find((b) => b.id === 'great_dane');
  assert.deepEqual(d.name, { en: 'Great Dane', sl: 'Nemška doga' });
  assert.equal(d.facets.size, 'giant');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S241']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 235, group: 2, section: 2, origin: 'DE' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [46, 54], female: [46, 54] }, ['S243']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [80, 90], female: [72, 84] }, ['S239']]]);
  // The VetCompass median from only 23 deaths is not exported (Dobermann precedent) — only the RKC band.
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['less_than', 10, ['S241']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 48);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 54 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 9000 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family', 'family_pet', 'large_home']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'not_for_small_children', 'shorter_lifespan', 'hips_hind_legs', 'eyelid_problems', 'chews_when_bored', 'sheds', 'drools', 'chases_small_pets']);
  assert.deepEqual(d.health.map((h) => h.key), ['bloat_gdv', 'hip_elbow_dysplasia', 'eyelid_problems', 'heart_muscle_disease', 'wobbler_syndrome', 'certain_cancers']);
  // RKC Best Practice: the IMGD DNA test (new key) and the elbow, eye and hip schemes.
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['dna_imgd', 'best_practice'], ['elbow_scheme', 'best_practice'], ['eye_scheme', 'best_practice'], ['hip_scheme', 'best_practice']]);
  const json = JSON.stringify(d);
  // The small-sample median is no lifespan fact (only the game decision names it) and its death count stays in research.
  assert.equal(d.facts.filter((f) => f.field === 'lifespan' && f.qualifier === 'median').length, 0);
  assert.doesNotMatch(json, /23 deaths/);
  // Never "hypoallergenic", never merle, never a percentage.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|merle|\d %\)/i);
});

test('Pomeranian (M5-R10-30): up to 30 minutes (RKC), RKC weight per sex, FCI height, FCI group 5 section 4 Germany, RKC lifespan band, Coren 23, new older_children chip, no welfare chip, brushing 3× a week', () => {
  const d = registry.breeds.find((b) => b.id === 'pomeranian');
  assert.deepEqual(d.name, { en: 'Pomeranian', sl: 'Pomeranec' });
  assert.equal(d.facets.size, 'small');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[30, 'up_to', ['S247']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 97, group: 5, section: 4, origin: 'DE' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [1.8, 2], female: [2, 2.5] }, ['S249']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [18, 24], female: [18, 24] }, ['S246']]]);
  // No McMillan 2024 / VetCompass value reachable — only the RKC band (the 12.2 y via Wikipedia stays in research).
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 12, ['S247']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 23);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 3000, cap_month: 3 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 108 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 2300 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['apartment', 'family_pet', 'other_pets']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['older_children', 'frequent_grooming', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['kneecap_luxation', 'windpipe_collapse_breathing', 'eye_problems_pra_cataracts_entropion', 'elbow_dysplasia_arthritis', 'cm_sm_screening', 'hypothyroidism']);
  // RKC Best Practice: CM/SM screening and the eye scheme (existing keys).
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['cm_sm_mri', 'best_practice'], ['eye_scheme', 'best_practice']]);
  const json = JSON.stringify(d);
  assert.doesNotMatch(json, /12\.2/);
  // Never "hypoallergenic", never merle, never a percentage.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|merle|\d %\)/i);
});

test('Boston Terrier (M5-R10-31): up to 1 hour (RKC), PDSA weight and height, FCI group 9 section 11 USA, RKC lifespan band, Coren 54, flat-faced welfare chip, no shedding fact, brushing 1× a week', () => {
  const d = registry.breeds.find((b) => b.id === 'boston_terrier');
  assert.deepEqual(d.name, { en: 'Boston Terrier', sl: 'Bostonski terier' });
  assert.equal(d.facets.size, 'small');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S255']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 140, group: 9, section: 11, origin: 'US' });
  // FCI / RKC give weight classes only (the lightest has no lower bound) → the PDSA average range.
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [7, 11], female: [7, 11] }, ['S259']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[[38, 43], ['S259']]]);
  // No McMillan 2024 / VetCompass value reachable — only the RKC band (the 11.8 y via Wikipedia stays in research).
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 10, ['S255']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 54);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 90 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['apartment', 'family_pet', 'children']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['brachycephalic_breathing', 'needs_company']);
  // RKC "Sheds: Yes" and AKC "shed minimally" disagree → no shedding fact and no chip.
  assert.equal(d.facts.filter((f) => f.field === 'shedding').length, 0);
  assert.deepEqual(d.health.map((h) => h.key), ['flat_face_breathing', 'heat_stroke_risk', 'prominent_eyes_injury_dry_eye']);
  // The RKC lists no pre-breeding test for the breed → not_found (German Shorthaired Pointer precedent).
  assert.equal(d.breeder_checklist.status, 'not_found');
  const json = JSON.stringify(d);
  assert.doesNotMatch(json, /11\.8/);
  // Never "hypoallergenic", never merle, never a percentage.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|merle|\d %\)/i);
});

test('Miniature American Shepherd (M5-R10-32): at least 1 hour (PetMD, game value), AKC weight chart, FCI height and group 1 section 1 USA, PetMD lifespan range, not ranked by Coren, no welfare chip, no frequent_grooming chip (sources conflict), FCI size small, brushing 2× a week', () => {
  const d = registry.breeds.find((b) => b.id === 'miniature_american_shepherd');
  assert.deepEqual(d.name, { en: 'Miniature American Shepherd', sl: 'Mali ameriški ovčar' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'small');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'at_least', ['S269']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 367, group: 1, section: 1, origin: 'US' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [9.1, 18.1], female: [9.1, 18.1] }, ['S268']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [35.5, 46], female: [33, 43.5] }, ['S265']]]);
  // No McMillan 2024 / VetCompass / RKC value — the PetMD range (the senior boundary from it is Claude's suggestion).
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['span', [12, 13], ['S269']]]);
  assert.equal(d.facts.filter((f) => f.field === 'coren_rank').length, 0);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 108 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['family_pet', 'children', 'other_pets']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['needs_mental_stimulation', 'may_herd_children', 'needs_company', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['drug_sensitivity_mdr1', 'inherited_eye_disease', 'hip_dysplasia', 'kneecap_luxation']);
  // The RKC lists no test for the breed; PetMD names the parents' tests → other_scheme (Cane Corso precedent).
  assert.equal(d.breeder_checklist.status, 'listed');
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['dna_mdr1', 'other_scheme'], ['eye_scheme', 'other_scheme'], ['hip_scheme', 'other_scheme'], ['patella_scheme', 'other_scheme']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage; the Wikipedia lifespan (tier C) stays in research.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)|13–15/i);
});

test('Chihuahua (M5-R10-33): up to 30 minutes (RKC Smooth Coat), KC / PDSA weight, PDSA height, FCI group 9 section 6 Mexico, RKC "over 12 years" (never the young-population VetCompass median), Coren 67, no welfare chip, brushing 1× a week', () => {
  const d = registry.breeds.find((b) => b.id === 'chihuahua');
  assert.deepEqual(d.name, { en: 'Chihuahua', sl: 'Čivava' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'small');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[30, 'up_to', ['S275']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 218, group: 9, section: 6, origin: 'MX' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [1.8, 2.7], female: [1.8, 2.7] }, ['S274']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [12, 20], female: [12, 20] }, ['S277']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 12, ['S275']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 67);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 3000, cap_month: 3 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 108 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 2300 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['apartment', 'other_pets']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['older_children', 'needs_company', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['kneecap_luxation', 'hip_dysplasia_arthritis', 'legg_perthes', 'hydrocephalus', 'eye_problems_dry_eye_entropion_lens_luxation', 'heart_valve_screening', 'windpipe_collapse_breathing', 'cm_sm_screening', 'dental_weight_problems']);
  // RKC best practice (CM/SM, eyes) + the PDSA's parent checks (hips, heart, kneecaps) as other_scheme.
  assert.equal(d.breeder_checklist.status, 'listed');
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['cm_sm_mri', 'best_practice'], ['eye_scheme', 'best_practice'], ['hip_scheme', 'other_scheme'], ['heart_scheme', 'other_scheme'], ['patella_scheme', 'other_scheme']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage; the VetCompass median of a young population (8.2 y) stays in research.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)|8\.2 y|"8\.2"/i);
  assert.ok(!d.facts.some((f) => f.field === 'lifespan' && f.value === 8.2));
});

test('English Springer Spaniel (M5-R10-34): more than 2 hours (RKC), PDSA weight, KC height, FCI group 8 section 2 Great Britain, McMillan 2024 median + RKC "over 10 years", Coren 13, no welfare chip, brushing 2× a week', () => {
  const d = registry.breeds.find((b) => b.id === 'english_springer_spaniel');
  assert.deepEqual(d.name, { en: 'English Springer Spaniel', sl: 'Angleški springer španjel' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'medium');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S284']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 125, group: 8, section: 2, origin: 'GB' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [18, 23], female: [18, 23] }, ['S285']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [51, 51], female: [51, 51] }, ['S282']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 13.5, ['S123']], ['more_than', 10, ['S284']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 13);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 122 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 9000 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family', 'family_pet', 'children', 'other_pets']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'needs_company', 'sheds', 'frequent_grooming']);
  assert.deepEqual(d.health.map((h) => h.key), ['hip_elbow_dysplasia', 'pra_eye_disease', 'ear_infections_long_ears']);
  // RKC Good Practice (elbows, eyes, hips) = minimum; five DNA tests = best practice.
  assert.equal(d.breeder_checklist.status, 'listed');
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['elbow_scheme', 'minimum'], ['eye_scheme', 'minimum'], ['hip_scheme', 'minimum'], ['dna_ams', 'best_practice'], ['dna_dams', 'best_practice'], ['dna_fuco', 'best_practice'], ['dna_pfk', 'best_practice'], ['dna_pra_cord1', 'best_practice']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage; the Teng 2022 life expectancy (11.9 y, a mean) stays in research.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)|11\.9/i);
});

test('Shetland Sheepdog (M5-R10-35): up to 1 hour (RKC), AKC weight, FCI height, FCI group 1 section 1 Great Britain, RKC "over 10 years", Coren 6, no welfare chip, brushing every day', () => {
  const d = registry.breeds.find((b) => b.id === 'shetland_sheepdog');
  assert.deepEqual(d.name, { en: 'Shetland Sheepdog', sl: 'Šetlandski ovčar' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'small');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S291']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 88, group: 1, section: 1, origin: 'GB' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [6.8, 11.3], female: [6.8, 11.3] }, ['S294']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [37, 37], female: [35.5, 35.5] }, ['S290']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 10, ['S291']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 6);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 90 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['family_pet', 'children']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['needs_mental_stimulation', 'may_herd_children', 'frequent_grooming', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['collie_eye_pra', 'hip_dysplasia_arthritis', 'bleeding_disorder_vwd', 'drug_sensitivity_mdr1']);
  // RKC Good Practice (genetic diversity, eyes) = minimum; DNA tests, elbows and hips = best practice.
  assert.equal(d.breeder_checklist.status, 'listed');
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['inbreeding_coi', 'minimum'], ['eye_scheme', 'minimum'], ['dna_cea', 'best_practice'], ['dna_mdr1', 'best_practice'], ['dna_bbs2_pra', 'best_practice'], ['dna_cnga1_pra', 'best_practice'], ['dna_vwd3', 'best_practice'], ['elbow_scheme', 'best_practice'], ['hip_scheme', 'best_practice']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage; the McMillan figure via Wikipedia (13.4 y, tier C) stays in research.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)|13\.4/i);
});

test('Brittany (M5-R10-36): more than 2 hours (RKC), AKC standard weight, RKC height, FCI group 7 France, RKC "over 10 years", Coren 19, no welfare chip, brushing 1× a week', () => {
  const d = registry.breeds.find((b) => b.id === 'brittany');
  assert.deepEqual(d.name, { en: 'Brittany', sl: 'Bretonski ptičar' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'medium');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S298']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 95, group: 7, section: 1, origin: 'FR' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [13.6, 18.1], female: [13.6, 18.1] }, ['S300']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [48, 51], female: [47, 50] }, ['S299']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 10, ['S298']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 19);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 90 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 9000 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family', 'family_pet', 'children']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'sheds', 'chases_small_pets']);
  assert.deepEqual(d.health.map((h) => h.key), ['hip_dysplasia_arthritis', 'epilepsy', 'hypothyroidism', 'cataracts']);
  // The RKC lists no test for the Brittany (breed page and Health Standard) → nothing guessed.
  assert.equal(d.breeder_checklist.status, 'not_found');
  assert.deepEqual(d.breeder_checklist.tests, []);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage; the PetMD lifespan (12–14 y) stays in research.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)|12–14/i);
});

test('American Cocker Spaniel (M5-R10-37): more than 2 hours (RKC), AKC article weight, AKC ideal height, FCI group 8 USA, RKC "over 10 years", Coren 20, no welfare chip, brushing every day', () => {
  const d = registry.breeds.find((b) => b.id === 'american_cocker_spaniel');
  assert.deepEqual(d.name, { en: 'American Cocker Spaniel', sl: 'Ameriški koker španjel' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'small');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S305']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 167, group: 8, section: 2, origin: 'US' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [9.1, 13.6], female: [9.1, 13.6] }, ['S308']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [38.1, 39.4], female: [35.6, 36.8] }, ['S307']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 10, ['S305']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 20);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 90 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 9000 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['family_pet', 'children', 'other_pets']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['frequent_grooming', 'food_motivated_weight', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['ear_infections_long_ears', 'hip_dysplasia_arthritis', 'bleeding_disorder_vwd', 'pra_eye_disease']);
  // RKC breed page: Good Practice genetic diversity + eye scheme, Best Practice DNA prcd-PRA + hip scheme.
  assert.equal(d.breeder_checklist.status, 'listed');
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['inbreeding_coi', 'minimum'], ['eye_scheme', 'minimum'], ['dna_prcd_pra', 'best_practice'], ['hip_scheme', 'best_practice']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage; the McMillan figure via Wikipedia (13.3 y, tier C) stays in research.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)|13\.3/i);
});

test('Belgian Malinois (M5-R10-38): more than 2 hours (RKC), FCI weight and height, FCI group 1 Belgium, RKC "over 10 years", Coren 22, no welfare chip, no family chip', () => {
  const d = registry.breeds.find((b) => b.id === 'belgian_malinois');
  assert.deepEqual(d.name, { en: 'Belgian Malinois', sl: 'Belgijski ovčar malinois' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'medium_large');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S314']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 15, group: 1, section: 1, origin: 'BE' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [25, 30], female: [20, 25] }, ['S313']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [60, 66], female: [56, 62] }, ['S313']], [{ male: [61, 66], female: [56, 61] }, ['S315']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 10, ['S314']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 22);
  // RKC "Coat length: Medium" contradicts the standards' short coat → not exported.
  assert.equal(d.facts.find((f) => f.field === 'coat'), undefined);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 90 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 9000 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family', 'large_home']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'may_herd_children', 'needs_mental_stimulation', 'needs_company', 'sheds', 'chases_small_pets']);
  assert.deepEqual(d.health.map((h) => h.key), ['hip_elbow_joint_disease', 'cataracts', 'pra_inherited_blindness']);
  // The RKC page shows no named test → the general dog checklist only.
  assert.equal(d.breeder_checklist.status, 'not_found');
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage; the McMillan figure via Wikipedia (12 y, tier C) stays in research.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)|life expectancy of 12/i);
});

test('Basset Hound (M5-R10-39): up to 1 hour (RKC), PDSA weight, FCI height, FCI group 6 Great Britain, RKC "over 10 years", Coren 71, welfare health keys, POAG DNA test', () => {
  const d = registry.breeds.find((b) => b.id === 'basset_hound');
  assert.deepEqual(d.name, { en: 'Basset Hound', sl: 'Baset' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'medium_large');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S321']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 163, group: 6, section: 1, origin: 'GB' });
  // FCI gives no weight → PDSA (one range for both sexes); FCI height only (the AKC article gives only an upper bound).
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [18, 27], female: [18, 27] }, ['S323']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [33, 38], female: [33, 38] }, ['S320']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 10, ['S321']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 71);
  assert.equal(d.facts.find((f) => f.field === 'food_motivated').value, true);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 90 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['family_pet', 'children', 'large_home']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['eyelid_problems', 'sensitive_skin', 'food_motivated_weight', 'needs_company', 'sheds', 'drools']);
  assert.deepEqual(d.health.map((h) => h.key), ['eyelids_turned', 'glaucoma_poag', 'skin_fold_dermatitis', 'bloat_gdv', 'back_disc_disease', 'ear_infections_long_ears', 'weight_gain']);
  // RKC POAG DNA testing scheme (K23) → other_scheme.
  assert.equal(d.breeder_checklist.status, 'listed');
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage; the McMillan figure via Wikipedia (12.5 y, tier C) and the
  // glaucoma prevalence stay in research.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)|life expectancy of 12|5\.44/i);
});

test('Hungarian Vizsla (M5-R10-40): more than 2 hours (RKC), PDSA weight, FCI + AKC height, FCI group 7 Hungary, RKC "over 12 years", Coren 25, no welfare chip, hip / elbow screening', () => {
  const d = registry.breeds.find((b) => b.id === 'hungarian_vizsla');
  assert.deepEqual(d.name, { en: 'Hungarian Vizsla', sl: 'Madžarski kratkodlaki ptičar' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'large');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S328']]]);
  // A new FCI origin (Hungary).
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 57, group: 7, section: 1, origin: 'HU' });
  // FCI / AKC give no weight → PDSA (one range for both sexes).
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [20, 30], female: [20, 30] }, ['S329']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => f.source_ids[0]), ['S327', 'S331']);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 12, ['S328']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 25);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 108 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 9000 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family', 'family_pet', 'large_home']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets']);
  assert.deepEqual(d.health.map((h) => h.key), ['hip_dysplasia_arthritis', 'entropion', 'epilepsy', 'autoimmune_conditions', 'certain_cancers', 'hypothyroidism', 'bloat_gdv', 'ear_infections_prone']);
  // RKC: hip and elbow screening are Assured Breeders Scheme requirements → other_scheme.
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['hip_scheme', 'other_scheme'], ['elbow_scheme', 'other_scheme']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage; the McMillan figure via Wikipedia (13.5 y, tier C) stays in research.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)|life expectancy of 13/i);
});

test('English Cocker Spaniel (M5-R10-41): up to 1 hour (RKC), PDSA weight, FCI + AKC height, FCI group 8 Great Britain, VetCompass median, Coren 18, no welfare chip, RKC tests', () => {
  const d = registry.breeds.find((b) => b.id === 'english_cocker_spaniel');
  assert.deepEqual(d.name, { en: 'English Cocker Spaniel', sl: 'Angleški koker španjel' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'small');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S337']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 5, group: 8, section: 2, origin: 'GB' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [13, 14.5], female: [13, 14.5] }, ['S339']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => f.source_ids[0]), ['S336', 'S343']);
  // VetCompass breed median (Engdahl 2023, S342) first, then the RKC band.
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 11.44, ['S342']], ['more_than', 10, ['S337']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 18);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 103 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['family_pet', 'children', 'other_pets']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['frequent_grooming', 'food_motivated_weight', 'chews_when_bored', 'needs_company', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['hip_dysplasia_arthritis', 'itchy_skin_allergy', 'ear_infections_prone', 'pra_eye_disease', 'familial_nephropathy', 'autoimmune_conditions']);
  // RKC Good Practice (minimum) and Best Practice tests; new keys dna_aon and dna_fn.
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['inbreeding_coi', 'minimum'], ['dna_ams', 'minimum'], ['dna_prcd_pra', 'minimum'], ['hip_scheme', 'minimum'], ['dna_aon', 'best_practice'], ['dna_fn', 'best_practice'], ['eye_scheme', 'best_practice']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage; the VetCompass prevalence figures stay in research.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)|20\.97|9\.88/i);
});

test('Maltese (M5-R10-42): up to 30 minutes (RKC), FCI weight + height, FCI group 9 Italy, RKC "over 12 years", Coren 59, no welfare chip, never hypoallergenic, RKC eye scheme', () => {
  const d = registry.breeds.find((b) => b.id === 'maltese');
  assert.deepEqual(d.name, { en: 'Maltese', sl: 'Maltežan' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'small');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[30, 'up_to', ['S347']]]);
  // FCI patronage country Italy (origin: Central Mediterranean Area).
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 65, group: 9, section: 1, origin: 'IT' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [3, 4], female: [3, 4] }, ['S346']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => f.source_ids[0]), ['S346']);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 12, ['S347']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 59);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 3000, cap_month: 3 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 108 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 2300 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['apartment', 'low_shedding']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['frequent_grooming', 'needs_company']);
  assert.deepEqual(d.health.map((h) => h.key), ['kneecap_luxation', 'liver_shunt', 'heart_disease_pda', 'chiari_malformation', 'hydrocephalus', 'cataracts_entropion', 'periodontal_daily_brushing']);
  // RKC Best Practice only: the eye scheme.
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['eye_scheme', 'best_practice']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage; the McMillan figure via Wikipedia (13.1 y, tier C) stays in research.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)|life expectancy of 13/i);
});

test('Pug (M5-R10-43): up to 1 hour (RKC), FCI weight, PDSA height, FCI group 9 Great Britain, McMillan 11.6 y + RKC "over 10 years", Coren 57, flat-face and skin chips, RKC breed club tests + RFGS', () => {
  const d = registry.breeds.find((b) => b.id === 'pug');
  assert.deepEqual(d.name, { en: 'Pug', sl: 'Mops' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'small');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S355']]]);
  // FCI patronage country Great Britain (origin: China).
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 253, group: 9, section: 11, origin: 'GB' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [6.3, 8.1], female: [6.3, 8.1] }, ['S354']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => f.source_ids[0]), ['S357']);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 11.6, ['S54']], ['more_than', 10, ['S355']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 57);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 104 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['apartment', 'children', 'other_pets']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['brachycephalic_breathing', 'sensitive_skin', 'food_motivated_weight', 'needs_company', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['flat_face_breathing', 'heat_stroke_risk', 'skin_fold_infections', 'eye_problems_shallow_sockets', 'kneecap_luxation', 'weight_gain']);
  // RKC 'Important' list (all breeders) = minimum; the Respiratory Function Grading Scheme from Cambridge (K24).
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [
    ['inbreeding_coi', 'minimum'], ['patella_scheme', 'minimum'], ['breed_health_scheme', 'minimum'], ['hemivertebrae_scheme', 'minimum'], ['boas_grading', 'other_scheme'],
  ]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage; the young-population figure (7.65 y via Wikipedia) stays out.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)|7\.65/i);
});

test('Rough Collie (M5-R10-44): up to 1 hour (RKC), AKC weight, FCI height, FCI group 1 Great Britain, RKC "over 12 years", Coren 16, herding / brushing / company chips, RKC eye + hip schemes and four DNA tests', () => {
  const d = registry.breeds.find((b) => b.id === 'rough_collie');
  assert.deepEqual(d.name, { en: 'Rough Collie', sl: 'Dolgodlaki škotski ovčar' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'large');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S365']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 156, group: 1, section: 1, origin: 'GB' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [27.2, 34.0], female: [22.7, 29.5] }, ['S367']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [56, 61], female: [51, 56] }, ['S364']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 12, ['S365']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 16);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 108 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['children', 'other_pets']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['may_herd_children', 'frequent_grooming', 'needs_company', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['collie_eye_pra', 'drug_sensitivity_mdr1', 'degenerative_myelopathy', 'hip_dysplasia_arthritis']);
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [
    ['eye_scheme', 'minimum'], ['hip_scheme', 'minimum'], ['dna_cea', 'best_practice'], ['dna_dm', 'best_practice'], ['dna_mdr1', 'best_practice'], ['dna_pra_rcd2', 'best_practice'],
  ]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage; the AKC-only white colour never appears as a fact.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)/i);
});

test('Mastiff (M5-R10-45): at least 1 hour (PDSA, RKC page not reachable), PDSA weight and height, FCI group 2 Great Britain, McMillan median + PDSA "under 10 years", Coren 72, Category 3 welfare chips, no RKC tests found', () => {
  const d = registry.breeds.find((b) => b.id === 'mastiff');
  assert.deepEqual(d.name, { en: 'Mastiff', sl: 'Angleški mastif' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'large');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'at_least', ['S370']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 264, group: 2, section: 2, origin: 'GB' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [80, 85], female: [80, 85] }, ['S370']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [71, 78], female: [71, 78] }, ['S370']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 9, ['S54']], ['less_than', 10, ['S370']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank').value, 72);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 81 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['family_pet', 'children', 'other_pets']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['not_for_small_children', 'shorter_lifespan', 'eyelid_problems', 'sensitive_skin', 'hips_hind_legs', 'sheds', 'drools']);
  assert.deepEqual(d.health.map((h) => h.key), ['bloat_gdv', 'eyelid_problems', 'skin_allergy_face_folds', 'hip_elbow_dysplasia', 'heart_muscle_disease', 'wobbler_syndrome']);
  assert.equal(d.breeder_checklist.status, 'not_found');
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage or a hazard ratio.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)|HR 2\.3/i);
});

test('Shiba Inu (M5-R10-46): up to 1 hour (RKC), AKC average weight, FCI height, FCI group 5 Japan, McMillan median + RKC "over 10 years", not ranked by Coren, new independent_experienced_owner chip, RKC best-practice hip scheme + GM1 / GM2 DNA tests', () => {
  const d = registry.breeds.find((b) => b.id === 'shiba_inu');
  assert.deepEqual(d.name, { en: 'Shiba Inu', sl: 'Šiba inu' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'small_medium');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S378']]]);
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 257, group: 5, section: 5, origin: 'JP' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.qualifier, f.source_ids]), [[{ male: [10.4, 10.4], female: [7.7, 7.7] }, 'about', ['S380']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [38, 41], female: [35, 38] }, ['S377']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 14.6, ['S376']], ['more_than', 10, ['S378']]]);
  assert.equal(d.facts.find((f) => f.field === 'coren_rank'), undefined);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 131 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), []);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['independent_experienced_owner', 'older_children', 'needs_mental_stimulation', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['skin_ear_allergies', 'kneecap_luxation', 'gum_disease_daily_brushing', 'hip_dysplasia']);
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['hip_scheme', 'best_practice'], ['dna_gm1', 'best_practice'], ['dna_gm2', 'best_practice']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage or a hazard ratio.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|\d %\)|HR 0\.5/i);
});

test('Rhodesian Ridgeback (M5-R10-47): more than 2 hours (RKC), FCI weight + height, FCI group 6 South Africa, RKC "over 10 years", Coren 52, no welfare chip, dermoid sinus + hip / elbow + DNA JME / DM breeder checks', () => {
  const d = registry.breeds.find((b) => b.id === 'rhodesian_ridgeback');
  assert.deepEqual(d.name, { en: 'Rhodesian Ridgeback', sl: 'Rodezijski ridgeback' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'large');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S387']]]);
  // New FCI origin South Africa (ZA, FCI nomenclature S384 / standard S385).
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 146, group: 6, section: 3, origin: 'ZA' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.qualifier, f.source_ids]), [[{ male: [36.5, 36.5], female: [32, 32] }, 'about', ['S385']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [63, 69], female: [61, 66] }, ['S385']]]);
  // No McMillan 2024 / VetCompass median reachable → only the RKC band (the Wikipedia 12 y is never exported).
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 10, ['S387']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'coren_rank').map((f) => [f.value, f.source_ids]), [[52, ['S35']]]);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 90 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 9000 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family', 'family_pet', 'large_home']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets']);
  assert.deepEqual(d.health.map((h) => h.key), ['dermoid_sinus', 'hip_elbow_dysplasia', 'bloat_gdv', 'hypothyroidism']);
  assert.deepEqual(d.breeder_checklist.tests.map((t) => t.key), ['dermoid_sinus_scheme', 'hip_scheme', 'elbow_scheme', 'dna_jme', 'dna_dm']);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage, a prevalence or a hazard ratio; never the tier-C Wikipedia lifespan.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|prevalence|\d %\)|mcmillan_2024/i);
});

test('Papillon (M5-R10-48): up to 30 minutes (RKC), FCI weight span + height, FCI group 9 Belgium / France, RKC "over 12 years", Coren 8, no welfare chip, Pap-PRA1 / vWD1 / NAD breeder checks', () => {
  const d = registry.breeds.find((b) => b.id === 'papillon');
  assert.deepEqual(d.name, { en: 'Papillon', sl: 'Papijon' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'small');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[30, 'up_to', ['S397']]]);
  // New FCI origin: the nomenclature names Belgium and France (S395) → BE-FR.
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 77, group: 9, section: 9, origin: 'BE-FR' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [1.5, 4.5], female: [1.5, 5] }, ['S396']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [28, 28], female: [28, 28] }, ['S396']]]);
  // No McMillan 2024 / VetCompass median reachable → only the RKC band (the Wikipedia 14.5 y is never exported).
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 12, ['S397']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'coren_rank').map((f) => [f.value, f.source_ids]), [[8, ['S35']]]);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 3000, cap_month: 3 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 108 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 2300 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['apartment', 'family_pet', 'children', 'other_pets']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['frequent_grooming', 'needs_mental_stimulation', 'needs_company', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['kneecap_luxation', 'pra_inherited_blindness', 'bleeding_disorder_vwd', 'cataracts', 'open_fontanelle', 'collapsing_trachea', 'periodontal_daily_brushing']);
  assert.deepEqual(d.breeder_checklist.tests.map((t) => t.key), ['dna_pap_pra1', 'dna_vwd1', 'dna_nad']);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage, a prevalence or a hazard ratio; never the tier-C Wikipedia lifespan.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|prevalence|\d %\)|mcmillan_2024/i);
});

test('Portuguese Water Dog (M5-R10-49): up to 1 hour (RKC), RKC standard weight + height, FCI group 8 Portugal, RKC "over 10 years", not ranked by Coren, no welfare chip, low shedding (never hypoallergenic), RKC breeder checks', () => {
  const d = registry.breeds.find((b) => b.id === 'portuguese_water_dog');
  assert.deepEqual(d.name, { en: 'Portuguese Water Dog', sl: 'Portugalski vodni pes' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'medium');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'up_to', ['S404']]]);
  // New FCI origin: Portugal (S402) → PT.
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 37, group: 8, section: 3, origin: 'PT' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [19, 25], female: [16, 22] }, ['S403']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [50, 57], female: [43, 52] }, ['S403']]]);
  // No McMillan 2024 / VetCompass median found → only the RKC band.
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 10, ['S404']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'coren_rank'), []);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 90 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['family_pet', 'children', 'large_home', 'other_pets', 'low_shedding']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['frequent_grooming']);
  assert.deepEqual(d.health.map((h) => h.key), ['hip_dysplasia_arthritis', 'pra_inherited_blindness', 'gm1_gangliosidosis', 'bloat_gdv', 'hypothyroidism', 'addisons_disease', 'cataracts', 'periodontal_daily_brushing']);
  assert.deepEqual(d.breeder_checklist.tests.map((t) => t.key), ['inbreeding_coi', 'dna_prcd_pra', 'elbow_scheme', 'hip_scheme', 'dna_gm1', 'dna_eopra']);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage, a prevalence or a hazard ratio.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|prevalence|\d %\)|mcmillan_2024/i);
});

test('Bichon Frise (M5-R10-50): up to 30 minutes (RKC), FCI height + PDSA weight, FCI group 9 Belgium / France, RKC "over 12 years", Coren 45, no welfare chip, low shedding (never hypoallergenic), RKC eye scheme + US club patella / hip checks', () => {
  const d = registry.breeds.find((b) => b.id === 'bichon_frise');
  assert.deepEqual(d.name, { en: 'Bichon Frise', sl: 'Kodrasti bišon' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'small');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[30, 'up_to', ['S411']]]);
  // FCI 215: Belgium and France (S409) → BE-FR, as the Papillon.
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 215, group: 9, section: 1, origin: 'BE-FR' });
  // The FCI gives only "Approximately 5 kg" → the PDSA range (S413) is the weight fact.
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [4, 7], female: [4, 7] }, ['S413']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [25, 30], female: [23, 29] }, ['S410']]]);
  // No McMillan 2024 / VetCompass median reachable → only the RKC band (the Wikipedia 12.5 y is never exported).
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['more_than', 12, ['S411']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'coren_rank').map((f) => [f.value, f.source_ids]), [[45, ['S35']]]);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 3000, cap_month: 3 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 108 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 2300 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['apartment', 'family_pet', 'children', 'other_pets', 'low_shedding']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['frequent_grooming', 'needs_company']);
  assert.deepEqual(d.health.map((h) => h.key), ['kneecap_luxation', 'itchy_skin_allergy', 'cataracts', 'bladder_stones', 'liver_shunt', 'diabetes_mellitus', 'dental_disease_home_brushing']);
  assert.deepEqual(d.breeder_checklist.tests.map((t) => t.key), ['eye_scheme', 'patella_scheme', 'hip_scheme']);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage, a prevalence or a ratio; never the tier-C Wikipedia lifespan.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|prevalence|times more likely|\d %\)|mcmillan_2024/i);
});

test('Newfoundland (M5-R10-51): at least 60 minutes (PDSA), PDSA height + weight, FCI group 2 Canada, McMillan 2024 median + PDSA "under 10 years", Coren 34, Category 2 eyelid chip, daily brushing, Kennel Club BHCP breeder tests', () => {
  const d = registry.breeds.find((b) => b.id === 'newfoundland');
  assert.deepEqual(d.name, { en: 'Newfoundland', sl: 'Novofundlandec' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'large');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'at_least', ['S423']]]);
  // FCI 50: Canada (S419) → CA, a new origin.
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 50, group: 2, section: 2, origin: 'CA' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [50, 69], female: [50, 69] }, ['S423']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [66, 71], female: [66, 71] }, ['S423']]]);
  // McMillan 2024 via the Dogs Trust table (S123) and the PDSA band.
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 11, ['S123']], ['less_than', 10, ['S423']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'coren_rank').map((f) => [f.value, f.source_ids]), [[34, ['S35']]]);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 99 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['family_pet', 'children', 'other_pets']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['shorter_lifespan', 'eyelid_problems', 'needs_company', 'sheds', 'frequent_grooming', 'drools']);
  assert.deepEqual(d.health.map((h) => h.key), ['bloat_gdv', 'eyelids_turned', 'hip_elbow_dysplasia', 'heart_muscle_disease']);
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['dna_cu', 'minimum'], ['hip_scheme', 'minimum'], ['elbow_scheme', 'minimum'], ['heart_echo_scheme', 'other_scheme']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage, a prevalence or a ratio; never gray (AKC only).
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|prevalence|times more likely|\d %\)|62%|gray/i);
});

test('Dalmatian (M5-R10-52): more than 2 hours (RKC), FCI height + PDSA weight, FCI group 6 Croatia, McMillan 2024 median + RKC "over 12 years", Coren 39, Category 1 (no welfare chip), weekly brushing, US breed club BAER + hip tests', () => {
  const d = registry.breeds.find((b) => b.id === 'dalmatian');
  assert.deepEqual(d.name, { en: 'Dalmatian', sl: 'Dalmatinec' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'large');
  assert.equal(d.facets.exercise, 'over_2h');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S429']]]);
  // FCI 153: Croatia (S427 / S428) → HR, a new origin.
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 153, group: 6, section: 3, origin: 'HR' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [18, 27], female: [18, 27] }, ['S430']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [56, 62], female: [54, 60] }, ['S428']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 13.2, ['S123']], ['more_than', 12, ['S429']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'coren_rank').map((f) => [f.value, f.source_ids]), [[39, ['S35']]]);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 119 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 9000 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family', 'large_home']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'not_for_small_children', 'needs_company', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['deafness', 'bladder_stones', 'itchy_skin_allergy', 'epilepsy', 'hip_dysplasia']);
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['hearing_baer', 'other_scheme'], ['hip_scheme', 'other_scheme']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage, a prevalence or a ratio; never a non-standard colour.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|prevalence|times more likely|\d %\)|\d%|tricolou?r|brindle/i);
});

test('Australian Cattle Dog (M5-R10-53): more than 2 hours (RKC), KC height + PDSA weight, FCI group 1 Australia, McMillan 2024 median + RKC "over 10 years", Coren 10, Category 1 (no welfare chip), weekly brushing, RKC parents\' tests', () => {
  const d = registry.breeds.find((b) => b.id === 'australian_cattle_dog');
  assert.deepEqual(d.name, { en: 'Australian Cattle Dog', sl: 'Avstralski govedar' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'medium');
  assert.equal(d.facets.exercise, 'over_2h');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[120, 'more_than', ['S437']]]);
  // FCI 287: Australia (S435) → AU, a new origin.
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 287, group: 1, section: 2, origin: 'AU' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [16, 20], female: [16, 20] }, ['S438']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [46, 51], female: [43, 48] }, ['S436']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 14, ['S123']], ['more_than', 10, ['S437']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'coren_rank').map((f) => [f.value, f.source_ids]), [[10, ['S35']]]);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 12000, cap_month: 12 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 126 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 9000 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['active_family', 'family_pet']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['long_daily_exercise', 'needs_mental_stimulation', 'may_herd_children', 'sheds']);
  assert.deepEqual(d.health.map((h) => h.key), ['deafness', 'pra_eye_disease', 'hip_elbow_dysplasia', 'itchy_skin_allergy', 'liver_shunt', 'kneecap_luxation', 'bladder_stones']);
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['dna_prcd_pra', 'minimum'], ['eye_scheme', 'minimum'], ['hip_scheme', 'minimum'], ['elbow_scheme', 'minimum'], ['hearing_baer', 'minimum'], ['inbreeding_coi', 'minimum']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage, a prevalence or a ratio; never a non-standard colour.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|prevalence|times more likely|\d %\)|\d%|merle|cream/i);
});

test('Whippet (M5-R10-54): at least 60 minutes (PDSA), KC height + PDSA weight, FCI group 10 Great Britain, McMillan 2024 median + PDSA "over 12 years", Coren 51, no welfare chip, new sensitive_older_children chip, weekly brushing, US breed club + Kennel Club heart advice for the parents\' tests', () => {
  const d = registry.breeds.find((b) => b.id === 'whippet');
  assert.deepEqual(d.name, { en: 'Whippet', sl: 'Mali angleški hrt' });
  assert.equal(d.availability, 'coming_soon');
  assert.equal(d.facets.size, 'medium');
  assert.deepEqual(d.facts.filter((f) => f.field === 'exercise').map((f) => [f.value, f.qualifier, f.source_ids]), [[60, 'at_least', ['S444']]]);
  // FCI 162: Great Britain (S442) → GB.
  assert.deepEqual(d.facts.find((f) => f.field === 'fci_standard').value, { number: 162, group: 10, section: 3, origin: 'GB' });
  assert.deepEqual(d.facts.filter((f) => f.field === 'weight').map((f) => [f.value, f.source_ids]), [[{ male: [11, 18], female: [11, 18] }, ['S444']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'height').map((f) => [f.value, f.source_ids]), [[{ male: [47, 51], female: [44, 47] }, ['S443']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'lifespan').map((f) => [f.qualifier, f.value, f.source_ids]), [['median', 13.4, ['S123']], ['more_than', 12, ['S444']]]);
  assert.deepEqual(d.facts.filter((f) => f.field === 'coren_rank').map((f) => [f.value, f.source_ids]), [[51, ['S35']]]);
  assert.deepEqual(d.game.stages[0].activity, { kind: 'steps_growing', per_month: 1000, first: 2000, cap: 6000, cap_month: 6 });
  assert.deepEqual(d.game.stages[3], { stage: 'senior', starts: { month: 121 }, meals: [{ from_months: null, meals: 2 }], activity: { kind: 'steps', value: 4500 } });
  assert.deepEqual(d.suitability.suits.map((t) => t.tag), ['family_pet', 'children']);
  assert.deepEqual(d.suitability.consider.map((t) => t.tag), ['sensitive_older_children', 'needs_company', 'chases_small_pets']);
  assert.equal(registry.suitability_vocabulary.sensitive_older_children, 'consider');
  assert.equal(registry.suitability_labels.sl.tags.sensitive_older_children, 'občutljiv pes — bolje za starejše otroke');
  assert.deepEqual(d.health.map((h) => h.key), ['pra_eye_disease', 'mitral_valve_disease', 'autoimmune_conditions', 'bloat_gdv', 'bleeding_disorder_vwd', 'deafness']);
  assert.deepEqual(d.breeder_checklist.tests.map((t) => [t.key, t.level]), [['heart_scheme', 'other_scheme'], ['eye_scheme', 'other_scheme'], ['hearing_baer', 'other_scheme']]);
  const json = JSON.stringify(d);
  // Never "hypoallergenic", never a percentage, a prevalence or a ratio; never merle.
  assert.doesNotMatch(json, /hypoallergenic|odds ratio|prevalence|times more likely|\d %\)|\d%|merle/i);
});
