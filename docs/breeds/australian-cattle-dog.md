# Avstralski govedar (Australian Cattle Dog)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-53 · 2026-10-21

| | |
|---|---|
| Ključ / slug | `australian_cattle_dog` / `australian-cattle-dog` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 490 — v izbirniku za dalmatincem |
| Vir podatkov | `docs/research/dog-data/data.json` → `australian_cattle_dog` (viri S435–S441; ponovno S8, S10, S11, S14, S24, S35, S45, S123, S174) |
| Izvor | FCI št. 287, skupina 1, sekcija 2 (goniči goveda), Avstralija (S435) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 49. mesto »Australian Cattle Dog« (S174) |
| PR-ji | pet-prep #308 (pasma) · portret — · spletna stran — |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-21 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | RKC S437 »Exercise: More than 2 hours per day« (»več kot N« → N); PDSA S438 »will need over 2 hours exercise per day« se ujema; PetMD S439 »vsaj 45 minut teka« je le ena dejavnost (alternativa) | #308 |
| 2026-10-21 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **126** mesecev; prihod 2 / 9 / 36 / 126 | 0,75 × mediana McMillan 2024 **14,0 leta** (tabela Dogs Trust S123; majhen vzorec — 117 psov) = 126 — ni začasno. Alternativi: 84 (Dogs Trust > 7 let, S14), 90 (spodnja meja RKC 10 let, S437) | #308 |
| 2026-10-21 | pravilo runbooka | učenje **1,8×** (Coren rang 10, skupina »Brightest«) | S35; max(1,8, round(2,0 − 9/30, 1)) = 1,8 | #308 |
| 2026-10-21 | pravilo runbooka | teža 16–20 kg, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S438 »16kg-20kg« (standard KC teže ne navaja; PetMD S439 35–50 lb = alternativa); PetMD S10 (razred »Medium«) | #308 |
| 2026-10-21 | pravilo runbooka (M5-R13) | česanje **1× na teden** (zmršena dlaka po 1 zamujenem) | RKC S437 »Grooming: Once a week«; PDSA S438 »just once weekly« se ujema; PetMD S439 »will benefit from daily brushing« → alternativa (glej Odprto) | #308 |
| 2026-10-21 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S438 »socialisation with children and training at a young age can help to overcome these possible problems« | #308 |
| 2026-10-21 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« | noben prebran vir ne navaja premora med obrokom in gibanjem | #308 |
| 2026-10-21 | pravilo runbooka | oznake: aktivna družina (`active_family`), družinsko življenje (`family_pet`); upoštevajte: vsak dan veliko gibanja (`long_daily_exercise`), potrebuje miselne izzive (`needs_mental_stimulation`), pri igri lahko »pase« otroke (`may_herd_children`), izpada mu dlaka (`sheds`). Brez nove oznake | RKC S437 »More than 2 hours per day«, »Sheds: Yes«; PDSA S438 »high energy levels«, »needing a lot of physical and mental activity«, »may nip at the heels of running children«; PetMD S439 »can be good family dogs« | #308 |
| 2026-10-21 | pravilo runbooka | **brez** oznak `children`, `large_home`, `apartment`, `needs_company`, `chases_small_pets` | PetMD: nadzor ob majhnih otrocih, PDSA: grizljanje pet otrok, ki tečejo (precedens avstralski ovčar); RKC »Small house« (ni stanovanje ne velika hiša); ločitev od lastnika le kot odlomek; ni citata »high prey drive« | #308 |
| 2026-10-21 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | RKC Breed Watch »Category 1« (S437) | #308 |
| 2026-10-21 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot lahek pes za majhne otroke ali pes z malo gibanja | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #308 |
| 2026-10-21 | pravilo runbooka (§3) | barve za AI in izbirnik: **modro pegast** z oznakami na glavi (portret), **rdeče pegast**; nikoli krem, bel ali merle; naraven rep do skočnega sklepa | standard Kennel Club S436 »Blue, blue-mottled or blue speckled …« / »Good even red speckle all over …« | #308 |
| 2026-10-21 | predlog Claude, David lahko spremeni | ime SL »avstralski govedar« (slug `avstralski-govedar`); iskalni ključi tudi »avstralski govedarski pes«, »blue heeler«, »red heeler«, »queensland heeler«, »acd« | Siol.net S441 (tier C); KZS ni preverjen | #308 |
| 2026-10-21 | pravilo runbooka (§2.3) | seznam za vzreditelja: DNA prcd-PRA, pregled oči (BVA/KC/ISDS), kolki in komolci (BVA/KC), test sluha BAER, kalkulator sorodstva — vse »minimum« (stari format strani RKC, precedens labradorec). DNA PLL (»Other«) ni dodan. Brez novega ključa | RKC S437 | #308 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »avstralski govedar«, »blue heeler«, »queensland heeler« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-53 | 2026-10-21 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 126 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-53 | 2026-10-21 |
| Česanje 1× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-21 |
| Barva (izbirnik barve: modro pegast, rdeče pegast) | 🛠 | 💶 izziv | M5-R21 | 2026-10-21 |
| AI portret v registru | 🗓 po združitvi | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/australian-cattle-dog`, `/sl/zivali/psi/avstralski-govedar`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal avstralskega govedarja — 49. najbolj priljubljeno pasmo po AKC 2024 in 49. plačljivo pasmo psa. 7 novih virov (S435–S441). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot dve uri gibanja), starejši od 10 let in pol 9.000; uči se skoraj dvakrat hitreje kot mešanček (Coren: 10. mesto, med najbistrejšimi). Tedensko česanje in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** Avstralski govedar je delovni pes, vzrejen za vodenje goveda na dolgih poteh. Viri povedo, kaj to pomeni doma: več kot dve uri gibanja na dan, veliko miselnega dela, močan pastirski nagon (lahko grize za pete otrok, ki tečejo), do tujcev je zadržan. Prav to pokaže 12 tednov PetPrepa — ali družina zmore takšen tempo.
**Kako povedati** — starši: »Avstralski govedar je zvest, zelo pameten in zelo aktiven pes. Potrebuje več kot dve uri gibanja na dan in naloge za glavo. V PetPrepu boste videli, ali otrok zmore 12.000 korakov na dan 12 tednov zapored.« · otroci: »Avstralski govedar je mali pastir iz Avstralije — rad teče in se uči novih trikov. Vsak dan greš z njim na dolg sprehod, enkrat na teden ga počešeš.« · vlagatelji / partnerji: »Devetinštirideseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili; nova država izvora (Avstralija) brez ročnega dela.«

## Odprto za Davida

- **Potrebe brez mehanike (needs-david):** redno čiščenje ušes, zlasti ob kopanju, in občasno kopanje (PetMD S439) → **M5-R20**; redno striženje krempljev (PetMD S439) → **M5-R19**.
- **Česanje:** RKC in PDSA »enkrat na teden« (zgrajeno 1×); PetMD S439 »will benefit from daily brushing« — če želite več, odločite vi.
- **Življenjska doba:** mediana McMillan 2024 (14,0 leta) temelji na majhnem vzorcu (117 psov, 50 umrlih); starejši od 126 mesecev.
- **Ime:** »avstralski govedar« ni preverjeno pri KZS (vir le Siol.net, tier C).
- **Spletna stran:** nova država izvora Avstralija (AU) — besede Claude; ključi zdravja že obstajajo.
