# Bouvier des Flandres (Flandrijski govedar)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-105 · 2026-11-12

| | |
|---|---|
| Ključ / slug | `bouvier_des_flandres` / `bouvier-des-flandres` (SL `flandrijski-govedar`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 980 — v izbirniku za beauceronom |
| Vir podatkov | `docs/research/dog-data/data.json` → `bouvier_des_flandres` (viri S795–S799; ponovno S8, S10, S11, S14, S24, S35, S45, S123, S174, S268); seznam za vzreditelja: stran RKC (S795) |
| Izvor | FCI št. 191 »Bouvier des Flandres-Vlaamse Koehond«, skupina 1, sekcija 2 (goniči goveda), Belgija / Francija (S796, S797) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 101. mesto »Bouvier des Flandres« (S174, prebrano 2026-11-12) |
| PR-ji | pet-prep #1065 (pasma, združen `c7932f7`) · portret pet-prep PR `feat/M5-R10-105-bouvier-des-flandres-portrait` (zagon `breed-portraits.yml` 43130234203, sprejet v prvem poskusu) · spletna stran: pet-prep-website `feat/register-bouvier-des-flandres` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-12 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček / mlad 10 min × starost do 60 (pri 6 mesecih) | stran RKC S795 »Exercise: Up to 1 hour per day« (»up to N« → N); viri se ne ujemajo — PetMD S798 »They need at least two hours of exercise every day« (120 min) ostane alternativa, po pravilu velja RKC | #1065 |
| 2026-11-12 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **102**; prihod 2 / 9 / 36 / 102 | AAHA S11; starejši = 0,75 × mediana McMillan 2024 **11,3 leta** (tabela Dogs Trust S123, »Bouvier des Flandres 11.3 10.4 - 11.9 151 79«) = 101,7 → 102 meseca. Ni izbrano: 90 (spodnja meja RKC »Over 10 years«) | #1065 |
| 2026-11-12 | pravilo runbooka | učenje **1,2×** | Coren 29. mesto (»Above Average Working Dogs«, tabela Wikipedia S35; skupaj z airedalskim terierjem) | #1065 |
| 2026-11-12 | pravilo runbooka | teža **27–40 kg** (FCI: psi približno 35–40 kg, psice približno 27–35 kg), višina psi 62–68 cm, psice 59–65 cm (FCI), rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | standard FCI št. 191 (kopija KUSA S797); AKC tabela teže 70–110 lb (S268) ostane alternativa; PetMD S10 (razred »Large«) | #1065 |
| 2026-11-12 | pravilo runbooka (M5-R13) | česanje **vsak dan (7× na teden)**; skuštrana dlaka po 4 zamujenih | RKC S795 »Grooming: Every day« → 7 (pravilo »Every day« → 7). PetMD S798 »Brush your Bouvier at least once or twice a week …« → 2 ostane alternativa (odprto za Davida) | #1065 |
| 2026-11-12 | pravila »Breed care tasks« (David 2026-10-24, M5-R18 / R19) | **čiščenje ušes vsaka 2 tedna** in **striženje krempljev vsake 4 tedne** (mini igri za otroka) | PetMD S798 »clean a Bouvier des Flandres’ ears with a routine ear cleaner every two to three weeks,« (spodnja meja → 2 tedna) in »A Bouvier’s nails should be trimmed every three to four weeks …« (3 tedni niso programsko obdobje → 4 tedne, precedens lhasa apso). Kopanje le, ko je dlaka umazana; frizer »po potrebi«; zobje — ni pogostosti → brez naloge | #1065 |
| 2026-11-12 | David 2026-10-12 (M5-R16) | opozorilo »počakaj s sprehodom« 60 min po obroku | PetMD S798 »Prevent exercise one hour before and one hour after eating« (napihnjenost / GDV) | #1065 |
| 2026-11-12 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S798 »Bouvier des Flandres puppies need early socialization that stays consistent throughout life,« | #1065 |
| 2026-11-12 | pravilo runbooka | brez poletnega opozorila za sprehod | ni ploskoobrazna pasma (»sub-brachymorphic« v standardu opisuje kratko, čokato telo, ne obraza); vir ne svari pred vročino | #1065 |
| 2026-11-12 | pravilo runbooka | oznake: primeren za **»družino z otroki«** in **»veliko hišo z vrtom«**; upoštevajte **»potrebuje česanje«**, **»rad ima družbo«**, **»rad je — pazite na težo«**, **»močan lovski nagon«** | PetMD S798 »They can be good with young children and other large dogs when introductions are supervised …« (nadzor v opombah); RKC S795 »Large house«, »Large garden«, »Grooming: Every day«; PetMD S798 najbolje pri ljudeh, ki delajo od doma; »prone to being overweight«; »Bouviers also have a strong prey drive …« | #1065 |
| 2026-11-12 | pravilo runbooka | **brez** `active_family`, `long_daily_exercise` (RKC do 1 ure ↔ PetMD vsaj 2 uri), `sheds` / `low_shedding` (RKC »Sheds: No« ↔ PetMD česanje »to reduce shedding«), `family_pet`, `small_children`, `other_pets`, `apartment`, `experienced_owner`; nikoli »hypoallergenic« | viri si nasprotujejo ali A/B navedbe ni | #1065 |
| 2026-11-12 | pravilo runbooka (§3, dobrobit) | RKC Breed Watch **kategorija 1** → brez opozorilnega čipa; **v aplikaciji da, v marketingu da** (samo naravna, nekupirana ušesa in nekupiran rep) | S795 | #1065 |
| 2026-11-12 | pravilo runbooka (§3) | barve za AI in izbirnik: **temno siv tigrast** (portret, največja utež), tigrast, tigrast s črnim nadihom, črn (»sprejet, a ne zaželen«); vedno brki, polna brada in naravna ušesa / rep; nikoli rumen (fawn), bel, čokoladno rjav ali »poper in sol« | FCI S797 »The Bouvier des Flandres’ coat is usually grey, brindle, or overlaid with black.« / »A completely uniform black is also accepted, without being favoured.«; uteži brez vira → `verified: false` | #1065 |
| 2026-11-12 | pravilo runbooka (§2.3) | seznam za vzreditelja: minimum genska pestrost; »Best Practice« komolci (BVA/KC), oči (BVA/KC/ISDS), kolki (BVA/KC) — brez novega ključa | stran RKC S795 | #1065 |
| 2026-11-12 | pravilo runbooka | zdravstveni ključi registra: `hip_elbow_dysplasia`, `bloat_gdv`, `heart_sas`, `hypothyroidism`, `eye_conditions`, `exercise_induced_collapse`; brez odstotkov | PetMD S798 (naslovi poglavij); RKC S795 (sheme) | #1065 |
| 2026-11-12 | predlog Claude, David lahko spremeni | EN »Bouvier des Flandres«, SL **»Flandrijski govedar«**; iskanje tudi »bouvier«, »flanders cattle dog«, »vlaamse koehond« | mednarodno ime (RKC, AKC, FCI; FCI tudi »Vlaamse Koehond«, angl. »Flanders Cattle Dog«); strani KZS nisem prebral | #1065 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »bouvier«, »flandrijski govedar« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-105 | 2026-11-12 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 102. meseca; 4 → 3 → 2 obroka; učenje 1,2×) | 🛠 | 💶 izziv | M5-R10-105 | 2026-11-12 |
| Česanje vsak dan, čiščenje ušes vsaka 2 tedna, striženje krempljev vsake 4 tedne, »Nova izkušnja« za mladička, opozorilo po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R18 / M5-R19 / M5-R17 / M5-R16 | 2026-11-12 |
| Barva (izbirnik: temno siv tigrast, tigrast, tigrast s črnim nadihom, črn) | 🛠 | 💶 izziv | M5-R21 | 2026-11-12 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), temno siv tigrast, brki in brada, naravna ušesa in rep | — | M5-R11-04 | 2026-11-12 |
| Stran na petprep.si (`/animals/dogs/bouvier-des-flandres`, `/sl/zivali/psi/flandrijski-govedar`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal flandrijskega govedarja (bouvier des Flandres) — čokatega belgijsko-francoskega goniča goveda (27–40 kg po FCI) z grobo, skuštrano dlako, brki in polno brado; 101. najbolj priljubljeno pasmo po AKC 2024 in 97. pasmo psa v aplikaciji. 5 novih virov (S795–S799). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do ene ure gibanja), česanje vsak dan, čiščenje ušes vsaka 2 tedna, striženje krempljev vsake 4 tedne in počitek po obroku; starejši postane pri 102 mesecih (mediana 11,3 leta, McMillan 2024).
**Zakaj je pomembno:** Bouvier je miren, preudaren in neustrašen pes, ki rad ima družino — a njegova gosta, groba dlaka potrebuje vsakodnevno nego, rad se zredi, ima močan lovski nagon do manjših živali in najraje ima nekoga doma. PetPrep pokaže, ali otrok vsak dan zmore česanje in redno nego, preden pride pravi pes.
**Kako povedati** — starši: »Flandrijski govedar je miren in zvest družinski spremljevalec, a njegova dlaka potrebuje vsakodnevno česanje, ušesa in kremplji pa redno nego. V PetPrepu boste videli, ali vaš otrok zmore tak ritem.« · otroci: »Bouvier je velik kosmat pes z brado — vsak dan ga počešeš, vsaka dva tedna mu očistiš ušesa in po obroku počakaš s sprehodom.« · vlagatelji / partnerji: »Sedemindevetdeseta pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, pravili nege, dvema mini igrama nege in opozorilom po obroku.«

## Odprto za Davida

- **Gibanje 60 min (RKC) ali 120 min (PetMD):** RKC »Up to 1 hour per day«, PetMD »They need at least two hours of exercise every day«. Po pravilu velja RKC (6.000 korakov). Če želite 12.000 korakov, povejte.
- **Česanje vsak dan (RKC) ali 2× na teden (PetMD):** RKC »Grooming: Every day« → 7; PetMD »at least once or twice a week« → 2. Pri cavalierju ste izbrali 4 — povejte, ali želite manj kot vsak dan.
- **Kremplji vsake 4 tedne:** vir pravi »every three to four weeks«; 3 tedni niso programsko obdobje, zato 4 tedne (kot pri lhasa apsu).
- **Ime:** »Flandrijski govedar« v slovenščini ni preverjen na straneh KZS. Potrdite ali popravite.
