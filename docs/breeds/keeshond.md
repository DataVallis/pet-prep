# Keeshond (volčji špic)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-94 · 2026-11-05

| | |
|---|---|
| Ključ / slug | `keeshond` / `keeshond` (SL `keeshond`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 880 — v izbirniku za staroangleškim ovčarjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `keeshond` (viri S709–S710; ponovno S10, S11, S14, S24, S35, S45, S54, S174, S245, S246); seznam za vzreditelja: ni najden (`not_found`) |
| Izvor | FCI št. 97 (nemški špic, različica volčji špic / keeshond), skupina 5, sekcija 4 (evropski špici), Nemčija (S246, S245) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 90. mesto »Keeshond« (S174, prebrano 2026-11-05) |
| PR-ji | pet-prep #749 (pasma, združen `5a29d95`) · portret pet-prep #754 (zagon `breed-portraits.yml` 41945256571, koda `5a29d95`, brez namestitve; sprejet v prvem poskusu) · spletna stran: pet-prep-website `feat/register-keeshond` (PR sledi) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-05 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | PDSA S709 »They need up to an hour of exercise per day to keep them mentally and physically fit.« in »Minimum exercise (per day): 1 hour« (eno število N → N); stran RKC ni bila dosegljiva | #749 |
| 2026-11-05 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 (vsi psi) | #749 |
| 2026-11-05 | **predlog Claude — čaka Davida** | starejši od **90. meseca** (meja in prihod starejšega psa, `verified=false`) | 0,75 × **10 let** — spodnja meja PDSA »Over 10 years« (S709) = 7,5 leta; runbook našteje McMillan 2024, mediano VetCompass in spodnjo mejo RKC — nobena ni bila dosegljiva (McMillan le v dodatni tabeli 3, stran RKC zavrnjena). Ni izbrano: 108 (VCA 12–14 let, S710), 84 (Dogs Trust »> 7 let«) | #749 |
| 2026-11-05 | pravilo runbooka | učenje **1,5×** | Coren: 16. mesto »Keeshond« (skupaj s collijem), skupina »Excellent« (11–26) (S35, preverjeno 2026-11-05); FCI S246 »It is very teachable and easy to train.« | #749 |
| 2026-11-05 | pravilo runbooka | teža **16–20 kg** (PDSA, oba spola); višina 43–55 cm (FCI 49 ± 6 cm); rast do 12–15 mesecev (razred »Medium«); hrana, voda in čiščenje kot pri border collieju | PDSA S709 »16-20 kg«; FCI S246 »Height at the withers: Wolfsspitz/Keeshond: 49 ± 6 cm.«, teže v številki ne navaja; PetMD S10 | #749 |
| 2026-11-05 | pravilo runbooka (M5-R13) | česanje **vsak dan** (7× na teden); skuštrana dlaka po 4 zamujenih | PDSA S709 »Grooming is a daily requirement to keep their thick double coat in good condition.«; VCA S710 »once or twice weekly« = alternativa (PDSA ima prednost, kot pri gibanju) | #749 |
| 2026-11-05 | pravila »Breed care tasks« (David 2026-10-24, M5-R20) | **opomnik staršu za kopanje vsake 4 tedne** (ne šteje v oceno) | VCA S710 »It needs bathing every month or so.« → 28 dni | #749 |
| 2026-11-05 | pravila »Breed care tasks« | **brez drugih nalog nege** (zobje, ušesa, kremplji, frizer) | viri ne navajajo pogostosti | #749 |
| 2026-11-05 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) — **Davidova splošna vrstica** (brez lastnega citata) | PDSA navaja le povezavo na splošno stran o socializaciji, ne nasveta za pasmo | #749 |
| 2026-11-05 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila »počakaj s sprehodom« po obroku in **brez** opozorila za vročino | viri premora po obroku ne omenjajo; ni ploskoobrazna pasma (VCA: gosta dlaka »may make it prone to overheating« — ostane dejstvo v besedilu) | #749 |
| 2026-11-05 | pravilo runbooka | primeren za: **družinsko življenje**, **družino z otroki**, **dom z drugimi ljubljenčki**; upoštevajte: »potrebuje česanje«, »izpada mu dlaka« | PDSA S709 »Affectionate and loving, Keeshonds make excellent family pets.«, »They'll also get along happily with other pets in the household.«, »Grooming is a daily requirement …«; VCA S710 »Playful and careful with children.«, »Shedding is above average.« | #749 |
| 2026-11-05 | pravilo runbooka | **brez** čipov `small_children` (ni izrecne ocene za majhne otroke), `apartment` / `large_home` (le iz RKC), `hips_hind_legs` (Breed Watch ni bil dosegljiv), `chases_small_pets` (FCI »lack of hunting instinct«), `food_motivated_weight` (VCA le nagnjenost k debelosti); nikoli »hypoallergenic« | S246, S709, S710 | #749 |
| 2026-11-05 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** (ni ploskoobrazna, ni izrecnega veterinarskega odsvetovanja oglaševanja) | FCI S246 gobec »not overlong … (approximately 2:3)« | #749 |
| 2026-11-05 | pravilo runbooka (§3) | barva za AI in izbirnik: **samo volčje siva** (srebrno siva s črnimi konicami, temen gobec in ušesa, »očala« okoli oči, svetlejša griva, črna konica repa) — edina barva standarda; nikoli bele lise | FCI S246 »Colour: Wolfsspitz/Keeshond: Grey-shaded.«; »Definite white markings or spots.« = izključujoča napaka | #749 |
| 2026-11-05 | pravilo runbooka (§2.3) | seznam za vzreditelja: **ni najden** (prikaže se le splošni seznam za pse) | stran RKC ni bila dosegljiva; PDSA omenja programe BVA in KC brez imena sheme za pasmo | #749 |
| 2026-11-05 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia`, `epilepsy`, `kneecap_luxation`; brez odstotkov, brez novega ključa | PDSA S709 | #749 |
| 2026-11-05 | predlog Claude, David lahko spremeni | EN »Keeshond«, SL »Keeshond«; iskanje tudi »volčji špic«, »wolfsspitz«, »nemški volčji špic«, »keeshonden« | FCI S246 »In nonGerman speaking countries Wolfsspitz dogs are known as Keeshonds.«; strani KZS nisem preveril | #749 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »keeshond«, »volčji špic« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-94 | 2026-11-05 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 90. meseca — predlog; 4 → 3 → 2 obroka; učenje 1,5×) | 🛠 | 💶 izziv | M5-R10-94 | 2026-11-05 |
| Česanje vsak dan, »Nova izkušnja« za mladička, opomnik staršu za kopanje vsake 4 tedne | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R20 | 2026-11-05 |
| Barva (izbirnik: 1 barva standarda — volčje siva) | 🛠 | 💶 izziv | M5-R21 | 2026-11-05 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«; volčje siva, »očala«, rep zavit čez hrbet) | — | M5-R11-04 | 2026-11-05 |
| Stran na petprep.si (`/animals/dogs/keeshond`, `/sl/zivali/psi/keeshond`) | 🛠 s portretom (spletni PR sledi); »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal keeshonda (nemškega volčjega špica) — srednje velikega, puhastega psa iz Nemčije s sivo »volčjo« dlako in značilnimi »očali« okoli oči; 90. najbolj priljubljeno pasmo po AKC 2024 in 88. plačljivo pasmo psa (z border collijem). 2 nova vira (S709–S710) in ponovno prebran standard FCI. Odrasel pes v igri potrebuje 6.000 korakov na dan (PDSA: do ene ure gibanja), česanje vsak dan, staršem aplikacija vsake 4 tedne pripomni kopanje.
**Zakaj je pomembno:** Keeshond je prijazen družinski pes, ki se razume z otroki in drugimi živalmi in se hitro uči (Coren: 16. mesto) — a gosta dvojna dlaka pomeni vsakodnevno česanje in veliko dlak po stanovanju. To je točno tisto, kar otrok v PetPrepu preizkusi pred pravim psom.
**Kako povedati** — starši: »Keeshond je ljubeč družinski pes, ki se rad uči, a njegovo gosto dlako je treba česati vsak dan, enkrat na mesec pa ga okopati. V PetPrepu boste videli, ali vaš otrok to zmore.« · otroci: »Keeshond je puhast sivi pes z 'očali' — vsak dan ga počešeš in greš z njim na sprehod, hitro se nauči novih trikov.« · vlagatelji / partnerji: »Oseminosemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, nalogami nege in pravili.«

## Odprto za Davida

- **Starejše obdobje od 90. meseca** je moj predlog (`verified=false`): runbook za življenjsko dobo našteje McMillan 2024, VetCompass in spodnjo mejo RKC — nič ni bilo dosegljivo; PDSA navaja »Over 10 years« (spodnja meja, enako kot pri staroangleškem ovčarju), zato 0,75 × 10 let. VCA navaja 12–14 let (to bi dalo 108). Potrdite ali določite drugo vrednost (seeder je samo-vstavljalni → sprememba prek Filamenta ali podatkovne migracije).
- **Česanje:** PDSA »daily requirement« (7× na teden, uporabljeno) proti VCA »once or twice weekly« (bi dalo 2). Po pravilu ima prednost PDSA; če želite manj, odločite.
- **Stran RKC ni bila dosegljiva** (preusmeritev na royalkennelclub.com zavrnjena): zato ni čipov »stanovanje« / »velika hiša«, ni kategorije Breed Watch in ni testov RKC za vzreditelja (`not_found`). Ob naslednji priložnosti preverite.
- **Vročina:** VCA piše, da se zaradi goste dlake lahko pregreje (»may make it prone to overheating«); opozorilo za sprehod v vročini je po pravilu runbooka le za ploskoobrazne pasme, zato ga ni. Če ga želite tudi tu, je to nova odločitev (nova naloga v ROADMAP — David).
- **Ime:** »Keeshond« (SL enako kot EN, kot pri bloodhoundu); »volčji špic« je v iskanju. Ime na straneh KZS ni preverjeno. Potrdite ali popravite.
- **Ugotovitev za skupne dokumente (ne urejam jih):** to je 88. plačljiva pasma psa (89 pasem psov z mešancem); skupni dokumenti, ki naštevajo število pasem, se sklicujejo na `docs/breeds/`.
