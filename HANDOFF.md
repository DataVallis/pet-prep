# PetPrep — Project Handoff & State Directory

> Update after every work session (`/handoff`). Only claim what was verified in that session.
> Older session logs and status lines: `docs/engineering/handoff-archive.md` (incl. the 2026-09-16 "100 % complete" handoff, which was false).

## 1. Executive summary

- **Last updated:** 2026-10-07 night (Claude — PR #70 M3-11b (lock time off the 12-week clock), #71 M3-04/05/06 health steps, #72 M3-12 emergency meal + honest pushes, #73 dock time / album posters merged; #70 and #72 deployed. **Next: M5-F01–F07**, then M5-R05, M5-R06. Payments sandbox test by David on 2026-10-08.)
- **Production:** `https://api.petprep.si` live (Hetzner CX23, Docker Compose + Caddy). Merge to `main` → CI (`CI OK`) → automatic deploy when backend / deployment / scripts / workflow changed (DEPLOYMENT.md D16). Website `petprep.si` is served from the separate repo `DataVallis/pet-prep-website` (D15). Last verified deploy: `98eabfb` (M5-R03 training backend), 2026-10-06.
- **App:** TestFlight build 1.24.4 has the push-registration loop (fixed on `main`, PR #45/#48). **David must ship a new TestFlight build from `main`** (he owns app version — commit `58551b1` "mobile version change" is his). Nothing from M5-R02 / M5-R03 UI and nothing of the **CGP v2 rebrand** has been checked on a device yet. The rebrand adds native modules (`expo-font`, `expo-splash-screen`) and new icons / splash → needs a **new native EAS build** (an OTA update is not enough).
- **PR #59 (M5-R03b) merged and deployed 2026-10-07** (`b3246ef`; GitHub Actions billing was blocking the `CI OK` job on 2026-10-06 — David fixed it). Small fix PR `fix/legacy-pet-age-label` (mobile only).
- **Realistic MVP completion:** ~65 % (payments, i18n and health steps built but not verified on a device; privacy / store review, beta still missing).

| Milestone | Status (ROADMAP checkboxes, 2026-10-06) |
|---|---|
| M0 Repo hygiene | 9 done / 6 open (M0-06, M0-07, M0-09, M0-11, M0-12, M0-15) |
| M1 Core loop | 14 done / 2 partial (M1-16, M1-19) / 3 open (M1-10, M1-11, M1-17); M1-18 i18n built (PR #65, not merged) |
| M2 Parent + auth | 3 done / 3 partial / 4 open (M2-04, M2-07, M2-09, M2-10 social login) |
| M3 Notifications, sensors, payments | push (M3-02) done; HealthKit, Health Connect, RevenueCat, paywall, trial, signature open |
| M4 AI media | 7 done / 4 open (M4-05 object storage, M4-05b, M4-06 fallback, M4-09 tokens) |
| M5 Production + beta | partial: deploy pipeline (fast, D16), backups same-disk only; Sentry, privacy, TestFlight beta, analytics open |
| M5-R Realism | R01, R02, R03, R03b, R04 done (2026-10-07) |

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
6. **i18n (M1-18) not verified on a device:** Hermes plural rules (polyfill `@formatjs/intl-pluralrules` loads only if needed), Android channel names after a switch, iOS Slovenian permission texts — all need the new native build. English copy (contract, push, legal/deletion texts, terms like "pup", "Dog school", "Mixed breed", "Place") awaits David's read-through. Terms / privacy URLs are the same for both languages.
7. **Security / hygiene still open:** test accounts in production DB (M0-15), EAS signing passwords in old repo history (M0-12), RevenueCat payments built but not live (D7a checklist: store products, webhook secret, SDK keys, then `PAYMENTS_ENFORCED=true`), PHP 8.3 prod vs 8.5 Sail (M0-11).
8. **About 40 decisions marked "čaka Davida"** in DECISIONS.md (incl. 7 behaviour questions from M5-R02: chewing 0.5, accidents vs. score, midnight clock, hard stop, quiet hours, walk for all breeds, adopted dogs).

## 4. Environment & configuration

- Local: Laravel Sail; `backend/.env` and `mobile/.env` exist (gitignored). `mobile/credentials.json` local only (gitignored).
- `FAL_AI_API_KEY` set locally (not on server yet). There is **no** fal webhook secret any more (ED25519 signature). Pending: RevenueCat, push, Sentry.
- Production queue worker must run for reference images (`queue` container exists).
- Production docs: `docs/PRODUCTION_DEPLOYMENT.md`, `docs/PRODUCTION_ENV.md`; findings: `docs/engineering/DEPLOYMENT.md`.
- SSH key of David's second Mac added to the server.
- **Cloud sessions (no Docker / Sail):** PostgreSQL 16 runs locally on `127.0.0.1:5432` (postgres / postgres). Run Pest with `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=testing_<branch> DB_USERNAME=postgres DB_PASSWORD=postgres php vendor/bin/pest --parallel`; in a worktree copy `vendor/` with `rsync -a --exclude=.git backend/vendor/ <worktree>/backend/vendor/` and delete it afterwards (disk is limited). The main checkout's `mobile/node_modules` is outdated (no `expo-notifications`) → `yarn install --frozen-lockfile` in the worktree.
- **Composer as root (cloud):** run `COMPOSER_ALLOW_SUPERUSER=1 composer install`, otherwise plugins are skipped, `vendor/pest-plugins.json` is missing and `pest --parallel` fails with "Unknown option". Start Postgres with `service postgresql start` and set the postgres password (`ALTER USER postgres PASSWORD 'postgres'`).
- **GitHub:** private repo on the free plan → no branch protection; the single gate is the `CI OK` check run. Actions logs can't be downloaded through the cloud proxy — read check-run **annotations** (the `plan` job prints its decision as a `::notice title=plan::`).

## 5. Next steps (priority queue)

**Start here (new session, after 2026-10-07 night):**
1. **David (2026-10-08):** payments sandbox test on a new native build from `main` (child card → "Preizkus" → "Kupi" → Apple sandbox → "Plačano"; US sandbox Apple ID shows $44.99 — use a Slovenian sandbox tester for 49,99 €). The same build carries health steps (needs new native modules + HealthKit capability, minSdk 26) — device checklist in `docs/engineering/HEALTH_STEPS.md`; also check emergency meal, dock time lines at 375 pt and the album posters.
2. **M5-F01–F07** (device feedback, ROADMAP M5-F): visible paywall entry, mutt "Free" not "Paid", mutt disabled under the challenge, honest dog-school result, tap-to-expand HUD header, "getting ready" notice hidden behind the meals card, unsigned-contract pet showing alarms (partly covered: care reminders no longer go to unsigned caretakers, PR #72).
3. **M5-R05** play & cuddle — decisions recorded, write a short spec, then build.
4. **M5-R06** species → breed picker (dog + cat) — cat care spec first.
5. **Optional (David asked 19:32):** language switch also in the child app (today: device language → saved choice; switch on the start screen and parent Nadzor → Jezik).
6. Before Play release: Health Connect privacy-policy rationale screen + Play Console health declaration; App Review may ask about unused `UIBackgroundModes: fetch` (added by `expo-task-manager`).

Older queue (still valid where not done):
1. **David:** new TestFlight / Android build from `main` (fixes the push loop; contains M5-R02 + M5-R03 UI and the CGP v2 rebrand). Rebrand checks: home-screen icon (iOS + Android adaptive / themed), splash on fog, fonts render (Instrument Sans body, Bricolage headings, Slovenian č/š/ž), status-bar text dark on light screens and light in the child app, mint "due" care button. Then device checks: one `POST /api/devices` per login in the Caddy log; "Šola" chip at 375 pt; "Pohvali" responsiveness; kill app mid-training and reopen; TalkBack / VoiceOver.
2. **David:** delete stale remote branches `diag/pet-state-1006`, `diag/prod-logs-1006`, `diag/child-flow`, `wip/M5-R04-picker-followup` (agent can't delete remote branches).
3. **David:** review `docs/product/FEATURES.md` (feature catalogue, created 2026-10-07 — keep it updated in every PR, CLAUDE.md rule). Open points from it: the parent "Pasme" tab is a fake purchase placeholder (`BreedPaywallScreen`, local unlock + English alert) — hide before a public build?; no UI to change the family timezone.
4. Parent "Pasme" tab hidden until RevenueCat (2026-10-07, `SHOW_BREED_PAYWALL_TAB`); family-timezone UI later (David). Next development (David 2026-10-07): M3 payments (RevenueCat), then M3-04/05/06 HealthKit / Health Connect.
5. **David:** M5-R03b — only the minimum-one-session rule (≥ 7 trainers) is still open (the rest confirmed 2026-10-07). Then the answer the open "čaka Davida" questions (start with the 7 behaviour ones and training m3/m4), then M1-18 i18n, M3 payments (RevenueCat).

## 6. Session log

### 2026-10-07 night (cloud, orchestrator) — M3-11b, M3-12, health steps, device fixes
- **PR #70 M3-11b (deployed):** payment-lock time does not count toward the 12 weeks (David) — program clock = `born_at` + `payment_lock` periods excluded (`Pet::programBirthAt` etc.); used by `virtualAgeInMonths`, certificate, life stages, `CareScoreService::progress`. Lock starts at `max(born_at, trial_ends_at)`. Runbook D7a: admin-unlock locked pets before turning `PAYMENTS_ENFORCED` off. David accepted the refund risk (full AI videos stay). Both former "open for David" items resolved (PAYMENTS_SPEC P9/P10).
- **PR #72 M3-12 (deployed):** emergency meal only **after a missed meal** (David chose rule A at ~20:15: hunger ≤ 20 % AND last ended child window unfed); pushes re-check `CareScheduleService::feedCheck/waterCheck` (`wait` / `clean_first` / `tidy` / dropped `not_actionable`); API additive (`feeding.feed_mode`, `feeding.emergency_threshold`). Open "čaka Davida": a child window that ended during a freeze counts as missed; phase-2 text "zbolel bo v 30 min" is still not literally true (hunger alone never makes the dog sick).
- **PR #71 M3-04/05/06:** `@kingstinct/react-native-healthkit` 16.0.0 (+ nitro-modules), `react-native-health-connect` 4.1.3, `expo-background-task`, minSdk 26; max(health, sensor) per day, read-only steps, lazy native loading. No backend change. ROADMAP `[~]` until a device test. Debt: Watch steps arriving late catch up at ≤ 200/min (David to confirm), Android has no background read.
- **PR #73 (David's device feedback 19:32):** dock "tomorrow" / time on two lines (time never cut), album video tiles use the reference photo as poster + label pill + play badge.
- Verified: each PR green `CI OK`; independent QA on all four (all blockers / majors fixed). **Nothing verified on a device.** Merge helper `/tmp/claude-0/prflow.sh` (waits for `CI OK` on the PR head, then merges) is session-local.
- `schema.ts` can be regenerated without a running server: `php artisan scramble:export --path=<f>` (pgsql env) → `npx -y openapi-typescript@7 <f> -o mobile/src/api/schema.ts` → prepend the header from `scripts/generate-api-types.mjs`.

### 2026-10-07 evening (cloud, orchestrator) — device feedback from TestFlight 3.0.0
- PR #67 (payments) and #68 (RevenueCat iOS public key in `eas.json`) merged and deployed; RevenueCat webhook "Send test event" verified by David (TEST event in Filament). App Store in-app purchase `petprep_challenge_12w` + RevenueCat project/offering set up by David.
- David's device feedback (screenshots 20:01) → ROADMAP **M5-F01–F07**, **M5-R05** updated with his play/cuddle decisions, new **M5-R06** species → breed picker. Decisions in DECISIONS.md (mutt never "Paid", challenge disables mutt, dog + cat, honest school result, visible paywall entry). **Scope change: cats are in** (CLAUDE.md scope guard, PRODUCT_SPEC §1) — care spec first.
- No code changed in this entry (docs PR `docs/device-feedback-2026-10-07`).

### 2026-10-07 afternoon (cloud, orchestrator) — payments finished on PR #67
- **PR #67 (`feat/M3-09-paywall-v2`)** = M3-08 ledger + M3-11 per-pet challenge / trial / lock (backend agent) + M3-07 SDK + M3-09 paywall (orchestrator). David's P5–P8 (14:20): paid dog deletion keeps the purchase used (+ explicit acknowledgement), full AI media only after a purchase (tokens M4-09 later, also for the free mutt), game over is not unlockable — new dog, one free trial per child (Claude's reading), Second Chance post-MVP.
- **QA (independent) on #67:** blocker = deploy would lock new pets with no way to pay → fixed with `PAYMENTS_ENFORCED` (default off; DEPLOYMENT.md D7a go-live checklist) + Filament "Unlock challenge (admin)". Also fixed: locked pets don't grow / generate AI media; exact product only; restore never assigns a credit by itself; "unlocked" only when it really is; no-trial honesty in picker / paywall; pressable plan badge (pay any time). Docs: DECISIONS (superseded M3-08 rows marked), PARENTS / INVESTORS pricing, PRODUCTION_ENV, BUILD_LOG.
- **Open for David:** (1) certificate after a long payment lock — should locked time count toward the 12 weeks? (QA M2; today the program clock runs through the lock); (2) refund abuse: a purchase queues the full AI video set (~3,5 $) which stays after a refund — accept or delay generation 48 h? (QA m7); (3) sandbox purchases in production during TestFlight (D7a).
- Verified: Jest 1295/1295, tsc clean, Pest (see PR). **Not verified on a device**; needs a native build (RevenueCat SDK, expo-localization).

### 2026-10-07 midday (cloud, orchestrator) — M3 payments started, stopped at the usage limit
- **David's payment decisions (2026-10-07):** one purchase = **one 12-week challenge for one pet** (consumable `petprep_challenge_12w`, 49,99 €); the 7-day trial starts at the pet's **birth**; after the trial without a purchase the **game pauses** (new lock `payment_required`, like hard stop); the free/paid split of BUSINESS_MODEL §7 is confirmed (mutt free forever as a sandbox). Full spec: `docs/product/PAYMENTS_SPEC.md` (on `feat/M3-11-challenge-trial`).
- Branches (all stacked on PR #65, none reviewed, no PRs yet):
  - `feat/M3-07-revenuecat-sdk` (0f98781) — mobile RevenueCat SDK infra, parent-only, done + tested (Jest 1292).
  - `feat/M3-08-revenuecat-ledger` (3783d3d) — backend ledger + fail-closed webhook, done + tested (Pest 1247) **but built as family entitlements** → must be reworked to per-pet credits (spec).
  - `feat/M3-11-challenge-trial` (9635def, **WIP**) — backend rework: enums, plan/trial migration, push copy started. Unfinished, untested.
  - `feat/M3-09-paywall` (d93cea8, **WIP**) — mobile plan picker / billing module started. Unfinished, untested.
- Subagents hit the weekly usage limit (resets 2026-10-11 21:00). Next session: finish M3-11 backend per spec → M3-09 app → QA → PRs. Then M3-04/05/06 HealthKit / Health Connect.
- **David found (device, 12:11):** legacy mutt at hunger 0 % at noon, feed button says "ob 17:00", push says it will get sick in 30 min — the rules make the push ask for an impossible action (missed 06–10 window, −8 %/h, next window 17:00, illness after 6 h at 0 %). Proposal awaiting David: emergency feeding outside a window when hunger is ≤ 20 % (counts as a late/missed routine in the score) and pushes never ask for an action that is currently refused. His screenshot was an old build ("STAROST: 0 MESECEV").
- **David's request:** play and cuddling with the pet (new needs) — not in the roadmap yet; needs a spec (proposed as M5-R05).

### 2026-10-07 (cloud, orchestrator) — M1-18 i18n (English default + Slovenian)
- David's order for today: 1. English, 2. payments (49,99 € challenge, 7-day trial, Apple + Google), 3. real steps (HealthKit / Health Connect).
- **PR #65 (`feat/M1-18-i18n-core`) contains everything:** core (`mobile/src/i18n`, `strings()` live views keep the `*_STRINGS` objects, typed keys, parity test incl. CLDR plurals, EN|SL switch on start + Nadzor, saved per device, `Accept-Language`), areas child / parent / misc (≈ 800 strings, 12 namespaces; Slovenian byte-identical — QA checked all removed literals), backend (middleware `SetRequestLocale` + `Vary`, `config/locales.php`, `lang/{en,sl}/{push,account}.php`, `device_push_tokens.locale` from explicit body field `locale` only — iOS adds its own Accept-Language — backfill + new installs without it = `sl`, export / deletion texts, optional `confirm_word` IZBRIŠI/DELETE), follow-ups (re-register push after a switch with `languagePending`, bounded by the devices gate; `confirm_word` sent), PluralRules polyfill, `schema.ts` regenerated.
- Verified: Jest 1240/1240, tsc clean; Pest 1211 passed (agent run; one unidentified flake once in a parallel run — watch CI). Three independent QA reviews (core, mobile, backend); all blockers / majors fixed.
- **Merge is David's** (the session may not merge to `main`): merging #65 deploys the backend (migration `2026_10_18_120000_add_locale_to_device_push_tokens`, backfills `sl`). Branches `feat/M1-18-i18n-{child,parent,misc,backend}`, `fix/M1-18-i18n-*-review`, `integ/…` are history only — delete after the merge.
- Docs: `docs/engineering/I18N.md` (rules + terminology), ARCHITECTURE, DECISIONS, FEATURES, ROADMAP `[x]`, BUILD_LOG.

### 2026-10-07 (cloud, orchestrator) — M5-R04 part 2: growth album + meals today
- Backend: `GET /api/child/pet/growth`, `GET /api/parent/pets/{pet}/growth` (dedicated endpoints, not in `media` — broadcast weight), signed `GET /api/media/history/{id}` (shared `serve()`, X-Accel), migration `pet_media_history.taken_at`, export `growth`; `profile.today.feed_windows[].fed` (one query). Pest 1127 green.
- App: "Kako je kuža rasel" in the "Moj kuža" album (≥ 2 images, child + parent view-only, query key per pet), "Obroki danes" chips above the dock (✓ from `fed`, "nahrani starš", hidden under a scene card, compact on small screens). Jest 1122 green; fixed slow `ChildHudScreen.behaviour` test (stubbed `Animated.loop`).
- QA: no blockers; fixed midnight-crossing current window, per-pet query key, HUD crowding, DST / midnight `fed` tests, swapped docblocks.
- **Not verified on a device.** Debt: parent dashboard +1 query per pet for `fed`; growth refresh uses device clock (rate-limited); album age uses accusative ("3 mesece") like the HUD.

### 2026-10-07 (cloud, orchestrator) — feature catalogue
- `docs/product/FEATURES.md` (14 areas, status legend ✅ / 📱 / 🛠 / 🗓 / ⏸, free / paid, roadmap IDs, dates, numbers with sources). CLAUDE.md (DoD 7 + Living documentation 5), `/handoff` and `/feature` now require updating it in every PR (David).

### 2026-10-07 (cloud, orchestrator) — David confirms training effects
- David: unsigned child not in the share, mid-day recalculation OK, effects 0.75 / 0.5 confirmed → seeder + data migration `2026_10_16_120000` (verified, audit, admin edits win). Pest 1100 green. Only `chewing_chance_per_day` remains unverified among behaviour/training numbers.

### 2026-10-07 (cloud, orchestrator) — merge + legacy age label
- PR #59 merged and deployed (`Deploy to Hetzner Production` success). Legacy pets (no profile) showed "STAROST: 0 MESECEV" in the child HUD — `virtual_age_months` is the challenge clock, not the dog's age → HUD now shows "TEDEN N OD 12" (`formatChallengeWeek`). Jest 1081 green.

### 2026-10-06 night (cloud, orchestrator) — M5-R03b training decisions
- **Backend:** migration `2026_10_15_110000` (new stage key `training_starting_progress`), data migration `2026_10_15_120000` flips 5 min / +1 / −2 to `verified` ("potrdil David 2026-10-06", audit row, admin edits win, idempotent); seeder clears the rules cache. Starting skills (young / adult / senior: sit 50, potty 70, come 30, place 0; puppy 0) applied once in `PairingService::createPet` for training-enabled pets; shown before birth. Fair share: trainers = active caretakers with a signed (or grandfathered) contract; share = `max(⌊budget / n⌋, session)`, pet-wide budget still caps; 422 `training_child_share_used`; child state `training.children_sharing` / `my_share_seconds` / `my_seconds_left` (additive). Pest 1095 green.
- **App:** remaining sessions from `my_seconds_left`, shared-time hint, kind refusal text, chip dot only when the child can still start. Jest 1081 green, tsc clean.
- **QA (independent):** approved; fixed m2 (cache after seeding + warning), m3 (≥ 7 trainers locked out → minimum one session), n1 (one trainer rule in service and payload).
- **Not verified on a device.** Debt: "Danes še 0 vaj" line still shown above the share-used reason; `TrainingPayload` +3 queries per child state.

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

