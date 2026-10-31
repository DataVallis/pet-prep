# PetPrep — Igre, šola in čiščenje: interaktivni sloj (GAMES_SPEC)

> **Status:** POTRJENO (David 30. 10. 2026, odgovori v §11) — smer, faze in odgovori V2, V5, V6, V10–V12. Spremembe pravil §10 Š1–Š3, C1, P1, P2 **potrjene** (David 30. 10. 2026, V1); v `PRODUCT_SPEC` / `CAT_SPEC` / `PLAY_CUDDLE_SPEC` zapisane 30. 10. 2026 (PR strežnika faz 2–4). **Faza 1 v gradnji** — strežniški del zgrajen 30. 10. 2026 (`feat/games-phase1-backend`: stikalo `users.game_feedback`, izpeljanki `ball_waiting_loop` in `exit_side`); aplikacija zgrajena 30. 10. 2026 na veji `feat/games-phase1-app` (PR #459: igralni sloj, žoga v2, stikalo staršev »Zvoki in vibracija v igrah«; PR #460: neredi in čiščenje) — potrebuje novo gradnjo v trgovini. **Faze 2–4: strežniški del zgrajen 30. 10. 2026** (`feat/games-rules-v2-backend`: `rules: 2` za palico in šolo, zvezdice, krogi, maska, predčasen konec, `training.treat_token`, izpeljanka `wand_crouch_loop`; crkljanje C1 je samo v aplikaciji). **Šola v2 v aplikaciji (faza 3) zgrajena 31. 10. 2026** na veji `feat/games-school-app` (zložena na `feat/games-phase1-app`): §4.1 / §4.2 za `gameLayerAvailable()` — `rules: 2`, 2 kroga × 3, rez pod masko 1,2 s brez `release`, velik čip ukaza + zvok (glas ukaza še ni posnet — V5), priboljšek / »Pohvali« ♥ (V6), »Končaj« v premoru, zaslon rezultata po ukazih; stara gradnja ostane pri šoli v1. Potrebuje novo gradnjo v trgovini. Palica v2 in crkljanje v aplikaciji še nista zgrajena. Pogodba za aplikacijo: `docs/engineering/ARCHITECTURE.md` → *Games rules v2 — contract for the app*.
> **Smer (David 30. 10. 2026):** »**Pes ostane video, igra postane interaktiven sloj.**« Realističen AI video ljubljenčka ostane (81 pasem × 333 barv, premium videz — brez 3D modela in brez risanega ljubljenčka); nad njim zgradimo nativni interaktivni sloj (60 fps, fizikalni predmeti, zvok, haptika).
> **Oznake:** **SPREMEMBA PRAVILA — potrdil David 30. 10.** = obstoječe pravilo iz `PRODUCT_SPEC.md` / `CAT_SPEC.md` / `PLAY_CUDDLE_SPEC.md` se spremeni. **(D)** = Claudov predlog brez spremembe obstoječega pravila. Vse ostalo je opis stanja (s potjo do kode).
> **Povezano:** `PRODUCT_SPEC.md` §5, §11, §13 · `PLAY_CUDDLE_SPEC.md` · `CAT_SPEC.md` §5 · `APP_REFINEMENT_SPEC.md` §3, §6, §10–§12 · `docs/engineering/SCHOOL_SMOOTH_PLAYBACK.md` · `brand/README.md`.

---

## 0. Povzetek predlogov

1. **Cel zaslon za ljubljenčka.** Vse igre in šola tečejo na celozaslonskem videu (danes `variant="card"`), UI je tanek stekleni sloj zgoraj in en velik gumb / kretnja spodaj.
2. **Besedilo zgoraj, nikoli pod palcem.** Največ ena kratka vrstica (≤ 32 znakov), na zaslonu vsaj 2 s; stanje ljubljenčka pokažejo **ikone, zvok in haptika**, ne proza.
3. **Prekrivni odziv (David):** velik animiran ✓ »Bravo!« ob uspehu, mehak ✗ ob zgrešenem, pike napredka zgoraj. Prekrivni sloj hkrati **zakrije menjavo posnetka**.
4. **Veriga posnetkov brez skokov:** vsak posnetek igre se začne in konča v isti »osrednji pozi« (hub), naslednji posnetek je že naložen v drugem predvajalniku, menjava ob prvem izrisanem okvirju, nikoli sredi posnetka; kratek preliv samo med enakima pozama.
5. **Krogi 20–30 s** namesto 60 s; vsak posamezen uspeh je nagrajen takoj (zvezdica), nobene seje »vse ali nič«.
6. **Palica s peresom (muca):** en lov ~25 s s tremi skoki in ulovom; **izmik šteje samo kot odziv na viden znak** (muca se pritaji → pero miruje, muca skoči → pero zbeži); spamanje ne prinese nič. Popravi dokazano napako v `WandPlayService::score`.
7. **Šola:** vaja = 2 kroga po 3 ukaze; namesto gumba »Pohvali« velik priboljšek, ki odleti h kužku — **samo če je družina kupila priboljške (»Želje«), sicer ostane »Pohvali« z ♥** (David 30. 10.); »vstane« (obrnjen posnetek, videti kot previjanje traku) zamenja prekrivni odziv.
8. **Žoga:** pravi met s fiziko, žoga odleti na stran, kamor kuža steče, kuža se vrne, otrok ga **pokliče** in **ujame žogo** — 3 meti ostanejo, a vsak ima tri dejanja.
9. **Čiščenje:** jasne, različne ilustracije za kakec, lužo, copat, mačji pesek in praskanje; pobiranje z vrečko, brisanje s krpo, zajemanje z lopatko — z zadovoljivo animacijo.
10. **Brez novih AI posnetkov** (0 $): vse deluje z obstoječimi posnetki + brezplačnimi izpeljankami ffmpeg; trije izbirni novi posnetki so našteti s ceno (§8.3). Novi sloj potrebuje **nove nativne knjižnice → nov EAS build** (ne pride z OTA).

---

## 1. Popis današnjega stanja (iz kode, `main` @ b721460)

### 1.1 Skupno: posnetki faz in poze
- `backend/app/Enums/PetPhaseClip.php`: 18 posnetkov faz — šola `school_<sit|come|place|potty>_<attention|performing|success>`, žoga `ball_<waiting|running|fetching>`, palica `wand_<stalking|pouncing|catching>`. Dolžine: `config/media.php` `state_media.clip_seconds` — šola 3 s, **performing 5 s**, igre 3 s. Kling 3.0 Pro: 3 s = 0,336 $, 5 s = 0,56 $ (ARCHITECTURE »School and game phase clips«).
- Začetna / končna slika = poza (`startPose()` / `endPose()`; `null` = referenčna slika, stoječa poza). Poze: `backend/app/Enums/PetPose.php` — pes `sit`, `come`, `place`, `door`, `empty` (prazna soba); muca `crouch`, `catch`. Nano Banana Pro Edit, 0,15 $ na sliko. `PetMedia::frames()` (`backend/app/Models/PetMedia.php`) vrne začetni / končni okvir posnetka.
- **Posnetki z isto začetno in končno pozo** (`sharesFrames()`: `school_*_attention`, `school_*_success`, `ball_waiting`, `wand_pouncing`) se pošljejo **samo z začetno sliko** (Kling z isto sliko na obeh koncih zamrzne) → **zadnji okvir ni zagotovljeno enak prvemu**. To je eden od virov skokov.
- Izpeljani posnetki (ffmpeg, 0 $, `SchoolClipDerivativeService`, SCHOOL_SMOOTH_PLAYBACK §A): `school_<cmd>_attention_loop` in `_success_loop` (naprej + nazaj, brezšivna zanka), `school_<cmd>_release` (performing **nazaj**, ≤ 2,5 s).
- Varčni posnetki (`MediaEntitlementService::FRUGAL_PHASE_SOURCES`, stikalo `frugal_clips`, privzeto izklopljeno, samo pes): pozornost za pridi / prostor / potrebico = posnetek pozornosti »sedi«.
- Aplikacija: `mobile/src/modules/petMedia/phasePlayer.ts` (kateri posnetek kdaj), `mobile/src/modules/training/SchoolStage.tsx` (šola: do 4 trajni predvajalniki, menjava ob `onFirstFrameRender`, brez prelivanja). **Žoga in palica ne uporabljata odra** — `PetMediaView phase={…}` z novim ključem za vsako fazo → predvajalnik se naloži šele ob menjavi, vmes se pokaže `still` = **končna** poza faze.
- Odvisnosti (`mobile/package.json`): `react-native-reanimated` 4, `react-native-worklets`, `react-native-svg`, `expo-video`. **Ni** `react-native-gesture-handler`, `@shopify/react-native-skia`, `expo-haptics`, `expo-audio`. Kretnje = RN `PanResponder`, animacije = RN `Animated`.

### 1.2 Šola (pes): sedi, pridi, prostor, lulat zunaj
- **Pravila** (`backend/app/Services/TrainingService.php`, PRODUCT_SPEC §5): `TRIALS_PER_SESSION` 6, `LEAD_IN_MS` 2000, premislek `THINK_MIN/MAX_MS` 300–1500, `PRAISE_WINDOW_MS` 1500, `MIN_REACTION_MS` 150, `CALM_MS` 700, `IGNORE_SLOT_MS` 4500, release `R` ≤ 2500 (`MAX_RELEASE_MS`), kuža uboga z verjetnostjo 0,5 → 0,9 glede na napredek (vsaj en ukaz uboga, vsaj enega ne). Ocena `TrainingService::score`: **prvi pritisk v rezini ukaza odloči** → `in_time` / `too_early` / `too_late` / `no_praise` / `waited` / `praised_without_obeying`. Napredek = `in_time` × 1 % × pasma × osebni faktor × 8/6. Dnevni čas **7 min** na psa, pošten delež med otroki; **rutina »Šolanje« = ena dokončana vaja na dan** (ne glede na rezultat); −2 % na ukaz za dan brez vaje. Sumljivo enakomerne latence se samo zabeležijo (`looksScripted`).
- **Potek** (urnik strežnika, `schoolTimeline` v `phasePlayer.ts`): uvod 2 s → ukaz → `attention_loop` (premislek) → `performing` od okvira 0 (5 s) → `obey_at` → `success_loop` skozi okno pohvale 1,5 s → `release` (obrnjen, ≤ 2,5 s) → mirno 0,7 s → naslednji ukaz. Prezrt ukaz: `attention_loop` 4,5 s. Vaja ~33–70 s.
- **Posnetki:** 12 (3 na ukaz; z varčnimi 9) + 12 izpeljanih (0 $) + poze `sit`, `come`, `place`, `door`.
- **Besedila** (`mobile/src/i18n/locales/{sl,en}/training.json`, `TrainingOverlay.tsx#dogLine`, `game.ts#frameAt` / `feedbackAt`): vrstica psa »Kuža posluša …« / »Your pup is listening …« (tudi **med** 5-s izvajanjem, ko kuža že vidno sede), ob `obey_at` »Kuža se usede.«, prezrt ukaz po 0,8 s »Kuža voha naokrog …« / »sniffing around …« (pridi »ostane, kjer je«, prostor »se raje igra«, potreba »gleda metuljčka«); povratna vrstica »Bravo, ob pravem trenutku!« / »Malo prepozno …« / »Super, da si počakal(a) …« (`FEEDBACK_CARRY_MS` 1,5 s); oblaček ukaza »Sedi!«; zgoraj »Ukaz n od 6« in »še N s«.
- **Roke:** otrok **pritiska en velik gumb »Pohvali«** (`onPressIn`, čas dotika stekla). Nič drugega.
- **Postavitev:** oder `flex: 1, minHeight: 180`, zaobljena kartica; vrstica psa in povratna vrstica sta **pod** odrom, tik nad gumbom.

### 1.3 Žoga (pes)
- **Pravila** (`PLAY_CUDDLE_SPEC.md` §4.2, `backend/app/Services/PlayService.php`, `config/play.php`): samo plačan izziv; prosta igra brez omejitve; vabilo 1× na dan; po igri 30 min »vesel«; **brez točk, rutin, Care Score**. Aplikacija ob koncu pošlje `POST /api/child/pet/play {kind: "play"}`.
- **Potek** (`mobile/src/modules/play/PlayOverlay.tsx#BallGame`, `play.ts`): **3 meti = konec** (`BALL_THROWS`); met = poteg navzgor ≥ 60 pt ali hiter poteg (`isThrowRelease`). Žoga (zelen krog 72 pt) odleti **navzgor in nazaj** (`ballFlightSteps`, skupaj `BALL_CLIP_FETCH_MS` = 6 s); posnetki `ball_waiting` → `ball_running` (iz »stoji« v prazno sobo) → po fiksnih 3000 ms `ball_fetching` (prazna soba → »stoji«).
- **Besedila** (`play.json`): »Povleci žogo navzgor, da jo vržeš.«, »Kuža teče po žogo …« (ves čas meta), »Met n od 3«, »Hvala za igro! Kuža je ves vesel.«
- **Roke:** en poteg navzgor na met; z »Lažjimi kretnjami« (M5-T17) pridrži → moč 1,2 s → spust.

### 1.4 Crkljanje (pes in muca)
- **Pravila:** kot žoga (mood only, `kind: "cuddle"`). **5 potegov** po ≥ 60 pt (`CUDDLE_STROKES`, `STROKE_MIN_PT`) ali držanje 3 s (`HOLD_MS`).
- **Posnetki:** samo stanje `playing` / `idle` + srčki (`HeartsLayer.tsx`).
- **Besedila:** »S prstom nežno pobožaj kužka.«, »Poteg n od 5«, »Drži in pobožaj« / »Hold to cuddle«, »Kuža uživa. Hvala za crkljanje!«

### 1.5 Palica s peresom (muca)
- **Pravila** (`backend/app/Services/WandPlayService.php`, `backend/config/wand.php`, CAT_SPEC §5.2, PRODUCT_SPEC §13): igra **60 s** (David 8. 10.), konec z »ulovom«; cilj 2 / dan (mucek 3), **2 h** med uspešnima igrama; merilnik »Igra« (`energy_level`); **rutina »Igra«** v Care Score; zamujen dan → naslednji dan »opraskala je kavč«. Ocena `WandPlayService::score` — igra šteje samo, če velja **vse**: ≥ 8 premikov »stran« (`min_away_moves`), vsaj en v vsaki od 4 četrtin (`segments`), ≥ 50 % premikov stran, **po vsakem skoku premik stran v 2 s** (`pounce_window_ms`), razmiki niso strojno enakomerni (`uniform_*`). Premiki bližje kot 300 ms se štejejo enkrat. Skoki: 3–5 naključno, ne v prvih / zadnjih 8 s.
- **Potek** (`mobile/src/modules/catCare/WandOverlay.tsx`, `catGames.ts`, `phasePlayer.ts#wandMomentAt`): `wand_stalking` (stoji → pritajena) → med skoki **mirna slika** pritajene poze (`clip: null`) → `wand_pouncing` ob skoku → `wand_catching` zadnji 2 s. Aplikacija iz prsta naredi en premik na potezo (`moveWandStroke`).
- **Besedila** (`cat.json` `wand.*`): »Muca opazuje pero …« → »Pripravlja se na skok …« (**0,7 s**, `CROUCH_MS`) → »Skok! Umakni pero!« (**0,8 s**, `POUNCE_MS`) → »Zdaj ga bo ujela …«; povratne vrstice 1,5 s (`WAND_FEEDBACK_MS`); razlog neuspeha šele po minuti.
- **Roke:** vlečenje peresa po odru; »Lažje kretnje«: pero beži samo (premik vsakih 0,56–0,90 s).

### 1.6 Ostale otroške mini-igre (kratko)
- **Česanje** (muca + pes, `ChoreOverlay` / `BrushArea`, `config/cat_care.php`): 30 s, ≥ 10 potez v 3 korakih, strgalna srečka. **Menjava peska:** 30 s drgnjenja. **Nega pasme** (`CareTaskOverlay.tsx`, `config/care_tasks.php`): zobki / gube / ušesa 30 s krtača, kremplji 8 tapov v 12 s. **Praskanje** (`ScratchingOverlay.tsx`, `cat_care.scratching`): muca pristane na praskalniku 0,8–2 s po začetku, pohvala 150 ms–3 s.
- Vse so že ≤ 30 s, točkovanje je »dovolj potez, razporejenih« — niso predmet prenove; dobijo samo skupni sloj (§7.7).

### 1.7 Neredi in čiščenje
- **Kakec** (`mobile/src/components/CleaningOverlay.tsx`, `mess="poop"`): 5 naključnih **rjavih krogov** (`rgba(180,83,9,.85)`) na rjavem zaslonu; tap = madež izgine; »Pospravi za kužkom!«, »Tapni madeže, da jih zdrgneš.«
- **Luža mladička** (`mess="accident"`): 5 **bledo rumenih elips**; »Ups, luža! Pobriši jo.« Prizor: `PuddleGraphic` (`BehaviourGraphics.tsx`) ali video `accident`.
- **Copat** (`chewing`): `ChewedSlipperGraphic`; gumb »Pospravi in daj igračo« (brez mini-igre).
- **Mačji pesek** (`ScoopOverlay.tsx`): `TrayGraphic` + 2–6 temnejših »grudic« (tap = pobrana). **Nered zraven peska**: isti `CleaningOverlay` (`mess="litter"`).
- **Praskanje:** `ScratchedSofaGraphic` + praskalnik (§1.6).
- Strežnik: `POST /api/child/pet/clean` (en klic po koncu), `…/litter/scoop`, pravila nereda v PRODUCT_SPEC §5 / §11 — **ta spec jih ne spreminja**.

---

## 2. Težave

### 2.1 Davidov test (posnetki zaslona 30. 10. 2026, mladiček doberman)
| Kje | Kaj | Vzrok v kodi |
|---|---|---|
| Šola | Kuža je majhna kartica; otrok samo pritiska »Pohvali«. | `TrainingOverlay` `styles.stage` (kartica), en `Pressable`. |
| Šola | Vrstica stanja se menja ~vsako sekundo, povratna vrstica tudi. | `dogLine` po `frameAt` (getReady → listening → obeys → listening …), `IGNORE_AFTER_MS` 800, `FEEDBACK_CARRY_MS` 1500, `CUE_VISIBLE_MS` 1500. Med 5-s izvajanjem piše »posluša«, čeprav kuža že sede (krši APP_REFINEMENT §0 pravilo 1). |
| Šola | Posnetek skače stoji → sedi → stoji. | `release` = **obrnjen** `performing`: pri sedi je videti kot »vstajanje nazaj«, pri **pridi / prostor / lulat zunaj kuža hodi vzvratno** — isti učinek »previjanja traku« kot `sleeping_to_idle` (tega David odstranjuje ločeno). Brez izpeljanih posnetkov: trd rez. |
| Šola | Edini znak je majhen oblaček »Sedi!«. | `styles.cueBubble`. |
| Žoga | Majhen zelen krog, poteg navzgor; žoga ne ustreza videu. | Žoga leti **gor in nazaj** 6 s, kuža pa steče **iz kadra na stran** (`PetPhaseClip::BallRunning` »sprints out of the frame to the side« — stran ni znana). |
| Žoga | Kuža pol iz kadra, nato nenadoma stoji. | Vsaka faza = nov predvajalnik (`PetMediaView` s ključem na fazo) → med nalaganjem `still` = končna poza (`fetching` → referenca = stoji). Preklop po fiksnih 3000 ms (`PHASE_CLIP_MS`), ne po dejanski dolžini / koncu posnetka. `ball_waiting` ima `loop: false` → med merjenjem kuža **zamrzne**. |
| Žoga | Besedilo vedno »Kuža teče po žogo …«; 3 meti in konec. | `status` v `BallGame`; `BALL_THROWS`. Med 6 s meta otrok nima česa početi. |
| Crkljanje | »Drži in pobožaj«, poteg 1–5 od 5. | Števec brez občutka; ni naučnega sporočila. |
| Prehod | `sleeping_to_idle` obrnjen = previjanje traku. | Odstranjuje se ločeno (Frugal clips B). |
| Neredi | Ikone nejasne (»krogeci in tulci za iztrebke«). | `CleaningOverlay` riše enake pike za kakec, lužo in nered pri pesku; `TrayGraphic` grudice. |

### 2.2 Testerji — palica s peresom
Ni zabavno; pogosto ne šteje; vsak poskus traja minuto; besedilo je pod sliko (palec ga prekrije); ni jasno, kaj je dober ulov in kdaj umakniti pero; tudi dosledna igra ne šteje; **napaka: z metanjem hitrih potegov peresa iz zaslona celo minuto dosežeš boljši rezultat**; boljše bi bilo z jasno animacijo; stanje muce se menja prehitro tudi za odraslega.

Potrjeno v kodi:
- **Vse ali nič:** en zgrešen skok (`$pouncesHit < count($pounces)` → `missed_pounces`) podre celo minuto; razlog otrok izve šele na koncu.
- **Spam zmaga:** potegi stran vsakih ≥ 300 ms izpolnijo vse pogoje — ≥ 8 stran, vse četrtine, 100 % stran, v 2 s po vsakem skoku je skoraj zagotovo premik; `too_uniform` ujame samo popolnoma enake razmike (±60 ms), človeški spam ni enakomeren. Poteg, ki se konča izven zaslona, šteje (`moveWandStroke` meri le razdaljo od muce).
- Stanje muce: »pritajena« 0,7 s, »skok« 0,8 s, povratna vrstica 1,5 s — besedilo se menja hitreje, kot ga otrok prebere.
- Med skoki mirna slika (`wandMomentAt` → `clip: null`) — muca zamrzne.
- Ulov je samodejen v zadnjih 2 s, ne glede na otroka — »dober ulov« ni nekaj, kar otrok naredi.

### 2.3 Dodatno najdeno v kodi
1. `wand_pouncing` in `ball_waiting` sta poslana samo z začetno sliko → konec ni nujno na osrednji pozi; ob naslednji menjavi je skok.
2. Žoga: `PHASE_CLIP_MS` 3000 je privzeta dolžina; strežnik pošilja dejansko (`media.phase_seconds`), igra je ne uporabi.
3. Šola: »Ukaz n od 6« + »še N s« + pike + vrstica psa + povratna vrstica + oblaček = **šest** sočasnih virov besedila.
4. Ni zvoka in haptike nikjer (`expo-haptics` ni nameščen; PLAY_CUDDLE §4.3).
5. Palica: v načinu »Lažje kretnje« pero beži samo vsakih 0,56–0,90 s — z novim pravilom »miruj pred skokom« (§5.3) mora samodejno beganje ob pritajitvi počakati.

---

## 3. Načela

### 3.1 UX (potrdil David 30. 10. 2026)
- **Besedilo nad igralnim poljem**, nikoli pod palcem. Spodnjih ~30 % zaslona je za roko (gumb, žoga, pero).
- **Največ ena vrstica**, ≤ 32 znakov, na zaslonu **≥ 2 s** (vrsta sporočil; novo zamenja staro šele po 2 s — obstoječe pravilo za sporočila HUD ostane 2,5 s + 60 ms / znak, APP_REFINEMENT §12).
- **Ikone, zvok, haptika** namesto proze o stanju (»posluša«, »voha«, »pripravlja se«).
- **Krogi 20–30 s.** Vsak uspeh takoj nagrajen (✓ + zvok + haptika + zvezdica). Nikoli seja »vse ali nič«.
- **6–10 let:** en cilj na krog, ena kretnja naenkrat, pravilo igre v ≤ 2 kratkih vrsticah pred začetkom, simboli namesto številk (pike, zvezdice).
- **Učna točka ostane:** pes — **pohvali v pravem trenutku** (ko uboga, ne prej, ne pozneje); muca — **plen beži stran, muca na koncu »ujame«** (Cats Protection [C11]; Ontario SPCA: »Near the end of play sessions, let your cat ›make a kill‹ … avoid frustration« [G1]); crkljanje — **pravilo 3 sekund**: boži 3 s, nato počakaj, ali želi še (Humane Society of Huron Valley [G2]).
- **Nikoli kazni ali sramote:** zgrešeno = mehak ✗ v jantarni barvi (ne rdeči, ne malinovi), brez negativne haptike, takoj »poskusi znova«.

### 3.2 Veriga posnetkov
1. **Osrednja poza (hub):** vsak posnetek igre se začne in konča v osrednji pozi igre — šola in žoga: **stoji** (referenčna slika); palica: **pritajena** (`crouch`); crkljanje: zanka `playing` / `idle`. Pozne slike že obstajajo kot začetni / končni okvirji (`PetMedia::frames`).
2. **Zanke osrednje poze so izpeljane** (naprej + nazaj, ffmpeg, 0 $) — začetek = konec je zagotovljen tudi za posnetke, poslane samo z začetno sliko.
3. **Prednalaganje:** vsi posnetki igre so ob odprtju igre naloženi v trajnih predvajalnikih (posplošen `SchoolStage` → `ClipDeck`, §8.1); menjava = takojšen preklop na že izrisan predvajalnik ob njegovem **prvem izrisanem okvirju** (`onFirstFrameRender`); nikoli nalaganje med igro. Ura igre začne teči šele, ko je vse pripravljeno (obstoječe »Kuža se pripravlja …«, omejeno na 12 s → način »Slika«).
4. **Nikoli rez sredi posnetka, ki gre od poze do poze:** dogodek se postavi v vrsto do konca posnetka. Izjema so zanke osrednje poze: iz njih se sme kadarkoli preklopiti s **prelivom ≤ 200 ms**, ker je vsak njihov okvir ≈ osrednja poza.
5. **Prelivi samo med enakima pozama** (nikoli stoji ↔ leži — to je dalo »dvojnega psa«, SCHOOL_SMOOTH_PLAYBACK diagnoza 1).
6. **Neizogiben rez zakrije prekrivni odziv** (✓ / ✗ / zvezdica / premor kroga): rez se zgodi samo, ko prekrivni sloj stoji ≥ 85 % prekrivnosti.
7. **Pod posnetkom nikoli končna poza** — `still` = začetni okvir posnetka (že pravilo šole, velja za vse igre).

---

## 4. Pes

### 4.1 Šola — skupni potek za vse štiri ukaze

**Cilj in učna točka:** otrok vidi, da pes nauči ukaz, ko ga **pohvalimo takoj, ko uboga** — prezgodnja pohvala ne pomaga, prepozna je zamujena, ko ne uboga, počakamo. Ukaz psu »da« aplikacija; otrokova naloga je pravočasna nagrada.

**Struktura vaje:** **2 kroga po 3 ukaze**, med njima premor 2,5 s. Ukazov ostane 6 (TRIALS_PER_SESSION).
> **SPREMEMBA PRAVILA — potrdil David 30. 10. (Š1):** vaja dobi premor po 3. ukazu (`ROUND_BREAK_MS` 2500, del urnika strežnika). Število ukazov, okno pohvale, verjetnost ubogljivosti in napredek ostanejo.

**Potek kroga (časi so urnik strežnika, aplikacija jih ne preračunava):**

| Čas | Ljubljenček (posnetek) | Prekrivni sloj / zgoraj | Otrok |
|---|---|---|---|
| uvod 0–2 s (samo 1. krog) | `attention_loop` (zanka) | vrstica »Ko uboga — takoj priboljšek!« (2 s); pike ○○○ ○○○ | gleda |
| ukaz `cue` | `attention_loop` | **velik čip ukaza** zgoraj (»Sedi!«, Bricolage 800, 34 pt) + glas ukaza (posnet zvok, sl / en); čip ostane do `obey_at` | — |
| `cue` … `perform_at` (0,3–1,5 s) | `attention_loop` | ikona 👂 (posluša) ob čipu | čaka |
| `perform_at` … `obey_at` (= dolžina `performing`, 5 s) | `performing` od okvira 0, enkrat | ikona 👂 ostane; **brez besedila** | čaka, opazuje |
| `obey_at` … `window_end` (1,5 s) | `success_loop` | čip ukaza izgine (to je viden znak »zdaj«); bralnik zaslona: »Kuža sedi.« | **tapne priboljšek** |
| ob tapu | — | ✓ »Bravo!« (mint, 1,0 s), priboljšek odleti v loku do psa, zvok »hrust«, haptika *success*; pika → ✓ | — |
| tap prezgodaj (pred `obey_at + 150`) | (teče naprej) | ✗ »Prezgodaj« (jantarno, 0,9 s), ikona ⏳ | — |
| tap prepozno / brez tapa | — | ob `window_end`: ✗ »Zamujeno« (0,9 s) ali ob tapu ✗ »Malo pozno« | — |
| `release_at` (= `window_end`) … +1,2 s | **rez** `success_loop` → `attention_loop` **pod masko** | maska: zadnji odziv (✓ / ✗) ostane gor, ozadje zatemnjeno ≥ 85 %, nato izgine v 250 ms | — |
| +0,7 s mirno | `attention_loop` | — | — |
| **prezrt ukaz** `cue` … `cue + 4,5 s` | `attention_loop` (kuža se ozira) | čip ukaza 1,5 s; od `cue + 1,6 s` (po najdaljšem premisleku) ikona 🦋 / 👃 in vrstica »Ne uboga — počakaj.« (≥ 2,9 s) | počaka |
| konec prezrtega ukaza | `attention_loop` | ✓ majhen »Prav, da si počakal(a)!« (1,0 s) ali ✗ »Ni ubogal — počakaj« (če je tapnil) | — |
| premor po 3. ukazu (2,5 s) | `attention_loop` | kartica »1. krog ✓« + zvezdice kroga (npr. ★★☆), mint konfeti (brez pri »zmanjšaj gibanje«) | — |

**Nadomestilo za »vstane«:**
> **SPREMEMBA PRAVILA — potrdil David 30. 10. (Š2):** »vstane« (`school_<cmd>_release`, obrnjen `performing`, David 28. 10. 2026) se **ne predvaja več**; namesto njega trd rez `success_loop` → `attention_loop` pod masko odziva. V urniku `R` = `MASK_MS` 1200 (prej dolžina release ≤ 2500). Razlog: obrnjen posnetek pri pridi / prostor / lulat zunaj kaže psa, ki hodi vzvratno (previjanje traku). Izpeljani posnetki `release` ostanejo shranjeni (0 $), da je odločitev obrnljiva. Če maska ne zadošča, glej izbirni posnetek A (§8.3).

**Kretnja:** velik žeton **priboljška** (kost, 96 pt) spodaj na sredini; **tap** (čas = dotik stekla, kot danes `onPressIn`). Vlečenje priboljška do psa bi dodalo zamik in bi bilo za počasnejše prste krivično — zato je kretnja tap, animacija leta je odziv po tapu. Po tapu je žeton 600 ms siv (vizualno; točkuje se prvi tap, kot danes).

**Točkovanje (nespremenjeno):** prvi tap v rezini ukaza odloči (`TrainingService::score`); spam (tapkanje ves čas) da `too_early` → brez napredka — pravilo je že odporno proti spamu. Odziv v aplikaciji je zrcalo (`game.ts#liveOutcome`), končni rezultat da strežnik.

**Zvezdice (D):** ena zvezdica na ukaz, ko je izid `in_time` ali `waited`. Samo prikaz — naslov rezultata ostane po pravilu M5-F04 (»Odlično! / Dobro! / Še malo vaje / Tokrat ni šlo«).

**Predčasen konec:**
> **SPREMEMBA PRAVILA — potrdil David 30. 10. (Š3):** otrok lahko po **1. krogu** konča (»Končaj«). Pravočasne pohvale 1. kroga štejejo v napredek (× 8/6 kot doslej); porabljen čas = dejanska dolžina; **rutina »Šolanje« šteje samo dokončana vaja (oba kroga)**. Danes se nedokončana vaja ne šteje nič (`training_session_not_over`).

**Dnevne omejitve in merilniki (nespremenjeni):** 7 min / pes / dan, pošten delež, ena vaja hkrati, rutina = ena dokončana vaja, −2 % za dan brez vaje, učinki potty / place. Trajanje vaje: ukaz, ki ga uboga, ≈ 0,3–1,5 + 5 + 1,5 + 1,2 + 0,7 = **8,7–9,9 s**, prezrt 4,5 s → krog **~14–30 s**, vaja **~35–64 s** (danes 33–70 s) → v 7 min še vedno ~6–8 vaj.

**Besedila (vse ostalo so ikone):**

| Ključ | SL | EN |
|---|---|---|
| `school.lead` | Ko uboga — takoj priboljšek! | When it obeys — treat right away! |
| `school.ignores` | Ne uboga — počakaj. | Not obeying — wait. |
| `school.fb.inTime` | Bravo! | Great job! |
| `school.fb.early` | Prezgodaj | Too early |
| `school.fb.late` | Malo pozno | A bit late |
| `school.fb.missed` | Zamujeno | Missed it |
| `school.fb.waited` | Prav, da si počakal(a)! | Good waiting! |
| `school.fb.notObeyed` | Ni ubogal — počakaj | It didn't obey — wait |
| `school.round` | {{n}}. krog ✓ | Round {{n}} ✓ |
| `school.finishEarly` | Končaj | Finish |
| `school.treatA11y` | Daj priboljšek | Give a treat |

Obstoječi ključi `commands.*.cue` ostanejo (čip); `listening`, `obeys`, `ignores` (proza) se v igri ne uporabljajo več (ostanejo za bralnik zaslona).

**Posnetki:** vse **obstoječe** — `attention_loop`, `performing`, `success_loop` (izpeljani + izvorni). Novih ni.

**Dostopnost:** tap je že najlažja kretnja — »Lažje kretnje« ne spremenijo ničesar. Bralnik zaslona: ob `obey_at` napove »Kuža sedi.« (iOS `announceForAccessibility`, Android `accessibilityLiveRegion`), žeton je gumb »Daj priboljšek« (`onPress` brez dotika = čas aktivacije). »Zmanjšaj gibanje«: priboljšek ne leti (bleskne pri psu), ✓ brez povečave, brez konfetov.

### 4.2 Šola — posebnosti ukazov
| Ukaz | Poza (`PetPose`) | Kaj vidi otrok | Opomba |
|---|---|---|---|
| **Sedi** | `sit` | kuža se usede | rez nazaj na »stoji« pod masko (prej vstajanje nazaj) |
| **Pridi** | `come` | kuža pride do kamere | rez nazaj na »stoji« pod masko (prej vzvratna hoja) |
| **Prostor** | `place` | kuža gre na blazino in leže | rez pod masko (prej vzvratna hoja z blazine) |
| **Lulat zunaj** | `door` | kuža gre k vratom | čip »Lulat zunaj!«; pohvala pri vratih; rez pod masko |

Pravila (verjetnost, napredek, učinki potty / place) se med ukazi ne razlikujejo — nespremenjeno.

**Aplikacija (faza 3, zgrajeno 31. 10. 2026, `mobile/src/modules/training/schoolV2/`).** Podrobnosti, ki jih spec ni določil, odločil Claude (čakajo Davida): (1) pravilo »Ko uboga — takoj priboljšek!« je zgoraj že med nalaganjem posnetkov, da se njegovi 2 s ne prekrivajo s prvim ukazom; (2) v premoru po 1. krogu žetona ni (tap v premoru bi pri prezrtem 3. ukazu štel kot »ni ubogal«), namesto njega gumb »Končaj«, ki je viden samo, dokler ga strežnik še sprejme (ocena: čas od zahteve za začetek do začetka ure + en povratni čas); če ga strežnik zavrne (`training_round_two_started`) ali ni povezave, 2. krog teče naprej in vaja se na koncu shrani cela (»Še 2. krog — kar naprej!«); (3) v načinu »Slika« med `performing` ostane stoječa slika (poza šele ob `obey_at`, sicer bi bila namig za prezgodnjo pohvalo); (4) zvok ukaza je lasten `cue.wav` (dva tona), »hrust« je `crunch.wav` (samo pri priboljšku; pri ♥ zvonček); (5) ikona prezrtega ukaza: sedi / pridi 👃, prostor 🎾, lulat zunaj 🦋 (kot besedila `commands.*.ignores`); (6) haptika samo ob uspehu in ob ukazu (*selection*) — ob zgrešenem nič.

### 4.3 Žoga

**Cilj in učna točka:** igra prinašanja je gibanje in vez; pravi pes prinese, ko ga **pokličemo** in **pohvalimo, ko se vrne**; žogo vržemo, ko kuža čaka (ne skače po nas). Brez točk (pravilo M5-R05 ostane).

**Krog = 3 meti** (`BALL_THROWS` ostane — ni spremembe pravila), ~25–30 s; vsak met ima tri dejanja: **vrzi → pokliči → ujemi**.

| Čas (od meta T) | Ljubljenček | Sloj | Otrok |
|---|---|---|---|
| pred metom | `ball_waiting_loop` (izpeljana zanka, kuža poskakuje na mestu) | žoga v spodnji tretjini; 1. met: vrstica »Povleci in spusti žogo!« (≥ 2 s); pike ○○○ | potegne žogo nazaj / navzgor in spusti |
| T | preliv ≤ 200 ms zanka → `ball_running` (okvir 0 = stoji) | žoga v 2,5D loku: manjša se, odbije na tleh (~600 ms), se odkotali **na stran, kamor kuža steče** (`exit_side`, §8.4); ✓ »Lep met!« 0,8 s; zvok odboja, haptika *light* | — |
| T … T+3 s | `ball_running` (kuža odide iz kadra) | žoga izgine iz kadra pri ~1,1 s | gleda |
| T+3 s | `ball_fetching` (prazna soba → nazaj; isti okvir, brez reza) | piščalka / gumb **»Pokliči!«** pulzira 2 s | **tapne »Pokliči!«** (neobvezno; po 2 s se kuža vrne sam) |
| T+3 … T+6 s | kuža se vrne in obstane | ob tapu: ✓ »Pridi!« + zvok žvižga; brez tapa nič | — |
| T+6 s | preliv → `ball_waiting_loop` | žoga pade pred psa in se kotali proti kameri 0,9 s | **ujemi žogo** (tap / poteg nanjo) |
| T+6 … T+7,5 s | zanka | ujeta: ★ + »Ujeta!«, haptika *success*; zgrešena: žoga se ustavi spodaj (brez ✗) | — |
| po 3. metu | zanka | »Bravo! ★★★« + gumba »Še en krog« / »Končano« | izbere |

**Točke / pravila:** nespremenjeno — ob koncu kroga en `POST /api/child/pet/play {kind:"play"}` (»Še en krog« pošlje še enega; strežnik ponovitev iste vrste v 10 s obravnava kot isto igro — `play.repeat_seconds`, zato je krog vsaj ~25 s, kar to izključi). Brez dnevne omejitve, vabila in 30 min »vesel« kot doslej. Zvezdice so samo prikaz (ne gredo na strežnik).

**Anti-spam:** ni potreben za točke; met je mogoč samo, ko je kuža nazaj (zanka čakanja). Poteg pod 30 pt ali počasnejši od praga se ne šteje (žoga se vrne v roko).

**Besedila:**
| Ključ | SL | EN |
|---|---|---|
| `ball.hint` | Povleci in spusti žogo! | Pull and let go of the ball! |
| `ball.fb.throw` | Lep met! | Nice throw! |
| `ball.call` | Pokliči! | Call! |
| `ball.fb.called` | Pridi! | Come! |
| `ball.fb.caught` | Ujeta! | Caught it! |
| `ball.done` | Bravo! Kuža je ves vesel. | Great! Your pup is so happy. |
| `ball.again` | Še en krog | One more round |

**Posnetki:** obstoječi `ball_waiting`, `ball_running`, `ball_fetching`; nov **izpeljan** `ball_waiting_loop` (naprej + nazaj, 0 $). Novih AI posnetkov ni.

**Dostopnost:** »Lažje kretnje« = obstoječe držanje (moč 1,2 s, spust) za met; »Pokliči« in »ujemi« sta tap (ujemi: velika tarča 96 pt, okno 2,5 s namesto 1,5 s). Bralnik zaslona: gumbi »Vrzi žogo«, »Pokliči kužka«, »Ujemi žogo«. »Zmanjšaj gibanje«: žoga ne leti — izgine z bleskom in se ob vrnitvi pojavi pred psom; brez kotaljenja.

### 4.4 Crkljanje (pes; muca enako)

**Cilj in učna točka:** **pravilo 3 sekund** [G2] — boži nežno 3 s, nato se ustavi in poglej, ali žival želi še; počasni gibi, ne trepljanje. To je hkrati pravilo varnosti otrok s psi.

> **SPREMEMBA PRAVILA — potrdil David 30. 10. (C1):** crkljanje ni več »5 potegov ≥ 60 pt« (`CUDDLE_STROKES`, PLAY_CUDDLE §4.3, predlog D), ampak **3 božanja po ~3 s s premorom** (~15–20 s). Strežnik in posledice (mood only) ostanejo.

**Potek:**
| Korak | Ljubljenček | Sloj | Otrok |
|---|---|---|---|
| začetek | zanka `playing` (sicer `idle`) | vrstica »Boži počasi, 3 sekunde.« (≥ 2,5 s); tri srca ♡♡♡ zgoraj | — |
| božanje | zanka | sled srčkov za prstom (skia), srce se polni 0 → 3 s; tiho »predenje«/mehka haptika vsakih 0,5 s | počasi vleče prst po ljubljenčku |
| prehitro (hitrost > 900 pt/s) | zanka | srce se ne polni; ikona 🐢 (≥ 2 s) | upočasni |
| po 3 s | zanka | srce polno → ikona ✋ »Počakaj …« (≥ 2 s) | **dvigne prst** |
| premor ≥ 1 s | zanka | ob ljubljenčku mehurček 💗 »Še!« (ljubljenček »prosi za več«) + haptika | nadaljuje |
| ne dvigne prsta | zanka | ✋ ostane; ničesar se ne izgubi | — |
| 3 srca | zanka | »Uživa! ♥♥♥« → konec, `POST play {kind:"cuddle"}` | — |

**Posnetki:** obstoječi (`playing` / `idle`). Izbirni nov posnetek C (§8.3).
**Besedila:** `cuddle.slow` »Boži počasi, 3 sekunde.« / »Stroke slowly, 3 seconds.« · `cuddle.wait` »Počakaj …« / »Wait …« · `cuddle.more` »Še!« / »More!« · `cuddle.done` »Uživa! Hvala za crkljanje.« / »It loves it! Thanks for the cuddles.«
**Dostopnost:** »Lažje kretnje« / bralnik: gumb »Pobožaj« — držanje polni srce 3 s, spust = premor; »zmanjšaj gibanje«: srčki brez sledi in letenja.

---

## 5. Muca

### 5.1 Palica s peresom — cilj
Muca lovi plen, ki **beži stran** in se **ustavi, ko jo opazi** [C11]; lov se konča z **ulovom**, sicer je muca razočarana [G1]. Otrok se nauči tri stvari: pero premikaj stran od muce (ne pred nosom), **ko se pritaji, pero miruje**, **ko skoči, pero zbeži**, na koncu jo **pusti, da ujame**.

> **SPREMEMBA PRAVILA — potrdil David 30. 10. (P1):** ena igra = **en lov ~25 s** (prej ~60 s, David 8. 10. 2026), ki se konča z ulovom. Cilj na dan (2 / mucek 3), razmik 2 h med uspešnima igrama, merilnik »Igra«, rutina »Igra«, opraskan kavč po zamujenem dnevu — **nespremenjeno**.

### 5.2 Potek lova (urnik strežnika)
- `stalk`: 0–3,0 s · skoki `p1 ∈ [6,0; 7,5] s`, `p2 = p1 + [5,0; 6,5] s`, `p3 = p2 + [5,0; 6,5] s` · znak pritajitve `p − 1500 ms` (pravilo mirovanja, David 31. 10. 2026) · ulov `c = p3 + 3,5 s` · konec `c + 3,5 s` → lov **~23–29 s**.

| Čas | Muca (posnetek) | Sloj | Otrok |
|---|---|---|---|
| 0–3 s | `wand_stalking` (stoji → pritajena) | pero v **igralnem polju** (zgornja polovica, kamor muca gleda — svetel obroč); vrstica »Pritaji se: miruj. Skoči: umakni!« (3 s); zvezdice ☆☆☆☆ | premika pero |
| med skoki | `wand_crouch_loop` (izpeljana zanka, rep trza) | iskrice, ko se pero premika v kratkih skokih znotraj polja | premika pero počasi |
| `p − 1500` … `p` | zanka | **ikona 👀 nad muco pulzira** + naraščajoč zvok + rahla haptika (*selection* × 3) | **pero obmiruje** (najpozneje v `p − 1000` popolnoma mirno) |
| `p` | `wand_pouncing` (enkrat; zanka se preklopi ob koncu svojega okvira ≤ 200 ms) | ⚡ šapa zamahne čez pero (skia lok, sinhronizirano s `p + 600`) | **potegne pero stran** |
| izmik v oknu | — | ★ + »Ušlo!« (1,0 s), zvok »švist«, haptika *success* | — |
| brez izmika do `p + 1500` | — | pero se zatrese v šapi (»ulovila«), brez ✗, brez besedila — 0,8 s, nato se izvije | — |
| `p + 3 s` | preliv ≤ 150 ms → `wand_crouch_loop` (pod odzivom, ker je konec skoka start-only) | — | — |
| `c` | `wand_catching` (pritajena → ulov) | vrstica »Pusti jo, da ujame!« (≥ 2,5 s), pero se spusti k muci | **ne vleče stran** (ali pero potegne k muci) |
| `c` … `c + 1500` | — | brez premika stran: ★ »Ujela ga je!«; s premikom stran: posnetek ulova vseeno, brez zvezdice | — |
| konec | poza `catch` (ostane) | rezultat (§5.4) | — |

### 5.3 Točkovanje (strežnik, `WandPlayService::score` v2)
> **SPREMEMBA PRAVILA — potrdil David 30. 10. (P2):** pragovi sodelovanja (8 premikov stran, vse četrtine, ≥ 50 % stran, odziv na **vsak** skok v 2 s, CAT_SPEC §5.2 / PRODUCT_SPEC §13) se zamenjajo z **zvezdicami**: 3 × izmik + 1 × ulov. **Igra šteje pri ≥ 2 zvezdicah.** Zvezdice se prikažejo takoj.

Zvezdica za **izmik pri skoku `p`** (vse mora veljati):
1. **mirovanje pred skokom (strogo pravilo — odločil David 31. 10. 2026):** v `[p − 1000, p)` **noben** štet premik (`calm_max_moves` 0; 300-ms pravilo štetja ostane) — viden znak 👀 je tam **1500 ms** pred skokom, torej ima otrok ~0,5 s, da ustavi potezo, ki jo je začel; prej (30. 10.) 0,9 s / 0,8 s / največ 1 premik — tako je enakomerno počasno premikanje dobilo zvezdice v ~90 % lovov;
2. **odziv:** prvi premik stran v `[p + 150, p + 1500]` (150 ms = človeški prag, kot pri šoli);
3. **v polju:** premik se konča znotraj igralnega polja (`in_zone: true`, poroča aplikacija) — poteg iz zaslona ne šteje;
4. **hitrostna meja:** največja hitrost poteze ≤ 4000 pt/s in dolžina ≥ 40 pt (`speed_ok`, poroča aplikacija);
5. šteje samo prvi izmik na skok.

Zvezdica za **ulov `c`:** v `[c, c + 1500]` ni premika stran in pero je v polju.

Zakaj je to odporno proti spamu: hitro neprekinjeno metanje peresa **in tudi počasno neprekinjeno premikanje** (poteza vsakih 0,8–1,2 s) kršita pravilo 1 pri vsakem skoku (v zadnji sekundi pred skokom je vedno premik — simulacija QA: počasni ≈ 0 % lovov, pošten otrok, ki se ustavi do ~0,45 s po znaku, ~98–100 %), poteg iz zaslona krši pravilo 3, ulov pa zahteva **mirovanje**. Nagrajen je **odziv na viden znak**, ne število premikov. `too_uniform` ostane kot zapis v dnevnik (ne zavrača več — pri treh tarčnih dogodkih je statistika premajhna).
Zaupanje: `in_zone` / `speed_ok` poroča aplikacija — enak sprejet model kot pri šoli (spremenjena aplikacija lahko ponaredi, David 6. 10. 2026); popravek naslavlja **običajno otroško strategijo**, ne goljufije.

Konfiguracija `config/wand.php` → `v2`: `round_ms`, `stalk_ms` 3000, `pounces` 3, `first_pounce_ms` [6000, 7500], `pounce_gap_ms` [5000, 6500], `crouch_cue_lead_ms` 1500, `calm_window_ms` 1000, `calm_max_moves` 0 (David 31. 10. 2026), `dodge_window_ms` [150, 1500], `catch_after_last_ms` 3500, `catch_window_ms` 1500, `catch_clip_ms` 3500, `min_stars` 2, `max_speed_pt_s` 4000, `min_dodge_pt` 40.

### 5.4 Rezultat
- ≥ 2 zvezdici: »Kakšen lov! ★★★☆« — igra šteje (merilnik, rutina kot doslej).
- < 2: »Še en lov?« + **en** namig po prvem manjkajočem pravilu (»Ko se pritaji, pero miruje.« / »Ko skoči, pero hitro stran.« / »Na koncu jo pusti, da ujame.«); brez kazni, takoj znova (kot doslej).

| Ključ | SL | EN |
|---|---|---|
| `wand.rule` | Pritaji se: miruj. Skoči: umakni! | Crouch: freeze. Pounce: pull away! |
| `wand.fb.dodge` | Ušlo! | Escaped! |
| `wand.catchCue` | Pusti jo, da ujame! | Let her catch it! |
| `wand.fb.caught` | Ujela ga je! | She caught it! |
| `wand.result.success` | Kakšen lov! | What a hunt! |
| `wand.result.again` | Še en lov? | One more hunt? |
| `wand.hint.calm` | Ko se pritaji, pero miruje. | When she crouches, keep the feather still. |
| `wand.hint.dodge` | Ko skoči, pero hitro stran. | When she pounces, pull it away fast. |
| `wand.hint.catch` | Na koncu jo pusti, da ujame. | At the end, let her catch it. |

**Posnetki:** obstoječi `wand_stalking`, `wand_pouncing`, `wand_catching`; nov **izpeljan** `wand_crouch_loop` = zadnjih ~1,0 s `wand_stalking` naprej + nazaj (0 $; konec stalking je poslan s končno sliko `crouch`, zato je pritajen). Če je zanka slaba: izbirni posnetek B (§8.3).

**Dostopnost:** »Lažje kretnje« / bralnik: pero samo plava v polju; **ob znaku 👀 samodejno obmiruje** (pravilo 1 se izpolni samo), izmik = gumb **»Umakni!«** (tap, velik), ulov = ne narediš nič. Bralnik napove »Pritaja se.«, »Skok!«, »Pusti jo, da ujame.«. »Zmanjšaj gibanje«: brez sledi peresa in loka šape (statična ikona ⚡), brez tresenja.

### 5.5 Praskanje, pesek, česanje
- **Praskanje:** pravila nespremenjena (pohvala 150 ms–3 s po pristanku). Dobi skupni sloj: celozaslonski prizor, ikona ✓ / ✗ prekrivni odziv, priboljšek kot pri šoli (»Pohvali« = tap žetona), besedilo zgoraj.
- **Pesek in nered zraven peska:** §6.
- **Česanje, menjava peska, nega pasme:** samo skupni sloj (§7.7) — pravila ostanejo.

---

## 6. Neredi in čiščenje — vizualni jezik

### 6.1 Slog (CGP v2 »Grafit in meta«)
- Ploska vektorska ilustracija (react-native-svg / skia), zaobljene geometrijske oblike, **brez obrazov, brez muh, brez realističnih tekstur**, brez besedila v sliki; »smrdljive« črtice le kot 2 mehki valoviti črti v megleno sivi (40 %).
- Obris 2 px v temnejšem odtenku polnila (kot ikone Lucide), viewBox 120 × 80 kot `BehaviourGraphics.tsx`; berljivo od 32 pt (ikona v piluli) do 160 pt (prizor).
- Na temnem steklu HUD-a; **mint je izključno barva »čisto«** (iskrice, ✓), malinova se ne uporablja, rdeča ne (nered ni nevaren).
- Novi barvni žetoni (`mobile/src/theme`): `messBrown` `#8B5E3C`, `messBrownDeep` `#5E3B22`, `puddle` `#FFD15C` (70 %), `puddleEdge` `#E0B53C`, `litter` `#D9CBB0`, `clump` `#A89478`, `fluff` = `n200`.
- Nered je narisan **na tleh prizora** (spodnjih ~22 % videa), ne kot abstraktne pike čez zaslon; v piluli sporočila ista ikona v majhnem.

### 6.2 Ilustracije in čiščenje
| Nered | Ilustracija | Orodje in kretnja | Animacija uspeha | Lažje kretnje |
|---|---|---|---|---|
| **Kakec** (pes) | mehak trinadstropni »sladoledni« zavoj v `messBrown` z enim bleskom; 1–3 kosi (ne 5 pik) | **vrečka**: povleci vrečko čez kakec | kakec se stisne v vrečko (250 ms), vrečka se zaveže in odleti v **koš** spodaj; mint iskrice | tap na kakec |
| **Luža / lulanje** (pes) | plitva luža `puddle` z robom in odsevom + **kapljica** (razlikovalni znak) | **krpa**: briši čez lužo (strgalna srečka, kot `BrushArea`) do ~90 % | luža se skrči in posuši, krpa v vedro; iskrice | držanje na luži (briše postopoma) |
| **Grizenje** (copat) | obstoječi `ChewedSlipperGraphic` + 3 kosmi | povleci kose v **škatlo**, nato **igračo za grizenje** do psa | škatla se zapre; pes dobi igračo (ikona ♥) | tap na kos, tap na igračo |
| **Pesek** (muca) | pladenj `litter` z grudicami `clump` (zaobljene, svetle — ne temne pike); 2–6 | **lopatka**: povleci skozi pesek, grudice se primejo, **stresi** (poteg navzgor) v vrečko | pesek se zgladi (val), iskrice | tap na grudico |
| **Nered zraven peska** | majhen kup / madež v `messBrown` ob pladnju | vrečka (kup) ali krpa (madež) — kot pes | kot zgoraj | tap |
| **Praskanje** (kavč) | obstoječi `ScratchedSofaGraphic` | obstoječi tok »na praskalnik + pohvala« | — | — |

- Učni namig ob prvem čiščenju vrste (enkrat, zgoraj, ≥ 2,5 s): kakec »Na sprehodu vedno pobereš z vrečko.« / »On walks you always pick it up with a bag.«; luža »Ne kregaj ga — pelji ga pogosteje ven.« / »Don't scold — take it out more often.« (vir za drugi namig dodati iz `docs/research/dog-data` pred objavo).
- **Vir za drugi namig (dodan 30. 10. 2026):** VCA Animal Hospitals, *House Training for Puppies and Dogs* (S47 v `docs/research/dog-data/sources.md`): »Do not punish your puppy.« … »calmly and quietly clean the area and resolve to better supervise your puppy«, s pogostimi izhodi na potrebo. Namig je zato vklopljen (`PUDDLE_TIP_ENABLED`, `mobile/src/modules/cleaning/cleaningTips.ts`).
- **Zgrajeno (30. 10. 2026, `feat/games-phase1-messes`):** risbe (`mobile/src/components/MessGraphics.tsx`, oblike v `modules/cleaning/messArt.ts`) so react-native-svg → zamenjajo stare kroge **v vseh gradnjah** (HUD, stara igra tapkanja, mačji pesek; pride z OTA); igre čiščenja (`modules/cleaning/MessGame` — kakec + luža, `SlipperGame`, `ScoopGame`) samo na gradnji z igralnim slojem (`loadMessGames()` za `gameLayerAvailable()`), stara gradnja ostane pri tapkanju in takojšnjem »Pospravi in daj igračo«. Narisani predmeti se premikajo z deljenimi vrednostmi reanimated na niti UI (svg risbe, iste kot drugod); Skia samo za lužo (strgalna srečka: plast + `clear` poteze). Kakec + luža hkrati = dve stopnji zaporedoma (najprej vrečka, nato krpa), en klic na koncu.
- Strežnik nespremenjen: en `POST /clean` / `/litter/scoop` / `resolve-chewing` po koncu, kot danes. »Zmanjšaj gibanje«: brez stiskanja in letenja, samo izginjanje.
- Število predmetov: kakec 1–3 (naključno, deterministično po dogodku), luža 1 velika. To je samo prikaz, ne pravilo.

---

## 7. Skupni igralni sloj (mobilna aplikacija)

### 7.1 Gradniki (`mobile/src/modules/gameLayer/`)
| Gradnik | Naloga |
|---|---|
| `GameScreen` | celozaslonska postavitev: `GameStage` (video), `GameTopBar` (zgoraj: čip / vrstica / pike / zvezdice / ×), `FeedbackLayer` (sredina), `PlayField` (fizikalni predmeti), `ActionDock` (spodaj: žeton / gumb). Varna območja. |
| `ClipDeck` | posplošen `SchoolStage`: za igro izmeri seznam vlog (`hub_loop`, `move_a`, `move_b`, …) → en trajen `expo-video` predvajalnik na vlogo, ob odprtju naložen iz lokalnega predpomnilnika, zaustavljen na okvirju 0, skrit. `ready` = vsi izrisali prvi okvir (ali `readyToPlay` 600 ms); 12 s brez → način »Slika«. |
| `ClipQueue` | čista logika: časovnica segmentov iz urnika strežnika (`schoolTimeline` → `gameTimeline`) + pravila verige (§3.2): preklop iz zanke s prelivom ≤ 200 ms, iz posnetka poza→poza šele ob koncu (`playToEnd`), rez samo pod masko (`FeedbackLayer.isMasking()`). Jest, lažne ure. |
| `FeedbackLayer` | reanimated: ✓ / ✗ / ★ / premor kroga; `show(kind, {mask})` vrne obljubo; maska = zatemnitev ≥ 85 % + glif 120 pt, vstop 150 ms, drža, izstop 250 ms. |
| `PlayField` | `@shopify/react-native-skia` platno + reanimated worklets: žoga (2,5D projekcija: y → velikost, odboj, kotaljenje), pero (vzmet za prstom, sled), priboljšek (lok), srčki (delci), šapa (lok). 60 fps na UI niti. |
| `useGameGestures` | `react-native-gesture-handler` (Pan / Tap / LongPress na UI niti) + preklop na držanje / gumbe po `useGestureMode` (`easyGestures.ts`, M5-T17) in bralniku zaslona. |
| `GameLine` | vrsta ene vrstice: najmanj 2 s, največ 32 znakov (test), `accessibilityLiveRegion` + iOS napoved. |
| `GameFx` | zvoki (`expo-audio`, ~10 kratkih prednaloženih datotek < 30 kB, upoštevajo tihi način iOS) in haptika (`expo-haptics`: tap *light*, uspeh *success*, znak *selection*; ob napaki **nič**). |
| `GameClock` | obstoječi `SessionClock` (`training/game.ts`): monotonična ura + popravek po steni, začne ob `ClipDeck.ready`. |

### 7.2 Strežnik ostane avtoriteta
- Strežnik ob začetku pošlje urnik (znaki, okna, dolžine) in na koncu oceni **surove dogodke** aplikacije (pritiski, premiki z `in_zone` / `speed_ok`); aplikacija ima zrcalo ocenjevalnika samo za takojšen odziv (kot `game.ts` / `catGames.ts`) — ob razliki velja strežnik (zaslon rezultata).
- **Različica pravil:** začetek igre pošlje `rules: 2`; strežnik vrne `rules_version` in urnik v2; brez parametra (stara aplikacija) → v1 nespremenjen. Seja shrani svojo različico (nadaljevanje po ponovnem zagonu ostane v svoji različici).
- Žoga in crkljanje nimata točk → strežnik ostane pri `POST /play` (brez spremembe API).

### 7.3 Spremembe na strežniku
| Kje | Sprememba |
|---|---|
| `TrainingService` | v2: `ROUND_BREAK_MS` 2500 za 3. ukazom (polje `round_breaks_ms`), `R` = `MASK_MS` 1200 namesto dolžine release (Š2), `finish` sprejme predčasen konec po 1. krogu (`ended_after_round: 1`, Š3: napredek da, rutina ne, `duration_ms` = konec kroga); `maxSessionMs()` po v2. Pest z `Carbon::setTestNow()`. |
| `WandPlayService` | `schedule()` v2 (§5.2), `score()` v2 (§5.3) z zvezdicami; `FinishWandRequest` sprejme `in_zone`, `speed_ok` (neobvezno, aditivno); v1 ostane za stare aplikacije. |
| `config/wand.php` | ključ `v2` (§5.3). `wand.session_seconds` ostane za v1. |
| `SchoolClipDerivativeService` → `ClipDerivativeService` | še `ball_waiting_loop` (ping-pong `ball_waiting`), `wand_crouch_loop` (ping-pong zadnje sekunde `wand_stalking`), `ball_running` → `exit_side` (§8.4); isti posel, ista pravila (nice, prlimit, en težek ffmpeg naenkrat), 0 $. |
| `PetMediaPayload` | novi ključi v `media.phases` / `phase_seconds`; `media.phase_meta.ball_running.exit_side`. Stare aplikacije neznane ključe ignorirajo. |
| `ChildProfileService` | **faza 1:** `users.game_feedback` (bool, privzeto `true`) — stikalo staršev »Zvoki in vibracija v igrah« prek `PATCH /api/parent/children/{child}/settings` (kot `easy_gestures`); v stanju otroka `settings.game_feedback`, v nadzorni plošči `family.children[].game_feedback`, v izvozu. Samo nastavitev, pravila ga ne berejo. |
| Readiness | `GameMediaReadiness`: izpeljanka, ki je še ni, igre **ne** zaklene — aplikacija ima nadomestilo (zanka iz izvirnika) kot pri šoli. |
| Dokumenti | PRODUCT_SPEC §5 / §13, CAT_SPEC §5.2, PLAY_CUDDLE §4, ARCHITECTURE §3 + DIAGRAMS, FEATURES, `schema.ts` — v PR-jih posameznih faz. |
| Crkljanje (C1) | **Brez spremembe strežnika** (preverjeno 30. 10. 2026): `POST /play {kind: "cuddle"}` sprejme samo vrsto, potegov strežnik nikoli ni preverjal — pravilo 3 sekund je samo v aplikaciji. |

**Zgrajeno 30. 10. 2026 (faze 2–4, strežnik):** vse zgornje vrstice za `TrainingService`, `WandPlayService`, `config/wand.php` (`v2`), izpeljanko `wand_crouch_loop` in `PetMediaPayload`; dodatno `training.treat_token` v stanju otroka (V6). Podrobnosti, ki jih spec ni določil, odločil Claude (čakajo Davida): (1) `treat_token` je `true` tudi, ko je paket priboljškov porabljen (»kupila« ≠ »ima še«; preklican paket = `false`); (2) zavrnitve začetka palice (tihe ure, polnoč) obe različici računata z 60 s v1 (stanje `can_start` se vedno ujema z začetkom); (3) prvi premik stran v `[p, p + 150)` je »prezgodaj« (izmik brez zvezdice); (4) premik brez `in_zone` / `speed_ok` v v2 ne prinese zvezdice; (5) predčasen konec še vedno pošlje `trained_pet` kot dogodek osvežitve (brez vrstice v dnevniku). Po QA #461: predčasen konec porabi **dejanski čas** (čas do »Končaj«, najmanj 1. krog) in je mogoč samo do začetka 2. kroga + 2 s (pozneje 422 `training_round_two_started`); konec palice v2 je mogoč šele po oknu ulova (brez 5-s tolerance); izmik in ulov štejeta vsak prijavljen premik (300-ms združevanje velja samo za pravilo mirovanja). **Pravilo mirovanja odločeno (David 31. 10. 2026, strogo):** znak 👀 1500 ms pred skokom, v zadnjih 1000 ms 0 premikov (`crouch_cues_ms` = p − 1500, `calm_window_ms` 1000, `calm_max_moves` 0) — samo `config/wand.php`; vsaka seja si pravilo shrani v urnik (lov, ki že teče, ostane pri svojem). Prej (0,9 s / 0,8 s / 1 premik) je enakomerno počasno premikanje dobilo zvezdice v ~90 % lovov.

#### Pogodba za aplikacijo (pravila v2)
Celotna pogodba (vsa polja zahtev in odgovorov za šolo, palico, `treat_token`, `wand_crouch_loop`) je v `docs/engineering/ARCHITECTURE.md` → **»Games rules v2 — contract for the app«** (angleško, tehnični dokument). Na kratko:
- **Šola:** `POST /training/start {command, rules: 2}` → `session` + `rules_version: 2`, `mask_ms` 1200, `round_break_ms` 2500, `round_breaks_ms[]`, `rounds[{round, first_index, last_index, start_ms, end_ms}]`; `POST /training/finish {session_id, taps, ended_after_round?: 1}` (samo do začetka 2. kroga + 2 s, sicer 422 `training_round_two_started`) → `result` + `rules_version`, `ended_after_round`, `counts_for_routine`. Stanje: `training.treat_token`.
- **Palica:** `POST /wand/start {rules: 2}` → `session` + `rules_version`, `stalk_ms`, `crouch_cues_ms[3]` (= p − 1500), `catch_window_ms`, `calm_window_ms` (1000), `calm_max_moves` (0), `dodge_window_ms`, `min_stars`, `max_stars`, `max_speed_pt_s`, `min_dodge_pt`; `POST /wand/finish {session_id, moves[{t, away, in_zone, speed_ok}]}` → `result` + `rules_version`, `stars`, `min_stars`, `max_stars`, `dodges[{pounce_ms, star, reason, move_ms}]`, `catch{star, reason}`, `hint`. Posnetek: `media.phases.wand_crouch_loop`.
- **Brez `rules`:** v1 nespremenjen (stare gradnje). Sejo v2, ki jo nadaljuje stara gradnja, strežnik oceni po v2 — brez `in_zone` / `speed_ok` palica ne dobi zvezdic (odpove zaprto), šola nima predčasnega konca.

### 7.4 Nativne odvisnosti in izdaja
- Novi: `react-native-gesture-handler`, `@shopify/react-native-skia`, `expo-haptics`, `expo-audio` → **spremenjen prstni odtis → nov EAS build v trgovinah**; OTA na stare gradnje jih ne prinese.
- Zato **zastavica zmožnosti** v aplikaciji: `gameLayerAvailable()` (moduli prisotni) — stara gradnja z novim JS ostane pri igrah v1; strežnik v1 ostane, dokler je v uporabi.
- Android tako ali tako potrebuje nov produkcijski build (HANDOFF §1b točka 3) — prvo fazo z nativnim slojem vežemo nanj.
- Velikost aplikacije: skia doda nekaj MB — izmeriti v EAS buildu pred potrditvijo.

### 7.5 Način »Slika« (M5-T11)
Isti sloj; `ClipDeck` namesto posnetkov pokaže poze (začetna poza vloge), prekrivni odziv in fizika ostaneta. Nič se ne prenaša.

### 7.6 Zvok in otrok
Brez glasbe, brez zvokov, ki strašijo; glas ukaza (šola) posnet v sl in en (8 kratkih datotek). Zvoki in haptika **privzeto vklopljeni**, upoštevajo tihi način telefona; **stikalo staršev** »Zvoki in vibracija v igrah« na otroka (kot »Lažje kretnje«, `users.game_feedback`, privzeto `true`) — David 30. 10. (V5, V11).

### 7.7 Česanje, menjava peska, nega pasme, praskanje
Brez spremembe pravil; prestavitev na `GameScreen`: besedilo zgoraj, celozaslonska žival / predmet, haptika ob vsaki šteti potezi, ✓ ob koncu koraka (3 koraki = 3 pike).

---

## 8. Posnetki

### 8.1 Osrednje poze in veriga po igrah
| Igra | Hub | Veriga | Kje je rez / preliv |
|---|---|---|---|
| Šola | stoji (ref) | `attention_loop` → `performing` (ref → poza) → `success_loop` (poza) → **[maska]** → `attention_loop` | edini rez poza → stoji, pod masko odziva |
| Žoga | stoji (ref) | `ball_waiting_loop` → `ball_running` (ref → prazno) → `ball_fetching` (prazno → ref) → `ball_waiting_loop` | prelivi ≤ 200 ms iz / v zanko; running → fetching brez reza (isti okvir) |
| Palica | pritajena | `wand_stalking` (ref → crouch) → `wand_crouch_loop` ⇄ `wand_pouncing` (crouch → ≈crouch) → `wand_catching` (crouch → catch) | konec skoka je start-only → preliv 150 ms v zanko pod odzivom |
| Crkljanje | zanka `playing` / `idle` | ena zanka | — |

### 8.2 Seznam (obstoječi / izpeljani / novi)
| Posnetek | Status | Strošek na videz × obdobje |
|---|---|---|
| šola 12 faz (`school_*`) | obstoječi | 0 $ (že plačano) |
| šola `*_attention_loop`, `*_success_loop` | obstoječi izpeljani | 0 $ |
| šola `*_release` | obstoječi izpeljani — se ne predvaja več (Š2) | 0 $ |
| `ball_waiting`, `ball_running`, `ball_fetching` | obstoječi | 0 $ |
| **`ball_waiting_loop`** | **nov izpeljan** (ffmpeg) | 0 $ |
| `wand_stalking`, `wand_pouncing`, `wand_catching` | obstoječi | 0 $ |
| **`wand_crouch_loop`** | **nov izpeljan** (ffmpeg) | 0 $ |
| `playing`, `idle` | obstoječi | 0 $ |

**Privzeti predlog: 0 novih AI generacij.**

### 8.3 Izbirni novi posnetki (samo, če David po testu reče, da ni dovolj gladko)
Kling 3.0 Pro 0,112 $/s brez zvoka; novih poz ni treba (začetek / konec sta obstoječi pozi).

| # | Posnetek | Dolžina | Od → do | Cena / videz × obdobje |
|---|---|---|---|---|
| A | `school_<sit\|come\|place\|potty>_return` — kuža se **naprej** vrne v stoječo pozo (vstane, se obrne in odide na izhodišče) | 4 × 3 s | poza → ref | **1,344 $** (pes) |
| B | `wand_crouch_idle` — pritajena, rep trza, oči sledijo | 3 s (samo začetna slika) | crouch → crouch | **0,336 $** (muca) |
| C | `cuddle` — žival uživa ob božanju (stanje) | 5 s | ref → ref | **0,56 $** (pes; muca enako, če želi) |

Vsi trije: pes 1,904 $ + muca 0,336 $ (0,896 $ s C) na videz in obdobje. Ker se mediji delijo po videzu (pasma + barva + obdobje), se strošek plača **enkrat na skupen videz**, ne na družino. Brez tega tudi ni nobene nove slike (Nano Banana Pro 0,15 $ — ni potrebna).

### 8.4 `exit_side` za žogo (0 $)
Strežnik ob izpeljankah primerja zadnji okvir `ball_running` (prazna soba) s srednjim okvirjem: kje je razlika (pes) bližje robu v zadnji četrtini → `left` / `right`; negotovo → `center`. Aplikacija pošlje žogo na to stran (pri `center` žoga odleti »v daljavo« in se zmanjša). Admin lahko vrednost popravi v pregledu medijev (D).

---

## 9. Faze uvajanja

**Odločitev (David 30. 10. 2026):** brez hitrih vmesnih popravkov — »še nismo v produkciji, hočem učinkovito, da ne bomo spet popravljali«. **Faza 0 odpade**; njene vsebine (palica brez »vse ali nič«, besedilo nad odrom, šola brez »posluša« med izvajanjem) se naredijo enkrat, pravilno, v fazah 2 in 3. Vrstni red: 1 → 2 → 3 → 4.

| Faza | Kaj | Zakaj ta vrstni red | Izdaja |
|---|---|---|---|
| ~~0 — hitri popravki~~ | odpade (David 30. 10.) — vsebina v fazah 2 in 3 | — | — |
| **1 — sloj + žoga + neredi** | `gameLayer` (ClipDeck, ClipQueue, FeedbackLayer, PlayField, GameFx), žoga v2, `ball_waiting_loop`, `exit_side`, nove ilustracije nereda in čiščenje — **stanje 30. 10. 2026:** strežnik (#457), sloj + žoga v2 + stikalo staršev v aplikaciji (#459) in neredi / čiščenje (`feat/games-phase1-messes`) zgrajeni, čaka novo gradnjo v trgovini | žoga nima pravil na strežniku → najnižje tveganje za preizkus nativnega sloja na napravi; neredi so samo prikaz | **nov EAS build** (+ OTA JS za stare gradnje ostane v1) |
| **2 — palica v2** | §5, `wand_crouch_loop`, rules v2 — **strežnik zgrajen 30. 10. 2026** | največ pritožb testerjev; uporabi sloj iz faze 1 | JS + strežnik (sloj je že v gradnji) |
| **3 — šola v2** | §4.1, krogi, maska namesto release, priboljšek — **strežnik zgrajen 30. 10. 2026; aplikacija zgrajena 31. 10. 2026** (`feat/games-school-app`, čaka novo gradnjo v trgovini) | največ pravil in najpomembnejša učna vsebina → zadnja, ko je sloj preverjen; QA krog (napredek, rutina) | JS + strežnik |
| **4 — crkljanje, praskanje, nega** | §4.4, §5.5, §7.7 — strežnik brez sprememb (C1 samo aplikacija) | majhne, brez tveganja | JS |

Merilo za izbirne posnetke A–C: po fazi 3 David pogleda posnetke zaslona na enem psu in eni muci; samo ob »še skače« se naročijo.

---

## 10. Seznam sprememb pravil (potrjene — David 30. 10. 2026)

| # | Pravilo danes | Predlog |
|---|---|---|
| **Š1** | vaja = 6 ukazov zaporedno | 2 kroga po 3 ukaze, premor 2,5 s |
| **Š2** | po pohvali kuža »vstane« (obrnjen posnetek, ≤ 2,5 s; David 28. 10.) | rez pod masko odziva 1,2 s; `release` se ne predvaja |
| **Š3** | nedokončana vaja se ne šteje | po 1. krogu lahko konča: napredek šteje, rutina samo cela vaja |
| **C1** | crkljanje = 5 potegov ≥ 60 pt (D) | 3 božanja po ~3 s s premorom (pravilo 3 sekund) |
| **P1** | igra s palico ~60 s (David 8. 10.) | en lov ~25 s z ulovom |
| **P2** | šteje samo, če velja vse (8 stran, 4 četrtine, ≥ 50 % stran, vsak skok v 2 s, ne enakomerno) | zvezdice: 3 izmiki + ulov, šteje pri ≥ 2; izmik samo z mirovanjem pred skokom (👀 1,5 s prej, zadnjo 1 s brez premika — David 31. 10.), v 150–1500 ms, v polju, pod hitrostno mejo |
| ~~**P0**~~ | odpade s fazo 0 (David 30. 10.) | — |

Nespremenjeno: okno pohvale, verjetnost ubogljivosti, napredek in upadanje šole, 7 min / dan in pošten delež, rutine, Care Score, cilj in razmik iger muce, merilnik »Igra«, opraskan kavč, žoga (3 meti, mood only), vsa pravila nereda.

---

## 11. Vprašanja za Davida in odgovori

| # | Vprašanje | Priporočilo | Odgovor |
|---|---|---|---|
| V1 | Potrdiš spremembe Š1–Š3, C1, P0–P2 (§10)? | da | **da — Š1–Š3, C1, P1, P2 potrjene** (David 30. 10. 2026); P0 odpade s fazo 0 |
| V2 | Faza 0 pred žogo (popravek palice, ker vpliva na Care Score)? | da | **ne** — faza 0 odpade, vse naenkrat pravilno (David 30. 10.) |
| V3 | Maska namesto »vstane« (Š2) — ali raje takoj izbirni posnetek A (1,344 $ na videz × obdobje)? | najprej maska (0 $), A samo po testu | odprto |
| V4 | Faza 1 zahteva nov EAS build v trgovinah (iOS + Android). Ga vežemo na naslednjo izdajo za Android? | da | odprto |
| V5 | Zvoki v igrah: privzeto vklopljeni (upoštevajo tihi način) ali stikalo staršev »Zvoki v igrah«? Glas ukaza v šoli — posnamemo otroški / odrasli glas ali sintetiziran? | vklopljeni + stikalo staršev; posnet glas odrasle osebe sl / en | **vklopljeni + stikalo staršev** (David 30. 10.); glas ukaza: odprto — faza 3 (31. 10.) ima vmesno rešitev: jasen lasten zvok `cue.wav` + velik čip ukaza; posnet glas sl / en ostane naloga |
| V6 | Priboljšek namesto gumba »Pohvali« — v redu, da šola uči »priboljšek« (marker + hrana)? Ali »Pohvali« z ♥ ostane? | priboljšek (bliže resničnemu treningu z nagrado) | **priboljšek, če je družina kupila priboljške (»Želje«), sicer »Pohvali« z ♥** (David 30. 10.); samo videz, ne porabi priboljška (omejitev 2 / dan ostane za domači zaslon) |
| V7 | Muca: ulov kot zvezdica (otrok mora pustiti) — ali naj ulov ostane samodejen? | zvezdica (uči »pusti, da ujame« [G1]) | odprto |
| V8 | Prag igre s palico ≥ 2 od 4 zvezdic — prestrog / premil? | 2 | odprto |
| V9 | Učna namiga ob čiščenju (»pobereš z vrečko«, »ne kregaj — pelji ga pogosteje ven«) — želiš jih? | da, z virom | **da** (naloga za fazo 1, 30. 10. 2026); vir za lužo VCA S47 — zgrajeno, enkrat na telefon, samo pes |
| V10 | Krogi žoge: ostane 3 meti ali 4 (~35 s)? | 3 (+ »Še en krog«) | **3 meti** (David 30. 10.) |
| V11 | Haptika za otroke — privzeto vklopljena? | da, samo pozitivna | **da**, s stikalom staršev skupaj z zvokom (David 30. 10.) |
| V12 | Neredi: kakec kot »sladoledni zavoj« (prepoznaven, ne gnusen) — v redu, ali raje bolj abstrakten kupček? | zavoj brez obraza | **zavoj** (David 30. 10., skica A) |
| V13 | Pravilo mirovanja pri palici (QA #461 M3): pri 0,9 s / 0,8 s / 1 premik počasno neprekinjeno premikanje dobi zvezdice v ~90 % lovov. Strožje? | 1,5 s / 1 s / 0 | **strogo: 👀 1500 ms pred skokom, zadnjih 1000 ms 0 premikov** (David 31. 10. 2026) |

---

## 12. Viri (novi v tem spec)
- **[G1]** Ontario SPCA — *What is interactive play for cats?* (2026-05-11): »Near the end of play sessions, let your cat ›make a kill‹ by catching, pouncing, kicking, or biting the toy«; »Always end the laser chase with the beam stopping on a toy so the cat can ›catch‹ the laser and avoid frustration«; »Prey animals typically move away from predators with quick stops, hiding, and darting in different directions.« https://ontariospca.ca/blog/what-is-interactive-play-for-cats/
- **[G2]** Humane Society of Huron Valley — *Dogs have a 3 second rule too*: »Pet for no more than three seconds, then stop. Does the dog nudge you or move into you to say keep going? Then go ahead and resume.« https://hshv.org/dogs-have-a-3-second-rule-too
- Obstoječi: [C11] Cats Protection (`docs/research/cat-data/sources.md`), [C23] AAFP Claw Friendly; šola: PRODUCT_SPEC §5 (S34–S37, S42, S47).
- Pred objavo besedil za starše vire dodati v `docs/research/` (G1 kot C-vir, G2 kot S-vir).
- Namig o luži: obstoječi **S47** (VCA Animal Hospitals, *House Training for Puppies and Dogs*): »Do not punish your puppy.«; »calmly and quietly clean the area and resolve to better supervise your puppy« (citat dodan v `docs/research/dog-data/sources.md`, 30. 10. 2026).
