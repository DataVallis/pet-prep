# Italijanski ostrodlaki ptičar (Spinone Italiano)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-124 · 2026-11-18

| | |
|---|---|
| Ključ / slug | `spinone_italiano` / `spinone-italiano` (SL `italijanski-ostrodlaki-pticar`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1150 — v izbirniku za mehiškim golim psom |
| Vir podatkov | `docs/research/dog-data/data.json` → `spinone_italiano` (viri S934–S941; ponovno S10, S11, S14, S24, S35, S45, S174, S771); seznam za vzreditelja: ni preverjen (stran RKC ni bila dosegljiva) |
| Izvor | FCI št. 165, skupina 7 (ptičarji), sekcija 1.3 (celinski ptičarji, tip grifon), Italija (S935, S934) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 120. mesto »Spinone Italiano« (S174, zapisano 2026-11-18 v vrstici 123) |
| PR-ji | pet-prep #1354 (pasma, združen `55cac82`) · portret pet-prep (zagon `breed-portraits.yml` 44227113983, sprejet v prvem poskusu) · barvne različice pet-prep (po portretu, runbook §8b) · spletna stran: pet-prep-website `feat/register-spinone-italiano` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-18 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | stran RKC ni bila dosegljiva → po pravilu PDSA: S936 »Italian Spinones are an energetic breed requiring plenty of exercise – ideally more than 2 hours per day.« (»more than N« → N; ključni podatek »2 hours« se ujema). PetMD S937 »around an hour« ni izbran | #1354 |
| 2026-11-18 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 | AAHA S11; starejši = 0,75 × spodnja meja PDSA **»Over 10 years«** (S936) = 90 mesecev (kot pri tibetanskem terierju). McMillan 2024 (S123) in VetCompass nista dala vrednosti. Ni izbrano: 84 (Dogs Trust »> 7 let«, S14), 78 (anketa KC 2004, povprečje 8,7 leta — le prek Wikipedije, S941) | #1354 |
| 2026-11-18 | pravilo runbooka | učenje **1,0×** | Coren pasme ne uvršča (S35) | #1354 |
| 2026-11-18 | pravilo runbooka | teža **28–37 kg** (psi 32–37, psice 28–30 — standard FCI), višina psi 60–70 cm / psice 58–65 cm (FCI), rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S935 »Males: 32 – 37 kg.« / »Males: 60 – 70 cm.«; PDSA S936 29–39 kg; PetMD S10 (razred »Large«) | #1354 |
| 2026-11-18 | pravilo runbooka (M5-R13) | česanje **3× na teden**; skuštrana dlaka po 2 zamujenih | PDSA S936 »… requires grooming at home 2 or 3 times per week …« (razpon → zgornja meja); WebMD S938 »weekly brushing« je alternativa | #1354 |
| 2026-11-18 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PDSA S936 »Once socialised, they fit in well with family life.« (PetMD S937: izpostavljanje živalim, ljudem in krajem v prvih 16 tednih) | #1354 |
| 2026-11-18 | David 2026-10-12 (M5-R16) | **opozorilo »počakaj s sprehodom« 60 min po obroku** (samo opozorilo, koraki se štejejo) | PetMD S937 »Don’t allow your dog to exercise for an hour before or after eating.« (napihnjenost / GDV) | #1354 |
| 2026-11-18 | pravila »Breed care tasks« (David 2026-10-24, M5-R15 / M5-R19) | **ščetkanje zob vsak dan** in **striženje krempljev 1× na teden** (otrokovi mini igri) | WebMD S938 »Weekly nail trims and daily tooth brushing is recommended.« Ušesa (»Talk to your vet …«), kopanje (»regular«), frizer (»occasionally«) → brez naloge | #1354 |
| 2026-11-18 | pravilo runbooka | brez poletnega opozorila za sprehod | ni ploskoobrazna pasma | #1354 |
| 2026-11-18 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družinsko življenje«**, **»družino z otroki«**; upoštevajte **»vsak dan potrebuje veliko gibanja«** in **»rad ima družbo«** | PDSA S936 (2 uri gibanja; »Once socialised, they fit in well with family life.«); WebMD S938 »They're generally regarded to be good with other dogs and kids,« (nadzor pri zelo majhnih otrocih ostane v opombah) in »… leaving them alone for long periods of time will often lead to them getting into trouble.« | #1354 |
| 2026-11-18 | pravilo runbooka | **brez** `small_children` (nadzor pri zelo majhnih otrocih), `chases_small_pets` (WebMD le »a prey drive«, ne »high«), `frequent_grooming` (PDSA 2–3× proti WebMD 1× na teden), `apartment` / `large_home` / `sheds` (ključnih podatkov RKC ni bilo), `shorter_lifespan` (PDSA »Over 10 years«); nikoli »hypoallergenic« | S936–S938 | #1354 |
| 2026-11-18 | pravilo runbooka (§3, dobrobit) | brez opozorilnega čipa (kategorija RKC Breed Watch ni bila dosegljiva, nobeno veterinarsko združenje pasme ne navaja); **v aplikaciji da, v marketingu da** — nikoli brez povodca na odprtem, nikoli kot »hipoalergen« | S92 (le ploskoobrazne pasme); PetMD S937 (povodec / ograjen prostor) | #1354 |
| 2026-11-18 | pravilo runbooka (§3) | barve za AI in izbirnik: **belo-oranžen** (privzeto za portret), rjavo lisast, belo-rjav, oranžno lisast, bel — barve standarda; nikoli trobarven, rjave oznake ali črna; naravni rep | FCI S935 »Pure white, white with orange markings, white speckled with orange,« … »orange roan or brown roan (chestnut).«; uteži brez vira → `verified: false` | #1354 |
| 2026-11-18 | pravilo runbooka (§2.3) | seznam za vzreditelja: **ni preverjen** (`not_checked`) — razdelek RKC o testih ni bil dosegljiv | runbook: brez vnosa → `not_checked` | #1354 |
| 2026-11-18 | pravilo runbooka | zdravstveni ključi registra: `bloat_gdv`, `hip_elbow_dysplasia`, `inherited_ataxia`, `eyelids_turned`, `hypothyroidism`, `itchy_skin_allergy`, `epilepsy`, `panosteitis`; brez odstotkov, brez novega ključa | PDSA S936, WebMD S938 | #1354 |
| 2026-11-18 | predlog Claude, David lahko spremeni | EN »Spinone Italiano« (AKC; RKC »Italian Spinone«), SL »Italijanski ostrodlaki ptičar«; iskanje tudi »spinone«, »italian spinone«, »italian griffon«, »italijanski ostrodlaki pticar«, »ptičar« | strani KZS nisem dosegel | #1354 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 3× na teden«, iskanje »spinone«, »italijanski ostrodlaki ptičar« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-124 | 2026-11-18 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-124 | 2026-11-18 |
| Česanje 3× na teden, »Nova izkušnja« za mladička, opozorilo po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 | 2026-11-18 |
| Ščetkanje zob vsak dan, striženje krempljev 1× na teden (otrok) | 🛠 | 💶 izziv | M5-R15 / M5-R19 | 2026-11-18 |
| Barve (izbirnik: 5 barv) | 🛠 | 💶 izziv | M5-R21 | 2026-11-18 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), belo-oranžen z lisami, robusten ptičar z brado, naravni rep | — | M5-R11-04 | 2026-11-18 |
| Stran na petprep.si (`/animals/dogs/spinone-italiano`, `/sl/zivali/psi/italijanski-ostrodlaki-pticar`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal italijanskega ostrodlakega ptičarja (spinone) — velikega, mirnega lovskega psa z brado in košatimi obrvmi iz Italije, 120. najbolj priljubljeno pasmo po AKC 2024 in 114. pasmo psa v aplikaciji. 8 novih virov (S934–S941). Odrasel pes v igri potrebuje 12.000 korakov na dan (PDSA: idealno več kot 2 uri gibanja), česanje trikrat na teden, ščetkanje zob vsak dan in krajšanje krempljev enkrat na teden.
**Zakaj je pomembno:** Spinone je prijazen z otroki in drugimi psi, a je energičen delovni pes: potrebuje veliko gibanja, ne mara biti dolgo sam (WebMD) in po obroku ne sme takoj teči (PetMD — nevarnost napihnjenosti). Prav to so stvari, ki jih družina lahko preizkusi, preden psa pripelje domov.
**Kako povedati** — starši: »Spinone je nežen, potrpežljiv družinski pes — a potrebuje več kot dve uri gibanja na dan in družbo. V PetPrepu boste videli, ali vaš otrok zmore 12.000 korakov in vsakodnevno skrb 12 tednov zapored.« · otroci: »Spinone ima brado kot dedek! Z njim greš vsak dan na dolg sprehod, ga počešeš in mu umiješ zobe — po kosilu pa malo počakata.« · vlagatelji / partnerji: »114. pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal z viri — ko glavni vir (RKC) ni bil dosegljiv, je po pravilu uporabil PDSA in standard FCI.«

## Odprto za Davida

- **Potreba brez mehanike (potrebna nova naloga v ROADMAP):** zunaj na povodcu ali v ograjenem prostoru (PetMD S937 »Hunting dogs at heart, Spinoni need to be kept on a leash or within a fenced area when outside …«) — igra šteje le korake.
- **Potreba brez mehanike (potrebna nova naloga v ROADMAP):** občasno ročno trimanje dlake (PetMD S937 »… hand-strip … your Spinone occasionally.«) — brez pogostosti; igra pozna le česanje.
- **Potreba brez mehanike (potrebna nova naloga v ROADMAP):** skrb za brado po hrani in vodi (PetMD S937 »… pay special attention to your Spinone Italiano’s beard …«).
- **Življenjska doba / starejše obdobje:** uporabljena je spodnja meja PDSA »Over 10 years« → starejši od 90. meseca. Anketa Kennel Cluba 2004 (povprečje 8,7 leta, le prek Wikipedije, S941) bi dala 78; preverite vrstico »Italian Spinone« v tabeli Dogs Trust (S123), če obstaja.
- **Česanje:** PDSA 2–3× na teden (uporabljeno 3), WebMD 1× na teden — po pravilu runbooka zgornja meja PDSA; spremenite, če želite manj.
- **Stran RKC:** ključni podatki RKC (velikost doma, izpadanje dlake), kategorija Breed Watch in testi za vzreditelje niso bili dosegljivi (preusmeritev na royalkennelclub.com) → seznam za vzreditelja je »ni preverjen«.
- **Ime:** »Italijanski ostrodlaki ptičar« ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
