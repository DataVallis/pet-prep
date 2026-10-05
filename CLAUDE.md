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
- `mobile/` — Expo SDK 57, RN 0.86, React 19, NativeWind 4, Zustand, TanStack Query, EAS (`eas.json`). See `mobile/CLAUDE.md`. Part of this repo since 2026-10-02 (the old `pet-prep-mobile` repo is retired).
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

## Git
- Branch from `main`: `feat/M1-07-child-actions`, `fix/M1-01-decay-rounding`, `chore/…`, `docs/…`.
- Conventional Commits (`feat(backend): add child feed endpoint [M1-07]`). One task per PR.
- Never commit `.env`, keys, `node_modules/`, `vendor/`, IDE files. Never force-push `main`.
- **Pre-production autonomy (David, 2026-10-03):** Claude opens a PR per task, waits for green CI (+ independent `qa-reviewer` review for non-trivial code), then **merges it to `main` itself**. A merge to `main` **deploys automatically** to `api.petprep.si` after tests pass. This stays until David says otherwise — then restore manual deploys (see DEPLOYMENT.md D2).
- Never merge red CI, never force-push `main`, never merge with unresolved review blockers. Delete nothing in production data without David.

## Living documentation (mandatory — "za nazaj se ne bomo spomnili")
Documentation is written **while** building, not afterwards. From it we will later produce technical docs, guides for parents and kids, investor and partner material, 2-pagers, decks and diagrams. Every PR updates, in the same PR:
1. `docs/engineering/ARCHITECTURE.md` + `docs/engineering/DIAGRAMS.md` (Mermaid) when structure, data, API, events or flows change.
2. `docs/product/DECISIONS.md` — one dated line per decision (who decided, what, why, link to PR). Product rules also go to `PRODUCT_SPEC.md`.
3. `docs/audiences/*` when anything a parent, child, investor or partner would notice changes (features, safety, privacy, pricing, numbers). Facts only; unbuilt things marked *načrt*/*planned*.
4. `docs/journey/BUILD_LOG.md` — dated story entry for every notable change (what · why it matters · how to tell it per audience), with real numbers.
5. `HANDOFF.md` + `ROADMAP.md` as before.
Keep the claude.ai Project copies (`petprep/*.md`) in sync after merges. No child personal data in any doc.

## Engineering rules
**Backend:** thin controllers → Service classes; FormRequest for every input; Policies/Sanctum abilities for authz (not ad-hoc `isParent()`); multi-row writes in `DB::transaction`; **no external HTTP inside transactions** — dispatch a queued Job; webhooks fail **closed** when the secret is missing; broadcasts on `PrivateChannel`; all wall-clock rules (quiet hours, midnight, feed windows) evaluated in the family's timezone; store UTC.
**Mobile:** strict TS, no `any`; server state via TanStack Query, UI-only state in Zustand; types from `schema.ts`; every user-visible string through i18n (once M1-18 lands); test on a dev build for native modules (HealthKit, RevenueCat, notifications don't run in Expo Go).
**Both:** the child is a minor — collect the minimum data, no analytics SDK without David's OK, no third-party calls carrying child PII.

## Scope guard (MVP)
Do NOT build: AR, GPS maps, weather API, LLM/vision vet, B2B QR coupons, cats. (Multiple parents/children/pets per family is IN scope since 2026-10-04 — see `docs/decisions/ADR-012-family-model.md`.) Post-MVP ideas go to the backlog in `ROADMAP.md`.

## Orchestration
Delegate by area: `backend-engineer`, `mobile-engineer`, `qa-reviewer` (independent review before merge), `devops` (Hetzner / Docker / CI), `growth-marketer` (copy, funnels, decks — Slovenian/English). Slash commands: `/handoff`, `/verify`, `/feature <ID>`, `/deploy`.

## Secrets & infra
Production: `https://api.petprep.si` — Hetzner CX23, Ubuntu 26.04, `138.199.172.97`, Docker Compose + Caddy, PHP 8.3 (PHP-FPM production image `backend/docker/production`, OPcache; Caddy serves `public/` and pet media directly). Docs: `docs/PRODUCTION_DEPLOYMENT.md`, `docs/PRODUCTION_ENV.md`; open findings in `docs/engineering/DEPLOYMENT.md`; growth / multi-country plan in `docs/engineering/SCALING.md` (update it when infra changes). Run `eas` only from `mobile/`.
Secrets live only in `backend/.env`, `mobile/.env`, `mobile/credentials.json` (local, gitignored) and `/opt/petprep/.env` (server). Don't read or print `.env` contents unless David asks; use `.env.example` for key names. `ACCESS.md` has dev-only credentials.
