# Borzoi (Ruski hrt)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-106 · 2026-11-12

| | |
|---|---|
| Ključ / slug | `borzoi` / `borzoi` (SL `ruski-hrt`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 990 — v izbirniku za flandrijskim govedarjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `borzoi` (viri S800–S804; ponovno S8, S10, S11, S14, S24, S35, S45, S174, S268); seznam za vzreditelja: PDSA (S800) |
| Izvor | FCI št. 193 »Russkaya Psovaya Borzaya« (»Borzoi – Russian Hunting Sighthound«), skupina 10, sekcija 1 (dolgodlaki hrti), Rusija (S801, S802) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 102. mesto »Borzoi« (S174, zapisano 2026-11-12) |
| PR-ji | pet-prep #1070 (pasma, združen `1f2494a`) · portret pet-prep PR `feat/M5-R10-106-borzoi-portrait` (zagon `breed-portraits.yml` 43143770168, sprejet v prvem poskusu) · spletna stran: pet-prep-website `feat/register-borzoi` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-12 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček / mlad 10 min × starost do 60 (pri 6 mesecih) | PDSA S800 »Minimum exercise (per day): 1 hour« (»minimum N« → N); stran RKC ni bila dosegljiva → PDSA | #1070 |
| 2026-11-12 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 (vsi psi) | #1070 |
| 2026-11-12 | **predlog Claude — čaka Davida** (`verified=false`) | starejši od **90. meseca** (tudi prihod starejšega) | 0,75 × spodnja meja PDSA »Over 10 years« (S800) = 90. Tabela McMillan 2024 (Dogs Trust S123) in stran RKC nista bili dosegljivi, mediane VetCompass ni → najbližja vrstica pravila (precedens bull terier, keeshond). Alternativi: 108 (McMillan 2024 12 let prek Wikipedije S804, raven C), 81 (PetMD 9–14 let, S803) | #1070 |
| 2026-11-12 | pravilo runbooka | učenje **0,5×** | Coren 75. mesto (»Lowest Degree of Working/Obedience Intelligence«, tabela Wikipedia S35) | #1070 |
| 2026-11-12 | pravilo runbooka | teža **27–47 kg** (PDSA, oba spola), višina psi 75–85 cm, psice 68–78 cm (FCI), rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S800 »Average weight: 27-47 kg« (standard FCI teže ne navaja); AKC tabela teže 60–105 lb (S268) in PetMD (S803) sta alternativi; PetMD S10 (razred »Large«) | #1070 |
| 2026-11-12 | pravilo runbooka (M5-R13) | česanje **3× na teden**; skuštrana dlaka po 2 zamujenih | PDSA S800 »need brushing a few times a week« nima števila → najbolj natančno število drugega vira: PetMD S803 »at least two to three times a week« → zgornja meja 3. PetMD »daily brushing is best« (7) ostane alternativa (odprto za Davida) | #1070 |
| 2026-11-12 | David 2026-10-12 (M5-R16) | opozorilo »počakaj s sprehodom« 60 min po obroku | PDSA S800 »Remember to leave a gap between eating and exercising.«; PetMD S803: napihnjenost (GDV) je najpogostejša težava | #1070 |
| 2026-11-12 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PDSA S800 »socialise your Borzoi from a young age with lots of different people, dogs and experiences« | #1070 |
| 2026-11-12 | pravila »Breed care tasks« (David 2026-10-24) | **brez** nalog nege (kremplji, ušesa, zobje, kopanje, frizer) | viri ne navajajo pogostosti: kremplji »regularly«, kopanje »occasional«, frizer »ask a groomer«, ušesa le pregled ob česanju | #1070 |
| 2026-11-12 | pravilo runbooka | brez poletnega opozorila za sprehod | ni ploskoobrazna pasma (dolga, ozka glava) | #1070 |
| 2026-11-12 | pravilo runbooka | oznake: **brez** »primeren za«; upoštevajte **»potrebuje česanje«**, **»ni za dom z majhnimi otroki«**, **»potrebuje izkušenega lastnika«**, **»rad ima družbo«**, **»izpada mu dlaka«**, **»močan lovski nagon«** | PDSA S800 »a few times a week« + PetMD S803 »two to three times a week«; »we wouldn’t recommend a Borzoi if you have younger children«; »For this reason, Borzois suit experienced owners familiar with their needs.«; »Ideally they need to be in a home where someone is around all the time«; »They do shed …«; »Borzois do have a very high prey drive …« | #1070 |
| 2026-11-12 | pravilo runbooka | **brez** `children` (PDSA odsvetuje mlajše otroke ↔ PetMD »great with people of all ages, including children«), `family_pet`, `large_home` / `apartment` (le iz RKC — ni dosegljiv), `active_family`, `long_daily_exercise`, `other_pets`, `low_shedding`; nikoli »hypoallergenic« | viri si nasprotujejo ali A/B navedbe ni | #1070 |
| 2026-11-12 | pravilo runbooka (§3, dobrobit) | **v aplikaciji da, v marketingu da**; brez opozorilnega čipa | ni ploskoobrazna (BVA S92 ne velja). PDSA S800: RKC Breed Watch »Category Two« (točke glede oblike gobca / čeljusti — le parafraza) → odprto za Davida | #1070 |
| 2026-11-12 | pravilo runbooka (§3) | barve za AI in izbirnik: **bel z rdečimi lisami** (portret, največja utež), bel, rdeč, rdeče sobolji, tigrast, črno-rjavkast, črn; vedno naraven rep; nikoli rjav / čokoladen, moder, lila ali ne-črn nos | FCI S802 »Any colours from white to black in any combination are acceptable,« razen rjave, modre, isabella; uteži brez vira → `verified: false` | #1070 |
| 2026-11-12 | pravilo runbooka (§2.3) | seznam za vzreditelja: DNA test za degenerativno mielopatijo (`dna_dm`, other_scheme) — brez novega ključa | PDSA S800 »Parents can be DNA tested prior to breeding.«; stran RKC ni bila dosegljiva | #1070 |
| 2026-11-12 | pravilo runbooka | zdravstveni ključi registra: `bloat_gdv`, `degenerative_myelopathy`, `hip_elbow_dysplasia`, `eye_conditions`; brez odstotkov | PetMD S803 (naslovi poglavij), PDSA S800 | #1070 |
| 2026-11-12 | predlog Claude, David lahko spremeni | EN »Borzoi«, SL **»Ruski hrt«**; iskanje tudi »borzoj«, »russian hunting sighthound«, »ruski chrt« (brez »russian wolfhound« — iskanje »wolfhound« naj najde le irskega volčjega hrta) | mednarodno ime (RKC, AKC); FCI »Russian Hunting Sighthound«; strani KZS nisem prebral | #1070 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 3× na teden«, iskanje »borzoi«, »ruski hrt« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-106 | 2026-11-12 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 90. meseca — predlog; 4 → 3 → 2 obroka; učenje 0,5×) | 🛠 | 💶 izziv | M5-R10-106 | 2026-11-12 |
| Česanje 3× na teden, »Nova izkušnja« za mladička, opozorilo po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 | 2026-11-12 |
| Barva (izbirnik: bel z rdečimi lisami, bel, rdeč, rdeče sobolji, tigrast, črno-rjavkast, črn) | 🛠 | 💶 izziv | M5-R21 | 2026-11-12 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), bel z rdečimi lisami, naraven pernat rep, črn nos | — | M5-R11-04 | 2026-11-12 |
| Stran na petprep.si (`/animals/dogs/borzoi`, `/sl/zivali/psi/ruski-hrt`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal ruskega hrta (borzoja) — visokega, vitkega in elegantnega dolgodlakega hrta iz Rusije (27–47 kg, psi 75–85 cm); 102. najbolj priljubljeno pasmo po AKC 2024 in 98. pasmo psa v aplikaciji. 5 novih virov (S800–S804). Odrasel pes v igri potrebuje 6.000 korakov na dan (PDSA: vsaj ena ura gibanja), česanje 3× na teden in počitek po obroku; starejši postane pri 90 mesecih (predlog, čaka Davida).
**Zakaj je pomembno:** Borzoj je miren in nežen, a ima zelo močan lovski nagon, ne sodi k majhnim otrokom ali manjšim živalim, potrebuje izkušenega lastnika in nekoga doma. PetPrep pokaže, ali družina zmore redno česanje, umirjen ritem in pozornost do psa, ki ga je treba spuščati le na varnem, ograjenem mestu.
**Kako povedati** — starši: »Ruski hrt je miren in nežen, a ni pes za družino z majhnimi otroki ali mačko. V PetPrepu boste videli, ali vaš otrok zmore redno česanje in mirne sprehode.« · otroci: »Borzoj je zelo visok kosmat hrt — trikrat na teden ga počešeš in po obroku počakaš s sprehodom.« · vlagatelji / partnerji: »Oseminindevetdeseta pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, pravili nege, opozorilom po obroku in jasnim opozorilom, za koga ni.«

## Odprto za Davida

- **Starejši od 90. meseca (predlog, `verified=false`):** 0,75 × spodnja meja PDSA »Over 10 years«. Tabele McMillan 2024 (Dogs Trust) in strani RKC orodje ni prebralo; Wikipedija navaja 12 let iz McMillan 2024 (→ 108), PetMD 9–14 let (→ 81). Potrdite 90 ali izberite drugo vrednost (sprememba = Filament ali podatkovna migracija).
- **Česanje 3× na teden ali vsak dan:** PetMD »at least two to three times a week« (→ 3) in »daily brushing is best« (→ 7). Po pravilu 3.
- **Breed Watch kategorija 2:** PDSA navaja RKC kategorijo 2 (oblika gobca / čeljusti → boleče težave z zobmi), besedila točk ni bilo mogoče prebrati. Ali želite opozorilni čip (nov ključ)?
- **Oznaka »potrebuje izkušenega lastnika«:** besedilo čipa pravi »močan pes«; PDSA utemeljuje z neodvisnostjo in trmo pri šolanju (precedens weimaraner).
- **Ime:** »Ruski hrt« v slovenščini ni preverjen na straneh KZS. Potrdite ali popravite.
