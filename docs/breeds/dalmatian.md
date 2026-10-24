# Dalmatinec (Dalmatian)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-52 · 2026-10-21

| | |
|---|---|
| Ključ / slug | `dalmatian` / `dalmatian` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 480 — v izbirniku za novofundlandcem |
| Vir podatkov | `docs/research/dog-data/data.json` → `dalmatian` (viri S427–S434; ponovno S8, S10, S11, S14, S24, S35, S45, S123, S174) |
| Izvor | FCI št. 153, skupina 6, sekcija 3 (sorodne pasme), Hrvaška (S427, S428) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 48. mesto »Dalmatian« (S174) |
| PR-ji | pet-prep #305 (pasma, združen `f8142b3`) · portret #306 (združen `a99b047`) · spletna stran #83 (združen `8e2d902`) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-21 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | RKC S429 »Exercise: More than 2 hours per day« (»več kot N« → N); PDSA S430 »a minimum of two hours of exercise every day« se ujema | #305 |
| 2026-10-21 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **119** mesecev; prihod 2 / 9 / 36 / 119 | 0,75 × mediana McMillan 2024 **13,2 leta** (tabela Dogs Trust S123) = 118,8 → 119 — ni začasno. Alternativi: 84 (Dogs Trust > 7 let, S14), 108 (spodnja meja RKC 12 let, S429) | #305 |
| 2026-10-21 | pravilo runbooka | učenje **1,2×** (Coren rang 39, skupina »Above average«) | S35 | #305 |
| 2026-10-21 | pravilo runbooka | teža 18–27 kg, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S430 »18kg-27kg« (standard FCI teže ne navaja; PetMD S431 45–70 lb = alternativa); PetMD S10 (razred »Medium«) | #305 |
| 2026-10-21 | pravilo runbooka (M5-R13) | česanje **1× na teden** (zmršena dlaka po 1 zamujenem) | RKC S429 »Grooming: Once a week«; PetMD S431 »weekly brushing« se ujema | #305 |
| 2026-10-21 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S431 »This is why socializing Dalmatian puppies early in life is so important.« | #305 |
| 2026-10-21 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« | noben prebran vir ne navaja premora med obrokom in gibanjem | #305 |
| 2026-10-21 | pravilo runbooka | oznake: aktivna družina (`active_family`), velika hiša z vrtom (`large_home`); upoštevajte: vsak dan veliko gibanja (`long_daily_exercise`), velik pes — ni za dom z majhnimi otroki (`not_for_small_children`), rad ima družbo (`needs_company`), izpada mu dlaka (`sheds`). Brez nove oznake | RKC S429 »More than 2 hours per day«, »Size of home: Large house«, »Size of garden: Large garden«, »Sheds: Yes«; PDSA S430 »great pets for active households«, »better suited to households with older children«, »never leave your dog alone for more than four hours«, »really big shedders!« | #305 |
| 2026-10-21 | pravilo runbooka | **brez** oznak `children`, `other_pets`, `chases_small_pets` | PDSA priporoča starejše otroke in nadzor; PDSA (nadzor ob manjših živalih, »may get the urge to chase«) in PetMD (»good with … other pets«) si nasprotujeta; ni citata »high prey drive« | #305 |
| 2026-10-21 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | RKC Breed Watch »Category 1« (S429) | #305 |
| 2026-10-21 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot pes za majhne otroke, za dom, kjer je sam, ali ob malih živalih | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #305 |
| 2026-10-21 | pravilo runbooka (§3) | barve za AI in izbirnik: **bel s črnimi pikami** (portret), **bel z rjavimi pikami**; nikoli lise, tribarvni, tigrasti, limonasti / modri madeži ali modre oči | FCI S428 »Black spotted variety with black spots, liver spotted variety with brown spots.«; izločilne napake S428 | #305 |
| 2026-10-21 | predlog Claude, David lahko spremeni | ime SL »dalmatinec« (slug `dalmatinec`); iskalni ključi tudi »dalmatinski pas«, »dalmatinka«, »carriage dog«, »firehouse dog« | Siol.net S434 (tier C); KZS ni preverjen | #305 |
| 2026-10-21 | pravilo runbooka (§8) | AI portret: realistična fotografija odraslega psa, bel s črnimi pikami, siva studijska podlaga; sprejet v prvem poskusu (zagon 39530281044, koda `f8142b3`, brez produkcijske namestitve) | barva portreta = najtežja barva iz `breed_appearance.php` | #306 |
| 2026-10-21 | pravilo runbooka (§2.3) | seznam za vzreditelja: test sluha BAER in ocena kolkov — druga shema (priporočilo ameriškega pasemskega kluba). Brez novega ključa | AKC K28 »Hip Evaluation«, »BAER Testing«; stran RKC ima naslov »Best Practice« brez testov; PDSA S430 »There is BAER testing for this.« | #305 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »dalmatinec«, »dalmatian«, »carriage dog« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-52 | 2026-10-21 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 119 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-52 | 2026-10-21 |
| Česanje 1× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-21 |
| Barva (izbirnik barve: črno pikast, rjavo pikast) | 🛠 | 💶 izziv | M5-R21 | 2026-10-21 |
| AI portret v registru (bel s črnimi pikami, sprejet v prvem poskusu, zagon 39530281044) | ✅ | — | M5-R11-04 | 2026-10-21 |
| Stran na petprep.si (`/animals/dogs/dalmatian`, `/sl/zivali/psi/dalmatinec`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal dalmatinca — 48. najbolj priljubljeno pasmo po AKC 2024 in 48. plačljivo pasmo psa. 8 novih virov (S427–S434). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot dve uri gibanja), starejši od skoraj 10 let 9.000; uči se nekoliko hitreje kot mešanček (Coren: 39. mesto). Tedensko česanje in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** Dalmatinca vsi poznajo po pikah in risankah. Viri povedo drugo plat: je zelo energičen (najmanj dve uri gibanja na dan, razdeljeno na vsaj dva sprehoda), močno linja, potrebuje družbo (ne več kot štiri ure sam), bolje se znajde ob starejših otrocih, nekateri psi so naglušni (pri mladičih se sluh preveri s testom BAER). Prav to pokaže 12 tednov PetPrepa.
**Kako povedati** — starši: »Dalmatinec je prijazen, a izjemno aktiven pes, ki potrebuje več kot dve uri gibanja na dan in družbo. V PetPrepu boste videli, ali otrok zmore 12.000 korakov na dan 12 tednov zapored.« · otroci: »Dalmatinec je hitri pikasti tekač — nekoč je tekel ob kočijah. Vsak dan greš z njim na dolg sprehod, enkrat na teden ga počešeš.« · vlagatelji / partnerji: »Oseminštirideseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili; stran RKC je bila tokrat dosegljiva, zato vsa ključna polja prihajajo iz prednostnega vira.«

## Odprto za Davida

- **Rešeno 24. 10. 2026 (David, M5-R14 / R15 / R18 / R19 / R20 — PR #376):** naloga samo, kjer vir navaja pogostost (razpon → spodnja meja); mini-igre otroka po modelu česanja (pošten delež, reže, poročilo, brez kazni), pasji frizer in kopanje = opomnik staršu, ne šteje v oceno; samo novi psi. Za to pasmo — zgrajeno: striženje krempljev enkrat na 4 tedne (mesec) (mini-igra otroka); brez pogostosti v viru → brez naloge (dejstvo ostane v besedilu): čiščenje ušes, ščetkanje zob. Spodnje točke M5-R14 / R15 / R18 / R19 / R20 so s tem zaprte.

- **Potrebe brez mehanike (needs-david → rešeno 24. 10. 2026):** redni pregled ušes, ušesa čista in suha (PetMD S431) → **M5-R20**; striženje krempljev enkrat na mesec (PetMD S431) → **M5-R19**; ščetkanje zob (PetMD S431, citat odrezan) → **M5-R15**; vsaj dva sprehoda na dan, po možnosti tek v varnem ograjenem prostoru (PDSA S430) → **potrebna nova naloga v ROADMAP — David** (igra šteje korake na dan, ne števila sprehodov; kot pri šiba inu / rodezijskem ridgebacku).
- **Prehrana:** PetMD S431 »Speak with your veterinarian before putting your Dalmatian on a low-purine diet.« (sečni kamni) — odločitev veterinarja, v igri ni izbire hrane; le v ozadju.
- **Seznam za vzreditelja:** stran RKC ne našteva testov; DNA test za hiperurikozurijo (HUU) ni bil najden pri viru A/B → ni dodan. Ob naslednjem zagonu preveriti stran RKC Breeds A–Z (naslov ni bil dovoljen).
- **Ime:** »dalmatinec« ni preverjeno pri KZS (vir le Siol.net, tier C).
- **Spletna stran:** nova država izvora Hrvaška (HR) in nov ključ zdravja `deafness` (besede Claude).
