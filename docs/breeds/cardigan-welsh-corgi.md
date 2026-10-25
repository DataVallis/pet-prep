# Cardigan Welsh Corgi (Welsh corgi cardigan)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-64 · 2026-10-25

| | |
|---|---|
| Ključ / slug | `cardigan_welsh_corgi` / `cardigan-welsh-corgi` (SL `welsh-corgi-cardigan`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 600 — v izbirniku za akito |
| Vir podatkov | `docs/research/dog-data/data.json` → `cardigan_welsh_corgi` (viri S513–S518; ponovno S8, S10, S11, S14, S35, S45, S174 in PDSA S206 — ena stran za oba korgija); seznam za vzreditelja: 3 testi »Best Practice« (RKC S515) |
| Izvor | FCI št. 38 »Welsh Corgi (Cardigan)«, skupina 1, sekcija 2 (goniči goveda), Velika Britanija (S513) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 60. mesto »Cardigan Welsh Corgi« (S174, prebrano 2026-10-25) |
| PR-ji | pet-prep #380 (pasma, združen `22fbc31`) · portret: **še ni** (zagon 40157509251 zavrnjen — `composer.lock` na `main` se razlikuje od produkcije) · spletna stran: čaka na portret |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-25 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S515 »Exercise: Up to 1 hour per day« (»up to N« → N); PDSA S206 »around an hour« se ujema | #380 |
| 2026-10-25 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108** mesecev; prihod 2 / 9 / 36 / 108 | AAHA S11; starejši = 0,75 × spodnja meja RKC »Over 12 years« (S515; PDSA S206 enako). McMillan 2024 le prek Wikipedije (S518, tier C) → ne uporabljeno | #380 |
| 2026-10-25 | pravilo runbooka | učenje **1,5×** (Coren rang 26, skupina »Excellent«) | S35 | #380 |
| 2026-10-25 | pravilo runbooka | teža psi 13,6–17,2 kg, psice 11,3–15,4 kg (igra 11,3–17,2 kg), višina 27–32 cm, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | standard AKC S517 (FCI S514 in RKC S516 navajata le »v sorazmerju z velikostjo«; PDSA 9–12 kg velja za oba korgija); PetMD S10 (razred »Medium«) | #380 |
| 2026-10-25 | pravilo runbooka (M5-R13) | česanje **3× na teden** | PDSA S206 »grooming two-three times per week« → zgornja meja 3; RKC S515 »More than once a week« | #380 |
| 2026-10-25 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S206 »socialisation with children and training at a young age can help to overcome these possible problems.« | #380 |
| 2026-10-25 | pravilo »Breed care tasks« (David 2026-10-24) | **brez** nalog zob, nohtov, ušes, frizerja ali kopanja; **brez** opozorila po obroku | noben vir ne navaja pogostosti; ni citata o premoru po obroku | #380 |
| 2026-10-25 | pravilo runbooka | oznake (kot pri pembroku): upoštevajte »pri igri lahko pase otroke« (`may_herd_children`), »izpada mu dlaka« (`sheds`), »česanje večkrat na teden« (`frequent_grooming`); brez oznak »primeren za« | PDSA S206 »They may nip at the heels of running children«; RKC S515 »Sheds: Yes«, »Grooming: More than once a week« | #380 |
| 2026-10-25 | pravilo runbooka | **brez** oznak `family_pet`, `children`, `apartment`, `large_home`, `active_family`, `first_time_owner`, `chases_small_pets`, `shorter_lifespan`; brez nove oznake | ni ocene za družino / otroke; RKC »Small house«; 60 min; ni navedbe lovskega nagona; »Over 12 years« | #380 |
| 2026-10-25 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | RKC Breed Watch kategorija 1 (S515); PDSA med možnimi težavami navaja IVDD in displazijo kolkov (S206) — le v raziskavi (precedens pembroke) | #380 |
| 2026-10-25 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli z obljubo o zdravju | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #380 |
| 2026-10-25 | pravilo runbooka (§3) | barve za AI in izbirnik: **rdeča z belimi oznakami** (portret), tigrasta, soboljasta, modro marmorirana (»blue merle« je standardna barva), trobarvna s tigrastimi / rdečimi oznakami; bela nikoli prevladujoča; rep kot lisičji čop (cardigan nikoli ni bil kupiran) | FCI S514 »Accepted colours are blue merle, brindle, red, sable, tricolour with brindle points and tri colour with red points.«; RKC S515. Uteži barv brez vira → `verified: false`; barva portreta je predlog Claude | #380 |
| 2026-10-25 | predlog Claude, David lahko spremeni | ime SL »Welsh corgi cardigan« (kot »Welsh corgi pembroke«); iskanje »cardigan«, »cardi«, »corgi«, »korgi«, »valižanski korgi« … (»corgi« / »korgi« najdeta oba korgija) | slovenskega vira (KZS) za ime ni bilo mogoče preveriti | #380 |
| 2026-10-25 | pravilo runbooka (§2.3) | seznam za vzreditelja: PRA-rcd3 (DNK), očesni pregled BVA/KC/ISDS, kolki BVA/KC — vsi »Best Practice« | RKC S515 | #380 |
| 2026-10-25 | pravilo runbooka | zdravstveni ključi registra (kot pembroke): `hip_dysplasia_arthritis`, `back_disc_disease`, `epilepsy`, `bleeding_disorder_vwd`, `cataracts_lens_luxation`; brez odstotkov | PDSA S206, RKC S515 | #380 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 3× na teden«, iskanje »cardigan«, »welsh corgi cardigan«, »cardi« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-64 | 2026-10-25 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 108 meseca; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-64 | 2026-10-25 |
| Česanje 3× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-25 |
| Barva (izbirnik: rdeče-bel, tigrast z belo, sobolji z belo, modro marmoriran, dve trobarvni) | 🛠 | 💶 izziv | M5-R21 | 2026-10-25 |
| AI portret v registru | 🗓 čaka: zagon `breed-portraits.yml` 40157509251 je zavrnil kodo (`composer.lock` iz M4-05 še ni v produkciji); naredi ga naslednji zagon (dohitevanje portretov) po naslednji produkcijski namestitvi | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/cardigan-welsh-corgi`, `/sl/zivali/psi/welsh-corgi-cardigan`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal welsh corgija cardigana — 60. najbolj priljubljeno pasmo po AKC 2024 in 60. plačljivo pasmo psa. 6 novih virov (S513–S518). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do ene ure gibanja), starejši 4.500 od 9. leta; uči se hitro (Coren: 26. mesto, ×1,5). Česanje trikrat na teden in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** Cardigan je starejši »bratranec« pembroka — pastirski pes iz Walesa z dolgim repom, ki rad »pase« otroke, ko tečejo, in mu izpada dlaka. Je ranljiva domača britanska pasma (malo registracij). Ena ura gibanja na dan in redno česanje sta obvladljivi, a vsakodnevni obvezi — PetPrep pokaže, ali ju družina zdrži 12 tednov.
**Kako povedati** — starši: »Cardigan potrebuje uro gibanja na dan in česanje večkrat na teden; otroke lahko med igro ščiplje za pete. V PetPrepu boste videli, ali vaš otrok zmore 6.000 korakov na dan 12 tednov zapored.« · otroci: »Cardigan je majhen pastirski pes z dolgim repom kot lisica. Z njim greš vsak dan ven in ga večkrat na teden počešeš.« · vlagatelji / partnerji: »Šestdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Meja starejšega psa (108 mesecev, pravilo runbooka, začasno):** iz spodnje meje RKC »Over 12 years«. McMillan 2024 (13,1 leta, zdaj le prek Wikipedije S518) bi dal 118 — če ga potrdite v viru A/B, podatkovna migracija ali urejanje v Filamentu.
- **Hrbtenica (IVDD):** PDSA jo navaja med možnimi težavami obeh korgijev; brez oznake `back_spine` (kot pri pembroku) — želite oznako?
- **Ime:** »Welsh corgi cardigan« ni preverjeno pri KZS.
- **Barva portreta:** rdeča z belimi oznakami je predlog Claude (standard ne določa najpogostejše barve).
- **Deljena stran PDSA:** citati PDSA (česanje, socializacija, ščipanje otrok) veljajo za oba korgija skupaj.
- **Portret (needs-david):** `breed-portraits.yml` (zagon 40157509251, koda `22fbc31`) je zavrnil zagon, ker se `composer.lock` na `main` (M4-05, `16abf65`) razlikuje od produkcije. Po naslednji produkcijski namestitvi (dnevna ob 6.00 ali ročna) ga naredi dohitevanje portretov naslednjega zagona; spletna stran pasme do takrat ne izide (pravilo »brez slike ni strani«).
