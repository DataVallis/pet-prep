# Runbook — add one breed, unattended (M5-R10)

Written 2026-10-10 from the French Bulldog run (M5-R10-03: pet-prep PR #130 + portrait PR #131, website PR #13).
Golden Retriever (PR #122) and Labrador (PR #121) are the older templates; the French Bulldog commits
(`git log --oneline --grep "French Bulldog"`) are the most complete example of every touch point.

## 0. Scheduled-run prompt (paste as the task's prompt)

```
PetPrep unattended breed run. Repos: DataVallis/pet-prep (and DataVallis/pet-prep-website).
1. Read CLAUDE.md, backend/CLAUDE.md, mobile/CLAUDE.md, HANDOFF.md §4, then
   docs/engineering/ADD_BREED_RUNBOOK.md and follow it exactly.
2. Lock: if any OPEN pet-prep PR has a head branch starting with "feat/M5-R10-", stop and do nothing.
3. Take the FIRST row with status "todo" in docs/research/BREED_QUEUE.md (one breed per run).
4. Research, build, test, review, PR, merge on green CI, deploy, portrait, website — per the runbook.
5. Apply only the runbook's standing decision rules; never ask questions. On a stop condition mark the
   queue row (blocked / needs-david) with the reason and stop.
6. Never force-push, never merge red CI, never touch production data, app.json identity or CLAUDE.md.
Report: breed, PR numbers + merge SHAs, deploy result, test counts, review findings, portrait verdict,
website URLs, time per phase.
```

## 1. Guard rails

| Rule | Detail |
|---|---|
| Lock | `gh api 'repos/DataVallis/pet-prep/pulls?state=open&per_page=100' --jq '.[].head.ref' \| grep '^feat/M5-R10-'` → any output = **exit without doing anything**. Every branch of a run starts with `feat/M5-R10-NN-<slug>` (also the portrait branch), so the lock holds for the whole run. |
| One breed per run | The first `todo` row of `docs/research/BREED_QUEUE.md`. Set it to `in-progress` in the first commit of the run. |
| Stop: research | A **required field** (§2.2: adult weight, lifespan, adult exercise) has no A/B source → do not build; set the row to `blocked` with the reason (which field, which sources were tried); commit only `BREED_QUEUE.md` + the research you have (`docs(research): … [M5-R10]`) in a docs PR, merge on green CI, stop. |
| Stop: code | CI fails twice for code reasons (not infra), or the review (§6) finds a blocker you cannot fix → leave the PR **open**, set the row to `needs-david` with the reason (in the PR branch), push, stop. |
| Infra failure | Docker Hub 429, runner loss, network: wait 5 min, `gh api -X POST repos/DataVallis/pet-prep/actions/runs/<run_id>/rerun-failed-jobs` **once**. Still failing → `needs-david`. |
| Never | force-push; merge without `CI OK` = success on the head SHA; edit `mobile/app.json` name / version / bundle ids; read or print `.env`; delete production data; add a "hypoallergenic" tag; show health percentages / odds ratios in the app or the register; use flat-faced / welfare-concern breeds in marketing. |
| Language | Code, comments, commits, this runbook → English. PRODUCT_SPEC, PARENTS, FEATURES, DECISIONS, BUILD_LOG, ROADMAP → Slovenian (formal *vi* to parents). |

## 2. Research (dogs: `docs/research/dog-data/`)

### 2.1 Format
- `data.json`: add `"<breed_key>": { "_note", "identity", "size_class", "height", "adult_weight", "growth", "lifespan", "exercise", "behaviour", "health", "trainability", "appearance", "suitability" }` after the last breed, and `proposed_game_parameters.<breed_key>` with the game values (§3). Copy the French Bulldog structure.
- Value object: `{value, unit, source_id, quote, confidence, notes}` (+ `derived_from`, `alternatives`, `decision`). `source_id` null ⇒ notes start with `UNSOURCED —` and it is never shown as fact.
- Quotes: only passages the fetch tool returned verbatim; paraphrase goes in `notes` marked "(paraphrase)".
- `sources.md`: next free ids (`S95`, …), one table row each — `| S95 | A | Publisher | Title | URL | Accessed YYYY-MM-DD. What it gave. |` (6 columns; the export parser checks it); update the access line at the top; add misses to "Looked for but not found".
- **Tiers:** **A** breed standard (FCI, RKC standard, AKC standard) / peer-reviewed paper / vet association (BVA, AAHA); **B** kennel-club breed page (RKC Breeds A–Z), vet charity (PDSA, Woodgreen, Dogs Trust, Blue Cross, Guide Dogs), university / VetCompass news; **C** secondary (Wikipedia, press) — only next to A/B. Coren's rank may come from Wikipedia (S35) when Coren's own top/bottom-10 article (S34) does not list the breed.

### 2.2 Required fields (missing any of the first three → `blocked`)
| Field | Path | Preferred sources |
|---|---|---|
| **Adult weight** | `adult_weight.<fci\|akc\|…>` `{male:[a,b], female:[c,d]}` | FCI / AKC / RKC standard; PDSA / Woodgreen as extra |
| **Lifespan** | `lifespan.median_uk` (+ `senior_from` with `months`) | McMillan 2024 (S54 / Dogs Trust S15) → VetCompass paper → RKC "Lifespan" lower bound |
| **Adult exercise** | `exercise.adult` (`"> 120"`, `"≤ 60"`, range text) | RKC breed page "Exercise:" → PDSA → Woodgreen / Dogs Trust |
| Identity | `identity` value `"FCI No. N, Group G Section S (…), <Country>"` | FCI nomenclature page; the country must be in `FCI_ORIGINS` of the export (add it + EN/SL words on the website) |
| Size class | `size_class` | RKC "Size:" / PDSA |
| Height | `height.<…>` `{male:[a,b], female:[c,d]}` | FCI / RKC / AKC |
| Growth end | `growth.adult_weight_reached` `"A–B months"` | PetMD size class (S10) |
| Coren | `trainability.coren_rank`, `coren_tier` | S34 / S35 |
| Appearance | `appearance.general, colours, colour_weights (UNSOURCED), coat, ears, eyes, tail` (+ `nose` when the face matters) | standards |
| Suitability | `suitability.rkc_*` (size of home / garden, grooming, coat length, sheds, lifespan, town or country), `pdsa_*`, `woodgreen_*` with quotes | RKC page, PDSA, Woodgreen |
| Behaviour | `behaviour.temperament, family, time_alone, other_pets, chewing, food_motivation` (null + note when nothing found) | standard, PDSA |
| Health | `health._note` + one entry per sourced condition; percentages / odds ratios stay here only | VetCompass papers, PDSA, RKC |

## 3. Standing decision rules (no questions to David)

Record each as `"decision": "potrdil David 2026-10-10 (pravilo runbooka): …"` in `proposed_game_parameters.<breed_key>.*` (the rules were confirmed on 2026-10-10; the date stays), and as one DECISIONS row "Claude (izvedba po pravilih runbooka)".

| Value | Rule | Example |
|---|---|---|
| Adult exercise minutes | The strongest A/B source's daily minutes, **RKC breed page preferred**: one number N ("up to N", "more than N", "at least N", "minimum N") → N; a range "A–B" → A (lower bound). If sources conflict, take the RKC value (else PDSA) and record the others in `alternatives`. | RKC "More than 2 hours" → 120; RKC "Up to 1 hour" → 60; Woodgreen only "60–90 mins" → 60 |
| Adult step goal | minutes × 100 (`general_by_size.exercise.steps_conversion`, S45) | 60 → 6,000 |
| Puppy / young | 10 min × age in months, capped at the adult minutes (general rule; nothing to decide) | French Bulldog reaches 60 at 6 months |
| Senior exercise | `round(0.75 × adult)` whole minutes, half up | 90 → 67.5 → 68; 60 → 45 |
| Stages | young **9**, adult **36**, senior **round(0.75 × median lifespan × 12)** (half up). Lifespan: McMillan 2024 (via S54 / Dogs Trust S15), else a VetCompass median, else the RKC lower bound | 9.8 y → 88.2 → 88 |
| Arrival ages | first month of each stage: puppy 2, young 9, adult 36, senior = senior boundary | 2 / 9 / 36 / 88 |
| Learning multiplier | By Coren tier (S35): **Brightest** (ranks 1–10) `max(1.8, round(2.0 − (rank − 1) / 30, 1))` → r1–2 2.0, r3–5 1.9, r6–10 1.8 (Border Collie r1 2.0, Golden r4 1.9, Labrador r7 1.8); **Excellent** (11–26) 1.5; **Above average** (27–39) 1.2; **Average** (40–54) 1.0; **Fair** (55–69) 0.7; **Lowest** (70–79) 0.5; **unranked** 1.0. No individual variation row for pedigree breeds. | rank 58 → 0.7 |
| Care rates | Border Collie (hunger −12 %/h, thirst −15 %/h, 2 poops, water 3× ≥ 180 min) unless a source gives a breed-specific number (none so far) | — |
| Paid / order | paid (`premium_unlock` true); `sort_order` = previous + 10 | French Bulldog 40 |
| Suitability tags | Only tags whose `refs` point at this breed's data.json entries **with a quote** citing every listed source id (BreedSuitabilityTest). `children` only with an explicit statement (supervision caveat kept in notes); `small_children` only with an explicit rating and no supervision caveat; `apartment` only from RKC "Flat/ Apartment"; `large_home` from RKC "Large house"; never "hypoallergenic". Conflicting sources (e.g. sheds yes vs minimal) → neither tag. | French Bulldog: apartment, family_pet, children / brachycephalic_breathing |
| New tag | Only if no vocabulary key fits a sourced, important trait. Key snake_case, kind `consider` or `suits`; labels in the existing style — lower case, short, no numbers, formal and calm (SL e.g. "kratek gobček — težave z dihanjem in vročino"; EN "flat face — breathing and heat problems"). | — |
| Welfare-concern breed | A breed that BVA, the UK Brachycephalic Working Group, UFAW, RVC/VetCompass or the RKC name for conformation-related welfare problems (e.g. flat-faced breeds) gets the matching sourced consider tag (`brachycephalic_breathing` for flat faces, or a new one per the row above) and is **excluded from marketing**: DECISIONS row, `proposed_game_parameters.<key>.marketing` (`value`, `source_id`, `quote`, `decision`), a line under "Smernice za marketing" in `docs/audiences/README.md`, the FEATURES row says "v aplikaciji da, v marketingu ne". | French Bulldog |
| Health statistics | Never in the app, the register export or audience docs (no %, no odds ratios, no "x times"). Register health = key + source only, with an EN + SL sentence on the website. | — |
| AI appearance | Colours listed by the breed standard only — never a non-standard or disqualifying colour (merle only where the standard lists it, e.g. Australian Shepherd; never for the French Bulldog), weights unsourced → `verified: false`; the portrait default = the highest-weighted colour (put the most typical standard colour first with the highest weight; `media.breed_portraits.traits.<key>` only if a lower-weight option must be the portrait). Welfare breeds: moderate, non-exaggerated features — dog breeds have no `features` key, so add a dog trait (e.g. `muzzle`) to `traits` + `prompt_order` + `sources`. | French Bulldog fawn, `muzzle` |
| Names | EN = RKC / AKC name; SL = the Slovenian common name used by KZS / Slovenian breeders (lower case inside a sentence; slug from `slugify`, e.g. `francoski-buldog`). Search keywords: EN + SL names, ASCII variant of SL, common nickname. | — |
| Cats | Follow `docs/product/CAT_SPEC.md` and `docs/research/cat-data/` (C-ids); stages / meals / play / litter are the general cat rules; breed-specific: arrival (kitten), grooming sessions per week if sourced, lifespan, weight, appearance. Cat breeds are built behind the same hidden switch as the Maine Coon (`PETPREP_CATS_ENABLED=false`, availability `coming_soon` in the export). | Maine Coon |

## 4. Touch points (dog; French Bulldog = example)

**Branch:** `git checkout -b feat/M5-R10-NN-<slug> origin/main` (NN = the `#` column of `docs/research/BREED_QUEUE.md`, slug = EN slug).

### Research commit — `docs(research): <Breed> sourced data + game decisions [M5-R10]`
`docs/research/dog-data/data.json`, `docs/research/dog-data/sources.md`, `docs/research/BREED_QUEUE.md` (row → `in-progress`).

### Backend commit — `feat(backend): add <Breed> breed [M5-R10]`
| File | Change |
|---|---|
| `backend/app/Enums/BreedType.php` | case, `slug()`, `species()`, `defaultPremium()` |
| `backend/database/migrations/<next date>_120000_add_<key>_breed.php` | next free date (`ls backend/database/migrations \| tail -1` + 1 day), widen `pets_breed_type_check` OLD → NEW, `down()` restores OLD (fails while such pets exist). **Cats:** also `pets_species_breed_check` (cat list). |
| `backend/database/seeders/BreedConfigsSeeder.php` | row: slug, `daily_steps_required` = adult steps, Border Collie care rates, `premium_unlock` true, species, `sort_order`, `label_key` `breeds.<key>`, `search_keywords` |
| `backend/database/seeders/BreedStageParamsSeeder.php` | `CONFIRMED_*` constant (decision text), `DOG_BREEDS`, `dogProfile()` arm, `<key>Profile()` (starts_at, arrival, arrival_meta, young_per_age_notes, adult_minutes, senior_minutes, adult_weight, growth_end, coren_rank, learning_multiplier, individual_variation null, lifespan). A row with `derived_from` in data.json must have `source_id` = exactly those ids (LifeStageDataTest). |
| `backend/config/breed_appearance.php` | header note + breed block (`display_name`, `verified` false, `source`, `sources`, `traits`, `prompt_order`) |
| `backend/app/Services/FalAiService.php` | DNA v1 `buildPromptAnchor` + `buildVisualTraits` arms |
| `backend/config/breed_suitability.php` | breed entry; new vocabulary key if any |
| `backend/app/Http/Resources/BreedCatalogResource.php` | `@var` breed union; suits / consider union for a new tag |
| `backend/app/Http/Controllers/PairingController.php` | `@var` breed union |
| `backend/app/Filament/Resources/BreedConfigResource.php` | slug examples in two helper texts |
| `backend/database/factories/PetFactory.php` | `<camelKey>()` state |
| Tests | new `tests/Feature/<Breed>BreedTest.php` (copy `FrenchBulldogBreedTest.php`: config, migration reversibility, insert-only seed, rows vs data.json, stage rules dataset, senior month, learning, generate-pin paid rule, `/api/breeds` entry, appearance / portrait traits, no statistics); update counts / lists in `BreedConfigTest`, `SpeciesFoundationTest`, `LabradorBreedTest`, `GoldenRetrieverBreedTest`, `FrenchBulldogBreedTest` (breed lists), `BreedSuitabilityTest` (per-breed tags + a test for a new tag), `BreedPortraitTest` (breed lists, counts "Generated N", "$X", `Stopped: N more`, ledger count / sum, manifest indexes) |

### Mobile commit — `feat(mobile): <Breed> in the picker [M5-R10]`
| File | Change |
|---|---|
| `mobile/src/api/schema.ts` | regenerate (§5) — must be additive only |
| `mobile/src/modules/species/species.ts` | `BREED_SPECIES` |
| `mobile/src/i18n/locales/{en,sl}/family.json` | `breeds.<key>` |
| `mobile/src/i18n/locales/{en,sl}/pet.json` | `picker.breedHints.<key>` ("Part of the 12-week challenge." / "Del 12-tedenskega izziva."), `picker.ageHints.<key>` (puppy / young / adult / senior with the real numbers, same sentence shapes as the other breeds), `breedSuitability.*` label for a new tag |
| `mobile/src/modules/petProfile/picker.ts` | `CONSIDER_TAGS` / `SUITS_TAGS` for a new tag, `FALLBACK_CATALOGUE` entry (= seeder + suitability), doc comment numbers |
| `mobile/src/i18n/__tests__/speciesCoverage.test.ts` | `DOG_ONLY` reason if a hint names a dog ("kot odrasel pes") |
| Tests | `petProfile/__tests__/catalogue.test.ts`, `petProfile.test.ts`, `picker.test.ts` (`DogBreed`, `DOG_BREEDS`, `ADULT_MINUTES`, exact hints EN + SL), `species/__tests__/species.test.ts`, `components/parent/__tests__/PetPickerStep.test.tsx` (order, chips SL + EN, search, age hints, confirm) |

### Register export commit — `feat(scripts): <Breed> in the breed registry export [M5-R10]`
`scripts/export-breed-registry.mjs`: `SPECIES[].breeds` entry `{ id, availability: 'coming_soon', synonyms: { en: [...], sl: [...] } }`, `DOG_FACTS.<key>` (only sourced refs; never a number that misleads — e.g. a median age at death of a young population), `DOG_GAME.<key>`, `FCI_ORIGINS` for a new country, a new qualifier / parser only if a value needs it. Then `node scripts/export-breed-registry.mjs` (writes `docs/research/breed-registry.json`) and extend `scripts/tests/export-breed-registry.test.mjs` (slug, `expected` game numbers, a breed-specific test for anything new).

### Docs commit — `docs: <Breed> across spec, audiences, features and handoff [M5-R10]`
`docs/product/PRODUCT_SPEC.md` (paid breeds lists §2 / §3, suitability §3, ages / stages §4, metrics table + steps + learning + hygiene §5), `docs/audiences/PARENTS.md` (plan / breed / tags, age hints, arrival ages, sourced minutes, steps table), `docs/audiences/INVESTORS.md` (breeds today), `docs/audiences/README.md` (marketing rule for welfare breeds), `docs/product/FEATURES.md` ("Stanje na dan", 💶 legend, Pasma row, new breed row, tags row, Starost ob prihodu, Potrebe po starosti; later register + portrait rows), `docs/engineering/ROADMAP.md` (`[x] M5-R10-NN`), `docs/product/DECISIONS.md` (rows at the top), `docs/engineering/ARCHITECTURE.md` (breed_type CHECK + migration, DOG_BREEDS, appearance, generate-pin union + senior arrival, `/api/breeds` sort orders + tag vocabulary + per-breed tags, life-stage senior months + minutes), `docs/engineering/DIAGRAMS.md` (`breed_type` list), `docs/journey/BUILD_LOG.md` (entry on top with real numbers), `HANDOFF.md` (§1 last updated, §5 update, §6 session log).

## 5. Build and test (cloud session, no Docker)

```bash
service postgresql start; su postgres -c "psql -c \"ALTER USER postgres PASSWORD 'postgres'\""
su postgres -c "createdb testing_<slug>"
cd backend && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction && cd ..
cd mobile && yarn install --frozen-lockfile && cd ..
PG="DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_USERNAME=postgres DB_PASSWORD=postgres"
# focused first, then everything
(cd backend && env $PG DB_DATABASE=testing_<slug> php vendor/bin/pest tests/Feature/<Breed>BreedTest.php tests/Feature/BreedSuitabilityTest.php tests/Feature/BreedPortraitTest.php)
(cd backend && env $PG DB_DATABASE=testing_<slug> php vendor/bin/pest --parallel --recreate-databases)
(cd backend && ./vendor/bin/pint --test <every changed PHP file>)   # ./vendor/bin/pint <file> to fix
# schema.ts from a temporary local server
su postgres -c "createdb schema_<slug>"
(cd backend && env $PG DB_DATABASE=schema_<slug> php artisan migrate --force -q && env $PG DB_DATABASE=schema_<slug> php artisan serve --port=8000 &) ; sleep 4
NO_PROXY=localhost,127.0.0.1 node scripts/generate-api-types.mjs && git diff mobile/src/api/schema.ts   # additive only
pkill -f "artisan serve"; su postgres -c "dropdb schema_<slug>"
(cd mobile && npx tsc --noEmit && yarn test --silent)
node scripts/export-breed-registry.mjs && node --test scripts/tests/export-breed-registry.test.mjs
bash scripts/tests/ci-plan.test.sh                     # always cheap; required when scripts/ or workflows change
shellcheck scripts/*.sh                                # only if a shell script changed
```
The export test also cross-checks every game number against `BreedStageParamsSeeder::allRows()` (needs `backend/vendor`). `BreedPortraitTest` compares the portrait breed list with the committed `breed-registry.json` — regenerate the export before the full Pest run.

## 6. Review (before the PR is merged)

Spawn the `qa-reviewer` agent when the session can; otherwise a written self-review in the PR body against this checklist — every item answered:
1. Migration expand-only, reversible, `down()` refuses while such pets exist; no data rewrite.
2. Other breeds byte-identical: their seeder rows, `breed_configs`, appearance prompts (`DogMediaPromptSnapshotTest` green, no snapshot re-record), app hints.
3. Every runtime number traces to `data.json` (`source_id` or `decision`); learning / stage rows' `source_id` = `derived_from`.
4. Tags only with quotes from this breed's entries; no hypoallergenic; welfare tag present when §3 says so.
5. API additive (`schema.ts` diff only adds union members).
6. EN / SL parity for every new string; Slovenian formal *vi*, lower-case chips, no numbers in chips.
7. No health statistic in app config, seeder rows, export or audience docs.
8. Docs: nothing unbuilt reads as available; "na telefonu še ni preverjeno"; marketing exclusion recorded for welfare breeds.
9. Queue row and ROADMAP updated.
Fix every finding in a `fix:` commit before merging.

## 7. PR, CI, merge, deploy (pet-prep)

```bash
git fetch origin && git merge --ff-only origin/main 2>/dev/null || git rebase origin/main   # branch up to date with main
git push -u origin feat/M5-R10-NN-<slug>
gh api repos/DataVallis/pet-prep/pulls -f title="feat: <Breed> breed [M5-R10-NN]" -f head=feat/M5-R10-NN-<slug> -f base=main -F body=@pr.md --jq '.number, .head.sha'
# poll until the "CI OK" check run on the HEAD SHA is completed (GraphQL is blocked — REST only)
gh api "repos/DataVallis/pet-prep/commits/<sha>/check-runs?per_page=100" --jq '.check_runs[] | select(.name=="CI OK") | "\(.status) \(.conclusion)"'
# only if conclusion == success:
gh api -X PUT repos/DataVallis/pet-prep/pulls/<n>/merge -f merge_method=merge --jq '.sha'
# the merge deploys automatically; wait for the main run of the merge SHA
gh api "repos/DataVallis/pet-prep/actions/runs?branch=main&head_sha=<merge_sha>" --jq '.workflow_runs[] | "\(.id) \(.name) \(.status) \(.conclusion)"'
gh api repos/DataVallis/pet-prep/actions/runs/<run_id>/jobs --jq '.jobs[] | "\(.name) \(.conclusion)"'   # "Deploy to Hetzner Production" success
```
PR body: summary, decisions applied (rule per value), touch points, test counts, review result, the footer lines from the session's attribution reminder. Commit trailers on every commit (Co-Authored-By + Claude-Session from the session's attribution reminder). A failing check: read annotations (`gh api repos/DataVallis/pet-prep/check-runs/<id>/annotations`), fix, push, wait again.

## 8. Portrait (after the deploy succeeded)

```bash
gh api -X POST repos/DataVallis/pet-prep/actions/workflows/breed-portraits.yml/dispatches -f ref=main -f 'inputs[breeds]=<key>'
gh api "repos/DataVallis/pet-prep/actions/workflows/breed-portraits.yml/runs?per_page=1" --jq '.workflow_runs[0].id'
# when completed: annotations name the review branch ("portraits branch: portraits/run-<id>") and the cost
gh api repos/DataVallis/pet-prep/check-runs/$(gh api repos/DataVallis/pet-prep/actions/runs/<run>/jobs --jq '.jobs[0].id')/annotations --jq '.[] | "\(.title): \(.message)"'
git fetch origin "refs/heads/portraits/run-<id>:refs/remotes/origin/portraits/run-<id>"
git show origin/portraits/run-<id>:docs/research/breed-portraits/dog/<slug>.webp > /tmp/p.webp
php -r '$i=imagecreatefromwebp($argv[1]); imagepng($i,$argv[2]);' /tmp/p.webp /tmp/p.png   # then Read /tmp/p.png and look at it
```
Verdict: one adult animal of the breed, standard colour = the portrait default, plain brand background, no text / people / props, natural proportions; welfare breeds: no extreme face (open nostrils, visible muzzle). Wrong → dispatch once more with `-F 'inputs[force]=true'`; still wrong → keep the better one, note it in the PR and the queue (`done`, "portrait needs David"). Then:

```bash
git checkout -b feat/M5-R10-NN-<slug>-portrait origin/main
git checkout origin/portraits/run-<id> -- docs/research/breed-portraits   # webp + manifest
git commit -m "chore(research): AI portrait of the <Breed> from run <id> [M5-R10]" (+ trailers)
node scripts/export-breed-registry.mjs && node --test scripts/tests/export-breed-registry.test.mjs
git commit -am "chore(research): register the <Breed> portrait in the breed registry export [M5-R10]" (+ trailers)
```
Same commit: FEATURES register + portrait rows, HANDOFF, queue row → `done` (with PR numbers). PR, `CI OK`, merge (docs-only merges do not deploy). Delete nothing on the review branch.

## 9. Website (DataVallis/pet-prep-website, from its `main`)

```bash
cd ../pet-prep-website && git checkout -b feat/register-<slug> origin/main
node ../pet-prep/scripts/export-breed-registry.mjs --out "$PWD/src/content/registry/registry.json" --copy-portraits public
```
- `src/content/registry/en.ts` + `sl.ts`: words the build asks for (new FCI group in `fciGroups`, country in `origins`, category values, qualifiers, health keys — one calm sentence each, no numbers), and `intros.<key>` = 2 short paragraphs EN + SL from sourced statements only, `sources` ⊂ the breed's `source_ids`, `aka` (other names).
- `npm run lint && npx tsc --noEmit && npm run build` — the build fails on missing words; check the generated page text (`.next/server/app/en/animals/<species>/<slug>.html`, `.next/server/app/sl/zivali/<psi|macke>/<sl-slug>.html`) for grammar (singular / plural, "slower" multipliers).
- Commit `feat(register): <Breed> page …` (+ trailers), push, PR via `gh api repos/DataVallis/pet-prep-website/pulls`, wait for check runs **"Lint, types, build"** and **"Docker image (build + smoke test, no push)"** = success on the head SHA, merge with `merge_method=merge`, then wait for the website `main` run(s) of the merge SHA to succeed.
- Live: `https://petprep.si/animals/dogs/<slug>` and `https://petprep.si/sl/zivali/psi/<sl-slug>`.

## 10. Queue and report

- `docs/research/BREED_QUEUE.md`: `done` + date + PR numbers; when the 20 are done, append the next breed by AKC 2024 popularity (dogs) or FIFe / GCCF registrations (cats) **with the source URL** in the row.
- Final report: breed, PRs + merge SHAs, deploy result, test counts (Pest, Jest, export), review findings + fixes, portrait verdict, website URLs, minutes per phase (research, build + tests, CI + merge + deploy, portrait, website).

### Timing of the French Bulldog run (2026-10-10, research already done before the run)
| Phase | Minutes |
|---|---|
| Orientation + decisions into data.json | ~10 |
| Backend + mobile + export + docs, local tests (two full Pest runs ≈ 5 min each) | ~25 |
| PR → `CI OK` → merge → deploy | ~10 |
| Portrait workflow + review | ~3 |
| Website words / intro / build + portrait PR + runbook | ~25 |
Research for a new breed (sources, quotes, data.json) took one separate session before this run; budget **60–90 min** for it. A full unattended run ≈ 2–2.5 h.
