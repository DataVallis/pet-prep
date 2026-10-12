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
      "verified_by": "claude-verifier"
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

## Import rules (`php artisan breeds:import-facts {--breed=*} {--dry-run}`)

- A file with **any** error is rejected as a whole (nothing of it is written); other files still import.
- Upsert by `key`. New key → inserted with the file's status.
- **Approved** fact, content unchanged → not touched. Approved fact whose content changed →
  new content, status back to the file's status, approval cleared → David approves again.
- Draft / verified fact: changed content → new content + the file's status; unchanged content →
  the file may promote `draft` → `verified` (a verify done in the admin is never undone).
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
