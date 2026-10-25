# Akita (Ameriška akita)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-63 · 2026-10-24

| | |
|---|---|
| Ključ / slug | `akita` / `akita` (SL `ameriska-akita`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 590 — v izbirniku za bloodhoundom |
| Vir podatkov | `docs/research/dog-data/data.json` → `akita` (viri S506–S512; ponovno S8, S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: ni bilo mogoče prebrati (RKC stran nedosegljiva) |
| Izvor | FCI št. 344 »American Akita«, skupina 5, sekcija 5 (azijski špici in sorodne pasme), država izvora Japonska, razvita v ZDA (S506) |
| Katera akita | AKC »Akita« = ameriški tip (FCI 344 »American Akita«, RKC »Akita«) — **ne** japonska akita inu (FCI 255, ločena pasma; S512) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 59. mesto »Akita« (S174, prebrano 2026-10-24) |
| PR-ji | pet-prep #378 (pasma, združen `a9a7e8f`) · portret: #383 (zagon 40174556521) · spletna stran: pet-prep-website `feat/register-akita-cardigan-gwp` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-24 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | PDSA S508 »Minimum exercise (per day): 2 hours« (»minimum N« → N); stran RKC (prednostni vir) zagon ni mogel odpreti → PDSA | #378 |
| 2026-10-24 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36 mesecev; prihod 2 / 9 / 36 | AAHA S11 (kot pri vseh psih) | #378 |
| 2026-10-24 | **predlog Claude — čaka Davida** (`verified=false`) | starejši od **89 meseca** (tudi prihod starejšega psa) | 0,75 × mediana 9 let in 11 mesecev iz ankete Kennel Club / BSAVA 2004 (S511, 28 smrti). Pravilo runbooka ne da vrednosti: tabele McMillan 2024 (Dogs Trust S123) zagon ni mogel odpreti, mediane VetCompass ni, strani RKC ni bilo mogoče prebrati, PDSA »Under 10 years« nima spodnje meje → najbližja vrstica (precedens bernski planšar, bloodhound). Alternative: 90 (PetMD spodnja meja 10 let, S510); 103 (McMillan 2024 »11.4 years«, le prek Wikipedije S512, tier C); 84 (Dogs Trust > 7 let, S14) | #378 |
| 2026-10-24 | pravilo runbooka | učenje **1,0×** (Coren rang 54, skupina »Average«, deljeno z bostonskim terierjem) | S35 | #378 |
| 2026-10-24 | pravilo runbooka | teža 31–59 kg (en razpon za oba spola), višina psi 66–71 cm, psice 61–66 cm, rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S508 »Average weight: 31-59 kg« (standarda FCI S507 in AKC S509 teže ne navajata; PetMD S510 70–130 lb se ujema); FCI S507 višina; PetMD S10 (razred »Large«) | #378 |
| 2026-10-24 | pravilo runbooka (M5-R13) | česanje **1× na teden** | PDSA S508 »at minimum a weekly brush to help get rid of dead hairs.« → 1 (PDSA pred PetMD); PetMD S510 »Daily brushing is best …« → 7 je alternativa za Davida | #378 |
| 2026-10-24 | David 2026-10-24 (M5-R18, pravilo »Breed care tasks«) | **opomnik staršu za pasjega frizerja** vsakih 12 programskih tednov (nikoli v oceni) | PDSA S508 »You might like to get your Akita professionally groomed every three months or so.« (»every three months« → 84 dni) | #378 |
| 2026-10-24 | David 2026-10-24 (pravilo »Breed care tasks«) | **brez** naloge čiščenja ušes | PetMD S510 »Akitas can also benefit from occasional ear cleanings.« — brez pogostosti → brez naloge | #378 |
| 2026-10-24 | David 2026-10-12 (M5-R16) | **opozorilo po obroku** (60 minut »počakaj s sprehodom«, koraki se štejejo) | PDSA S508 »Remember to leave a gap after eating and before exercising.« | #378 |
| 2026-10-24 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S508 »your dog will need to be well socialised from a young age« | #378 |
| 2026-10-24 | pravilo runbooka | oznake: aktivno družino (`active_family`); upoštevajte: veliko gibanja (`long_daily_exercise`), izkušen lastnik (`experienced_owner`), ni za dom z majhnimi otroki (`not_for_small_children`), rad ima družbo (`needs_company`), izpada mu dlaka (`sheds`), močan lovski nagon (`chases_small_pets`, zadnja) | PDSA S508 »Minimum exercise (per day): 2 hours«, »… would do best with experienced owners.«, »we wouldn’t recommend an Akita if you have young children«, »we wouldn’t recommend leaving them alone as many don’t cope well with this«, »… so Akitas shed a lot!«, »… and so have a high prey drive.« | #378 |
| 2026-10-24 | pravilo runbooka | **brez** oznak `family_pet`, `children`, `small_children`, `first_time_owner`, `apartment`, `large_home`, `other_pets`, `frequent_grooming`, `shorter_lifespan`, `drools`; brez nove oznake | PDSA »loyal family pets« skupaj z »ne z majhnimi otroki« in »izkušeni lastniki«; brez strani RKC ni »Size of home«; česanje PDSA tedensko proti PetMD dnevno (nasprotje); življenjska doba PDSA »Under 10 years« proti PetMD 10–14 let (nasprotje → brez oznake) | #378 |
| 2026-10-24 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | ni sploščenega obraza; kategorije Breed Watch zagon ni mogel prebrati (stran RKC nedosegljiva) | #378 |
| 2026-10-24 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli z majhnimi otroki ali ob manjših živalih, nikoli kot pes za začetnike ali čuvaj, nikoli z obljubo o zdravju, nikoli zamenjan z japonsko akito inu | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #378 |
| 2026-10-24 | pravilo runbooka (§3) | barve za AI in izbirnik: **rdečkasto plav s črno masko** (portret), lisast, tigrast, bel; nikoli »merle« ali jetrno rjav; naravno pokončni uhlji in zavit rep | FCI S507 »Any colour like red, fawn, white, etc; or even pinto and brindle.«, izključujoče »Merle marking pattern; Liver color.« Uteži barv brez vira → `verified: false`; barva portreta je predlog Claude | #378 |
| 2026-10-24 | predlog Claude, David lahko spremeni | ime SL »Ameriška akita« (slug `ameriska-akita`); iskalni ključi »akita«, »american akita«, »ameriška akita«, »ameriska akita«, »great japanese dog« (nikoli »akita inu«) | slovenskega vira (KZS) za ime ni bilo mogoče preveriti; FCI ime pasme je »American Akita« | #378 |
| 2026-10-24 | pravilo runbooka (§2.3) | seznam za vzreditelja: `not_found` (prikaže se splošni pasji seznam) | stran RKC z zdravstvenimi testi ni bila dosegljiva; PDSA in PetMD testov ne naštevata | #378 |
| 2026-10-24 | pravilo runbooka | zdravstveni ključi registra (obstoječi): `hip_dysplasia_arthritis`, `bloat_gdv`, `eye_problems_pra_cataracts_entropion`, `autoimmune_conditions`, `hypothyroidism`; brez odstotkov | PDSA S508, PetMD S510 | #378 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »akita«, »ameriška akita«, »american akita« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-63 | 2026-10-24 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 89 meseca — predlog; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-63 | 2026-10-24 |
| Česanje 1× na teden (rutina »Počeši«), »Nova izkušnja« za mladička, opozorilo »počakaj s sprehodom« po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 | 2026-10-24 |
| Opomnik staršu »pasji frizer« vsakih 12 tednov (ne šteje v oceno) | 🛠 | 💶 izziv | M5-R18 | 2026-10-24 |
| Barva (izbirnik barve: rdečkasto plav, lisast, tigrast, bel) | 🛠 | 💶 izziv | M5-R21 | 2026-10-24 |
| AI portret v registru (rdeče plave barve s črno masko, sprejet v prvem poskusu, zagon 40174556521, PR #383) | ✅ | — | M5-R11-04 | 2026-10-25 |
| Stran na petprep.si (`/animals/dogs/akita`, `/sl/zivali/psi/ameriska-akita`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije (le s portretom) | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal akito (ameriški tip) — 59. najbolj priljubljeno pasmo po AKC 2024 in 59. plačljivo pasmo psa. 7 novih virov (S506–S512). Odrasel pes v igri potrebuje 12.000 korakov na dan (PDSA: vsaj dve uri gibanja), starejši 9.000; uči se kot mešanec (Coren: 54. mesto, ×1,0). Tedensko česanje, socializacija mladička, opozorilo »počakaj s sprehodom« po obroku (nevarnost zasuka želodca) in opomnik staršu za pasjega frizerja vsake tri mesece so del izziva od prvega dne.
**Zakaj je pomembno:** Akita je zvest, samostojen in zelo velik pes z močnim lovskim nagonom, ki se mu ne godi, če je pogosto sam, in ki ga viri priporočajo le izkušenim lastnikom — ne družinam z majhnimi otroki in ne domu z manjšimi živalmi. Dve uri gibanja na dan in veliko družbe sta velika obveza — PetPrep pokaže, ali jo družina zdrži 12 tednov.
**Kako povedati** — starši: »Akita potrebuje vsaj dve uri gibanja na dan, veliko družbe in izkušenega lastnika. V PetPrepu boste videli, ali družina zmore 12.000 korakov na dan 12 tednov zapored.« · otroci: »Akita je velik in zelo zvest pes. Z njim greš vsak dan dolgo ven, po kosilu pa najprej malo počakaš.« · vlagatelji / partnerji: »Devetinpetdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Meja starejšega psa (89 mesecev, `verified=false`):** predlog iz mediane ankete Kennel Club / BSAVA 2004 (9 let in 11 mesecev, S511, le 28 smrti). Če potrdite PetMD spodnjo mejo (10 let) → 90; McMillan 2024 (11,4 leta, zdaj le prek Wikipedije) → 103 (podatkovna migracija ali urejanje v Filamentu).
- **Življenjska doba v registru:** PDSA »Under 10 years« in PetMD 10–14 let se ne ujemata; zato brez oznake `shorter_lifespan` — želite oznako?
- **Česanje:** PDSA »vsaj enkrat na teden« (izbrano, 1×) proti PetMD »najbolje vsak dan« (7×) — potrdite 1× na teden ali izberite drugo.
- **Stran RKC (Breed Watch, zdravstveni testi, »Size of home«):** zagon je ni mogel odpreti; brez oznake dobrobiti in brez testov za vzreditelja. Ob naslednjem dostopu preverite kategorijo Breed Watch.
- **Družinski pes:** PDSA »incredibly loyal family pets« ob odsvetovanju majhnih otrok in priporočilu izkušenih lastnikov — brez oznake `family_pet` (precedens bloodhound / veliki šnavcer); potrdite.
- **Ime:** »Ameriška akita« ni preverjeno pri KZS; EN ime je »Akita« (AKC / RKC).
- **Barva portreta:** standard dovoli vse barve; rdečkasto plava s črno masko je predlog Claude.
- **Portret:** prvi poskus (zagon 40146982212) zavrnjen zaradi `composer.lock` iz M4-05, ki še ni bil v produkciji; po produkcijski namestitvi 2026-10-25 narejen v zagonu 40174556521 (koda `ff0ed6e`) in sprejet; PR #383.
