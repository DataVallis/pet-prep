# PetPrep — Project Handoff & State Directory

> Update after every work session (`/handoff`). Only claim what was verified in that session. Keep this file ≤ ~300 lines.
> Older session logs, status bullets and the long per-breed / per-feature "Update …" paragraphs: `docs/engineering/handoff-archive.md` (newest block "Archived 2026-10-22"; also the 2026-09-16 "100 % complete" handoff, which was false).
> Per-breed history since 2026-10-15: `docs/breeds/<slug>.md`. Every decision: `docs/product/DECISIONS.md`. Feature status: `docs/product/FEATURES.md`.

## 1. Executive summary

**Status 2026-10-22 (Claude, orchestrator, after the M5-T block):**

- **M5-T "Dodelava po testiranju s testerji" is complete in code** — every row in ROADMAP M5-T is ticked (T01–T14, T02b, T05b, T08b; **T07 cancelled** by David 21. 10.: Kling 3.0 Pro for every video, Nano Banana Pro (+ Edit) for every image). 19 PRs merged 2026-10-21 04:51 → 2026-10-22 04:38 UTC (#311–#332, see §6). **Nothing of M5-T has been verified on a phone, and no real fal call has been made yet for key images, loops, transitions, poses or phase clips** — all tested with Pest / Jest only.
- **Production:** `https://api.petprep.si` (Hetzner CX23, Docker Compose + Caddy). Last production deploy: `eb2f3a60` (#326, M5-T12 QA) on 2026-10-21 18:09 UTC (manual Run workflow). **#327–#332 (M5-T08 / T08b / T11 / T09–T10) and the breed runs merged after it are on `main` only** — they ship with the next daily deploy (D21, `daily-deploy.yml`; GitHub fires the 04:00 UTC cron ~5 h late, ~09:00–10:00 UTC) or a manual deploy by David. Website `petprep.si` = repo `DataVallis/pet-prep-website` (privacy policy §3 Health + §4 adapted mode live since #85 / #86, 21. 10. 2026).
- **App on phones:** last recorded store build **3.1.3** (`docs/research/app-release.json`, commit `02953801`, 2026-10-12, 29 breeds; `main` has 58 register breeds). **M5-T12 changed both native fingerprints** (ios `18c9650c…`, android `9a1b20a5…`) → **no JS OTA reaches the current store builds until the next store release** (checklist in `docs/engineering/DEPLOYMENT.md` "Next store release"). So the M5-T JS changes (take-out clock, message queue, care guide, preparation screen, birthday, brush, picture mode, state / phase media players) reach users only with that store build.
- **AI cost per breed + colour + stage (fal list prices, DECISIONS 2026-10-21):** state set (M5-T08) 5,38 $; full dog incl. school + ball (M5-T09 / T10) **11,17 $**, puppy with behaviour 12,59 $, cat 7,40 $ — once per shared look, 0 $ for later buyers of the same breed + colour. **David decided 22. 10.: production AI daily cap 25 $ / day** — he sets it in `/admin → AI budget` (env default stays 5 $, which would spread one new dog set over ~3 days).
- **Realistic MVP completion: ~70 %** — game rules, parent / child apps, payments, health steps, AI media pipeline and admin are built; device verification of most of M5-R / M5-T, store release, legal review (M5-06), beta (M5-07) and test-account cleanup (M0-15) are missing.

| Milestone | ROADMAP checkboxes (incl. sub-items), 2026-10-22 | Realistic |
|---|---|---|
| M0 Repo hygiene | 9 done / 6 open (M0-06, M0-07, M0-09, M0-11, M0-12, M0-15) | ~60 % |
| M1 Core loop | 18 done / 2 partial (M1-16, M1-19) / 4 open (M1-10, M1-11, M1-17, hard-stop sub-item) | ~85 % |
| M2 Parent + auth | 19 done / 4 partial / 15 open (family model, PIN, social sign-in built; several sub-items open) | ~70 % |
| M3 Notifications, sensors, payments | 7 done / 4 partial (M3-04 … M3-07) / 3 open (M3-01, M3-03, M3-10) | ~70 % (built, little device proof) |
| M4 AI media | 10 done / 4 open (M4-05 object storage, M4-05b box, M4-06 fallback, M4-09 tokens) | ~75 % |
| M5 Production + beta | 2 done / 3 partial (M5-03, M5-04, M5-09) / 4 open (M5-05, M5-06, M5-07, M5-08) | ~45 % |
| M5-R Realism | 72 done / 8 partial / 12 open (M5-R07, R12, R14, R15, R18–R20 need David) | ~80 % |
| M5-F Device fixes | 12 done / 1 open (M5-F10 OTA — `EXPO_TOKEN` + store build) | ~90 % |
| M5-T Refinement | 19 done / 0 open (T07 cancelled; T15 AI budget warnings + T16 bulk media upgrade added 22. 10.) | code 100 %, **device 0 %** |

## 2. Decisions

All decisions with who / why / where: `docs/product/DECISIONS.md` (newest on top; ~73 rows still marked »čaka Davida«). Standing rules worth knowing before any work:

- **Business model:** 12-week PetPrep Challenge **49,99 €**, no free trial since 2026-10-08 (the free mutt / domestic cat is the try-out); one purchase = one pet.
- **Child login:** PIN only, no child e-mail. Profile switching on one phone (»Igraj kot«) since M2-11; up to 3 shared sessions per (parent, child).
- **Languages:** English (default) + Slovenian. Code / docs EN, product docs and talk with David SL.
- **Thresholds follow the displayed value** (PRODUCT_SPEC §6). All wall-clock rules in the family's timezone; times shown as »7:00« everywhere (M5-T02b).
- **Media rule »stanje → medij, nikoli obratno«:** what the child sees must match the state; Kling 3.0 Pro for video, Nano Banana Pro (+ Edit) for images; state clips 5 s, school / game clips 3 s; existing pets never auto-upgrade (v1 / v2 keep their set; purchase or admin »Upgrade to state media« → v3).
- **Product switches live in the superadmin panel** (`/admin → Feature switches`: cats, unique AI media per pet, state media, school & game clips) — not env.
- **Deploys:** merge to `main` = CI only; production by the daily 06:00 run or David's manual Run workflow (agents never dispatch). Claude merges its own PRs after green CI (+ one QA round for risky areas).
- **Technical documentation gate (David 21. 10.):** code and its docs change in the same PR; PR body ends with `Docs updated:`.
- **Breed runs** (unattended) document only in `docs/breeds/<slug>.md`.

## 3. Known bugs & debt

**New / open after M5-T (2026-10-22):**

1. **No M5-T feature verified on a phone; no real fal call** for key images, loops (`end_image_url`), transitions, poses or phase clips. First real purchase on production is the first end-to-end test of the M5-T08–T10 pipeline (cost + quality).
2. **No JS OTA reaches current store builds** (native fingerprint change by M5-T12) until the next store release — checklist in DEPLOYMENT.md: Play **Health apps declaration** add `READ_WHEELCHAIR_PUSHES`; App Store Connect **Health permission text** changed (re-check App Privacy answers).
3. **AI daily cap:** David must set the production daily cap to **25 $** in `/admin → AI budget` (decided 22. 10.). Until then a new dog set (~11,17 $) completes over ~3 days (games / school later; game never locks — failed phase clip → old rules).
4. **Off-site backups (D5)** are built but still need David's one-time setup (PRODUCTION_DEPLOYMENT §6b), although the privacy policy already states that deleted data leaves backups within 35 days. No deletion log yet (a restore brings back families deleted after the snapshot).
5. **Adapted mode (M5-T12):** which Android apps actually write Health Connect `WheelchairPushes` is unknown; turning the mode on mid-day keeps the day's max (pushes count only above steps already synced); a yesterday parent entry never rewrites closed routine rows.
6. **School performing clip** can start up to ~2 s before the cue (it now ends at `obey_at − 100 ms`); if David dislikes it → shorter clip or seek.
7. **Process:** orchestrator subagents often cannot spawn `qa-reviewer` themselves → the orchestrator must run QA. David merged #317 / #323 / #329 before their QA fixes landed (fixed in #318 / #326 / #331) — **recommendation: let Claude merge after the QA round.**
8. **Daily deploy cron fires ~5 h late** (GitHub scheduled runs 09:08 / 09:59 UTC instead of 04:00) — the deploy itself works; users get merges late morning, not at 06:00.
9. M5-T06 preparation timing has not been measured on production yet (admin »AI media« shows Preparation / Media pipeline times).

**Older, still valid:**

10. **Push registration edge case (Android):** a real token rotation arriving as the first event inside the 10 s echo window is taken as the echo → registered only at next app start / login.
11. **No mobile crash reporter:** backend errors go to `/admin/system-health` (PR #281, instead of Sentry); RN render errors only reach the device console; external uptime check missing (M5-05).
12. **Training:** schedule is sent to the app (modified app can fake praise — accepted by David); taps before an app restart are lost on resume; potty "asked to go out" only logged; minors m3 / m4 in DECISIONS.
13. **Tests / tooling:** Pint may still fail repo-wide on untouched files (use `pint --test --dirty`); two Pest runs in one checkout share `storage/framework/testing/disks` (use separate worktrees); cold-cache Jest timeout in `ChildHudScreen.behaviour.test.tsx`; `actions/checkout@v4` on deprecated Node 20.
14. **i18n (M1-18) not verified on a device** (Hermes plurals, Android channel names after a switch, iOS SL permission texts); English copy awaits David's read-through.
15. **Security / hygiene before beta:** test accounts in the production DB (M0-15), EAS signing passwords in old repo history (M0-12), PHP 8.3 prod vs 8.5 Sail (M0-11), move `PRODUCTION_SSH_PRIVATE_KEY` into the `production` environment (DEPLOYMENT D20).
16. **Android payments / sign-in (David):** RevenueCat Play service-account JSON, Android product in the current offering's package, Android OAuth client with the Play signing SHA-1.
17. **OTA pipeline:** `docs/research/app-release.json` still records only store build 3.1.3 — no OTA has been recorded; check `EXPO_TOKEN` and that the next store build gets recorded (`scripts/record-app-release.mjs`), otherwise the website register keeps showing newer breeds as »kmalu«.
18. **Breeds are hard-coded in the app** — every new breed needs an app release to be pickable (candidate: server-driven breed catalogue).
19. **Shared looks (M5-R21):** old look files stay after regenerate; regenerate does not fix earlier stages; `auto-` keys not migrated when a colour list is added later.
20. **Media storage:** pet media on the server disk (`app_storage` volume); object storage + CDN (M4-05) before many families (SCALING.md).

## 4. Environment & configuration

- **Production docs:** `docs/PRODUCTION_DEPLOYMENT.md`, `docs/PRODUCTION_ENV.md`; findings / store-release checklist: `docs/engineering/DEPLOYMENT.md`; scaling: `docs/engineering/SCALING.md`. Production Laravel logs live in the `app_storage` volume (`docker exec backend-app-1 tail storage/logs/laravel.log`), not `/opt/petprep/repo/backend/storage/logs`.
- **Local (David's Mac):** Laravel Sail; `backend/.env`, `mobile/.env`, `mobile/credentials.json` local only (gitignored). Before every `eas build`: `cd mobile && yarn install`.
- **GitHub:** private repo on the free plan → no branch protection; the single gate is the `CI OK` check run (`gh api "repos/DataVallis/pet-prep/commits/<sha>/check-runs?per_page=100"`). Actions logs can't be downloaded through the cloud proxy — read check-run **annotations** (the `plan` job prints `::notice title=plan::`).

**Cloud sessions (no Docker / Sail) — tips:**

- **Postgres 16 locally, no Docker:** `service postgresql start`, `ALTER USER postgres PASSWORD 'postgres'`; run Pest with `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=testing_<branch> DB_USERNAME=postgres DB_PASSWORD=postgres php vendor/bin/pest <files>` (own DB per worktree; only the touched tests — the full suite runs in CI).
- **Composer as root:** `COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=<scratch>/composer COMPOSER_CACHE_DIR=<scratch>/composer-cache composer install --ignore-platform-reqs` (PHP version differs from the lock), otherwise plugins are skipped, `vendor/pest-plugins.json` is missing and `pest --parallel` fails with "Unknown option". Faster: `rsync -a --exclude=.git backend/vendor/ <worktree>/backend/vendor/` from a checkout that already has it.
- **Disk is limited (~5 GB per worktree with vendor + node_modules):** delete the `.git` dirs inside `vendor/` (`find backend/vendor -name .git -type d -prune -exec rm -rf {} +`), remove worktrees when done (`git worktree remove`).
- **Yarn:** `yarn install --frozen-lockfile --cache-folder <scratch>/yarn-cache` in the worktree (the main checkout's `mobile/node_modules` may be outdated).
- **`schema.ts` without a running server:** `cd backend && php artisan scramble:export --path=<scratch>/api.json`, then `npx openapi-typescript@7 <scratch>/api.json -o mobile/src/api/schema.ts` and put back the header comment that `scripts/generate-api-types.mjs` writes (`PetPrep API Schema — Auto-generated …`).
- **GitHub through the proxy:** GraphQL is blocked → use REST (`gh api repos/DataVallis/pet-prep/pulls -f title=… -f head=… -f base=main -F body=@pr.md`, merges via `gh api -X PUT …/pulls/<n>/merge`); the proxy refuses branch deletion (David deletes stale branches).
- `FAL_AI_API_KEY` is set in production (the media pipeline runs there); no fal webhook secret (ED25519 signature). Never call fal from tests (`phpunit.xml` `AI_STATE_MEDIA_ENABLED=false`; tests opt in with `useStateMedia()`).

## 5. Next steps (priority queue)

1. **Device test of all M5-T features on a new native build** (David + Claude checklist from FEATURES rows M5-T01 … T14): take-out clock + message queue + ×, sleep rules + »7:00«, care guide (child + parent), games wait for media, preparation »Pripravljamo: …« + »Vse je pripravljeno«, brush scratch card + hold, birthday, adapted mode (iPhone PushCount / Android WheelchairPushes, parent walk entry), state media loops + transitions, picture mode, school + ball / wand phase clips. On confirmation → FEATURES status 📱 with date + build.
2. **Next store release (iOS + Android)** per the DEPLOYMENT.md "Next store release" checklist (Play health declaration `READ_WHEELCHAIR_PUSHES`, App Store Health text / App Privacy), then record it in `docs/research/app-release.json` and confirm OTA works again (M5-F10: `EXPO_TOKEN`).
3. **First real purchase on production** (after the deploy of #327–#332 and the 25 $ cap): watch `/admin → AI budget` ledger, »AI media« preparation time and media quality (key image consistency, seamless loops, transitions, poses, 3 s phase clips, school cue timing); adjust prompts / clip timing with David.
4. **Before the beta (ROADMAP):** M0-15 test accounts out of production, M5-06 legal review + App Store Kids / Play Families check (privacy policy text is live), M5-07 TestFlight + Play internal testing with 20–50 families, D5 off-site backups setup (David), M5-05 mobile error reporting. Open »čaka Davida« items: M5-R14 / R15 / R18 / R19 / R20 breed care needs, M5-R12 fetch spec.

## 6. Session log

### 2026-10-23 (cloud, mobile) — child HUD top bar decluttered (`fix/child-hud-topbar-declutter`)
David's iPhone screenshot: name / stage / breed truncated. Album, »?« (care guide) and the exit moved from the bar into `ChildSettingsSheet` rows (Album → Kako skrbim → Odjava at the bottom; same testIDs, `useChildExit` unchanged). JS only → OTA. Debt: not on a device — check the Modal hand-off (settings → care guide / parental gate) on iOS + Android.

### 2026-10-23 (cloud, mobile) — M5-T17 »Lažje kretnje« app (`feat/M5-T17-easy-gestures-app`, backend #354)
Parent switch per child (`EasyGesturesRow`, `settings.easy_gestures`); hold mode = switch OR live screen reader (`modules/gestures/easyGestures.ts`). Hold for brushing (now gated), wand (»Pomigaj s palico«), litter change (BrushArea scrub), ball (charge + release) — same server inputs. Debt: not on a device; ARCHITECTURE.md still names the old »Umakni pero« button (not edited per instruction).

### 2026-10-22 (cloud, mobile) — legal links follow the app language (`fix/legal-links-app-language`)
Google Play rejected 1.0 (privacy policy missing a Health Data disclosure). Website PR #90 adds an explicit "Health and fitness data" section; this PR makes the in-app terms/privacy links open the page in the app's language (`termsUrl()` / `privacyUrl()` in `modules/auth/signup.ts`, was always Slovenian `petprep.si/zasebnost`). JS only → OTA. Next: David re-submits in Play Console (Data safety: Health & fitness → Fitness info; health declaration incl. READ_WHEELCHAIR_PUSHES).

### 2026-10-22 (cloud, backend) — M5-T16 bulk upgrade to the new media (`feat/M5-T16-bulk-state-media-upgrade`)
- David 22. 10.: existing paid pets get no v3 media automatically — calculation first, then a click. Built `/admin → AI media → Upgrade to new media` (`StateMediaUpgradePlanner` preview per shared look + stage, `lookMissingItems()` prices each look item once, unique-media pets per pet, budget days at the daily cap; »Upgrade« with confirmed total → `UpgradePetsToStateMedia` chunks of 25 → `generateMissing(upgradeStateMedia: true)`; audit `app_setting_changes` `state_media_bulk_upgrade`); `media:backfill --state-media --dry-run` prints the same preview. Pest `StateMediaBulkUpgradeTest` (0 fal calls in preview; 3 pets / 1 look → 20 calls; complete look 0 $; switches off; non-eligible untouched). **Not run on production; David decides when to upgrade** (after the first real purchase confirms media quality).
- QA round (PR #344): confirmation freezes + signs the shown pet ids / total, »Upgrade« recomputes and refuses »Numbers changed, check again« (M1; `only_complete` passed into chunks); stage-behind pets priced with the full new-stage set (M2); `media:backfill --state-media` real run = the planner (M3, `--limit` ignored); `fal_balance` block, chunk logs + 3 tries, per-pet catch with version rollback, cache lock, eager-loaded media, days estimate with monthly cap + nightly retry limit; David 22. 10.: a bulk upgrade may use the whole daily budget (confirmation shows today's %). **Run upgrades outside children's peak hours:** while an upgraded pet's ball-game clips are generated, the game shows »se še pripravlja« (M5-T04 readiness).

### 2026-10-22 (cloud, backend) — M5-T15 AI budget warnings (`feat/M5-T15-ai-budget-warning`)
- David 22. 10.: budgets stay capped, warn at ≥ 80 % (20 % left). Built: `AiBudgetMonitor` / `AiBudgetStatus` (80 % yellow, 100 % red, percentages of the configured cap; production + AI Lab, day + month), banner `AiBudgetBanner` on the dashboard + System health, queued `CheckAiBudgetAlerts` (`ai:budget-check` every 5 min) → Filament database notification to superadmins (+ e-mail only with a real `MAIL_MAILER` — production is `log`, so bell only), dedupe `ai_budget_alerts`; fal.ai balance via Platform API (`GET api.fal.ai/v1/account/billing?expand=credits`, needs scope `billing:usage:read`) with a low-balance warning (`/admin → AI budget`, default 20 $) + `fal_balance` refusals in 24 h; AI budget help text now ≈ 11,17 $ / 12,59 $ / 7,40 $ per NEW breed + colour + stage. Pest `AiBudgetWarningTest`.
- **David:** the balance shows *unknown* until a fal key with `billing:usage:read` is set as `FAL_AI_BILLING_KEY` in `/opt/petprep/.env` (unless the current key already has that scope); for e-mails a real mailer (`MAIL_MAILER` + `MAIL_*`) is needed. Not verified on production.

### 2026-10-21 → 2026-10-22 (cloud, orchestrator + backend / mobile engineers + qa-reviewer) — M5-T block
- **Merged (pet-prep, all by David after green `CI OK`):** #311 + #312 M5-T01 / T03 (take-out deadline clock mint → orange → red ≤ 30 min, ✓ confirmation, message queue 2,5 s + 60 ms/char, visible ×); #313 + #314 M5-T02 / T02b (one action-rules table; sleep disables play / school / cuddle / grooming, `sleep {asleep, until}`, 422 `training_pet_sleeping` / `play_pet_sleeping`, »7:00« clock everywhere); #315 M5-T05 care guide »Kako skrbim za …« from simulation numbers; #316 M5-T04 games wait for all their clips (`play.media_ready`, 422 `media_not_ready`); #317 + #318 M5-T06 preparation screen (QA: buying an already-playing pet no longer starts a preparation; »Vse je pripravljeno: [ime]« to children + parents); #319 M5-T14 brush scratch card + hold; #320 M5-T05b parent care guide; #322 + #324 M5-T13 first birthday (push to children + parents, held over quiet hours); #323 + #326 M5-T12 adapted mode (wheelchair pushes 1 : 1, neutral walk-reminder push, consent copy = GDPR Art. 9 legal basis, parent logs walk minutes for TODAY only ≤ 240 / child / day, device-steps sync gate `steps.my_device_steps_today`); #327 + #329 + #331 M5-T08 / T08b state media (key images, seamless loops via Kling `end_image_url`, idle ↔ sleeping transitions; admin switch »Mediji po stanjih« read fresh by workers; resume on re-enable; 5,38 $ per breed + colour + stage); #330 M5-T11 picture / video mode per child; #332 M5-T09 / T10 school + game phase clips (3 s, switch »Posnetki šole in iger«, `state_media_version` 3, 11,17 $ dog / 7,40 $ cat, Reverb `max_request_size` 65 536, largest `PetUpdated` 8 768 B).
- **Website (pet-prep-website):** #85 privacy policy §3 Health + §4 adapted mode (21. 10. 2026), #86 steps-source wording (Apple Health / Health Connect).
- **Decisions:** all in DECISIONS.md 2026-10-21 (models Kling 3.0 Pro + Nano Banana Pro, T07 cancelled, 3 s school / game clips, 30 min red take-out clock, orange threshold, 2,5 s + 60 ms/char, no points on confirmation, × close, read-aloud later, birthday push held over quiet hours, school window keeps the sleep rule, »7:00«, free set without sleeping key image, state media ON immediately, phases approved, ball airtime 6 s, v2 pets no auto-upgrade, picture mode uses poses in school / games, parent walks today only) + **2026-10-22: production AI daily cap 25 $ / day** (David sets it in the admin).
- **Verified:** Pest / Jest of touched areas + CI only. **Not verified on a phone; no real fal call.** Production has everything up to #326; #327–#332 wait for the next deploy.
- **This handoff (`docs/handoff-2026-10-22`):** HANDOFF cut from 1 099 to ≤ 300 lines (old §1–§6 verbatim → `handoff-archive.md` "Archived 2026-10-22"), BUILD_LOG summary entry, DECISIONS row 25 $ cap, FEATURES health row gets M5-T12, ROADMAP M5-06 notes the live privacy text.
- **Next:** §5 items 1–3 (device test → store release → first real purchase).

### 2026-10-16 → 2026-10-20 (summary — details in the archive, "Archived 2026-10-22" §1)
- Child no longer signed out every ~2 days (Keychain `AFTER_FIRST_UNLOCK`, 10-17); Android release fixes + first Android EAS build, Health Connect privacy rationale (10-19); System health page `/admin/system-health` and cat routine ledger hotfix (10-19); launch splash can no longer hang + HTTP/3 UDP 443 (10-19); MP4 faststart for fal videos, AI Lab clip comparison, emergency meal covers next window, local media cache (10-20); »Igraj kot« shared sessions fix (PR #321, 10-21). Breed runs M5-R10-41 … 56 (16 breeds) in `docs/breeds/`.

### 2026-10-15 (cloud, devops) — D21 daily production deploy (`chore/daily-production-deploy`)
- New `.github/workflows/daily-deploy.yml` + `scripts/daily-deploy.sh` (harness `scripts/tests/daily-deploy.test.sh`): 06:00 Europe/Ljubljana, dispatches CI & Deploy → Run workflow (target production) only when `main` is newer than production and its push CI is green. Manual deploys unchanged; agents still never dispatch. Next: watch the first 06:00 run (and that the Mobile OTA workflow_run still follows a bot-dispatched run).

### 2026-10-15 — breed runs in their own files (`chore/breed-runs-own-files`)
- Breed runs (night only) now write only their code/data/tests, `docs/breeds/<en-slug>.md`, the queue row and generated files; shared tests read breed lists from code; registry i18n hashes cover only used strings; runbook rewritten. Debt: per-breed blocks still appended in shared mobile tests and `BreedSuitabilityTest` (runbook says: own file from now on); no per-breed docs written for the existing 42 breeds (history stays in the shared docs).
