# Runbook — nightly care food run (M6-K4)

David 2026-10-31: every food we can source goes into the care knowledge base; a scheduled task works
through `docs/research/care/FOOD_QUEUE.md`, **10 foods per night** (each for dog and cat = up to 20 files),
unattended, no questions. Plan: `docs/product/CARE_KNOWLEDGE_SPEC.md`; format: `docs/research/care/README.md`.

## 0. Lock

```bash
gh api 'repos/DataVallis/pet-prep/pulls?state=open&per_page=100' --jq '.[].head.ref' | grep '^feat/M6-K-foods-'
```
Prints anything → another run (or an unmerged batch) is open: **stop, do nothing**.
Also stop if `docs/research/care/README.md` or this runbook is missing on `main` (M6-K0 not merged yet).

## 1. Pick the batch

The first 10 queue entries (in file order) that are not `[x]` and are not missing for a species that
needs them (`[d]` = dog done, `[c]` = cat done; a `(cat)` / `(dog)` suffix = that species only).
Skip entries marked `→ hazards`. Branch: `feat/M6-K-foods-<first-slug>`.

## 2. Write (parallel agents, one per species)

Each writer agent gets the batch slugs, the README schema and these rules (same as M6-K0):
- open every source page itself (WebFetch, ask for verbatim sentences); quotes verbatim; tier A/B ≥ 1;
  prefer the species-specific page; sources must speak about that species;
- reuse existing source ids (grep `sources-<species>.md` for the URL first); new ids continue after the
  highest existing `KD…` / `KC…`;
- `level` stricter when sources disagree, explained in `why`; no doses, no "induce vomiting", no
  frightening child text; every statement backed by a quote;
- locales: every locale already used in that species' files (today `en`, `sl`);
- write one food at a time; no A/B source → no file, and the queue line gets
  `— no source <species> <date>`.

## 3. Independent verification

A **separate** agent that did not write the text checks every file and every locale against the quotes
(open the URL when a quote looks suspicious). It may only narrow wording to what the quote says, fix
grammar, or reject (delete the file + queue note). For each locale it passes it sets
`verification[L] = {"status":"verified","verified_by":"claude-verifier","verified_hash":<hash>}` with the
hash from `node scripts/export-care-knowledge.mjs --hashes <file>` **after** its last edit.

## 4. Export, test, PR, merge

```bash
node scripts/export-care-knowledge.mjs          # writes docs/research/care-knowledge.json
node --test scripts/tests/export-care-knowledge.test.mjs
```
Tick the queue lines (`[d]` / `[c]` / `[x]`). Docs: this run touches only `docs/research/care/**`,
`docs/research/care-knowledge.json` and `FOOD_QUEUE.md` — never the shared docs (DECISIONS, HANDOFF …),
like unattended breed runs. PR `feat(care): foods <first>…<last> [M6-K4]`, wait for `CI OK`, merge
(squash). The website picks the export up within an hour (`care-sync.yml`) — no website PR, no deploy.

## 5. Stop conditions (mark the queue line, never ask)

Red CI after one fix attempt → leave the PR open (the lock stops the next run) and say so in the summary.
A new species folder or locale needs website words (`src/content/care/copy.ts`) → do not add it; queue note
`needs-david`. Never force-push, never edit production data, `.env`, `CLAUDE.md` or mobile identity.

## 6. Summary (Slovenian, short)

Foods added per species, skipped (why), verifier changes / rejections, PR number + merge SHA, queue left.
