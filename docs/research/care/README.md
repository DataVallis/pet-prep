# Care knowledge — sourced content (M6-K)

Plan: `docs/product/CARE_KNOWLEDGE_SPEC.md`. **Content lives here in git**; `scripts/export-care-knowledge.mjs`
builds `docs/research/care-knowledge.json`, which the website (`DataVallis/pet-prep-website`) syncs like the
breed register. The app import (M6-K3) comes later and will read the same files.

Rules (same as breed facts, stricter for health):
- every statement comes from a source in `sources-<species>.md` and is backed by a **verbatim quote** in the file;
- an **independent verifier** (an agent that did not write the text) checks every language against the quotes
  and writes `verified_hash`; only `verified` locales are published;
- no doses, no "induce vomiting", no diagnoses, never "hypoallergenic"; the child text never frightens
  (no "can kill", no skulls);
- when sources disagree, pick the **stricter** level and say so in `why`;
- pages carry the "not vet-reviewed" note (David 2026-10-31).

## Layout

```
care/
  sources-dog.md      # KD1, KD2 … (tier A/B/C as in dog-data/sources.md)
  sources-cat.md      # KC1, KC2 …
  foods/<species>/<slug>.json
```

A new species = a new folder + its `sources-<species>.md`. A new language = new keys in every
text map (`"de": "…"`), each verified on its own — no schema change (David 2026-10-31: 10–15 languages at once).

## Food file — `foods/<species>/<slug>.json`

```json
{
  "slug": "chocolate",
  "species": "dog",
  "name": {"en": "Chocolate", "sl": "Čokolada"},
  "question": {"en": "Can dogs eat chocolate?", "sl": "Ali lahko psi jedo čokolado?"},
  "url_slug": {"en": "chocolate", "sl": "cokolada"},
  "level": "toxic",
  "answer": {"en": "≤ 50 words, direct answer", "sl": "…"},
  "why": {"en": "…", "sl": "…"},
  "signs": {"en": ["…"], "sl": ["…"]},
  "what_to_do": {"en": "…", "sl": "…"},
  "serving": null,
  "child": {"en": "≤ 25 words, kind", "sl": "…"},
  "faq": [{"q": {"en": "…", "sl": "…"}, "a": {"en": "…", "sl": "…"}}],
  "sources": [{"id": "KD1", "quote": "verbatim sentence"}],
  "related": ["xylitol", "coffee"],
  "updated": "2026-10-31",
  "verification": {
    "en": {"status": "verified", "verified_by": "claude-verifier", "verified_hash": "…"},
    "sl": {"status": "draft", "verified_by": null, "verified_hash": null}
  }
}
```

| Field | Rule |
|---|---|
| `slug` | English, lower-case, `-`; equals the file name; **never renamed** |
| `species` | `dog` · `cat` (folder name) |
| `question` | per locale, the page title as a natural question (grammar per language — never built from `name`) |
| `url_slug` | per locale, ASCII, lower-case, `-` (website URL) |
| `level` | `toxic` (vet now) · `dangerous` · `not_recommended` · `small_amounts` (safe as an occasional treat) |
| `answer` | first sentence answers the question; ≤ 50 words |
| `signs` | list, or `null` for `small_amounts` |
| `serving` | `null` or text map: how to give it safely (e.g. no seeds, plain, cooked) — no grams, no doses |
| `child` | ≤ 25 words, for the child app and the "For kids" box |
| `faq` | 0–4 items, each answer backed by a quote |
| `sources` | ≥ 1, ids from the species' sources file; at least one tier A or B |
| `related` | slugs of the same species |

No other keys. Every text map has the same locales.

### Content hash (`verified_hash`, per locale)

SHA-256 hex of `JSON.stringify` of:

```
[slug, species, level, name[L], question[L], answer[L], why[L], signs?.[L] ?? null, what_to_do[L],
 serving?.[L] ?? null, child[L], faq.map(f => [f.q[L], f.a[L]]), sources]
```

`scripts/export-care-knowledge.mjs --hashes <file>` prints the hash of each locale. The verifier writes the
hash of the text **it checked**; any later edit makes the locale unpublished until it is verified again.
