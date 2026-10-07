/**
 * Every language has exactly the English keys (plural keys compared by their base and
 * checked against the language's CLDR categories) and the same `{{placeholders}}`.
 */
import { NAMESPACES, resources } from '@/i18n/resources';
import { SUPPORTED_LANGUAGES, type Language } from '@/i18n/language';

type Tree = { [key: string]: string | Tree };

const PLURAL_SUFFIX = /_(zero|one|two|few|many|other)$/;

function flatten(tree: Tree, prefix = ''): Map<string, string> {
  const out = new Map<string, string>();
  for (const [key, value] of Object.entries(tree)) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (typeof value === 'string') out.set(path, value);
    else for (const [k, v] of flatten(value, path)) out.set(k, v);
  }
  return out;
}

/** base key → the plural categories present ([] for a non-plural key). */
function grouped(flat: Map<string, string>): Map<string, string[]> {
  const out = new Map<string, string[]>();
  for (const key of flat.keys()) {
    const match = key.match(PLURAL_SUFFIX);
    const base = match ? key.slice(0, -match[0].length) : key;
    const list = out.get(base) ?? [];
    if (match) list.push(match[1]);
    out.set(base, list);
  }
  return out;
}

function placeholders(value: string): string[] {
  return [...value.matchAll(/\{\{\s*([\w.]+)[^}]*\}\}/g)].map((m) => m[1]).sort();
}

function pluralCategories(language: Language): string[] {
  return [...new Intl.PluralRules(language).resolvedOptions().pluralCategories].sort();
}

const others = SUPPORTED_LANGUAGES.filter((l) => l !== 'en');

describe.each(NAMESPACES)('namespace %s', (ns) => {
  const enFlat = flatten(resources.en[ns] as Tree);
  const enGroups = grouped(enFlat);

  it.each(others)('%s has the same keys as English', (language) => {
    const flat = flatten(resources[language][ns] as Tree);
    const groups = grouped(flat);
    expect([...groups.keys()].sort()).toEqual([...enGroups.keys()].sort());
    for (const [base, enCats] of enGroups) {
      if (enCats.length === 0) {
        expect({ key: base, plural: groups.get(base) }).toEqual({ key: base, plural: [] });
      } else {
        // A plural key carries exactly the language's CLDR categories.
        expect({ key: base, cats: [...(groups.get(base) ?? [])].sort() }).toEqual({
          key: base,
          cats: pluralCategories(language),
        });
      }
    }
  });

  it('English plural keys carry exactly one/other', () => {
    for (const [base, cats] of enGroups) {
      if (cats.length > 0) expect({ key: base, cats: [...cats].sort() }).toEqual({ key: base, cats: ['one', 'other'] });
    }
  });

  it.each(others)('%s uses the same placeholders', (language) => {
    const flat = flatten(resources[language][ns] as Tree);
    for (const [key, value] of flat) {
      const base = key.replace(PLURAL_SUFFIX, '');
      const enValue = enFlat.get(key) ?? enFlat.get(`${base}_other`) ?? '';
      // `{{count}}` may be left out only in the "one"/"two" forms ("en pes", "dva psa").
      const countOptional = /_(one|two)$/.test(key);
      const strip = (vars: string[]) => (countOptional ? vars.filter((p) => p !== 'count') : vars);
      expect({ key, vars: strip(placeholders(value)) }).toEqual({ key, vars: strip(placeholders(enValue)) });
    }
  });

  it.each(SUPPORTED_LANGUAGES)('%s has no empty strings', (language) => {
    for (const [key, value] of flatten(resources[language][ns] as Tree)) {
      expect({ key, empty: value.trim() === '' }).toEqual({ key, empty: false });
    }
  });
});
