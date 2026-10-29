# Miniature Pinscher (pritlikavi pinč)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-77 · 2026-10-29

| | |
|---|---|
| Ključ / slug | `miniature_pinscher` / `miniature-pinscher` (SL `pritlikavi-pinc`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 710 — v izbirniku za bulterierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `miniature_pinscher` (viri S594–S598; ponovno S8, S10, S11, S14, S24, S35, S45, S92, S174); seznam za vzreditelja: test ameriškega pasemskega kluba (AKC K36) |
| Izvor | FCI št. 185 »Miniature Pinscher« (Zwergpinscher), skupina 2, sekcija 1 (pinči in šnavcerji), Nemčija (S594) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 73. mesto »Miniature Pinscher« (S174, prebrano 2026-10-29) |
| PR-ji | pet-prep #437 (pasma, združen `d30eb73`) · portret: #438 (zagon 40810238687) · spletna stran: pet-prep-website `feat/register-miniature-pinscher` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-29 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **30 min/dan → 3.000 korakov**; starejši 23 min → 2.300; mladiček 10 min × starost do 30 (pri 3 mesecih) | PDSA S595 »Minimum exercise (per day): 30 minutes« (»minimum N« → N; besedilo PDSA »up to half an hour« da isto število); stran RKC ni bila dosegljiva (pravilo RKC → PDSA) | #437 |
| 2026-10-29 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 (za vse pse enako) | #437 |
| 2026-10-29 | **predlog Claude — čaka Davida** (`verified=false`) | starejši od **108. meseca** (tudi starost ob prihodu starejšega psa) | 0,75 × spodnja meja PDSA »Over 12 years« (S595) = 108. Pravilo runbooka (McMillan 2024 → VetCompass → spodnja meja RKC) v tem zagonu ni dalo vrednosti: tabela Dogs Trust (S123) in stran RKC nista bili dosegljivi. Precedens: bulterier. Alternative: McMillan 2024 13,7 leta le prek Wikipedije (S598, raven C) → 123; Dogs Trust »> 7 let« → 84 | #437 |
| 2026-10-29 | pravilo runbooka | učenje **1,2×** (Coren 37. mesto, »nadpovprečni«) | S35 (prebrano 2026-10-29) | #437 |
| 2026-10-29 | pravilo runbooka | teža **4–6 kg** (oba spola), višina 25–30 cm, rast do 8–12 mesecev; hrana, voda in čiščenje kot pri border collieju | standard FCI S594; PDSA 3,5–4,5 kg in VCA 8–10 lb kot alternativi; PetMD S10 (razred »Toy«) | #437 |
| 2026-10-29 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | PDSA S595 »… with a brush once a week usually enough …«; PetMD S596 in VCA S597 enako | #437 |
| 2026-10-29 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S596 »… they can make excellent family pets with the right socialization and training.« | #437 |
| 2026-10-29 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« | noben vir ne omenja premora po obroku | #437 |
| 2026-10-29 | pravila »Breed care tasks« (David 2026-10-24) | **brez** nalog zob, ušes, krempljev, kopanja, frizerja ali obraza; brez opozorila za vročino | PetMD: ušesa »redno« (brez frekvence); tedensko brisanje z vlažno krpo nadomešča kopanje in ni kopel; ni plosko-obrazna pasma | #437 |
| 2026-10-29 | pravilo runbooka | oznake: primeren za »družinsko življenje« (`family_pet`) in »družino z otroki« (`children` — z opombo VCA: zelo majhni ali neukrotljivi otroci ga lahko poškodujejo pri grobi igri); upoštevajte »majhen pes — bolje za starejše otroke« (`older_children`) in »tanka dlaka — občutljiv na mraz« (`cold_sensitive`) | PetMD S596 »excellent family pets with the right socialization and training«; VCA S597 »Playful and very good with children, although very young or unruly children may hurt it with rough play.«, »Its thin coat and small size makes it vulnerable to chilling.« | #437 |
| 2026-10-29 | pravilo runbooka | **brez** `small_children`, `apartment`, `large_home`, `other_pets`, `chases_small_pets`, `needs_company`, `sheds`, `food_motivated_weight`, `long_daily_exercise`; nikoli »hypoallergenic« | stran RKC ni bila dosegljiva; VCA le »may not be good with small pets« (ni »močan lovski nagon«); ločitvena tesnoba le na Wikipediji (raven C); VCA navaja nagnjenost k debelosti, ne motivacije s hrano — glej Odprto | #437 |
| 2026-10-29 | pravilo runbooka (§3) | ni pasma z oznako dobrobiti; marketing: **v aplikaciji da, v marketingu da** (nikoli kot igrača, s kupiranimi ušesi ali repom ali za grobo igro z majhnimi otroki) | nobeno veterinarsko združenje pasme ne navaja; ni plosko-obrazna pasma, BVA S92 ne velja | #437 |
| 2026-10-29 | pravilo runbooka (§3) | barve za AI in izbirnik (2): **rdeč** (portret), črn s porjavelimi oznakami; naravna ušesa in rep; nikoli čokoladen, moder, plav (isabella), merle ali bel | standard FCI S594 (»Self coloured: Deer red …« / »Black and Tan«; rep »Natural«); uteži barv brez vira → `verified: false`; barva portreta je predlog Claude | #437 |
| 2026-10-29 | predlog Claude, David lahko spremeni | EN »Miniature Pinscher«, SL »Pritlikavi pinč«; iskanje »min pin«, »zwergpinscher«, »pinč«, »pritlikavi pinc« … | slovenskega vira (KZS) za ime ni bilo mogoče preveriti | #437 |
| 2026-10-29 | pravilo runbooka (§2.3) | seznam za vzreditelja (ameriški pasemski klub, raven »drugo«): pregled pogačice | AKC K36 (stran RKC ni bila dosegljiva → brez ravni »minimum« / »best practice«) | #437 |
| 2026-10-29 | pravilo runbooka | zdravstveni ključi registra: `kneecap_luxation`, `legg_perthes`, `pra_inherited_blindness`, `cataracts`, `bladder_stones`, `epilepsy`, `weight_gain`; brez odstotkov, brez novega ključa | PDSA S595, PetMD S596, VCA S597 | #437 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »pritlikavi pinč«, »min pin« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-77 | 2026-10-29 |
| Pravila po starosti (2.000 → 3.000 korakov pri 3 mesecih, odrasel 3.000, starejši 2.300 od 108. meseca — meja je predlog; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-77 | 2026-10-29 |
| Česanje 1× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-29 |
| Barva (izbirnik: 2 standardni barvi, privzeto rdeč) | 🛠 | 💶 izziv | M5-R21 | 2026-10-29 |
| AI portret v registru (rdeč, sprejet v prvem poskusu, zagon 40810238687, PR #438) | ✅ | — | M5-R11-04 | 2026-10-29 |
| Stran na petprep.si (`/animals/dogs/miniature-pinscher`, `/sl/zivali/psi/pritlikavi-pinc`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal pritlikavega pinča — 73. najbolj priljubljeno pasmo po AKC 2024 in 71. plačljivo pasmo psa. 5 novih virov (S594–S598). Odrasel pes v igri potrebuje 3.000 korakov na dan (PDSA: pol ure gibanja), česanje je enkrat na teden, mladiček pa vsak teden dobi novo izkušnjo. Uči se hitreje od mešanca (Coren 37. mesto).
**Zakaj je pomembno:** Pritlikavi pinč je majhen, a pogumen in živahen pes. Majhnost ne pomeni manj skrbi: potrebuje vsakodnevni sprehod in igro, zaradi tanke dlake ga zebe, pri grobi igri majhnih otrok pa se lahko poškoduje. PetPrep pokaže, ali otrok zmore nežno in redno skrb — preden pride pravi pes.
**Kako povedati** — starši: »Pritlikavi pinč je po PetMD lahko odličen družinski pes, če je pravilno socializiran in vzgojen. VCA opozarja, da ga zelo majhni ali neukrotljivi otroci lahko poškodujejo pri grobi igri in da ga zaradi tanke dlake hitro zebe. V PetPrepu boste videli, ali vaš otrok zmore 12 tednov skrbi zapored.« · otroci: »Pritlikavi pinč je majhen, a pogumen. Vsak dan gre na sprehod, rad se igra in se hitro uči novih ukazov — z njim ravnaj nežno.« · vlagatelji / partnerji: »Enainsedemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Meja starejšega psa (108. mesec):** predlog Claude (`verified=false`) iz spodnje meje PDSA »Over 12 years«. Če je mediana McMillan 2024 res 13,7 leta (Wikipedija), bi bila meja 123. Potrdite ali spremenite (Filament ali podatkovna migracija — sejalnik samo dodaja).
- **Plašček v mrazu — potrebna nova naloga v ROADMAP (David):** VCA (S597) »Its thin coat and small size makes it vulnerable to chilling.« Mehanike za oblačila / mraz v igri ni (vremenski API je zunaj obsega) — zaenkrat le čip `cold_sensitive` (precedens: italijanski hrt).
- **Brisanje z vlažno krpo 1× na teden** (PetMD S596, namesto pogostega kopanja): po pravilu »Breed care tasks« ni kopel → brez opomnika staršem. Želite opomnik?
- **Ločitvena tesnoba:** le Wikipedija (raven C) → brez čipa `needs_company`.
- **Stran RKC:** ni bila dosegljiva (preusmeritev na royalkennelclub.com zavrnjena) — ni ključnih dejstev RKC (velikost doma, izguba dlake), ni kategorije Breed Watch in ni testov RKC; gibanje iz PDSA.
- **Ime:** »Pritlikavi pinč« ni preverjeno pri KZS.
- **Barva portreta:** rdeč je predlog Claude (standard dovoli rdečo ali črno s porjavelimi oznakami). Portret: zagon 40810238687 (koda `d30eb73`, brez produkcijske namestitve, 0,15 $), sprejet v prvem poskusu — realistična fotografija odraslega psa, rdeča kratka dlaka, naravna (povešena, visoko nastavljena) ušesa in naraven srpast rep, črn nos, svetlo siva studijska podlaga; postava je nekoliko visoka na nogah (sprejemljivo). PR #438.
