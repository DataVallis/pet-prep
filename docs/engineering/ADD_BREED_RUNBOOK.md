# Runbook — add one breed, unattended (M5-R10)

Written 2026-10-10 from the French Bulldog run (M5-R10-03: pet-prep PR #130 + portrait PR #131, website PR #13).
Golden Retriever (PR #122) and Labrador (PR #121) are the older templates; the French Bulldog commits
(`git log --oneline --grep "French Bulldog"`) are the most complete example of every touch point.

**Own files only (David 2026-10-15).** Breed runs happen **at night only** (David sets the schedule himself) and
must not collide with other work. A breed run writes **only**: its code / data / test files (§4), its own
`docs/breeds/<en-slug>.md` (+ the generated index `docs/breeds/README.md`), its `docs/research/BREED_QUEUE.md`
row, the registry export and generated files (`mobile/src/api/schema.ts`, `docs/research/breed-registry.json`).
It **never edits** `docs/product/DECISIONS.md`, `docs/product/FEATURES.md`, `HANDOFF.md`, `docs/journey/BUILD_LOG.md`,
`docs/product/PRODUCT_SPEC.md`, `docs/audiences/*`, `docs/engineering/ROADMAP.md`, `docs/engineering/ARCHITECTURE.md`,
`docs/engineering/DIAGRAMS.md`, `README.md` — those point to `docs/breeds/` and never list breeds one by one.
Other breeds' test files are never edited either: shared tests read the breed list from code (§4 "Tests").

## 0. Scheduled-run prompt (paste as the task's prompt)

```
PetPrep unattended breed run. Repos: DataVallis/pet-prep (and DataVallis/pet-prep-website).
1. Read CLAUDE.md, backend/CLAUDE.md, mobile/CLAUDE.md, HANDOFF.md §4, then
   docs/engineering/ADD_BREED_RUNBOOK.md and follow it exactly.
2. Lock: if any OPEN pet-prep PR has a head branch starting with "feat/M5-R10-", stop and do nothing.
   Otherwise take the lock at once: branch + queue row in-progress + DRAFT PR before any research.
3. Take the FIRST row with status "todo" in docs/research/BREED_QUEUE.md (one breed per run).
4. Research, build, test, review, PR, merge on green CI (deploys nothing), portrait right after the merge
   (§8, no deploy needed), website only with the portrait — per the runbook. Never dispatch a production deploy.
   Write only the breed's own files (runbook header "Own files only"): document in docs/breeds/<en-slug>.md,
   never in DECISIONS / FEATURES / HANDOFF / BUILD_LOG / PRODUCT_SPEC / audiences / ROADMAP / ARCHITECTURE / DIAGRAMS.
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
| One breed per run | The first `todo` row of `docs/research/BREED_QUEUE.md`. **Take the lock immediately (before any research):** create branch `feat/M5-R10-NN-<slug>`, commit only the queue row → `in-progress`, push, and open the PR as a **draft** right away (`gh api repos/DataVallis/pet-prep/pulls -f draft=true …`). Runs start every hour but take ~2 h, so without this early draft PR a second run would pick the same or the next breed in parallel. Mark it ready (`POST /repos/{owner}/{repo}/pulls/{n}/ccr/ready_for_review`) once the build is complete. On a research stop, convert that same PR into the docs PR described below. |
| Stop: research | A **required field** (§2.2: adult weight, lifespan, adult exercise) has no A/B source → do not build; set the row to `blocked` with the reason (which field, which sources were tried); commit only `BREED_QUEUE.md` + the research you have (`docs(research): … [M5-R10]`) in a docs PR, merge on green CI, stop. |
| Stop: code | CI fails twice for code reasons (not infra), or the review (§6) finds a blocker you cannot fix → leave the PR **open**, set the row to `needs-david` with the reason (in the PR branch), push, stop. |
| Infra failure | Docker Hub 429, runner loss, network: wait 5 min, `gh api -X POST repos/DataVallis/pet-prep/actions/runs/<run_id>/rerun-failed-jobs` **once**. Still failing → `needs-david`. |
| Never | force-push; merge without `CI OK` = success on the head SHA; edit `mobile/app.json` name / version / bundle ids; read or print `.env`; delete production data; add a "hypoallergenic" tag; show health percentages / odds ratios in the app or the register; use a breed in marketing that a veterinary association explicitly advises against showing in advertising (today: flat-faced breeds, BVA S92). |
| Language | Code, comments, commits, this runbook → English. `docs/breeds/<en-slug>.md` and the `BREED_QUEUE.md` row → Slovenian (formal *vi* to parents). |
| Schedule | Night only (David sets the schedule; never change it). |
| Shared docs | Never edited by a breed run (header "Own files only") — not even to fix a breed count or a list. A shared-doc fact that a new breed makes wrong is an **open item for David** in the breed doc, not an edit. |

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

### 2.3 Breeder checklist (M5-R11-06, not a stop condition)
`docs/research/breeder-checklist/data.json` → `breeds.<breed_key>` — the parents' health tests a buyer should ask about. Copy an existing entry.
- **Dogs:** fetch the breed's RKC Breeds A–Z page (already in `dog-data/sources.md`) and ask for the health-testing section verbatim. Tests under "We strongly recommend that breeders, at a minimum, conduct these tests before breeding" → `level: "minimum"`; under "Best Practice" → `"best_practice"`. A well-known scheme the page does not list but an A/B source describes for the breed (e.g. BVA/KC CM/SM for the Cavalier) → `"other_scheme"` with its own K-source. Each test: `key` (reuse a key from the file; new key = `dna_<acronym>` or `<thing>_scheme`), `name` (page wording), `source_id`, `quote`.
- **Cats:** a federation breeding-register rule (GCCF via World Cat Congress, FIFe, a breed club) → `"minimum"`; nothing A/B → `status: "not_found"` with `notes` (commercial lab pages are tier C — never alone).
- Page says there are no recommended tests (Westie) → `status: "none_recommended"` + `basis.quote`. Fetch failed → `status: "not_found"` + what was tried. Never guess a test.
- `general.<species>` items are per species and may carry `country` (ISO alpha-2) for a national rule shown only on that country's language pages — never put a national rule in an item without `country`.
- New source → next free **K-id** in `docs/research/breeder-checklist/sources.md` (6-column row, as in dog sources). The general items (`general.dog` / `general.cat`) are per species — do not touch them in a breed run.
- No entry at all → the export shows the breed as `not_checked` (no failure), so a run that cannot do this step still finishes; note it in the queue row.
- Website: every new test key needs words in `src/content/registry/{en,sl}.ts` → `checklist.tests` (the build fails otherwise) — short, plain name of the test and the condition, no numbers or odds.

## 3. Standing decision rules (no questions to David)

**Every sourced need is built (David 2026-10-11).** If an A/B source says this breed needs something — even a small thing (brushing once a week, a secure fence, nail clipping) — the run builds it into the game in the same PR, as a routine / rule / tag with the source and a `decision` line. Never leave a sourced breed need as "proposal, not built": "otherwise we forget and the app won't have the feature". When the game has the mechanic already (grooming, exercise, tags), only the value is new — build it. When the mechanic does not exist yet and is too large for the run, still finish the breed (merge on green CI as usual), set the queue row to `done` with the note `needs-david: <mechanic> not built — <task ID>`, and list it under **Odprto za Davida** in `docs/breeds/<en-slug>.md` with the task ID (an existing M5-R14 / R15 / R19 / R20 … — never edit ROADMAP or FEATURES; for a mechanic without a task the breed doc says "needs a new ROADMAP task — David"), so it cannot be forgotten. (Status `needs-david` alone still means the code stop of §1: PR left open.)

**Breed care tasks (David 2026-10-24, M5-R14 / R15 / R18 / R19 / R20 — the mechanic exists now):** a source that states a **frequency** for tooth brushing, face / skin-fold cleaning, ear cleaning, nail trimming (child mini-games), a professional groomer visit or a bath (parent reminders, never scored) → add the breed to `BreedStageParamsSeeder::DOG_CARE_TASKS` **and** `DOG_CARE_TASKS` in `scripts/export-breed-registry.mjs` (same table; the export test compares both ways): `[times, period]` with `day`/`week` → 7, every other week → 14, month / 4–6 weeks → 28, 6–8 weeks → 42, 2–3 months / every couple of months → 56, 3 months → 84 (a range → its lower bound; a month = 4 program weeks), with the source id, the verbatim quote (quote or notes of the `ref` entry) and the data.json `ref`. A flat-faced breed whose source says not to exercise it in the heat → `DOG_NO_WALK_IN_HEAT` (both files). **No frequency in the source (or only conditional, e.g. "if allergic") → no task** — keep the fact in the breed text / register; it is not needs-david any more.

Record each as `"decision": "potrdil David 2026-10-10 (pravilo runbooka): …"` in `proposed_game_parameters.<breed_key>.*` (the rules were confirmed on 2026-10-10; the date stays), and as one row in the **Odločitve** table of `docs/breeds/<en-slug>.md` "Claude (izvedba po pravilih runbooka)" (not in DECISIONS.md).

| Value | Rule | Example |
|---|---|---|
| Adult exercise minutes | The strongest A/B source's daily minutes, **RKC breed page preferred**: one number N ("up to N", "more than N", "at least N", "minimum N") → N; a range "A–B" → A (lower bound). If sources conflict, take the RKC value (else PDSA) and record the others in `alternatives`. | RKC "More than 2 hours" → 120; RKC "Up to 1 hour" → 60; Woodgreen only "60–90 mins" → 60 |
| Adult step goal | minutes × 100 (`general_by_size.exercise.steps_conversion`, S45) | 60 → 6,000 |
| Puppy / young | 10 min × age in months, capped at the adult minutes (general rule; nothing to decide) | French Bulldog reaches 60 at 6 months |
| Senior exercise | `round(0.75 × adult)` whole minutes, half up | 90 → 67.5 → 68; 60 → 45 |
| Stages | young **9**, adult **36**, senior **round(0.75 × median lifespan × 12)** (half up). Lifespan: McMillan 2024 (via S54 / Dogs Trust S15), else a VetCompass median, else the RKC lower bound. If none exists (RKC "Under 10 years" has no lower bound), take the closest row — a peer-reviewed breed median — but seed the senior boundary and the senior arrival age with `verified => false` and a `predlog Claude … čaka Davida` decision (never "potrdil David"), and list it as open for David (Bernese Mountain Dog, M5-R10-12) | 9.8 y → 88.2 → 88 |
| Arrival ages | first month of each stage: puppy 2, young 9, adult 36, senior = senior boundary | 2 / 9 / 36 / 88 |
| Learning multiplier | By Coren tier (S35): **Brightest** (ranks 1–10) `max(1.8, round(2.0 − (rank − 1) / 30, 1))` → r1–2 2.0, r3–5 1.9, r6–10 1.8 (Border Collie r1 2.0, Golden r4 1.9, Labrador r7 1.8); **Excellent** (11–26) 1.5; **Above average** (27–39) 1.2; **Average** (40–54) 1.0; **Fair** (55–69) 0.7; **Lowest** (70–79) 0.5; **unranked** 1.0. No individual variation row for pedigree breeds. | rank 58 → 0.7 |
| Care rates | Border Collie (hunger −12 %/h, thirst −15 %/h, 2 poops, water 3× ≥ 180 min) unless a source gives a breed-specific number (none so far) | — |
| Paid / order | paid (`premium_unlock` true); `sort_order` = previous + 10 | French Bulldog 40 |
| Suitability tags | Only tags whose `refs` point at this breed's data.json entries **with a quote** citing every listed source id (BreedSuitabilityTest). `children` only with an explicit statement (supervision caveat kept in notes); `small_children` only with an explicit rating and no supervision caveat; `apartment` only from RKC "Flat/ Apartment"; `large_home` from RKC "Large house"; never "hypoallergenic"; `shorter_lifespan` (consider) when the RKC breed page says "Lifespan: Under 10 years" (or an A/B source gives an upper bound ≤ 10 years) — no number in the chip (Bernese Mountain Dog, M5-R10-12). `chases_small_pets` (consider, David 2026-10-12) when a quote in this breed's own entry names a **high prey drive** (e.g. PDSA "… have a high prey drive"; ref `<breed>.behaviour.other_pets`), appended at the end of the consider list — a supervision advice alone ("always supervise with smaller pets") is not enough (Beagle, Bernese, Rottweiler, Cavalier, German Shepherd have none). Conflicting sources (e.g. sheds yes vs minimal) → neither tag. | French Bulldog: apartment, family_pet, children / brachycephalic_breathing, chases_small_pets |
| New tag | Only if no vocabulary key fits a sourced, important trait. Key snake_case, kind `consider` or `suits`; labels in the existing style — lower case, short, no numbers, formal and calm (SL e.g. "kratek gobček — težave z dihanjem in vročino"; EN "flat face — breathing and heat problems"). | — |
| Welfare-concern breed | A breed that BVA, the UK Brachycephalic Working Group, UFAW, RVC/VetCompass or the RKC name for conformation-related welfare problems (e.g. flat-faced breeds) gets the matching sourced consider tag (`brachycephalic_breathing` for flat faces, or a new one per the row above). **Marketing (David 2026-10-10):** the breed stays **in marketing** with that chip in the app — marketing never promises the breed's health. It is **excluded from marketing only** when a veterinary association explicitly advises against showing that type of dog in advertising (today: flat-faced / brachycephalic breeds — BVA S92); then: a row in the breed doc's **Odločitve**, `proposed_game_parameters.<key>.marketing` (`value`, `source_id`, `quote`, `decision`), the marketing row of the breed doc says "v aplikaciji da, v marketingu ne" (`docs/audiences/README.md` is not edited — its "Smernice za marketing" point to `docs/breeds/`). Record the marketing value in `proposed_game_parameters.<key>.marketing` either way. | French Bulldog (excluded, BVA); German Shepherd, Cavalier (in marketing, David 2026-10-10) |
| Health statistics | Never in the app, the register export or audience docs (no %, no odds ratios, no "x times"). Register health = key + source only, with an EN + SL sentence on the website. | — |
| AI appearance | Colours listed by the breed standard only — never a non-standard or disqualifying colour (merle only where the standard lists it, e.g. Australian Shepherd; never for the French Bulldog), weights unsourced → `verified: false`; the portrait default = the highest-weighted colour (put the most typical standard colour first with the highest weight; `media.breed_portraits.traits.<key>` only if a lower-weight option must be the portrait). Welfare breeds: moderate, non-exaggerated features — dog breeds have no `features` key, so add a dog trait (e.g. `muzzle`) to `traits` + `prompt_order` + `sources`. | French Bulldog fawn, `muzzle` |
| Names | EN = RKC / AKC name; SL = the Slovenian common name used by KZS / Slovenian breeders (lower case inside a sentence; slug from `slugify`, e.g. `francoski-buldog`). Search keywords: EN + SL names, ASCII variant of SL, common nickname. | — |
| Cats | Follow `docs/product/CAT_SPEC.md` and `docs/research/cat-data/` (C-ids); stages / meals / play / litter are the general cat rules; breed-specific: arrival (kitten), lifespan, weight, appearance and **grooming whenever a source gives a frequency** (David 2026-10-11): `<key>.grooming.game_sessions_per_week` = the source's number; a range "A–B per week" → **B** (the upper bound — Claude's reading of David's Ragdoll decision 2026-10-11 "as realistic as possible"; David may change it); "at least weekly" → 1; **no number** ("regularly", "often") → 1× per week seeded with `verified => false` and decision `predlog Claude 2026-…: … čaka Davida`, listed under **Odprto za Davida** in `docs/breeds/<en-slug>.md` (built, not forgotten); a seasonal number ("daily while moulting") → the out-of-season number, the season in `notes` (moult is not modelled); a seeder row `grooming_sessions_per_week` (decision `potrdil David 2026-10-11 (pravilo runbooka, M5-R10-NN)`), `game_matted_after_missed` = ceil(goal / 2) (`CatChoreService::mattedAfterMissed`), the breed in `GROOMING_CATS` (export) and `GROOMING_CAT_BREEDS` (mobile `species.ts`), the picker hint "… · brushing N× a week" / "… · česanje N× na teden". Cat breeds are built behind the same hidden switch as the Maine Coon (`PETPREP_CATS_ENABLED=false`); the export derives availability from `docs/research/app-release.json` (§9b). | Maine Coon |
| Dog grooming (M5-R13, David 2026-10-12 "kot pri mački") | Every dog breed whose A/B sources give a brushing / grooming frequency gets the weekly brushing routine — the cat's mechanic (same session, ledger slots, matted coat at ceil(N / 2) missed). N = `proposed_game_parameters.<key>.grooming.value` with `value`, `unit` "brushing sessions per week (sourced need)", `source_id`, a **verbatim** `quote` from the breed's own data.json entries, `confidence`, `notes`, `decision`: "Once a week" → **1**; "Every day" / "daily" → **7**; a range "A–B per week" / "once or twice a week" → **B** (upper bound, also when another source gives a single lower number — that number goes to `alternatives`); "More than once a week" / "a few / several times a week" **without a number** → the most specific number of another A/B source in data.json (`suitability.rkc_grooming`, `pdsa_grooming`, `woodgreen_*`); still no number → **2** seeded `verified => false` with decision `predlog Claude <date>: … čaka Davida` and listed under **Odprto za Davida** in `docs/breeds/<en-slug>.md` (David confirmed 2 for the five existing breeds on 2026-10-12 — `CONFIRMED_DAVID_2026_10_12_TWICE`, 6th tuple element of `DOG_GROOMING`; a new breed still asks David); a hedged "may need daily" is still read as 7 by the rule, but list it for David (he chose 4 for the Cavalier on 2026-10-12); no grooming statement at all → no row, no routine (the mutt, the Border Collie). A row changed by a data migration (e.g. `2026_12_01_120000_apply_david_dog_grooming_decisions`) carries `breed_stage_param_changes` audit rows, so the insert-only seeder never touches it again — any later change of that row needs another data migration. Then: `BreedStageParamsSeeder::DOG_GROOMING` row `[N, source id, quote, confidence, proposal?]` (the row is appended after the lifespan by `rows()`; decision `CONFIRMED_R13_DOG_GROOMING` / `PROPOSED_R13_DOG_GROOMING`), the breed test's `$profileKeys` (Border Collie comparison) already lists `GroomingSessionsPerWeek` — a proposal row also goes into the breed test's "open proposals" list and `LifeStageDataTest`; mobile `GROOMING_DOG_BREEDS` (`species.ts`) and the picker hint "Part of the 12-week challenge · brushing N× a week" / "Del 12-tedenskega izziva · česanje N× na teden" (7 → "every day" / "vsak dan"); the export adds `grooming_rule` from the data.json entry by itself (`DogGroomingTest` and the export test check seeder ↔ data.json ↔ app list). | Rottweiler 1, Pembroke Welsh Corgi 3, Yorkshire Terrier 7, German Shepherd 2 (confirmed by David 2026-10-12) |
| Puppy socialisation (M5-R17, David 2026-10-12 "tedenska naloga"; **every dog breed** since David 2026-10-12 "socializacija za vse mladičke") | **Every new dog breed gets the row automatically** — `BreedStageParamsSeeder::dogSocialisation()` returns David's decision row (`CONFIRMED_R17_ALL_PUPPIES`, value 1, puppy stage, `source_id` / `quote` null, ref `proposed_game_parameters.puppy_socialisation`) for any dog breed without a `DOG_SOCIALISATION` entry; `PuppySocialisationTest` fails when a dog breed has no row or a cat has one. **Additionally**, a dog breed whose data.json holds a **verbatim** A/B quote advising socialisation (a need — "will need socialising from a young age" — or a condition — "if socialised correctly from a young age …", "given the right socialisation as puppies") gets the puppy's weekly "new experience" (one tap: a new person, another dog, a new sound, a new place; never scored). Record `proposed_game_parameters.<key>.socialisation` = `{value: 1, unit, source_id, quote (verbatim, copied from the breed's own entry — never invented, never a paraphrase), confidence, notes (the data.json path of the quote), decision "potrdil David 2026-10-12: 1 nova izkušnja na teden, dokler je mladiček; brez kazni (ROADMAP M5-R17 …)"}`; then a `BreedStageParamsSeeder::DOG_SOCIALISATION` row `[source id, quote]` (seeded on the **puppy** stage, value 1, `CONFIRMED_R17_SOCIALISATION`, verified) and `StageParamKey::SocialisationTasksPerWeek` in the breed test's `$profileKeys` (Border Collie comparison). `PuppySocialisationTest` checks the breed list, the quotes against data.json and the excluded breeds — update its lists. No quote (only a paraphrase in `value`, or nothing) → no `DOG_SOCIALISATION` entry: the breed keeps David's general row (never invent a quote). No app change: the server's `socialisation` block decides. | Rottweiler (PDSA S169), Dobermann (PDSA S211) with a quote; the Bernese, Golden Retriever, Yorkshire Terrier … with David's general row |
| Meal / exercise gap (M5-R16, David 2026-10-12 "1 ura, samo opozorilo") | A dog breed whose A/B source says to leave a gap after eating and before exercise (GDV / bloat) **with a verbatim quote in the breed's own data.json entries** gets the digestion warning: `proposed_game_parameters.<key>.meal_exercise_gap` with `value` **60**, `unit` "minutes after a meal with the 'wait with the walk' warning (the source gives no number; 60 = David's decision)", `source_id` (the source of the advice), the verbatim `quote`, `confidence`, `notes`, `decision` "potrdil David 2026-10-12: 60 min, samo opozorilo, koraki se štejejo (ROADMAP M5-R16)". Then one `BreedStageParamsSeeder::MEAL_EXERCISE_GAP` entry `[source id, quote]` (the row is appended after the grooming row by `rows()`, decision `CONFIRMED_R16_MEAL_EXERCISE_GAP`) and `StageParamKey::MealExerciseGapMinutes` in the breed test's `$profileKeys` (Border Collie comparison); `MealExerciseGapTest` checks data.json ⇔ seeder both ways. A paraphrase only (e.g. the Bernese Mountain Dog's PDSA S154 note) → no row; never invent a quote. No mobile or export change. | Cane Corso (S199), Dobermann (S211) |

## 4. Touch points (dog; French Bulldog = example)

**Branch:** `git checkout -b feat/M5-R10-NN-<slug> origin/main` (NN = the `#` column of `docs/research/BREED_QUEUE.md`, slug = EN slug).

### Research commit — `docs(research): <Breed> sourced data + game decisions [M5-R10]`
`docs/research/dog-data/data.json`, `docs/research/dog-data/sources.md`, `docs/research/breeder-checklist/data.json` (+ `sources.md` for a new K-id, §2.3), `docs/research/BREED_QUEUE.md` (row → `in-progress`).

### Backend commit — `feat(backend): add <Breed> breed [M5-R10]`
| File | Change |
|---|---|
| `backend/app/Enums/BreedType.php` | case, `slug()`, `species()`, `defaultPremium()` |
| `backend/database/migrations/<next date>_120000_add_<key>_breed.php` | next free date (`ls backend/database/migrations \| tail -1` + 1 day), widen `pets_breed_type_check` OLD → NEW, `down()` restores OLD (fails while such pets exist). **Cats:** also `pets_species_breed_check` (cat list). |
| `backend/database/seeders/BreedConfigsSeeder.php` | row: slug, `daily_steps_required` = adult steps, Border Collie care rates, `premium_unlock` true, species, `sort_order`, `label_key` `breeds.<key>`, `search_keywords` |
| `backend/database/seeders/BreedStageParamsSeeder.php` | `CONFIRMED_*` constant (decision text), `DOG_BREEDS`, `dogProfile()` arm, `<key>Profile()` (starts_at, arrival, arrival_meta, young_per_age_notes, adult_minutes, senior_minutes, adult_weight, growth_end, coren_rank, learning_multiplier, individual_variation null, lifespan), and (M5-R13) the breed's `DOG_GROOMING` row when the sources give a grooming frequency (§3 "Dog grooming"), and (M5-R16) a `MEAL_EXERCISE_GAP` entry when a source quotes the gap after eating (§3 "Meal / exercise gap"). A row with `derived_from` in data.json must have `source_id` = exactly those ids (LifeStageDataTest). |
| `backend/config/breed_appearance.php` | header note + breed block (`display_name`, `verified` false, `source`, `sources`, `traits`, `prompt_order`) |
| `backend/config/breed_colours.php` | (M5-R21, paid breeds) the colour picker: one entry per `coat_color` option of the new appearance block, **same order, `value` byte-identical** to the appearance option; `key` = short kebab-case id unique within the breed (reuse an existing key for the same colour, e.g. `black`, `black-tan`, `tricolour`), `en` / `sl` = short picker labels (SL adjective, masculine: »Črn«, »Rdeče-bel«). Forgetting it does not break media (the breed still shares looks with a colour sampled from its appearance, `BreedColourCatalogue::lookColours`), but the parent gets no colour picker and `BreedColourSharedMediaTest` (»lists exactly the standard coat colours…«) fails — expected: that test green with the new breed's list. |
| `backend/app/Services/FalAiService.php` | DNA v1 `buildPromptAnchor` + `buildVisualTraits` arms |
| `backend/config/breed_suitability.php` | breed entry; new vocabulary key if any |
| `backend/app/Http/Resources/BreedCatalogResource.php` | `@var` breed union; suits / consider union for a new tag |
| `backend/app/Http/Controllers/PairingController.php` | `@var` breed union |
| `backend/app/Filament/Resources/BreedConfigResource.php` | slug examples in two helper texts |
| `backend/database/factories/PetFactory.php` | `<camelKey>()` state |
| `backend/lang/{en,sl}/breeds.php` | breed name for the responsibility certificate (EN name / SL name; `ChallengeCertificateTest` checks every `BreedType`) — added after the Boxer run (M5-R10-26) |
| Tests | new `tests/Feature/<Breed>BreedTest.php` (copy the newest breed test: config, migration reversibility, insert-only seed, rows vs data.json, stage rules dataset, senior month, learning, generate-pin paid rule, `/api/breeds` entry, appearance / portrait traits, no statistics). **Every assertion about the new breed goes into its own file** — its suitability tags too (don't append a block to `BreedSuitabilityTest`). In it use the shared helpers of `tests/Pest.php`: `breedSlugsOf(Species::Dog, '<slug>')` for "every other breed untouched", `expectListedAfter($breeds, '<key>', '<previous key>')` for the `/api/breeds` position (returns the index for the payload check, `collect(...)->except('colours')`), `seededBreedConfig('<key>')` for seeder values. **Never edit another breed's test file or a shared test to add the breed to a list**: `BreedConfigTest`, `SpeciesFoundationTest`, `BreedPortraitTest`, `CatLifeStageDataTest`, `CatAiMediaTest`, `DogGroomingTest`, `PuppySocialisationTest` and every `*BreedTest.php` read the breeds from `BreedType` / `BreedConfigsSeeder` / data.json (David 2026-10-15) and cover the new breed automatically. Edit a shared test only when the breed is a genuine exception to a shared rule there (e.g. a `BreedSuitabilityTest` invariant with a per-breed exception list) — say so in the PR. |

### Mobile commit — `feat(mobile): <Breed> in the picker [M5-R10]`
| File | Change |
|---|---|
| `mobile/src/api/schema.ts` | regenerate (§5) — must be additive only |
| `mobile/src/modules/species/species.ts` | `BREED_SPECIES`; `GROOMING_DOG_BREEDS` when the breed brushes (M5-R13) |
| `mobile/src/i18n/locales/{en,sl}/family.json` | `breeds.<key>` |
| `mobile/src/i18n/locales/{en,sl}/pet.json` | `picker.breedHints.<key>` ("Part of the 12-week challenge." / "Del 12-tedenskega izziva."; a brushed dog: "Part of the 12-week challenge · brushing N× a week" / "Del 12-tedenskega izziva · česanje N× na teden", M5-R13), `picker.ageHints.<key>` (puppy / young / adult / senior with the real numbers, same sentence shapes as the other breeds), `breedSuitability.*` label for a new tag |
| `mobile/src/modules/petProfile/picker.ts` | `CONSIDER_TAGS` / `SUITS_TAGS` for a new tag, `FALLBACK_BREEDS` entry (= seeder + suitability; no colours offline — `FALLBACK_CATALOGUE` adds `colours: []`, M5-R21), doc comment numbers |
| `mobile/src/i18n/__tests__/speciesCoverage.test.ts` | nothing for the breed's own texts (`pet:picker.*Hints.<dog key>`, `family:breeds.<dog key>` are dog-only by rule, read from `BREED_SPECIES`); a `DOG_ONLY` reason only for a new non-breed key that names a dog |
| Tests | the breed's own expectations (exact hints EN + SL, chips, search, age hints, confirm) — today appended per breed in `petProfile/__tests__/picker.test.ts`, `catalogue.test.ts`, `species/__tests__/species.test.ts`, `components/parent/__tests__/PetPickerStep.test.tsx` (prefer a new `__tests__/<key>.breed.test.ts(x)` file next to them). **Never add the breed to a list:** the full-list checks (fallback order, plan / lock chain, `DOG_BREEDS`, picker order) read `BREED_SPECIES`, and the step goals come from data.json (`ADULT_MINUTES` is a frozen lock of the older breeds, David 2026-10-15). |

### Register export commit — `feat(scripts): <Breed> in the breed registry export [M5-R10]`
`scripts/export-breed-registry.mjs`: `SPECIES[].breeds` entry `{ id, synonyms: { en: [...], sl: [...] } }` (**no `availability`** — derived, §9b; a new breed exports as `coming_soon` until a release contains it), `DOG_FACTS.<key>` (only sourced refs; never a number that misleads — e.g. a median age at death of a young population), `DOG_GAME.<key>`, `FCI_ORIGINS` for a new country, a new qualifier / parser only if a value needs it. Then `node scripts/export-breed-registry.mjs` (writes `docs/research/breed-registry.json`) and extend `scripts/tests/export-breed-registry.test.mjs` (slug, `expected` game numbers, a breed-specific test for anything new).

### Breed doc commit — `docs(breeds): <Breed> [M5-R10]`
**Only** `docs/breeds/<en-slug>.md` (copy `docs/breeds/_TEMPLATE.md`; `<en-slug>` = the breed's `slug.en` in `breed-registry.json`), then `node scripts/breeds-index.mjs` (regenerates the index table in `docs/breeds/README.md`; `node --test scripts/tests/breeds-index.test.mjs` checks it), and the `BREED_QUEUE.md` row. In Slovenian:
- **Stanje** line (FEATURES style: 🛠 built / ✅ in the app / 📱 verified on a phone / 🗓 planned; free / paid; `M5-R10-NN`; date) — "na telefonu še ni preverjeno" until David confirms;
- **Odločitve** — one row per decision with the source ids and who decided (runbook rule / David / Claude's proposal awaiting David): minutes → steps, stage boundaries, senior month, learning, grooming, meal gap, socialisation, tags, colours, marketing (§3);
- **Kaj je v aplikaciji** (FEATURES-style rows), **Zgodba** (BUILD_LOG style: what · why it matters · how to tell parents / kids / investors, real numbers);
- **Odprto za Davida** — every needs-david item with its target task id — instead of appending the breed to ROADMAP rows (breed care tasks with a sourced frequency are no longer needs-david: §3 »Breed care tasks«);
- PR numbers (pet-prep, portrait, website).
Do **not** touch PRODUCT_SPEC, PARENTS / INVESTORS / audiences README, FEATURES, ROADMAP, DECISIONS, ARCHITECTURE, DIAGRAMS, BUILD_LOG or HANDOFF (they point to `docs/breeds/`; HANDOFF gets nothing — the queue row is the run's status).

## 5. Build and test (cloud session, no Docker)

```bash
service postgresql start; su postgres -c "psql -c \"ALTER USER postgres PASSWORD 'postgres'\""
su postgres -c "createdb testing_<slug>"
cd backend && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction && cd ..
cd mobile && yarn install --frozen-lockfile && cd ..
PG="DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_USERNAME=postgres DB_PASSWORD=postgres"
# focused first, then everything
(cd backend && env $PG DB_DATABASE=testing_<slug> php vendor/bin/pest tests/Feature/<Breed>BreedTest.php tests/Feature/BreedSuitabilityTest.php tests/Feature/BreedPortraitTest.php tests/Feature/BreedConfigTest.php tests/Feature/SpeciesFoundationTest.php tests/Feature/DogGroomingTest.php tests/Feature/PuppySocialisationTest.php)
# the full suite runs once, in CI (CLAUDE.md Speed rules) — not locally
(cd backend && ./vendor/bin/pint --test <every changed PHP file>)   # ./vendor/bin/pint <file> to fix
# schema.ts from a temporary local server
su postgres -c "createdb schema_<slug>"
(cd backend && env $PG DB_DATABASE=schema_<slug> php artisan migrate --force -q && env $PG DB_DATABASE=schema_<slug> php artisan serve --port=8000 &) ; sleep 4
NO_PROXY=localhost,127.0.0.1 node scripts/generate-api-types.mjs && git diff mobile/src/api/schema.ts   # additive only
pkill -f "artisan serve"; su postgres -c "dropdb schema_<slug>"
(cd mobile && npx tsc --noEmit && yarn test --silent src/modules/petProfile src/modules/species src/components/parent/__tests__/PetPickerStep.test.tsx src/i18n)
node scripts/export-breed-registry.mjs && node --test scripts/tests/export-breed-registry.test.mjs
node scripts/breeds-index.mjs && node --test scripts/tests/breeds-index.test.mjs
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
8. Breed doc: nothing unbuilt reads as available; "na telefonu še ni preverjeno"; marketing decision recorded (exclusion only with explicit veterinary advice against advertising the breed type, §3). `git diff --name-only origin/main` lists no shared doc (header "Own files only") and no other breed's test file.
9. Queue row and `docs/breeds/<en-slug>.md` updated; `docs/breeds/README.md` regenerated.
10. Every sourced breed need is built (David 2026-10-11) — no "proposal, not built" left in data.json, the breed doc or the queue row.
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
# the merge runs CI only (D18) — wait for the main run of the merge SHA to be green
gh api "repos/DataVallis/pet-prep/actions/runs?branch=main&head_sha=<merge_sha>" --jq '.workflow_runs[] | "\(.id) \(.name) \(.status) \(.conclusion)"'
```
**Merge ≠ production (D18, David 2026-10-14).** A merge to `main` deploys nothing. A merged breed reaches users only with the **next manual production deploy** (Actions → CI & Deploy → Run workflow → target `production` — David, or Claude only on David's explicit request; an unattended run never dispatches it) and the production OTA that follows it. Until then the website keeps the breed "Kmalu / Coming soon" (§9b, David 2026-10-13) — never claim it is in the app earlier.
PR body: summary, decisions applied (rule per value), touch points, test counts, review result, the footer lines from the session's attribution reminder. Commit trailers on every commit (Co-Authored-By + Claude-Session from the session's attribution reminder). A failing check: read annotations (`gh api repos/DataVallis/pet-prep/check-runs/<id>/annotations`), fix, push, wait again.

## 8. Portrait (right after the merge — no production deploy needed)

**David 2026-10-19:** a breed that appears on the website must already have its portrait. `breed-portraits.yml`
runs the command on the production server (FAL key, AI Lab budget) in a **one-off container** with the code of
the `ref` input (default `main`) mounted over the image — the live app keeps running production's code, nothing
is deployed. So the portrait is made right after the merge on green CI, **before the website PR (§9)**; never
open the website PR of a breed without its portrait. The workflow refuses when `ref` changes `composer.lock`
(new PHP dependencies need a real deploy) — then `needs-david`, website PR waits.
**Catch-up:** every run starts with one `breed-portraits.yml` run for every register breed missing from
`manifest.json` (e.g. old rows "portrait pending production deploy"), then §8 + §9 for them; take only those
breeds' `.webp` files and manifest entries from the review branch (it can carry stale files of other breeds from
the server folder). Pass `inputs[ref]=<merge_sha>`:

```bash
gh api -X POST repos/DataVallis/pet-prep/actions/workflows/breed-portraits.yml/dispatches -f ref=main -f 'inputs[breeds]=<key>' -f 'inputs[ref]=<merge_sha>'
gh api "repos/DataVallis/pet-prep/actions/workflows/breed-portraits.yml/runs?per_page=1" --jq '.workflow_runs[0].id'
# when completed: annotations name the review branch ("portraits branch: portraits/run-<id>") and the cost
gh api repos/DataVallis/pet-prep/check-runs/$(gh api repos/DataVallis/pet-prep/actions/runs/<run>/jobs --jq '.jobs[0].id')/annotations --jq '.[] | "\(.title): \(.message)"'
git fetch origin "refs/heads/portraits/run-<id>:refs/remotes/origin/portraits/run-<id>"
git show origin/portraits/run-<id>:docs/research/breed-portraits/dog/<slug>.webp > /tmp/p.webp
php -r '$i=imagecreatefromwebp($argv[1]); imagepng($i,$argv[2]);' /tmp/p.webp /tmp/p.png   # then Read /tmp/p.png and look at it
```
Verdict: a realistic photograph (not an illustration — David 2026-10-10, M5-R11-05; manifest `kind: ai_photo`) of one adult animal of the breed, standard colour = the portrait default, plain fog-grey studio backdrop, no text / people / props, natural proportions; welfare breeds: no extreme face (open nostrils, visible muzzle). Wrong → dispatch once more with `-F 'inputs[force]=true'`; still wrong → keep the better one, note it in the PR and the queue (`done`, "portrait needs David"). Then:

```bash
git checkout -b feat/M5-R10-NN-<slug>-portrait origin/main
git checkout origin/portraits/run-<id> -- docs/research/breed-portraits   # webp + manifest
git commit -m "chore(research): AI portrait of the <Breed> from run <id> [M5-R10]" (+ trailers)
node scripts/export-breed-registry.mjs && node --test scripts/tests/export-breed-registry.test.mjs
git commit -am "chore(research): register the <Breed> portrait in the breed registry export [M5-R10]" (+ trailers)
```
Same commit: the breed doc (`docs/breeds/<en-slug>.md`: portrait row + PR number; `node scripts/breeds-index.mjs`) and the queue row → `done` (with PR numbers). No FEATURES / HANDOFF edit. PR, `CI OK`, merge (docs-only merges do not deploy). Delete nothing on the review branch.

**Optional — colour variants (David 2026-10-26, first a test on `border_collie`; not part of an unattended breed run yet):**
the website breed page will later show the breed in every standard coat colour. Once the default portrait is on
`main`, `breed-portraits.yml` with `inputs[colours]=true` runs `breeds:portraits --colours`: for every colour of
`config/breed_colours.php` except the one the default portrait shows (manifest `colour`; older entries: the coat
colour of the portrait prompt, else the first colour) an **edit** of that portrait (Nano Banana Pro Edit,
`media.breed_portraits.colour_profile`, the portrait as reference image) that changes only the coat colour /
pattern → `<species>/<slug>--<colour-key>.webp` + manifest list `colour_variants` (colour, value, reference,
profile, cost, generated_at). Cost = (colours − 1) × $0.15 (border collie: 6 × $0.15 = $0.90), AI Lab budget. The
reference portraits come from `docs/research/breed-portraits` of `ref`. Check the cost first with
`inputs[dry_run]=true`. Review every variant like the portrait (same dog, pose and backdrop; only the colour
changed; the colour matches the standard); take only the variant files + their `colour_variants` entries from the
review branch (the server manifest's `portraits` list can be older than `main`'s — keep `main`'s; add the default
portrait's `colour` key to its `main` entry if it has none), then `node scripts/export-breed-registry.mjs`: a breed
with committed variants gets `portrait.colours` (ordered like `breed_colours.php`, each `{ key, label: { en, sl },
file: "<species>/<slug>--<key>.webp" }` plus the default portrait `{ …, file: "<species>/<slug>.webp", default: true }`;
no variants → no `colours`), and `--copy-portraits` copies the variant files to `public/animals/<species>/` as well.
First breed on `main`: Border Collie, 6 variants (David approved 2026-10-26; PR M5-R11-08).

**Redo single colours:** `inputs[colour_keys]` (space-separated `breed_colours` keys, with `colours`) passes
`--colour=<key>` — only those colours are planned. An existing (approved) variant is never replaced without
`inputs[force]=true`, also when its prompt changed (`stale` is reported and kept) — so `force` + `colour_keys` redoes
exactly the named variants. **Colour hints** (`BreedPortraitService::COLOUR_HINTS`) add a sentence to the edit prompt
for colours that models get wrong: dilute *blue* (slate blue-grey, visibly lighter than black — not for merle / roan /
speckled / ticked blues), *blue merle* (blue-grey base with black patches), *lilac / lavender*, *liver / chocolate* and
*sable*; colours without a hint keep a byte-identical prompt and hash. For the Border Collie the hints make
`blue-white`, `blue-merle-white` and `sable-white` stale (kept; redo only what David rejects).

```bash
gh api -X POST repos/DataVallis/pet-prep/actions/workflows/breed-portraits.yml/dispatches -f ref=main -f 'inputs[breeds]=border_collie' -F 'inputs[colours]=true' -F 'inputs[dry_run]=true'
# redo one colour (blue-white came out almost black, David 2026-10-26):
gh api -X POST repos/DataVallis/pet-prep/actions/workflows/breed-portraits.yml/dispatches -f ref=main -f 'inputs[breeds]=border_collie' -F 'inputs[colours]=true' -f 'inputs[colour_keys]=blue-white' -F 'inputs[force]=true'
```

## 9. Website (DataVallis/pet-prep-website, from its `main`)

```bash
cd ../pet-prep-website && git checkout -b feat/register-<slug> origin/main
node ../pet-prep/scripts/export-breed-registry.mjs --out "$PWD/src/content/registry/registry.json" --copy-portraits public
```
- `src/content/registry/en.ts` + `sl.ts`: words the build asks for (new FCI group in `fciGroups`, country in `origins`, category values, qualifiers, health keys — one calm sentence each, no numbers; new breeder-checklist test keys in `checklist.tests`, §2.3), and `intros.<key>` = 2 short paragraphs EN + SL from sourced statements only, `sources` ⊂ the breed's `source_ids`, `aka` (other names).
- `npm run lint && npx tsc --noEmit && npm run build` — the build fails on missing words; check the generated page text (`.next/server/app/en/animals/<species>/<slug>.html`, `.next/server/app/sl/zivali/<psi|macke>/<sl-slug>.html`) for grammar (singular / plural, "slower" multipliers).
- Commit `feat(register): <Breed> page …` (+ trailers), push, PR via `gh api repos/DataVallis/pet-prep-website/pulls`, wait for check runs **"Lint, types, build"** and **"Docker image (build + smoke test, no push)"** = success on the head SHA, merge with `merge_method=merge`, then wait for the website `main` run(s) of the merge SHA to succeed.
- Live: `https://petprep.si/animals/dogs/<slug>` and `https://petprep.si/sl/zivali/psi/<sl-slug>`.

## 9b. App release → website status (David 2026-10-13)

The website must say "in the app" only for what users really have. `docs/research/app-release.json` = breeds of the JS bundle of the last production release; the export marks those `in_app`, every other built breed `coming_soon`. Never set `availability` by hand (only `'info_only'` for research-only breeds — the export fails otherwise).

- **OTA:** `mobile-ota.yml` records it itself after a production update reached **at least one** platform — iOS or Android is enough (David 2026-10-25; before: both) — bot commit `chore(release): record OTA <sha> (<platforms>) …` on `main`.
- **Website follows by itself:** `pet-prep-website/.github/workflows/registry-sync.yml` (hourly + Run workflow) copies `docs/research/breed-registry.json` from pet-prep `main` when it differs, runs lint / build / tsc, commits `chore(register): sync …` to the website `main` and dispatches its "CI & Deploy". When the build fails (the export needs new words or a portrait the website does not have yet) it only warns — then the website breed PR (§9) carries the registry.
- **Store build:** when David says which commit his EAS production build came from: `node scripts/record-app-release.mjs --kind store --commit <sha>` → re-export → PR.
- **Every unattended run, before §1 (fallback — normally registry-sync.yml has done it):** if `inputs.app_release.sha256` in the website's `src/content/registry/registry.json` differs from the export's, sync the website first: branch `chore/register-app-release-<date>`, re-export into the website (§9 command), build, PR, merge on green.
- A breed merged in a run stays "Kmalu / Coming soon" on the site until the next release — that is correct, not a bug. Since D18 a production OTA is published only after a manual production deploy (or a manual OTA run with channel `production`); merges publish no OTA.

## 10. Queue and report

- `docs/research/BREED_QUEUE.md`: `done` + date + PR numbers + link to `docs/breeds/<en-slug>.md`; when the 20 are done, append the next breed by AKC 2024 popularity (dogs) or FIFe / GCCF registrations (cats) **with the source URL** in the row.
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
