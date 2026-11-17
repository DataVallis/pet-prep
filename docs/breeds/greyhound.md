# Angleški hrt (Greyhound)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-122 · 2026-11-17

| | |
|---|---|
| Ključ / slug | `greyhound` / `greyhound` (SL `angleski-hrt`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1130 — v izbirniku za tibetanskim terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `greyhound` (viri S912–S922; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: ni preverjen (stran RKC ni bila dosegljiva) |
| Izvor | FCI št. 158, skupina 10 (hrti), sekcija 3 (kratkodlaki hrti), Velika Britanija (S912) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 118. mesto »Greyhound« (S174, zapisano 2026-11-17 v vrstici 121) |
| PR-ji | pet-prep #1303 (pasma, združen `0fe447a`) · portret pet-prep (zagon `breed-portraits.yml` 44035672011, sprejet v prvem poskusu) · barvne različice (sledi) · spletna stran: pet-prep-website `feat/register-greyhound` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-17 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | stran RKC ni bila dosegljiva → po pravilu PDSA: S914 »Greyhounds aren't overly energetic, requiring about an hour of exercise daily.« (»about N« → N; ključni podatek »1 hour« se ujema) | #1303 |
| 2026-11-17 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **103**; prihod 2 / 9 / 36 / 103 | AAHA S11; starejši = 0,75 × mediana VetCompass **11,4 leta** (O'Neill et al. 2019, S920: »The median longevity of greyhounds overall was 11.4 years …«) = 102,6 → 103 meseci. Tabela McMillan 2024 (S123) ni bila dosegljiva (11,5 leta le prek Wikipedije, S921, ozadje). Ni izbrano: 90 (0,75 × PDSA »Over 10 years«), 84 (Dogs Trust »> 7 let«) | #1303 |
| 2026-11-17 | pravilo runbooka | učenje **1,0×** | Coren 46. mesto »Greyhound«, skupina »Average« (40–54) (S35) | #1303 |
| 2026-11-17 | pravilo runbooka | teža **27,2–31,8 kg** (psi 65–70 lb, psice 60–65 lb — standard AKC), višina psi 71–76 cm / psice 68–71 cm (FCI, idealna), rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | AKC S918 »Dogs, 65 to 70 pounds« / »Bitches, 60 to 65 pounds« (FCI teže ne navaja; PDSA S914 25–35 kg); FCI S913 »Males: 71cm – 76cm«; PetMD S10 (razred »Large«) | #1303 |
| 2026-11-17 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | PDSA S914 »They have a short coat which requires weekly grooming to keep it in good condition.« (»weekly« → 1); AKC S919 »weekly brushings« enako | #1303 |
| 2026-11-17 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PDSA S914 »With the right socialisation as puppies, they can make good family pets« | #1303 |
| 2026-11-17 | pravila »Breed care tasks« (David 2026-10-24, M5-R15) | **ščetkanje zob vsak dan** (otrokova mini igra) | WebMD S917 »Brush your dog's teeth on a daily basis …«. Kremplji (»whenever they get too long«), kopanje (»whenever they become too dirty or smelly«), ušesa (PetMD »Routine ear cleaning«, brez pogostosti) → brez naloge | #1303 |
| 2026-11-17 | pravilo runbooka | brez opozorila po obroku in brez poletnega opozorila za sprehod | PDSA, WebMD in PetMD omenjajo napihnjenost (GDV), a noben vir ne navaja premora po obroku z dobesednim citatom (PetMD le »two or three smaller meals«); ni ploskoobrazna pasma | #1303 |
| 2026-11-17 | pravilo runbooka | oznake: primeren za **»družinsko življenje«**; upoštevajte **»močan lovski nagon — lahko lovi manjše živali«** | PDSA S914 »With the right socialisation as puppies, they can make good family pets« (pogoj ostane v opombah); PetMD S916 »Greyhounds have a very strong prey drive, so they aren't ideal for homes with cats or other small animals.« | #1303 |
| 2026-11-17 | pravilo runbooka | **brez** `children` / `small_children` (PetMD »patient with children« proti WebMD »only moderately good with young children«), `active_family` (PDSA »aren't overly energetic«), `low_shedding` / `sheds` (WebMD »shed infrequently« proti Dogs Trust »moderate shedding«), `apartment` (ključnih podatkov RKC ni bilo), `cold_sensitive` (WebMD govori o skrajnih temperaturah, ne o mrazu), `needs_company` (ni vira); nikoli »hypoallergenic« | S914–S917 | #1303 |
| 2026-11-17 | pravilo runbooka (§3, dobrobit) | RKC Breed Watch kategorija 1 (pasemski klub S922) → brez opozorilnega čipa; **v aplikaciji da, v marketingu da** — nikoli z mačkami / malimi živalmi ali brez povodca na odprtem, nikoli kot »hipoalergen« | S92 (le ploskoobrazne pasme); S914 / S916 (lovski nagon) | #1303 |
| 2026-11-17 | pravilo runbooka (§3) | barve za AI in izbirnik: **tigrast** (privzeto za portret), črn, plavo rjav, moder (siv), rdeč, črno-bel — imenovane barve standarda; nikoli merle; naravni rep, neprirezana ušesa | FCI (kopija KUSA S913) »Black, white, red, blue, fawn, fallow, brindle or any of these colours broken with white.« (PDF fci.be S912 je vrnil stavek brez »fawn«); uteži brez vira → `verified: false` | #1303 |
| 2026-11-17 | pravilo runbooka (§2.3) | seznam za vzreditelja: **ni preverjen** (`not_checked`) — razdelek RKC o testih ni bil dosegljiv | runbook: brez vnosa → `not_checked` | #1303 |
| 2026-11-17 | pravilo runbooka | zdravstveni ključi registra: `bloat_gdv`, `periodontal_disease`, `pra_eye_disease`, `bleeding_disorder_vwd`; brez odstotkov, brez novega ključa (osteosarkom ter vročina in otrdelost po naporu ostaneta v data.json) | PDSA S914 | #1303 |
| 2026-11-17 | predlog Claude, David lahko spremeni | EN »Greyhound«, SL »Angleški hrt«; iskanje tudi »greyhound«, »angleski hrt«, »grejhaund«, »hrt« | strani KZS nisem dosegel | #1303 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »angleški hrt«, »greyhound« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-122 | 2026-11-17 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 103. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-122 | 2026-11-17 |
| Česanje 1× na teden, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-17 |
| Ščetkanje zob vsak dan (otrok) | 🛠 | 💶 izziv | M5-R15 | 2026-11-17 |
| Barve (izbirnik: 6 barv) | 🛠 | 💶 izziv | M5-R21 | 2026-11-17 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), tigrast, vitek atletski hrt, naravni rep | — | M5-R11-04 | 2026-11-17 |
| Stran na petprep.si (`/animals/dogs/greyhound`, `/sl/zivali/psi/angleski-hrt`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal angleškega hrta — velikega, mirnega in nežnega šprinterja iz Velike Britanije, 118. najbolj priljubljeno pasmo po AKC 2024 in 112. pasmo psa v aplikaciji. 11 novih virov (S912–S922). Odrasel pes v igri potrebuje 6.000 korakov na dan (PDSA: okoli ene ure gibanja), česanje enkrat na teden in ščetkanje zob vsak dan.
**Zakaj je pomembno:** Angleški hrt ni »pes za maraton« — PDSA pravi, da ni pretirano energičen in ima raje nekaj dobrih tekov kot dolge sprehode. Ima pa zelo močan lovski nagon (PetMD), zato ni primeren za dom z mačkami ali malimi živalmi. Veliko hrtov v Veliki Britaniji je upokojenih dirkalnih psov, ki iščejo dom.
**Kako povedati** — starši: »Angleški hrt je miren, nežen družinski pes, ki doma rad poleži — a potrebuje uro gibanja na dan in ne sodi k mački ali zajčku. V PetPrepu boste videli, ali vaš otrok zmore 6.000 korakov in skrb 12 tednov zapored.« · otroci: »Angleški hrt je najhitrejši pes na svetu, doma pa velik zaspanec — vsak dan greš z njim na sprehod in mu umiješ zobe.« · vlagatelji / partnerji: »112. pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal z viri — ko glavni vir (RKC) ni bil dosegljiv, je po pravilu uporabil PDSA, standard AKC in recenzirano študijo VetCompass.«

## Odprto za Davida

- **Potreba brez mehanike (potrebna nova naloga v ROADMAP):** nekaj dobrih tekov namesto dolgih sprehodov (PDSA S914 »They prefer the opportunity for a good couple of runs rather than miles of walking«) — igra šteje le korake (kot tek brez povodca pri malem angleškem hrtu).
- **Potreba brez mehanike (potrebna nova naloga v ROADMAP):** pozornost ob skrajnih temperaturah in velikih spremembah vremena (WebMD S917 »… they can be susceptible to extreme temperatures.«) — igra nima vremena; čipa `cold_sensitive` nisem dodal, ker vir ne govori izrecno o mrazu.
- **Življenjska doba / starejše obdobje:** uporabljena je mediana VetCompass 11,4 leta (2019, S920) → starejši od 103. meseca. McMillan 2024 (11,5 leta, le prek Wikipedije) bi dal 104 — preverite vrstico »Greyhound« v tabeli Dogs Trust (S123).
- **Stran RKC:** ključni podatki RKC (velikost doma, izpadanje dlake) in testi za vzreditelje niso bili dosegljivi (preusmeritev na royalkennelclub.com) → seznam za vzreditelja je »ni preverjen«. Pasemski klub (S922) omenja DNK test za nevropatijo hrtov — ob ročnem pregledu strani RKC dopolnite.
- **Barve:** PDF FCI (S912) je stavek o barvah vrnil brez »fawn«, kopija KUSA (S913) ga ima — plavo rjava je ostala med barvami. Preverite, ali je novi standard 2026 barvo res umaknil.
- **Otroci:** PetMD (potrpežljiv z otroki) in WebMD (le zmerno dober z majhnimi otroki) se razlikujeta → brez čipa »družino z otroki«; odločite, če želite drugače.
- **Ime:** »Angleški hrt« ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
