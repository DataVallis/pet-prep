# PetPrep — Project Handoff & State Directory

> Update after every work session (`/handoff`). Only claim what was verified in that session.
> Older session logs and status lines: `docs/engineering/handoff-archive.md` (incl. the 2026-09-16 "100 % complete" handoff, which was false).

## 1. Executive summary

- **Last updated:** 2026-10-06 late evening (Claude, orchestrator — mobile app rebranded to CGP v2: theme tokens, brand fonts, new app icons / splash, logos).
- **Production:** `https://api.petprep.si` live (Hetzner CX23, Docker Compose + Caddy). Merge to `main` → CI (`CI OK`) → automatic deploy when backend / deployment / scripts / workflow changed (DEPLOYMENT.md D16). Website `petprep.si` is served from the separate repo `DataVallis/pet-prep-website` (D15). Last verified deploy: `98eabfb` (M5-R03 training backend), 2026-10-06.
- **App:** TestFlight build 1.24.4 has the push-registration loop (fixed on `main`, PR #45/#48). **David must ship a new TestFlight build from `main`** (he owns app version — commit `58551b1` "mobile version change" is his). Nothing from M5-R02 / M5-R03 UI and nothing of the **CGP v2 rebrand** has been checked on a device yet. The rebrand adds native modules (`expo-font`, `expo-splash-screen`) and new icons / splash → needs a **new native EAS build** (an OTA update is not enough).
- **All PRs #1–#56 are merged; no open PRs.** `main` @ `58551b1`.
- **Realistic MVP completion:** ~55 % (payments, HealthKit / Health Connect, i18n, privacy / store review, beta still missing).

| Milestone | Status (ROADMAP checkboxes, 2026-10-06) |
|---|---|
| M0 Repo hygiene | 9 done / 6 open (M0-06, M0-07, M0-09, M0-11, M0-12, M0-15) |
| M1 Core loop | 14 done / 2 partial (M1-16, M1-19) / 4 open (M1-10, M1-11, M1-17, M1-18 i18n) |
| M2 Parent + auth | 3 done / 3 partial / 4 open (M2-04, M2-07, M2-09, M2-10 social login) |
| M3 Notifications, sensors, payments | push (M3-02) done; HealthKit, Health Connect, RevenueCat, paywall, trial, signature open |
| M4 AI media | 7 done / 4 open (M4-05 object storage, M4-05b, M4-06 fallback, M4-09 tokens) |
| M5 Production + beta | partial: deploy pipeline (fast, D16), backups same-disk only; Sentry, privacy, TestFlight beta, analytics open |
| M5-R Realism | R01, R02, R03 done; **R03b** (David's training decisions) open; R04 part 1 done, **part 2 (growth album)** open |

## 2. Decisions (2026-10-02)

- **Business model:** 12-week PetPrep Challenge **49.99 €** with a **7-day free trial**; **Mutt stays free forever**. Proposed free/paid split in `docs/business/BUSINESS_MODEL.md` §7 (awaiting David's confirmation of the split).
- **Child login:** PIN only, no child email (M2-02) — backend + app built 2026-10-04 (`feat/M2-02-pin-only-child`).
- **Monorepo:** `pet-prep-mobile` merged into `pet-prep/mobile` — done.
- **Languages:** English (default) + Slovenian, more later (M1-18).
- **Package manager:** yarn 1 (root `package.json` declares it) — cleanup in M0-05.
- **Thresholds follow the displayed value** (David, 2026-10-03): warning/escalation thresholds, pet state and zero tracking (`*_zero_since` → phase 3, illness, game over) compare the rounded half-up value the child sees (`Pet::displayMetric`): 30.4 shows 30 → phase 1; 0.4 shows 0 → counts as zero. PRODUCT_SPEC §6.
- **Interim hygiene 1.5 %/h kept until M1-05** (David, 2026-10-03) — removed on `feat/M1-04-energy-hygiene-breeds` (random hygiene events replace it).

## 3. Known bugs & debt (top items — full list in AUDIT, DEPLOYMENT.md and the archive)

1. **TestFlight 1.24.4 still loops `POST /api/devices`** (iOS echoes the push token) — fixed on `main` (mobile) + own `throttle:devices` on the server, so the game is no longer blocked; the installed build still drains battery until a new build ships.
2. **Push registration edge case:** on Android, a real token rotation arriving as the first event inside the 10 s window is taken as the echo → registered only at next app start / login.
3. **No crash reporter:** render errors only reach the device console (`logRenderError`); Sentry needs David's OK (M5-05).
4. **Training:** schedule is sent to the app, so a modified app can fake praise taps — **accepted by David** (only system security matters); taps before an app restart are lost on resume; potty "asked to go out" only logged, no timeline row; minors m3/m4 (catch-up accidents use today's potty progress; decay applied after later gains) open in DECISIONS.
5. **Tests / tooling:** Pint fails repo-wide on 9 untouched files; two Pest runs in one checkout share `storage/framework/testing/disks` (use separate worktrees); cold-cache Jest timeout in `ChildHudScreen.behaviour.test.tsx`; GitHub Actions: `actions/checkout@v4` on deprecated Node 20, `ubuntu-latest` moves to Ubuntu 26 on 2026-10-19 — check CI after that date.
6. **Strings inline** in the app until i18n (M1-18).
7. **Security / hygiene still open:** test accounts in production DB (M0-15), EAS signing passwords in old repo history (M0-12), RevenueCat webhook fails open (M3-08), PHP 8.3 prod vs 8.5 Sail (M0-11).
8. **About 40 decisions marked "čaka Davida"** in DECISIONS.md (incl. 7 behaviour questions from M5-R02: chewing 0.5, accidents vs. score, midnight clock, hard stop, quiet hours, walk for all breeds, adopted dogs).

## 4. Environment & configuration

- Local: Laravel Sail; `backend/.env` and `mobile/.env` exist (gitignored). `mobile/credentials.json` local only (gitignored).
- `FAL_AI_API_KEY` set locally (not on server yet). There is **no** fal webhook secret any more (ED25519 signature). Pending: RevenueCat, push, Sentry.
- Production queue worker must run for reference images (`queue` container exists).
- Production docs: `docs/PRODUCTION_DEPLOYMENT.md`, `docs/PRODUCTION_ENV.md`; findings: `docs/engineering/DEPLOYMENT.md`.
- SSH key of David's second Mac added to the server.
- **Cloud sessions (no Docker / Sail):** PostgreSQL 16 runs locally on `127.0.0.1:5432` (postgres / postgres). Run Pest with `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=testing_<branch> DB_USERNAME=postgres DB_PASSWORD=postgres php vendor/bin/pest --parallel`; in a worktree copy `vendor/` with `rsync -a --exclude=.git backend/vendor/ <worktree>/backend/vendor/` and delete it afterwards (disk is limited). The main checkout's `mobile/node_modules` is outdated (no `expo-notifications`) → `yarn install --frozen-lockfile` in the worktree.
- **GitHub:** private repo on the free plan → no branch protection; the single gate is the `CI OK` check run. Actions logs can't be downloaded through the cloud proxy — read check-run **annotations** (the `plan` job prints its decision as a `::notice title=plan::`).

## 5. Next steps (priority queue)

1. **David:** new TestFlight / Android build from `main` (fixes the push loop; contains M5-R02 + M5-R03 UI and the CGP v2 rebrand). Rebrand checks: home-screen icon (iOS + Android adaptive / themed), splash on fog, fonts render (Instrument Sans body, Bricolage headings, Slovenian č/š/ž), status-bar text dark on light screens and light in the child app, mint "due" care button. Then device checks: one `POST /api/devices` per login in the Caddy log; "Šola" chip at 375 pt; "Pohvali" responsiveness; kill app mid-training and reopen; TalkBack / VoiceOver.
2. **David:** delete stale remote branches `diag/pet-state-1006`, `diag/prod-logs-1006`, `diag/child-flow`, `wip/M5-R04-picker-followup` (agent can't delete remote branches).
3. **M5-R03b** (backend + app): flip the confirmed training numbers to `verified = true`; adult-arrival start progress (sit 50, potty 70, come 30, place 0; puppies 0); fair share of the 5 min daily budget between the pet's children. Tests + docs.
4. **M5-R04 part 2:** growth album (pet images across life stages).
5. **David:** answer the open "čaka Davida" questions (start with the 7 behaviour ones and training m3/m4), then M1-18 i18n, M3 payments (RevenueCat).

## 6. Session log

### 2026-10-06 late (cloud, orchestrator) — CGP v2 mobile rebrand
- **Theme:** `mobile/src/theme/` (palette + light/dark/meter tokens from `brand/README.md`, radii, fonts); every screen/component recoloured from the old Tailwind palette (codemod + manual pass): parent, auth, start, splash and the breed paywall are light (fog + white cards, graphite primary, mint-text links); child PIN, contract, HUD, overlays dark graphite (mint primary with graphite text). Glow orbs removed / calmed. Guard `src/__tests__/brandColours.test.ts` (no hex outside `src/theme`, `Text` only from `@/components/ui/Text`).
- **Type:** `@expo-google-fonts/bricolage-grotesque` + `instrument-sans` (6 faces via per-weight subpaths), `expo-font`; brand `Text`/`TextInput` maps `fontWeight` → Instrument Sans face; headings Bricolage with tight tracking (codemod on fontSize ≥ 20). Native splash held until fonts load.
- **Icons / splash / logos:** `mobile/assets/*` replaced with `brand/app-icon/*` (iOS icon flattened to RGB — App Store rejects alpha), adaptive bg `#7FE0B4`, notification colour `#1A7A55`, `expo-splash-screen` plugin (face on `#F3F5F2`). `BrandMark` / `BrandLogo` (react-native-svg, generated wordmark glyphs) on start (logo + slogan), splash (stacked), parent login / signup, child PIN (mark on dark), parent dashboard header.
- **HUD:** `ActionButton due` = solid mint (feed while `can_feed`, scrub when needed, "Pelji ven" when due).
- **Status bar:** default dark text; child branch + PIN login use `useDarkStatusBar()` (several `<StatusBar>` elements mounting/unmounting in one commit looped forever under Jest fake timers).
- **Verified:** `tsc` clean, Jest 1064/1064, `expo config` resolves; visual check via a temporary web export (Playwright screenshots of start, splash, login, signup, PIN, dashboard, child detail, controls, add child, paywall, HUD, contract, lock) — temporary web deps and gallery were removed again. **Not verified on a device.**
- **QA review (independent):** approved; fixed font-failure fallback (brand family names only once registered + 4 s splash timeout), nested Text inheriting the parent face, due-button pressed state, one due button at a time (take out > scrub > feed), status bar on the legacy no-pet path, disabled auth button contrast.
- **Debt:** HUD keeps the emoji dog placeholder and the warm "mud" rgba colours in `CleaningOverlay`; `app.json` `userInterfaceStyle` stays `light` (no system dark mode yet); i18n still pending (M1-18).

### 2026-10-06 (cloud, orchestrator) — incident, CI speed-up, M5-R03, handoff
- **Incident (TestFlight 1.24.4):** 429 on `GET /api/child/pet` + crash → cause: iOS push-token echo loop (`POST /api/devices` up to ~1 650/min) draining the shared `api` limiter. Fixed: PR #45 (mobile dedupe / limiter / backoff, child refetch governor, 429 handling with Retry-After, HUD error boundary, lock time in family TZ; backend `throttle:devices` 10/min/user, unregister stays on `api`), PR #48 follow-ups (rotation during in-flight run, generation counter, `logRenderError`, LockedScreen boundary). App identity rule in CLAUDE.md (PR #46).
- **Flaky CI:** PR #47 — fixed RNG salt in `TestCase` (prod still salts with APP_KEY), FamilyModelTest disables hygiene after birth, stable Filament sort (`defaultKeySort`), devices limiter test.
- **CI pipeline (David: "ne čakamo 2× na isti pipeline"):** PR #50 + #51 — `plan` job with path filters, PR-green tree markers let `main` skip identical suites, deploy only for backend / deployment / scripts / workflow, `CI OK` single gate, deploy guard (only current `main` head), Pest `--parallel`. Verified on GitHub: docs PR ≈ 50 s end to end; backend merge on `main` deployed in ≈ 2 min without re-testing. Root cause of the first skipped deploy: implicit `success()` across skipped upstream jobs.
- **M5-R03 training:** PR #53 (backend, deployed) + PR #54 (app, not on a device yet) — see DECISIONS / ARCHITECTURE §3 / PRODUCT_SPEC; QA reviewed both (changes requested → fixed → approved).
- **David's decisions (evening):** training numbers confirmed; fake taps accepted; adult dogs start with some skills; fair share between children; long-session rule (→ CLAUDE.md, DECISIONS). Code for these = M5-R03b.
- **Merge helper:** `/tmp/claude-0/prflow.sh` (session-local, gone with the container) updated the PR branch with `main`, waited for `CI OK`, merged; a new session should recreate a similar helper rather than rely on it.

