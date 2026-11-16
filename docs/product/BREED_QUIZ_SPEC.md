# PetPrep — kviz »Katera pasma je za vas?« in podstran »Pred nakupom psa«

> **Zapisano 16. 11. 2026 (pogovor David + Claude).** Ideja je Davidova: brezplačen kviz na petprep.si, ki vrne
> najprimernejše pasme za način življenja družine, nato upsell na PetPrep s to pasmo. David je izbral: **ločena
> podstran s karticami mini orodij + najprej specifikacija** (anketa, 16. 11. 2026). Ostalo je Claudov predlog;
> Davidove odločitve so označene **(David)**.
> **Stanje:** ✅ zgrajeno 17. 11. 2026 — spletna stran pet-prep-website #447 (+ #449 glava strani), strežnik pet-prep #1274. Na petprep.si z dnevno objavo. Odstopanje od §4: register ima drugačen nabor oznak kot tabela v §4 (npr. `needs_company`, `not_for_small_children`, `experienced_owner`, `chases_small_pets`); točkovanje uporablja dejanske oznake (`pet-prep-website/src/lib/breedQuiz.ts`).
> **Davidove odločitve 16. 11. 2026 (21:46):** (1) **brez PDF-jev in brez Lemon Squeezy za povzetke** — povzetek je dinamična stran
> za tisk (enako kot T8); (2) **e-naslove zbiramo** (prek našega API-ja, T9); (3) **kalkulator »Koliko dela je pes« se
> razširi na vse pasme in samodejno dobi vsako novo pasmo**, ko jo dodamo v register; (4) kartica »Je vaš dom
> pripravljen?« je dobra ideja — prostor za trgovce s pohištvom in opremo (Harvey Norman, Mömax, Lesnina).
> Povezano: `THREEJS_OPPORTUNITIES.md` (§3 #1 kalkulator, #2 primerjalnik velikosti, §4 prototip),
> `THREEJS_OPPORTUNITIES.md` T8 (povzetek je dinamična stran za tisk) in T9 (e-naslovi prek našega API-ja, M6-S05),
> `docs/business/VALIDATION_PLAN.md` §2 (kaj je zamrznjeno), `docs/business/REVENUE_MODELS.md` (R3 sponzor),
> `backend/config/breed_suitability.php` (oznake »Za koga je primerna«), `docs/research/dog-data/data.json`.

## 1. Sklep

- Kviz je **prvi korak lijaka**: kalkulator »Koliko dela je z labradorcem?« pomaga nekomu, ki pasmo že ima v mislih;
  kviz pomaga tistemu, ki je še nima. To vprašanje starši iščejo (»kateri pes je primeren za nas«).
- **Ni del labradorčevega prototipa (David).** Kviz in kalkulator imata drug namen; zlepljena v en potek
  pomenita predolgo pot do rezultata, eno stran za dve različni iskanji in rezultat, ki ga ni mogoče deliti.
- Pot: **kviz → top 3 pasme → pri vsaki »Koliko dela in denarja« → »Preizkusite to pasmo v PetPrepu, preden jo kupite«.**

## 2. Podstran »Pred nakupom psa« (hub)

- URL: `/sl/pred-nakupom-psa` · `/before-you-get-a-dog` in slugi v **vseh 15 jezikih** (pravilo, David 16. 11. 2026). **V glavi strani** (David 16. 11. 2026).
- Kartice mini orodij, vsaka s svojim URL-jem (SEO, oglasi, deljenje):

| Kartica | Orodje | Stanje |
|---|---|---|
| Katera pasma je za vas? | ta kviz | 🗓 ta spec |
| Koliko je dela s psom? | three.js kalkulator (THREEJS §4), stran za vsako pasmo (M6-S07) | ✅ vse pasme (SL + EN; ostali jeziki M6-S08) |
| Kako velik bo zares? | primerjalnik velikosti (THREEJS §3 #2) | 🗓 predlog |
| Je vaš dom pripravljen? | kontrolni seznam doma in stanovanja pred prihodom psa (ležišče, varna tla, ograjice, kabli, prostor za hrano) | 🗓 ideja (David 16. 11. 2026), dummy kartica »Kmalu« |

- Kartica brez orodja je vidna kot »Kmalu« (lažje skriti kot dodati pozneje).
- **»Je vaš dom pripravljen?« in partnerji (David):** naravno sponzorsko mesto za trgovce s pohištvom in opremo doma
  (Harvey Norman, Mömax, Lesnina). Predlog: postavke seznama (ležišče, ograjica, pralna preproga …) imajo mesto
  »priporoča partner«; fiksno sponzorstvo kartice ali kategorije, kot pri zavarovalnici (REVENUE_MODELS R3).
  Brez vpisanih izdelkov ali logotipov, dokler ni dogovora; do takrat poziv »Želite postati naš partner?«.
- Pod karticami kratek blok »Zakaj PetPrep«: otrok 30 dni skrbi za virtualnega psa izbrane pasme, starš vidi, ali zmore.

## 3. Potek kviza

1. Začetek: en stavek (»9 vprašanj, ~1 minuta, brez e-naslova«) in gumb.
2. **9 vprašanj, eno na zaslon**, velike tipke, napredek zgoraj, nazaj vedno mogoče. Brez 3D in brez težkih
   nalaganj — na telefonu mora prvo vprašanje odpreti v < 1 s.
3. Rezultat: top 3 pasme + kartica »Razmislite tudi o posvojitvi« (§5).
4. Pri vsaki pasmi: oznake »primerno za« / »razmislite o«, povezava na kalkulator, CTA PetPrep.
5. Neobvezno, priporočeno: e-naslov za novice PetPrep (§7). Povzetek »Vaše pasme« je stran za tisk, brez PDF.

Otrok lahko odgovarja skupaj s staršem, a kviz nagovarja **starša** (odločitve o bivanju, času in denarju so njegove).

## 4. Vprašanja in točkovanje

**Pravilo: točkujemo samo z oznakami, ki že imajo vire** (`breed_suitability.php`, vsaka oznaka s `source_ids` in
`refs`). Ne izmišljujemo ocen »prijaznost 4/5«. **Manjkajoča oznaka pomeni »ni podatka«, ne minus.**

| # | Vprašanje | Odgovori | Oznake |
|---|---|---|---|
| 1 | Kje živite? | stanovanje · hiša brez vrta · hiša z vrtom · hiša z velikim vrtom | `apartment`, `large_home` |
| 2 | Koliko časa na dan lahko namenite sprehodom in gibanju? | < 30 min · 30–60 min · 1–2 h · več kot 2 h | `active_family`, `long_daily_exercise` |
| 3 | Koliko ur na dan bi bil pes sam? | skoraj nikoli · do 4 h · 4–8 h · več | `often_alone` |
| 4 | Ali imate otroke? Najmlajši je star … | ni otrok · do 5 let · 6–12 let · 13+ | `children`, `small_children`, `may_herd_children` |
| 5 | Bo to vaš prvi pes? | da · ne | `first_time_owner` |
| 6 | Imate druge živali? | ne · mačko · drugega psa · drugo | `other_pets` |
| 7 | Kako vas moti dlaka in krtačenje? | zelo · malo · ne moti | `low_shedding`, `sheds`, `frequent_grooming` |
| 8 | Kako velik pes vam je všeč? | majhen · srednji · velik · vseeno | velikost iz `data.json` |
| 9 | Koliko časa imate za učenje in igre za glavo? | malo · nekaj · veliko | `needs_mental_stimulation`, `chews_when_bored` |

Točkovanje (predlog, Claude):
- ujemanje z oznako `suits` = +2; konflikt z oznako `consider` (npr. < 30 min gibanja in `long_daily_exercise`) = −3;
- velikost: izbrana = +2, sosednja = 0, nasprotna = −2, »vseeno« = 0;
- **trdo izločanje** samo pri jasnem konfliktu z virom: stanovanje + pasma z `large_home`; »več kot 8 h sam«
  izloči vse pasme in prikaže sporočilo (§5);
- pri izenačenju odloči vrstni red priljubljenosti v registru;
- zdravstvene oznake (`brachycephalic_breathing`, `hips_hind_legs`, `heart_and_spine`, `back_spine`,
  `sensitive_skin`, kratka življenjska doba …) **ne odštevajo**, ampak se pri rezultatu prikažejo kot mirna oznaka
  »razmislite o« s pozivom k zdravstveno testiranim staršem (enako kot v aplikaciji, nikoli odstotek).

**Pokritost:** pred gradnjo prešteti, koliko od 111 pasem ima dovolj oznak za smiselno točkovanje. Pasme z manj kot
3 oznakami ne smejo biti v top 3 (rezultat bi temeljil na »ni podatka«). Če jih je premalo, kviz v1 izbira samo med
pokritimi pasmami in to pove: »Izbiramo med X pasmami, za katere imamo preverjene vire.«

## 5. Odgovornost (pozicioniranje)

- **Vedno 4. kartica: »Razmislite tudi o posvojitvi«** — mešanček iz zavetišča, z dejstvi iz `adopted_vs_bought` in
  `medium_mixed_breed` v `data.json`. Brez tega bi kviz izpadel kot reklama za vzreditelje. Odpre partnerstva z zavetišči.
- Če odgovori kažejo, da pes ta čas ne bi bil srečen (npr. več kot 8 h sam vsak dan), rezultat to pove prijazno in
  predlaga PetPrep kot preizkus »ali naša družina zmore« — ne vrne pasme na silo.
- Na dnu: »Kviz je pomoč pri razmisleku, ne nadomesti pogovora z veterinarjem ali zavetiščem.«

## 6. Razkritje rezultata in three.js

- v1: kartice rezultata brez 3D (hitro, preverljivo).
- v2: **»wow« je razkritje** — top 3 pasme v merilu ob otroku, kavču in vratih. To je primerjalnik velikosti
  (THREEJS §3 #2): ena koda za dve orodji. Višine in teže z viri so že v registru.
- Vprašanja kviza ostanejo brez 3D (odpad na telefonu).

## 7. Upsell, e-naslov, deljenje

- **CTA PetPrep** pri vsaki pasmi: »Preizkusite <pasmo> v PetPrepu, preden jo kupite«. Ker aplikacija še ni v trgovinah,
  CTA vodi na čakalni seznam / prednaročilo s pasmo kot parametrom; ko bo v trgovinah, na prenos (pasmo si zapomni
  spletna stran, ne aplikacija — v aplikacijo ne gre nič o otroku).
- **Povezava na kalkulator** pri vsaki pasmi: kalkulator se razširi na vse pasme in bere podatke iz registra, zato vsaka
  nova pasma dobi svojo stran samodejno (David 16. 11. 2026; prej samo labradorec, 15. 11. 2026).
- **Brez PDF-jev in brez Lemon Squeezy za povzetke (David 16. 11. 2026; prodaja bonov in »Obljube« ostane, kjer je).** Povzetek »Vaše pasme« (top 3 + oznake + stroški) je
  dinamična stran za tisk, enako kot povzetek kalkulatorja (T8); starš jo natisne ali shrani sam.
- **E-naslov zbiramo, a ni pogoj** za rezultat: isto polje in ista privolitev kot pri kalkulatorju, isti
  `POST /api/summary-leads` (M6-S05) z novo vrednostjo `source: breed_quiz` (strežnik danes sprejme samo `real_cost` —
  majhna sprememba ob gradnji) in `breed` = prva pasma rezultata. En e-naslov ostane ena vrstica.
- **Deljenje:** rezultat ima svoj URL z odgovori v parametrih (brez osebnih podatkov) in sliko za deljenje
  (»Za našo družino: …«).

## 8. Zasebnost in podatki

**David 16. 11. 2026: anonimno štetje je dovoljeno.**

- Kviz teče v brskalniku; odgovori se ne pošiljajo na strežnik PetPrep.
- Brez analitičnih orodij brez Davidovega OK (pravilo repozitorija). Če David odobri, šteti samo anonimno
  (zaključeni kvizi, kateri top 3, kliki na CTA) — to je tudi vrednost za sponzorja (»koliko družin je razmišljalo o X«),
  enako kot pri kalkulatorju: partner dobi številke, ne naslovov.
- **Strežniški del zgrajen 16. 11. 2026 (M6-S06, backend):** `POST /api/breed-quiz/events` — javno, brez piškotkov, 60 na uro na IP (IP je samo ključ omejitve, ne shranjuje se in ne beleži), skrito polje `website` mora biti prazno (sicer 204 in nič shranjeno). Telo `{ "event": "completed" | "calculator_click" | "app_click", "locale": ena od 15 jezikov strani, "breeds": [id pasme] }`; `completed` → `breeds` = top 3 po vrstnem redu (1–3 id-ji), klika → natanko 1 id (kartica); id `^[a-z0-9_]{1,64}$`, brez iskanja v bazi. Odgovor `204`, pri neveljavnem vnosu `422`, ob preseženi omejitvi `429`. Shramba: tabela `breed_quiz_stats` (dan UTC · dogodek · pasma · mesto · jezik · števec, atomarni upsert, rang NULL pri klikih, `NULLS NOT DISTINCT`); pregled v administraciji (superadmin). E-naslov iz kviza: `POST /api/summary-leads` z `source: breed_quiz`, `breed` = prva pasma rezultata. Podrobnosti: ARCHITECTURE §3 + tabela `breed_quiz_stats`.
- Kartica rezultata ima prostor za partnerja (enako kot kalkulator, poziv »Želite postati naš partner?«).

## 9. Tehnika (predlog)

- Stran v `pet-prep-website` (Next.js), vseh 15 jezikov; vprašanja in točkovanje kot čista funkcija s testi
  (vhod: odgovori + podatki pasem → izhod: razvrščen seznam z razlogi).
- Podatki: `registry.json` na spletni strani + izvoz oznak iz `backend/config/breed_suitability.php`
  (preveriti, ali so oznake že v izvozu za splet; če ne, skripta izvoza, da ostane en vir resnice).
- Prevodi oznak iz obstoječih nizov `breedSuitability.<tag>` (naravno, ne dobesedno).
- Meritve uspeha (če David odobri štetje): delež zaključenih kvizov, klik na kalkulator, klik na CTA PetPrep.

## 10. Odločitve (David, 16. 11. 2026)

1. Kalkulator za vse pasme, dinamično tudi za prihodnje — **narejeno** (M6-S07, pet-prep-website #442).
2. Podstran **v glavi strani**.
3. Kviz v1 **samo psi**.
4. **Anonimno štetje dovoljeno** (§8): števci na našem strežniku, brez osebnih podatkov in piškotkov.
5. **Gradimo zdaj.**
6. **Vseh 15 jezikov** — pravilo za vse na spletni strani (»Vse mora biti v vseh jezikih ki jih podpiramo. To je. Pravilo«).
