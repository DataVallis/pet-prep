# PetPrep — Razvojni načrt (Roadmap)

> Živi dokument. Lastnik: orkestrator (Claude) + David. Vsak zaključen task se odkljuka tukaj **in** v `HANDOFF.md`.
> ID-ji taskov (npr. `M1-03`) se uporabljajo v imenih vej (`feat/M1-03-decay-fix`) in commitih.
> Ozadje in utemeljitve: `docs/engineering/AUDIT-2026-10-02.md`.

Legenda: `[ ]` odprto · `[~]` v delu · `[x]` končano · **(D)** = čaka na Davidovo odločitev

---

## M0 — Higiena repozitorija (1 dan)

- [x] M0-01 Root `.gitignore` (node_modules, .idea, .DS_Store, .env)
- [x] M0-02 ~~`.gitmodules`~~ — ni več potrebno (M0-04)
- [x] M0-03 `node_modules/`, `.idea/`, `.DS_Store`, `.augment_tmp_*` niso več v gitu
- [x] M0-04 `pet-prep-mobile` združen v `pet-prep/mobile` (zgodovina ohranjena, `credentials.json` odstranjen iz zgodovine). Ročno: arhivirati repo `DataVallis/pet-prep-mobile` na GitHubu
- [x] M0-05 En paketni manager: **yarn 1** (root `package.json` že deklarira `packageManager: yarn`); odstraniti `package-lock.json` v root in `mobile/`, posodobiti skripte in CLAUDE.md
- [ ] M0-06 `backend/.env.example` z vsemi dejanskimi spremenljivkami; `mobile/.env.example` (`EXPO_PUBLIC_*`)
- [ ] M0-07 `pestphp/*` → `require-dev`; nadgradnja Laravel na aktualno verzijo (+ Filament, Sanctum, Reverb)
- [x] M0-08 CI popravek: obstoječi `deploy-production.yml` testira na sqlite (`.env.example`) → dodaj `postgres:18` service; dodaj mobile job (tsc, Jest); **deploy samo z ročno odobritvijo** (GitHub Environment reviewers) — glej DEPLOYMENT.md D1–D2
- [ ] M0-09 Izbrisati podvojeni `mobile/src/components/WalkTrackerOverlay.tsx`, odstraniti `expo-av`
- [x] M0-10 Gumbi za hitro prijavo s testnimi gesli so vidni samo v development buildih (`__DEV__`); EAS preview/production jih ne prikažeta (test v `ParentLoginScreen.devLogins.test.tsx`, prej `PairingScreen.devLogins.test.tsx`)
- [ ] M0-15 **Pred javno beto:** v produkcijski bazi obstajata `parent@test.com` / `child@test.com` z geslom `password` (potrjeno 2026-10-03) — zamenjati gesla ali izbrisati, preveriti `admin@petprep.io`
- [ ] M0-11 Poenotiti verzijo PHP (dev Sail 8.5, prod + CI 8.3) — predlog 8.4 povsod
- [x] M0-13 Mobilni Jest + tsc delujeta po sveži namestitvi (`@react-native/jest-preset` 0.86.3, `@types/jest` 29, TS 6 `types`) — 72/72 testov, 0 tsc napak
- [x] M0-14 Otroški HUD vizualno preverjen (David, 2026-10-03)
- [ ] M0-12 Rotirati gesla EAS podpisnih ključev (bila so v git zgodovini `pet-prep-mobile`); `credentials.json` samo lokalno / EAS remote credentials

## M1 — Jedro igre end-to-end (1,5–2 tedna)

**Backend**
- [x] M1-01 Decay rewrite: stolpec `last_decay_at`, metrike `decimal(5,2)` (API zaokroži), decay neodvisen od `updated_at`; unit testi s `Carbon::setTestNow` čez 24 h simulacijo za obe pasmi (lakota 0 % po 12,5 h / 8,3 h) — *izvedeno z `double precision` namesto `decimal(5,2)` (2 decimalki bi izgubili ~2,5 % na minutni tick); veja `fix/M1-01-decay-engine`*
- [x] M1-02 Decay se ne izvaja med `is_hard_stopped` in med boleznijo (zamrznjeno) — *tudi neaktiven / game over; ura `last_decay_at` teče naprej, brez "catch-up" po odmrznitvi; hard stop in bolezen zamrzneta tudi ure zanemarjanja (`*_zero_since`, `frozen_at`) in eskalacijo*
- [x] M1-03 `users.timezone` (IANA, privzeto `Europe/Ljubljana`); tihe ure, polnoč in časovna okna v lokalnem času družine — *časovni pas družine = starševski; `PUT /api/parent/settings`, neobvezen `timezone` v `PUT /api/parent/quiet-hours`, `timezone` v dashboardu; pravilno čez premik ure (DST); okna hranjenja še ne obstajajo (M1-07) — veja `feat/M1-03-family-timezone`*
- [x] M1-04 Energija = `min(100, daily_steps / breed.daily_steps_required * 100)`; reset ob lokalni polnoči — *`PetActivityService::recordSteps()` (idempotentno = max dnevnega števila, anti-cheat ≤ 200 korakov/min od zadnjega sprejetega synca ali lokalne polnoči — presežek se zavrne, ostanek lahko sprejme kasnejši sync; sync s preteklega dne se ignorira; zaklenjen med hard stop / bolezen / game over); sync energije nikoli ne zniža (rojstni dan: 100 % do prve polnoči); števec bolezni zdaj šteje samo čas izven tihih ur; HTTP endpoint je M1-07 — veja `feat/M1-04-energy-hygiene-breeds`. Po Davidovi odločitvi 3. 10.: **energija = dnevni sprehod** (brez urnih ur zanemarjanja; ob polnoči `pet_daily_walks`, brez sprehoda → bolezen ob koncu tihih ur) in **ozdravitev = nov začetek** (higiena 100 %, ure znova) — `DailyWalkService`, `Pet::recoverFromIllnessIfDue`*
- [x] M1-05 Higiena: naključni dogodek "kakec" 1× (mutt) / 2× (BC) dnevno izven tihih ur → higiena 0 %; vnaprej razporejeni časi (`next_poop_at`) — *tabela `pet_hygiene_events` (namesto `next_poop_at`), en naključen čas v vsakem enakem deležu netihega dne, determinističen RNG (app key + pes + datum); dogodki med zamrznitvijo / pred rojstvom se preskočijo; catch-up po izpadu enkrat; `PetActivityService::clean()` → 100 %; začasno padanje 1,5 %/h odstranjeno*
- [x] M1-06 `breed_configs` razširiti: `thirst_decay_rate`, `poops_per_day`, `feed_windows`, `water_times_per_day` (odstrani hardcode iz servisa) — *+ `water_min_gap_minutes`; CHECK omejitve, backfill v migraciji, idempotenten seeder, Filament urejanje (tudi okna hranjenja); okna in voda se uveljavijo v M1-07*
- [x] M1-07 Otroški API (nov `ChildPetController` + `PetActionService`) — *2026-10-04, veja `feat/M1-07-child-actions`: `ChildPetController` + `ChildContractController` na `PetActivityService` (feed / water / contract dodani) + `CareScheduleService` (okna, voda); `PetPolicy` (samo otrok, starš 403), FormRequesti, `throttle:child-actions` 30/min; 423 z razlogom (`game_over|inactive|hard_stopped|ill`), 422 z razlogom + `next_allowed_at`, 409 za drugo pogodbo; tabela `pet_contracts`; vsaka akcija: zaklep vrstice, ena vrstica v `activities_log`, en `PetUpdated` po commitu; hrana/voda zavrnjeni, dokler higiena kaže 0 % (čaka Davida). + popravek: `EscalationService` z `lockForUpdate()` na psa. 99 novih testov (DST 25. 10. 2026 in 28. 3. 2027). Tipi `schema.ts` regenerirani.*
- [x] M1-07b Pogodba pred rojstvom (David, 4. 10. 2026; spec §3 "PIN → pogodba → pes se rodi") — *2026-10-04, veja `fix/M1-07b-contract-before-birth`: `pets.born_at` nullable brez privzete vrednosti (null = nerojen); parjenje ustvari nerojenega psa (referenčna slika ostane ob parjenju); tick, eskalacija, `DailyWalkService`, `HygieneEventService` ga preskočijo (SQL filter `Pet::born()` + varovalka); vse akcije razen pogodbe → 423 `contract_required` (prednost game over › neaktiven › hard stop › pogodba › bolezen); podpis pod zaklepom vrstice = rojstvo (`Pet::giveBirth`: born_at, ura upadanja, rojstni dan korakov, urnik higiene, metrike 100 %), en `PetUpdated` po commitu; `awaiting_contract` v dashboardu, parjenju, stanju otroka in oddaji; obstoječi psi ostanejo rojeni (grandfathered). 25 + 2 nova testa (po združitvi z M1-08 skupaj 464). Tipi `schema.ts` regenerirani. Aplikacija: podpis s prstom (SVG pot) → `POST /api/child/contract`, pes iz odgovora strežnika v seji; nerojen pes ob zagonu/prijavi → korak pogodbe (Jest 201).*
  - `GET  /api/child/pet` — polno stanje (vključno z `is_hard_stopped`, okni akcij, `next_feed_window`)
  - `POST /api/child/pet/feed` — samo v oknu (privzeto 06–10 in 17–21), 422 izven
  - `POST /api/child/pet/water` — max 3× / dan, razmik ≥ 3 h
  - `POST /api/child/pet/clean`
  - `POST /api/child/pet/steps` — `{steps_today, source, recorded_at}`; anti-cheat (Δ ≤ 200 / min od zadnjega synca), idempotentno (vzame max)
  - `POST /api/child/contract` — shrani podpis (SVG path / PNG) + čas
  - vse akcije: 423 Locked, ko je `is_hard_stopped` / `isIll()` / `is_game_over`
- [x] M1-08 `PetUpdated` → `PrivateChannel('pet.{id}')`, `/broadcasting/auth` prek Sanctum; odstrani podvojene broadcaste (observer + ročni klici) *(2026-10-04, veja `feat/M1-08-private-channels`: `POST /api/broadcasting/auth`, `PetPolicy::listen`, brez opazovalcev, `PetUpdated::afterCommit`, payload brez PII)*
- [x] M1-09 Broadcast prek queue (ne `sync`) — izpad Reverba ne sme ustaviti cron zanke *(2026-10-04, ista veja: vrsta `broadcasts`, delavec `queue-broadcasts` v produkciji, napake se zabeležijo)*
- [ ] M1-10 Pest feature testi za vse zgornje + test "24-urni dan" (integracijski)

**Mobile**
- [ ] M1-11 Navigacija: Expo Router ali React Navigation; root `RootNavigator` z role-based vejama (parent / child)
- [x] M1-12 Session bootstrap: ob zagonu prebere token iz SecureStore → `GET /api/user` (vrne tudi `pet`) → usmeri po vlogi kot po prijavi; splash, 401 → odjava, brez povezave → "Poskusi znova"; enotna odjava (`logout()`) *(2026-10-03, veja `feat/M1-12-session-restore-parent-pin`; `GET /api/child/pet` ostane za M1-07/M1-15)*
- [x] M1-13 TanStack Query hooki (`usePet`, `useFeed`, …) z optimistic update; Zustand samo za UI / ws stanje *(2026-10-04, veja `feat/M1-14-child-actions-ui`: `useChildPet` (`GET /api/child/pet`, normaliziran pogled), `useFeed` / `useWater` / `useClean` (optimistično 100 %, nato vedno `state` iz odgovora, povrnitev brez odgovora), `useSyncSteps`; dogodki v živo v isti predpomnilnik, starejši po `emitted_at` zavrženi; v Zustandu ostanejo seja, ws, zaklep in prekrivni zasloni)*
- [x] M1-14 Feed / Water / Clean / Walk vezani na API; okna akcij iz API-ja (disabled + "naslednje okno ob 17:00") *(2026-10-04, ista veja: gumbi iz `can_feed` / `can_water` z namigom po času družine, prijazna sporočila za vse razloge 422/423, čiščenje → `POST clean`, koraki: iOS iz CoreMotion od polnoči, Android števec v živo, sync ob odprtju / vrnitvi / 5 min; lokalni +20 % odstranjen)*
- [x] M1-15 Echo: private channel z `authorizer` (Bearer token), wss v produkciji, pravi polling fallback (`GET /api/child/pet` vsakih 10 s)
  - [x] `echo.private('pet.{id}')`, authorizer `api.authorizeChannel` (Bearer), wss + forceTLS pri https, dogodek `.pet.updated` *(2026-10-04, `feat/M1-08-private-channels`)*
  - [x] Polling fallback: `useChildPet` `refetchInterval` 10 s, dokler `wsStatus` ni `connected` (= naročnina na kanal uspela) *(2026-10-04, `feat/M1-14-child-actions-ui`; starševski dashboard še brez fallbacka)*
- [~] M1-16 `lockState` iz `is_hard_stopped` / `is_ill` / `is_game_over`; hard stop API usklajen
  - [x] Aplikacija: zaklep iz strežniškega stanja (obnova seje z `is_hard_stopped` / `is_active`, v živo iz dogodka, odklep ob preklicu), zaslon z razlogom in uro veterinarja po času družine *(2026-10-04, `feat/M1-14-child-actions-ui`)*
  - [ ] Odprto (backend): hard stop preklop brez zaklepa vrstice / transakcije
- [ ] M1-17 Generirani tipi iz OpenAPI (`schema.ts`) se uporabljajo v `client.ts` namesto ročnih
- [ ] M1-18 i18n s `expo-localization` + `i18next`: **EN privzeto + SL**; tudi strežniška sporočila (push, napake) prek Laravel lang datotek
- [ ] M1-19 **Uvoz podatkov o pasmah iz virov** (David, 2026-10-03): zbrati zanesljive vire (FCI/AKC standardi, veterinarska literatura o gibanju, prehrani, vodi), AI izlušči vrednosti → tabela z virom na vsako številko → David potrdi → uvoz v `breed_configs` (+ stolpec/tabela za vire). Do takrat so številke iz izvirne specifikacije označene kot *nepreverjene*. Sejalnik je insert-only.

## M2 — Starš, avtentikacija, dashboard (1 teden)

- [~] M2-01 **Družinski model** (David, 2026-10-04; ADR-012): več staršev, več otrok, vsak otrok svoj pes ali skupni pes, dejanja pripisana otroku, pes = enota plačila
  - [x] Faza 1 backend *(2026-10-04, `feat/M2-01-family-model`)*: tabele `families`, `family_user`, `pet_caretakers` (največ 1 aktiven pes na otroka — indeks v bazi), `pet_daily_steps`, `family_invites`, `pets.family_id`, `activities_log.actor_user_id`, pogodba na (pes, otrok); migracija obstoječih podatkov; politike po družini (kanal: vsi skrbniki + vsi starši); `POST /api/parent/generate-pin {pet_id?}`, `POST /api/parent/invite-parent`, `POST /api/parent/join-family`; dashboard `family` z ocenami po otroku (7 dni); stara polja ostanejo za obstoječe verzije aplikacije
  - [x] M2-01a Mobilni družinski UI: dashboard z več otroki / psi, "Povabi drugega starša", "PIN za obstoječega psa", statistika po otroku, pogodba za otroka, ki se pridruži skupnemu psu *(mobile-engineer)*
    - [x] Seznam otrok na pregledu in v Nadzoru (vzdevek, pes, število naprav, "Nova koda za prijavo", "Odjavi vse naprave"), "Dodaj otroka" vedno, "Pridruži se psu …" pri novem otroku, pogodba za otroka, ki se pridruži skupnemu psu *(2026-10-04, `feat/M2-02-pin-only-child`)*
    - [x] Preostanek *(2026-10-04, `feat/M2-05-parent-dashboard-ui`)*: kartica na otroka s psom in metrikami tega psa, nadzor po psu (skrbniki, hard stop s `pet_id`), statistika / poročilo po otroku, "Povabi drugega starša" (koda + deljenje) in "Imate kodo družine?" (vnos kode, napake 409/422/429)
  - [ ] M2-01b Odstrani zastarele `users.parent_id`, `pets.user_id`, ogledalo `users.timezone` in mostne model hooke, ko jih aplikacija ne bere več
  - [x] M2-01c Formula ocene / semaforja po otroku (skupni pes) — David 4. 10.: "pošten delež" (PRODUCT_SPEC §11.2); zgrajeno z M2-06 *(2026-10-04, `feat/M2-05-dashboard-scoring`)*
  - [ ] M2-01d Certifikat po otroku (12 tednov) iz dejanj otroka
  - [ ] Odprto **(D)**: nov pes po game overu za istega otroka, skrbnik zapusti skupnega psa, starš zapusti družino, združevanje družin
- [~] M2-02 Otroški profil brez emaila (**odločeno**): starš ustvari otroka (ime, starost), PIN pairing izda Sanctum token z abilities `child:*`; odstraniti email/geslo prijavo za otroka
  - [x] Starševski zaslon "Dodaj otroka" (obstoječi `POST /api/parent/generate-pin`): PIN `734 912`, odštevanje 15 min, "Nova koda", 429 ohladitev, samodejna potrditev, ko se otrok poveže *(2026-10-03, ista veja)*
  - [x] Backend *(2026-10-04, `feat/M2-02-pin-only-child`)*: `POST /api/parent/children` (vzdevek + neobvezna letnica, brez e-pošte/gesla), `POST /api/parent/generate-pin {child_id, pet_id?}` (načini `new_pet` / `join_pet` / `relogin`), javni `POST /api/child/pin-login` (HMAC PIN, enkraten, 15 min, enak odgovor za napačen/potekel/porabljen, 10 napak/IP + 100 skupaj na 15 min, 10 zahtevkov/min/IP), največ 3 naprave na otroka, `DELETE /api/parent/children/{child}/tokens`; `users.email` / `password` nullable, `users.birth_year`, tabela `child_login_pins`; e-poštna prijava otroka in `POST /api/child/pair` zastarela (delujeta za stare račune); zaupanje Caddyju za IP odjemalca
  - [x] Mobilni zasloni *(2026-10-04, ista veja)*: začetni zaslon "Sem otrok" / "Sem starš"; otrok: velika tipkovnica za 6-mestni PIN → `pin-login` → pogodba ali HUD (napačna koda, 429 odštevanje, brez povezave); starš: "Dodaj otroka" (vzdevek, neobvezna letnica) → "Nov pes" / "Pridruži se psu …" → PIN za tega otroka; "Nova koda za prijavo", "Odjavi vse naprave"; e-poštna prijava otroka odstranjena iz aplikacije
  - [ ] Pretvorba starih otroških računov z e-pošto v PIN profile in odstranitev e-poštne prijave za otroke (ko je nova aplikacija v trgovinah) — **(D)**
- [~] M2-03 Sanctum abilities + Policies namesto ročnih `isParent()` preverjanj
  - [x] Žetoni z ability `parent` / `child` (prijava z e-pošto in PIN prijava), `ability:parent` na `/api/parent/*`, `ability:child` na `/api/child/*`; stari žetoni `*` delujejo naprej; politike ostanejo druga plast *(2026-10-04, `feat/M2-02-pin-only-child`)*
  - [ ] Preostanek: `isParent()` / `isChild()` znotraj servisov (`PairingService`, `FamilyInviteService`, …) zamenjati s politikami; razmisliti o preklicu starih `*` žetonov po izdaji nove aplikacije
- [ ] M2-04 Izbira pasme pred "rojstvom" (starš) → nato pairing; RevenueCat odklep pred izbiro, ne sredi igre
- [x] M2-05 Parent dashboard na pravih podatkih (`/api/parent/dashboard`, `/activities`), live prek Reverb — *sprehodi po dnevih so v `pet_daily_walks` (koraki, cilj, dosežen, bolezen)*
  - [x] Backend *(2026-10-04, `feat/M2-05-dashboard-scoring`)*: `family.children[]` → semafor z razlogi, Care Score, današnje rutine (zamujene s tipom in uro), zadnjih 7 dni, napredek 12 tednov; `family.pets[]` → semafor, metrike, Care Score, današnje rutine, časovnica (20 dejanj z vzdevkom otroka); `GET /api/parent/children/{child}/report?days=7|30|84`; stalno število poizvedb (41 za 2 psa, ne glede na 7 ali 84 dni zgodovine)
  - [x] Mobilni zasloni *(2026-10-04, `feat/M2-05-parent-dashboard-ui`)*: pregled družine s kartico na otroka (semafor z razlogi v slovenščini, Care Score "x od y rutin", "Teden N od 12", današnje rutine z zamujenimi in uro po času družine, zadnjih 7 dni, mini stanje psa), podrobnosti otroka (poročilo 7 / 30 / 84 dni + časovnica z "Naloži več"), Nadzor (hard stop po psu s potrditvijo, tihe ure, starši), svetla tema (ADR-007), živo prek Reverb (kanal na psa) + polling 30 s brez povezave; demo podatki odstranjeni
- [x] M2-06 Semafor po spec: zelena / rumena (> 2 zamujeni rutini danes) / rdeča; definirati "zamujena rutina" na strežniku — David 4. 10. (PRODUCT_SPEC §9 / §11): rutine hrana / voda / čiščenje / sprehod, Care Score, pošten delež; `RoutineLedgerService` + `pet_daily_routines` (zaključeni dnevi) + `pet_status_periods`, `CareScoreService` *(2026-10-04, `feat/M2-05-dashboard-scoring`)*
- [ ] M2-07 Reset po game over / bolezni (starš) + "Breed Downgrade" (brezplačno)
- [ ] M2-08 Brisanje računa (Apple obvezno), izvoz podatkov (GDPR)
- [ ] M2-09 Rate-limit testi, Policies testi
- [ ] M2-10 Registracija / prijava staršev: Sign in with Apple + Google (Socialite / token verify) + email fallback *(prej M2-01; številka prepuščena družinskemu modelu 2026-10-04)*

## M3 — Obvestila, senzorji, plačila (1,5 tedna)

- [ ] M3-01 EAS projekt, `expo-dev-client`, dev build za iOS + Android (Expo Go ne podpira HealthKit / RevenueCat)
- [ ] M3-02 `expo-notifications`: registracija Expo push tokena → `POST /api/devices`; queue jobi za Phase 1 / 2; parent alarm (Phase 3) kot push + Reverb
- [ ] M3-03 Lokalni opomniki po urniku (hranjenje zjutraj / zvečer) — delujejo offline
- [ ] M3-04 iOS: HealthKit (`react-native-health` ali `@kingstinct/react-native-healthkit`) — branje korakov za danes
- [ ] M3-05 Android: Health Connect (`react-native-health-connect`); Google Fit je deprecated
- [ ] M3-06 Sync korakov ob odprtju aplikacije + background fetch (`expo-background-task`)
- [ ] M3-07 RevenueCat SDK (`react-native-purchases`), `appUserID = user.id` (string), Offerings
- [ ] M3-08 Webhook rewrite: tabela `purchases` (idempotentno po `event.id`), `entitlements` na družino, podpora `CANCELLATION` / `REFUND` / `TRANSFER`, fail-closed brez secreta
- [ ] M3-09 Paywall (**odločeno**): mešanček vedno brezplačen; 12-tedenski izziv 49,99 € s 7-dnevnim preizkusom; entitlement `challenge` (BUSINESS_MODEL §7) — **(D)** B7 non-consumable vs. naročnina
- [ ] M3-11 Trial logika na strežniku: `trial_started_at`, konec preizkusa → zaklep plačljivih funkcij, mešanček ostane
- [ ] M3-10 Podpis s prstom (`react-native-signature-canvas` ali Skia) → `POST /api/child/contract`

## M4 — AI mediji (3–4 dni)

- [x] M4-01 `GeneratePetReferenceImage` job (queue, afterCommit, 3 poskusi z zamikom) namesto sinhronega klica v transakciji; `pets.media_status`; popravljen klic slike (`fal.run` namesto `queue.fal.run`)
- [ ] M4-02 Model: referenčna slika (NanoBanana Pro / Flux pro na fal.ai) + Kling (aktualna verzija) image-to-video
- [ ] M4-03 Predgeneriranje 6 stanj (`idle, sleeping, low_energy, hungry, sick, playing`) ob rojstvu → `pet_media` tabela; aplikacija preklaplja lokalno brez novega generiranja
- [x] M4-04 fal webhook: ED25519 podpis (JWKS cache 24 h, ±5 min), format `{status: OK|ERROR, payload}`, `pet_media_jobs` (ujemanje request_id, idempotenca), dovoljeni samo `*.fal.media` URL-ji, `FAL_AI_WEBHOOK_SECRET` odstranjen
- [ ] M4-05 Prenos medijev na lasten storage (Hetzner Object Storage / S3) + CDN
- [ ] M4-06 Fallback: kuratiran nabor statičnih videov na pasmo (če fal odpove ali za demo)
- [ ] M4-07 Kalkulacija stroška na psa + dnevni limit porabe

## M5 — Produkcija in zaprta beta (1 teden)

- [x] M5-01 Strežnik postavljen (Docker Compose + Caddy) — `docs/PRODUCTION_DEPLOYMENT.md`
- [x] M5-02 Domena `api.petprep.si` + avtomatski TLS (Caddy)
- [~] M5-03 Deploy skripta + GitHub Action obstajata; manjka ročna odobritev in delujoč test job (M0-08)
- [~] M5-04 Backup pred vsakim deployem obstaja (7 dni, isti disk) → dodati dnevni cron, off-site kopijo in test obnove
- [ ] M5-05 Sentry (Laravel + RN), uptime monitoring, Laravel Pulse / Horizon
- [ ] M5-06 Politika zasebnosti, pogoji, privolitev staršev, App Store "Kids" / Family policy pregled
- [ ] M5-07 TestFlight + Google Play Internal testing; 20–50 beta družin (waitlist)
- [ ] M5-08 Analitika produkta (PostHog EU): aktivacija, D1 / D7, dokončanje dneva, konverzija paywalla

## Po MVP (backlog)

- Certifikat odgovornosti (PDF) + fizična licenca po pošti
- Breed Matchmaker, "Real Cost of a Dog" kalkulator (tudi kot lead magnet na webu)
- 7-dnevni Puppy Promoter badge (lahko že v M3, če je del paywall toka)
- Second Chance reset (19,99 € consumable)
- IAP ekonomija: veterinar, priboljški, igrače
- B2B affiliate (Mr. Pet) — QR kuponi
- Faza 2: Real-World AI asistent (LLM + RAG, pgvector), IoT ovratnice — spec `docs/product/PHASE2_SPEC.md`; predpogoj: metrika "% družin, ki po izzivu kupijo psa"
- Mačka
