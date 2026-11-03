# Runbook — daily vet directory translation + language homework (one language per day)

David 2026-11-03: the vet directory on the emergency page showed English texts (and research remarks) on the
Slovenian site. Decision: translate the visitor text of every vet entry into **all 15 site languages**, through a
schedule — **one language per day**, so David controls the credits — and each run also does **homework**: it reads
the whole site in that language and fixes anything still not translated. Queue: `docs/research/care/VET_L10N_QUEUE.md`.

## 0. Lock
```bash
gh api 'repos/DataVallis/pet-prep/pulls?state=open&per_page=100' --jq '.[].head.ref' | grep '^feat/vet-l10n-'
gh api 'repos/DataVallis/pet-prep-website/pulls?state=open&per_page=100' --jq '.[].head.ref' | grep '^feat/vet-l10n-'
```
Prints anything → stop.

## 1. Pick
First `[ ]` line of the queue (`cleanup`, then one language code). Branch `feat/vet-l10n-<code>` in both repos.
All lines `[x]` → **top-up run**: the language whose line is oldest; translate entries added since (no
`duty_i18n.<code>`) and redo the homework.

## 2. Cleanup (line `cleanup`, and on every run for entries that have no `duty_i18n` yet)
One agent reads every `duty` in `docs/research/care/vets/*.json` and makes it **visitor text only**
(`VET_DIRECTORY_RUNBOOK.md` "Public vs internal text"): research remarks (pages, registers, "listed",
"could not be opened", conflicting numbers) move to `note`; the rest — hours, emergency arrangements, surcharges,
"call ahead" — stays in `duty`, rewritten in plain English if it was in another language. Never change a fact,
time, phone number or price; never add one. A separate agent compares before/after for 10 % of entries.

## 3. Translate (agent A) and review (agent B, native, never the translator)
For every entry with a `duty` and no `duty_i18n.<code>`: write `duty_i18n.<code>` (for `en` the English `duty`
itself is the text — skip). Rules from `LANGUAGE_RUNBOOK.md` §2 (meaning, not words; core glossary; the address
form of the language's queue line). Phone numbers, times, prices, names and URLs exactly as in `duty`; local time
and number format (e.g. sl `8.00–13.00` or `8–13`, de `8–13 Uhr`); keep it as short as the English. Split the work
by country across parallel sub-agents. B reads every translated string, rewrites anything a native would not
write, and checks numbers/times against `duty`.

## 4. Homework (agent B, same language)
Read the whole site in that language and fix what is still English or reads as a translation:
`pet-prep-website` — `src/content/<code>.ts`, every `Record<Locale, …>` copy (grep for strings identical to `en`),
register labels `src/content/registry/<code>.ts` + `locales/<code>.json`, care copy and emergency texts, and the
built pages (`npm run build`, then grep `/<code>/` HTML for long English sentences). Care food texts that are
missing in this language are **not** translated here (that is the language run's top-up); list them in the summary.
Product names (PetPrep, Curio, #walkyourpet, "12 Weeks" if no local title) and `/investors` (English only by
decision) stay as they are.

## 5. Ship
1. pet-prep: `node scripts/export-care-knowledge.mjs` + `node --test scripts/tests/export-care-knowledge.test.mjs`,
   tick the queue line (`[x] <code> — <n> entries translated, <n> homework fixes, PRs #…, <date>`), PR
   `feat(i18n): vet directory in <language> [vet-l10n]`, `CI OK`, merge.
2. Website (homework fixes only; the care export reaches the site through the scheduled "Sync care knowledge"
   workflow): tsc + lint + build, PR `fix(i18n): <language> homework [vet-l10n]`, wait for all checks, merge.
Never force-push, merge red CI, touch production data, `.env`, CLAUDE.md, the app or the shared docs
(DECISIONS, HANDOFF, ROADMAP, FEATURES).

## 6. Summary (Slovenian, one short message)
Language, entries translated, cleanup moves (duty → note), homework fixes (3 examples "prej → potem"), food texts
still missing in this language, PRs + merge SHAs, next line.
