# Manchesterski terier (Manchester Terrier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-119 · 2026-11-16

| | |
|---|---|
| Ključ / slug | `manchester_terrier` / `manchester-terrier` (SL `manchesterski-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1110 — v izbirniku za ostrodlakim foksterierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `manchester_terrier` (viri S888–S894; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: ni preverjen (stran RKC ni bila dosegljiva) |
| Izvor | FCI št. 71, skupina 3, sekcija 1 (veliki in srednji terierji), Velika Britanija (S888) — le standardna velikost; ni angleški toy terier (FCI 13) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 115. mesto »Manchester Terrier« (S174, prebrano 2026-11-16; 114. boerboel je blokiran) |
| PR-ji | pet-prep #1229 (pasma, združen `acbd29b`) · portret pet-prep (zagon `breed-portraits.yml` 43821147714, sprejet v prvem poskusu) · spletna stran: pet-prep-website `feat/register-manchester-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-16 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | stran RKC ni bila dosegljiva → po pravilu PDSA: S889 »Manchester Terriers will need up to an hour of exercise per day …« (»up to N« → N; ključni podatek »1 hour« se ujema); PetMD S890 »at least 30 minutes a day« manj (alternativa), WebMD S891 »an hour of outside playtime« enako | #1229 |
| 2026-11-16 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108**; prihod 2 / 9 / 36 / 108 | AAHA S11; starejši = 0,75 × spodnja meja PDSA **12 let** (»Over 12 years«, S889) = 108 mesecev. McMillan 2024 / VetCompass ni dosegljiv, stran RKC tudi ne → PDSA (precedens cane corso). Ni izbrano: 84 (Dogs Trust »> 7 let«), 115 (starejša anketa KC 12,8 leta prek Wikipedije, S894) | #1229 |
| 2026-11-16 | pravilo runbooka | učenje **1,2×** | Coren 32. mesto »Manchester Terrier«, skupina »Above Average Working Dogs« (27–39) (S35, zrcalo Kiwix) | #1229 |
| 2026-11-16 | pravilo runbooka | teža **5,5–10 kg** (oba spola — standard CKC), idealna višina 41 cm (psi) / 38 cm (psice) po FCI, rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | CKC S892 »Weight: 5.5 to 10 kg (12 to 22 lb)« (FCI teže ne navaja; UKC S893 enak razpon v funtih; PDSA S889 6–10 kg); FCI S888 »Ideal Height at the withers: Males: 41 cm. Females: 38 cm.«; PetMD S10 (razred »Small«) | #1229 |
| 2026-11-16 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | PDSA S889 »… brushing once a week is generally all that’s needed …«; PetMD S890 in WebMD S891 tedensko se ujemata | #1229 |
| 2026-11-16 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S890 »socialize your Manchester Terrier puppy from the moment you bring them home.« | #1229 |
| 2026-11-16 | pravila »Breed care tasks« (David 2026-10-24, M5-R15) | **ščetkanje zob vsak dan** (otrokova mini igra) | WebMD S891 »You should brush your Manchester's teeth daily with dog-friendly toothpaste«. Kremplji (»regular nail trims«), kopanje (»an occasional bath«) in ušesa (PetMD »Talk to your veterinarian …«) brez pogostosti → brez naloge; frizer ni omenjen | #1229 |
| 2026-11-16 | pravilo runbooka | brez opozorila po obroku in brez poletnega opozorila za sprehod | noben vir ne navaja premora po obroku; ni ploskoobrazna pasma | #1229 |
| 2026-11-16 | pravilo runbooka | oznake: primeren za **»aktivno družino«** in **»družino z otroki«**; upoštevajte **»tanka dlaka — občutljiv na mraz«** in »močan lovski nagon« | PDSA S889 (do ure gibanja na dan); PetMD S890 »Manchesters also do well with children, especially when exposed to kids during puppyhood.« (WebMD z opozorilom »small children should never be left with dogs unsupervised«); WebMD S891 »Manchester Terriers are sensitive to the cold.« (precedens italijanski hrt); PetMD S890 »They can also have a high prey drive because of their history as ratters« | #1229 |
| 2026-11-16 | pravilo runbooka | **brez** `family_pet` (noben vir A/B ne pravi »družinski pes«), `apartment` / `sheds` (ključnih podatkov RKC ni bilo), `small_children` (opozorilo o nadzoru), `needs_company` (brez vira), `long_daily_exercise`, `frequent_grooming`; nikoli »hypoallergenic« | S889–S891 | #1229 |
| 2026-11-16 | pravilo runbooka (§3, dobrobit) | nobeno veterinarsko združenje pasme ne navaja zaradi težav s telesno zgradbo → brez opozorilnega čipa; **v aplikaciji da, v marketingu da** (nikoli z malimi živalmi, nikoli s kupiranimi ušesi, nikoli kot »hipoalergen«); kategorija RKC Breed Watch ni preverjena | S92 (le ploskoobrazne pasme); stran RKC ni bila dosegljiva | #1229 |
| 2026-11-16 | pravilo runbooka (§3) | barva za AI in izbirnik: **črno-rjav** (globoko črn z bogato mahagonijevo rjavimi oznakami) — edina standardna barva, privzeto za portret; naravna V-ušesa, naraven rep | FCI S888 »Any other colour or combination of colours unacceptable.«; UKC S893 izključi vsako drugo barvo; uteži brez vira → `verified: false` | #1229 |
| 2026-11-16 | pravilo runbooka (§2.3) | seznam za vzreditelja: **ni preverjen** (`not_checked`) — razdelek RKC o testih ni bil dosegljiv | runbook: brez vnosa → `not_checked` | #1229 |
| 2026-11-16 | pravilo runbooka | zdravstveni ključi registra: `bleeding_disorder_vwd`, `heart_muscle_disease`, `legg_perthes`, `hypothyroidism`, `primary_lens_luxation`; brez odstotkov, brez novega ključa (alopecija in SLE ostaneta v data.json) | PDSA S889, PetMD S890, WebMD S891 | #1229 |
| 2026-11-16 | predlog Claude, David lahko spremeni | EN »Manchester Terrier« (FCI / AKC »Manchester Terrier (Standard)«), SL »Manchesterski terier«; iskanje tudi »manchester«, »manchester terier«, »black and tan terrier« | strani KZS nisem dosegel | #1229 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »manchesterski terier«, »manchester« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-119 | 2026-11-16 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 108. meseca; 4 → 3 → 2 obroka; učenje 1,2×) | 🛠 | 💶 izziv | M5-R10-119 | 2026-11-16 |
| Česanje 1× na teden, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-16 |
| Ščetkanje zob vsak dan (otrok) | 🛠 | 💶 izziv | M5-R15 | 2026-11-16 |
| Barva (izbirnik: črno-rjav) | 🛠 | 💶 izziv | M5-R21 | 2026-11-16 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), črno-rjav, naravna V-ušesa, naraven rep | — | M5-R11-04 | 2026-11-16 |
| Stran na petprep.si (`/animals/dogs/manchester-terrier`, `/sl/zivali/psi/manchesterski-terier`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal manchesterskega terierja — eleganten, kratkodlak črno-rjav terier iz Velike Britanije, 115. najbolj priljubljeno pasmo po AKC 2024 in 110. pasmo psa v aplikaciji (boerboel pred njim je ostal blokiran, ker za gibanje ni vira s številom). 7 novih virov (S888–S894). Odrasel pes v igri potrebuje 6.000 korakov na dan (PDSA: do ene ure gibanja), česanje enkrat na teden in ščetkanje zob vsak dan.
**Zakaj je pomembno:** Manchesterski terier je bister, živahen in predan lastniku (FCI, PDSA), dobro se razume z otroki, ki jih spozna kot mladiček (PetMD) — a je bil vzrejen za lov na podgane, zato ima močan lovski nagon in ni za dom s hrčki ali kunci (PetMD, WebMD). Kratka dlaka ga slabo greje: pozimi pogosto potrebuje plašček (WebMD).
**Kako povedati** — starši: »Manchesterski terier je majhen, a športen pes: uro gibanja na dan, malo nege dlake, a vsak dan ščetkanje zob. Ni primeren za dom z malimi glodavci in pozimi ga zebe. V PetPrepu boste videli, ali vaš otrok zmore ta ritem.« · otroci: »Manchesterski terier je hiter, pameten pes v črno-rjavem kožuhu — vsak dan greš z njim na sprehod in mu umiješ zobe, enkrat na teden ga počešeš.« · vlagatelji / partnerji: »110. pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal z viri — ko glavni vir (RKC) ni bil dosegljiv, je po pravilu uporabil PDSA.«

## Odprto za Davida

- **Življenjska doba / starejše obdobje:** uporabljena je spodnja meja PDSA 12 let → starejši od 108. meseca. Wikipedija navaja starejšo anketo KC z mediano 12,8 leta → bilo bi 115 mesecev; WebMD 15–17 let; tabela Dogs Trust McMillan 2024 (S123) ni bila dosegljiva — preverite.
- **Stran RKC:** ključni podatki RKC (velikost doma, izpadanje dlake, Breed Watch) in testi za vzreditelje niso bili dosegljivi (preusmeritev na royalkennelclub.com) → seznam za vzreditelja je »ni preverjen«; ob ročnem pregledu dopolnite.
- **Gibanje:** PDSA »do ure na dan« → 60 min; PetMD »vsaj 30 minut« (klub 30–60 min). Povejte, če naj bo manj.
- **Kremplji / kopanje:** WebMD »redno« / »občasno« brez pogostosti → po pravilu brez naloge.
- **Ime:** »Manchesterski terier« ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
