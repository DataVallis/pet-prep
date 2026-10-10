# Breed queue (M5-R10, unattended runs)

Process: `docs/engineering/ADD_BREED_RUNBOOK.md`. One breed per run: the run takes the **first `todo` row**,
sets it to `in-progress`, and ends with `done`, `blocked` or `needs-david` (+ reason).
Order = ROADMAP M5-R10 (David 2026-10-09: most popular first). Cats are built behind the hidden switch
(`PETPREP_CATS_ENABLED=false`, register `coming_soon`) like the Maine Coon.

Status: `done` · `in-progress` · `blocked` (research could not source a required field — reason) ·
`needs-david` (CI failed twice for code reasons or an unfixable review blocker — PR left open) · `todo`.

| # | Breed (EN / SL) | Key | Species | Status | Notes |
|---|---|---|---|---|---|
| — | Border Collie / Border collie | `border_collie` | dog | done | original paid dog (M5-R01) |
| — | Maine Coon / Maine Coon | `maine_coon` | cat | done | M5-R06 (hidden with the cats) |
| 01 | Labrador Retriever / Labradorec | `labrador_retriever` | dog | done | M5-R10-01, PR #121 |
| 02 | Golden Retriever / Zlati prinašalec | `golden_retriever` | dog | done | M5-R10-02, PR #122 |
| 03 | French Bulldog / Francoski buldog | `french_bulldog` | dog | done | M5-R10-03, PR #130 + portrait PR #131, website PR #13 (2026-10-10); welfare breed — not in marketing |
| 04 | German Shepherd Dog / Nemški ovčar | `german_shepherd` | dog | in-progress | M5-R10-04, run started 2026-10-10 |
| 05 | Cavalier King Charles Spaniel / Kavalir King Charles španjel | `cavalier_king_charles_spaniel` | dog | todo | check welfare-concern rule (§3) |
| 06 | Beagle / Bigl | `beagle` | dog | todo | |
| 07 | Poodle (Standard) / Pudelj (veliki) | `standard_poodle` | dog | todo | never "hypoallergenic" |
| 08 | Dachshund / Jazbečar | `dachshund` | dog | todo | check welfare-concern rule (§3) |
| 09 | Australian Shepherd / Avstralski ovčar | `australian_shepherd` | dog | todo | merle is a standard colour here — follow the breed standard (§3 colour rule) |
| 10 | Havanese / Havanski bišon | `havanese` | dog | todo | |
| 11 | West Highland White Terrier / Zahodnoškotski beli terier | `west_highland_white_terrier` | dog | todo | |
| 12 | Bernese Mountain Dog / Bernski planšarski pes | `bernese_mountain_dog` | dog | todo | |
| 13 | Siberian Husky / Sibirski haski | `siberian_husky` | dog | todo | |
| 14 | British Shorthair / Britanska kratkodlaka mačka | `british_shorthair` | cat | todo | hidden (cats) |
| 15 | Ragdoll / Ragdoll | `ragdoll` | cat | todo | hidden (cats) |
| 16 | Siberian / Sibirska mačka | `siberian_cat` | cat | todo | hidden (cats) |
| 17 | Norwegian Forest Cat / Norveška gozdna mačka | `norwegian_forest_cat` | cat | todo | hidden (cats) |
| 18 | Bengal / Bengalska mačka | `bengal` | cat | todo | hidden (cats) |

**After the 20:** the next most popular breed by **AKC 2024 registrations** (dogs) or **FIFe / GCCF registrations** (cats),
appended here by the run that finishes the list — one row at a time, with the source URL in Notes.
