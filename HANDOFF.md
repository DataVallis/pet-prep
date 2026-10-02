# PetPrep — Project Handoff & State Directory

> Update after every work session (`/handoff`). Only claim what was verified in that session.
> Previous agent's handoff (2026-09-16, claimed "100 % complete") is archived in `docs/engineering/handoff-archive.md`.

## 1. Executive summary

- **Last updated:** 2026-10-02 afternoon (Claude, orchestrator)
- **Realistic MVP completion:** ~45 % (see `docs/engineering/AUDIT-2026-10-02.md` + addendum)
- **Current milestone:** M0 — repo hygiene → then M1 — core game loop end-to-end
- **Production:** `https://api.petprep.si` is live (Docker Compose + Caddy on Hetzner CX23). Deploy: auto on push to `main` **until PR #2 is merged**; afterwards manual only (Actions → CI & Deploy → Run workflow).

| Milestone | Status |
|---|---|
| M0 Repo hygiene | 4 / 12 (gitignore, monorepo merge, untracked node_modules/.idea) |
| M1 Core loop end-to-end | 0 / 18 (role-based routing partially done on mobile) |
| M2 Parent + auth | 0 / 9 |
| M3 Notifications, sensors, payments | 0 / 11 |
| M4 AI media | 0 / 7 |
| M5 Production + beta | 2 / 8 (server + domain/TLS live; deploy pipeline and backups partial) |

## 2. Decisions (2026-10-02)

- **Business model:** 12-week PetPrep Challenge **49.99 €** with a **7-day free trial**; **Mutt stays free forever**. Proposed free/paid split in `docs/business/BUSINESS_MODEL.md` §7 (awaiting David's confirmation of the split).
- **Child login:** PIN only, no child email (M2-02).
- **Monorepo:** `pet-prep-mobile` merged into `pet-prep/mobile` — done.
- **Languages:** English (default) + Slovenian, more later (M1-18).
- **Package manager:** yarn 1 (root `package.json` declares it) — cleanup in M0-05.

## 3. Known bugs & debt (top items — full list in AUDIT + DEPLOYMENT.md)

1. **Decay math wrong** — hunger/thirst ≈1.8× too fast, hygiene frozen, energy never changes. (M1-01, M1-04)
2. **No child action endpoints**; Feed/Water only +20 % locally; walk/clean local only. (M1-07, M1-14)
3. **Parent dashboard timeline/chart still mock data.** (M2-05)
4. **Hard stop doesn't lock the child**; decay continues during hard stop. (M1-02, M1-16)
5. **Public broadcast channel**; **webhooks fail open**. (M1-08, M3-08, M4-04)
6. **CI tests run on sqlite** → Postgres-only migrations fail; **deploy has no manual approval**. (M0-08)
7. **Quick-login buttons with test passwords** in the app, which defaults to the production API. (M0-10)
8. **EAS signing passwords** were committed in the old `pet-prep-mobile` history (purged from the monorepo, still in the old GitHub repo). (M0-12)
9. PHP 8.3 in prod/CI vs 8.5 in local Sail. (M0-11)
10. No push, HealthKit/Health Connect, RevenueCat SDK, registration/social login. (M2, M3)
11. Everything in UTC — quiet hours off by 1–2 h for Slovenia. (M1-03)

## 4. Environment & configuration

- Local: Laravel Sail; `backend/.env` and `mobile/.env` exist (gitignored). `mobile/credentials.json` local only (gitignored).
- Pending keys: `FAL_AI_API_KEY`, `FAL_AI_WEBHOOK_SECRET` (David adding), RevenueCat, push, Sentry.
- Production docs: `docs/PRODUCTION_DEPLOYMENT.md`, `docs/PRODUCTION_ENV.md`; findings: `docs/engineering/DEPLOYMENT.md`.
- SSH from this Mac to the server: not yet set up — add this Mac's public key from the computer that has access (DEPLOYMENT.md).
- Local backup of the old submodule checkout: `.backup-mobile-submodule-2026-10-02/` (gitignored) — delete once the monorepo is pushed and verified.

## 5. Next steps (priority queue)

1. **David:** `bash setup/install-claude-config.sh && rm -rf setup` (updated agents/commands), then review and `git push` (⚠️ triggers deploy — CI test job will likely fail on sqlite, which blocks the deploy; see M0-08).
2. **David:** archive `DataVallis/pet-prep-mobile` on GitHub (make sure it is private); rotate EAS signing passwords (M0-12); add this Mac's SSH key to the server.
3. **David:** confirm the free/paid split (BUSINESS_MODEL §7) and B7 (non-consumable vs subscription).
4. **M0-08, M0-10** (CI on Postgres + manual approval; hide test logins) — before any further push to `main`.
5. **M1-01 → M1-10** backend core loop (`/feature M1-01`), then **M1-11 → M1-18** mobile.

## 6. Session log

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
