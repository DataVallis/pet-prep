# Maltežan (Maltese)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-42 · 2026-10-18

| | |
|---|---|
| Ključ / slug | `maltese` / `maltese` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 380 — v izbirniku za angleškim koker španjelom |
| Vir podatkov | `docs/research/dog-data/data.json` → `maltese` (viri S345–S352; ponovno S8, S10, S11, S14, S24, S35, S45, S174) |
| Izvor | FCI št. 65, skupina 9, sekcija 1 (bišoni in sorodne pasme), osrednje Sredozemlje, pokroviteljska država Italija (S345) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 37. mesto »Maltese« (S174) |
| PR-ji | pet-prep #269 (pasma) · portret #276 (2026-10-19) · spletna stran #71 |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-18 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **30 min/dan → 3.000 korakov**; starejši 23 min → 2.300; mladiček 10 min × starost do 30 (pri 3 mesecih) | RKC S347 »Exercise: Up to 30 minutes per day« (»do N« → N); PDSA S348 »up to half an hour a day usually sufficient« da isto število | #269 |
| 2026-10-18 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108** mesecev; prihod 2 / 9 / 36 / 108 — **začasno** | 0,75 × spodnja meja RKC »Lifespan: Over 12 years« (S347; PDSA S348 enako, PetMD S351 12–15 let). McMillan 2024 (13,1 leta → 118) je dosegljiv samo prek Wikipedije (S352, raven C) — tabela Dogs Trust (S123) ni bila dosegljiva. Alternativa: 84 (Dogs Trust > 7 let) | #269 |
| 2026-10-18 | pravilo runbooka | učenje **0,7×** (Coren rang 59, skupina »Fair«) | S35 (tudi S352) | #269 |
| 2026-10-18 | pravilo runbooka | teža 3–4 kg (za oba spola), rast do 8–12 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S346 »Weight: 3–4 kg.« (PDSA S348 »3kg-4kg« enako; AKC 4–6 lb samo alternativa); PetMD S10 (razred »Toy«) | #269 |
| 2026-10-18 | pravilo runbooka (M5-R13) | česanje **vsak dan** (7× na teden; zmršena dlaka po 4 zamujenih) | RKC S347 »Grooming: Every day«; PDSA S348 »Their coats require daily grooming …«; PetMD S351 »Daily brushing is required …« | #269 |
| 2026-10-18 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S348 »Known to be sweet-natured, they can be reserved with strangers but given the right socialisation when young,« | #269 |
| 2026-10-18 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« po obroku | noben vir A/B pri maltežanu ne omenja premora med obrokom in gibanjem | #269 |
| 2026-10-18 | pravilo runbooka | oznake: primerno za življenje v stanovanju (`apartment`) in dom, kjer želite manj dlak (`low_shedding` — nikoli »hipoalergen«); upoštevajte: česanje večkrat na teden (`frequent_grooming`), rad ima družbo (`needs_company`). Brez nove oznake | RKC S347 »Size of home: Flat/ Apartment«, »Sheds: No«, »Grooming: Every day«; PDSA S348; PetMD S351 »This breed can develop separation anxiety when left alone for too long.« | #269 |
| 2026-10-18 | pravilo runbooka | **brez** oznake za otroke ali družino | AKC S350 in PetMD S351 dajeta samo nasvet o nadzoru pri majhnih otrocih; nobene izjave o družinskem psu, drugih ljubljenčkih ali lovskem nagonu | #269 |
| 2026-10-18 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot »hipoalergen« pes, igrača ali modni dodatek in nikoli z majhnimi otroki brez nadzora (predlog Claude) | RKC Breed Watch kategorija 1 (S347, brez točk); ni pes s sploščenim obrazom | #269 |
| 2026-10-18 | pravilo runbooka (§3) | barva za AI: **samo čisto bela** (portret in edina izbira v izbirniku barve); naravna dolga dlaka hišnega psa, brez razstavnega ovijanja in pentelj; lastnost `nose` | FCI S346 »Colour: Pure white, a pale ivory tinge is permitted.«; AKC S349 »Color, pure white.« | #269 |
| 2026-10-18 | predlog Claude, David lahko spremeni | ime SL »maltežan« (slug `maltezan`); iskalni ključi tudi »malteški bišon«, »malteški psiček«, »bichon maltais« | raba v slovenskih medijih; KZS ni preverjen | #269 |
| 2026-10-18 | pravilo runbooka (§2.3) | testi za vzreditelja po RKC: pregled oči (BVA/KC/ISDS) — najboljša praksa; minimuma RKC ne navaja | RKC S347 »Eye testing using the BVA/KC/ISDS Eye Scheme« | #269 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »maltežan«, »maltese«, »malteški bišon« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-42 | 2026-10-18 |
| Pravila po starosti (2.000 → 3.000 korakov, odrasel 3.000, starejši 2.300 od 108 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-42 | 2026-10-18 |
| Česanje vsak dan (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-18 |
| Barva (izbirnik barve, ena barva standarda: bela) | 🛠 | 💶 izziv | M5-R21 | 2026-10-18 |
| AI portret v registru (sprejet v prvem poskusu) | ✅ | — | M5-R11-04 | 2026-10-19 |
| Stran na petprep.si (`/animals/dogs/maltese`, `/sl/zivali/psi/maltezan`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal maltežana — 37. najbolj priljubljeno pasmo po AKC 2024 in 38. plačljivo pasmo psa. 8 novih virov (S345–S352). Odrasel maltežan v igri potrebuje 3.000 korakov na dan (The Royal Kennel Club: do 30 minut gibanja), starejši od 9 let 2.300; uči se počasneje kot mešanček (Coren: 59. mesto). Česanje vsak dan in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** Maltežan je majhen (3–4 kg) in malo izpada dlake, zato ga starši pogosto vidijo kot »lahkega« psa. Viri pravijo drugače: dolgo belo dlako je treba česati vsak dan, zobe ščetkati vsak dan (PetMD), ne mara biti dolgo sam in z majhnimi otroki potrebuje nadzor. Prav to pokaže 12 tednov PetPrepa.
**Kako povedati** — starši: »Maltežan potrebuje malo sprehodov, a veliko nege in družbe — vsak dan česanje in nekdo, ki je velik del dneva doma. V PetPrepu boste videli, ali otrok vsakodnevno nego zmore 12 tednov zapored.« · otroci: »Maltežan je majhen bel kosmatinec z dolgo svilnato dlako. Vsak dan ga počešeš, pelješ na kratek sprehod in se z njim nežno igraš — je zelo majhen!« · vlagatelji / partnerji: »Štiriindvajseta pasma, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal (vseh plačljivih psov je zdaj 38) — z viri, testi in pravili; nikoli ne trdimo, da je pes hipoalergen. Staršem svetujemo, naj vzreditelja vprašajo za pregled oči staršev — brez statistik.«

## Odprto za Davida

- **Meja starejšega psa 108 je začasna:** spodnja meja RKC (12 let). McMillan 2024 navaja 13,1 leta (prek Wikipedije, S352) → 118 mesecev; preveriti v tabeli Dogs Trust (S123), ko bo dosegljiva.
- **Potrebe brez mehanike (needs-david):** ščetkanje zob **vsak dan** (PetMD S351) → **M5-R15**; čiščenje madežev solz okoli oči (PetMD S351, brez pogostosti) → **M5-R14** (nega obraza); redno čiščenje ušes (PetMD S351) → **M5-R20**.
- **Pasji frizer:** PetMD (S351) omenja redne obiske frizerja samo, če dlako držite kratko (brez pogostosti) → zabeleženo, ni potreba igre (M5-R18, če David želi).
- **Otroci:** AKC (S350) in PetMD (S351) svetujeta nadzor z majhnimi otroki (PetMD: razigrani otroci lahko psička po nesreči poškodujejo) — brez oznake `older_children` / `fragile_older_children` (noben vir ne pravi »bolje za starejše otroke« ali »krhke kosti«). Ali naj dobi oznako?
- **Ime:** »maltežan« ni preverjeno pri KZS.
- **Spletna stran:** nova ključa zdravja `heart_disease_pda`, `chiari_malformation`, `cataracts_entropion`, `periodontal_daily_brushing` (besede Claude).
- **Portret:** narejen 2026-10-19 (PR #276) brez namestitve produkcije — workflow portretov zdaj uporabi kodo merganega commita (PR #275).
