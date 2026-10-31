# Basenji (basenji)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-84 · 2026-10-31

| | |
|---|---|
| Ključ / slug | `basenji` / `basenji` (SL `basenji`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 780 — v izbirniku za aljaškim malamutom |
| Vir podatkov | `docs/research/dog-data/data.json` → `basenji` (viri S642–S646; ponovno S8, S10, S11, S14, S24, S35, S45, S54, S123, S174); seznam za vzreditelja: Basenji Club of America (K37) |
| Izvor | FCI št. 43, skupina 5, sekcija 6 (primitivni tip), Srednja Afrika (pokroviteljstvo Velika Britanija; S642, S643) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 80. mesto (S174, prebrano 2026-10-31) |
| PR-ji | pet-prep #468 (pasma, združen `5386c7c`) · portret: PR (ta, zagon 41179845335) · spletna stran: pet-prep-website `feat/register-basenji` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-31 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | PDSA S644 ključni podatek »Minimum exercise (per day): 1 hour« (»minimum N« → N); besedilo PDSA »around 60-90 minutes’ exercise per day« → spodnja meja 60 (isto); stran RKC ni bila dosegljiva | #468 |
| 2026-10-31 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 (za vse pse) | #468 |
| 2026-10-31 | **predlog Claude — čaka Davida** | starejši od **108. meseca** (tudi prihod starejšega 108; `verified: false`) | 0,75 × spodnja meja PDSA »Over 12 years« (S644) = 9 let. Tabela McMillan 2024 (Dogs Trust S123) in stran RKC nista bili dosegljivi, mediane VetCompass ni → najbližja vrstica pravila (precedens samojed, aljaški malamut). WebMD S646 »between 13 and 14 years« je razpon, ne mediana. Ni izbrano: 84 (Dogs Trust »> 7 let«) | #468 |
| 2026-10-31 | pravilo runbooka | učenje **0,5×** | Coren 78. mesto, razred »Lowest« (S35) — Coren meri ubogljivost na ukaz, ne »pameti« | #468 |
| 2026-10-31 | pravilo runbooka | teža **9–11 kg**, višina 40–43 cm (PDSA, za oba spola), rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S644; FCI S643 idealno samci 43 cm / 11 kg, samice 40 cm / 9,5 kg; AKC S645 17 / 16 in, 24 / 22 lb; PetMD S10 (razred »Small«) | #468 |
| 2026-10-31 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | PDSA S644 »brushing once a week is usually enough to remove any dead hair.«; WebMD S646 »once a week« se ujema | #468 |
| 2026-10-31 | pravila »Breed care tasks« (David 2026-10-24, M5-R15 / R20) | otrokovi mini igri **zobje 2× na teden** in **ušesa 1× na teden** | WebMD S646 »Brush their teeth often — daily is best, but at least twice a week« (navedeni minimum → 2; precedens Russell terier »at least three times a week« → 3); »Check their ears for debris and clean them on a weekly basis«. Kremplji »as needed«, kopanje le, ko se umaže → brez naloge | #468 |
| 2026-10-31 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) — **Davidova splošna vrstica** brez citata pasme | WebMD S646 »Early training is necessary to keep them sociable and obedient.« govori o šolanju, ne o socializaciji → brez lastnega citata | #468 |
| 2026-10-31 | pravilo runbooka (M5-R16) | **brez** opozorila po obroku | noben vir ne svetuje premora po obroku | #468 |
| 2026-10-31 | pravilo runbooka | oznake: upoštevajte »potrebuje miselne izzive« (`needs_mental_stimulation`) in »rad pobegne — potrebuje varno, visoko ograjo« (`secure_fencing`); **brez** oznake »primeren za« | PDSA S644 »require plenty of mental stimulation, and do well at canine sports«; WebMD S646 »These dogs love the outdoors but need very high fences or constant supervision.« | #468 |
| 2026-10-31 | pravilo runbooka | **brez** `chases_small_pets` | WebMD S646 »They’ll chase anything that looks or acts like prey.« opisuje lov, ne imenuje pa »high / strong prey drive« — pravilo (David 2026-10-12) zahteva dobesedno navedbo lovskega nagona; dejstvo ostane v besedilu registra | #468 |
| 2026-10-31 | pravilo runbooka | **brez** `family_pet`, `children`, `small_children`, `active_family`, `long_daily_exercise`, `needs_company`, `apartment`, `large_home`, `low_shedding`, `shorter_lifespan`; nikoli »hypoallergenic« | WebMD: »only moderately good with young children«; 60 min je pod mejo 2 ur; o samoti ni vira; ključnih podatkov RKC ni bilo mogoče prebrati; »Over 12 years« | #468 |
| 2026-10-31 | pravilo runbooka (§3) | **brez čipa dobrobiti**; marketing: **v aplikaciji da, v marketingu da** (nikoli obljuba zdravja; nikoli kot pes za majhne otroke, za dom z malimi živalmi ali za spuščanje s povodca ob cesti) | ni plosko-obrazna pasma (BVA S92 ne velja); kategorija Breed Watch neznana (stran RKC ni bila dosegljiva) | #468 |
| 2026-10-31 | pravilo runbooka (§3) | barve za AI in izbirnik: **rdeče-bel (portret)**, črno-bel, trobarven (črn, rjavkast, bel), tigrast z belo; vedno bele tačke, prsi in konica repa | FCI S643 »Pure black and white; red and white; black and tan, and white with melon pips and tan markings on muzzle and cheeks;« … »brindle : red background with black stripes«; AKC S645 »Chestnut red; pure black; tricolor …; or brindle«, »all with white feet, chest and tail tip.«; uteži brez vira → `verified: false` | #468 |
| 2026-10-31 | pravilo runbooka (§2.3) | seznam za vzreditelja: **druga shema** — DNA test za Fanconijev sindrom, DNA test PRA-BJ1, pregled oči, ščitnica, kolki in komolci | Basenji Club of America (K37) »If you are considering a basenji puppy, ask the breeder for these test results.«; nova ključa `dna_fanconi`, `dna_pra_bj1`, `thyroid_scheme` | #468 |
| 2026-10-31 | pravilo runbooka | zdravstveni ključi registra: `fanconi_syndrome` (nov), `pra_eye_disease`, `pyruvate_kinase_deficiency` (nov), `kneecap_luxation`, `bladder_stones`; brez odstotkov | PDSA S644 | #468 |
| 2026-10-31 | predlog Claude, David lahko spremeni | EN »Basenji«, SL »Basenji«; iskanje »basenji«, »basendži«, »basendzi«, »african barkless dog«, »barkless dog«, »congo dog«, »kongovski terier«, »pes, ki ne laja« | ime je v slovenščini enako; strani KZS nisem preverjal | #468 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »basenji«, »basendži« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-84 | 2026-10-31 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 108. meseca — predlog; 4 → 3 → 2 obroka; učenje 0,5×) | 🛠 | 💶 izziv | M5-R10-84 | 2026-10-31 |
| Česanje 1× na teden, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-31 |
| Mini igri zobje (2× na teden) in ušesa (1× na teden) | 🛠 | 💶 izziv | M5-R15 / M5-R20 | 2026-10-31 |
| Barva (izbirnik: 4 standardne barve) | 🛠 | 💶 izziv | M5-R21 | 2026-10-31 |
| AI portret v registru (rdeče-bel, sprejet v prvem poskusu, zagon 41179845335) | ✅ | — | M5-R11-04 | 2026-10-31 |
| Stran na petprep.si (`/animals/dogs/basenji`, `/sl/zivali/psi/basenji`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal basenjija — lahkega, elegantnega lovskega psa iz Srednje Afrike, ki ne laja, ampak »jodla«; 80. najbolj priljubljeno pasmo po AKC 2024 in 77. plačljivo pasmo psa. 5 novih virov (S642–S646). Odrasel pes v igri potrebuje 6.000 korakov na dan (PDSA: najmanj ena ura gibanja), česanje enkrat na teden, otrok pa mu dvakrat na teden umije zobe in enkrat na teden očisti ušesa.
**Zakaj je pomembno:** Basenji je majhen in čist (»kot mačka«), a samostojen in trmast: po Corenu je med najtežje vodljivimi pasmami, potrebuje miselne izzive in zelo visoko ograjo, ker rad pobegne in lovi vse, kar je videti kot plen. PetPrep pokaže, ali otrok zmore njegov ritem in potrpežljivo šolanje 12 tednov, preden pride pravi pes.
**Kako povedati** — starši: »Basenji po PDSA potrebuje vsaj uro gibanja na dan in veliko miselnih izzivov; je samostojen in šolanje je lahko izziv, zato potrebuje varno, visoko ograjo. V PetPrepu boste videli, ali vaš otrok zmore takšen ritem.« · otroci: »Basenji je pes, ki ne laja, ampak poje — vsak dan greš z njim na sprehod, enkrat na teden ga počešeš, dvakrat na teden mu umiješ zobe, v šoli pa moraš biti zelo potrpežljiv.« · vlagatelji / partnerji: »Sedeminsedemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Starejše obdobje (predlog 108):** mediane McMillan 2024 / VetCompass ni bilo mogoče prebrati (tabela Dogs Trust S123 in stran RKC sta bili za orodje nedosegljivi); uporabljena je spodnja meja PDSA »Over 12 years« (`verified: false`, `PROPOSED_R10_BASENJI_SENIOR`). Potrdite ali preverite vrstico »Basenji« v tabeli Dogs Trust.
- **Vir WebMD (S646):** stran je medicinsko pregledal zdravnik (MD), ne veterinar; uvrščena je kot B (kot S31). Na njej temeljita mini igri zobje 2× in ušesa 1× na teden ter oznaka »visoka ograja«. Če WebMD z zdravnikom kot recenzentom ne šteje za B, povejte — naloge in oznako odstranimo s podatkovno migracijo.
- **Zobje:** WebMD »daily is best, but at least twice a week« → vzet je navedeni minimum (2×). Če želite »vsak dan« (7×), povejte.
- **Lovski nagon:** WebMD »They’ll chase anything that looks or acts like prey« — po pravilu ni čipa `chases_small_pets` (ni besed »high / strong prey drive«). Če želite čip tudi za tako izjavo, je to sprememba pravila.
- **Ime:** »Basenji« je ime tudi v slovenščini; strani KZS nisem preverjal. Potrdite ali popravite.
- **Standard FCI:** stran FCI navaja nov uradni standard z dne 08/09/2026; prebran je angleški PDF iz leta 1999 (S643). Barve in velikosti se z AKC S645 ujemajo.
- **Ugotovitev za skupne dokumente (ne urejam jih):** nova država izvora »Srednja Afrika« (`AF-C` v `FCI_ORIGINS`), nova zdravstvena ključa `fanconi_syndrome`, `pyruvate_kinase_deficiency` in novi testi `dna_fanconi`, `dna_pra_bj1`, `thyroid_scheme` (besedila na spletni strani dodana v PR spletne strani).
- **Portret:** zagon 41179845335 (koda `5386c7c`, brez produkcijske namestitve, 0,15 $), sprejet v prvem poskusu — realistična fotografija odraslega psa, kostanjevo rdeč z belimi prsmi, tačkami in konico repa, gubice na čelu, pokončna ušesa, rep tesno zavit čez hrbet, svetlo siva studijska podlaga.
