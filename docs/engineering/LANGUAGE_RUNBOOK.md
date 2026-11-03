# Runbook — hourly language run (L2 + L3)

David 2026-10-31: 13 new languages (DE, FR, ES, IT, PT, NL, PL, CS, HR, SV, DA, FI, NB) for petprep.si and the care
knowledge base, **grammatically perfect** — **one language per hourly run**, each checked by separate reviewers.
Plan: `docs/product/CARE_KNOWLEDGE_SPEC.md` §12. Queue: `docs/research/care/LANGUAGE_QUEUE.md`.

The website is language-generic since L1 (pet-prep-website PR "feat(i18n): language-generic site"): adding a
language = add it to `locales` + `localeMeta` in `src/i18n/config.ts`, then `npx tsc --noEmit` lists **every**
text that still needs a translation. A language goes live only when tsc, lint and build are green.

## 0. Lock

```bash
gh api 'repos/DataVallis/pet-prep/pulls?state=open&per_page=100' --jq '.[].head.ref' | grep '^feat/L-'
gh api 'repos/DataVallis/pet-prep-website/pulls?state=open&per_page=100' --jq '.[].head.ref' | grep '^feat/L-'
```
Prints anything → another language run is open: **stop, do nothing**.

## 1. Pick

First `[ ]` line of `LANGUAGE_QUEUE.md` (e.g. `de German`). Branch `feat/L-<code>` in both repos.
When every line is `[x]`, the run is a **top-up run**: find texts that exist in EN but not in a live language
(new care foods from the nightly food run, new breeds from the breed run, new website copy) and translate +
review those the same way (one language per run, round-robin from the oldest top-up).
A `[ ] review <code>` line (section "Review" of the queue) is a **review run** — see §3R; it comes before top-ups.

## 2. Translate (agent A — translator)

Native-level, natural, never word-for-word; the brand voice of `brand/README.md` (calm, warm, precise, playful
only in the child texts); formal or informal address as is usual for consumer family apps in that language — decide once per
language, write it in the queue line and keep it everywhere. Keep product names (PetPrep, Curio, #walkyourpet) and URLs; units and number/date formats of the language.

**Meaning, not words (David 2026-11-02: "ne prevajaš gluposti, ampak slovnično smiselnost").** Translate what a
sentence *means* for a parent or child, then write it the way a native copywriter in that country would — change
word order, split or merge sentences, swap the image for a local idiom. Each string must pass the test "would a
native parent write or say exactly this?"; a correct-but-foreign sentence fails. Core glossary — the same everywhere:

| EN | Meaning | sl | hr |
|---|---|---|---|
| pet / a pet (to want, get, be ready for) | a companion animal at home, not "an animal" | hišni ljubljenček (*žival* only for species/biology) | kućni ljubimac / ljubimac (*životinja* only for species/biology) |
| pet care | | skrb za hišnega ljubljenčka / za živali | briga za ljubimca / za životinje |
| colouring page | | pobarvanka | bojanka |

Add a line for every new language to this table (the queue line's glossary points here).

Website repo (`DataVallis/pet-prep-website`, branch `feat/L-<code>`):
1. `src/i18n/config.ts`: add the code to `locales`, `localeMeta` (htmlLang, og, label in its own language, short,
   intl, country = home country ISO-2 for the vet directory), and every slug map (`pageSlugs`, `compareSlug`, …):
   ASCII slugs in the language, never reused across pages.
2. `src/content/<code>.ts` from `en.ts` (every page, FAQ, legal pages **marked** with the note "Translation —
   the English version is binding" — David confirmed 2026-10-31), add it to `src/content/index.ts`.
   Also add `src/content/investors/<code>.ts` (the /investors page, type `InvestorsCopy`, from `en.ts`; register it in `investors/index.ts`) — the page is localized in every language, noindex (David 2026-11-03).
3. `src/content/registry/<code>.ts` (register words) and `src/content/registry/locales/<code>.json`
   (species names + slugs, every breed name + slug, synonyms, coat colour labels, suitability labels) — breed names
   as the kennel clubs of that country write them (FCI / national club), sourced where unusual.
4. `src/content/care/copy.ts` (+ `speciesWords`), `src/content/care/emergency.ts` (steps, labels, slugs), any
   other `Record<Locale, …>` tsc reports.
5. `npx tsc --noEmit && npm run lint && npm run build` green; no English left on `/<code>/…` pages
   (`grep` the built HTML for a few long English sentences).

pet-prep repo (branch `feat/L-<code>`): every `docs/research/care/foods/*/*.json` gets `<code>` in every text
map (name, question, url_slug, answer, why, signs, what_to_do, serving, child, faq) translated **from EN and the
quotes**, never adding claims; verification `<code>: draft`.

## 3. Review (agents B and C — never the translator)

- **B — native grammar & style reviewer** for that language: reads every changed string (site, register, care
  copy, emergency, every food) and fixes grammar, spelling, punctuation, agreement, case, gender, idiom, typography
  (quotes, dashes, non-breaking spaces as the language uses them), consistent terminology (one word per concept,
  glossary at the top of the queue line notes and the core glossary in §2). **Rewrites every string that reads as
  a translation** (calques, English word order, a literal word where the language uses another, e.g. sl "žival"
  for "pet") — fixing grammar alone is not enough. Lists every fix.
- **C — meaning & safety reviewer**: compares the translation with EN (and for foods with the quotes): no meaning
  drift, no stronger claims, no doses, warnings intact, child texts kind. For each food locale it passes it sets
  `verification[<code>] = verified` with the hash from `node scripts/export-care-knowledge.mjs --hashes <file>`
  after B's and its own last edit.
- If B or C finds more than 5 % of strings needing a meaning fix, A re-translates those parts and B + C run again
  (max. 2 rounds), else the line is marked `[!]`.

## 3R. Review run — re-read a live language (David 2026-11-02: "vse je treba pregledat")

For a `[ ] review <code>` line. Branch `feat/L-<code>-review` in both repos (the §0 lock still applies). No
translator: two fresh agents who did not translate the language.
1. **B (native copywriter + grammar)** reads **every** string of that locale in context, page by page: website
   `src/content/<code>.ts`, every `Record<Locale, …>` entry (kids, colouring, care copy, emergency, register words,
   `registry/locales/<code>.json` labels), then every food text in `docs/research/care/foods/*/*.json`. Applies §2
   "Meaning, not words" and the core glossary: rewrites every sentence a native parent would not write, fixes
   grammar, consistency and typography. Does not change facts, numbers, slugs or URLs (a wrong slug → note only).
2. **C (meaning & safety)** checks every changed string against EN (foods also against the quotes) and re-sets
   `verification[<code>] = verified` with the new hash for every food it passes.
3. Ship as §4 with PR titles `fix(i18n): <language> review [L-review]`; queue line →
   `[x] review <code> — <n> strings rewritten (B) / <n> meaning fixes (C), PRs #…, <date>` + any new glossary terms
   on the language's main queue line. The summary lists 3–5 typical before → after examples.

## 4. Ship

1. pet-prep: `node scripts/export-care-knowledge.mjs` + `node --test scripts/tests/export-care-knowledge.test.mjs`,
   tick the queue line, PR `feat(i18n): <language> care knowledge [L3]`, `CI OK`, merge.
2. Website: copy `docs/research/care-knowledge.json` → `src/content/care/care-knowledge.json`, tsc + lint + build,
   PR `feat(i18n): <language> [L2]`, wait for all checks, merge (the merge deploys petprep.si; IndexNow runs).
3. Queue line → `[x] <code> <language> — address: <Sie/du/…>, PRs #…, #…, <date>`.

Only these files change; never the shared docs (DECISIONS, HANDOFF, ROADMAP, FEATURES). Never force-push, merge
red CI, touch production data, `.env`, CLAUDE.md or the app (`mobile/`, app languages are L4, separate).

## 5. Summary (Slovenian, one short message)

Language, address form, strings translated, B fixes / C fixes, foods verified, PR numbers + merge SHAs, live URL
(`https://petprep.si/<code>`), next language.
