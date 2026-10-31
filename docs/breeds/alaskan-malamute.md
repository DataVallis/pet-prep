# Alaskan Malamute (aljaški malamut)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-83 · 2026-10-31

| | |
|---|---|
| Ključ / slug | `alaskan_malamute` / `alaskan-malamute` (SL `aljaski-malamut`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 770 — v izbirniku za lagottom romagnolom |
| Vir podatkov | `docs/research/dog-data/data.json` → `alaskan_malamute` (viri S637–S641; ponovno S8, S10, S11, S14, S24, S35, S45, S54, S123, S174); seznam za vzreditelja: AKC Working Group (K32), PDSA (S639) |
| Izvor | FCI št. 243, skupina 5, sekcija 1 (nordijski vlečni psi), ZDA (S637, S638) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 79. mesto (S174, prebrano 2026-10-31) |
| PR-ji | pet-prep #464 (pasma, združen `fbc4367`) · portret: PR (ta, zagon 41164901354) · spletna stran: pet-prep-website `feat/register-alaskan-malamute` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-31 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | PDSA S639 »Malamutes need a minimum of two hours of heavy exercise every day.« (»minimum N« → N); stran RKC ni bila dosegljiva; VCA S641 »a very long walk or jog … every day« se ujema | #464 |
| 2026-10-31 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 (za vse pse) | #464 |
| 2026-10-31 | **predlog Claude — čaka Davida** | starejši od **90. meseca** (tudi prihod starejšega 90; `verified: false`) | 0,75 × spodnja meja PDSA »Over 10 years« (S639) = 7,5 leta; PetMD S640 (10–14 let) in VCA S641 (10–12 let) imata isto spodnjo mejo. Tabela McMillan 2024 (Dogs Trust S123) in stran RKC nista bili dosegljivi, mediane VetCompass ni → najbližja vrstica pravila (precedens samojed). Ni izbrano: 84 (Dogs Trust »> 7 let«) | #464 |
| 2026-10-31 | pravilo runbooka | učenje **1,0×** | Coren 50. mesto, razred »Average« (S35) | #464 |
| 2026-10-31 | pravilo runbooka | teža **34–39 kg**, višina 58–64 cm (PDSA, za oba spola), rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S639; FCI S638 navaja le želeni velikosti (samci 63,5 cm / 38 kg, samice 58,5 cm / 34 kg); PetMD / VCA 75–85 lb; PetMD S10 (razred »Large«) | #464 |
| 2026-10-31 | pravilo runbooka (M5-R13) | česanje **vsak dan** (7× na teden); skuštrana dlaka po 4 zamujenih | PDSA S639 »… they will need daily brushing«, PetMD S640 »needs daily brushing«; VCA S641 »weekly brushing---more when shedding« je alternativa | #464 |
| 2026-10-31 | David 2026-10-12 (M5-R16) | opozorilo »počakaj s sprehodom« 60 min po obroku | PDSA S639 »Remember to leave a gap after eating and before exercising, as this is thought to reduce the risks of GDV.« | #464 |
| 2026-10-31 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S639 »Remember to socialise your Malamute as a puppy with lots of other dogs, different people and types of experiences.« | #464 |
| 2026-10-31 | pravila »Breed care tasks« (David 2026-10-24, M5-R20 / R19) | otrokova mini igra **ušesa 1× na teden**; opomnik staršem **kopanje vsakih 6 tednov** (nikoli točkovano) | PetMD S640 »… check their Alaskan Malamute’s ears once a week …«; »… need a bath every 6-8 weeks.« (6–8 tednov → 6). Frizer: PDSA »around once every six months« je daljše od vseh obdobij → brez opomnika (precedens nemški ostrodlaki ptičar); zobje, kremplji: brez frekvence A/B → brez naloge | #464 |
| 2026-10-31 | pravilo runbooka | oznake: primeren za »aktivno družino« (`active_family`); upoštevajte »vsak dan potrebuje veliko gibanja«, »močan pes — potrebuje izkušenega lastnika«, »velik pes — ni za dom z majhnimi otroki«, »rad ima družbo«, »izpada mu dlaka«, »potrebuje česanje večkrat na teden«, »gost kožuh — slabo prenaša vročino«, »močan lovski nagon — lahko lovi manjše živali« | PDSA S639 (gibanje, »probably aren't well suited to first time dog owners«, »could knock over or injure small children«, »never leave … more than four hours«, »Malamutes shed a lot«, »daily brushing«, »very strong prey drive«); PetMD S640 (»daily brushing«, »trouble in hot weather«) | #464 |
| 2026-10-31 | pravilo runbooka | **brez** `family_pet`, `children`, `small_children`, `other_pets`, `apartment`, `large_home`, `low_shedding`, `shorter_lifespan`; nikoli »hypoallergenic« | PDSA: primeren za aktivne odrasle ali družine s starejšimi otroki / najstniki; RKC ključnih podatkov ni bilo mogoče prebrati; »Over 10 years« | #464 |
| 2026-10-31 | pravilo runbooka (§3) | **brez čipa dobrobiti**; marketing: **v aplikaciji da, v marketingu da** (nikoli obljuba zdravja; nikoli kot pes za majhne otroke, začetnike, vroče podnebje ali za dolgo samoto) | ni plosko-obrazna pasma (BVA S92 ne velja); kategorija Breed Watch neznana (stran RKC ni bila dosegljiva) | #464 |
| 2026-10-31 | pravilo runbooka (§3) | barve za AI in izbirnik: **sivo-bel (portret)**, črno-bel, sobolji z belo, rdeče-bel, bel; vedno bel trebuh in maska; rjave oči, nikoli modre | FCI S638 »The usual colours range from light grey through intermediate shadings to black, sable, and shading of sable to red.«, »The only solid colour allowable is all-white.«, »Blue eyes are a disqualifying fault.«; uteži brez vira → `verified: false` | #464 |
| 2026-10-31 | pravilo runbooka (§2.3) | seznam za vzreditelja: **druga shema** — ocena kolkov, pregled oči, DNA test za polinevropatijo (AMPN) | AKC Working Group (K32, priporočila ameriškega kluba pasme); PDSA S639 (BVA/KC sheme, vsakoletni pregled oči); nov ključ `dna_ampn` | #464 |
| 2026-10-31 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia`, `hypothyroidism`, `cataracts`, `chondrodysplasia` (nov), `polyneuropathy` (nov); brez odstotkov | PDSA S639, PetMD S640 | #464 |
| 2026-10-31 | predlog Claude, David lahko spremeni | EN »Alaskan Malamute«, SL »Aljaški malamut«; iskanje »malamute«, »malamut«, »aljaški malamut«, »aljaski malamut«, »malamute de l alaska« | običajno slovensko ime; strani KZS nisem preverjal | #464 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »malamute«, »aljaški malamut« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-83 | 2026-10-31 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90. meseca — predlog; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-83 | 2026-10-31 |
| Česanje vsak dan, opozorilo po obroku, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R16 / M5-R17 | 2026-10-31 |
| Mini igra ušesa (1× na teden); opomnik staršem: kopanje (vsakih 6 tednov) | 🛠 | 💶 izziv | M5-R20 / M5-R19 | 2026-10-31 |
| Barva (izbirnik: 5 standardnih barv) | 🛠 | 💶 izziv | M5-R21 | 2026-10-31 |
| AI portret v registru (sivo-bel, sprejet v prvem poskusu, zagon 41164901354) | ✅ | — | M5-R11-04 | 2026-10-31 |
| Stran na petprep.si (`/animals/dogs/alaskan-malamute`, `/sl/zivali/psi/aljaski-malamut`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal aljaškega malamuta — enega najstarejših arktičnih vlečnih psov, 79. najbolj priljubljeno pasmo po AKC 2024 in 76. plačljivo pasmo psa. 5 novih virov (S637–S641). Odrasel pes v igri potrebuje 12.000 korakov na dan (PDSA: najmanj dve uri napornega gibanja), česanje vsak dan, otrok mu enkrat na teden pregleda ušesa, starša pa aplikacija spomni na kopanje vsakih 6 tednov.
**Zakaj je pomembno:** Malamut je velik, močan in prijazen pes, a po PDSA »zelo zahteven« — ni za začetnike, ne za dom z majhnimi otroki in ne mara samote. PetPrep pokaže, ali otrok zmore njegov ritem 12 tednov, preden pride pravi pes.
**Kako povedati** — starši: »Aljaški malamut po PDSA potrebuje najmanj dve uri napornega gibanja na dan in dnevno česanje; najbolje se znajde pri aktivnih odraslih ali v družinah s starejšimi otroki. V PetPrepu boste videli, ali vaš otrok zmore takšen ritem.« · otroci: »Malamut je velik puhast vlečni pes s Aljaske — vsak dan greš z njim na zelo dolg sprehod in ga počešeš, enkrat na teden pa mu pogledaš ušesa.« · vlagatelji / partnerji: »Šestinsedemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Starejše obdobje (predlog 90):** mediane McMillan 2024 / VetCompass ni bilo mogoče prebrati (tabela Dogs Trust S123 in stran RKC sta bili za orodje nedosegljivi); uporabljena je spodnja meja PDSA »Over 10 years« (`verified: false`, `PROPOSED_R10_ALASKAN_MALAMUTE_SENIOR`). Potrdite ali preverite vrstico »Alaskan Malamute« v tabeli Dogs Trust.
- **Frizer:** PDSA svetuje profesionalno nego »around once every six months« — daljše od 12-tedenskega izziva, zato opomnika ni.
- **Vročina:** PetMD / PDSA svarita pred vročino (čip `heat_sensitive`); pravilo »brez sprehoda v vročini« (M5-R14) je le za plosko-obrazne pasme — če ga želite tudi za malamuta, povejte.
- **Ime:** »Aljaški malamut« je običajno slovensko ime; strani KZS nisem preverjal. Potrdite ali popravite.
- **Ugotovitev za skupne dokumente (ne urejam jih):** nova zdravstvena ključa `chondrodysplasia`, `polyneuropathy` in nov test `dna_ampn` (besedila na spletni strani dodana v PR spletne strani).
- **Portret:** zagon 41164901354 (koda `fbc4367`, brez produkcijske namestitve, 0,15 $), sprejet v prvem poskusu — realistična fotografija odraslega psa, sivo-bel z belo masko in trebuhom, rjave oči, pokončna ušesa, perjanica repa čez hrbet, svetlo siva studijska podlaga.
