# Weimarski ptičar (Weimaraner)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-59 · 2026-10-23

| | |
|---|---|
| Ključ / slug | `weimaraner` / `weimaraner` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 550 — v izbirniku za škotskim terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `weimaraner` (viri S477–S483; ponovno S8, S10, S11, S14, S24, S35, S45, S123, S174) |
| Izvor | FCI št. 99, skupina 7, sekcija 1.1 (celinski ptičarji, tip »braque«), Nemčija (S477, S478) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 55. mesto »Weimaraner« (S174, prebrano 2026-10-23) |
| PR-ji | pet-prep #352 (pasma, združen `8eda9d4`) · portret #353 (združen `210efaf`) · spletna stran #93 (združen `6b3d9cd`) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-23 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | RKC S479 »Exercise: More than 2 hours per day« (»more than N« → N); PDSA S480 »a minimum of two hours« in PetMD S481 »at least two hours« se ujemata | #352 |
| 2026-10-23 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90** mesecev; prihod 2 / 9 / 36 / 90 | 0,75 × spodnja meja RKC »Over 10 years« (S479) = 7,5 leta = 90. Tabele Dogs Trust z medianami McMillan 2024 (S123) ta zagon ni mogel odpreti, mediane VetCompass ni → tretja izbira pravila. Alternativi: 84 (Dogs Trust > 7 let, S14); 115 (mediana McMillan 2024 12,8 leta, le prek Wikipedije S483, tier C) | #352 |
| 2026-10-23 | pravilo runbooka | učenje **1,5×** (Coren rang 21, skupina »Excellent«) | S35 (rang 21) | #352 |
| 2026-10-23 | pravilo runbooka | teža 25–40 kg, rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | standard FCI št. 99 (S478): psi okoli 30–40 kg, psice okoli 25–35 kg; PDSA S480 25–40 kg se ujema; PetMD S10 (razred »Large«) | #352 |
| 2026-10-23 | pravilo runbooka (M5-R13) | česanje **1× na teden** (zmršena dlaka po 1 zamujenem) | RKC S479 »Grooming: Once a week« → 1; PDSA S480 »A weekly brush should be plenty …« in PetMD S481 enako | #352 |
| 2026-10-23 | David 2026-10-12 (M5-R16) | **opozorilo po obroku** (60 min »počakaj s sprehodom«, koraki se štejejo) | PDSA S480 »Remember to leave a gap between eating and exercising as this is thought to reduce the risk of GDV.« (PetMD S481: 1–2 uri — alternativa) | #352 |
| 2026-10-23 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S480 »Weimaraners tend to be a little cautious of unfamiliar dogs, so socialisation from a young age is really important.« | #352 |
| 2026-10-23 | pravilo runbooka | oznake: aktivno družino (`active_family`), družinsko življenje (`family_pet`), veliko hišo z vrtom (`large_home`); upoštevajte: vsak dan potrebuje veliko gibanja (`long_daily_exercise`), potrebuje izkušenega lastnika (`experienced_owner`), ni za dom z majhnimi otroki (`not_for_small_children`), rad ima družbo (`needs_company`), izpada mu dlaka (`sheds`), močan lovski nagon (`chases_small_pets`) | RKC S479 »Exercise: More than 2 hours per day«, »Size of home: Large house«, »Sheds: Yes«; PDSA S480 »In the right hands, Weimaraners make wonderful family pets …«, »… do best with owners familiar with the breed and their needs.«, »we wouldn’t recommend getting a Weimaraner if you have smaller children …«, »They have a very strong prey drive …«; PetMD S481 »Weimaraners can develop separation anxiety.« | #352 |
| 2026-10-23 | pravilo runbooka | **brez** oznak `children`, `small_children`, `other_pets`, `apartment`, `low_shedding`, `frequent_grooming`, `chews_when_bored`, `first_time_owner` | PDSA odsvetuje manjše otroke in manjše živali; RKC »Large house«; česanje tedensko; grizenje PDSA omenja le ob ločitveni tesnobi (pokrito z `needs_company`) | #352 |
| 2026-10-23 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | RKC Breed Watch kategorija 1 (S479) — brez posebnih težav zaradi telesne zgradbe | #352 |
| 2026-10-23 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot pes za majhne otroke, začetnike ali stanovanje, pes z malo gibanja, pes, ki je lahko dolgo sam, ali s kupiranim repom kot ciljem | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #352 |
| 2026-10-23 | pravilo runbooka (§3) | barve za AI in izbirnik: **srebrno siv** (portret), srnje siv, mišje siv; nikoli moder, rjav ali s porjavelimi oznakami; belo le majhne lise na prsih in prstih; kratkodlaka različica, naraven (nekupiran) rep | FCI S478 »Silver, roe or mouse grey, as well as shades of these colours.«; diskvalifikacija »Colour other than grey. Blue colouring.« (uteži barv brez vira → `verified: false`; srebrno siva za portret je predlog Claude) | #352 |
| 2026-10-23 | predlog Claude, David lahko spremeni | ime SL »weimarski ptičar« (slug `weimarski-pticar`); iskalni ključi tudi »vajmarski ptičar«, »weim« | običajno slovensko ime; pri KZS ni preverjeno | #352 |
| 2026-10-23 | pravilo runbooka (§8) | AI portret: realistična fotografija odraslega psa, srebrno siva kratka dlaka, jantarne oči, naraven (nekupiran) rep, svetla studijska podlaga, naravna razmerja; sprejet v prvem poskusu (zagon 39898014999, koda `8eda9d4`, brez produkcijske namestitve). Odtenek je bolj proti mišje sivi, konica repa malo temnejša — sprejemljivo | barva portreta = najtežja barva iz `breed_appearance.php` | #353 |
| 2026-10-23 | pravilo runbooka (§2.3) | seznam za vzreditelja (najboljša praksa): BVA/KC shema za komolce, pregled oči BVA/KC/ISDS, BVA/KC shema za kolke | RKC S479 »To support the breed's health, responsible breeders should ensure they complete all tests in Best Practice.« Brez novega ključa | #352 |
| 2026-10-23 | pravilo runbooka | nov zdravstveni ključ registra **`extra_eyelashes`** (distihiaza — dodatne trepalnice) — mirna poved brez številk | PDSA S480 »a condition in which small eyelashes grow on the inner surface or the very edge of the eye…« | #352 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »weimarski ptičar«, »vajmarski« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-59 | 2026-10-23 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-59 | 2026-10-23 |
| Česanje 1× na teden (rutina »Počeši«), opozorilo po obroku (60 min), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R16 / M5-R17 | 2026-10-23 |
| Barva (izbirnik barve: srebrno siv, srnje siv, mišje siv) | 🛠 | 💶 izziv | M5-R21 | 2026-10-23 |
| AI portret v registru (srebrno siv, sprejet v prvem poskusu, zagon 39898014999) | ✅ | — | M5-R11-04 | 2026-10-23 |
| Stran na petprep.si (`/animals/dogs/weimaraner`, `/sl/zivali/psi/weimarski-pticar`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal weimarskega ptičarja — 55. najbolj priljubljeno pasmo po AKC 2024 in 55. plačljivo pasmo psa. 7 novih virov (S477–S483). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC, PDSA in PetMD: vsaj 2 uri gibanja), starejši od 7,5 leta 9.000; uči se hitro (Coren: 21. mesto, ×1,5). Tedensko česanje, opozorilo »počakaj s sprehodom« po obroku in socializacija mladička so del izziva od prvega dne.
**Zakaj je pomembno:** Weimarski ptičar je ljubeč, zvest in zelo dejaven lovski pes, ki se hitro ne utrudi, ne mara biti sam, ima močan lovski nagon in ni za dom z majhnimi otroki. Prav to pokaže 12 tednov PetPrepa — ali je nekdo večino dneva doma in zmore vsak dan dve uri gibanja.
**Kako povedati** — starši: »Weimarski ptičar je čudovit družinski pes v pravih rokah, a potrebuje vsaj dve uri gibanja na dan, družbo in izkušenega lastnika. V PetPrepu boste videli, ali družina zmore 12.000 korakov na dan 12 tednov zapored.« · otroci: »Weimarec je srebrno siv pes z jantarnimi očmi. Vsak dan greš z njim na dolg sprehod, a ne takoj po jedi, in enkrat na teden ga počešeš.« · vlagatelji / partnerji: »Petinpetdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Potreba brez mehanike (needs-david, ROADMAP M5-R20):** čiščenje ušes 1× na mesec in kopanje 1× na mesec (PetMD S481).
- **Mediana življenjske dobe:** tabele McMillan 2024 (Dogs Trust S123) ta zagon ni mogel odpreti; Wikipedija (S483, tier C) navaja mediano 12,8 leta iz McMillan 2024 → če jo potrdite v viru, bi bil starejši od 115 mesecev namesto 90 (nova podatkovna migracija).
- **Različica dlake:** igra prikazuje kratkodlako različico; FCI priznava tudi dolgodlako (RKC »Short & long«).
- **Otroci:** PDSA ne priporoča pri manjših otrocih → `not_for_small_children` namesto `children` (kot pri madžarskem ptičarju).
- **Grizenje:** PDSA omenja grizenje ob ločitveni tesnobi — brez oznake `chews_when_bored`.
- **Ime:** »weimarski ptičar« ni preverjeno pri KZS.
- **Barva portreta:** standard nobenemu odtenku sive ne daje prednosti; srebrno siva je izbrana kot najbolj znana (predlog Claude).
