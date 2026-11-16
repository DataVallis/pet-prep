# Ostrodlaki foksterier (Wire Fox Terrier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-117 · 2026-11-16

| | |
|---|---|
| Ključ / slug | `wire_fox_terrier` / `wire-fox-terrier` (SL `ostrodlaki-foksterier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1100 — v izbirniku za parson russell terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `wire_fox_terrier` (viri S871–S878; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: stran RKC (S872) |
| Izvor | FCI št. 169, skupina 3, sekcija 1 (veliki in srednji terierji), Velika Britanija (S871) — ni toy fox terier (`toy_fox_terrier`) in ni kratkodlaki foksterier (FCI 12, ni v aplikaciji) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 113. mesto »Wire Fox Terrier« (S174, zapisano 2026-11-15 v vrstici 116 čakalne vrste) |
| PR-ji | pet-prep #1203 (pasma) · portret: sledi · spletna stran: sledi |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-16 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | stran RKC S872 »Exercise: Up to 1 hour per day« (»up to N« → N); PDSA S873 »1 hour« in PetMD S874 »at least an hour« se ujemata | #1203 |
| 2026-11-16 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108**; prihod 2 / 9 / 36 / 108 | AAHA S11; starejši = 0,75 × spodnja meja RKC **12 let** (»Over 12 years«, S872; PDSA enako) = 108 mesecev. McMillan 2024 / VetCompass ni dosegljiv (le prek Wikipedije, S878) → tretji vir pravila. Ni izbrano: 84 (Dogs Trust »> 7 let«), 122 (McMillan prek Wikipedije) | #1203 |
| 2026-11-16 | pravilo runbooka | učenje **1,0×** (kot mešanček) | Coren 51. mesto »Wire Fox Terrier«, skupina »Average« (40–54) (S35) | #1203 |
| 2026-11-16 | pravilo runbooka | teža **6,8–8,6 kg** (psi 7,7–8,6, psice 6,8–7,7 kg — AKC), višina do 39 cm (FCI; psice nekoliko manj), rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | AKC S876 »should scale 18 pounds … a bitch weighing some two pounds less - with a margin of one pound either way« (naša pretvorba); FCI S871 idealno 8,25 kg za pse, KC S877 8 kg, PDSA S873 8 kg; PetMD S10 (razred »Small«) | #1203 |
| 2026-11-16 | pravilo runbooka (M5-R13) | česanje **2× na teden**; skuštrana dlaka po 1 zamujenem | RKC S872 »Grooming: More than once a week« brez števila → najbolj natančno število drugega vira: PDSA S873 »once or twice a week« → zgornja meja 2 (precedens angleški springer španjel) | #1203 |
| 2026-11-16 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S874 »Socialize your Wire Fox Terrier puppy well when they are young« | #1203 |
| 2026-11-16 | pravila »Breed care tasks« (David 2026-10-24, M5-R14 / R15 / R19 / R20) | **ščetkanje zob vsak dan** in **striženje krempljev vsake 4 tedne** (otrokovi mini igri) ter opomnika staršem **kopanje vsake 4 tedne** in **frizer / ročno trimanje vsakih 12 tednov** (nikoli v točkah) | PetMD S874 »daily tooth brushing« (WebMD S875 se ujema); WebMD »trim … nails about once a month«; PetMD »about once a month« (kopanje); PetMD ročno trimanje »three to four times a year« → vsake 3 mesece (precedens airedale terier). Ušesa: brez pogostosti (»Talk to your vet«) → brez naloge | #1203 |
| 2026-11-16 | pravilo runbooka | brez opozorila po obroku in brez poletnega opozorila za sprehod | noben vir ne navaja premora po obroku; ni ploskoobrazna pasma | #1203 |
| 2026-11-16 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družinsko življenje«**, **»družino z otroki«**; upoštevajte »močan lovski nagon« | RKC S872 »Up to 1 hour per day«; PDSA S873 »Fox Terriers need around an hour's exercise daily …«, »For this reason, they make excellent family pets.«; WebMD S875 »… great companions for children.« (z opozorilom »Always supervise your dog around young kids.«); PetMD S874 »… because of their high prey drive.« | #1203 |
| 2026-11-16 | pravilo runbooka | **brez** čipa o izpadanju dlake (viri si nasprotujejo: RKC »Sheds: Yes«, PetMD »sheds minimally«, WebMD »don't shed much«), brez `apartment` (RKC »Small house«), `small_children`, `needs_company` (brez vira), `long_daily_exercise`, `frequent_grooming`; nikoli »hypoallergenic« | S872, S874, S875 | #1203 |
| 2026-11-16 | pravilo runbooka (§3, dobrobit) | RKC Breed Watch **kategorija 1**, brez točk skrbi → brez opozorilnega čipa; **v aplikaciji da, v marketingu da** (nikoli z majhnimi živalmi, nikoli s kupiranim repom, nikoli kot »hipoalergen«) | S872 »Currently no points of concern specific to this breed …« | #1203 |
| 2026-11-16 | pravilo runbooka (§3) | barve za AI in izbirnik: **tribarven** (bel s črnimi in rjavimi lisami — privzeto za portret, največja utež), bel z rjavimi lisami, bel s črnimi lisami; naraven rep | FCI S871 »White predominates with black, black and tan or tan markings.«; nikoli tigrast, rdeč, jetrn ali skrilasto moder; uteži brez vira → `verified: false` | #1203 |
| 2026-11-16 | pravilo runbooka (§2.3) | seznam za vzreditelja: minimum genetska raznolikost (`inbreeding_coi`), najboljša praksa pregled oči BVA/KC/ISDS; RKC za pasmo nima DNK paketa; brez novega ključa | stran RKC S872 | #1203 |
| 2026-11-16 | pravilo runbooka | zdravstveni ključi registra: `primary_lens_luxation`, `legg_perthes`, `cataracts`, `itchy_skin_allergy`, `kneecap_luxation`, `glaucoma`, `dental_disease_tooth_brushing`; brez odstotkov, brez novega ključa (displazija kolkov / komolcev in wobbler po WebMD ostaneta v data.json) | PDSA S873, PetMD S874, WebMD S875 | #1203 |
| 2026-11-16 | predlog Claude, David lahko spremeni | EN »Wire Fox Terrier« (AKC; FCI / RKC »Fox Terrier (Wire)«), SL »Ostrodlaki foksterier«; iskanje tudi »foksterier«, »fox terier«, »wire haired fox terrier« — iskanje »toy fox« ostane le toy fox terier | strani KZS nisem dosegel | #1203 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »ostrodlaki foksterier«, »foksterier« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-117 | 2026-11-16 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 108. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-117 | 2026-11-16 |
| Česanje 2× na teden, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-16 |
| Ščetkanje zob vsak dan, kremplji vsake 4 tedne (otrok), opomnika staršem: kopanje vsake 4 tedne, frizer vsakih 12 tednov | 🛠 | 💶 izziv | M5-R14 / M5-R15 / M5-R19 / M5-R20 | 2026-11-16 |
| Barva (izbirnik: tribarven, bel z rjavimi lisami, bel s črnimi lisami) | 🛠 | 💶 izziv | M5-R21 | 2026-11-16 |
| AI portret v registru | sledi (portret se naredi takoj po združitvi) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/wire-fox-terrier`, `/sl/zivali/psi/ostrodlaki-foksterier`) | 🗓 šele s portretom; nato »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal ostrodlakega foksterierja — živahnega terierja iz Velike Britanije z gosto, žimasto dlako, 113. najbolj priljubljeno pasmo po AKC 2024 in 109. pasmo psa v aplikaciji. 8 novih virov (S871–S878). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do ene ure gibanja), česanje dvakrat na teden, ščetkanje zob vsak dan in striženje krempljev vsake štiri tedne; starši dobijo opomnik za kopanje vsake štiri tedne in za frizerja (ročno trimanje) vsakih 12 tednov.
**Zakaj je pomembno:** Foksterier je prijazen, odprt in neustrašen (FCI), odličen družinski pes (PDSA), a ima močan lovski nagon (PetMD, WebMD) — ob mačkah in malih živalih potrebuje nadzor. Njegova žimasta dlaka potrebuje redno nego, ki jo družina pogosto spregleda.
**Kako povedati** — starši: »Ostrodlaki foksterier je vesel, pogumen družinski pes, ki potrebuje uro gibanja na dan in redno nego dlake — tudi obisk frizerja nekajkrat na leto. V PetPrepu boste videli, ali vaš otrok zmore ta ritem: sprehodi, česanje, zobje in kremplji.« · otroci: »Foksterier je majhen, radoveden terier, ki se rad igra in raziskuje — vsak dan greš z njim na sprehod, mu umiješ zobe, dvakrat na teden ga počešeš in enkrat na mesec mu postrižeš kremplje.« · vlagatelji / partnerji: »109. pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, štirimi nalogami nege (dve za otroka, dva opomnika za starše) in seznamom za vzreditelje.«

## Odprto za Davida

- **Življenjska doba / starejše obdobje:** uporabljena je spodnja meja RKC 12 let → starejši od 108. meseca. Wikipedija navaja McMillan 2024: 13,5 leta → bilo bi 122 mesecev; tabela Dogs Trust (S123) ni bila dosegljiva — preverite.
- **Česanje:** po pravilu 2× na teden (PDSA »once or twice a week«); PetMD priporoča »brushing your dog every other day« (≈ 3–4× na teden). Povejte, če naj bo več.
- **Frizer:** PetMD ročno trimanje »three to four times a year« → vsakih 12 tednov; WebMD za hišne pse (ne razstavne) navaja mesečno striženje (»trimmed monthly«). Povejte, če naj bo opomnik vsake 4 tedne.
- **Izpadanje dlake:** RKC »Sheds: Yes«, PetMD / WebMD »minimalno« → po pravilu ni nobenega čipa (niti »izpada mu dlaka« niti »malo izpada«).
- **Višina psic:** FCI / KC navajata le »nekoliko manj« od 39 cm → register kaže 39 cm za oba spola (kot zgornjo mejo).
- **Ime:** »Ostrodlaki foksterier« ni preverjen na straneh KZS (v slovenščini se uporablja tudi »žimavi foksterier«). Potrdite ali popravite.
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
