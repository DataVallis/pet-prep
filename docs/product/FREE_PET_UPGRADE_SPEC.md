# PetPrep — nadgradnja brezplačnega ljubljenčka v 12-tedenski izziv

> **Odločitve:** U1–U6 in §3–§4 potrdil David 10. 11. 2026. Popravki istega dne po pregledu kode: Claudovi predlogi K1–K7 potrjeni, plus Davidove spremembe D1–D4 (§2).
> **Tehnični del (§5) je preverjen v kodi** (`origin/main` 8f0f95de, 10. 11. 2026). Datoteke in vrstice so navedene, da jih inženir najde. Pred gradnjo jih preveri še enkrat, ker se koda vmes lahko premakne.
> **Gradnja poteka v dveh PR-jih** (§8): PR 1 je nadgradnja obstoječega ljubljenčka, PR 2 je izziv z mešančkom ob ustvarjanju (U4) in opomnik 7. dan (U5).
> **Stanje (11. 11. 2026): PR 1 (M6-U01) je zgrajen** — strežnik in aplikacija (OTA, brez nove trgovinske gradnje), vključno z »namenom nakupa« (§5.3, David »da« 10. 11. 2026). **Pri uporabnikih od 11. 11. 2026** (produkcijski deploy + OTA za iOS in Android); na napravi še ni preverjeno. **PR 2 (M6-U02) je zgrajen 11. 11. 2026** — »mešanček + izziv« ob ustvarjanju (nove aplikacije) in opomnik 7. dan (§5.6, §5.7 »Kako je zgrajeno«); prav tako pri uporabnikih od 11. 11. 2026, na napravi še ni preverjeno.
> Del načrta validacije (`VALIDATION_PLAN.md` §4.3).

## 1. Zakaj

Danes je brezplačni mešanček (in domača mačka) preizkus aplikacije, plačljivi izziv pa je mogoč samo z drugo, pasemsko živaljo. Otrok se naveže na svojega ljubljenčka, starš pa bi moral za izziv začeti znova z drugim. Nadgradnja to luknjo zapre: **isti ljubljenček in isti otrok, zdaj z 12-tedenskim programom in certifikatom.**

## 2. Odločitve (David, 10. 11. 2026)

| # | Odločitev |
|---|---|
| U1 | **12 tednov teče od nakupa.** Ljubljenček ostane isti: ime, videz, starost, naučeni ukazi, stvari iz »Želja«. Prejšnja zgodovina ostane vidna, a ne šteje v oceno skrbi in certifikat. |
| U2 | **Cena je 49,99 €, isti izdelek** `petprep_challenge_12w`. V trgovinah ni treba dodati ničesar. |
| U3 | Velja za **mešančka in domačo mačko**. |
| U4 | **Izziv je mogoč z vsako pasmo že na začetku**: starš lahko pri novem ljubljenčku izbere »mešanček + izziv«. Mešanček ostane tudi brezplačen peskovnik. To zamenja pravilo M5-F03 (»izziv samo s plačljivo pasmo«, 7. 10. 2026). **Samo za nove aplikacije, starim ostane brezplačen (K3). PR 2.** |
| U5 | **Opomnik staršu 7. dan** (pogoj D1). **PR 2.** |
| U6 | **Nakup med boleznijo ali hard stopom je dovoljen takoj.** Izziv ne čaka na ozdravitev. |
| K1 | Ob nakupu štejejo samo **dnevne** rutine, ki so bile že odprte (okno hranjenja, nered, današnji sprehod), če je njihov rok po nakupu. **Tedenske in mesečne** (česanje, pesek, opravila) se začnejo z naslednjim obdobjem. |
| K2 | **Ocena ljubljenčka na nadzorni plošči** šteje od nakupa, enako kot potrdilo. |
| K3 | U4: neplačan »mešanček + izziv« se ob rojstvu zaklene kot pasemski ljubljenček na izzivu. Izbira je samo v novih aplikacijah; stare jo dobijo kot brezplačnega ljubljenčka. |
| K4 | **Webhook:** nakupa ne dodeli samodejno, kadar ima družina brezplačnega ljubljenčka, ki ga je mogoče nadgraditi. Za katerega ljubljenčka je nakup, vedno pove aplikacija. **Pred spremembo preveriti (D2).** |
| K5 | **Stari mešančki (pred M5, `legacy` profil)** nadgradnje ne dobijo (D3). |
| K6 | **Igra in crkljanje** se odkleneta ob nakupu, kot danes v kodi (D4). |
| K7 | **Ponoven nakup po vračilu** pomeni novih 12 tednov od novega nakupa. |
| K8 | **Ni poti nazaj na brezplačni načrt** (David 11. 11. 2026): nadgrajen ljubljenček ali neplačan »mešanček + izziv« se ne more sam vrniti na brezplačni načrt, enako kot pasemski. Edini poti sta vračilo kupnine (→ brezplačno) in izbris. |
| D1 | Opomnik 7. dan se pošlje, ko je od pogodbe minilo **7 dni** in je otrok vsaj eno dejanje naredil v **vsaj 5 od teh 7 dni** (ne vsak dan). |
| D2 | Pred spremembo webhooka preveriti v kodi, ali **vse** različice aplikacije, ki so pri uporabnikih (trgovinske gradnje in OTA), po nakupu same pokličejo aktivacijo za izbranega ljubljenčka. Če se katera zanaša na samodejno dodelitev, se ustavimo, Davidu predlagamo rešitev in čakamo nanj. **Starš nikoli ne sme plačati in ostati z zaklenjenim ljubljenčkom.** Stanje (10. 11.): **preverjeno — ni varno, kot je bilo predlagano.** Vse različice pokličejo aktivacijo samo, če nakup vidijo v ~8 s (5 poizvedb), brez kasnejšega poskusa; ob zakasnelem webhooku, zaprti aplikaciji ali brez povezave ljubljenčka danes odklene samodejna dodelitev. Predlog »namen nakupa« (§5.3) je David potrdil 10. 11. 2026 (»da«); zgrajen v PR 1. |
| D3 | Staršu starega mešančka **pošteno povemo**, zakaj nadgradnja ni mogoča (kratko besedilo). Gumba ne skrijemo brez razlage. |
| D4 | »Puppy Promoter« ni zgrajen, zato ga ta specifikacija ne vključuje. |

## 3. Pravila

**Kdaj je nadgradnja mogoča**
- Ljubljenček je na brezplačnem načrtu, rojen (pogodba podpisana), aktiven in ni v zavetišču (game over). Ni star mešanček (K5). Game over se z nakupom ne odklene (P7 ostane).
- Med boleznijo ali hard stopom je nakup dovoljen. Izziv se začne takoj in veljajo običajna pravila (U6).
- Kupi samo starš, v starševskem delu, tako kot vse ostale nakupe.

**Kaj se zgodi ob nakupu**
- Načrt ljubljenčka postane izziv, plačan z nakupom. Starš vidi »Plačano« in »Teden 1 od 12«. Brezplačnega preizkusa aplikacija ne ponudi več.
- **Začetek izziva je trenutek nakupa.** Za vse otroke, ki takrat skrbijo za ljubljenčka, izziv teče od tega trenutka. Otrok, ki se pridruži pozneje, začne s svojo pogodbo (kot danes).
- **Starost in življenjsko obdobje se ne ponastavita.** Ljubljenček se stara naprej od rojstva, izziv pa ima svojo uro. Od nakupa štejeta **obe** uri izziva:
  - otrokova: teden N, ocena, potrdilo po 84 dneh;
  - ljubljenčkova: »konec simulacije«.

  Mešanček, starejši od 12 tednov, ob nakupu **ni** »končan«. Vračilo, opozorilo ob izbrisu in potrdilo delujejo kot pri vsakem drugem plačanem izzivu.
- **Ocena skrbi in potrdilo** štejeta samo rutine od nakupa naprej (K1). Bolezni pred nakupom ne odštevajo točk. Ocena ljubljenčka na nadzorni plošči šteje od nakupa (K2).
- **Zgodovina:** omejitev 7 dni za brezplačne izgine. Dnevi pred nakupom so v poročilu vidni z oznako **»pred izzivom«** in niso v oceni.
- **Nova pogodba ni potrebna**, ker jo je otrok že podpisal. Otrok vidi kratko sporočilo »Izziv se je začel! Teden 1 od 12«.
- **AI mediji:** nakup sproži manjkajoče posnetke polnega nabora na skupnem videzu (že zgrajeno, P6 / M4-10). Igra medtem teče z obstoječimi posnetki. Šola in igre z lastnimi posnetki so »v pripravi«, dokler posnetki niso narejeni (obstoječe pravilo).
- Odklenejo se igra in crkljanje (takoj, K6) in potrdilo.

**Po izzivu in posebni primeri**
- **Po 84 dneh** programa od nakupa: potrdilo ali poročilo po obstoječih pravilih. Ljubljenček ostane odklenjen, kot drugi plačani.
- **Vračilo kupnine:** ljubljenček se vrne na brezplačni načrt (obstoječe pravilo za mešančka). Izziv in potrdilo prenehata, shranjeni posnetki ostanejo (P10).
- **Ponoven nakup po vračilu:** novih 12 tednov od novega nakupa (K7).
- **Izbris:** nakup ostane porabljen, velja opozorilo P5.
- **Drugi izziv za istega ljubljenčka** po končanem prvem: za zdaj ne.
- **Nov mešanček z izzivom (U4, PR 2):** obnaša se kot pasemski ljubljenček na izzivu in ob rojstvu čaka na nakup (K3). V izbirniku priporočimo: »Začnite brezplačno, izziv lahko dodate kadarkoli.«

## 4. Aplikacija

**Starš (PR 1)**
- Na kartici brezplačnega ljubljenčka in v podrobnostih otroka je gumb **»Začni 12-tedenski izziv z [ime]«**. Odpre obstoječi plačilni zaslon z besedilom za nadgradnjo:
  - kaj ostane: isti ljubljenček;
  - kaj se doda: 12-tedenski program, potrdilo, celotna zgodovina in vsi posnetki;
  - kdaj se začne: takoj;
  - cena in podatek, da ni naročnine.
- Po nakupu aplikacija pokliče aktivacijo **za tega ljubljenčka**.
- Pri starem mešančku (K5) je namesto gumba kratko pojasnilo (D3).
- V poročilu in zgodovini so dnevi pred nakupom označeni »pred izzivom«.

**Starš (PR 2)**
- V izbirniku novega ljubljenčka mešanček in domača mačka nista več siva pri izbiri izziva (samo nove aplikacije).
- **Opomnik 7. dan (U5, D1):** starš dobi eno obvestilo in kartico na pregledu: »[Vzdevek] že 7 dni skrbi za [ime]. Je čas za pravi preizkus?«
  - Pošlje se enkrat na ljubljenčka, nikoli v tihih urah. Ne pošlje se za zaklenjene ljubljenčke, po game overu ali po nakupu.
  - V obvestilu ni imena otroka: naslov je ime ljubljenčka, besedilo je brez vzdevka. Vzdevek je samo na kartici v aplikaciji.

**Otrok**
- Nič o cenah ali nakupu. Po nakupu vidi sporočilo o začetku izziva in »Teden N od 12« v listu »O tvojem kužku«.

Besedila so EN + SL, za psa in muco.

## 5. Strežnik (preverjeno v kodi 10. 11. 2026)

**5.1 Ura izziva, ločena od rojstva (PR 1)**
- Danes obstajata dve uri in obe se začneta ob rojstvu:
  - **Otrok ↔ ljubljenček:** začetek otroka je `pet_caretakers.started_at`, sicer pogodba, sicer `born_at`, nikoli pred rojstvom. Najdeš ga v `CareScoreService::board` (`CareScoreService.php:101-124`) in `ChallengeCertificateService::startOf` (`:330-347`).
    - Ta ura poganja `progress()` (»teden N«, ~`:501-518`, brez časa zaklepa), `childScore`/`sharers` (`:293-338`, `:602-616`), konec potrdila (`completionAt`, 84 dni = `CHALLENGE_DAYS`) in iskalni SQL potrdila (`:130-131`).
  - **Ljubljenček:** `Pet::virtualAgeInMonths()` (`Pet.php:830-837`) → `hasReachedSimulationEnd()` (`:1014-1017`).
    - Uporabljajo jo zaščita vračila (`ChallengeService.php:298`), `deletionLosesPurchase` (`Pet.php:1089-1095`), stari `certificate_eligible` (`PetDecayService.php:549-566`) in `virtual_age_months` v podatkih za aplikacijo (`PetUpdated.php:175`, `ChildPetStateResource.php:174`, `ParentDashboardController.php:123`, otroški »TEDEN N OD 12« pri starih profilih).
- **Novo:**
  - stolpec `pets.challenge_started_at`, nullable, brez polnjenja obstoječih (null = kot danes);
  - **dejanski začetek izziva otroka** = `max(začetek otroka, challenge_started_at)`. Ta mora biti **ločen ključ** (npr. `challenge_starts`), ki ga uporabljajo `progress`, `childScore`, `startOf` in iskalni SQL (`GREATEST(…)`);
  - obstoječi `starts` (prikaz zgodovine, `dailyRow`, `last_7_days`, deljenje v poročilu) ostanejo, sicer bi dnevi »pred izzivom« izginili;
  - »konec simulacije« in z njim vračilo, izbris in `certificate_eligible` šteje program od `challenge_started_at`, če je nastavljen;
  - `petSummary` (ocena ljubljenčka, `since = born_at` pri `:222`) šteje od začetka izziva (K2).
- **Ne spremeni:** starost, življenjska obdobja in tedenska obdobja (`programBirthAt` / `programPeriodAt`, `Pet.php:966-990`, `LifeStageService`) ostanejo na rojstvu. Novega stolpca **ne** vpletaj v `programBirthAt`.
- `challenge_started_at` dodaj v `PetPlanPayload`, v podatke o napredku in v izvoz podatkov družine.

**5.2 Aktivacija (PR 1)**
- Pot že obstaja: `POST /api/parent/pets/{pet}/challenge/activate` → `BillingController::activate` → `ChallengeCreditService::activate` (`:138-176`).
  - Zaklepa družino, nato ljubljenčka, nato najstarejši nakup.
  - Je idempotentna (`already_active`).
  - Napake: 422 `free_plan` / `already_paid` / `pet_not_active`, 409 `no_credit`.
  - Obvestilo aplikaciji pride iz `markPaid` (`PetUpdated challenge_paid`).
- **Novo:** veja za brezplačnega ljubljenčka pod istimi zaklepi. Pogoji: brezplačna pasma, rojen, aktiven, ni game over, ni star mešanček. Nato:
  - `plan = challenge`;
  - `challenge_started_at = now`;
  - `trial_ends_at = born_at`, sicer `trialAvailable()` vrne true (`:271-278`) in aplikacija ponudi brezplačni preizkus;
  - `assign()`.
- Zavrnitve imajo jasne kode: game over, nerojen, star mešanček.
- `billing()` navede brezplačne ljubljenčke kot »nadgradljive«. Morebitni nov `assigned_via = upgrade` zahteva preverjanje CHECK omejitve.
- Ponoven nakup po vračilu postavi nov `challenge_started_at` (K7).

**5.3 Webhook (PR 1, samo po D2)**
- `ChallengeCreditService::create()` (`:47-68`, kliče ga `RevenueCatWebhookService.php:159`) danes nakup samodejno dodeli, kadar ima družina **natanko enega** neplačanega ljubljenčka na izzivu (`unpaidPets`, `:286-297`). Brezplačnega ljubljenčka nikoli.
- **Tveganje:** družina ima brezplačnega mešančka in enega neplačanega pasemskega ljubljenčka. Starš kupi za mešančka, webhook pa nakup dodeli pasemskemu, zato aktivacija mešančka vrne 409 `no_credit`.
- **Preverjeno (D2):** vse živeče različice (trgovinske 2.0.3+ in vse OTA do `d47f94b2`) imajo enako logiko: po nakupu 5 poizvedb po 2 s (`billing.ts` `pollBilling`), aktivacija samo, če nakup vidijo; sicer »odklepanje lahko traja minuto« in nič več. Edina ročna rešitev je gumb »Uporabi kupljen izziv« na `ChallengeScreen`. Nobena različica ne more kupiti za brezplačnega ljubljenčka. Obnovitev nakupov ne dodeli nič. Zato bi preprosta sprememba K4 lahko pustila plačanega ljubljenčka zaklenjenega.
- **Potrjeno (David »da« 10. 11. 2026) in zgrajeno v PR 1 (11. 11. 2026):** »namen nakupa« — nova aplikacija pred odprtjem plačila pove strežniku, za katerega ljubljenčka kupuje (`POST /api/parent/pets/{pet}/challenge/intent`, na starša, enkraten, 30 min); webhook z namenom dodeli temu ljubljenčku (tudi brezplačnemu → nadgradnja); webhook brez namena (vse stare aplikacije) dela kot danes; nova aplikacija ob zagonu ponudi porabo neporabljenega nakupa; opozorilo na Discord, če nakup > 10 min ostane nedodeljen pri družini z zaklenjenim ljubljenčkom. `assignAvailableBeforeBirth` ostane.

- **Kako je zgrajeno (PR 1):**
  - Tabela `challenge_purchase_intents` (družina, starš = RevenueCat `app_user_id`, ljubljenček, `expires_at` = +30 min, `consumed_at` / `consumed_by` webhook|activate|superseded, kredit). `ChallengePurchaseIntentService` (zaklep družina → ljubljenček, enaki pogoji kot aktivacija, nov namen istega starša zamenja starega), avtorizacija `PetPolicy@buyChallenge` (samo starš družine; otrok 403, druga družina 404).
  - Webhook (`ChallengeCreditService::create`): namen enega od staršev dogodka (`app_user_id`, `original_app_user_id`, `aliases`), ki je veljal ob **času nakupa** (`expires_at` ≥ `purchased_at`, ustvarjen največ 5 min po njem) — tudi zakasneli webhook ga upošteva. Kredit gre temu ljubljenčku (`assigned_via = intent`; brezplačni ljubljenček se nadgradi po isti poti kot aktivacija), namen je porabljen. Če ljubljenček ni več primeren (plačan drugače, game over, izbrisan), se namen šteje, kot da ga ni: velja staro pravilo. Izjema (QA #978, 11. 11. 2026): namen za **brezplačnega** ljubljenčka nikoli ne vzame nakupa edinemu ljubljenčku družine, ki čaka za plačilnim zaklepom (nakup je lahko iz stare aplikacije na drugem telefonu) — ta dobi nakup po starem pravilu, administrator pa dobi opozorilo na Discord (`intent_overridden`), da nadgradnjo uredi ročno. Namen, ki je veljal ob nakupu, zmaga tudi, če ga je kasnejši namen istega starša že zamenjal (zamenjan, brez kredita).
  - Aktivacija istega ljubljenčka zapre odprte namene tega starša (`activate`).
  - Aplikacija: brez uspešnega namena se trgovina ne odpre (`intent_failed`, nič ni zaračunano); po nakupu enkrat pokliče aktivacijo, če ljubljenček še ni plačan (tudi ko čakanje ~8 s poteče; 409 je neškodljiv). Na nadzorni plošči: »Imate neporabljen nakup — izberite ljubljenčka«, ko je `credits_available > 0` in ga lahko kateri ljubljenček sprejme.
  - Varovalo: `billing:unassigned-credits-check` vsakih 5 min — kredit, ki je 10 min po webhooku še nedodeljen (zadnjih 7 dni), v družini z ljubljenčkom `payment_required` ali z namenom nakupa iz ure pred nakupom → eno opozorilo na Discord (`unassigned_challenge_credit`) na kredit; ob kakršni koli napaki se ponovi.
  - Otrok vidi »Teden N od 12« po **svojem** začetku (`pet.challenge_progress` v stanju otroka), ne po uri ljubljenčka — otrok, ki se pridruži kasneje, je v zgodnejšem tednu.
  - Kartico »Imate neporabljen nakup« starš lahko skrije (do naslednjega nakupa); ne pokaže se, ko na nakup čaka še nerojen ljubljenček (P11).
  - Povrnitev migracije zavrne, dokler obstajajo nadgrajeni ljubljenčki.
  - `assignAvailableBeforeBirth` in obnova nakupov ostaneta, kot sta bila.

**5.4 Rutine in ocena (PR 1)**
- Dnevna knjiga rutin teče za vsakega rojenega ljubljenčka, ne glede na načrt (`RoutineLedgerService::closeDueDays`).
  - Zaprti dnevi so v `pet_daily_routines` z `opens_at` / `due_at` in se nikoli ne preračunajo.
  - Odprti se računajo sproti (`Results/Routine.php`, `opensAt` / `dueAt`).
- `childScore` danes filtrira samo po lokalnem datumu (`CareScoreService.php:293-305`). Dodaj filter po trenutku:
  - dnevne rutine štejejo, če je `due_at` po začetku izziva;
  - tedenska in mesečna obdobja štejejo samo, če se odprejo po začetku izziva (K1);
  - enako pri klicu iz potrdila (`ChallengeCertificateService.php:255`).
- Bolezni (−10) se štejejo od začetka. Z dejanskim začetkom izziva bolezni pred nakupom izpadejo same.
- Datumi v pasu ljubljenčka: `Pet::effectiveTimezone()`.

**5.5 Zgodovina 7 dni (PR 1)**
- Omejitev velja po `isFreePlan()` (`FamilyDashboardService.php:41-63, 358-374, 476-483`, `ParentDashboardController.php:141-142, 195-196`) in ob spremembi načrta izgine sama. Podatki niso pobrisani.
- Dodaj oznako »pred izzivom« (polje, npr. `pre_challenge: true` na dnevu ali `challenge_started_at` v podatkih).

**5.6 Pravilo M5-F03 in U4 (PR 2)**
- Pravilo je danes uveljavljeno na več mestih:
  - 422 `challenge_requires_paid_breed`: `PairingService::assertPlanAllowed` (`:302-308`), prek `ChildPinLoginService.php:126-129` / `GeneratePinRequest.php:84-95`;
  - tihi pretvorbi v brezplačnega: `Pet::creating` (`Pet.php:89-94`) in `PairingService::createPet` (`:342-344`);
  - zastavica v katalogu `challenge_allowed = premium` (`BreedCatalogService.php:155-156`);
  - aplikacija: `picker.ts` (`lockedBreedsFor` / `breedLockReason`), `pin.ts`, `AddChildScreen.tsx`.
- `isOfferablePet` (`mobile/src/modules/plan/purchaseEntry.ts`) izloča brezplačno pasmo. **Za nadgradnjo se spremeni že v PR 1.**
- Pri U4 je izbira »mešanček + izziv« dovoljena samo novim aplikacijam (zmožnost odjemalca, kot `species_cat`). Stare aplikacije dobijo brezplačnega ljubljenčka. `defaultPlanFor` in `convertUnpaidMuttToFree` ostaneta.
- **Kako je zgrajeno (PR 2, 11. 11. 2026):**
  - Zmožnost odjemalca `free_breed_challenge` (`ClientFeature::FreeBreedChallenge`). Šteje samo starševa (`features` pri generate-pin, shranjene na PIN-u; `features[]` pri `GET /api/breeds` → brezplačna pasma `challenge_allowed: true`). Otrokova naprava ni pomembna.
  - `PairingService::assertPlanAllowed` 422 samo brez zmožnosti; `createPet` in `Pet::creating` ohranita neplačan izziv brezplačne pasme samo, ko ga je izbrala nova aplikacija (`allowsFreeBreedChallenge` → stolpec `pets.free_breed_challenge`; tak ljubljenček se nikoli ne prikaže kot brezplačen, tudi če ga odklene administrator); vse druge poti (stari PIN-i, `/child/pair`, administracija, tovarne) še vedno naredijo brezplačnega.
  - Tak ljubljenček je navaden ljubljenček na izzivu: `payment_required`, zaklep ob rojstvu, webhook / namen / aktivacija kot pri pasemskem (pravilo PR 1 iz QA #978 b ga šteje kot ljubljenčka na izzivu, ne kot brezplačnega). Vračilo → brezplačni načrt.
  - Aplikacija: izbirnik sledi strežnikovemu `challenge_allowed` in priporoči »Začnite brezplačno, izziv lahko dodate kadarkoli.«; ob preklopu na izziv je izbrana prva plačljiva pasma, mešanček samo, če ga starš tapne (QA #983); `isOfferablePet` sprejme neplačan izziv brezplačne pasme (gumb za nakup).

**5.7 Opomnik 7. dan (PR 2)**
- Vzorec je `PetBirthdayService`: enkrat na ljubljenčka (`*_celebrated_at`), preverjanje v ticku (`*_next_check_at`), datum v `effectiveTimezone()`.
- Nova vrsta obvestila potrebuje:
  - migracijo CHECK omejitve;
  - izjemo v `DogPushTextSnapshotTest`;
  - besedila v `PushCopy`;
  - usmerjanje ob dotiku v aplikaciji.
- Velja samo za starše, zadržano čez tihe ure.
- Pogoj D1 izpelji iz dejanj otroka po dnevih v pasu ljubljenčka. Logike za »N dni zapored« danes ni.
- **Kako je zgrajeno (PR 2, 11. 11. 2026):** `UpgradeReminderService` (stopnja ticka za rojstnimi dnevi), stolpci `pets.upgrade_reminder_sent_at` / `_child_id` / `_next_check_at`, push `upgrade_reminder` (CHECK migracija `2027_03_22_120000_add_upgrade_reminder`, izjema v `DogPushTextSnapshotTest`, `PushCopy` + `push.upgrade_reminder` / `push.cat.upgrade_reminder`), `pets[].upgrade_reminder` na nadzorni plošči (kartica 7 dni), dotik obvestila odpre nadgradnjo. Privzete izbire (DECISIONS 11. 11. 2026): 7 dni = datum pogodbe + 6; en otrok mora doseči 5 dni; podpis pogodbe ne šteje; okno 24 ur, brez opomnikov za nazaj.

**5.8 Mediji (PR 1, že deluje)**
- `markPaid` ob nakupu:
  - poveča `state_media_version` na 3;
  - sproži `queueStateVideos` (`ChallengeService.php:215-243`);
  - stopnja `full` velja samo za nakup (`MediaEntitlementService::tierFor`).
- Ljubljenčki iz skupnega nabora dopolnijo vrstice skupnega videza (`PetMediaService.php:56-66`). To dokazuje `FreePetLookPoolTest.php:350-384`. Njegov pomočnik `lpPurchase` ponaredi menjavo načrta in naj preide na pravo aktivacijo.
- Po vračilu `usesStateMedia` pade na osnovno raven. Shranjeni posnetki ostanejo, a se ne prikazujejo (obstoječi P10).

**5.9 Stare aplikacije**
- Teden izziva in konec računa strežnik, zato stare aplikacije nadgrajenega ljubljenčka prikažejo pravilno.
- Nadgradnje ne morejo začeti. Dni »pred izzivom« ne označijo, kar je sprejemljivo.
- Obstoječi plačani ljubljenčki se ne spremenijo, ker je `challenge_started_at` pri njih null. To dokazuje regresijski posnetek.

**5.10 Ni v obsegu:** nadgradnja iz administracije (`grantByAdmin` zahteva ljubljenčka na izzivu) in Puppy Promoter (D4).

## 6. Testi (najmanj)

**PR 1**
- **Nadgradnja:** načrt, začetek izziva, teden 1, nespremenjeni starost, videz in ime, `trial_available = false`.
- **Mešanček, starejši od 12 tednov, ob nakupu:**
  - ni »končan«;
  - **vračilo** ga vrne na brezplačni načrt;
  - **opozorilo ob izbrisu** pove, da se nakup izgubi;
  - **potrdilo** šele po 84 dneh od nakupa, tudi z zaklepi in prestopom ure.

  To so izrecni testi (David).
- **Ocena:**
  - dnevi pred nakupom ne štejejo;
  - odprta dnevna rutina z rokom po nakupu šteje;
  - tedensko ali mesečno obdobje, odprto pred nakupom, ne šteje;
  - bolezen pred nakupom ne odšteje;
  - ocena ljubljenčka na nadzorni plošči šteje od nakupa.
- **Zgodovina:** dnevi pred nakupom vidni in označeni.
- **Skupni ljubljenček:** vsi skrbniki začnejo ob nakupu; otrok, ki se pridruži pozneje, ob svoji pogodbi.
- **Zavrnitve in posebni primeri:**
  - game over → 422; nerojen → 422; star mešanček → 422;
  - že plačan → idempotentno;
  - nakup med boleznijo ali hard stopom uspe;
  - ponoven nakup po vračilu → nov začetek.
- **Mediji:** pes in muca; skupni videz dopolni manjkajoče posnetke.
- **Regresija:** obstoječi plačani ljubljenčki se ne spremenijo (regresijski posnetek); stare aplikacije dobijo enake podatke kot prej.

**PR 2**
- **Nov mešanček z izzivom:** ob rojstvu zaklenjen, z nakupom odklenjen; stara aplikacija dobi brezplačnega.
- **Opomnik 7. dan:**
  - 5 od 7 dni → pošlje; 4 od 7 → ne;
  - pošlje se enkrat;
  - ne v tihih urah, ne po nakupu, ne zaklenjenim in ne po game overu.

## 7. Kaj je treba popraviti drugje

- petprep.si (cenik, FAQ, pogoji) in opisi v App Store in Google Play: izziv ni več »samo s pasemsko živaljo«. **Objavi šele, ko je funkcija v aplikaciji uporabnikov.**
- `BUSINESS_MODEL.md` §7 (tabela brezplačno / plačljivo), `PAYMENTS_SPEC.md` (P4, pravilo M5-F03), `PRODUCT_SPEC.md` §2, `audiences/*`. Te spremembe pridejo s PR-jem, ki pravilo zares spremeni: nadgradnja s PR 1, U4 s PR 2.
- Stran »Lijak« (VALIDATION_PLAN §4.1) ločeno šteje nakupe z nadgradnjo in nakupe novega ljubljenčka.

## 8. Vrstni red

1. **PR 1 (prednost) — zgrajen 11. 11. 2026 (M6-U01):** nadgradnja obstoječega brezplačnega ljubljenčka (mešanček, domača mačka). §5.1–5.5, 5.8, 5.9 in §4 »Starš (PR 1)« / »Otrok«, z »namenom nakupa« (5.3, D2 potrjen). En krog QA pred združitvijo.
2. **PR 2:** U4 (5.6) in opomnik 7. dan (5.7). En krog QA pred združitvijo.
