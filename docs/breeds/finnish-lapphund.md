# Finski laponski pes (Finnish Lapphund)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-133 · 2026-11-23

| | |
|---|---|
| Ključ / slug | `finnish_lapphund` / `finnish-lapphund` (SL `finski-laponski-pes`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1240 — v izbirniku za napolitanskim mastifom |
| Vir podatkov | `docs/research/dog-data/data.json` → `finnish_lapphund` (viri S1002–S1008; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: RKC (S1004) |
| Izvor | FCI št. 189, skupina 5, sekcija 3 (nordijski čuvaji in pastirski psi), Finska (S1002, S1003) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 129. mesto »Finnish Lapphund« (S174, prebrano 2026-11-23: »128 Neopolitan Mastiff«, »129 Finnish Lapphund«, »130 Norfolk Terrier«) |
| PR-ji | pet-prep #1519 (pasma) · portret pet-prep (sledi) · barvne različice pet-prep (sledi) · spletna stran: pet-prep-website `feat/register-finnish-lapphund` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-23 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S1004 »Up to 1 hour per day« (»up to N« → N); PetMD S1006 »about 60–90 minutes every day« (spodnja meja 60 se ujema) | #1519 |
| 2026-11-23 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108**; prihod 2 / 9 / 36 / 108 | AAHA S11; starejši = 0,75 × spodnja meja RKC »Over 12 years« (S1004) = 9 let; mediane McMillan 2024 / VetCompass nisem dosegel (glej »Odprto«) | #1519 |
| 2026-11-23 | pravilo runbooka | učenje **1,0×** | pasma po znanem ni na Corenovem seznamu (S35 ni bil ponovno prebran po imenu) → pravilo »unranked« | #1519 |
| 2026-11-23 | pravilo runbooka | teža **15–24 kg** (oba spola), višina samci 46–52 cm, samice 41–47 cm (FCI 49 / 44 cm ± 3 cm), rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | PetMD S1006 »33–53 pounds« (standarda FCI in AKC teže ne navajata); WebMD S1007 enako; FCI S1003; PetMD S10 (razred »Medium«) | #1519 |
| 2026-11-23 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | RKC S1004 »Grooming: Once a week«; PetMD S1006 »a weekly brushing«; WebMD S1007 »once a week« (v času menjave dlake vsak dan — menjava ni modelirana) | #1519 |
| 2026-11-23 | pravila »Breed care tasks« (David 2026-10-24, M5-R15 / R19 / R20) | **ščetkanje zob vsak dan** in **striženje krempljev vsake 4 tedne** (otrokovi mini igri), **kopanje vsake 4 tedne** (opomnik staršem) | WebMD S1007 »daily with dog-friendly toothpaste«, »You should trim your Finnish Lapphund's nails once a month.«; PetMD S1006 »Monthly bathing is usually sufficient«. Ušesa: PetMD »vprašajte veterinarja« (brez pogostosti) → brez naloge; frizer: ni vira | #1519 |
| 2026-11-23 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S1006 »can get along with people of all ages when socialized as a puppy.« | #1519 |
| 2026-11-23 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« | noben vir ne svetuje premora med obrokom in gibanjem | #1519 |
| 2026-11-23 | pravilo runbooka | brez poletne prepovedi sprehoda in brez čipa »vročina« | pasma ni ploskoobrazna; WebMD S1007 le splošno »watch for signs of overheating in extreme heat« | #1519 |
| 2026-11-23 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družinsko življenje«**, **»družino z otroki«**; upoštevajte **»rad ima družbo«**, **»izpada mu dlaka«** | RKC S1004 »Up to 1 hour per day«, »Sheds: Yes«; WebMD S1007 »Lappies are good with children since they're a family companion breed.«; PetMD S1006 »Finnish Lapphunds are dogs that crave companionship.« | #1519 |
| 2026-11-23 | pravilo runbooka | **brez** `small_children` (PetMD: otroci naj se naučijo varnega ravnanja), `apartment` (RKC »Small house«), `other_pets` (pogojno), `chases_small_pets` (PetMD »happy to chase whatever creature crosses their path« — ne »high prey drive«), `secure_fencing` (le parafraza); nikoli »hypoallergenic« | S1004, S1006, S1007 | #1519 |
| 2026-11-23 | pravilo runbooka (§3) | RKC Breed Watch **kategorija 1** → brez opozorilnega čipa; **v aplikaciji da, v marketingu da** | S1004; veterinarsko odsvetovanje oglaševanja velja le za ploskoobrazne pse (BVA S92) | #1519 |
| 2026-11-23 | pravilo runbooka (§3) | barve za AI in izbirnik: **črno-rjav** (privzeto za portret), **črn**, **rjav**, **volčje siv**, **sobolji**, **krem**; naravna pokončna ušesa, naraven rep | FCI S1003 »All colours are permitted.« »The basic colour must be dominant.«; AKC S1005 »sable, wolfsable, or domino«; pogoste barve po Wikipediji (S1008, vir C) — izbor Claude; uteži brez vira → `verified: false` | #1519 |
| 2026-11-23 | pravilo runbooka (§2.3) | seznam za vzreditelja: genetska raznolikost, DNK test prcd-PRA — minimum; DNK test GSDII (nov ključ `dna_gsd2`), komolci in kolki (BVA/KC), oči (BVA/KC/ISDS) — najboljša praksa | RKC S1004 | #1519 |
| 2026-11-23 | pravilo runbooka | zdravstveni ključi registra: `hip_elbow_dysplasia`, `pra_eye_disease`; brez odstotkov, brez novega ključa | PetMD S1006, WebMD S1007 | #1519 |
| 2026-11-23 | predlog Claude, David lahko spremeni | EN »Finnish Lapphund« (RKC / AKC), FCI »Finnish Lapponian Dog« / »Suomenlapinkoira«; SL »finski laponski pes«; iskanje tudi »lapinkoira«, »lappie«, »laponski pes« | strani KZS nisem preverjal | #1519 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »finski laponski pes«, »finnish lapphund«, »lapinkoira« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-133 | 2026-11-23 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 108. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-133 | 2026-11-23 |
| Česanje 1× na teden, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-23 |
| Ščetkanje zob vsak dan, kremplji vsake 4 tedne (otrok); opomnik za kopanje vsake 4 tedne (starši) | 🛠 | 💶 izziv | M5-R15 / R19 / R20 | 2026-11-23 |
| Barve (izbirnik: 6 barv) | 🛠 | 💶 izziv | M5-R21 | 2026-11-23 |
| AI portret v registru | 🗓 sledi v tem zagonu | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/finnish-lapphund`, `/sl/zivali/psi/finski-laponski-pes`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal finskega laponskega psa — srednje velikega (15–24 kg) nordijskega pastirja severnih jelenov z gosto dlako, 129. najbolj priljubljeno pasmo po AKC 2024 in 124. pasmo psa v aplikaciji (poleg mešančka). 7 novih virov (S1002–S1008). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do ene ure gibanja), česanje enkrat na teden, vsak dan ščetkanje zob, vsake 4 tedne striženje krempljev; starši dobijo opomnik za kopanje enkrat na mesec.
**Zakaj je pomembno:** Prijazen, miren družinski pes, ki ne mara biti sam — hrepeni po družbi in rad laja. Gosta dvojna dlaka izpada, v času menjave dlake jo je treba česati vsak dan.
**Kako povedati** — starši: »Finski laponski pes je prijazen in potrpežljiv z otroki, a želi biti z družino in potrebuje redno česanje. V PetPrepu boste videli, ali vaš otrok to zmore 12 tednov.« · otroci: »Finski laponski pes je nekoč pomagal pasti severne jelene — danes pa najraje teče s teboj na sprehod in čaka, da ga počešeš.« · vlagatelji / partnerji: »124. pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal z viri — ključne podatke s strani Royal Kennel Club, mere iz standarda FCI, nego iz PetMD in WebMD (pregledano pri veterinarjih).«

## Odprto za Davida

- **Meja »starejši« (108 mesecev):** po pravilu runbooka iz spodnje meje RKC »Over 12 years«. Mediane McMillan 2024 nisem dosegel (berljivi del PDF-ja pasme ne navaja, tabela Dogs Trust je bila zavrnjena). PetMD / WebMD navajata 12–15 let. Ob ročnem pregledu tabele Dogs Trust lahko mejo popravite.
- **Coren:** Wikipedijine tabele nisem ponovno prebral po imenu; pasma po znanem ni uvrščena → 1,0×. Potrdite.
- **Ime:** »finski laponski pes« ni preverjen na straneh KZS (FCI »Finnish Lapponian Dog«). Potrdite ali popravite.
- **Barve:** standard dovoljuje vse barve; šest barv v izbirniku je izbor Claude po Wikipediji (vir C ob standardu A). Potrdite ali spremenite nabor.
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
