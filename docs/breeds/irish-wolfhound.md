# Irski volčji hrt (Irish Wolfhound)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-80 · 2026-10-30

| | |
|---|---|
| Ključ / slug | `irish_wolfhound` / `irish-wolfhound` (SL `irski-volcji-hrt`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 740 — v izbirniku za irskim mehkodlakim pšeničnim terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `irish_wolfhound` (viri S615–S624; ponovno S8, S10, S11, S35, S45, S174); seznam za vzreditelja iz S617 |
| Izvor | FCI št. 160 »Irish Wolfhound«, skupina 10 (hrti), sekcija 2 (hrti z ostro dlako), Irska (S615) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 76. mesto »Irish Wolfhound« (S174, prebrano 2026-10-30) |
| PR-ji | pet-prep #446 (pasma) · portret: sledi · pet-prep-website: sledi |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-30 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček / mlad pes 10 min × starost do 120 (pri 12 mesecih) | RKC S617 »Exercise: More than 2 hours per day« (»more than N« → N); PDSA S619 »a minimum of two hours of exercise every day« se ujema | #446 |
| 2026-10-30 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36 mesecev; prihod 2 / 9 / 36 | AAHA S11 (kot pri vseh psih) | #446 |
| 2026-10-30 | **predlog Claude — čaka Davida** (`verified=false`) | starejši od **68. meseca** (tudi prihod starejšega psa) | 0,75 × mediana 7,5 leta (Orleifson in sod. 2017, Acta Vet Scand, švedski irski volčji hrti — S623) = 67,5 → 68. Pravilo runbooka ne da vrednosti: tabele McMillan 2024 (Dogs Trust S123) zagon ni mogel odpreti, mediane VetCompass ni, RKC »Under 10 years« nima spodnje meje → najbližja vrstica (precedens bernski planšar, bernardinec). Alternative: 89 (McMillan 2024 9,9 leta, le prek Wikipedije S624, tier C), 54 (PetMD / VCA 6–8 let) | #446 |
| 2026-10-30 | pravilo runbooka | učenje **1,0×** (Coren rang 41, skupina »Average«, deljen s curly coated retrieverjem) | S35 | #446 |
| 2026-10-30 | pravilo runbooka | teža 40–55 kg, višina 71–79 cm (oba spola), rast do 18–24 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S619 (standarda FCI S616 in RKC S618 navajata le najmanjšo višino 79 / 71 cm in težo 54,5 / 40,9 kg); PetMD S10 (razred »Giant«) | #446 |
| 2026-10-30 | pravilo runbooka (M5-R13) | česanje **2× na teden** | RKC S617 »Grooming: More than once a week« brez števila → PDSA S619 »Brushing them around twice a week …« → 2; PetMD S620 / VCA S621 »weekly« (alternativa) | #446 |
| 2026-10-30 | David 2026-10-12 (M5-R16) | **opozorilo po obroku** (60 minut »počakaj s sprehodom«, koraki se štejejo) | PetMD S620 »Do not let your Irish Wolfhound dog exercise right before or after eating.«; PDSA S619 enako (presledek po obroku, nevarnost zasuka želodca) | #446 |
| 2026-10-30 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S620 »Begin socializing your Irish Wolfhound puppy as soon as you bring them home.« | #446 |
| 2026-10-30 | pravilo runbooka (M5-R20, David 2026-10-24) | **čiščenje ušes na 2 tedna** (otrokova mini-igra) | PetMD S620 »Cleaning your dog's ears every two to four weeks …« → razpon, spodnja meja | #446 |
| 2026-10-30 | pravilo runbooka (»Breed care tasks«) | **brez** opomnika za kopanje in trimanje, brez naloge za kremplje in zobe | kopanje PetMD »every one to three months as needed« (pogojno; PDSA: kopanje ni potrebno, razen če se pes umaže); ročno trimanje na 6 mesecev (PetMD, VCA »twice a year«) je dlje od vseh obdobij nalog (precedens nemški ostrodlaki ptičar); kremplji / zobe brez pogostosti | #446 |
| 2026-10-30 | pravilo runbooka | oznake: veliko hišo z vrtom (`large_home`); upoštevajte: vsak dan veliko gibanja (`long_daily_exercise`), krajša življenjska doba (`shorter_lifespan`), ni za dom z majhnimi otroki (`not_for_small_children`), rad ima družbo (`needs_company`), česanje večkrat na teden (`frequent_grooming`), močan lovski nagon (`chases_small_pets`) | RKC S617 »Size of home: Large house«, »Exercise: More than 2 hours per day«, »Lifespan: Under 10 years«, »Grooming: More than once a week«; PDSA S619 »We wouldn't recommend having an Irish Wolfhound if you have smaller children«, »As Irish Wolfhounds do have a high prey drive, we wouldn't recommend keeping them with smaller pets.«; PetMD S620 »Some might develop separation anxiety when left alone …« | #446 |
| 2026-10-30 | pravilo runbooka | **brez** oznak `family_pet`, `children`, `small_children`, `other_pets`, `apartment`, `sheds` / `low_shedding`, `heat_sensitive`; brez nove oznake | PDSA odsvetuje manjše otroke (PetMD / VCA »good with children« — opozorilo PDSA prevlada); RKC »Sheds: Yes« proti PDSA / VCA »malo« → nobena; o vročini ni vira A/B | #446 |
| 2026-10-30 | pravilo runbooka (§3) | **brez oznake dobrobiti** | RKC Breed Watch kategorija 2 (S617), točke niso bile vrnjene (precedens pomeranec, stafordširski bulterier) | #446 |
| 2026-10-30 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli z majhnimi otroki ali malimi živalmi brez nadzora, nikoli kot pes čuvaj ali lovski pes, nikoli z obljubo o zdravju | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #446 |
| 2026-10-30 | pravilo runbooka (§3) | barve za AI in izbirnik: **siv** (portret), sivo tigrast, pšenično rumen, rumenorjav, rdeč, črn, čisto bel; nikoli lisast (merle), večbarven ali jetrno rjav | FCI S616 »The only recognized colours are Grey, Brindle, Red, Black, Pure White, Fawn, and Wheaten.«; RKC S618 doda »steel grey«. Uteži barv brez vira → `verified: false`; siva za portret je predlog Claude | #446 |
| 2026-10-30 | pravilo runbooka (§2.3) | seznam za vzreditelja — minimum: genetska raznolikost; najboljša praksa: oči (BVA/KC/ISDS) | RKC S617 »We strongly recommend that breeders, at a minimum, conduct these tests before breeding« | #446 |
| 2026-10-30 | pravilo runbooka | zdravstveni ključi registra (obstoječi): `bloat_gdv`, `heart_muscle_disease`, `certain_cancers`, `pra_eye_disease`, `liver_shunt`; brez odstotkov | PDSA S619, PetMD S620, Urfer 2007 S622 | #446 |
| 2026-10-30 | predlog Claude, David lahko spremeni | ime SL »Irski volčji hrt« (slug `irski-volcji-hrt`); iskalni ključi tudi »volčji hrt«, »irski hrt«, »wolfhound«, »cu faoil« | slovenskega vira (KZS) za ime zagon ni bral | #446 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »irski volčji hrt«, »volčji hrt«, »wolfhound« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-80 | 2026-10-30 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 68. meseca — predlog; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-80 | 2026-10-30 |
| Česanje 2× na teden, »Nova izkušnja« za mladička, opozorilo »počakaj s sprehodom« po obroku, čiščenje ušes na 2 tedna | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 / M5-R20 | 2026-10-30 |
| Barva (izbirnik barve: siv, sivo tigrast, pšenično rumen, rumenorjav, rdeč, črn, čisto bel) | 🛠 | 💶 izziv | M5-R21 | 2026-10-30 |
| AI portret v registru | 🗓 sledi takoj po združitvi | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/irish-wolfhound`, `/sl/zivali/psi/irski-volcji-hrt`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal irskega volčjega hrta — 76. najbolj priljubljeno pasmo po AKC 2024 in najvišjega psa v aplikaciji. 10 novih virov (S615–S624). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC in PDSA: več kot dve uri gibanja), starejši 9.000; uči se kot mešanec (Coren: 41. mesto, ×1,0). Česanje dvakrat na teden, socializacija mladička, opozorilo »počakaj s sprehodom« po obroku (nevarnost zasuka želodca) in čiščenje ušes vsaka dva tedna so del izziva od prvega dne.
**Zakaj je pomembno:** Irski volčji hrt je nežen velikan, a tehta 40–55 kg in več, potrebuje več kot dve uri gibanja na dan, velik dom, ima močan lovski nagon in živi manj kot deset let. PDSA ga ne priporoča za dom z manjšimi otroki. PetPrep pokaže, ali družina zmore tako dolge sprehode 12 tednov zapored.
**Kako povedati** — starši: »Irski volčji hrt potrebuje več kot dve uri gibanja na dan, veliko prostora in družbo. V PetPrepu boste videli, ali vaša družina res zmore 12.000 korakov vsak dan.« · otroci: »Volčji hrt je največji pes v igri. Z njim greš vsak dan na dolg sprehod, ga dvakrat na teden počešeš — po kosilu pa najprej malo počakaš.« · vlagatelji / partnerji: »Pasma, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Meja starejšega psa (68 mesecev, `verified=false`):** predlog iz recenzirane mediane 7,5 leta (Orleifson 2017, švedska kohorta, S623). Če potrdite McMillan 2024 (9,9 leta, zdaj le prek Wikipedije), bi bil starejši od 89 mesecev (podatkovna migracija ali urejanje v Filamentu).
- **Breed Watch kategorija 2:** točke niso bile vrnjene, zato brez oznake dobrobiti — preverite.
- **Kopanje:** PetMD »na 1–3 mesece po potrebi«, PDSA »ni potrebno« → brez opomnika; če PetMD beremo kot pogostost, bi bil opomnik na 4 tedne.
- **Ročno trimanje na 6 mesecev:** daljše od vseh obdobij nalog — za opomnik bi potrebovali novo obdobje (nova naloga ROADMAP — David).
- **Seznam za vzreditelja:** PDSA omenja letni pregled srca vzrejnih psov in test na jetrni šant — le parafraza, zato ni na seznamu.
- **Ime:** »Irski volčji hrt« ni preverjeno pri KZS.
- **Barva portreta:** siva je predlog Claude (najbolj značilna, prva v standardu FCI).
