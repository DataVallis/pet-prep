# German Wirehaired Pointer (Nemški ostrodlaki ptičar)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-65 · 2026-10-25

| | |
|---|---|
| Ključ / slug | `german_wirehaired_pointer` / `german-wirehaired-pointer` (SL `nemski-ostrodlaki-pticar`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 610 — v izbirniku za cardiganom |
| Vir podatkov | `docs/research/dog-data/data.json` → `german_wirehaired_pointer` (viri S519–S526; ponovno S8, S10, S11, S14, S24, S35, S45, S174 in PDSA S188 — ena stran za obe različici nemškega ptičarja); seznam za vzreditelja: 4 testi »Best Practice« (RKC S521) |
| Izvor | FCI št. 98 »Deutsch Drahthaar«, skupina 7, sekcija 1.1 (celinski ptičarji, tip »braque«), Nemčija (S519) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 61. mesto »German Wirehaired Pointer« (S174, prebrano 2026-10-25) |
| PR-ji | pet-prep #382 (pasma, združen `4670d9e`) · portret: #383 (zagon 40174556521) · spletna stran: pet-prep-website `feat/register-akita-cardigan-gwp` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-25 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | RKC S521 »Exercise: More than 2 hours per day« (»more than N« → N); PDSA S188 »over 2 hours ideally per day« se ujema | #382 |
| 2026-10-25 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90** mesecev; prihod 2 / 9 / 36 / 90 | AAHA S11; starejši = 0,75 × spodnja meja RKC »Over 10 years« (S521; PDSA S188 enako). McMillan 2024 (13 let) le prek Wikipedije (S526, tier C) → ne uporabljeno | #382 |
| 2026-10-25 | pravilo runbooka | učenje **1,0×** (Coren rang 44, skupina »Average«) | S35 | #382 |
| 2026-10-25 | pravilo runbooka | teža psi 25–34 kg, psice 20,5–29 kg (igra 20,5–34 kg), višina psi 60–67 cm, psice 56–62 cm, rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | standard RKC S522 (FCI S520 in AKC S523 ne navajata teže); PetMD S10 (razred »Large«) | #382 |
| 2026-10-25 | pravilo runbooka (M5-R13) | česanje **2× na teden** | RKC S521 »Grooming: More than once a week« nima števila → PDSA S188 »once or twice a week« → zgornja meja 2 (isti citat in vrednost, ki ju je David potrdil za kratkodlakega ptičarja 2026-10-12) | #382 |
| 2026-10-25 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S524 »They can make great family dogs when trained and socialized from an early age.« | #382 |
| 2026-10-25 | pravilo »Breed care tasks« (David 2026-10-24, M5-R20) | otrokova mini-igra **čiščenje ušes 1× na 4 tedne** | PetMD S524 »Regularly (once a month, or more frequently as instructed by your veterinarian) clean your dog's ears« (mesec = 4 programski tedni) | #382 |
| 2026-10-25 | predlog Claude — čaka Davida | **brez** opomnika za trimanje dlake (»stripping«) | PetMD S524: dvakrat na leto — obdobje je daljše od 12-tedenskega izziva in od najdaljšega obdobja nalog (3 mesece) | #382 |
| 2026-10-25 | pravilo runbooka | **brez** opomnika za kopanje in **brez** opozorila po obroku | PetMD: »rarely needs a bath« (brez pogostosti); PDSA S188 opiše GDV, a ne svetuje premora po obroku | #382 |
| 2026-10-25 | pravilo runbooka | oznake »primeren za«: aktivno družino (`active_family`), družinsko življenje (`family_pet`), družino z otroki (`children`), veliko hišo z vrtom (`large_home`); »upoštevajte«: veliko gibanja (`long_daily_exercise`), miselni izzivi (`needs_mental_stimulation`), rad ima družbo (`needs_company`), izpada mu dlaka (`sheds`), lahko lovi manjše živali (`chases_small_pets`) | RKC S521, PDSA S188; PetMD S524: »great family dogs …«, »do well with children who have been taught how to interact with animals« (z nadzorom), »lots of exercise and mental stimulation«, »never be left alone for long periods«, »high prey drive« | #382 |
| 2026-10-25 | pravilo runbooka | **brez** oznak `small_children`, `other_pets`, `apartment`, `frequent_grooming`, `experienced_owner`, `shorter_lifespan`; brez nove oznake | nadzor otrok; mačke / male živali; RKC »Large house«; RKC »več kot enkrat« proti PDSA »enkrat ali dvakrat« (precedens bretonski ptičar); AKC S525 »challenging for new dog owners« se ne ujema z besedilom oznake »močan pes«; »Over 10 years« | #382 |
| 2026-10-25 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | RKC Breed Watch kategorija 1 (S521); PDSA-jeve možne težave (displazija kolkov, GDV, entropij, atopija …) ostanejo v raziskavi | #382 |
| 2026-10-25 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli z obljubo o zdravju ali s kupiranim repom kot ciljem | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #382 |
| 2026-10-25 | pravilo runbooka (§3) | barve za AI in izbirnik: **rjavo-sivkasta z rjavimi lisami** (portret), rjavo-bela, enobarvno rjava — le barve, ki jih sprejmejo vsi trije standardi; nikoli črna, enobarvno črna ali trobarvna; naraven, nekupiran rep | RKC S522 »Liver and white, solid liver, black and white. Solid black and tricoloured highly undesirable.«; FCI S520 (rjavo-sivkasta = »brown roan«); AKC S523 »Any black in the coat is to be severely penalized.« Uteži barv brez vira → `verified: false`; barva portreta je predlog Claude | #382 |
| 2026-10-25 | predlog Claude, David lahko spremeni | ime SL »nemški ostrodlaki ptičar«; iskanje »drahthaar«, »drahtar«, »gwp« …; »ptičar« / »pointer« najdeta tudi to pasmo | slovenskega vira (KZS) za ime ni bilo mogoče preveriti | #382 |
| 2026-10-25 | pravilo runbooka (§2.3) | seznam za vzreditelja: vWD2 (DNK, nov ključ `dna_vwd2`), komolci BVA/KC, oči BVA/KC/ISDS, kolki BVA/KC — vsi »Best Practice« | RKC S521 | #382 |
| 2026-10-25 | pravilo runbooka | zdravstveni ključi registra (kot kratkodlaki ptičar + ščitnica): `hip_dysplasia_arthritis`, `bloat_gdv`, `bleeding_disorder_vwd`, `heart_muscle_disease`, `itchy_skin_allergy`, `entropion_cherry_eye`, `hypothyroidism`; brez odstotkov | PDSA S188, PetMD S524 | #382 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »drahthaar«, »gwp«, »nemški ostrodlaki ptičar« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-65 | 2026-10-25 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90. meseca; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-65 | 2026-10-25 |
| Česanje 2× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-25 |
| Čiščenje ušes 1× na 4 tedne (otrokova mini-igra; za nove pse, obstoječi po posodobitvi aplikacije) | 🛠 | 💶 izziv | M5-R20 | 2026-10-25 |
| Barva (izbirnik: rjavo-sivkast, rjavo-bel, rjav) | 🛠 | 💶 izziv | M5-R21 | 2026-10-25 |
| AI portret v registru (jetrno-sivkast, sprejet v prvem poskusu, zagon 40174556521, PR #383; prvi poskus 40164191744 zavrnjen zaradi `composer.lock`) | ✅ | — | M5-R11-04 | 2026-10-25 |
| Stran na petprep.si (`/animals/dogs/german-wirehaired-pointer`, `/sl/zivali/psi/nemski-ostrodlaki-pticar`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal nemškega ostrodlakega ptičarja (drahthaarja) — 61. najbolj priljubljeno pasmo po AKC 2024 in 61. plačljivo pasmo psa. 8 novih virov (S519–S526). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot dve uri gibanja), starejši 9.000 od 7,5 leta. Česanje dvakrat na teden, socializacija mladička in mesečno čiščenje ušes so del izziva od prvega dne.
**Zakaj je pomembno:** Drahthaar je vsestranski lovski pes z žimasto dlako, brado in obrvmi — prijazen do družine in otrok, ki znajo ravnati s psom, a z močnim lovskim nagonom in veliko potrebo po gibanju in družbi. Dve uri gibanja na dan sta za družino velika obveza; PetPrep pokaže, ali jo zdrži 12 tednov.
**Kako povedati** — starši: »Nemški ostrodlaki ptičar potrebuje več kot dve uri gibanja na dan, miselne izzive in nekoga doma večino dneva; z mačkami in malimi živalmi je lahko težko. V PetPrepu boste videli, ali vaš otrok zmore 12.000 korakov na dan 12 tednov zapored.« · otroci: »Drahthaar je lovski pes z brado in kosmatimi obrvmi. Rad teče, zato greš z njim vsak dan dolgo ven, dvakrat na teden ga počešeš in enkrat na mesec mu očistiš ušesa.« · vlagatelji / partnerji: »Enainšestdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Meja starejšega psa (90 mesecev, pravilo runbooka, začasno):** iz spodnje meje RKC »Over 10 years«. McMillan 2024 (13 let, zdaj le prek Wikipedije S526) bi dal 117 — če ga potrdite v viru A/B, podatkovna migracija ali urejanje v Filamentu.
- **Trimanje dlake dvakrat na leto (PetMD S524):** ni zgrajeno — obdobje pol leta presega 12-tedenski izziv in najdaljše obdobje nalog (3 mesece). Želite opomnik (npr. enkrat med izzivom) ali le dejstvo v registru? Potrebuje novo nalogo v ROADMAP (David).
- **Oznaka »česanje večkrat na teden«:** ni podeljena (RKC »More than once a week« proti PDSA »once or twice a week«) — želite `frequent_grooming`?
- **Izkušen lastnik:** AKC S525 pravi, da je pasma lahko zahtevna za nove lastnike; nobena obstoječa oznaka se ne ujema (»močan pes« / »samostojen in trmast«) — želite novo oznako?
- **Barve:** črno-sivkasto (»black roan«, FCI) in črno-belo (RKC) sta izpuščeni, ker AKC črno strogo kaznuje; portret rjavo-sivkast = predlog Claude.
- **Rep:** igra riše naraven, nekupiran rep (RKC opisuje tudi nekupiranega; FCI dovoli naravnega, kjer je kupiranje prepovedano).
- **Ime:** »nemški ostrodlaki ptičar« ni preverjeno pri KZS.
- **Deljena stran PDSA:** citati PDSA (česanje, gibanje, zdravje) veljajo za kratkodlako in ostrodlako različico skupaj.
