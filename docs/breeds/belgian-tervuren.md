# Belgijski ovčar tervuren (Belgian Tervuren)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-111 · 2026-11-14

| | |
|---|---|
| Ključ / slug | `belgian_tervuren` / `belgian-tervuren` (SL `belgijski-ovcar-tervuren`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1040 — v izbirniku za schipperkejem |
| Vir podatkov | `docs/research/dog-data/data.json` → `belgian_tervuren` (viri S830–S832; ponovno S10, S11, S14, S24, S35, S45, S174, S312, S313, S318); seznam za vzreditelja: RKC (S830) |
| Izvor | FCI št. 15 »Chien de Berger Belge«, različica tervueren (dolgodlaka), skupina 1, sekcija 1 (ovčarski psi), Belgija (S312, S313, S831) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 107. mesto »Belgian Tervuren« (S174, prebrano 2026-11-14) |
| PR-ji | pet-prep #1120 (pasma) · portret pet-prep PR `feat/M5-R10-111-belgian-tervuren-portrait` · spletna stran: pet-prep-website `feat/register-belgian-tervuren` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-14 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček / mlad 10 min × starost do 120 (pri 12 mesecih) | RKC S830 »More than 2 hours per day« (»more than N« → N); PetMD S832 števila ne navaja | #1120 |
| 2026-11-14 | pravilo runbooka | obdobja 0 / 9 / 36 / **90** (začasno); prihod 2 / 9 / 36 / 90 | AAHA S11; starejši = 0,75 × spodnja meja RKC »Over 10 years« (S830) × 12 = 90 — mediana McMillan 2024 za to različico ni bila dosegljiva (kot pri malinoisu). Alternative: 124 (McMillan 13,8 leta prek Wikipedie S318), 108 (PetMD 12–14 let), 84 (Dogs Trust > 7 let) | #1120 |
| 2026-11-14 | pravilo runbooka | učenje **1,5×** | Coren 14. mesto »Belgian Shepherd Dog (Tervuren)« (»Excellent Working Dogs«, tabela Wikipedia S35) | #1120 |
| 2026-11-14 | pravilo runbooka | teža **25–30 kg (psi) / 20–25 kg (psice)**, višina 60–66 / 56–62 cm, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | standard FCI 15 S313 / S831 (»Males about 25-30 kg.«); PetMD S10 (razred »Medium«) | #1120 |
| 2026-11-14 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | RKC »More than once a week« nima števila → PetMD S832 »brush their beautiful, long double coat every week« → 1 (v času menjave dlake skoraj vsak dan — sezona se ne modelira) | #1120 |
| 2026-11-14 | pravila »Breed care tasks« (David 2026-10-24) | **čiščenje ušes 1× na teden** (otrokova mini igra), **opomnik za kopanje vsake 4 tedne** (staršu, brez točk) | PetMD S832 »Cleaning your Terv's ears every one or two weeks …« (razpon → spodnja meja), »a bath every four to six weeks« (4–6 tednov → 4); zobje, kremplji, frizer brez pogostosti → brez naloge | #1120 |
| 2026-11-14 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S832 »This makes socializing your Belgian Tervuren puppy a vital part of their care.« | #1120 |
| 2026-11-14 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila po obroku in **brez** poletnega opozorila | PetMD navaja želodčno torzijo (GDV) le kot naslov, brez nasveta o premoru; pasma ni ploskoobrazna | #1120 |
| 2026-11-14 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»veliko hišo z vrtom«**; upoštevajte **»vsak dan potrebuje veliko gibanja«**, **»pri igri lahko »pase« otroke«**, **»potrebuje miselne izzive«**, **»izpada mu dlaka«**, **»potrebuje česanje večkrat na teden«** | RKC S830 »More than 2 hours per day«, »Large house«, »Large garden«, »Sheds: Yes«, »Grooming: More than once a week«; PetMD S832 »a lot of physical activity and mental stimulation«, »may try to "herd" children and smaller animals«, »Bored Tervs may get into trouble …«, tedensko česanje | #1120 |
| 2026-11-14 | pravilo runbooka | **brez** `family_pet`, `children`, `small_children`, `apartment`, `other_pets`, `chases_small_pets` (le pasenje, ne »prey drive«), `needs_company`, `experienced_owner`; nikoli »hypoallergenic« | ni A/B navedbe ali je pogojna (kot pri malinoisu) | #1120 |
| 2026-11-14 | pravilo runbooka (§3) | **v aplikaciji da, v marketingu da** | ni ploskoobrazna; RKC Breed Watch kategorija 1 (»Currently no points of concern …«); nobeno veterinarsko združenje ne odsvetuje oglaševanja | #1120 |
| 2026-11-14 | pravilo runbooka (§3) | barva za AI in izbirnik: **plav s črno masko** (prednostna barva standarda, portret), **siv s črno masko**; pokonci stoječa ušesa, ovratnik, »hlačke«, košat naraven rep; nikoli črn, tigrast ali z belimi lisami | FCI S831 »Only fawn with black overlay or grey with black overlay, with black mask«, »the fawn with black overlay is still preferred«; uteži brez vira → `verified: false` | #1120 |
| 2026-11-14 | pravilo runbooka (§2.3) | seznam za vzreditelja: genetska raznolikost (minimum); komolci, oči, kolki po shemah BVA/KC (best practice); brez novega ključa | RKC S830 | #1120 |
| 2026-11-14 | pravilo runbooka | zdravstveni ključi registra (obstoječi): `hip_elbow_dysplasia`, `pra_inherited_blindness`, `hypothyroidism`, `bleeding_disorder_vwd`, `bloat_gdv`; brez odstotkov | PetMD S832, RKC S830 | #1120 |
| 2026-11-14 | predlog Claude, David lahko spremeni | SL **»belgijski ovčar tervuren«** (kot »belgijski ovčar malinois«); iskanje tudi »tervuren«, »tervueren«, »terv« | FCI / RKC / AKC; strani KZS nisem prebral | #1120 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »tervuren«, »tervueren« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-111 | 2026-11-14 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90. meseca; 4 → 3 → 2 obroka; učenje 1,5×) | 🛠 | 💶 izziv | M5-R10-111 | 2026-11-14 |
| Česanje 1× na teden, ušesa 1× na teden, opomnik za kopanje vsake 4 tedne, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R18 / M5-R17 | 2026-11-14 |
| Barva (izbirnik: plav s črno masko, siv s črno masko) | 🛠 | 💶 izziv | M5-R21 | 2026-11-14 |
| AI portret v registru | 🗓 po združitvi (breed-portraits.yml) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/belgian-tervuren`, `/sl/zivali/psi/belgijski-ovcar-tervuren`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal belgijskega ovčarja tervurena — dolgodlako različico belgijskega ovčarja (20–30 kg) s plavo, črno prekrito dlako in črno masko; 107. najbolj priljubljeno pasmo po AKC 2024 in 103. pasmo psa v aplikaciji. 3 novi viri (S830–S832). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot 2 uri gibanja), česanje 1× na teden in čiščenje ušes 1× na teden; staršu vsake 4 tedne pride opomnik za kopanje.
**Zakaj je pomembno:** Tervuren je čudovit, pameten delovni pes, ki potrebuje veliko gibanja in miselnih izzivov, sicer se dolgočasi; lahko poskuša »pasti« otroke. PetPrep pokaže, ali družina zmore več kot dve uri gibanja na dan in redno nego dolge dlake.
**Kako povedati** — starši: »Tervuren je zelo dejaven in bister pes. V PetPrepu boste videli, ali vaš otrok zmore vsakodnevne dolge sprehode in tedensko nego dlake in ušes.« · otroci: »Tervuren je kosmat belgijski ovčar, ki rad teče — vsak dan greš z njim na dolg sprehod in enkrat na teden ga počešeš.« · vlagatelji / partnerji: »103. pasma psa — samodejni nočni zagon jo je sam izbral po javni lestvici AKC in dodal z viri ter s pravili nege iz veterinarskih virov.«

## Odprto za Davida

- **Starost »starejši« (90 mesecev) je začasna:** po pravilu iz spodnje meje RKC »Over 10 years«, ker mediana McMillan 2024 za tervurena ni bila dosegljiva (Wikipedia jo navaja kot 13,8 leta → bilo bi 124 mesecev; PetMD 12–14 let → 108). Ko bo tabela Dogs Trust / Supplementary Table 3 preverjena, se lahko meja popravi s podatkovno migracijo.
- **Česanje 1× na teden:** RKC »More than once a week« bi pomenil vsaj 2×; edino število (PetMD) je »every week«. Po pravilu 1× — želite 2× (kot pri malinoisu)?
- **Ušesa 1× na teden:** PetMD »every one or two weeks« → po pravilu spodnja meja (1 teden). Želite vsaka 2 tedna?
- **Ime:** »belgijski ovčar tervuren« ni preverjeno na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** število pasem psov v FEATURES / predstavitvah (zdaj 103) se po pravilu »Own files only« ne spreminja v tem zagonu.
