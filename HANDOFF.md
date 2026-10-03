# PetPrep — Project Handoff & State Directory

> Update after every work session (`/handoff`). Only claim what was verified in that session.
> Previous agent's handoff (2026-09-16, claimed "100 % complete") is archived in `docs/engineering/handoff-archive.md`.

## 1. Executive summary

- **Last updated:** 2026-10-02 afternoon (Claude, orchestrator — cloud session; PR #2 CI, PR #1 fal webhooks)
- **Realistic MVP completion:** ~45 % (see `docs/engineering/AUDIT-2026-10-02.md` + addendum)
- **Current milestone:** M0 — repo hygiene → then M1 — core game loop end-to-end
- **Production:** `https://api.petprep.si` is live (Docker Compose + Caddy on Hetzner CX23). Deploy is manual only (Actions → CI & Deploy → Run workflow on `main`). Last deploy 2026-10-03 (PR #1 + #2: signed fal.ai webhooks, CI) — first ever deploy via Actions.

| Milestone | Status |
|---|---|
| M0 Repo hygiene | 7 / 14 (+ yarn only, CI on Postgres + manual deploy, mobile test infra — PR #2) |
| M1 Core loop end-to-end | 2 / 18 (M1-01, M1-02 on branch `fix/M1-01-decay-engine`; role-based routing partially done on mobile) |
| M2 Parent + auth | 0 / 9 |
| M3 Notifications, sensors, payments | 0 / 11 |
| M4 AI media | 2 / 7 (M4-01 queued reference image, M4-04 signed webhooks — PR open) |
| M5 Production + beta | 2 / 8 (server + domain/TLS live; deploy pipeline and backups partial) |

## 2. Decisions (2026-10-02)

- **Business model:** 12-week PetPrep Challenge **49.99 €** with a **7-day free trial**; **Mutt stays free forever**. Proposed free/paid split in `docs/business/BUSINESS_MODEL.md` §7 (awaiting David's confirmation of the split).
- **Child login:** PIN only, no child email (M2-02).
- **Monorepo:** `pet-prep-mobile` merged into `pet-prep/mobile` — done.
- **Languages:** English (default) + Slovenian, more later (M1-18).
- **Package manager:** yarn 1 (root `package.json` declares it) — cleanup in M0-05.

## 3. Known bugs & debt (top items — full list in AUDIT + DEPLOYMENT.md)

1. ~~**Decay math wrong** — hunger/thirst ≈1.8× too fast, hygiene frozen~~ → fixed on `fix/M1-01-decay-engine` (M1-01). Energy still never changes. (M1-04)
2. **No child action endpoints**; Feed/Water only +20 % locally; walk/clean local only. (M1-07, M1-14)
3. **Parent dashboard timeline/chart still mock data.** (M2-05)
4. **Hard stop doesn't lock the child** (M1-16); decay during hard stop/illness fixed on `fix/M1-01-decay-engine` (M1-02).
5. **Public broadcast channel**; RevenueCat webhook fails open (fal.ai fixed in PR #1). (M1-08, M3-08)
6. ~~CI on sqlite / invalid workflow / auto-deploy~~ → fixed in PR #2 (M0-08). Note: the old workflow was **invalid** (secrets in `environment.url`), so no GitHub Actions run ever executed — production was deployed manually.
7. **Quick-login buttons with test passwords** in the app, which defaults to the production API. (M0-10)
8. **EAS signing passwords** were committed in the old `pet-prep-mobile` history (purged from the monorepo, still in the old GitHub repo). (M0-12)
9. PHP 8.3 in prod/CI vs 8.5 in local Sail. (M0-11)
10. No push, HealthKit/Health Connect, RevenueCat SDK, registration/social login. (M2, M3)
11. Everything in UTC — quiet hours off by 1–2 h for Slovenia. (M1-03)

## 4. Environment & configuration

- Local: Laravel Sail; `backend/.env` and `mobile/.env` exist (gitignored). `mobile/credentials.json` local only (gitignored).
- `FAL_AI_API_KEY` set locally (not on server yet). There is **no** fal webhook secret any more (ED25519 signature). Pending: RevenueCat, push, Sentry.
- Production queue worker must run for reference images (`queue` container exists).
- Production docs: `docs/PRODUCTION_DEPLOYMENT.md`, `docs/PRODUCTION_ENV.md`; findings: `docs/engineering/DEPLOYMENT.md`.
- SSH key of David's second Mac added to the server.
- Local backup of the old submodule checkout: `.backup-mobile-submodule-2026-10-02/` (gitignored) — delete once the monorepo is pushed and verified.

## 5. Next steps (priority queue)

1. **David:** rotate EAS signing passwords (M0-12).
2. **David:** confirm the free/paid split (BUSINESS_MODEL §7) and B7 (non-consumable vs subscription).
3. **David:** visually check the child HUD (M0-14).
4. **M0-10** hide test logins in the app.
5. **M1-01 → M1-10** backend core loop (`/feature M1-01`), then **M1-11 → M1-18** mobile.

## 6. Session log

### 2026-10-03 (cloud, backend-engineer) — M1-01 + M1-02: decay engine rewrite (branch `fix/M1-01-decay-engine`, no PR yet)
- New migration `2026_10_03_120000_add_last_decay_at_and_fractional_metrics_to_pets_table`: `pets.last_decay_at` (backfill `now()`), the four metric columns → `double precision` (0–100 CHECKs kept). Chose double over `decimal(5,2)`: 2 decimals would still drop ~0.003 % per minute-tick (mutt hunger 0.1333 → 0.13, ~2.5 % slower).
- `PetDecayService` rewritten: elapsed time only from `last_decay_at` (whole seconds), per-minute split of normal vs quiet time (catch-up across quiet boundaries is exact), frozen while hard-stopped / ill / inactive / game over with the clock advanced, no catch-up after unfreezing (`Pet::updating` hook resets the clock; illness decays only from `illness_until`). Thresholds, `pet_state` and `*_zero_since` use precise values (zero = `<= 0`).
- Broadcast only when a displayed (rounded) metric, state or certificate flag changes; otherwise `updateQuietly()` → max one `PetUpdated` per pet per tick from decay.
- API contract unchanged: `Pet::displayMetric()` (round half up) in `PetUpdated::broadcastWith`, parent dashboard, pairing response; `Pet::attributesToArray()` rounds for `/api/login` + `/api/user` (serialized model); Filament table/form show ints. No OpenAPI regen needed (types still int).
- Simulation (24 h, 1-min ticks): mutt hunger 0 % at 12 h 31 min (12 h 30 exactly after the review fix below), thirst 10 h 00, 6 h → hunger 52 / thirst 40 / hygiene 91; BC hunger 8 h 20, thirst 6 h 40, 6 h → hunger 28 / hygiene 91. Identical with 5-min ticks and with a 3 h scheduler gap.
- Tests: 143 passed (541 assertions) on PostgreSQL 16 (was 123); `PetDecayTest` rewritten (old tests relied on `updated_at`). Pint run on changed files only.
- New debt: (1) escalation still double-broadcasts (Phase 3 / illness / game over: observer + explicit `broadcast`) and decay + escalation can each broadcast in the same minute — M1-08. (2) `*_zero_since` is stamped at tick time, so after a long scheduler gap the illness/game-over clocks start late.

### 2026-10-03 (cloud, backend-engineer) — review fixes on `fix/M1-01-decay-engine`
- **Lost update fixed:** `processAllActivePets()` selects IDs only; each pet is processed in `DB::transaction` from `Pet::lockForUpdate()` (stale models are ignored and re-synced). Broadcast after commit; `PetObserver` now `ShouldHandleEventsAfterCommit`. `EditPet` (Filament) saves under the same lock. Rule documented in `backend/CLAUDE.md`.
- **Hard stop / illness freeze neglect too (decision: spec §5, audit §2.5):** new `pets.frozen_at`; `EscalationService` skips hard-stopped and ill pets; on thaw every `*_zero_since` is shifted forward by the frozen duration (hard stop: in the `updating` hook, also without ticks; illness: from `illness_until` on the next tick). 20 h at zero + 30 h hard stop → game over exactly 4 h after the stop is lifted.
- **Float drift:** values within 1e-6 of an integer are snapped → zero minutes are exact (mutt hunger 750, thirst 600; BC 500 / 400).
- **Filament:** metric fields are saved only if the admin changed the displayed integer; table colours use the displayed value.
- **Catch-up:** quiet/normal split jumps between window boundaries (≤ 4 segments/day) instead of per minute.
- Tests: 154 passed (568 assertions), ~76 s. New: row-lock tests (stale model, write during `processAllActivePets`), neglect-freeze tests (30 h hard stop with and without ticks, illness, no escalation while frozen), exact-zero snap, multi-window catch-up, `FilamentPetResourceTest` (2).
- Note: when `processPetDecay()` is called inside an existing transaction (e.g. tests with `RefreshDatabase`) it locks within that transaction instead of opening a savepoint — nested savepoints made the 24 h simulations quadratic in Laravel's test transaction manager (222 s suite).
- **Open questions for David:**
  1. Warning thresholds use the precise value while the child sees the rounded one (30.4 shows "30 %" but no phase-1 reminder; 10.3 shows "10 %" but no critical alert). Proposal: evaluate thresholds on the displayed value (≤ 30 / ≤ 10 / = 0 as shown). Zero tracking could stay precise.
  2. The interim hygiene decay of 1.5 %/h isn't in the spec (spec: random drop to 0 % 1×/2× per day, M1-05). Keep it until M1-05, or switch it off now?

### 2026-10-03 — M0-10 dev-only demo logins; M0-14 HUD checked
- Quick-login buttons and the `child@test.com` placeholder now render only in development builds (`__DEV__`); Jest test added (74/74). Lucide icon mock made generic.
- Found in prod: test accounts `parent@test.com` / `child@test.com` (password `password`) exist and the test pet had hit game over (decay bug); David revived it via tinker. → M0-15 before public beta.
- Gap found: parent app has no "add child / show PIN" UI (`api.generatePin` unused).

### 2026-10-03 — First deploy through GitHub Actions
- PR #1 and #2 merged. Deploy job needed the `PRODUCTION_SSH_PRIVATE_KEY` repo secret (never set before — the old workflow never ran); David created a dedicated deploy key and the manual run succeeded.
- Verified on the server: all app containers recreated, `fal OK` (FAL_AI_API_KEY loaded), migration `2026_10_02_120000_create_pet_media_jobs_table` ran.

### 2026-10-02 (afternoon, cloud) — M4-04 + M4-01: signed fal.ai webhooks, async reference image
- GitHub access to `DataVallis/pet-prep` granted; work happens in the cloud on branches, PRs for David to merge (merge to `main` = deploy).
- `FalWebhookVerifier` (ED25519 via JWKS `https://rest.fal.ai/.well-known/jwks.json`, 24 h cache, refresh rate-limited to 1/min, ±300 s), verified in `FalAiWebhookRequest::authorize()` before validation; 401 fail-closed.
- New `pet_media_jobs` table: webhooks only for request IDs we created; idempotent; `pets.media_status` (+ backfill); media URLs restricted to `*.fal.media` over HTTPS (rejects `\`, `@`, userinfo, ports).
- `GeneratePetReferenceImage` queued job (afterCommit, 3 tries, timeout 75 s < retry_after 90 s); pairing no longer calls fal.ai. Fixed image call (`fal.run` instead of `queue.fal.run`).
- Removed `FAL_AI_WEBHOOK_SECRET` everywhere. `media_status` added to broadcast, pairing response and mobile types.
- Tests: 113 passed (303 assertions) on PostgreSQL 16 locally; independent AI review → 3 majors fixed before PR.
- Started `docs/journey/BUILD_LOG.md` (content raw material).

### 2026-10-02 (afternoon, cloud) — M0-08 CI on PostgreSQL + M0-13 mobile test infra
- Workflow "CI & Deploy": `backend-tests` (Pest on `postgres:18` service), `mobile-checks` (yarn, tsc, Jest) on every PR/push; `deploy` only on manual dispatch from `main` after both pass.
- Mobile (mobile-engineer agent): pinned `@react-native/jest-preset` 0.86.3 + `@types/jest` 29, TS 6 `types`, css module decl; fixed real type errors (`absoluteFill`, `space-between`, Echo/Pedometer types). Jest 72/72, tsc 0 errors from a clean install. **Needs a visual check of the child HUD** (M0-14).
- Removed `package-lock.json` (root + mobile); yarn 1 only (M0-05).
- Merge order: **PR #2 (CI) first, then PR #1 (fal webhooks)** — PR #1 will then be tested by CI before anything deploys.

### 2026-10-02 (afternoon) — Pull conflict, monorepo merge, decisions
- Resolved the pull: stashed local `.idea` changes (`git stash list` → "local .idea changes before pull 2026-10-02"), moved the untracked `.gitignore` aside, fast-forwarded `main` to `origin/main` (498874f — production deployment setup from the other computer), merged both `.gitignore` files.
- Merged `pet-prep-mobile` (6 commits, history preserved, rewritten under `mobile/`, `credentials.json` purged) into the monorepo; restored local `mobile/.env`, `mobile/credentials.json` (now gitignored).
- Untracked `node_modules/` (2,013 files), `.idea/`, `.DS_Store`, `mobile/.augment_tmp_tsc_cmd.txt`.
- Recorded decisions; updated ROADMAP (new M0-10…12, M3-11), BUSINESS_MODEL, PRODUCT_SPEC, ARCHITECTURE, AUDIT addendum, DEPLOYMENT (now a findings overview pointing at `docs/PRODUCTION_*.md`), agents/commands.
- Verified `https://api.petprep.si/up` responds. Did not run test suites. Nothing pushed.

### 2026-10-02 (morning) — Orchestrator takeover (Claude)
- Reviewed all 13 business/technical docs and the full monorepo.
- Added `CLAUDE.md` (root, backend, mobile), Claude Code agents/commands, `docs/` (product spec, business model, architecture, audit, roadmap, ADR template, source docs as Markdown).
- Verified the decay defect by simulating the exact algorithm (hunger 10 % after 6 h vs spec 52 %). No application code changed.
