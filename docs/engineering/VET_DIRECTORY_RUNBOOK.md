# Runbook — hourly vet directory run (M6-K1)

David 2026-10-31: the emergency page (petprep.si/care/emergency · /sl/skrb/nujni-primeri) gets a directory of
vets, 24-hour emergency clinics, duty rotas and animal poison lines for **every country**, filterable by country,
region, town and "24 h only". A scheduled task works through `docs/research/care/VET_QUEUE.md`, **one queue line
per run** (a country, or one region of a large country), unattended, no questions.

Data: `docs/research/care/vets/<CC>.json` (ISO-2 file name), exported by `scripts/export-care-knowledge.mjs` into
`docs/research/care-knowledge.json` → the website syncs it hourly (`care-sync.yml`). Format below.

## 0. Lock

```bash
gh api 'repos/DataVallis/pet-prep/pulls?state=open&per_page=100' --jq '.[].head.ref' | grep '^feat/M6-K-vets-'
```
Prints anything → another run is open: **stop, do nothing**.

## 1. Pick the line

The first `[ ]` line in `VET_QUEUE.md`. Branch `feat/M6-K-vets-<cc>[-<region-slug>]`. A line for a region appends
to the existing `<CC>.json` (never removes other regions' entries).

## 2. Research (one agent; a second one for very large lines)

Sources in this order, every page opened with WebFetch — never search snippets, never invented data:
1. official bodies: national/regional veterinary chamber or association, ministry / food-safety authority lists,
   official duty-rota ("Notdienst", "guardia", "dežurstvo", "garde") pages, university veterinary hospitals;
2. the clinic's own website (hours, emergency number);
3. never an SEO aggregator or a map listing as the only source.

Per entry (`kind`): `clinic` — small-animal practice / hospital; `duty_rota` — page that says who is on duty;
`poison_line` — animal poison information line. `emergency_24h: true` **only** when a page explicitly says
24 h / 0–24 / H24 / nonstop / rund um die Uhr / 24 horas. Prefer: all 24-h clinics, all duty rotas, then other
small-animal clinics in tourist areas and large towns. Up to ~60 entries per run; note what is left.
Farm-only practices: `small_animals: false` (kept in research, not published).

## 3. Independent check

A **separate** agent that did not collect the data opens the `source_url` of every 24-h clinic, every duty rota
and every poison line, plus a random 20 % of the other clinics, and confirms name, phone and the 24-h claim.
Wrong phone → fix from the page; claim not on the page → `emergency_24h: false`; entry not found → delete.
It records `"checked"` = today on what it confirmed.

## 4. Export, test, PR, merge

```bash
node scripts/export-care-knowledge.mjs
node --test scripts/tests/export-care-knowledge.test.mjs
```
Tick the queue line (`[x] … — N entries, M × 24 h, <date>`; partial → `[~] … — left: …` and add a follow-up line
under it). PR `feat(care): vets <country/region> [M6-K1]`, wait for `CI OK`, merge (squash). The website updates
itself within an hour — no website PR, no deploy. Only `docs/research/care/**` and the export change; never the
shared docs.

## 5. Rules

Business contact data only (name, address, phone, website, hours as published) — no people's private data, no
reviews or ratings, no opinions. Respect robots.txt and site terms; if a site forbids automated access or a
registry says its data may not be reused, use the clinics' own pages instead and note it. Never ask questions;
on a stop condition mark the line `[!] … — <reason>` and stop. Never force-push, merge red CI or deploy.

## 6. Summary (Slovenian, one short message)

Country/region, entries added (24 h, duty rotas, poison lines), check results (fixed / removed), PR + merge SHA,
next queue line.

## File format — `vets/<CC>.json`

```json
{ "country": "SI", "checked": "2026-10-31",
  "sources": [{ "name": "…", "url": "…", "note": "…" }],
  "entries": [{ "kind": "clinic", "name": "…", "city": "…", "region": "…", "address": "…", "phone": "…",
                "emergency_24h": true, "duty": "…" , "small_animals": true, "url": "…",
                "source_url": "…", "checked": "2026-10-31" }],
  "notes": "…" }
```
Allowed entry keys: kind, name, city, region, address, phone, emergency_24h, duty, small_animals, url, source_url,
checked, note, quote. A clinic needs a phone or a url.
