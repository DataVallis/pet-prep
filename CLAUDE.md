# PetPrep — Agent Operating Manual

AI dog simulator that proves (or disproves) a child is ready for a real pet. Parent + child dual-profile mobile app,
Laravel backend, real-time sync. Owner: David Tacer (Data Vallis). The main Claude session acts as **orchestrator**;
specialised subagents live in `.claude/agents/`.

**Language:** code, comments, commits, agent docs → English. Product/business docs and conversation with David → Slovenian.

## Read first (in this order)
1. `HANDOFF.md` — current state, what is broken, what's next. Trust it over older docs.
2. `docs/engineering/ROADMAP.md` — pick tasks by ID (M1-03 …). Don't freelance outside the roadmap without asking.
3. `docs/product/PRODUCT_SPEC.md` — canonical game rules (decay rates, windows, escalation). Code must match it.
4. `docs/engineering/ARCHITECTURE.md` — what exists today (tables, routes, events).
5. `docs/engineering/AUDIT-2026-10-02.md` — known defects; don't rediscover them.

`prompt.md` is the historical build prompt. `docs/source/` are the original docs — background only.
⚠️ The old `HANDOFF` claim "100 % complete" was false; the audit is the truth.

## Monorepo map
- `backend/` — Laravel 11 API, PHP 8.5 (Sail), PostgreSQL 18, Reverb, Sanctum, Filament 3 (`/admin`), Scramble (`/docs/api`), Pest. See `backend/CLAUDE.md`.
- `mobile/` — Expo SDK 57, RN 0.86, React 19, StyleSheet (no NativeWind), Zustand, TanStack Query, EAS (`eas.json`). See `mobile/CLAUDE.md`. Part of this repo since 2026-10-02 (the old `pet-prep-mobile` repo is retired).
- `brand/` — CGP v2 ("Grafit in meta"): logos, app icons (iOS + Android adaptive/monochrome, splash), fonts, colour tokens, voice. Read `brand/README.md` before any UI, icon or marketing work.
- `scripts/` — `generate-api-types.mjs` (OpenAPI → `mobile/src/api/schema.ts`), production deploy/backup/restore scripts.
- `deployment/Caddyfile`, `backend/compose.production.yaml`, `.github/workflows/deploy-production.yml` — production stack.

## Commands
```bash
# backend (Docker via Sail — preferred)
npm run sail:up            # pgsql, redis, mailpit, app on :8000
npm run sail:migrate       # migrate:fresh --seed (dev data only)
npm run sail:test          # Pest
npm run sail:reverb        # websocket :8080
npm run sail:queue
cd backend && ./vendor/bin/sail artisan pets:process-decay   # one game-loop tick
cd backend && ./vendor/bin/sail pint                        # code style
# mobile
cd mobile && yarn install && npx expo start      # yarn 1 is the package manager (M0-05)
cd mobile && yarn test && npx tsc --noEmit
# contract
npm run generate-api-types # backend must be running
```
Dev accounts come from `TestUsersSeeder` (`parent@test.com` / `child@test.com`, password `password`). Never seed them in production.

## Definition of done (every task)
1. Behaviour matches `PRODUCT_SPEC.md`; if the spec is ambiguous, ask David — don't invent rules.
2. Tests: Pest feature test for every endpoint/service change; Jest for mobile logic. Time-dependent logic uses `Carbon::setTestNow()` / fake timers.
3. `sail test`, `pint --test`, `npx tsc --noEmit`, `npm test` all green.
4. API changed → regenerate types (`npm run generate-api-types`) and update `ARCHITECTURE.md` §3.
5. Tick the task in `ROADMAP.md`, add a dated entry to `HANDOFF.md` (what changed, new debt, next step).
6. Notable progress → entry in `docs/journey/BUILD_LOG.md` (Slovenian; raw material for social, investor, partner and parent content).
7. Any user-visible feature added, changed, removed or verified on a device → update its row in `docs/product/FEATURES.md` (status, roadmap ID, date) in the same PR.

## Git
- Branch from `main`: `feat/M1-07-child-actions`, `fix/M1-01-decay-rounding`, `chore/…`, `docs/…`.
- Conventional Commits (`feat(backend): add child feed endpoint [M1-07]`). One task per PR.
- Never commit `.env`, keys, `node_modules/`, `vendor/`, IDE files. Never force-push `main`.
- **Pre-production autonomy (David, 2026-10-03):** Claude opens a PR per task, waits for green CI (+ one `qa-reviewer` round only for risky areas — see Speed rules), then **merges it to `main` itself**.
- **Manual production deploys (David, 2026-10-14, DEPLOYMENT.md D18):** a merge to `main` runs CI only and **deploys nothing**. **Production `api.petprep.si` is deployed only when David — or Claude on David's explicit request — runs Actions → CI & Deploy → Run workflow on `main` with target `production`** (`gh workflow run deploy-production.yml --ref main -f target=production`). Never dispatch a production deploy on your own (unattended breed runs included). A merged change reaches users only with the next production deploy (OTA follows it).
- CI's single gate is the **`CI OK`** check (no branch protection on this plan — the merge helper waits for it). Bring the PR branch up to date with `main` before CI so `main` can skip re-testing the identical tree; "Run workflow" on `main` = full test run (+ production deploy with target `production`) (DEPLOYMENT.md D16, D18).
- Never merge red CI, never force-push `main`, never merge with unresolved review blockers. Delete nothing in production data without David.

## Living documentation (mandatory — "za nazaj se ne bomo spomnili")
Documentation is written **while** building, not afterwards. From it we will later produce technical docs, guides for parents and kids, investor and partner material, 2-pagers, decks and diagrams. Every PR updates, in the same PR:
1. `docs/engineering/ARCHITECTURE.md` + `docs/engineering/DIAGRAMS.md` (Mermaid) when structure, data, API, events or flows change.
2. `docs/product/DECISIONS.md` — one dated line per decision (who decided, what, why, link to PR). Product rules also go to `PRODUCT_SPEC.md`.
3. `docs/audiences/*` when anything a parent, child, investor or partner would notice changes (features, safety, privacy, pricing, numbers). Facts only; unbuilt things marked *načrt*/*planned*.
4. `docs/journey/BUILD_LOG.md` — dated story entry for every notable change (what · why it matters · how to tell it per audience), with real numbers.
5. `docs/product/FEATURES.md` — **feature catalogue (David, 2026-10-07): what the product can do today**, per area, with status (✅ in the app · 📱 verified on a phone · 🛠 backend only · 🗓 planned · ⏸ post-MVP), free/paid, roadmap ID and date. It is the source for the website, store listings and audience material, so it must never lag behind: every PR that adds, changes or removes something a parent, child or admin can see updates its row; when David confirms a feature on a device, change it to 📱 with date and build. Facts only — nothing planned may read as available.
6. `HANDOFF.md` + `ROADMAP.md` as before.
Exception: unattended breed runs document only in `docs/breeds/<slug>.md` (ADD_BREED_RUNBOOK.md) and never edit the shared docs above (David 2026-10-15).
Keep the claude.ai Project copies (`petprep/*.md`) in sync after merges. No child personal data in any doc.

## Engineering rules
**Backend:** thin controllers → Service classes; FormRequest for every input; Policies/Sanctum abilities for authz (not ad-hoc `isParent()`); multi-row writes in `DB::transaction`; **no external HTTP inside transactions** — dispatch a queued Job; webhooks fail **closed** when the secret is missing; broadcasts on `PrivateChannel`; all wall-clock rules (quiet hours, midnight, feed windows) evaluated in the family's timezone; store UTC.
**Mobile:** strict TS, no `any`; server state via TanStack Query, UI-only state in Zustand; types from `schema.ts`; every user-visible string through i18n (once M1-18 lands); test on a dev build for native modules (HealthKit, RevenueCat, notifications don't run in Expo Go).
**Both:** the child is a minor — collect the minimum data, no analytics SDK without David's OK, no third-party calls carrying child PII.

## Scope guard (MVP)
Do NOT build: AR, GPS maps, weather API, LLM/vision vet, B2B QR coupons. (Cats are IN scope since 2026-10-07 — species → breed picker, ROADMAP M5-R06 — but need a care spec first; don't build cat rules before David approves it. Multiple parents/children/pets per family is IN scope since 2026-10-04 — see `docs/decisions/ADR-012-family-model.md`.) Post-MVP ideas go to the backlog in `ROADMAP.md`.

## Speed rules (David, 2026-10-15 — "6 h for a small feature is unacceptable")
- **Tests:** agents run only the tests of the area they touched (the changed test files + their neighbours, e.g. `pest tests/Feature/BreedColour*`, `yarn test src/modules/petProfile`), plus `tsc` / `pint --test --dirty`. **The full Pest / Jest suite runs once, in CI** — never locally "just to be sure" (it takes ~35 min here and CI repeats it anyway). CI green is the gate.
- **QA review only where risk is real:** auth / sign-in, payments, deletion or export of data, child data / privacy, security, migrations on existing data, money / AI-cost logic. Everything else (UI, copy, small features, docs, breed runs) ships on tests + green CI. Where QA runs: **one round** — fix the blockers/majors, no second full review unless a blocker touched security.
- **Parallel, not serial:** agree the API contract first, then backend and mobile agents work at the same time.
- **Small PRs, merge as soon as CI is green;** bring the branch up to date with `main` right before CI, not after.
- **Docs:** update only the docs whose content really changed (FEATURES for user-visible changes, DECISIONS for decisions, HANDOFF one short line). No doc churn for its own sake.
- Targets: small task (one screen / one fix) ≤ 45 min from request to merge; medium feature (backend + app) ≤ 2 h.

## Orchestration
**Long sessions (David, 2026-10-06):** when a session gets very long (older context already compacted into summaries) or after a larger finished block of work, run `/handoff` so `HANDOFF.md` is complete, then recommend David to start a new session; the new agent starts by reading `HANDOFF.md`.
Delegate by area: `backend-engineer`, `mobile-engineer`, `qa-reviewer` (independent review before merge), `devops` (Hetzner / Docker / CI), `growth-marketer` (copy, funnels, decks — Slovenian/English). Slash commands: `/handoff`, `/verify`, `/feature <ID>`, `/deploy`.

## Secrets & infra
Production: `https://api.petprep.si` — Hetzner CX23, Ubuntu 26.04, `138.199.172.97`, Docker Compose + Caddy, PHP 8.3 (PHP-FPM production image `backend/docker/production`, OPcache; Caddy serves `public/` and pet media directly). Docs: `docs/PRODUCTION_DEPLOYMENT.md`, `docs/PRODUCTION_ENV.md`; open findings in `docs/engineering/DEPLOYMENT.md`; growth / multi-country plan in `docs/engineering/SCALING.md` (update it when infra changes). Run `eas` only from `mobile/`. **Never change `mobile/app.json` `name`, `version`, `ios.bundleIdentifier` or `android.package` — David owns app identity and versions (2026-10-06).**
Secrets live only in `backend/.env`, `mobile/.env`, `mobile/credentials.json` (local, gitignored) and `/opt/petprep/.env` (server). Don't read or print `.env` contents unless David asks; use `.env.example` for key names. `ACCESS.md` has dev-only credentials.
