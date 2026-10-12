# Breed facts — »Ali si vedel?« (M5-R09)

Sourced facts per breed for the »Ali si vedel? / Ali ste vedeli?« card and the breed guide
(spec: `docs/product/DID_YOU_KNOW_SPEC.md`). **Content lives here in git**; the server
imports it (`breeds:import-facts`) and David approves each fact in the admin
(`/admin` → Breed facts). Only **approved** facts reach the apps.

## Files

One file per breed: `<breed>.json`, where `<breed>` is the `BreedType` value
(`border_collie.json`, `maine_coon.json`, …). **No file for `mutt` or `domestic_cat`**
(David 2026-10-09: breeds without a pedigree get no facts — the importer rejects them).

```json
{
  "breed": "border_collie",
  "facts": [
    {
      "key": "border_collie-001",
      "category": "exercise",
      "audience": "child",
      "title": {"en": "…", "sl": "…"},
      "why": {"en": "…", "sl": "…"},
      "source_id": "S5",
      "source_name": "The Royal Kennel Club",
      "source_url": "https://…",
      "quote": "verbatim sentence from the source",
      "game_rule_key": "daily_steps",
      "sort": 1,
      "status": "verified",
      "verified_by": "claude-verifier",
      "verified_hash": "1122ede85cf066391be6c671fb8ea18976bb85c74719aed51bff8a6fade65655"
    }
  ]
}
```

| Field | Rule |
|---|---|
| `breed` | `BreedType` value; must equal the file name; not `mutt` / `domestic_cat` |
| `key` | stable, unique, `<breed>-…` (lower case, digits, `_`, `-`; ≤ 100). **Never reuse or rename a key** — reads and approvals hang on it |
| `category` | `origin` · `body` · `temperament` · `exercise` · `diet` · `grooming` · `training` · `family` · `health` |
| `audience` | `child` or `parent`. **`family` and `health` are parent-only** (`audience` must be `parent`) |
| `title.en`, `title.sl` | required, ≤ 120 characters |
| `why` | `null`, or both `en` and `sl`, each ≤ 140 characters (»zakaj je to pomembno zate«) |
| `source_id` | id from the breed's `sources.md` (e.g. `S5`, `C3`), required |
| `source_name` | institution name shown in the app (child too), required |
| `source_url` | `http(s)://…`, required (shown to parents only) |
| `quote` | the **verbatim** sentence the fact is based on, required |
| `game_rule_key` | `null` or snake_case key of the game rule the fact explains (e.g. `daily_steps`) |
| `sort` | integer, display order (lower first) |
| `status` | `draft` or `verified`. **`approved` is not allowed in a file** — only David approves, in the admin |
| `verified_by` | `null`, or who checked the fact against the quote (required when `status` is `verified`) |
| `verified_hash` | `null`, or the **content hash** of the exact text the verifier checked (see below). A fact is imported as `verified` **only** when `status` is `verified` **and** `verified_hash` equals the fact's current content hash — otherwise it is imported as **`draft`** (also for a new fact). Any change of the text therefore needs a new verification and a new `verified_hash`. |

**No other keys are allowed** — top level only `breed` and `facts`; per fact only the keys above; `title` / `why` only `en` and `sl`. An unknown key (e.g. a typo) rejects the file.

### Content hash (`verified_hash`)

Lower-case hex **SHA-256** of the UTF-8 bytes of the JSON array (no spaces, `/` and non-ASCII characters **not** escaped — exactly JavaScript's `JSON.stringify`):

```
[breed, category, audience, title.en, title.sl, why.en|null, why.sl|null,
 source_id, source_name, source_url, quote, game_rule_key|null]
```

`key`, `sort`, `status`, `verified_by` and `verified_hash` are **not** part of it (reordering never re-opens a review). Compute it with either:

```bash
# PHP (prints "<key> <hash> <no-verified_hash | verified_hash-ok | verified_hash-MISMATCH>"; writes nothing)
cd backend && php artisan breeds:import-facts --dir=../docs/research/breed-facts --print-hashes

# Node (no backend needed)
node -e 'const c=require("crypto"),d=require(require("path").resolve(process.argv[1]));for(const f of d.facts){const v=[d.breed,f.category,f.audience,f.title.en,f.title.sl,f.why?f.why.en:null,f.why?f.why.sl:null,f.source_id,f.source_name,f.source_url,f.quote,f.game_rule_key??null];console.log(f.key,c.createHash("sha256").update(JSON.stringify(v),"utf8").digest("hex"))}' docs/research/breed-facts/border_collie.json
```

The verifier writes the hash **of the text it checked** into `verified_hash` (never re-compute it for an edited text without verifying again).

## Import rules (`php artisan breeds:import-facts {--breed=*} {--dry-run}`)

- A file with **any** error is rejected as a whole (nothing of it is written); other files still import.
- `verified` only with a matching `verified_hash` (see above); otherwise `draft` (reported).
- Upsert by `key`. New key → inserted (`verified` with proof, else `draft`).
- **Content unchanged** → only `sort` may change; `draft` → `verified` with proof; never demoted;
  an approval stays.
- **Content changed** (any status, also approved) → new content, `verified` with proof else
  `draft`, approval cleared → David approves again.
- Facts in the database but missing from the file are **kept** and listed in the output (never deleted).
- Idempotent: running it twice changes nothing.

## Shipped copy (production)

The production image is built from `backend/` only, so `docs/` is not on the server's PHP
containers. The importer reads `backend/database/data/breed-facts/` — an **exact copy** of this
folder (the `*.json` files). After adding or changing a file here:

```bash
cp docs/research/breed-facts/*.json backend/database/data/breed-facts/
```

and commit both. `BreedFactImportTest` fails CI when the two folders differ. Then, after the
deploy, on the server:

```bash
docker compose -f backend/compose.production.yaml run --rm app php artisan breeds:import-facts --dry-run
docker compose -f backend/compose.production.yaml run --rm app php artisan breeds:import-facts
```

and approve the facts in `/admin` → Breed facts (bulk »Approve selected« for verified rows).
