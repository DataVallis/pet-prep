# Mops (Pug)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-43 · 2026-10-18

| | |
|---|---|
| Ključ / slug | `pug` / `pug` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 390 — v izbirniku za maltežanom |
| Vir podatkov | `docs/research/dog-data/data.json` → `pug` (viri S353–S362; ponovno S8, S10, S11, S14, S24, S35, S45, S54, S92, S93, S174, S236) |
| Izvor | FCI št. 253, skupina 9, sekcija 11 (mali molosi), izvor Kitajska, pokroviteljska država Velika Britanija (S353) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 38. mesto »Pug« (S174) |
| PR-ji | pet-prep #270 (pasma) · portret #276 (2026-10-19) · spletna stran #72 |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-18 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S355 »Exercise: Up to 1 hour per day« (»do N« → N); PDSA S357 »Your Pug will need up to an hour of exercise a day.« | #270 |
| 2026-10-18 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **104** mesecev; prihod 2 / 9 / 36 / 104 | 0,75 × mediana McMillan 2024 **11,6 leta** (popravljena legenda slike 3, S54: »Pug (brown, x̃=11.6)«) = 104,4 → 104. Ni izbrano: 90 (spodnja meja RKC »Over 10 years«, S355), 84 (Dogs Trust > 7 let, S14) | #270 |
| 2026-10-18 | pravilo runbooka | učenje **0,7×** (Coren rang 57, skupina »Fair«) | S35 | #270 |
| 2026-10-18 | pravilo runbooka | teža 6,3–8,1 kg (za oba spola), rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S354 »Ideal weight 6,3 – 8,1 kg.« (PDSA S357 »6-8 kg« enako; AKC 14–18 lb samo alternativa); PetMD S10 (razred »Small«) | #270 |
| 2026-10-18 | pravilo runbooka (M5-R13) | česanje **1× na teden** (zmršena dlaka po 1 zamujenem) | RKC S355 »Grooming: Once a week«; PDSA S357 in PetMD S359 »at least once a week« | #270 |
| 2026-10-18 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S359 »Pug puppies, like all dogs, need to be socialized during their first months of life.« | #270 |
| 2026-10-18 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« po obroku | noben vir A/B pri mopsu ne omenja premora med obrokom in gibanjem | #270 |
| 2026-10-18 | pravilo runbooka (§3, pasma s skrbjo za dobrobit) | oznake: primerno za življenje v stanovanju (`apartment`), družino z otroki (`children`), dom z drugimi ljubljenčki (`other_pets`); upoštevajte: kratek gobček — težave z dihanjem in vročino (`brachycephalic_breathing`), občutljiva koža (`sensitive_skin`), rad je — pazite na težo (`food_motivated_weight`), rad ima družbo (`needs_company`), izpada mu dlaka (`sheds`). Brez nove oznake | PDSA S357 »they are also a severely brachycephalic (flat-faced) breed«, »They often get on well with children …«, »Pugs are known for getting along well with other pets and dogs.«, »Pugs adore company and don’t do well if left alone.«; RKC S355 »Size of home: Flat/ Apartment«, »Sheds: Yes«; RKC Breed Watch S356 (kategorija 3: dihanje, dermatitis v kožnih gubah, »Too fat«); PetMD S359 »they make excellent dogs for kids.«, »Pugs are typically food-motivated«, »obesity is common for the breed.« | #270 |
| 2026-10-18 | pravilo runbooka | **brez** oznak `small_children`, `family_pet`, `eyelid_problems`, `back_spine` | ni ocene za majhne otroke (PDSA svetuje nadzor); ni izjave o družinskem psu; Breed Watch nima točke o vekah (le »Protruding eyes« in draženje oči); hemivertebre samo kot točka seznama PDSA (parafraza) in test vzreditelja | #270 |
| 2026-10-18 | pravilo runbooka (§3, BVA) | marketing: **v aplikaciji da, v marketingu / posnetkih zaslona / družbenih omrežjih ne** | BVA S92 »avoiding imagery of brachycephalic dogs in advertising, marketing materials and social media campaigns«; BVA S236 med brahicefalnimi pasmami izrecno navaja mopsa; PDSA S357 »severely brachycephalic« | #270 |
| 2026-10-18 | pravilo runbooka (§3) | barve za AI: **plava s črno masko — portret**, črna, marelična, srebrna; zmeren gobček z jasno odprtimi nosnicami, rahle gube brez težke gube nad nosom, oči niso izbuljene, vitko telo, rep zavit čez bok; nikoli merle, tigrasta, bela ali lisasta; lastnost `muzzle` | FCI S354 »Silver, apricot, fawn or black.«, »Black with fairly large well opened nostrils.«, »Pinched nostrils and heavy over nose wrinkle is unacceptable.«; Breed Watch S356; RKC S355 ne registrira merle mopsov | #270 |
| 2026-10-18 | predlog Claude, David lahko spremeni | ime SL »mops« (slug `mops`); iskalni ključi tudi »mopsek«, »pug dog«, »carlin« (francosko ime FCI) | uveljavljeno slovensko ime; KZS ni preverjen | #270 |
| 2026-10-18 | pravilo runbooka (§2.3) | testi za vzreditelja po RKC (seznam »Important« za vse vzreditelje = minimum): kalkulator sorodstvene vzreje, pregled pogačic pri klubu, nacionalni zdravstveni program kluba (National Pug Health Scheme), rentgen hemivertebr staršev po 12. mesecu; dodatno ocena dihanja (Respiratory Function Grading Scheme) | RKC S355; Univerza Cambridge K24 / S360 »currently available for the Pug, French Bulldog and Bulldog.« DNK test za PDE je pri RKC le med »Other tests available« → ni dodan. Nova ključa `breed_health_scheme`, `hemivertebrae_scheme` | #270 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »mops«, »pug«, »mopsek«, »carlin«, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-43 | 2026-10-18 |
| Pravila po starosti (2.000 → 6.000 korakov, odrasel 6.000, starejši 4.500 od 104 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-43 | 2026-10-18 |
| Česanje 1× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-18 |
| Barva (izbirnik barve: plava, črna, marelična, srebrna) | 🛠 | 💶 izziv | M5-R21 | 2026-10-18 |
| AI portret v registru (sprejet v prvem poskusu) | ✅ | — | M5-R11-04 | 2026-10-19 |
| Stran na petprep.si (`/animals/dogs/pug`, `/sl/zivali/psi/mops`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal mopsa — 38. najbolj priljubljeno pasmo po AKC 2024 in 39. plačljivo pasmo psa. 10 novih virov (S353–S362). Odrasel mops v igri potrebuje 6.000 korakov na dan (The Royal Kennel Club: do 1 ure gibanja), starejši od 8,7 leta 4.500; uči se počasneje kot mešanček (Coren: 57. mesto). Česanje enkrat na teden in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** Mops je ljubek, majhen in »za stanovanje«, zato ga mnoge družine vidijo kot lahkega psa. Veterinarji (PDSA, BVA) opozarjajo drugače: ima močno sploščen obraz, zato težje diha in se hitro pregreje; gube na obrazu je treba čistiti vsak dan, zobe ščetkati vsak dan (PetMD), rad je in se hitro zredi, sam ne sme biti dolgo. PDSA staršem celo svetuje, naj razmislijo o pasmi z normalnim gobčkom. PetPrep tega ne skriva — pokaže, kaj pomeni skrb za mopsa, preden ga družina kupi.
**Kako povedati** — starši: »Mops potrebuje kratke sprehode, ki niso v vročini, vsakodnevno nego gub in nekoga doma. V PetPrepu boste videli, ali otrok to zmore 12 tednov zapored — in se boste pred nakupom lažje odločili.« · otroci: »Mops je majhen pes z zavitim repkom in nagubanim obrazkom. Z njim greš na kratek sprehod, ko ni prevroče, ga enkrat na teden počešeš in mu ne daješ preveč priboljškov.« · vlagatelji / partnerji: »Petindvajseta pasma, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal (vseh plačljivih psov je zdaj 39) — z viri, testi in pravili. Kot pasmo s sploščenim obrazom je po priporočilu BVA nikoli ne pokažemo v oglasih; v aplikaciji jo staršem pošteno predstavimo z oznako o dihanju — brez statistik.«

## Odprto za Davida

- **Potrebe brez mehanike (needs-david):** čiščenje kožnih gub na obrazu **vsak dan** in brisanje oči (PetMD S359) ter nobenega gibanja v vročini (PDSA S357) → **M5-R14**; ščetkanje zob **vsak dan** (PetMD S359) → **M5-R15**; čiščenje ušes (PetMD S359, pogostost po nasvetu veterinarja) → **M5-R20**.
- **Oči:** RKC Breed Watch (S356) navaja »Protruding eyes« in draženje oči, PDSA (S357) poškodbe oči zaradi plitvih očesnih votlin — v slovarju ni oznake za oči (samo `eyelid_problems` za veke). Po zgledu buldoga in francoskega buldoga brez oznake. Ali naj dobi novo oznako?
- **Nasvet PDSA** »Our vets would strongly recommend opting for a breed with a normal snout or even a Pug cross-breed instead« je zabeležen v podatkih; v aplikaciji ga nosi le mirna oznaka o dihanju. Ali naj ga izbirnik pokaže izrecno?
- **Ime:** »mops« ni preverjeno pri KZS.
- **Spletna stran:** novi ključ zdravja `eye_problems_shallow_sockets` in nova ključa testov `breed_health_scheme`, `hemivertebrae_scheme` (besede Claude).
- **Portret:** narejen 2026-10-19 (PR #276) brez namestitve produkcije — workflow portretov zdaj uporabi kodo merganega commita (PR #275).
