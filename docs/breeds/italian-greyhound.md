# Mali italijanski hrt (Italian Greyhound)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-60 · 2026-10-23

| | |
|---|---|
| Ključ / slug | `italian_greyhound` / `italian-greyhound` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 560 — v izbirniku za weimarskim ptičarjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `italian_greyhound` (viri S484–S490; ponovno S8, S10, S11, S14, S24, S35, S45, S123, S174) |
| Izvor | FCI št. 200 »Piccolo Levriero Italiano«, skupina 10, sekcija 3 (kratkodlaki hrti), Italija (S484) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 56. mesto »Italian Greyhound« (S174, prebrano 2026-10-23) |
| PR-ji | pet-prep #365 (pasma, združen `0ad5e09`) · portret #366 · spletna stran: pet-prep-website, veja `feat/register-italian-greyhound` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-23 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S486 »Exercise: Up to 1 hour per day« (»up to N« → N); PDSA S487 »Minimum exercise (per day) 1 hour« se ujema | #365 |
| 2026-10-23 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108** mesecev; prihod 2 / 9 / 36 / 108 | 0,75 × spodnja meja RKC »Over 12 years« (S486) = 9 let = 108. Tabele Dogs Trust z medianami McMillan 2024 (S123) ta zagon ni mogel odpreti, mediane VetCompass ni → tretja izbira pravila (kot pri papijonu). Alternativi: 126 (mediana McMillan 2024 »about 14 years«, le prek Wikipedije S489, tier C; PetMD S488 14–15 let); 84 (Dogs Trust > 7 let, S14) | #365 |
| 2026-10-23 | pravilo runbooka | učenje **0,7×** (Coren rang 60, skupina »Fair«) | S35 (rang 60) | #365 |
| 2026-10-23 | pravilo runbooka | teža 3,6–4,5 kg, rast do 8–12 mesecev; hrana, voda in čiščenje kot pri border collieju | standard Kennel Club (S485) »3.6kg – 4.5kg.«; PDSA S487 3–4,5 kg (alternativa); PetMD S10 (razred »Toy«) | #365 |
| 2026-10-23 | pravilo runbooka (M5-R13) | česanje **1× na teden** (zmršena dlaka po 1 zamujenem) | RKC S486 »Grooming: Once a week« → 1; PDSA S487 tedensko in PetMD S488 »a gentle weekly brushing …« enako | #365 |
| 2026-10-23 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S487 »They can also be quite timid, so early socialisation is essential.« | #365 |
| 2026-10-23 | pravilo runbooka (M5-R16) | **brez** opozorila po obroku | noben vir ne svetuje premora med hranjenjem in gibanjem | #365 |
| 2026-10-23 | pravilo runbooka | oznake: družino z otroki (`children`), dom, kjer želite manj dlak (`low_shedding`); upoštevajte: krhke kosti — bolje za starejše otroke (`fragile_older_children`), **nova** oznaka tanka dlaka — občutljiv na mraz (`cold_sensitive`) | PetMD S488 »Though Italian Greyhounds do well with children who understand how to appropriately interact with dogs, …« (z opozorilom: nadzor, ne za majhne razigrane otroke); RKC S486 »Sheds: No«; PDSA S487 »Due to their bone fragility during growth, they may not be the ideal breed to live with other pets or young children.«; PetMD S488 »However, their coat does not insulate them well, … they can be easily affected by the cold.« | #365 |
| 2026-10-23 | pravilo runbooka (»nova oznaka«) | nov ključ besednjaka **`cold_sensitive`** (»thin coat — feels the cold« / »tanka dlaka — občutljiv na mraz«) | noben obstoječi ključ ne pove občutljivosti na mraz (`heat_sensitive` = gost kožuh v vročini) | #365 |
| 2026-10-23 | pravilo runbooka | **brez** oznak `small_children`, `other_pets`, `family_pet`, `apartment`, `large_home`, `chases_small_pets`, `needs_company` | PDSA: ni idealen z majhnimi otroki in drugimi živalmi (krhke kosti); družinska izjava PetMD je bila vrnjena le kot povzetek; RKC »Small house«; PetMD »have a prey drive« ni »high prey drive« (pravilo); o samoti ni vira | #365 |
| 2026-10-23 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | RKC Breed Watch kategorija 1 (S486) | #365 |
| 2026-10-23 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot pes za majhne otroke, »igrača« za nošenje, pes brez povodca na odprtem ali »hipoalergen« | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #365 |
| 2026-10-23 | pravilo runbooka (§3) | barve za AI in izbirnik: **rumenorjav** (portret), modrosiv, črn, kremast, rdeč, bel, rumenorjavo-bel; nikoli merle, tigrast ali s porjavelimi oznakami; vitek, a zdrav (nikoli shujšan) | KC S485 »Black, blue, cream, fawn, red, white, or any of these colours broken with white.« in »Merle, black or blue with tan markings, brindle … unacceptable.« (uteži barv brez vira → `verified: false`; rumenorjava za portret je predlog Claude) | #365 |
| 2026-10-23 | predlog Claude, David lahko spremeni | ime SL »mali italijanski hrt« (slug `mali-italijanski-hrt`); iskalni ključi tudi »italijanski hrt«, »italijanski hrtek«, »piccolo levriero italiano«, »iggy« | običajno slovensko ime (MrPet.si S490, tier C); pri KZS ni preverjeno | #365 |
| 2026-10-24 | pravilo runbooka (§8) | AI portret: realistična fotografija odraslega psa, rumenorjava kratka fina dlaka, vitka postava hrta v malem, zavihani uhlji, dolg nizko nošen rep, svetla studijska podlaga, naravna razmerja; sprejet v prvem poskusu (zagon 40042977749, koda `0ad5e09`, brez produkcijske namestitve, 0,15 $) | barva portreta = najtežja barva iz `breed_appearance.php` | #366 |
| 2026-10-23 | pravilo runbooka (§2.3) | seznam za vzreditelja (najboljša praksa): pregled oči BVA/KC/ISDS | RKC S486 »To support the breed’s health, responsible breeders should ensure they complete all tests in best practice.« Brez novega ključa | #365 |
| 2026-10-23 | pravilo runbooka | nov zdravstveni ključ registra **`periodontal_disease`** (bolezen obzobnih tkiv) — mirna poved brez številk; ostali: `epilepsy`, `kneecap_luxation`, `pra_eye_disease`, `autoimmune_conditions`, `legg_perthes`, `hypothyroidism` | PDSA S487, PetMD S488 | #365 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »mali italijanski hrt«, »italijanski« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-60 | 2026-10-23 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 108 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-60 | 2026-10-23 |
| Česanje 1× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-23 |
| Barva (izbirnik barve: rumenorjav, modrosiv, črn, kremast, rdeč, bel, rumenorjavo-bel) | 🛠 | 💶 izziv | M5-R21 | 2026-10-23 |
| AI portret v registru (rumenorjav, sprejet v prvem poskusu, zagon 40042977749) | ✅ | — | M5-R11-04 | 2026-10-24 |
| Stran na petprep.si (`/animals/dogs/italian-greyhound`, `/sl/zivali/psi/mali-italijanski-hrt`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal malega italijanskega hrta — 56. najbolj priljubljeno pasmo po AKC 2024 in 56. plačljivo pasmo psa. 7 novih virov (S484–S490). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC in PDSA: do oziroma okoli ene ure gibanja), starejši od 9 let 4.500; uči se počasneje (Coren: 60. mesto, ×0,7). Tedensko česanje in socializacija mladička sta del izziva od prvega dne. Nova oznaka »tanka dlaka — občutljiv na mraz«.
**Zakaj je pomembno:** Mali italijanski hrt je majhen, eleganten in ljubeč, a ima krhke kosti (posebej med rastjo), slabo prenaša mraz in je lahko plašen — zato ni idealen za dom z majhnimi otroki. Prav to se v 12 tednih PetPrepa pokaže: ali otrok zmore nežno ravnanje in vsakodnevno skrb.
**Kako povedati** — starši: »Mali italijanski hrt ne potrebuje veliko prostora, a potrebuje nežne roke: kosti so krhke, pozimi potrebuje plašček. V PetPrepu boste videli, ali družina zmore 6.000 korakov na dan in mirno skrb 12 tednov zapored.« · otroci: »Mali italijanski hrt je majhen in hiter kot puščica. Z njim ravnaš nežno, vsak dan greš na sprehod in enkrat na teden ga počešeš.« · vlagatelji / partnerji: »Šestinpetdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili ter novo oznako, brez novih vprašanj.«

## Odprto za Davida

- **Rešeno 24. 10. 2026 (David, M5-R14 / R15 / R18 / R19 / R20 — PR #PRNUM):** naloga samo, kjer vir navaja pogostost (razpon → spodnja meja); mini-igre otroka po modelu česanja (pošten delež, reže, poročilo, brez kazni), pasji frizer in kopanje = opomnik staršu, ne šteje v oceno; samo novi psi. Za to pasmo — brez pogostosti v viru → brez naloge (dejstvo ostane v besedilu): čiščenje ušes, striženje krempljev. Spodnje točke M5-R14 / R15 / R18 / R19 / R20 so s tem zaprte.

- **Potreba brez mehanike (potrebuje novo nalogo v ROADMAP):** plašček / pulover v mrazu (PetMD S488) — v igri le oznaka `cold_sensitive`.
- **Potreba brez mehanike (potrebuje novo nalogo v ROADMAP):** tek brez povodca samo v zaprtem prostoru (PDSA S487 »exercise in a confined area is recommended.«; kot pri malem angleškem hrtu).
- **Potrebe brez mehanike (ROADMAP M5-R20 / M5-R19 / M5-R14):** čiščenje ušes in občasno kopanje, striženje krempljev, čiščenje oči proti madežem solz (PetMD S488, brez pogostosti).
- **Zobje:** PDSA navaja bolezen obzobnih tkiv, vira s pogostostjo ščetkanja ni → ni zgrajeno (ROADMAP M5-R15, če ga najdete).
- **Mediana življenjske dobe:** tabele McMillan 2024 (Dogs Trust S123) ta zagon ni mogel odpreti; Wikipedija (S489, tier C) navaja »about 14 years« iz McMillan 2024 → če jo potrdite v viru, bi bil starejši od 126 mesecev namesto 108 (nova podatkovna migracija).
- **Lovski nagon:** PetMD »have a prey drive« ni »high prey drive« → brez oznake `chases_small_pets` (pravilo runbooka); po želji jo dodate.
- **Družinska oznaka:** PetMD opisuje pasmo kot primerno za družinsko življenje, a je bilo vrnjeno le kot povzetek → brez `family_pet`.
- **Ime:** »mali italijanski hrt« ni preverjeno pri KZS.
- **Barva portreta:** standard nobeni barvi ne daje prednosti; rumenorjava je izbrana kot pogosta enobarvna (predlog Claude).
