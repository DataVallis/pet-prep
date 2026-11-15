# Afganski hrt (Afghan Hound)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-114 · 2026-11-15

| | |
|---|---|
| Ključ / slug | `afghan_hound` / `afghan-hound` (SL `afganski-hrt`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1070 — v izbirniku za gordonskim setrom |
| Vir podatkov | `docs/research/dog-data/data.json` → `afghan_hound` (viri S848–S854; ponovno S10, S11, S14, S24, S34, S35, S45, S54, S174); seznam za vzreditelja: stran RKC (S850) |
| Izvor | FCI št. 228, skupina 10, sekcija 1 (dolgodlaki hrti), Afganistan; pokroviteljstvo Velika Britanija (S848, S849) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 110. mesto »Afghan Hound« (S174, zapisano 2026-11-14 v vrstici 113) |
| PR-ji | pet-prep #1128 (pasma, združen `42e6bca`) · portret pet-prep PR `feat/M5-R10-114-afghan-hound-portrait` (zagon `breed-portraits.yml` 43660780963, sprejet v prvem poskusu) · spletna stran: pet-prep-website `feat/register-afghan-hound` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-15 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | stran RKC S850 »Exercise: More than 2 hours per day« (»more than N« → N); PDSA S851 (2 uri) in WebMD S853 (»At least 2 hours«) se ujemata; PetMD S852 »at least an hour« (60) = alternativa; 0,75 × 120 = 90 | #1128 |
| 2026-11-15 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 | AAHA S11; starejši = 0,75 × spodnja meja RKC **10 let** (»Over 10 years«, S850; PDSA enako) = 7,5 leta = 90 mesecev. McMillan 2024 (le oznaka na sliki 5, vrednost v dodatni tabeli) / VetCompass ni dosegljiv → tretji vir pravila. Ni izbrano: 84 (Dogs Trust »> 7 let«) | #1128 |
| 2026-11-15 | pravilo runbooka | učenje **0,5×** | Coren: 79. mesto, »Lowest Degree of Working/Obedience Intelligence« (70–79) — tabela »The Intelligence of Dogs« (S35, prebrana prek zrcala); Corenov članek S34: zadnji med desetimi najnižje uvrščenimi | #1128 |
| 2026-11-15 | pravilo runbooka | teža **22,7–27,2 kg** (AKC: psi okoli 60 lb, psice okoli 50 lb — naša pretvorba), višina psi 68–74 cm / psice 63–69 cm (FCI), rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | standard AKC S854 (FCI teže ne navaja); PDSA S851 23–27 kg (alternativa); PetMD S10 (razred »Large«) | #1128 |
| 2026-11-15 | pravilo runbooka (M5-R13) | česanje **vsak dan** (7× na teden); skuštrana dlaka po 4 zamujenih | RKC S850 »Grooming: Every day«; PDSA S851 »… will require daily brushing as their adult coats in particular are prone to matting.« | #1128 |
| 2026-11-15 | David 2026-10-12 (M5-R16) | opozorilo **»počakaj s sprehodom« 60 min po obroku** (koraki se štejejo, brez kazni) | PetMD S852 »It’s important to wait at least an hour after your Afghan Hound eats before letting them exercise«; PDSA S851 in WebMD S853 navajata napihnjenost (GDV) | #1128 |
| 2026-11-15 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PDSA S851 »Afghan Hounds, if well socialised, are good with children.« | #1128 |
| 2026-11-15 | pravila »Breed care tasks« (David 2026-10-24, M5-R15 / R19) | **ščetkanje zob vsak dan** (otrokova mini igra) in opomnik staršem **kopanje vsak teden** (nikoli v točkah) | WebMD S853 »Their teeth should be brushed daily with dog-friendly toothpaste.«; PetMD S852 »Bathing your dog at least once every week or two …« → razpon → spodnja meja = vsak teden. Kremplji (WebMD: preden se dotaknejo tal), ušesa (PetMD: pogosto preverjajte), frizer: brez pogostosti → brez naloge | #1128 |
| 2026-11-15 | pravilo runbooka | brez poletnega opozorila za sprehod | ni ploskoobrazna pasma | #1128 |
| 2026-11-15 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družino z otroki«**, **»veliko hišo z vrtom«**; upoštevajte **»vsak dan potrebuje veliko gibanja«**, »izpada mu dlaka«, »potrebuje česanje večkrat na teden« | RKC S850 »More than 2 hours per day«, »Large house«, »Sheds: Yes«, »Grooming: Every day«; PDSA S851 dnevno česanje in »if well socialised, are good with children« (pogoj ostane v opombah) | #1128 |
| 2026-11-15 | pravilo runbooka | **brez** `chases_small_pets`, `small_children`, `family_pet`, `other_pets`, `needs_company`, `apartment`, `shorter_lifespan`; nikoli »hypoallergenic« | PDSA »strong chase instinct«, PetMD »prey drive nature«, WebMD »not a good fit for houses with small animals« — nobeden ne navaja dobesedno »high / strong prey drive« (pravilo runbooka) → odprto za Davida; PetMD: ob majhnih otrocih nadzor → ne `small_children` | #1128 |
| 2026-11-15 | pravilo runbooka (§3, dobrobit) | RKC Breed Watch **kategorija 1**, brez točk skrbi → brez opozorilnega čipa; **v aplikaciji da, v marketingu da** | S850 »Currently no points of concern specific to this breed …« | #1128 |
| 2026-11-15 | pravilo runbooka (§3) | barve za AI in izbirnik: **sedem pogostih barv iz »vse barve razen merle«** — zlat s črno masko, kremast, rdeč s črno masko, črn, črn z rjavimi oznakami, moder, tigrast; portret **zlat s črno masko**, naraven rep z obročkom, nikoli ostrižen | FCI S849 »All colours, except merle which is unacceptable. However white markings on the head or collar are highly undesirable.«; AKC S854 »All colors are permissible«; izbor in uteži brez vira → `verified: false` | #1128 |
| 2026-11-15 | pravilo runbooka (§2.3) | seznam za vzreditelja: minimum »Prioritising genetic diversity« (`inbreeding_coi`), najboljša praksa shema za kolke (`hip_scheme`); brez novega ključa | stran RKC S850 (»We don't currently offer a breed-specific DNA testing package for this breed.«) | #1128 |
| 2026-11-15 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia`, `bloat_gdv`, `hypothyroidism`, `panosteitis`; brez odstotkov, brez novega ključa (nekrotizirajoča mielopatija, paraliza grla, hilotoraks, občutljivost za anestezijo ostanejo v data.json) | RKC S850, PDSA S851, PetMD S852, WebMD S853 | #1128 |
| 2026-11-15 | pravilo runbooka (§2.2) | nova država izvora **Afganistan** (`AF`) v izvozu registra in besede na spletni strani | FCI S848 »AFGHANISTAN« | #1128 |
| 2026-11-15 | predlog Claude, David lahko spremeni | EN »Afghan Hound«, SL »Afganski hrt«; iskanje tudi »afganec«, »afghan«, »tazi«, »baluchi hound«, »hrt« | slovensko ime po vzoru »ruski hrt« (Borzoi); strani KZS nisem dosegel | #1128 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »afganski hrt«, »afganec«, »tazi« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-114 | 2026-11-15 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90. meseca; 4 → 3 → 2 obroka; učenje 0,5×) | 🛠 | 💶 izziv | M5-R10-114 | 2026-11-15 |
| Česanje vsak dan, »Nova izkušnja« za mladička, opozorilo 60 min po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 | 2026-11-15 |
| Ščetkanje zob vsak dan (otrok), opomnik staršem: kopanje vsak teden | 🛠 | 💶 izziv | M5-R15 / M5-R19 | 2026-11-15 |
| Barva (izbirnik: sedem barv standarda) | 🛠 | 💶 izziv | M5-R21 | 2026-11-15 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), zlat s črno masko, naraven rep z obročkom | — | M5-R11-04 | 2026-11-15 |
| Stran na petprep.si (`/animals/dogs/afghan-hound`, `/sl/zivali/psi/afganski-hrt`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal afganskega hrta — visokega, elegantnega dolgodlakega hrta iz Afganistana; 110. najbolj priljubljeno pasmo po AKC 2024 in 106. pasmo psa v aplikaciji. 7 novih virov (S848–S854). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot dve uri gibanja), česanje vsak dan in ščetkanje zob vsak dan; po obroku aplikacija eno uro opozarja, naj s sprehodom počaka; starši dobijo opomnik za kopanje vsak teden.
**Zakaj je pomembno:** Afganski hrt je dostojanstven in samosvoj (FCI), z dolgo svilnato dlako, ki se hitro zaplete (PDSA), in z močnim nagonom za lovljenje. Odpoklic in osnovno šolanje »ne bosta vedno lahka« (PDSA) — zato v igri uči počasneje (0,5×). Prav to PetPrep preizkusi: dve uri gibanja, vsakodnevna nega dlake in zob ter potrpežljivo učenje, dvanajst tednov zapored.
**Kako povedati** — starši: »Afganski hrt je čudovit, a zahteven pes: več kot dve uri gibanja na dan, česanje vsak dan, kopanje vsak teden in veliko potrpežljivosti pri učenju. V PetPrepu boste videli, ali vaš otrok zmore tak ritem.« · otroci: »Afganski hrt ima dolgo, mehko dlako in zelo hitro teče — vsak dan greš z njim na dolg sprehod, ga počešeš in mu umiješ zobe, po hrani pa malo počakata. Uči se počasi, zato bodi potrpežljiv.« · vlagatelji / partnerji: »106. pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, nalogami nege, opozorilom po obroku, novo državo izvora in testi za vzreditelje.«

## Odprto za Davida

- **Lovski nagon:** PDSA »They do have a strong chase instinct, so be aware of this if they are around other pets at home«, PetMD »prey drive nature«, WebMD »not a good fit for houses with small animals such as rabbits or guinea pigs« — pravilo zahteva dobeseden »high / strong prey drive«, zato čipa »močan lovski nagon« ni. Za hrta je to verjetno pomembno: povejte, če naj ga dodam.
- **Kopanje:** PetMD »at least once every week or two« → vsak teden (spodnja meja razpona); WebMD »bathed and groomed twice a week« bi dal 2× na teden. Povejte, če želite drugače.
- **Življenjska doba / starejše obdobje:** uporabljena je spodnja meja RKC 10 let → starejši od 90. meseca. McMillan 2024 pasmo vsebuje (slika 5), vrednost je v dodatni tabeli 3; tabela Dogs Trust (S123) ni bila dosegljiva — preverite.
- **Teža:** standard FCI teže ne navaja; uporabljen je AKC (okoli 60 / 50 lb = 27,2 / 22,7 kg, naša pretvorba). PDSA 23–27 kg je alternativa.
- **Barve:** standard dovoljuje vse barve razen merle; izbor sedmih barv in njihove uteži sta predlog (brez vira).
- **Ime:** »Afganski hrt« ni preverjen na straneh KZS. Potrdite ali popravite.
- **Standard FCI:** PDF nosi datum 6. 10. 2026 (nomenklatura 10. 6. 2026) — številke so prebrane iz tega PDF-ja.
