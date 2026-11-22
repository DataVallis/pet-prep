# PetPrep — Project Handoff & State Directory

> **How to continue (»nadaljuj«):** a new session reads **§0** (this box + the read-first list in `CLAUDE.md`), then **§5 Next steps**, then the **newest §6 entry**, then continues with the top open item of §5 (or what David asks). §1 says where we are, §1b what only David can do, §3 what is known to be broken.
> **claude.ai Project copy:** `petprep/HANDOFF.md` — a session without the repo starts there; re-sync it after every merge that changes this file (`.claude/commands/handoff.md` step 6, David 2026-11-21).
> **Rules for this file:** update after every work session (`/handoff`); only claim what was verified (code, merged PRs, Actions runs — not older docs); keep it ≤ ~300 lines.
> **Older material:** `docs/engineering/handoff-archive.md` — newest block »Archived 2026-11-21« = this file verbatim before the refresh (full §1b steps, every session-log entry since 2026-10-15, old debt numbering). Per-breed history: `docs/breeds/<slug>.md`. Decisions: `docs/product/DECISIONS.md`. Feature status: `docs/product/FEATURES.md`.

## 0. Read-first order

`CLAUDE.md` → this file (§1, §5, newest §6) → `docs/engineering/ROADMAP.md` (task IDs) → `docs/product/PRODUCT_SPEC.md` (game rules) → `docs/engineering/ARCHITECTURE.md` → `docs/business/VALIDATION_PLAN.md` (what is frozen until the validation ends).

## 1. Executive summary

**Status 2026-11-21 (Claude, `docs/handoff-refresh-2026-11-21`)** — re-checked against `main` (`f908bbe0`), merged PRs and GitHub Actions runs of both repos.

- **Production API `https://api.petprep.si`** (Hetzner CX23, Docker Compose + Caddy). **Last production deploy: daily run 44891708674, 2026-11-21 09:53–09:57 UTC, `ba44f11d` (#1473)** — all jobs green, tests reused (identical tree). Before it: **David's manual run 44748255299, 2026-11-20 13:53 UTC, `d815b3d4` = bowls fix #1461** (requested by David, dispatched by David); daily 44716215115 (11-20 10:05, `4cd90c6c`); David's manual 44552564528 (11-19 15:14, `367c126e`, item realism #1417). David's manual run 44300325939 (11-18 12:03) failed on Pest shard 5/6 (temp-file sweep flake, fixed by #1371 the same hour). Since `ba44f11d`, `main` has docs only (#1474–#1477) → nothing undeployed but docs. Daily cron (03:17 Ljubljana) still starts ~6–7 h late (runs 08:15 / 09:53 UTC on 11-21).
- **OTA:** `mobile-ota.yml` runs after every production deploy. `docs/research/app-release.json` = **OTA 4.1.6 @ `ba44f11d`, iOS + Android, 2026-11-21** (130 breeds in app). On 2026-11-20 Android was refused once (»New production build needed: android — native code changed«, run 44716722250; that day's record is iOS only); the 2026-11-21 OTA reached both, so a matching Android production build exists. The 11-20 13:56 OTA run sent nothing (`mobile/` unchanged — #1461 is server only).
- **Website `petprep.si`** (repo `DataVallis/pet-prep-website`): deployed once a day (03:47 Ljubljana, D32) or by a manual run — **last deploy 2026-11-21 10:18 UTC** (`bba69c96`); later merges (#601–#605, colour-label / vet i18n) go live with the next daily run. Registry sync (~hourly) and care sync run green. All 15 languages rule (CLAUDE.md); M6-S06 quiz + hub, M6-S07 real-cost per breed, M6-S08 all languages are live.
- **Validation plan (`docs/business/VALIDATION_PLAN.md`, started 2026-11-10 → now end of week 2):** goal 100 paying families. Built: `/admin/funnel`, free-pet upgrade M6-U01–U03. **Backend built 2026-11-21 (`feat/M5-08-validation-signals-v2`, supersedes #1480 after QA):** acquisition source at sign-up, parent dashboard opens (H6), Phase-2 fake door (H7) incl. withdrawing »Obvestite me« + their `/admin/funnel` parts; app part in preparation (PR follows); since 2026-11-22 behind the admin switch »Validation signals« (default off; David tests with »Only test families«, §1b); privacy-policy lines still missing (§3.17). **Not built yet:** parent survey (by e-mail, D4). Wave 1 (30 families in Slovenia) not started — needs both stores with real payments. Nightly breed / language runs keep running — the freeze does not apply to them (David 2026-11-21, D1).

**Biggest shipped blocks (newest first; all on production unless marked):**
- **#1461** (11-20, supersedes #1459 after QA) — bowls key image in a neutral pose (eat + drink clips start from it), bed / bowls sized by the pet's life stage, one-off migration reopens given-up bowls chains (cap 20, ≤ ≈ 10 $). Not checked on a phone.
- **#1453 / #1450** (11-20) — bowls drinking clip for »Voda« (`item_media.kind = drink_clip`, +0,34 $ per look); parent sees »Treba počistiti« instead of »Sick« for a mess (hygiene 0 % without illness). JS part via OTA.
- **#1429** (11-19) — calm sleep clips (stricter per try), bed floor contact, durable item given up at once after its last failed try.
- **#1417** (11-19, supersedes #1414/#1415) — AI realism check for item key images and clips (`item` rubric, flagged files never shown, `media:item-recheck` dry run by default).
- **#1418 / #1421** (11-19) — one segmented store for every e-mail on petprep.si (`email_contacts` + `email_signups`, `summary_leads` migrated and dropped, admin »E-mails (website)«); migration with exclusive lock. Klaviyo sync job not built (M6-S10).
- **#1413** (11-19) — wishes bundle picker lists all three items and preselects the wished looks. **#1403** (11-19) — take-out line »needs to go outside«.
- **M6-S09 Stripe shop** (#1330 + fixes #1336–#1343, website #474 / #477, 11-17) — gift voucher + Promise Kit via Stripe Managed Payments, own voucher codes, e-mail queue `outbound_emails` (SMTP `mail.datavallis.com`), PDFs in 15 languages, admin shop. Test purchase + refund passed 11-17; **live mode waits for David** (§1b.23). Behind switches.
- **Per-pet quiet hours + 12 / 24 h clock + wheel time picker** (#1259 / #1260, 11-16); collapsible child cards (#1263); no dead end after a game (#1264); game HUD off the pet's head (#1265); colour photos per breed in the picker (M5-R11-09) + nightly colour backfill.
- **M6-U01–U03** (11-11 … 11-15, #1136) — free pet → 12-week challenge upgrade, »mutt + challenge« at creation + day-7 reminder, keep / restart the Care Score. Not verified with a real sandbox purchase.
- **Media rule** (#1139, 11-15) — a feature whose own clip is missing is disabled (»… se še pripravlja«, 422 `media_not_ready`); treats locked until their clip exists; core care never locked (confirmed by David). Hunger floor 25 % until the next meal (#1158). Weekly litter change with three gestures (#1138), Android back = hint in every game (#1137).
- **Website lead tools** — M6-S04 real-cost page, M6-S05 parent e-mails via own API, M6-S06 breed quiz + anonymous counter (#1274), partner inquiries in 15 locales (#1279). Web sign-in for voucher redeem (M6-S01b) and login brute-force guard are on `main` (11-11).
- Nightly content: breeds up to M5-R10-131 Black Russian Terrier (#1445; Neapolitan Mastiff #1472 open), colour variants, care foods (M6-K4) and vet directory (M6-K1, + translations).

**Realistic MVP completion: ~75 %** — game, parent / child apps, payments, AI media pipeline, admin, OTA on both platforms and the web shop are built; missing: device verification of most features (12 📱 rows in FEATURES, newest 2026-11-15), store products for wishes, legal review (M5-06), Kids / Families check, the beta / validation waves (M5-07).

| Milestone | ROADMAP checkboxes 2026-11-21 (done / partial / open, incl. sub-items) | Realistic |
|---|---|---|
| M0 Repo hygiene | 12 / 1 (M0-06) / 2 (M0-11 PHP version, M0-12 EAS key rotation) | ~80 % |
| M1 Core loop | 20 / 4 (M1-10, M1-11, M1-17, M1-19) / 0 | ~90 % |
| M2 Parent + auth | 21 / 6 / 11 (M2-01b, M2-07, M2-08a/b, M2-10b, Facebook, (D) rules) | ~75 % |
| M3 Notifications, sensors, payments | 8 / 5 (M3-01, M3-04 … M3-07 device checks) / 1 (M3-03 local reminders) | ~75 % |
| M4 AI media | 10 / 1 (M4-05b) / 3 (M4-05 object storage switch, M4-06 fallback, M4-09 tokens) | ~80 % |
| M5 Production + beta | 3 / 6 (M5-04 … M5-09; M5-08 funnel partly built) / 0 | ~55 % |
| M5-R Realism | 81 / 6 / 6 (R07, R09 parent + sources, R11-07, R12, popularity order) | ~85 % |
| M5-F Device fixes | 13 / 0 / 0 | 100 % |
| M5-T Refinement | 22 / 1 (T18 shadow) / 1 (accessibility badge on the website) | code ~95 %, device low |
| M6-W Wishes | 4 / 0 / 2 (W02 store products, W06 collar) | code ~85 %, not buyable until §1b.9 |
| M6-K Care knowledge | 0 / 0 / 5 ticked — but K0 pages, K1 vets and K4 nightly foods ship (rows not ticked: fix in a docs PR) | ~35 % |
| M6-U Free-pet upgrade | 3 / 0 / 0 | code 100 %, device 0 % |
| M6-S Website sales | 10 / 1 (S09 live mode) / 1 (S10 Klaviyo job) | ~85 % |

## 1b. Čaka Davida (konkretni koraki)

*David 25. 10. 2026: vsak korak, ki ga mora narediti David, je tukaj z natančnimi kliki / vrsticami. Številke točk so stalne (enake kot prej, ker se nanje sklicujejo drugi dokumenti), zato manjkajo zaprte točke (0 priboljški, 7, 10, 12, 13, 17 …) — te so z ✅ v arhivu, blok »Archived 2026-11-21«, kjer so tudi celotni stari koraki. Brez skrivnosti: `<…>` so vrednosti iz upravitelja gesel. Strežnik: `ssh -i ~/.ssh/petprep_deploy_key deploy@138.199.172.97`.*

- **Barvne slike pasem (16. 11.):** `/admin` → *AI budget* → **AI Lab** za čas zaostanka (~2 tedna) na **8 $ / dan** in **80 $ / mesec**; potem ga lahko vrneš. Iz repoja se ne vidi, ali je nastavljeno (nočne barvne PR-je #1345, #1383, #1430, #1471 so združeni).
- **Varnostne kopije:** enkrat na mesec `backup-offsite.sh verify` (`docs/PRODUCTION_DEPLOYMENT.md` §6b); po želji `BACKUP_HEALTHCHECK_URL`.
- **Validacijski signali — preizkus na svojem računu (22. 11., po deployu PR-ja za stikalo; aplikacija #1486):** 1. Odpri `https://api.petprep.si/admin` in se prijavi kot superadmin. 2. Levo **Nastavitve → Funkcije** (»Feature switches«, naslov `/admin/feature-switches`). 3. V razdelku **»Validacijski signali«** izberi **»Samo testne družine«**. 4. V polje **»Testni starši (družina je testna, če je v njej kateri od teh staršev)«** vpiši svoj starševski e-naslov (tisti, s katerim si prijavljen v aplikaciji) in ga izberi s seznama. 5. Na dnu klikni **»Shrani«**; spodaj v zgodovini se pojavi vrstica `validation_signals`. 6. V aplikaciji počakaj ≈ 1 minuto, nato aplikacijo zapri in znova odpri (ali potegni pregled navzdol) — v nastavitvah se pokaže »Kje ste izvedeli za PetPrep?«, na pregledu »Dobili smo pravo žival«. Ob registraciji se vprašanje v tem načinu NE pokaže (nov račun ni testna družina). Izklop: isti razdelek → **»Izklopljeno«** → Shrani (že shranjeni podatki ostanejo).
- **Validacijski signali za vse (šele po politiki zasebnosti):** ko je politika zasebnosti na petprep.si v vseh 15 jezikih dopolnjena (§3.17 — odgovor »Kje ste izvedeli za PetPrep?«, čas odpiranja pregleda, hranimo 400 dni, e-pošta za obvestilo z umikom »Ne obveščajte me več« in izbrisom z računom), v **Nastavitve → Funkcije → »Validacijski signali«** izberi **»Vsi«** → **Shrani**. Šele takrat se vprašanje pokaže tudi ob registraciji. Osnutek besedila politike pripravi Claude v PR-ju na spletni strani.

0. **E-naslovi s spletne strani (M6-S05 / M6-S09):** 1. `/admin` → **Management → E-naslovi (spletna stran)**. 2. Ko starš zahteva izbris: poišči e-naslov → **Delete**. 3. **Klaviyo:** seznam nastavi na *single opt-in* (potrditvenega sporočila ne pošiljamo); naslove zaenkrat prenašaš ročno (samodejni job = M6-S10, še ni zgrajen); ko se starš odjavi v Klaviyu, izbriši vrstico tudi v `/admin`. Partnerjem štej **različne e-naslove**.
1. **Object Storage (M4-05, D23)** — bucket in ključi so vpisani (25. 10.). Odprto:
   1. `/admin/system-health` → ploščica **Media storage** mora kazati *Bucket petprep-media reachable* (preveri).
   2. **Zrcalo:** v **ločenem** Hetzner projektu bucket `petprep-media-mirror` (nbg1, Private) + ključi → **samo** v `/opt/petprep/backup.env` (`MEDIA_MIRROR_S3_BUCKET`, `_KEY`, `_SECRET`, `MEDIA_MIRROR_S3_ENDPOINT=https://nbg1.your-objectstorage.com`, `MEDIA_MIRROR_S3_REGION=nbg1`).
   3. **Selitev skupaj s Claudom** (miren večer): runbook `docs/engineering/DEPLOYMENT.md` »Pet media → object storage« koraki 3–8 (`media:move-to-object-storage --dry-run` → kopija + `--verify` → pavza AI → `PET_MEDIA_DISK=s3` + deploy → `--verify`).
2. **AI proračun:** `/admin` → *AI budget* → preveri, da dnevna meja kaže **25 $** (ne privzetih 5 $); **mesečno mejo določi pred zagonom** (pri 25 $ / dan do ~750 $).
3. **Naslednja oddaja v trgovine:**
   1. Google Play Console → *App content* → **Health apps** → samo `READ_STEPS` (+ `ACTIVITY_RECOGNITION`); če je v izjavi `READ_WHEELCHAIR_PUSHES`, ga odstrani.
   2. Google Play → *Data safety* → *App info and performance* → **Crash logs** (+ *Diagnostics*): collected, not shared, not linked, *App functionality*, encrypted in transit.
   3. App Store Connect → *App Privacy* → **Diagnostics → Crash Data** (+ *Other Diagnostic Data*): not linked, no tracking, *App Functionality*; preveri *Health & Fitness*.
   4. Pri oddaji preglej besedilo `NSHealthShareUsageDescription` (EN + SL) in `NSMicrophoneUsageDescription` (aplikacija mikrofona ne uporablja; besedilo zahteva `expo-audio`).
   5. Po oddaji: `node scripts/record-app-release.mjs` (lahko Claude).
4. **Po 3.2 + 3.3:** `/admin` → *Feature switches* → **»Poročila o napakah iz aplikacije«** → ON; preveri, da se napaka pokaže v `/admin/system-health` → »App errors« in v Discordu.
5. **Testi na telefonu** (vsako potrditev sporoči Claudu → FEATURES 📱 z datumom in buildom; seznam v §5.1). Podrobni scenariji iger (žoga v2, čiščenje, šola v2, palica, crkljanje, nega) so v arhivu, stara točka 16, koraki 5–5d.
6. **Testni računi:** `/admin` → *Users* → izbriši `parent@test.com` in `child@test.com`; za `admin@petprep.io` nastavi močno geslo.
8. **Pravni pregled (M5-06):** pogoje in politiko zasebnosti s petprep.si (vključno z odstavki o bonih, e-naslovih in poročilih o napakah) daj pravniku; nato App Store *Kids* / Google Play *Families*. Pripombe → Claude popravi stran.
9. **Izdelki za »Želje« (M6-W02)** — brez njih nakup v aplikaciji ne deluje:
   1. **App Store Connect** → PetPrep → *Monetization → In-App Purchases* → **+** → **Consumable**, Product ID točno: `petprep_wish_bed` (5,99 €), `petprep_wish_bowls` (5,99 €), `petprep_wish_toy_1` (5,99 €), `petprep_wish_toy_2` (5,99 €), `petprep_wish_treats_10` (2,99 €), `petprep_wish_bundle_welcome` (15,99 €); vsakemu ime + opis (SL + EN) in posnetek zaslona.
   2. **Google Play Console** → *Monetize → Products → In-app products* → **Create product** z enakimi ID-ji in cenami → *Activate*.
   3. **RevenueCat** → *Products* → **+ New** za vsak ID v obeh trgovinah (brez entitlementa). Webhook ostane isti; `REVENUECAT_WISH_PRODUCTS` samo, če bi bili ID-ji drugačni (`docs/PRODUCTION_ENV.md`).
11. **Neuspele ključne slike / poze (po #433):** če v `/admin` → *Pets* → ljubljenček → *AI media* vidiš slot `failed`, klikni **»Generate missing«** (skupen videz) ali **»Regenerate«** (lastni mediji); najprej preveri, da ni že `running` / `ready`.
14. **Obrnjeni »spanje → budnost« (#456):** na strežniku `cd /opt/petprep/repo/backend`:
   1. `docker compose -p backend -f compose.production.yaml exec app php artisan media:frugal-unreverse --dry-run` (brezplačno; pokaže vrstice in oceno).
   2. Če je znesek v redu: isti ukaz brez `--dry-run` (po želji `--limit=<n>`).
   3. Čez ~15 min ponovi `--dry-run` — seznam naj bo prazen. Runbook `docs/PRODUCTION_DEPLOYMENT.md` §8b.
15. **Pravila iger v2 — odločitve (PR #461)**, odgovori »da« ali »spremeni v …«: (a) priboljšek v šoli tudi, ko je paket porabljen; (b) nakup priboljškov velja za tega ljubljenčka, ne za družino; (d) palica rezervira 60 s + 60 s pred tihimi urami / polnočjo; (e) »Končaj« po 1. krogu pošlje `trained_pet`; (f) in poveča `sessions_completed`; (g) starš predčasne vaje ne vidi v časovnici. ((c) zaprto 8. 11. — preprosta palica.)
18. **journald omejitev (D31), enkrat kot `root`:** `ssh root@138.199.172.97` → `install -d /etc/systemd/journald.conf.d` → `printf '[Journal]\nSystemMaxUse=200M\nSystemKeepFree=2G\n' > /etc/systemd/journald.conf.d/petprep.conf` → `systemctl restart systemd-journald` → `journalctl --disk-usage` (≤ 200 M) in `df -h /`.
19. **AI preverjanje slik (#736):**
   1. `/admin` → *Feature switches* → »Clip quality check«: »Quality check mode« = **Shadow**, »AI vision call of the clip check« = vklopljeno, »Item check mode« = **Enforce**, »Pet image check mode« = **Shadow** (preveri; `AI_QUALITY_VISION_ENABLED=false` v `.env` ne sme biti).
   2. **Umerjanje:** `/admin` → Ljubljenčki → ljubljenček → *AI media* → stolpec »Quality«: odpri nekaj »would fail (shadow)« in nekaj »passed«. Če so zavrnjene res slabe in dobre niso zavrnjene → »Pet image check mode« = **Enforce**; enako kasneje za posnetke (»Quality check mode«).
20. **Časovni pas (v produkciji od 10. 11.):** 1. Nadzor → kartica tvojega psa → **Časovni pas → Spremeni → Lizbona → Zamenjaj**. 2. **Nadzor → Časovni pas družine → Spremeni → Ljubljana → Zamenjaj.** 3. Preveri: drugi ljubljenčki »Ljubljana · UTC+1 · po družini«, tvoj »Lizbona · UTC«. (Iz repoja se ne vidi, ali je narejeno.)
21. **Nadgradnja mešančka v izziv (M6-U01, v produkciji od 11. 11.):**
   1. Sandbox: otrok z brezplačnim mešančkom → kartica v Pregledu → **»Začni 12-tedenski izziv z [ime]«** → **Kupi za 49,99 €** → v ~8 s »Hvala! … teden 1 od 12«; otrok vidi enkrat »Izziv se je začel!«. Ob nadgradnji izberi »Obdrži dosedanjo oceno« / »Začni na novo« (M6-U03).
   2. Discord »… challenge purchase(s) not assigned …« → dodeli v `/admin` → *Challenge credits* ali piši družini.
   3. Posodobi cenik / FAQ / pogoje na strani in opise v trgovinah (spec §7).
22. **»Mešanček + izziv« in opomnik 7. dan (M6-U02):**
   1. Sandbox: Dodaj otroka → Nov pes → **12-tedenski izziv** → mešanček ni siv (»Začnite brezplačno …«) → PIN → pogodba → ljubljenček zaklenjen do nakupa.
   2. Opomnik pride 7 dni po pogodbi brezplačnega ljubljenčka, če je en otrok skrbel vsaj 5 od 7 dni: brez imena otroka, kartica na pregledu, dotik odpre nakup.
   3. Privzete izbire lahko spremeniš (DECISIONS 11. 11.).
23. **Stripe trgovina — živi način (M6-S09; test + vračilo uspela 17. 11.):**
   1. Stripe (live) → *API keys* → omejen ključ »PetPrep server« (Checkout Sessions **Write**; Products, Prices, Payment Intents **Read**); *Webhooks* → `we_1UctcnEShsvVJ1lPeQADrDTx` → *Reveal* signing secret.
   2. `/opt/petprep/.env`: `STRIPE_SECRET_KEY=<rk_live_…>`, `STRIPE_WEBHOOK_SECRET=<whsec_…>`, `STRIPE_PRICE_GIFT_VOUCHER=price_1UctcmEShsvVJ1lPS8vmaIBG`, `STRIPE_PRICE_PROMISE_KIT=price_1UctcnEShsvVJ1lPY0fK1Bdy`, `STRIPE_ACCEPT_TEST_MODE=false` → `docker compose restart app scheduler queue`.
   3. Pošta (če še ni): `curio@petprep.si` (pošiljatelj) in `hello@petprep.si` na `mail.datavallis.com`; DNS `petprep.si`: SPF, DKIM, DMARC `p=none`. Stripe → *Business details* → *Support email* = naslov, ki ga bereš.
   4. PDF-ji kompleta, če še niso uvoženi: `petprep-promise-kit-pdfs.zip` → `scp` na strežnik → `unzip -o … -d /tmp/kit` → `docker compose -f compose.production.yaml cp /tmp/kit app:/tmp/kit` → `… exec -T app php artisan shop:import-files /tmp/kit`.
   5. `/admin` → *Feature switches* → **»Komplet Obljuba«** ON.
   6. Pogoji in zasebnost na petprep.si: odstavek o bonih (veljavnost 12 mesecev, neunovčen vračljiv 14 dni, unovčen ne; prodajalec Link) in o e-naslovu kupca.
   7. Ko je aplikacija v obeh trgovinah: »Gift vouchers« ON + `NEXT_PUBLIC_FEATURE_GIFT=1` na spletni strani.
24. **Prijava na strani za unovčitev (M6-S01b) — končni preizkus:** z »Darilni boni« za kratek čas ON se na `petprep.si/redeem` prijavi (a) z Google računom iz aplikacije, (b) z Apple ID-jem iz aplikacije na iPhonu, (c) z e-naslovom + geslom testnega starša — vsak mora priti do koraka s kodo. **Če Apple vrne `account_not_found`, USTAVI in sporoči Claudu.** Stikalo nazaj OFF.
25. **#walkyourpet galerija z Instagrama (odobreno 15. 11., ni zgrajeno):** (1) @petprep.si → profesionalni račun (Business / Creator); (2) poveži ga s Facebook stranjo PetPrep (Meta Business Suite → Nastavitve → Računi → Instagram); (3) developers.facebook.com → aplikacija vrste *Business* + izdelek *Instagram* → Claudu »Meta app done« (ID; skrivnosti v `/opt/petprep/.env`).

## 2. Decisions

All decisions (who / what / why / PR): `docs/product/DECISIONS.md` (newest on top). Standing rules to know before any work:

- **Business model:** 12-week PetPrep Challenge **49,99 €**, no free trial (the free mutt / domestic cat is the try-out, upgradable to the challenge with the same pet — M6-U); one purchase = one pet. Website sells gift voucher + Promise Kit through Stripe (Lemon Squeezy rejected the store).
- **Validation first (David 2026-11-10):** 8-week plan, 100 paying families; frozen meanwhile: new breeds / species / languages, Curio, shop + plush, 2.5D pet, vet directory (`VALIDATION_PLAN.md` §2).
- **Media rule »stanje → medij, nikoli obratno«** + **general media rule** (PRODUCT_SPEC §10.1): a feature without its own clip is disabled (»… se še pripravlja«, 422 `media_not_ready`, via `GameMediaReadiness` / `ItemMediaService::actionReady()` only); core care is never locked; simple mode of school / games after exhausted retries is the accepted exception.
- **Time:** every per-pet wall-clock rule in `Pet::effectiveTimezone()`; family-wide rules in the family zone; store UTC; quiet hours per pet (`Pet::quietHours()`); 12 / 24 h per user.
- **Child:** PIN only, no child e-mail, minimum data, no analytics SDK without David's OK, no child name in any push.
- **Website:** every page in all 15 languages (David 2026-11-16); website copy original, never copied.
- **Product switches live in `/admin → Feature switches`**, not env (cats, unique AI media, state media, school & game clips, quality-check modes, gift vouchers, Promise Kit, app error reports).
- **Deploys:** merge to `main` = CI only; production = daily 03:17 run or a manual Run workflow by David (or by Claude only on David's explicit request). OTA follows every production deploy.
- **Claude merges its own PRs** after green `CI OK` (+ one QA round for auth, payments, personal data, migrations on existing data, AI cost); technical-docs gate on every PR (`Docs updated:`).
- **Never push to an existing PR branch** — superseding work goes in a new PR from a fresh branch off `main` (#1459 → #1461, #1414 → #1417, #1316 → #1418).

## 3. Known bugs & debt

*Current items only (2026-11-21). Old numbering and fixed items: archive. »Unverified« = cannot be seen from the repo / Actions — ask David.*

1. **Device verification is the gap:** only 12 FEATURES rows are 📱 (newest 2026-11-15). Unverified on a phone: game layer (ball / school / wand / cuddles v2, cleaning games, litter change gestures), M6-U01–U03 purchases, per-pet quiet hours + wheel picker, colour photos, bowls drinking clip, »Treba počistiti«, M5-T features, i18n (Hermes plurals, Android channel names).
2. **Item media quality on production:** bowls composition (which side the water bowl is) is not fixed — prompts name the bowl by content (#1461); Kling takes no seed, only the prompt changes per try (#1429); the item realism check (#1417) has no measured pass rate yet — watch »retrying« / »needs review« in `/admin → Shop → Item media`.
3. **Quality check (M5-T18)** for clips and pet key images still in **shadow** until David calibrates (§1b.19); item pictures enforce.
4. **`mobile/src/api/schema.ts`:** CI »API types« is the gate; local Scramble differs (hand patch in #1453). A full regeneration weakened the `plan` block because `PetPlanPayload::toArray()` uses a conditional spread (#1136) — check whether it was fixed before the next full regeneration.
5. **E-mail store (M6-S09):** migration `down()` is lossy (take a `pg_dump` before any rollback); no Klaviyo sync (M6-S10); `signups_count` of migrated contacts can exceed their signup rows.
6. **Shop minors (QA 17. 11.):** `async_payment_failed` before `completed` leaves an order `pending`; a game-data reset keeps vouchers but deletes their credits; a price change during an open checkout only raises a Discord alert.
7. **Wishes (M6-W02):** store / RevenueCat products missing (§1b.9) → wishes not buyable in the app; the »Lijak« page does not count upgrade purchases separately (M6-U01 spec §7).
8. **Android / stores:** Play health declaration + Data safety, App Privacy (Diagnostics) still to submit (§1b.3); app error reports switch off until then (§1b.4); RevenueCat Play service account, Android product in the offering, Android OAuth client with the Play signing SHA-1 — *unverified*.
9. **Daily deploy cron starts ~6–7 h late** (GitHub schedule) — deploys work; urgent fixes need David's manual run.
10. **Security / hygiene before beta:** EAS signing passwords in old repo history (M0-12, *unverified* whether rotated); PHP 8.3 prod / CI vs 8.5 Sail (M0-11); `PRODUCTION_SSH_PRIVATE_KEY` is a repository secret (D20); dev test accounts + superadmin password (§1b.6); mobile Dependabot zod / uuid / deepmerge deferred (build tooling only).
11. **Media storage:** pet media on the server disk (`app_storage`); object storage built but off (§1b.1); no CDN; no per-IP connection limit in Caddy (M4-05b). Disk hygiene automatic since D31; journald cap manual (§1b.18).
12. **Time zones:** after a zone change today's routines are computed live in the new zone (an earlier meal may fall outside a moved window — not forgiven); child state has no zone label.
13. **Breeds hard-coded in the app** (`KNOWN_BREEDS`) — a new breed reaches phones only with an OTA (and Android only while its fingerprint matches a store build).
14. **Code debt:** `backend/.env.example` lacks `REVERB_*` (M0-06); 9 services still call `isParent()` / `isChild()` (M2-03); no »24-h day« test (M1-10); no `expo-dev-client` (M3-01); fragile double `Queue::fake` in `PetPreparationTest`.
15. **Website:** 13 languages not reviewed by native speakers; »Try <breed> in PetPrep« CTA does not pass the breed; hub cards »Kako velik bo zares?« / »Je vaš dom pripravljen?« are »Kmalu«.
16. **Docs drift found in this refresh:** ROADMAP M6-K rows are unticked although K0 / K1 / K4 content ships; nightly breed / language runs continue during the validation freeze (§1).
17. **Privacy policy lines for the validation signals (VALIDATION_PLAN §4, flagged by QA #1480, not done):** the website privacy policy (`pet-prep-website`, all 15 languages) must name the acquisition answer, the parent dashboard opens (kept 400 days) and the »Obvestite me« e-mail left in the app (withdraw in the app / deleted with the account). Until then the admin switch »Validation signals« stays **Off** or **Only test families** (David 2026-11-22); **Everyone** only after the policy is live (§1b). Needs a website PR + David's approval of the wording.

## 4. Environment & configuration

- **Production docs:** `docs/PRODUCTION_DEPLOYMENT.md`, `docs/PRODUCTION_ENV.md`; findings / store checklist `docs/engineering/DEPLOYMENT.md` (D1 … D35); scaling `docs/engineering/SCALING.md`. Logs: `docker exec backend-app-1 tail storage/logs/laravel.log` (volume `app_storage`).
- **Which code a phone runs:** parent Nadzor → »O aplikaciji« → »Različica aplikacije« (store build + OTA commit); »Preveri posodobitve« fetches a pending OTA.
- **GitHub:** public repo, no branch protection; the single gate is the `CI OK` check (`gh api "repos/DataVallis/pet-prep/commits/<sha>/check-runs?check_name=CI%20OK"`). Logs cannot be downloaded through the proxy — read check-run **annotations** (`plan` job, OTA plan). GraphQL is blocked: PRs via `gh api repos/DataVallis/pet-prep/pulls -f …`, merges via `gh api -X PUT …/pulls/<n>/merge`; the proxy refuses branch deletion.
- **Cloud sessions (no Docker):** local Postgres + `DB_DATABASE=testing_<branch>` per worktree, only the touched Pest files; Composer as root needs `COMPOSER_ALLOW_SUPERUSER=1` + scratch `COMPOSER_HOME`; disk is tight (~5 GB per worktree) — remove worktrees when done; the local fetch refspec tracks only `main` (add a temporary `remote.origin.fetch` entry to push / track a branch). Full tips: archive, block »Archived 2026-11-21« §4.
- Secrets only in `backend/.env`, `mobile/.env`, `mobile/credentials.json` (local) and `/opt/petprep/.env`, `/opt/petprep/backup.env` (server). Never call fal from tests.

## 5. Next steps (priority queue)

1. **Device checks David owes (M5-07 / M5-T / M6-U, §1b.5, .21, .22, .24):** on iPhone and Android with the current OTA (4.1.6 @ `ba44f11d`): game layer (ball / school / wand / cuddles v2, cleaning, litter change gestures, Android back hint), free-pet upgrade + keep-score choice (sandbox), mutt + challenge + day-7 reminder, per-pet quiet hours + wheel picker + 12 / 24 h, colour photos in the picker, voucher redeem sign-in (3 ways). Each confirmation → FEATURES 📱 with date + build.
2. **Phone check of the remade bowls / bed clips (M6-W03, #1461 / #1453 / #1429 / #1417):** open a pet with bought bowls and bed — bowls in a neutral pose, eating on »Hrana«, drinking on »Voda«, item size fits the life stage, bed on the floor, calm sleep; in `/admin → Shop → Item media` check the reopened bowls rows (60, 69–72) and »needs review« counts. Report bad clips with the row id.
3. **Validation plan week 2–3 build (M5-08 rest, VALIDATION_PLAN §4.1, §4.4, §4.5):** campaign code / acquisition source at sign-up, parent dashboard-open count (H6), Phase-2 fake door »Dobili smo pravo žival« (H7), short parent survey — spec / contract first, then backend + mobile in parallel. Ask David about the breed / language freeze.
4. **Real payments in both stores (VALIDATION_PLAN §4.2, M6-W02, M3-07):** David's store submission (§1b.3) and wish products (§1b.9); then app error reports ON (§1b.4) and Wave 1 (30 families in Slovenia).
5. **M6-S09 live mode (§1b.23)** → first real website sales; then **M6-S10** Klaviyo sync job (queued, `provider_synced_at`).
6. **Before the beta (M5-06, M5-05, §1b.6, .8):** legal review + Kids / Families check, delete dev test accounts, external uptime check.
7. **Quality-check calibration (M5-T18, §1b.19):** after real production clips, switch pet images and clips from shadow to enforce.
8. **M4-05 object storage migration (§1b.1)** with David on a quiet evening; docs PR to tick ROADMAP M6-K rows that already ship.

## 6. Session log

*Last ~7 days, condensed. Full entries (files, tests, QA details): archive, block »Archived 2026-11-21«.*

### 2026-11-21 (backend, `feat/M5-08-validation-signals-v2`)
- **Validation signals backend (VALIDATION_PLAN §4.1 / §4.4, contract `docs/engineering/VALIDATION_API.md`, David D1–D4):** acquisition source at register / social sign-up + once via new `PATCH /api/parent/settings` (PUT unchanged), `POST /api/parent/dashboard-opened` (H6, ≤ 1 / 30 min, pruned after 400 days), `POST /api/parent/phase2-interest[/viewed]` (H7, e-mail into `email_contacts` source `app_phase2`), dashboard `phase2_interest {submitted, notify}`, export + deletion, `/admin/funnel` source table + H6 + H7. Tests `ValidationSignalsTest` (+ SocialSignInTest). App: mobile PR follows. Debt: H6 uses wall-clock days 8–14 (payment-lock time not subtracted); the e-mail survey (D4) not built. **QA #1480 → v2:** withdrawal `{notify: false}` without a price, dashboard `phase2_interest.price_choice`, own signup per parent (`email_signup_id`), reset removes `app_phase2` signups, separate `PatchParentSettingsRequest`, answer stored before the timezone cooldown; open question for David: per-parent fields in the family export (VALIDATION_API §4).

### 2026-11-20 / 21 (cloud, orchestrator + docs)
- Bowls: #1459 was **closed unmerged after QA** and superseded by **#1461** (fresh branch `fix/bowls-drink-pose-and-size-v2`: neutral key image, stage-relative size, one-off reopen migration `2027_04_29_120000`); merged 11-20 13:35 UTC.
- **Manual production deploy on David's request:** run 44748255299 (2026-11-20 13:53 UTC, `d815b3d4`, dispatched by David) — green; OTA run 44748728545 sent nothing (server-only change). The daily run 44891708674 (2026-11-21 09:53 UTC, `ba44f11d`) deployed the rest + OTA to iOS and Android.
- **#1477:** HANDOFF.md is synced to the claude.ai Project copy `petprep/HANDOFF.md`; rule added to `.claude/commands/handoff.md` step 6 and CLAUDE.md.
- **This refresh (`docs/handoff-refresh-2026-11-21`):** HANDOFF cut from 684 lines / ~200 KB to ≤ 300 lines; previous file moved verbatim to the archive; §1 re-verified against GitHub (finding: the last deploy is the 11-21 daily run, not 11-20); §1b reduced to open steps with stable numbers; closed ones ✅ in the archive. No code change.

### 2026-11-20 (cloud, backend + mobile)
- #1453 bowls drinking clip (`drink_clip`, backfilled by `media:item-media`); #1450 parent »Treba počistiti« + water plays the drinking clip (JS → OTA). Breeds M5-R10-129 … 131 (American Hairless Terrier, Tibetan Spaniel, Black Russian Terrier), cat colour backfill #1471, i18n top-ups, vet directory in Finnish / Norwegian.

### 2026-11-19 (cloud, backend + mobile)
- #1417 item realism check (supersedes #1414 / #1415 after QA; David wanted it live the same day → manual deploy 44552564528). #1429 calm sleep + bed fit. #1418 / #1421 e-mail store v5 (supersedes never-merged #1316; one QA round, personal data). #1413 wishes bundle picks. #1403 take-out wording. Breeds M5-R10-126 … 128 (Mudi, Welsh Terrier, Norwegian Elkhound).

### 2026-11-17 / 18 (cloud, backend + website)
- M6-S09 Stripe shop #1330 (one QA round) + e-mail fixes #1336–#1343; test purchase + refund passed; live Stripe ids created (§1b.23). Website: real-cost in 15 languages, partners + media kit, quiz + hub (#443–#449), Promise Kit on (#477). #1371 CI flake fix (temp-file sweep). #1368 website content audit. Breeds M5-R10-123 … 125.

### 2026-11-16 (cloud, backend + mobile + docs)
- Per-pet quiet hours + 12 / 24 h (#1259, QA round) and the app part (#1260, wheel picker); collapsible child cards (#1263); no dead end after a game (#1264); HUD off the pet's head (#1265); colour photos per breed (M5-R11-09) + nightly colour backfill; breed quiz API (#1274); all-languages rule + M6-S07 (#1269–#1272).

### 2026-11-15 (cloud, backend + mobile + docs)
- Media rule + treats locked until their clip exists (#1139, QA round; David confirmed the open points); hunger floor 25 % (#1158); keep / restart score at upgrade M6-U03 (#1136); litter change gestures (#1138), Android back hint in every game (#1137, #1135); schema.ts generated in CI (#1141); M6-S04 real-cost page + M6-S05 parent e-mails (#1162, #1166); three.js analysis.
