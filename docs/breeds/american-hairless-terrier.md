# Ameriški goli terier (American Hairless Terrier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-129 · 2026-11-20

| | |
|---|---|
| Ključ / slug | `american_hairless_terrier` / `american-hairless-terrier` (SL `ameriski-goli-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1200 — v izbirniku za norveškim losovcem |
| Vir podatkov | `docs/research/dog-data/data.json` → `american_hairless_terrier` (viri S967–S979; ponovno S10, S11, S14, S24, S35, S45, S174, S771); seznam za vzreditelja: `listed` (AHTCA K43) |
| Izvor | ZDA (Louisiana, iz dlakavih rat terierjev — UKC S968); AKC skupina terierjev od 1. 1. 2016 (S969), UKC od 2004 (S968), Dogs Australia skupina 2 (S976). **Ni priznan pri FCI** (ni na seznamu začasno priznanih pasem S979) → v registru brez podatka FCI |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 125. mesto »American Hairless Terrier« (S174, vrstica 129 čakalne vrste) |
| PR-ji | pet-prep #1431 (pasma, združen `7bf2778`) · portret pet-prep (zagon `breed-portraits.yml` 44651807133, sprejet v prvem poskusu) · barvne različice: zagon `breed-portraits.yml` 44653324521, vse 3 odobrene v prvem poskusu · spletna stran: pet-prep-website `feat/register-american-hairless-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-20 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **20 min/dan → 2.000 korakov**; starejši 15 min → 1.500; mladiček 10 min × starost do 20 (že pri 2 mesecih, ob prihodu) | Dogs NSW S973 »Exercise: 20 min/day« (ena številka N → N). Strani RKC / PDSA za to pasmo ne obstajajo; PetMD S971 in WebMD S972 (»moderate exercise needs«) minut ne navajata | #1431 |
| 2026-11-20 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11, PetMD S10 | #1431 |
| 2026-11-20 | **predlog Claude — čaka Davida** | starejši od **126. meseca** (tudi prihod starejšega psa); `verified: false` | najbližja vrstica pravila: 0,75 × spodnja meja PetMD »14–16 years« (S971; WebMD S972 enako). McMillan 2024 pasmo uporabi le kot zunanjo skupino (S771), mediane VetCompass ni, strani RKC ni. Alternativa: Dogs Victoria S974 »12 to 14 years« → 108 | #1431 |
| 2026-11-20 | pravilo runbooka | učenje **1,0×** | pasma ni v Corenovi lestvici (S35, ponovno prebrano 2026-11-20) → pravilo »unranked« | #1431 |
| 2026-11-20 | pravilo runbooka | teža **5,4–12,7 kg** (12–28 lb, oba spola), višina 30,5–40,6 cm, rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | PetMD S971 »… weighing between 12 and 28 pounds« (standarda teže ne navajata); standard S967 »Ideal height is from 12 to 16 inches at the withers.«; PetMD S10 (razred »Small«) | #1431 |
| 2026-11-20 | pravilo runbooka (M5-R13) | **brez** česanja | gola pasma, vir ne navaja pogostosti: PetMD S971 »… don't require the grooming routine many other dog breeds have.« | #1431 |
| 2026-11-20 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S971 »Begin socializing your American Hairless Terrier puppy as soon as you bring them home.« | #1431 |
| 2026-11-20 | pravila »Breed care tasks« (David 2026-10-24) | **ščetkanje zob vsak dan** (otrok) | WebMD S972 »… brush their teeth daily …« | #1431 |
| 2026-11-20 | pravila »Breed care tasks« (David 2026-10-24) | **striženje krempljev vsak teden** (otrok) | AHTCA S977 »Weekly nail trimming with a Dremel will help you maintain healthy nails.« (WebMD »on a regular basis« — brez števila) | #1431 |
| 2026-11-20 | pravila »Breed care tasks« (David 2026-10-24) | **opomnik za kopanje vsak teden** (starš, ne šteje v oceno) | PetMD S971 »… need regular baths, preferably every week.« Ušesa (»ask your vet how often«) in frizer (ni vira) → brez naloge | #1431 |
| 2026-11-20 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« | ni citata o premoru po obroku | #1431 |
| 2026-11-20 | pravilo runbooka (M5-R14) | brez poletnega opozorila za sprehod | ni ploskoobrazna pasma | #1431 |
| 2026-11-20 | pravilo runbooka | oznake: primeren za **»družino z otroki«**; upoštevajte **»gola koža — potrebuje zaščito pred soncem«**, **»tanka dlaka — občutljiv na mraz«**, **»potrebuje miselne izzive«**, **»močan lovski nagon — lahko lovi manjše živali«** (zadnja) | PetMD S971 »These dogs can be great playmates for children …« (nadzor majhnih otrok ostaja v opombi), »They also get cold easily …«, »Regular exercise and mental stimulation are other musts …«; AHTCA S977 »Sunscreen or protective clothing is a must …«, »… as they have a high prey drive.« | #1431 |
| 2026-11-20 | pravilo runbooka | **brez** `small_children` (PetMD: nadzor majhnih otrok; Dogs NSW: majhen pes se ob grobem ravnanju poškoduje), `family_pet` (ni izjave »družinski pes«), `apartment` (le iz RKC), `other_pets` (viri si nasprotujejo), `needs_company`, `secure_fencing`, `long_daily_exercise`; **nikoli »hypoallergenic«** — pasmo drugi oglašujejo za alergike (S969, S972), PetMD S971: »there's really no such thing« | S971–S974, S977 | #1431 |
| 2026-11-20 | pravilo runbooka (§3, dobrobit) | brez opozorilnega čipa; **v aplikaciji da, v marketingu da** — nikoli kot »hipoalergen« ali »pes za alergike«, nikoli opečen ali premražen | nobeno veterinarsko združenje ne odsvetuje oglaševanja te pasme (S92 le ploskoobrazne pasme); pasma ni v RKC (brez Breed Watch) | #1431 |
| 2026-11-20 | pravilo runbooka (§3) | AI videz: gola različica, naravni (nikoli kupiran) rep, pokončna V-ušesa; 4 barve kože za izbirnik: **rožnata s črnimi pegami** (privzeta, portret), rožnata s čokoladnimi pegami, temno siva z rožnatimi lisami, rožnata z rjavkastimi pegami; uteži brez vira → `verified: false` | UKC S968 »The skin is usually parti-colored …«; standard S967 »Any color or combination of colors is allowed with the exception of albino or merle.«, »The tail on the hairless variety should never be docked.« Besedilo štirih barv je naše (iz barv UKC) | #1431 |
| 2026-11-20 | pravilo runbooka (§2.3) | seznam za vzreditelja: 6 testov ameriškega pasemskega kluba (CHIC): srce, kolki (OFA / PennHIP), pogačica, Legg-Calvé-Perthes (**nov ključ** `legg_perthes_scheme`), gluhost, primarna luksacija leče | AHTCA K43 »Recommended test required to receive a CHIC:« | #1431 |
| 2026-11-20 | pravilo runbooka | zdravstveni ključi registra: `bare_skin_sun_cold`, `kneecap_luxation`, `legg_perthes`, `primary_lens_luxation`, `deafness` (vsi obstoječi); brez odstotkov | AHTCA S977, PetMD S971, WebMD S972 | #1431 |
| 2026-11-20 | predlog Claude, David lahko spremeni | EN »American Hairless Terrier« (AKC), SL »ameriški goli terier«; iskanje tudi »aht«, »hairless terrier«, »ameriski goli terier«, »goli terier« | strani KZS za to pasmo nisem našel | #1431 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva.«, iskanje »aht«, »ameriški goli terier« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-129 | 2026-11-20 |
| Pravila po starosti (2.000 korakov od prihoda, odrasel 2.000, starejši 1.500 od 126. meseca — predlog; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-129 | 2026-11-20 |
| »Nova izkušnja« za mladička, ščetkanje zob vsak dan, striženje krempljev vsak teden, opomnik staršu za tedensko kopanje | 🛠 | 💶 izziv | M5-R17 / R15 / R19 | 2026-11-20 |
| Barve (izbirnik: 4 barve kože) | 🛠 | 💶 izziv | M5-R21 | 2026-11-20 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), gola rožnata koža s črnimi pegami, temnejša glava, pokončna ušesa, naraven rep, na svetlo sivem ozadju | — | M5-R11-04 | 2026-11-20 |
| Barvne različice (3 dodatne barve kože: rožnata s čokoladnimi pegami, temno siva z rožnatimi lisami, rožnata z rjavkastimi pegami) | ✅ AI fotografije, isti pes in poza, spremenjena le barva kože | — | M5-R11-08 | 2026-11-20 |
| Stran na petprep.si (`/animals/dogs/american-hairless-terrier`, `/sl/zivali/psi/ameriski-goli-terier`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal ameriškega golega terierja — majhnega, živahnega terierja brez dlake iz Louisiane, 125. najbolj priljubljeno pasmo po AKC 2024 in 120. pasmo psa v aplikaciji (poleg mešančka). 13 novih virov (S967–S979), med njimi uradni standard, standard UKC, pasemski klub AHTCA in avstralski kinološki zvezi. Odrasel pes v igri potrebuje 2.000 korakov na dan (Dogs NSW: 20 minut), otrok mu vsak dan ščetka zobe in vsak teden pristriže kremplje, starš dobi tedenski opomnik za kopanje.
**Zakaj je pomembno:** pes brez dlake ni pes brez dela — gola koža potrebuje zaščito pred soncem in mrazom, kopanje in vlaženje, pes pa ima močan lovski nagon. Pasmo pogosto oglašujejo kot »hipoalergeno«; PetMD pravi, da takega psa v resnici ni, zato je PetPrep nikoli ne označi tako.
**Kako povedati** — starši: »Ameriški goli terier ne pušča dlake po stanovanju, a terja svojo skrb: kremo za sončenje ali majico na soncu, pulover v mrazu, tedensko kopanje, vsak dan zobe in vsak teden kremplje. V PetPrepu boste videli, ali otrok zmore te drobne vsakodnevne naloge 12 tednov zapored.« · otroci: »Ameriški goli terier je radoveden terier brez dlake s pegasto kožo. Z njim greš vsak dan na kratek sprehod, mu ščetkaš zobke in pristrižeš krempeljčke.« · vlagatelji / partnerji: »120. pasma psa, ki jo je nočni zagon sam izbral po javni lestvici AKC in dodal z viri — tudi za pasmo, ki je FCI in britanski klub ne priznavata, z jasno označenim predlogom za odločitev in brez zavajajoče oznake ›hipoalergen‹.«

## Odprto za Davida

- **Starejše obdobje (126. mesec):** predlog Claude — 0,75 × spodnja meja PetMD 14 let; vrstica je `verified: false`. Dogs Victoria (S974) navaja 12–14 let → 108. Potrdite ali določite drugo mejo.
- **Gibanje 20 min:** po pravilu iz Dogs NSW (S973 »20 min/day«); PetMD (S971) pasmo opiše kot »high-energy« in zahteva »regular exercise and mental stimulation« brez minut — 20 minut je morda malo. Potrdite ali spremenite.
- **Zaščita pred soncem in mrazom:** vir zahteva kremo za sončenje / zaščitna oblačila (AHTCA S977, PetMD S971) in pulover v mrazu (PetMD S971, UKC S968), a brez pogostosti — igra take mehanike nima (le čipa »gola koža — potrebuje zaščito pred soncem« in »občutljiv na mraz«). **Potrebna je nova naloga v ROADMAP — David** (kot pri kitajskem čopastem psu in xoloitzcuintliju).
- **`small_children`:** WebMD (S972) piše »fantastic with young children«, PetMD (S971) in Dogs Victoria (S974) pa zahtevata nadzor otrok, Dogs NSW (S973) opozarja na poškodbe ob grobem ravnanju → po pravilu brez čipa. Odločite, ali pasma dobi čip.
- **Bolezni srca:** PetMD (S971) jih omenja, AHTCA zahteva pregled srca — register nima natančnega ključa, zato ostane le v raziskavi in na seznamu za vzreditelja (`cardiac_exam`).
- **Nov ključ seznama za vzreditelja** `legg_perthes_scheme`: besedilo na spletni strani (vsi jeziki strani) še manjka — pride s PR-jem spletne strani.
- **Ime:** »ameriški goli terier« ni preverjen na straneh KZS. Potrdite ali popravite.
- **Standard AKC:** besedilo uradnega standarda (S967) je iz ponatisa revije Showsight (stran ne navaja telesa; enako besedilo pri Dogs Australia S976) — izvirnik AKC ni bil dosegljiv.
- **Test druge pasme:** zagon je moral dopolniti pričakovani seznam iskanja »hairless« v testu kitajskega čopastega psa (`mobile/src/modules/petProfile/__tests__/chineseCrested.breed.test.tsx`), ker ključ te pasme vsebuje besedo »hairless« (enako je pred tem storil zagon xoloitzcuintlija). Bolje bi bilo, da test preverja le, ali seznam vsebuje obe pasmi — odločite.
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
