# Dolgodlaki škotski ovčar (Rough Collie)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-44 · 2026-10-19

| | |
|---|---|
| Ključ / slug | `rough_collie` / `rough-collie` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 400 — v izbirniku za mopsom |
| Vir podatkov | `docs/research/dog-data/data.json` → `rough_collie` (viri S363–S369; ponovno S8, S10, S11, S14, S24, S35, S45, S54, S92, S174) |
| Izvor | FCI št. 156, skupina 1, sekcija 1 (ovčarski psi), Velika Britanija (S363) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 39. mesto »Collie« (S174). AKC vodi dolgodlakega in kratkodlakega škotskega ovčarja kot eno pasmo; FCI in RKC ju ločita — zgradili smo dolgodlakega (pogostejšega) |
| PR-ji | pet-prep #271 (pasma) · portret: čaka na namestitev produkcije · spletna stran #73 |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-19 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S365 »Exercise: Up to 1 hour per day« (»do N« → N); PetMD S368 »at least an hour of outdoor exercise daily« da isto število | #271 |
| 2026-10-19 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108** mesecev; prihod 2 / 9 / 36 / 108 — **začasno** | 0,75 × spodnja meja RKC »Lifespan: Over 12 years« (S365). McMillan 2024 ni bil dosegljiv (popravljena slika S54 navaja le border collieja, tabela Dogs Trust S123 ni bila dosegljiva). Alternativi: 90 (PetMD 10–14 let, S368), 84 (Dogs Trust > 7 let) | #271 |
| 2026-10-19 | pravilo runbooka | učenje **1,5×** (Coren rang 16 »Collie«, skupina »Excellent«) | S35 | #271 |
| 2026-10-19 | pravilo runbooka | teža 22,7–34 kg (psice 50–65 lb, psi 60–75 lb), rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | standard AKC S367 (FCI S364 in RKC S366 teže ne navajata; PetMD S368 50–75 lb enako); PetMD S10 (razred »Large«) | #271 |
| 2026-10-19 | pravilo runbooka (M5-R13) | česanje **vsak dan** (7× na teden; zmršena dlaka po 4 zamujenih) | RKC S365 »Grooming: Every day«; PetMD S368 »Daily brushing is recommended …« (»at least weekly« = alternativa 1) | #271 |
| 2026-10-19 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S368 »Puppy socialization is crucial for proper emotional development and intelligence.« | #271 |
| 2026-10-19 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« po obroku | PetMD (S368) omenja le napihnjenost pri prehitrem hranjenju (počasna skleda), ne premora pred gibanjem | #271 |
| 2026-10-19 | pravilo runbooka | oznake: primerno za družino z otroki (`children`) in dom z drugimi ljubljenčki (`other_pets`); upoštevajte: pri igri lahko »pase« otroke (`may_herd_children`), česanje večkrat na teden (`frequent_grooming`), rad ima družbo (`needs_company`), izpada mu dlaka (`sheds`). Brez nove oznake | RKC standard S366 »A great companion dog, friendly, happy and active, good with children and other dogs.«; PetMD S368 »are good with other animals.«, »they may nip at ankles and heels when excited«, »do not do well by themselves for long periods of time.«; RKC S365 »Grooming: Every day«, »Sheds: Yes« | #271 |
| 2026-10-19 | pravilo runbooka | **brez** oznake `family_pet` in `needs_mental_stimulation` | ni izjave o »družinskem življenju« (le »great companion dog«); »can quickly become bored« (PetMD) ni navedena potreba | #271 |
| 2026-10-19 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot pes brez nege, nikoli pretežno bel ali »dvojni merle« | RKC Breed Watch kategorija 2 brez imenovane točke (S365); ni pes s sploščenim obrazom | #271 |
| 2026-10-19 | pravilo runbooka (§3) | barve za AI in izbirnik: **sobolja z belo** (portret), **trobarvna**, **modri merle** — vse s tipičnimi belimi oznakami; nikoli bela (samo AKC), kremasta ali dvojni merle; lastnost `nose` | FCI S364 »Any other colour or combination of colours unacceptable.«; RKC S366 »Sable and white, Tricolour and Blue Merle.«; RKC S365: ni registracije mladičev merle × merle | #271 |
| 2026-10-19 | predlog Claude, David lahko spremeni | ime SL »dolgodlaki škotski ovčar« (slug `dolgodlaki-skotski-ovcar`); iskalni ključi tudi »škotski ovčar«, »collie«, »long-haired collie«; »koli« ostane border collieju (njegov obstoječi ključ) | raba v slovenskih medijih; KZS ni preverjen | #271 |
| 2026-10-19 | pravilo runbooka (§2.3) | testi za vzreditelja po RKC: pregled oči (BVA/KC/ISDS) in kolkov (BVA/KC) — minimum; DNA CEA/CH, DM, MDR1, PRA-rcd2 — najboljša praksa (nov ključ `dna_pra_rcd2`) | RKC S365 »Good Practice« / »Best Practice« | #271 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »dolgodlaki škotski ovčar«, »rough collie«, »collie« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-44 | 2026-10-19 |
| Pravila po starosti (2.000 → 6.000 korakov, odrasel 6.000, starejši 4.500 od 108 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-44 | 2026-10-19 |
| Česanje vsak dan (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-19 |
| Barva (izbirnik barve: sobolja z belo, trobarvna, modri merle) | 🛠 | 💶 izziv | M5-R21 | 2026-10-19 |
| AI portret v registru | 🗓 čaka na naslednjo ročno namestitev produkcije (D18) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/rough-collie`, `/sl/zivali/psi/dolgodlaki-skotski-ovcar`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal dolgodlakega škotskega ovčarja — 39. najbolj priljubljeno pasmo po AKC 2024 in 40. plačljivo pasmo psa. 7 novih virov (S363–S369). Odrasel pes v igri potrebuje 6.000 korakov na dan (The Royal Kennel Club: do 1 ure gibanja), starejši od 9 let 4.500; uči se hitreje kot mešanček (Coren: 16. mesto). Česanje vsak dan in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** Škotski ovčar je znan kot prijazen družinski pes z otroki, zato ga starši pogosto izberejo. Viri pa povedo tudi drugo plat: gosto dolgo dlako je treba česati vsak dan, veliko izpada, ne mara biti dolgo sam, pri igri lahko otroke »pase« in jih ščipa za pete, kremplje je treba striči približno vsak drugi teden. Prav to pokaže 12 tednov PetPrepa.
**Kako povedati** — starši: »Škotski ovčar je prijazen do otrok in drugih živali, a potrebuje vsakodnevno česanje, uro gibanja in nekoga, ki je velik del dneva doma. V PetPrepu boste videli, ali otrok to zmore 12 tednov zapored.« · otroci: »Škotski ovčar je velik kosmat pes z bujno grivo. Vsak dan ga počešeš in pelješ na sprehod — in rad je s tabo, ne sam.« · vlagatelji / partnerji: »Šestindvajseta pasma, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal (vseh plačljivih psov je zdaj 40) — z viri, testi in pravili. Staršem svetujemo, naj vzreditelja vprašajo za preglede oči in kolkov ter DNA teste (tudi MDR1 — občutljivost na nekatera zdravila) — brez statistik.«

## Odprto za Davida

- **Meja starejšega psa 108 je začasna:** spodnja meja RKC (12 let). McMillan 2024 za to pasmo ni bil dosegljiv; preveriti v tabeli Dogs Trust (S123), ko bo dosegljiva.
- **Potrebe brez mehanike (needs-david):** striženje krempljev približno vsak drugi teden (PetMD S368) → **M5-R19**; čiščenje ušes ob kopanju in kopanje enkrat na mesec (PetMD S368) → **M5-R20**; pasji frizer med linjenjem (PetMD S368, brez pogostosti, parafraza) → **M5-R18**.
- **Breed Watch kategorija 2** brez imenovane točke (S365) → brez oznake dobrobiti (kot pri šetlandskem ovčarju).
- **Oznake:** `family_pet` (»great companion dog«, S366) in `needs_mental_stimulation` (»can quickly become bored«, S368) nista dodani — ali naj ju dobi?
- **Vrsta AKC »Collie«** zajema obe različici dlake; zgrajen je samo dolgodlaki (FCI 156). Kratkodlaki (FCI 296) bi bil ločena pasma.
- **Ime:** »dolgodlaki škotski ovčar« ni preverjeno pri KZS.
- **Spletna stran:** nov ključ testa `dna_pra_rcd2` (besede Claude).
- **Portret:** naredi ga prvi samodejni zagon po naslednji ročni namestitvi produkcije (D18).
