# Tibetanski terier (Tibetan Terrier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-121 · 2026-11-17

| | |
|---|---|
| Ključ / slug | `tibetan_terrier` / `tibetan-terrier` (SL `tibetanski-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1120 — v izbirniku za manchesterskim terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `tibetan_terrier` (viri S904–S911; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: ni preverjen (stran RKC ni bila dosegljiva) |
| Izvor | FCI št. 209, skupina 9, sekcija 5 (tibetanske pasme), Tibet (Kitajska), pokroviteljstvo Velika Britanija (S904) — po FCI pes družabnik, ne terier po tipu |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 117. mesto »Tibetan Terrier« (S174, prebrano 2026-11-17; 116. ameriški eskimski pes je blokiran) |
| PR-ji | pet-prep #1292 (pasma) · portret pet-prep (sledi) · barvne različice (sledi) · spletna stran: pet-prep-website `feat/register-tibetan-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-17 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | stran RKC ni bila dosegljiva → po pravilu PDSA: S905 »Tibetan Terriers need around an hour of exercise every day …« (»around N« → N; ključni podatek »1 hour« se ujema); PetMD S907 »at least an hour of exercise daily« enako | #1292 |
| 2026-11-17 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108**; prihod 2 / 9 / 36 / 108 | AAHA S11; starejši = 0,75 × spodnja meja PDSA **12 let** (»Over 12 years«, S905; Dogs Trust S908 »typically 12+ years«) = 108 mesecev. Tabela McMillan 2024 (S123) ni bila dosegljiva, članek pasmo omenja le kot oznako na sliki, stran RKC tudi ni bila dosegljiva → PDSA (precedens manchesterski terier). Ni izbrano: 84 (Dogs Trust »> 7 let«) | #1292 |
| 2026-11-17 | pravilo runbooka | učenje **0,7×** | Coren 62. mesto »Tibetan Terrier« (deljeno z japonskim činom), skupina »Fair« (55–69) (S35) | #1292 |
| 2026-11-17 | pravilo runbooka | teža **8,25–13,5 kg** (oba spola — standard CKC), višina 36–41 cm (PDSA; FCI za pse 36–41 cm, psice »nekoliko manjše«), rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | CKC S909 »Weight: 8.25 to 13.5 kg (18 to 30 lb)« (FCI teže ne navaja; UKC S910 »approximately 18 to 30 pounds«; PDSA S905 9–11 kg); FCI S904 »Height at the withers: Males: 36 - 41 cm; females slightly smaller.«; PetMD S10 (razred »Medium«) | #1292 |
| 2026-11-17 | pravilo runbooka (M5-R13) | česanje **vsak dan**; skuštrana dlaka po 4 zamujenih | PDSA S905 »Grooming is a daily requirement to keep their coats in tip-top condition.« (»daily« → 7, precedens lhasa apso); PetMD S907 »once or twice a week« / »several times a week« manj (alternativa) | #1292 |
| 2026-11-17 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S907 »Enrolling your Tibetan Terrier puppy in socialization classes before they're 16 weeks old« | #1292 |
| 2026-11-17 | pravila »Breed care tasks« (David 2026-10-24, M5-R15) | **ščetkanje zob vsak dan** (otrokova mini igra) | WebMD S906 »You should always trim your dog's nails as needed and brush their teeth on a daily basis.« Kremplji (»as needed«), kopanje (PetMD »when their coat becomes dirty or smelly«), ušesa (PetMD po kopanju / plavanju) in frizer (WebMD, PetMD) brez pogostosti → brez naloge | #1292 |
| 2026-11-17 | pravilo runbooka | brez opozorila po obroku in brez poletnega opozorila za sprehod | noben vir ne navaja premora po obroku; ni ploskoobrazna pasma | #1292 |
| 2026-11-17 | pravilo runbooka | oznake: primeren za **»aktivno družino«** in **»družinsko življenje«**; upoštevajte **»potrebuje česanje večkrat na teden«** in **»rad ima družbo«** | PDSA S905 (okoli ure gibanja, »will happily join in with family games«; »will make good family pets«; »Grooming is a daily requirement«); PetMD S907 »Tibetan Terriers can develop separation anxiety if left alone for long periods« (`needs_company` — v deljenem testu `BreedSuitabilityTest` dodan na seznam izjem, kot pri drugih pasmah s tem čipom) | #1292 |
| 2026-11-17 | pravilo runbooka | **brez** `children` / `small_children` (PetMD »good with young children … when introductions are supervised« proti WebMD »Some individuals can struggle with other dogs and young children« — viri si nasprotujejo), `low_shedding` / `sheds` (Dogs Trust »shed very little« proti WebMD »a moderate amount«), `apartment` (ključnih podatkov RKC ni bilo), `chases_small_pets` (ni vira o lovskem nagonu), `cold_sensitive` (PetMD: dobro prenaša hladnejše vreme); nikoli »hypoallergenic« | S905–S908 | #1292 |
| 2026-11-17 | pravilo runbooka (§3, dobrobit) | nobeno veterinarsko združenje pasme ne navaja zaradi težav s telesno zgradbo → brez opozorilnega čipa; **v aplikaciji da, v marketingu da** (nikoli kot »hipoalergen« ali »brez nege«); kategorija RKC Breed Watch ni preverjena | S92 (le ploskoobrazne pasme); stran RKC ni bila dosegljiva | #1292 |
| 2026-11-17 | pravilo runbooka (§3) | barve za AI in izbirnik: **zlat** (privzeto za portret), bel, kremast, siv ali dimast, črn, dvobarven, trobarven — imenovane barve standarda; nikoli čokoladna, jetrna ali merle; naravna, nepostrižena dlaka | FCI S904 »White, golden, cream, grey or smoke, black, parti-colour and tricolours« / »in fact any colour except chocolate, liver or merle permissible.«; uteži brez vira → `verified: false` | #1292 |
| 2026-11-17 | pravilo runbooka (§2.3) | seznam za vzreditelja: **ni preverjen** (`not_checked`) — razdelek RKC o testih ni bil dosegljiv | runbook: brez vnosa → `not_checked` | #1292 |
| 2026-11-17 | pravilo runbooka | zdravstveni ključi registra: `kneecap_luxation`, `primary_lens_luxation`, `pra_inherited_blindness`, `extra_eyelashes`, `cataracts`, `hip_dysplasia`, `diabetes_mellitus`; brez odstotkov, brez novega ključa (NCL, atopija, srčni šumi v starosti in rak ostanejo v data.json) | PDSA S905, PetMD S907, WebMD S906 | #1292 |
| 2026-11-17 | predlog Claude, David lahko spremeni | EN »Tibetan Terrier«, SL »Tibetanski terier«; iskanje tudi »tibetan terier«, »tibetan« | strani KZS nisem dosegel | #1292 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »tibetanski terier«, »tibetan« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-121 | 2026-11-17 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 108. meseca; 4 → 3 → 2 obroka; učenje 0,7×) | 🛠 | 💶 izziv | M5-R10-121 | 2026-11-17 |
| Česanje vsak dan, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-17 |
| Ščetkanje zob vsak dan (otrok) | 🛠 | 💶 izziv | M5-R15 | 2026-11-17 |
| Barve (izbirnik: 7 barv) | 🛠 | 💶 izziv | M5-R21 | 2026-11-17 |
| AI portret v registru | 🗓 sledi po združitvi | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/tibetan-terrier`, `/sl/zivali/psi/tibetanski-terier`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal tibetanskega terierja — srednje velikega, čvrstega psa družabnika z dolgo dvojno dlako iz Tibeta, 117. najbolj priljubljeno pasmo po AKC 2024 in 111. pasmo psa v aplikaciji (ameriški eskimski pes pred njim je ostal blokiran, ker za gibanje ni vira s številom). 8 novih virov (S904–S911). Odrasel pes v igri potrebuje 6.000 korakov na dan (PDSA: okoli ene ure gibanja), česanje vsak dan in ščetkanje zob vsak dan.
**Zakaj je pomembno:** Tibetanski terier je nežen, prijazen in družinski pes (PDSA), a njegova dolga dlaka potrebuje vsakodnevno nego (PDSA) in če je dolgo sam, lahko razvije ločitveno tesnobo (PetMD). Ime vara: po FCI ni terier, ampak pes družabnik.
**Kako povedati** — starši: »Tibetanski terier je čudovit družinski pes, a z dolgo dlako: vsak dan česanje, vsak dan ščetkanje zob in uro gibanja. Ne mara biti dolgo sam. V PetPrepu boste videli, ali vaš otrok zmore ta vsakodnevni ritem.« · otroci: »Tibetanski terier ima dolgo, mehko dlako, ki ti pade čez oči — vsak dan ga počešeš, mu umiješ zobe in greš z njim na sprehod.« · vlagatelji / partnerji: »111. pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal z viri — ko glavni vir (RKC) ni bil dosegljiv, je po pravilu uporabil PDSA in standard CKC.«

## Odprto za Davida

- **Življenjska doba / starejše obdobje:** uporabljena je spodnja meja PDSA 12 let → starejši od 108. meseca. PetMD in WebMD navajata 15–16 let; tabela Dogs Trust McMillan 2024 (S123) ni bila dosegljiva — preverite vrstico »Tibetan Terrier«.
- **Stran RKC:** ključni podatki RKC (velikost doma, izpadanje dlake, Breed Watch) in testi za vzreditelje niso bili dosegljivi (preusmeritev na royalkennelclub.com) → seznam za vzreditelja je »ni preverjen«; ob ročnem pregledu dopolnite.
- **Česanje:** PDSA »vsak dan« → 7; PetMD »enkrat ali dvakrat na teden« / »večkrat na teden«. Povejte, če naj bo manj (kot pri kavalirju, 4).
- **Otroci:** PetMD (pod nadzorom dobro) in WebMD (nekateri imajo težave z majhnimi otroki) si nasprotujeta → brez čipa »družino z otroki«; odločite, če želite drugače.
- **Standard FCI 2026:** stran FCI navaja veljavni standard z 9/8/2026, prebral sem besedilo 2017 (dev.fci.be) in kopijo KUSA »01/01/2026« — višina in barve sta enaki.
- **Ime:** »Tibetanski terier« ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
