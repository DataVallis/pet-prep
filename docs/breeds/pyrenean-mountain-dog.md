# Pyrenean Mountain Dog (pirenejski gorski pes)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-78 · 2026-10-29

| | |
|---|---|
| Ključ / slug | `pyrenean_mountain_dog` / `pyrenean-mountain-dog` (SL `pirenejski-gorski-pes`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 720 — v izbirniku za pritlikavim pinčem |
| Vir podatkov | `docs/research/dog-data/data.json` → `pyrenean_mountain_dog` (viri S599–S607; ponovno S8, S10, S11, S14, S24, S35, S45, S92, S174); seznam za vzreditelja: stran RKC (S600) |
| Izvor | FCI št. 137 »Pyrenean Mountain Dog« (Chien de Montagne des Pyrénées), skupina 2, sekcija 2.2 (molosi, gorski tip), Francija (S599); v ZDA (AKC) »Great Pyrenees« |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 74. mesto »Great Pyrenees« (S174, prebrano 2026-10-29) |
| PR-ji | pet-prep #439 (pasma, združen `5c20348`) · portret: #441 (zagon 40833543405) · spletna stran: pet-prep-website `feat/register-pyrenean-mountain-dog` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-29 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S600 »Exercise: Up to 1 hour per day« (»up to N« → N); PDSA S603 »Minimum exercise (per day): 1 hour« enako | #439 |
| 2026-10-29 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 (za vse pse enako) | #439 |
| 2026-10-29 | **predlog Claude — čaka Davida** (`verified=false`) | starejši od **90. meseca** (tudi starost ob prihodu starejšega psa) | 0,75 × spodnja meja PetMD »10–12 years« (S604; VCA S605 enako) = 90. RKC in PDSA navajata »Under 10 years« (brez spodnje meje); tabela Dogs Trust McMillan 2024 (S123) ni bila dosegljiva, mediane VetCompass ni. Alternativi: McMillan 2024 10,9 leta le prek Wikipedije (S606, raven C) → 98; Dogs Trust »> 7 let« → 84 | #439 |
| 2026-10-29 | pravilo runbooka | učenje **0,7×** (Coren 64. mesto, »zadovoljivi«) | S35 (prebrano 2026-10-29) | #439 |
| 2026-10-29 | pravilo runbooka | teža **40–50 kg** (oba spola), višina samci 70–80 cm, samice 65–75 cm, rast do 18–24 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S603 (standard FCI teže ne navaja); višina standard FCI S599; standard RKC S601: najmanj 50 / 40 kg; PetMD S10 (razred »Giant«) | #439 |
| 2026-10-29 | **predlog Claude — čaka Davida** (`verified=false`, M5-R13) | česanje **2× na teden**; skuštrana dlaka po 1 zamujenem | RKC S600 »Grooming: More than once a week« (brez števila). Dobesedno pravilo bi vzelo »weekly« iz VCA S605 / PetMD S604 = 1, kar nasprotuje RKC (»več kot enkrat«) in čipu »potrebuje česanje večkrat na teden«; PetMD: »more frequent grooming can help« (močno izpadanje dlake). Precedens: belgijski ovčar malinois | #439 |
| 2026-10-29 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | VCA S605 »Early socialization is very important« | #439 |
| 2026-10-29 | David 2026-10-12 (M5-R16) | opozorilo »počakaj s sprehodom« **60 min** po obroku (samo opozorilo, koraki štejejo) | PetMD S604 »Avoid vigorous exercise around mealtimes« (napenjanje želodca / GDV) | #439 |
| 2026-10-29 | pravila »Breed care tasks« (David 2026-10-24, M5-R19 / R20) | otrokova mini igra **striženje krempljev 1× na teden** in **čiščenje ušes 1× na teden** | VCA S605 »weekly pedicure and ear cleaning«. Zobje, kopanje, frizer: brez frekvence → brez naloge; ni plosko-obrazna pasma → brez pravila za vročino | #439 |
| 2026-10-29 | pravilo runbooka | oznake: primeren za »družino z otroki« (`children` — z opombo PetMD: najbolje brez majhnih otrok), »veliko hišo z vrtom« (`large_home`), »dom z drugimi ljubljenčki« (`other_pets`); upoštevajte »velik pes — ni za dom z majhnimi otroki« (`not_for_small_children`), »izpada mu dlaka« (`sheds`), »potrebuje česanje večkrat na teden« (`frequent_grooming`), »krajša življenjska doba« (`shorter_lifespan`) | VCA S605 »devoted to his family – especially to children«; PetMD S604 »may be best in homes without smaller children«, »live well with other pets«; RKC S600 »Large house« / »Large garden«, »Sheds: Yes«, »More than once a week«, »Under 10 years«; PDSA S603 »Under 10 years« | #439 |
| 2026-10-29 | pravilo runbooka | **brez** `family_pet`, `small_children`, `apartment`, `active_family`, `long_daily_exercise`, `chases_small_pets`, `experienced_owner`, `drools`, `often_alone`; nikoli »hypoallergenic« | ni izjave »družinski pes«; do 1 ure gibanja; ni izjave o lovskem nagonu ali o izkušenem lastniku | #439 |
| 2026-10-29 | pravilo runbooka (§3) | **brez čipa dobrobiti** (`hips_hind_legs`); marketing: **v aplikaciji da, v marketingu da** (nikoli kot pes za majhno stanovanje ali dom z majhnimi otroki, nikoli »hypoallergenic«) | strani RKC si nasprotujeta: stran pasme S600 »Breed Watch Category 1«, stran Breed Watch S602 »Category 2« (preveč debel; nepravilna zadnja okončina) → glej Odprto; BVA S92 ne velja (ni plosko-obrazna pasma) | #439 |
| 2026-10-29 | pravilo runbooka (§3) | barve za AI in izbirnik (5): **bel** (portret), bel z jazbečastimi, sivimi, bledo rumenimi ali oranžnimi lisami na glavi in ušesih; dvojni zadnji krempeljci; nikoli črn, tigrast ali merle | standard FCI S599 »White or white with patches appearing grey (badger or wolf) or pale yellow or orange (arrouye)«; RKC S601 in AKC S607 enako; uteži barv brez vira → `verified: false`; barva portreta je predlog Claude | #439 |
| 2026-10-29 | predlog Claude, David lahko spremeni | EN »Pyrenean Mountain Dog« (FCI / RKC), SL »Pirenejski gorski pes«; iskanje »great pyrenees«, »pyrenees«, »patou«, »pirenejski pes«, »pirenejec« … | dobeseden prevod imena FCI; slovenskega vira (KZS) za ime ni bilo mogoče preveriti | #439 |
| 2026-10-29 | pravilo runbooka (§2.3) | seznam za vzreditelja: genetska raznolikost (minimum); kolki, komolci, oči — sheme BVA/KC (best practice) | stran RKC S600 | #439 |
| 2026-10-29 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia_arthritis`, `bloat_gdv`, `deafness`, `epilepsy`, `panosteitis`, `kneecap_luxation`, `entropion`, `certain_cancers`; brez odstotkov, brez novega ključa | PDSA S603, PetMD S604 | #439 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »pirenejski gorski pes«, »great pyrenees«, »patou« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-78 | 2026-10-29 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 90. meseca — meja je predlog; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-78 | 2026-10-29 |
| Česanje 2× na teden (predlog), »Nova izkušnja« za mladička, opozorilo 60 min po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 | 2026-10-29 |
| Mini igri striženje krempljev in čiščenje ušes (vsaka 1× na teden) | 🛠 | 💶 izziv | M5-R19 / M5-R20 | 2026-10-29 |
| Barva (izbirnik: 5 standardnih barv, privzeto bel) | 🛠 | 💶 izziv | M5-R21 | 2026-10-29 |
| AI portret v registru (bel, sprejet v prvem poskusu, zagon 40833543405, PR #441) | ✅ | — | M5-R11-04 | 2026-10-29 |
| Stran na petprep.si (`/animals/dogs/pyrenean-mountain-dog`, `/sl/zivali/psi/pirenejski-gorski-pes`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal pirenejskega gorskega psa (v ZDA »Great Pyrenees«) — 74. najbolj priljubljeno pasmo po AKC 2024 in 72. plačljivo pasmo psa. 9 novih virov (S599–S607). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do ene ure gibanja), česanje dvakrat na teden, krempljev in ušes pa se otrok loti enkrat na teden. Po obroku aplikacija opozori, naj s sprehodom počaka, mladiček pa vsak teden dobi novo izkušnjo.
**Zakaj je pomembno:** »Nežni velikan« je miren pes, a zahteva veliko dela: 40–50 kg, gost dvojni kožuh, ki ga je treba redno česati, in veliko hišo z vrtom. RKC in PDSA navajata, da živi manj kot 10 let. PetPrep pokaže, ali otrok zmore redno, tudi manj zabavno skrb — česanje, kremplje, ušesa — preden pride pravi pes.
**Kako povedati** — starši: »Pirenejski gorski pes je po VCA vdan družini, posebej otrokom, a po PetMD najbolje sodi v dom brez majhnih otrok. RKC priporoča veliko hišo in vrt ter česanje večkrat na teden. V PetPrepu boste videli, ali vaš otrok zmore 12 tednov skrbi zapored.« · otroci: »Pirenejski gorski pes je velik, bel in miren. Vsak dan gre na sprehod, rad ima, da ga večkrat na teden počešeš, enkrat na teden pa mu skrajšaš kremplje in očistiš ušesa.« · vlagatelji / partnerji: »Dvainsedemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Meja starejšega psa (90. mesec):** predlog Claude (`verified=false`) iz spodnje meje PetMD / VCA »10–12 let«. RKC in PDSA pravita »Under 10 years«; če je mediana McMillan 2024 res 10,9 leta (Wikipedija), bi bila meja 98. Potrdite ali spremenite (Filament ali podatkovna migracija — sejalnik samo dodaja).
- **Česanje 2× na teden:** predlog Claude (`verified=false`) — RKC »More than once a week« brez števila; dobesedno pravilo runbooka bi dalo 1 (VCA / PetMD »weekly«), kar bi nasprotovalo RKC in čipu »potrebuje česanje večkrat na teden«. Potrdite 2 ali izberite drugo število.
- **Breed Watch:** stran pasme RKC (S600) pravi »Category 1«, stran Breed Watch (S602) »Category 2« z dvema točkama (preveč debel; nepravilna zadnja okončina / slabo gibanje zadaj). Pri rotvajlerju in nemški dogi je taka točka dala čip `hips_hind_legs`. Želite čip?
- **Entropij (zavihane veke):** le na zdravstvenem seznamu PDSA, ne kot točka Breed Watch → brez čipa `eyelid_problems`.
- **Ime:** »Pirenejski gorski pes« ni preverjeno pri KZS (»planšarski« je izraz za švicarske Sennenhunde, zato ni uporabljen).
- **Barva portreta:** bel je predlog Claude (vsi standardi ga navajajo prvega). Portret: zagon 40833543405 (koda `5c20348`, brez produkcijske namestitve, 0,15 $), sprejet v prvem poskusu — realistična fotografija odraslega psa, čisto bela gosta dlaka z ovratnikom, črn nos, jantarno rjave oči, majhna ušesa ob glavi, dolg košat rep nizko, svetlo siva studijska podlaga. PR #441.
