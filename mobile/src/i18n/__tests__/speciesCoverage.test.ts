/**
 * M5-R06_PLAN T8 / M5-R06-08c — species coverage of the translations.
 *
 * The dog texts stay where they are (byte-identical); a cat's text that differs lives in
 * `cat:override.<namespace>.<key>` (the child app reads it while its pet is a cat, a parent
 * screen through `tSpecies(key, pet.species)`), the picker's in `pet:picker.cat.*`.
 *
 * 1. Every key of the checked namespaces whose English or Slovenian text names a dog
 *    ("kuža", "pes", "pup", "dog" …) has a cat variant — or is in `DOG_ONLY` with a reason.
 * 2. No cat text names a dog.
 * 3. English and Slovenian have the same cat keys; every override replaces an existing dog
 *    key, with the same `{{placeholders}}` and the language's plural forms.
 */
import { NAMESPACES, resources } from '@/i18n/resources';
import i18n, { tSpecies } from '@/i18n';

type Tree = { [key: string]: string | Tree };
type Lang = 'en' | 'sl';
const LANGS: readonly Lang[] = ['en', 'sl'];

/** Dog nouns (sl declensions too), whole words, any case. "pesek" (litter) is not a dog. */
const DOG_WORD =
  /(?<![\p{L}\p{N}_])(kuža|kužek|kužka|kužku|kužkom|kužki|kužkov|kužke|kužko|kužo|pes|psa|psu|psom|psi|psov|psoma|psih|pse|mladiček|mladička|mladičku|mladičkom|mladiči|mladičev|kuži|kuže|kužu|kužkih|kužkoma|pup|pups|puppy|puppies|dog|dogs|doggy)(?![\p{L}\p{N}_])/iu;

/** Every namespace except `cat` itself (child-facing and parent texts alike, QA 08c n1). */
const CHECKED = NAMESPACES.filter((ns) => ns !== 'cat');

/**
 * Dog texts a cat never gets, or that name no single pet — with the reason. A key matches an
 * entry equal to it or below it (`training:child` covers `training:child.title`).
 */
const DOG_ONLY: Readonly<Record<string, string>> = {
  // ── Dog-only features: a cat never sees them ──
  'child:walk': 'walk / steps — a cat plays with the feather wand instead (CAT_SPEC Q1)',
  'child:actions.refused.needsTidying': 'chewed slipper — a cat scratches the sofa instead ("Najprej praskalnik")',
  'behaviour:child.takeOutNotNeeded': 'potty training (take out) — dogs only',
  'behaviour:child.takeOutIn': 'potty training (take out) — dogs only',
  'behaviour:child.takeOutAt': 'potty training (take out) — dogs only',
  'behaviour:child.takeOutNow': 'potty training (take out) — dogs only',
  'behaviour:child.success.take_out': 'potty training (take out) — dogs only',
  'behaviour:child.unchanged.take_out': 'potty training (take out) — dogs only',
  'behaviour:child.success.resolve_chewing': 'chewed slipper — dogs only',
  'behaviour:child.scene.accident': 'puppy puddle — a kitten has no accident (CAT_SPEC, R06-05)',
  'behaviour:child.scene.chewing': 'chewed slipper — dogs only',
  'behaviour:parent.takeOut': 'potty training (take out) — dogs only',
  'play:play': 'ball play — dogs only (no ball for a cat, David 2026-10-08)',
  training: 'dog school — a cat gets `training_not_available` (M5-R06-04)',
  'family:activities.took_out_pet': 'potty training (take out) — dogs only',
  'family:systemActivities.pet_accident': 'puppy puddle — dogs only',
  'family:systemActivities.pet_chewed': 'chewed slipper — dogs only',
  'pet:picker.breedHints.mutt': 'the mutt is a dog breed',
  'pet:picker.ageHints.mutt': 'the mutt is a dog breed',
  // ── Names the species on purpose ──
  'family:species.dog': 'the species name "Dog" / "Pes"',
  'family:breedUnknown.dog': 'the species name of an unknown dog breed',
  'pet:picker.speciesIntro': 'compares both species ("a dog goes for walks, a cat …")',
  'parent:addChild.newPetAnyHint': 'names both species ("a dog or a cat")',
  // ── Shown only while dogs are the only species; with cats on offer the "Any" key is used ──
  'parent:addChild.newPet': 'dog-only catalogue; with cats → `newPetAny`',
  'parent:addChild.newPetHint': 'dog-only catalogue; with cats → `newPetAnyHint`',
  'parent:addChild.petTitle': 'dog-only catalogue; with cats → `petTitleAny`',
  'parent:addChild.petsLoading': 'dog-only catalogue; with cats → `petsLoadingAny`',
  // ── Payments for a cat (the paywall and the paid-challenge texts) — R06-09 (PAYMENTS_SPEC) ──
  // The other family-level texts are neutral ("pet" / "ljubljenček") since M5-R06-08d (David 2026-10-09).
  'family:children.deleteErrors.paid_challenge': '(D) payments for a cat — R06-09 (PAYMENTS_SPEC)',
  'account:card.deleteErrors.paid_challenge': '(D) payments for a cat — R06-09 (PAYMENTS_SPEC)',
  'account:deletionForm.paidWarning': '(D) payments for a cat — R06-09 (PAYMENTS_SPEC)',
  paywall: '(D) the challenge for a cat — R06-09 (PAYMENTS_SPEC "izziv za mačko")',
};

function flatten(tree: Tree, prefix = ''): Map<string, string> {
  const out = new Map<string, string>();
  for (const [key, value] of Object.entries(tree)) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (typeof value === 'string') out.set(path, value);
    else for (const [k, v] of flatten(value, path)) out.set(k, v);
  }
  return out;
}

/** Whether a text names a dog (`{{placeholders}}` such as `{{dogs}}` are not text). */
const namesDog = (value: string) => DOG_WORD.test(value.replace(/\{\{[^}]*\}\}/g, ''));

const PLURAL = /_(zero|one|two|few|many|other)$/;
const base = (key: string) => key.replace(PLURAL, '');
const placeholders = (value: string) => [...value.matchAll(/\{\{\s*([\w.]+)[^}]*\}\}/g)].map((m) => m[1]).sort();

const tree = (lang: Lang, ns: string) => (resources[lang] as unknown as Record<string, Tree>)[ns];
const flat = (lang: Lang, ns: string) => flatten(tree(lang, ns));
const catFlat: Record<Lang, Map<string, string>> = { en: flat('en', 'cat'), sl: flat('sl', 'cat') };

/** `cat:override.<ns>.<key>` base keys of a language (`<ns>:<key>`). */
function overrideKeys(lang: Lang): Set<string> {
  const out = new Set<string>();
  for (const key of catFlat[lang].keys()) {
    if (key.startsWith('override.')) {
      const rest = base(key.slice('override.'.length));
      const dot = rest.indexOf('.');
      out.add(`${rest.slice(0, dot)}:${rest.slice(dot + 1)}`);
    }
  }
  return out;
}

/** The cat variant of a dog key, if any (an override, or the picker's `pet:picker.cat.*`). */
function hasCatVariant(lang: Lang, key: string): boolean {
  if (overrideKeys(lang).has(key)) return true;
  if (key.startsWith('pet:picker.')) {
    const picker = flat(lang, 'pet');
    const catKey = `picker.cat.${key.slice('pet:picker.'.length)}`;
    return [...picker.keys()].some((k) => base(k) === catKey);
  }
  return false;
}

function dogOnlyReason(key: string): string | undefined {
  const entry = Object.keys(DOG_ONLY).find((prefix) => key === prefix || key.startsWith(`${prefix}.`) || key.startsWith(`${prefix}:`));
  return entry === undefined ? undefined : DOG_ONLY[entry];
}

/** Every `<ns>:<baseKey>` of the checked namespaces whose text (any language, any plural form) names a dog. */
function dogKeys(): Set<string> {
  const out = new Set<string>();
  for (const ns of CHECKED) {
    for (const lang of LANGS) {
      for (const [key, value] of flat(lang, ns)) {
        if (ns === 'pet' && key.startsWith('picker.cat.')) continue; // the cat variants themselves
        if (namesDog(value)) out.add(`${ns}:${base(key)}`);
      }
    }
  }
  return out;
}

describe('T8 species coverage (M5-R06-08c)', () => {
  it('the dog-word check finds the known dog keys (sanity)', () => {
    const keys = dogKeys();
    expect(keys.has('child:hud.loading')).toBe(true); // "Fetching your pup …" / "Nalagam kužka …"
    expect(keys.has('family:petStatus.ill')).toBe(true); // "The dog is at the vet"
    expect(keys.has('parent:dashboard.counts.dogs')).toBe(true); // "1 kuža"
    expect(keys.has('push:push.channels.default')).toBe(true); // "Dog reminders"
    expect(DOG_WORD.test('Počisti pesek')).toBe(false);
    expect(DOG_WORD.test('Kuža je sit.')).toBe(true);
  });

  it.each(LANGS)('every dog text has a cat variant or a dog-only reason (%s)', (lang) => {
    const missing = [...dogKeys()].filter((key) => !hasCatVariant(lang, key) && dogOnlyReason(key) === undefined);
    expect(missing).toEqual([]);
  });

  it('the parent strings of 08c have a cat variant (not a dog-only entry)', () => {
    const required = [
      'parent:dashboard.counts.dogs',
      'account:counts.dogs',
      'family:petStatus.game_over',
      'family:petStatus.awaiting_contract',
      'family:petStatus.inactive',
      'family:petStatus.ill',
      'family:reasons.game_over',
      'family:reasons.phase3_alarm',
      'family:reasons.fell_ill_today',
      'family:activities.fed_pet',
      'parent:addChild.editDog',
      'parent:addChild.changeDog',
      'parent:childCard.pet',
      'parent:childCard.awaitingContract',
      'parent:childDetail.album',
      'pet:media.pendingParent',
      'pet:album.parentTitle',
      'pet:profile.stages.puppy',
      'pet:profile.stagesLower.young',
      'push:push.channels.default',
    ];
    for (const lang of LANGS) {
      for (const key of required) expect({ lang, key, cat: hasCatVariant(lang, key) }).toEqual({ lang, key, cat: true });
    }
  });

  it('every dog-only entry still matches a dog text (no stale entries)', () => {
    const keys = [...dogKeys()];
    const stale = Object.keys(DOG_ONLY).filter(
      (prefix) => !keys.some((key) => key === prefix || key.startsWith(`${prefix}.`) || key.startsWith(`${prefix}:`)),
    );
    expect(stale).toEqual([]);
  });

  it.each(LANGS)('no cat text names a dog (%s)', (lang) => {
    const catTexts = [...catFlat[lang]].concat([...flat(lang, 'pet')].filter(([k]) => k.startsWith('picker.cat.')));
    const dogWords = catTexts.filter(([, value]) => namesDog(value)).map(([key, value]) => `${key}: ${value}`);
    expect(dogWords).toEqual([]);
  });

  it('English and Slovenian have the same cat keys', () => {
    const keys = (lang: Lang) => [...new Set([...catFlat[lang].keys()].map(base))].sort();
    expect(keys('sl')).toEqual(keys('en'));
  });

  it.each(LANGS)('every override replaces an existing dog key with the same placeholders (%s)', (lang) => {
    const problems: string[] = [];
    for (const [key, value] of catFlat[lang]) {
      if (!key.startsWith('override.')) continue;
      const rest = key.slice('override.'.length);
      const dot = rest.indexOf('.');
      const ns = rest.slice(0, dot);
      if (!(NAMESPACES as readonly string[]).includes(ns)) {
        problems.push(`${key}: unknown namespace`);
        continue;
      }
      const dog = flat(lang, ns);
      const dogKey = rest.slice(dot + 1);
      const original = dog.get(dogKey) ?? dog.get(`${base(dogKey)}_other`);
      if (original === undefined) {
        problems.push(`${key}: no dog key ${ns}:${dogKey}`);
        continue;
      }
      // `{{count}}` may be left out only in the "one"/"two" forms ("1 muca"), as in parity.test.ts.
      const strip = (vars: string[]) => (/_(one|two)$/.test(dogKey) ? vars.filter((p) => p !== 'count') : vars);
      if (strip(placeholders(value)).join() !== strip(placeholders(original)).join()) {
        problems.push(`${key}: {{${placeholders(value).join(',')}}} ≠ dog {{${placeholders(original).join(',')}}}`);
      }
    }
    expect(problems).toEqual([]);
  });

  it.each(LANGS)('a plural override carries the same plural forms as its dog key (%s)', (lang) => {
    const forms = (map: Map<string, string>, prefix: string, b: string) =>
      [...map.keys()].filter((k) => k.startsWith(prefix) && base(k.slice(prefix.length)) === b).map((k) => k.slice(prefix.length)).sort();
    for (const key of catFlat[lang].keys()) {
      if (!key.startsWith('override.') || !PLURAL.test(key)) continue;
      const rest = key.slice('override.'.length);
      const dot = rest.indexOf('.');
      const ns = rest.slice(0, dot);
      const b = base(rest.slice(dot + 1));
      expect({ key: b, forms: forms(catFlat[lang], `override.${ns}.`, b) }).toEqual({ key: b, forms: forms(flat(lang, ns), '', b) });
    }
  });
});

describe('family-level texts are species-neutral (M5-R06-08d, David 2026-10-09)', () => {
  const NEUTRAL = [
    'family:children.noPet',
    'parent:childCard.noPet',
    'parent:childDetail.noPet',
    'family:children.deletePets',
    'family:children.keptPets',
    'family:join.hint',
    'family:join.errors.family_not_empty',
    'family:parents.inviteHint',
    'parent:controls.pets',
    'parent:quietHours.hint',
    'parent:notifications.status.on',
    'parent:notifications.status.off',
    'push:push.prePrompt.parent.title',
    'push:push.prePrompt.parent.message',
    'push:push.channels.shared',
    'account:card.exportHint',
    'account:card.lastParentLines.records',
    'account:card.otherParentStaysLines.family',
    'parent:addChild.errors.already_paired',
  ];

  it.each(LANGS)('names no dog and needs no cat override (%s)', (lang) => {
    for (const key of NEUTRAL) {
      const [ns, rest] = key.split(':');
      const texts = [...flat(lang, ns)].filter(([k]) => base(k) === rest).map(([, v]) => v);
      expect({ key, found: texts.length > 0 }).toEqual({ key, found: true });
      for (const text of texts) expect({ key, text, dog: namesDog(text) }).toEqual({ key, text, dog: false });
      expect({ key, override: overrideKeys(lang).has(key) }).toEqual({ key, override: false });
    }
  });

  it('uses the Slovenian forms of "ljubljenček"', async () => {
    await i18n.changeLanguage('sl');
    try {
      expect(i18n.t('family:children.noPet')).toBe('Še brez ljubljenčka');
      expect(i18n.t('parent:controls.pets')).toBe('Ljubljenčki');
      expect(i18n.t('family:children.deletePets', { count: 1 })).toMatch(/^Ljubljenček, za katerega/);
      expect(i18n.t('family:children.deletePets', { count: 2 })).toMatch(/^Ljubljenčka, za katera skrbi sam \(2\), se izbrišeta/);
      expect(i18n.t('family:children.keptPets', { count: 2 })).toMatch(/^Skupna ljubljenčka \(2\) ostaneta/);
      expect(i18n.t('family:children.keptPets', { count: 3 })).toMatch(/^Skupni ljubljenčki \(3\) ostanejo/);
      expect(i18n.t('push:push.channels.shared')).toBe('Opomniki za ljubljenčke');
    } finally {
      await i18n.changeLanguage('en');
    }
  });
});

describe('tSpecies (parent texts by the shown pet, M5-R06-08c)', () => {
  afterEach(async () => {
    await i18n.changeLanguage('en');
  });

  it('keeps exactly the dog text for a dog or an unknown species', async () => {
    await i18n.changeLanguage('sl');
    expect(tSpecies('family:petStatus.ill', 'dog')).toBe('Kuža je pri veterinarju');
    expect(tSpecies('family:petStatus.ill', null)).toBe('Kuža je pri veterinarju');
    expect(tSpecies('parent:dashboard.counts.dogs', undefined, { count: 3 })).toBe('3 kužki');
  });

  it('reads the cat override for a cat, plurals included', async () => {
    await i18n.changeLanguage('sl');
    expect(tSpecies('family:petStatus.ill', 'cat')).toBe('Muca je pri veterinarju');
    expect(tSpecies('parent:dashboard.counts.dogs', 'cat', { count: 1 })).toBe('1 muca');
    expect(tSpecies('parent:dashboard.counts.dogs', 'cat', { count: 2 })).toBe('2 muci');
    expect(tSpecies('parent:dashboard.counts.dogs', 'cat', { count: 3 })).toBe('3 muce');
    expect(tSpecies('parent:dashboard.counts.dogs', 'cat', { count: 5 })).toBe('5 muc');
    await i18n.changeLanguage('en');
    expect(tSpecies('pet:album.parentTitle', 'cat')).toBe("Cat's photos and videos");
  });

  it('falls back to the dog text when a cat has no override (species-neutral text)', () => {
    expect(tSpecies('family:petStatus.hard_stopped', 'cat')).toBe('Game paused (hard stop)');
  });
});
