# Mehiški goli pes (Xoloitzcuintli)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-123 · 2026-11-18

| | |
|---|---|
| Ključ / slug | `xoloitzcuintli` / `xoloitzcuintli` (SL `mehiski-goli-pes`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1140 — v izbirniku za angleškim hrtom |
| Vir podatkov | `docs/research/dog-data/data.json` → `xoloitzcuintli` (viri S923–S933; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: ni preverjen (stran RKC ni bila dosegljiva) |
| Izvor | FCI št. 234, skupina 5 (špici in primitivni tipi), sekcija 6 (primitivni tip), Mehika (S923 / S924) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 119. mesto »Xoloitzcuintli« (S174, prebrano 2026-11-18) |
| Različice | tri velikosti (FCI: mini 25–35 cm, srednja 35–45 cm, standardna 46–60 cm; AKC / WebMD: toy, miniature, standard) in dve dlaki (gola, dlakava). Igra je **en pes** s celim razponom teže; portret in AI videz kažeta **standardno golo** različico. |
| PR-ji | pet-prep #1347 (pasma, združen `bd4a000`) · portret pet-prep (zagon `breed-portraits.yml` 44213117263, sprejet v prvem poskusu) · barvne različice pet-prep · spletna stran: pet-prep-website `feat/register-xoloitzcuintli` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-18 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **20 min/dan → 2.000 korakov**; starejši 15 min → 1.500; mladiček 10 min × starost do 20 (že ob prihodu pri 2 mesecih) | strani RKC in PDSA nista bili dosegljivi → najmočnejši vir s številom: PetMD S926 »They need about 20 minutes of exercise every day« (»about N« → N). VCA in WebMD navajata le »zmerno« gibanje; Purina UK (S932, vir C) za srednjo in standardno velikost »about an hour« — le alternativa, glej Odprto | #1347 |
| 2026-11-18 | pravilo runbooka + **predlog Claude** (čaka Davida) | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **117**; prihod 2 / 9 / 36 / 117 — starejša meja in prihod starejšega sta `verified: false` | AAHA S11; McMillan 2024, VetCompass in RKC niso bili dosegljivi → najbližja vrstica pravila: 0,75 × spodnja meja PetMD **13 let** (S926 »They live a long life of 13–18 years«; WebMD S928 13–18 let) = 117 mesecev (precedens Toy Fox Terrier) | #1347 |
| 2026-11-18 | pravilo runbooka | učenje **1,0×** | pasma ni na Corenovi lestvici (S35) → hitrost mešančka | #1347 |
| 2026-11-18 | pravilo runbooka | teža **4,5–24,9 kg** (vse tri velikosti: 10–55 lb), višina 25–60 cm (vse tri velikosti FCI), rast do 12–15 mesecev (standardna velikost); hrana, voda in čiščenje kot pri border collieju | WebMD S928 »Toy … 10 and 15 pounds«, »Miniature … 15 and 30 pounds«, »Standard — between 18 and 23 inches tall and between 30 and 55 pounds« (FCI in UKC teže ne navajata; VCA 10–50 lb); FCI S924 višine po različicah; PetMD S10 (razred »Medium«) | #1347 |
| 2026-11-18 | pravilo runbooka (M5-R13) | **brez česanja** — gola pasma | PetMD S926 »Wipe them down with a washcloth to keep their skin clean.«; noben vir ne navaja pogostosti česanja | #1347 |
| 2026-11-18 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S926 »It's also important to socialize your Xolo puppy so they don't grow up suspicious of new people.« | #1347 |
| 2026-11-18 | pravila »Breed care tasks« (David 2026-10-24, M5-R15) | **ščetkanje zob 2× na teden** (otrokova mini igra) | WebMD S928 »Xolos need their teeth brushed two to three times a week,« (razpon → spodnja meja). Kremplji (Dogs Australia »The nails should be trimmed.«), ušesa (PetMD »after every bath«), kopanje (VCA »the odd bath«) → brez pogostosti, brez naloge | #1347 |
| 2026-11-18 | pravilo runbooka | brez opozorila po obroku in brez poletnega opozorila za sprehod | noben vir ne navaja premora po obroku; ni ploskoobrazna pasma | #1347 |
| 2026-11-18 | pravilo runbooka | oznake: primeren za **»družinsko življenje«** in **»družino z otroki«** (z opozorilom: otroke vedno nadzorujte); upoštevajte **»gola koža — potrebuje zaščito pred soncem«**, **»tanka dlaka — občutljiv na mraz«** in **»močan lovski nagon — lahko lovi manjše živali«** | WebMD S928 »can be a good family dog for families with children.«; PetMD S926 »… can be great friends to kids who know how to properly interact with pets.« / »Interactions between children and dogs should always be supervised.«; PetMD »Lather them in doggy sunscreen before they go outside to prevent sunburns.«; VCA S927 »Best suited to warm climates, they will need a sweater in cooler temperatures.«; PetMD »These dogs also have a high prey drive …« | #1347 |
| 2026-11-18 | pravilo runbooka | **brez** `small_children` (le z nadzorom), `apartment` (ključnih podatkov RKC ni bilo), `other_pets` (lovski nagon), `low_shedding` / `sheds`, `needs_company`; nikoli »hypoallergenic« | S926–S928 | #1347 |
| 2026-11-18 | pravilo runbooka (§3, dobrobit) | ni pasma z oznako dobrobiti → brez opozorilnega čipa; **v aplikaciji da, v marketingu da** — nikoli opečen ali premražen, nikoli z malimi živalmi brez nadzora, nikoli kot modni dodatek ali »hipoalergen« | S92 (le ploskoobrazne pasme) | #1347 |
| 2026-11-18 | pravilo runbooka (§3) | barve za AI in izbirnik (barva kože): **črna** (privzeto za portret), skrilasto siva, rdečkasta, jetrno rjava, bronasta, svetlo zlata (»blonde«) — imenovane barve standarda; »blackish grey« in »dark grey« izpuščeni (vizualno med črno in skrilasto sivo); nikoli merle, bela, tigrasta, črno-rjava ali albino; naravni rep, neprirezana ušesa | FCI (kopija KUSA S924) »The range includes black, blackish grey, slate grey, dark grey, reddish, liver, bronze or blonde.«; uteži brez vira → `verified: false` | #1347 |
| 2026-11-18 | pravilo runbooka (§2.3) | seznam za vzreditelja: **ni preverjen** (`not_checked`) — razdelek RKC o testih ni bil dosegljiv | runbook: brez vnosa → `not_checked` | #1347 |
| 2026-11-18 | pravilo runbooka | zdravstveni ključi registra: `bare_skin_sun_cold`, `kneecap_luxation`; brez odstotkov, brez novega ključa (manjkajoči zobje gole različice ostanejo v data.json) | VCA S927, PetMD S926 | #1347 |
| 2026-11-18 | predlog Claude, David lahko spremeni | EN »Xoloitzcuintli«, SL »Mehiški goli pes«; iskanje tudi »xolo«, »xoloitzcuintle«, »mehiski goli pes«, »mexican hairless«, »sholo« | strani KZS nisem dosegel | #1347 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva.«, iskanje »mehiški goli pes«, »xolo« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-123 | 2026-11-18 |
| Pravila po starosti (2.000 korakov od prihoda, odrasel 2.000, starejši 1.500 od 117. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 (starejša meja čaka Davida) | 💶 izziv | M5-R10-123 | 2026-11-18 |
| »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R17 | 2026-11-18 |
| Ščetkanje zob 2× na teden (otrok) | 🛠 | 💶 izziv | M5-R15 | 2026-11-18 |
| Barve (izbirnik: 6 barv kože) | 🛠 | 💶 izziv | M5-R21 | 2026-11-18 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), standardna gola različica, črna koža, pokončna ušesa, naravni rep | — | M5-R11-04 | 2026-11-18 |
| Stran na petprep.si (`/animals/dogs/xoloitzcuintli`, `/sl/zivali/psi/mehiski-goli-pes`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal mehiškega golega psa (xoloitzcuintli) — eno najstarejših pasem na svetu, mehiškega narodnega psa, 119. najbolj priljubljeno pasmo po AKC 2024 in 113. pasmo psa v aplikaciji. 11 novih virov (S923–S933). Odrasel pes v igri potrebuje 2.000 korakov na dan (PetMD: okoli 20 minut gibanja), ščetkanje zob dvakrat na teden — in nobenega česanja, ker nima dlake.
**Zakaj je pomembno:** Gola koža ni »brez dela«: po PetMD potrebuje pasjo kremo za sončenje pred vsakim izhodom, po VCA pa pulover, ko je hladno. Ima tudi močan lovski nagon. Pasma pokaže otroku, da je skrb za psa lahko čisto drugačna od česanja — in da so potrebe različne od pasme do pasme.
**Kako povedati** — starši: »Mehiški goli pes je miren, pameten družinski pes, ki ne potrebuje veliko gibanja — a njegova gola koža potrebuje zaščito pred soncem in mrazom. V PetPrepu boste videli, ali vaš otrok zmore skrb 12 tednov zapored.« · otroci: »Xolo nima dlake! Ne rabiš ga česati, ampak mu vsak teden dvakrat umiješ zobe in greš z njim na kratek sprehod.« · vlagatelji / partnerji: »113. pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal z viri — ko britanski viri (RKC, PDSA) niso bili dosegljivi, je po pravilu uporabil standard FCI in veterinarske strani (PetMD, VCA, WebMD), negotovo starejšo mejo pa pustil Davidu v potrditev.«

## Portret

Zagon `breed-portraits.yml` 44213117263 (koda `bd4a000`, brez produkcijske namestitve), Nano Banana Pro, 0,15 $. Pregled Claude 2026-11-18: realistična fotografija enega odraslega psa standardne gole različice, enakomerna črna koža, malo kratke dlake na glavi, velika pokončna ušesa, tanek naravni rep, sivo studijsko ozadje, brez besedila in rekvizitov — **sprejet v prvem poskusu**.

## Odprto za Davida

- **Starejše obdobje (117. mesec):** predlog Claude — 0,75 × spodnja meja PetMD 13 let. McMillan 2024 / VetCompass / RKC niso bili dosegljivi; vrstica je `verified: false`. Potrdite ali določite drugo mejo (npr. iz tabele Dogs Trust S123, če ima vrstico za pasmo).
- **Gibanje 20 minut:** PetMD (S926) navaja »about 20 minutes« za pasmo; Purina UK (S932, vir C) za srednjo in standardno velikost »about an hour«. Igra je en pes za vse tri velikosti — odločite, ali naj standardna velikost dobi več.
- **Tri velikosti v enem psu:** teža 4,5–24,9 kg in višina 25–60 cm pokrivata vse različice; portret kaže standardno golo. Če želite izbiro velikosti (mini / srednja / standardna) ali dlakavo različico, je to nova naloga (ROADMAP — David).
- **Potreba brez mehanike (potrebna nova naloga v ROADMAP):** zaščita gole kože pred soncem (PetMD S926 »Lather them in doggy sunscreen before they go outside …«) in pulover v mrazu (VCA S927) — igra tega nima (enako kot pri kitajskem golem psu in italijanskem hrtu). Do takrat le čipa `sun_protection` + `cold_sensitive` in besedilo v registru.
- **Stran RKC:** ključni podatki RKC (velikost doma, izpadanje dlake, Breed Watch) in testi za vzreditelje niso bili dosegljivi (preusmeritev na royalkennelclub.com) → seznam za vzreditelja je »ni preverjen«. WebMD (S928) omenja preglede srca, kolen, kolkov in oči — ob ročnem pregledu strani RKC dopolnite.
- **Coren:** tabela S35 ta zagon ni bila dosegljiva; pasma po zapisu prejšnjih zagonov na lestvici ni → učenje 1,0×.
- **Ime:** »Mehiški goli pes« ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
