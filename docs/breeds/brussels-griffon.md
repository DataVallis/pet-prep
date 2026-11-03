# Bruseljski grifon (Brussels Griffon / Griffon Bruxellois)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-88 · 2026-11-03

| | |
|---|---|
| Ključ / slug | `brussels_griffon` / `brussels-griffon` (SL `bruseljski-grifon`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 820 — v izbirniku za šarpejem |
| Vir podatkov | `docs/research/dog-data/data.json` → `brussels_griffon` (viri S669–S674; ponovno S10, S11, S14, S24, S35, S45, S92, S174); seznam za vzreditelja: stran RKC (S670) |
| Izvor | FCI št. 80, skupina 9, sekcija 3 (mali belgijski psi), Belgija (S669); RKC in AKC vodita skupaj grobodlako in gladkodlako različico (FCI: Griffon Belge in Petit Brabançon sta ločeni pasmi) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 84. mesto »Brussels Griffon« (S174, prebrano 2026-11-03) |
| PR-ji | pet-prep #582 (pasma) · portret: sledi · spletna stran: šele s portretom |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-03 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **30 min/dan → 3.000 korakov**; starejši 23 min → 2.300; mladiček 10 min × starost do 30 (pri 3 mesecih) | stran RKC S670 »Exercise: Up to 30 minutes per day« (»up to N« → N); PetMD S672 »… up to 30 minutes of moderate activity or neighborhood walks per day.« se ujema; 0,75 × 30 = 22,5 → 23 | #582 |
| 2026-11-03 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108**; prihod 2 / 9 / 36 / 108 | AAHA S11; starejši = 0,75 × spodnja meja RKC **12 let** (»Over 12 years«, S670) = 9 let = 108 mesecev. McMillan 2024 / VetCompass ni dosegljiv → tretji vir pravila (začasno). PetMD 12–15 let se ujema. Ni izbrano: 120 (McMillan 13,3 leta le prek Wikipedie, S673), 84 (Dogs Trust »> 7 let«) | #582 |
| 2026-11-03 | pravilo runbooka | učenje **0,7×** | Coren: 59. mesto »Griffon Bruxellois« (skupaj z maltežanom), skupina »Fair« (55–69) (S35, preverjeno 2026-11-03) | #582 |
| 2026-11-03 | pravilo runbooka | teža **3,5–6 kg** (oba spola, FCI), višina 18–25 cm (PetMD 7–10 in), rast do 8–12 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S669 »Varie de 3,5 à 6 kg.«; KC S671 »Weight: 3.2kg – 5kg.« (alternativa); standarda višine ne navajata; PetMD S10 (razred »Toy«) | #582 |
| 2026-11-03 | **predlog Claude — čaka Davida** (M5-R13) | česanje **2× na teden** (`verified=false`); skuštrana dlaka po 1 zamujenem | RKC S670 »Grooming: More than once a week« brez števila; PetMD S672: grobodlaki »weekly brushing« (1 — nasprotuje »več kot enkrat«), gladkodlaki »daily to weekly brushing« (do 7) → ni enotne številke za grobodlakega psa iz igre → nadomestna vrednost 2 (precedens lagotto romagnolo, pirenejski gorski pes) | #582 |
| 2026-11-03 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S672 »However, early and consistent socialization can help them feel more confident and comfortable.« | #582 |
| 2026-11-03 | pravila »Breed care tasks« (David 2026-10-24, M5-R14 / R15 / R18) | mini igri **čiščenje gub na obrazu 1× na teden** in **ščetkanje zob vsak dan**; opomnik staršem **obisk frizerja vsake 4 tedne** | PetMD S672 »Their face folds should be cleaned around once a week.«; »Brussels Griffons will likely benefit from daily at-home tooth brushing«; grobodlaki »professional grooming every four to eight weeks« (razpon → spodnja meja 4 tedne). Kremplji / ušesa »regularly«, kopanje »occasional« → brez naloge | #582 |
| 2026-11-03 | pravilo runbooka (M5-R14) | **opozorilo za sprehod poleti opoldne** (ploskoobrazna pasma, vir o vročini) | PetMD S672 »This makes them more prone to snore and more sensitive to hot, humid weather.«; »Care should be taken to limit exercise and extended periods of time outside during the summer months« | #582 |
| 2026-11-03 | pravilo runbooka | brez opozorila po obroku (M5-R16) | noben vir ne omenja premora med hranjenjem in gibanjem | #582 |
| 2026-11-03 | pravilo runbooka | oznake: primeren za **»življenje v stanovanju«**, **»družino z otroki«** (z nadzorom), **»dom z drugimi ljubljenčki«**; upoštevajte **»kratek gobček — težave z dihanjem in vročino«**, **»rad ima družbo«**, »izpada mu dlaka«, »potrebuje česanje večkrat na teden« | RKC S670 »Flat/ Apartment«, »Sheds: Yes«, »More than once a week«; PetMD S672 »Brussels Griffons typically do well in families with children, other dogs, or cats,« + »but they can be sensitive, so supervision is recommended.«; »brachycephalic dogs« + vročina; »They are considered “Velcro dogs” and don’t like to be left alone for extended periods of time.« | #582 |
| 2026-11-03 | pravilo runbooka | **brez** `family_pet`, `small_children`, `often_alone`, `low_shedding`, `sensitive_skin`, `short_muzzle_breathing`, `shorter_lifespan`, `chases_small_pets`; nikoli »hypoallergenic« | ni izrecne izjave »družinski pes« (AKC S674: »A sensitive companion for discerning grownups«); ni ocene za majhne otroke; RKC »Over 12 years«; ni izjave o lovskem nagonu | #582 |
| 2026-11-03 | pravilo runbooka (§3, dobrobit) | **ploskoobrazna pasma s skrbmi za dobrobit** (PetMD »brachycephalic«, RKC Breed Watch kategorija 2, RKC najboljša praksa: ocena dihanja BOAS) → čip `brachycephalic_breathing`; marketing: **v aplikaciji da, v marketingu / posnetkih zaslona ne** (BVA S92 »avoiding imagery of brachycephalic dogs in advertising …«) | S672, S670, S92 | #582 |
| 2026-11-03 | pravilo runbooka (§3) | barve za AI in izbirnik: **rdeča (privzeta), črno-rjava, črna** — brez belih znamenj; portret rdeč, grobodlak, zmerno kratek obraz z vidnim nosom in odprtimi nosnicami, naravna ušesa in rep | KC S671 »Clear red, black, or black and rich tan, without white markings.«; FCI S669 dovoli le rdečo (črne in črno-rjave grobodlake vodi kot Griffon Belge); uteži brez vira → `verified: false` | #582 |
| 2026-11-03 | pravilo runbooka (§2.3) | seznam za vzreditelja: minimum »Prioritising genetic diversity« (`inbreeding_coi`); najboljša praksa očesna shema BVA/KC/ISDS in ocena dihanja BOAS (`boas_grading`, »NEW test recognised Aug 2026«) | stran RKC S670; brez novega ključa | #582 |
| 2026-11-03 | pravilo runbooka | zdravstveni ključi registra: `flat_face_breathing`, `heat_stroke_risk`, `dental_disease_tooth_brushing`, `eye_conditions`, `kneecap_luxation`, `hip_dysplasia`; brez odstotkov, brez novega ključa | PetMD S672 | #582 |
| 2026-11-03 | predlog Claude, David lahko spremeni | EN »Brussels Griffon«, SL »Bruseljski grifon«; iskanje tudi »griffon bruxellois«, »griffon«, »belgium griffon«, »grifon« | strani KZS nisem preveril | #582 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »bruseljski grifon«, »griffon bruxellois« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-88 | 2026-11-03 |
| Pravila po starosti (2.000 → 3.000 korakov pri 3 mesecih, odrasel 3.000, starejši 2.300 od 108. meseca; 4 → 3 → 2 obroka; učenje 0,7×) | 🛠 | 💶 izziv | M5-R10-88 | 2026-11-03 |
| Česanje 2× na teden (predlog), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-03 |
| Čiščenje gub 1× na teden, ščetkanje zob vsak dan (mini igri), opomnik za frizerja vsake 4 tedne, opozorilo za sprehod poleti opoldne | 🛠 | 💶 izziv | M5-R14 / M5-R15 / M5-R18 | 2026-11-03 |
| Barva (izbirnik: rdeča, črno-rjava, črna) | 🛠 | 💶 izziv | M5-R21 | 2026-11-03 |
| AI portret v registru | 🗓 sledi po združitvi | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/brussels-griffon`, `/sl/zivali/psi/bruseljski-grifon`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal bruseljskega grifona — majhnega belgijskega psa z ostro, žimasto dlako in brado; 84. najbolj priljubljeno pasmo po AKC 2024 in 81. plačljivo pasmo psa. 6 novih virov (S669–S674). Odrasel pes v igri potrebuje 3.000 korakov na dan (RKC: do pol ure gibanja), česanje dvakrat na teden, otrok mu enkrat na teden očisti gube na obrazu in vsak dan ščetka zobe, starš dobi opomnik za frizerja vsake 4 tedne, poleti opoldne pa aplikacija opozori, naj s sprehodom počakata.
**Zakaj je pomembno:** Grifon je pameten, živahen in navezan na družino — po PetMD »Velcro« pes, ki ne mara biti dolgo sam. Je ploskoobrazna pasma: lažje se pregreje, potrebuje redno nego obraza in zob. PetPrep tega ne olepšuje — v izbirniku so čipi, nega je prava naloga.
**Kako povedati** — starši: »Bruseljski grifon je majhen, a zahteven pes: ima rad družbo, kratek gobček pomeni previdnost v vročini, potrebuje redno nego zob in obraza. V PetPrepu boste videli, ali vaš otrok zmore vsakodnevno skrb.« · otroci: »Grifon je majhen bradač, ki rad ostane s tabo — vsak dan mu ščetkaš zobke, enkrat na teden očistiš gubice, poleti pa ne hodita ven, ko je najbolj vroče.« · vlagatelji / partnerji: »Enainosemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, opozorili o dobrobiti, nalogami nege in testi.« (v marketingu brez slik grifona — BVA)

## Odprto za Davida

- **Česanje 2× na teden** je moj predlog (`verified=false`): RKC »More than once a week« nima števila, PetMD za grobodlakega navaja tedensko (1), za gladkodlakega dnevno do tedensko (do 7). Potrdite ali določite drugo število.
- **Življenjska doba / starejše obdobje:** uporabljena je spodnja meja RKC 12 let → starejši od 108. meseca (začasno). Wikipedia navaja McMillan 2024 13,3 leta (to bi dalo 120); tabela Dogs Trust (S123) ni bila dosegljiva — preverite.
- **Marketing:** izključen po pravilu BVA (S92) — PetMD grifona izrecno imenuje brahicefalnega. Če ga želite v marketingu, povejte.
- **Barve:** igra uporablja barve standarda KC (rdeča, črno-rjava, črna); FCI pod št. 80 dovoli le rdečo (črne grobodlake vodi kot Griffon Belge). Gladkodlaka različica (FCI Petit Brabançon) v igri ni na voljo — pes v igri je grobodlak.
- **Ime:** »Bruseljski grifon« ni preverjen na straneh KZS. Potrdite ali popravite.
- **Iskanje:** »grifon« zdaj najde tudi francoskega ostrodlakega ptičarja in bruseljskega grifona (popravljen en test pri ptičarju, ker ga ime pasme vsebuje).
- **Kremplji, ušesa, kopanje:** viri pišejo »regularly« / »occasional« brez pogostosti → ni naloge (pravilo).
