# Chesapeake Bay retriver (Chesapeake Bay Retriever)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-56 · 2026-10-22

| | |
|---|---|
| Ključ / slug | `chesapeake_bay_retriever` / `chesapeake-bay-retriever` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 520 — v izbirniku za francoskim ostrodlakim ptičarjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `chesapeake_bay_retriever` (viri S457–S462; ponovno S8, S10, S11, S14, S24, S35, S45, S123, S174) |
| Izvor | FCI št. 263, skupina 8, sekcija 1 (prinašalci), ZDA (S457) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 52. mesto »Chesapeake Bay Retriever« (S174) |
| PR-ji | pet-prep #339 (pasma) · portret — · spletna stran — |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-22 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | RKC S459 »Exercise: More than 2 hours per day« (»more than N« → N). VCA S460 in PetMD S461 minut ne navajata | #339 |
| 2026-10-22 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90** mesecev; prihod 2 / 9 / 36 / 90 | 0,75 × spodnja meja RKC »Over 10 years« (S459) = 7,5 leta = 90. Tabele Dogs Trust z medianami McMillan 2024 (S123) ta zagon ni mogel odpreti, mediane VetCompass ni → tretja izbira pravila (kot pri francoskem ostrodlakem ptičarju). Alternativi: 84 (Dogs Trust > 7 let, S14); 97 (mediana 10,75 leta iz ankete KC, le Wikipedija S462, tier C) | #339 |
| 2026-10-22 | pravilo runbooka | učenje **1,2×** (Coren rang 27, skupina »Above average«) | S35 (rang 27, skupaj s pulijem in jorkširskim terierjem) | #339 |
| 2026-10-22 | pravilo runbooka | teža 25–36,5 kg, rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | standard FCI št. 263 (S458): psi 29,5–36,5 kg, psice 25–32 kg; VCA S460 in PetMD S461 55–80 lb se ujemata; PetMD S10 (razred »Large«) | #339 |
| 2026-10-22 | pravilo runbooka (M5-R13) | česanje **1× na teden** (zmršena dlaka po 1 zamujenem) | RKC S459 »Grooming: Once a week« → 1; VCA S460 »Brushing once a week is adequate; more during shedding seasons.« in PetMD S461 enako (obdobja levitve igra ne modelira) | #339 |
| 2026-10-22 | David 2026-10-12 (M5-R16) | **opozorilo po obroku** (60 min »počakaj s sprehodom«, koraki se štejejo) | PetMD S461 »Do not let your dog exercise directly before or after eating.« (zasuk želodca) | #339 |
| 2026-10-22 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S461 »With proper introductions and socialization, Chessies would be a great addition to a family.« | #339 |
| 2026-10-22 | pravilo runbooka | oznake: aktivno družino (`active_family`), družinsko življenje (`family_pet`), družino z otroki (`children`), veliko hišo z vrtom (`large_home`); upoštevajte: vsak dan potrebuje veliko gibanja (`long_daily_exercise`), rad ima družbo (`needs_company`), izpada mu dlaka (`sheds`) | RKC S459 »Exercise: More than 2 hours per day«, »Size of home: Large house«, »Sheds: Yes«; PetMD S461 družinski pes s pogojem socializacije; VCA S460 »Playful and generally good with children.« (PetMD: otroke nadzorujte); PetMD S461 »… if they're left alone for too long.« | #339 |
| 2026-10-22 | pravilo runbooka | **brez** oznak `small_children`, `other_pets`, `chases_small_pets`, `often_alone`, `apartment`, `low_shedding`, `frequent_grooming`, `experienced_owner` | majhni otroci: PetMD svetuje nadzor; druge živali: VCA le »fairly well«; noben vir ne navaja močnega lovskega nagona; RKC »Large house«; česanje tedensko; »assertive and willful« le Wikipedija (tier C) | #339 |
| 2026-10-22 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | RKC Breed Watch kategorija 1 (S459) — brez posebnih težav zaradi telesne zgradbe | #339 |
| 2026-10-22 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot pes za stanovanje, pes z malo gibanja ali pes, ki je lahko dolgo sam | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #339 |
| 2026-10-22 | pravilo runbooka (§3) | barve za AI in izbirnik: **rjav** (portret), rdečkasto zlat (sedge), barve suhe trave (deadgrass); nikoli črn, belo le majhna lisa na prsih ali prstih | FCI S458 »Any colour of brown, sedge or deadgrass is acceptable, self-coloured Chesapeakes being preferred.«; diskvalifikacija »Black coloured.« (uteži barv brez vira → `verified: false`; standard nobene barve ne daje prednosti — rjava za portret je predlog Claude) | #339 |
| 2026-10-22 | predlog Claude, David lahko spremeni | ime SL »Chesapeake Bay retriver« (slug `chesapeake-bay-retriver`); iskalni ključi tudi »chessie«, »chesapeake«, »chesapeake retriever« | slovenskega imena nisem našel (KZS ni dosegljiv); angleško ime s slovensko besedo »retriver« | #339 |
| 2026-10-22 | pravilo runbooka (§2.3) | seznam za vzreditelja: najmanj genetska raznolikost, BVA/RKC shema za komolce, BVA/RKC shema za kolke, DNK test za degenerativno mielopatijo (DM); najboljša praksa DNK test prcd-PRA, pregled oči BVA/RKC/ISDS | RKC S459 (»We strongly recommend that breeders, at a minimum, conduct these tests before breeding« / »To support the breed’s health, responsible breeders should ensure they complete all tests in both categories.«). Brez novega ključa | #339 |
| 2026-10-22 | pravilo runbooka | nov zdravstveni ključ registra **`exercise_induced_collapse`** (kolaps ob naporu, EIC) — mirna povedi brez številk | PetMD S461 »This neuromuscular condition can cause Chessies to collapse.« | #339 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »chesapeake«, »chessie« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-56 | 2026-10-22 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-56 | 2026-10-22 |
| Česanje 1× na teden (rutina »Počeši«), opozorilo po obroku (60 min), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R16 / M5-R17 | 2026-10-22 |
| Barva (izbirnik barve: rjav, rdečkasto zlat, barve suhe trave) | 🛠 | 💶 izziv | M5-R21 | 2026-10-22 |
| AI portret v registru | 🗓 | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/chesapeake-bay-retriever`, `/sl/zivali/psi/chesapeake-bay-retriver`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal Chesapeake Bay retriverja (»Chessie«) — 52. najbolj priljubljeno pasmo po AKC 2024 in 52. plačljivo pasmo psa. 6 novih virov (S457–S462). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot 2 uri gibanja), starejši od 7,5 leta 9.000; uči se hitreje od mešančka (Coren: 27. mesto, ×1,2). Tedensko česanje, opozorilo »počakaj s sprehodom« po obroku in socializacija mladička so del izziva od prvega dne.
**Zakaj je pomembno:** Chessie je vesel, zelo ljubeč delovni prinašalec iz vode, ki potrebuje več kot dve uri gibanja na dan, veliko hišo z vrtom in družbo — če je predolgo sam, postane uničevalen. Prav to pokaže 12 tednov PetPrepa — ali je nekdo vsak dan doma in zmore dolg sprehod.
**Kako povedati** — starši: »Chesapeake Bay retriver je ljubeč družinski pes, a potrebuje več kot dve uri gibanja na dan in ne mara biti dolgo sam. V PetPrepu boste videli, ali družina zmore 12.000 korakov na dan 12 tednov zapored.« · otroci: »Chessie je rjav pes, ki obožuje vodo. Vsak dan greš z njim na dolg sprehod, a ne takoj po jedi, in enkrat na teden ga počešeš.« · vlagatelji / partnerji: »Dvainpetdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Potreba brez mehanike (needs-david, potrebna nova naloga v ROADMAP):** počasna skleda, ko pes je prehitro, in ne dvignjena skleda zaradi zasuka želodca (PetMD S461).
- **Mediana življenjske dobe:** tabele McMillan 2024 (Dogs Trust S123) ta zagon ni mogel odpreti (orodje jo je zavrnilo, članek na PMC je vrnil captcho) → starejši od 90 mesecev po spodnji meji RKC »Over 10 years«. Preverite, ali ima tabela vrstico za pasmo; če jo ima, se meja lahko spremeni (nova podatkovna migracija).
- **Ime:** »Chesapeake Bay retriver« ni preverjeno pri KZS.
- **Barva portreta:** standard nobeni od treh barv ne daje prednosti; rjava je izbrana kot najbolj znana (predlog Claude).
- **Nadzor otrok:** PetMD svetuje nadzor ob otrocih (za vse pse) → oznaka `children` brez `small_children`.
