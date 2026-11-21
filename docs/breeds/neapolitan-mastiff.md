# Napolitanski mastif (Neapolitan Mastiff)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-132 · 2026-11-21

| | |
|---|---|
| Ključ / slug | `neapolitan_mastiff` / `neapolitan-mastiff` (SL `napolitanski-mastif`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1230 — v izbirniku za ruskim črnim terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `neapolitan_mastiff` (viri S993–S1001; ponovno S10, S11, S14, S24, S35, S45, S92, S174); seznam za vzreditelja: RKC (S994) |
| Izvor | FCI št. 197, skupina 2, sekcija 2.1 (molosi, tip doge), Italija (S993) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 128. mesto »Neopolitan Mastiff« (S174, zapisano 2026-11-20 v vrstici 131: »127 Black Russian Terrier«, »128 Neopolitan Mastiff«) |
| PR-ji | pet-prep #1472 (pasma) · portret pet-prep (sledi) · barvne različice pet-prep (sledijo) · spletna stran: pet-prep-website `feat/register-neapolitan-mastiff` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-21 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S994 »Up to 1 hour per day« (»up to N« → N); PDSA S995 »1 hour« se ujema | #1472 |
| 2026-11-21 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 | #1472 |
| 2026-11-21 | **predlog Claude — čaka Davida** (`verified=false`) | starejši od **74. meseca** (prihod starejšega 74) | RKC in PDSA »Under 10 years« nimata spodnje meje; mediane McMillan 2024 / VetCompass nisem dosegel (tabela Dogs Trust zavrnjena; študija VetCompass o velikanskih pasmah S1001 le kot povzetek brez vrednosti za to pasmo) → najbližja recenzirana vrednost: povprečna starost ob smrti 8,17 leta (anketa lastnikov, Bell & Hesketh 2021, S1000) × 0,75 = 74 mesecev (precedens bernski planšar). Ni izbrano: 63 (PetMD 7 let), 84 (Dogs Trust »> 7 let«) | #1472 |
| 2026-11-21 | pravilo runbooka | učenje **1,0×** | pasme ni na Corenovem seznamu (S35 prebran prek zrcalne kopije, ki nima niti angleškega mastifa → nepreverjeno) → pravilo »unranked« | #1472 |
| 2026-11-21 | pravilo runbooka | teža **samci 60–70 kg, samice 50–60 kg**, višina samci 65–75 cm, samice 60–68 cm (FCI), rast do 18–24 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S993; PDSA S995 50–70 kg; PetMD S10 (razred »Giant«) | #1472 |
| 2026-11-21 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | RKC S994 »Grooming: Once a week«; PDSA S995 »brushing once a week is generally all that’s needed«; PetMD S996 »a few times each week« (brez števila) | #1472 |
| 2026-11-21 | pravila »Breed care tasks« (David 2026-10-24, M5-R14 / R20) | **čiščenje kožnih gub vsak dan** (otrokova mini igra), **kopanje enkrat na mesec** (opomnik staršem) | PDSA S995 »Their skin folds will need daily cleaning.«; PetMD S996 »will need a bath once or twice a month« (razpon → spodnja meja). Ušesa »often«, koža »regularly« (PetMD) → brez naloge; krempljev, zob in frizerja vir ne navaja s pogostostjo | #1472 |
| 2026-11-21 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S996 »Proper socialization and positive-reinforcement training from an early age« | #1472 |
| 2026-11-21 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« | PDSA S995 o napihnjenosti le »This most commonly occurs after they have eaten.« (tveganje, ne navodilo); noben vir A/B ne svetuje premora → brez vrstice | #1472 |
| 2026-11-21 | pravilo runbooka | brez poletne prepovedi sprehoda | noben vir A/B pasme ne imenuje ploskoobrazne | #1472 |
| 2026-11-21 | pravilo runbooka | oznake: primeren za **»družinsko življenje«**, **»družino z otroki«**, **»veliko hišo z vrtom«**; upoštevajte **»velik pes — ni za dom z majhnimi otroki«**, **»krajša življenjska doba«**, **»veke in oči«**, **»izpada mu dlaka«**, **»se slini«** | PDSA S995 »loyal, devoted dogs that love family life«; PetMD S996 »great with families and children«, »shouldn't leave one alone with a small child«, »They also drool a lot«; RKC S994 »Large house«, »Sheds: Yes«, »Under 10 years« | #1472 |
| 2026-11-21 | pravilo runbooka | **brez** `small_children`, `apartment`, `other_pets` (PetMD »may not get along with other pets«), `chases_small_pets` (ni vira o lovskem nagonu), `experienced_owner`, `brachycephalic_breathing`; nikoli »hypoallergenic« | S994, S995, S996 | #1472 |
| 2026-11-21 | pravilo runbooka (§3, dobrobit) | RKC Breed Watch **kategorija 3** → opozorilni čip **»veke in oči«**; **v aplikaciji da, v marketingu da** — nikoli s pretiranimi gubami ali povešenimi vekami, nikoli sam z majhnimi otroki | S994; PDSA S995 »Eyelid problems – such as entropion and ectropion – occur … due to excessive skin over the face«; veterinarsko odsvetovanje oglaševanja velja le za ploskoobrazne pse (BVA S92) | #1472 |
| 2026-11-21 | pravilo runbooka (§3) | barve za AI in izbirnik: **siv** (privzeto za portret), **črn**, **mahagonij** (rjav), **plav**, **temno plav**; nikoli velike bele lise ali bela na glavi; zmerne gube (`skin`), tesne veke, viden gobec z odprtimi nosnicami (`muzzle`); naravna ušesa in rep | FCI S993 »Preferred colours are: grey, lead grey and black, but also brown, fawn and deep fawn (red deer).«; tigrasti in »tolerirani« odtenki niso v izbirniku; uteži brez vira → `verified: false` | #1472 |
| 2026-11-21 | pravilo runbooka (§2.3) | seznam za vzreditelja: genetska raznolikost, kolki in komolci (BVA/KC) — minimum; oči (BVA/KC/ISDS) — najboljša praksa | RKC S994 | #1472 |
| 2026-11-21 | pravilo runbooka | zdravstveni ključi registra: `eyelid_problems`, `bloat_gdv`, `heart_muscle_disease`, `wobbler_syndrome`, `back_disc_disease`, `hip_elbow_dysplasia`, `hypothyroidism`, `epilepsy`, `certain_cancers`; brez odstotkov, brez novega ključa | PDSA S995, RKC S994, PetMD S996 | #1472 |
| 2026-11-21 | predlog Claude, David lahko spremeni | EN »Neapolitan Mastiff« (RKC / AKC), FCI »Mastino Napoletano«; SL »napolitanski mastif«; iskanje tudi »mastino napoletano«, »mastino«, »neo«, »neapeljski mastif« | strani KZS nisem preverjal | #1472 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »napolitanski mastif«, »neapolitan mastiff«, »mastino« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-132 | 2026-11-21 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 74. meseca — meja čaka Davida; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-132 | 2026-11-21 |
| Česanje 1× na teden, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-21 |
| Čiščenje kožnih gub vsak dan (otrok); opomnik za kopanje enkrat na mesec (starši) | 🛠 | 💶 izziv | M5-R14 / R20 | 2026-11-21 |
| Barve (izbirnik: 5 barv — siv, črn, mahagonij, plav, temno plav) | 🛠 | 💶 izziv | M5-R21 | 2026-11-21 |
| AI portret v registru | 🗓 sledi v tem zagonu | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/neapolitan-mastiff`, `/sl/zivali/psi/napolitanski-mastif`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal napolitanskega mastifa — težkega (50–70 kg) italijanskega čuvaja z gubasto kožo, 128. najbolj priljubljeno pasmo po AKC 2024 in 123. pasmo psa v aplikaciji (poleg mešančka). 9 novih virov (S993–S1001). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do ene ure gibanja), česanje enkrat na teden, vsak dan pa čiščenje kožnih gub; starši dobijo opomnik za kopanje enkrat na mesec.
**Zakaj je pomembno:** Kratka dlaka zavede — skrb je v gubah: čistiti jih je treba vsak dan, pes se močno slini, The Kennel Club ga uvršča v kategorijo 3 (pasme, pri katerih pretiran videz povzroča zdravstvene težave, npr. z vekami), živi pa praviloma manj kot 10 let. Ni za dom z majhnimi otroki.
**Kako povedati** — starši: »Napolitanski mastif je zvest in miren družinski velikan, a zahteva vsakodnevno čiščenje gub, veliko hišo in previdnost ob majhnih otrocih. V PetPrepu boste videli, ali vaš otrok to zmore 12 tednov.« · otroci: »Napolitanski mastif ima kožo v gubah kot velik mehak plašč — vsak dan mu očistiš gubice in greš z njim na sprehod.« · vlagatelji / partnerji: »123. pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal z viri — ključne podatke s strani Royal Kennel Club in PDSA, telesne mere iz standarda FCI; kjer vir manjka (meja starosti), je vrednost označena kot predlog za človeka.«

## Odprto za Davida

- **Ustavitev zagona (2026-11-21):** CI je dvakrat padel zaradi kode (skupni testi z ročnimi seznami pasem: DavidStageDecisionsTest / TrainingDecisionsTest, nato MastiffBreedTest, ki je trdil, da `neapolitan_mastiff` ni ključ pasme). Vse tri popravke sem potisnil na vejo; po pravilu runbooka PR #1472 ostaja odprt. Ko je `CI OK` zelen: združite, nato portret (§8), barve (§8b) in spletna stran (§9) — prej pasme ne objavljajte na petprep.si.
- **Meja »starejši« (74 mesecev):** predlog Claude, `verified=false`. RKC / PDSA navajata le »Under 10 years«; mediane McMillan 2024 / VetCompass za pasmo nisem dosegel. Osnova je povprečje 8,17 leta iz ankete lastnikov (Bell & Hesketh 2021, S1000). Alternativi: 63 (PetMD 7 let) ali 84 (Dogs Trust »> 7 let«). Ob ročnem pregledu tabele Dogs Trust / študije VetCompass o velikanskih pasmah (S1001) lahko mejo popravite.
- **Coren:** Wikipedija ni bila dosegljiva; zrcalna kopija pasme ne navaja, a nima niti angleškega mastifa → 1,0× je nepreverjen. Potrdite.
- **Kratek gobec:** Purina UK (vir C) pasmo imenuje brahicefalno; standard FCI (S993) navaja gobec tretjino dolžine glave in noben vir A/B pasme ne imenuje ploskoobrazne → brez čipa za dihanje in pasma ostaja v marketingu. Preverite, ali želite čip ali izključitev iz marketinga.
- **Kategorija 3 v marketingu:** pasma ostaja v marketingu (ni veterinarskega odsvetovanja oglaševanja); ali jo kljub kategoriji 3 želite v kampanjah?
- **Ime:** »napolitanski mastif« ni preverjen na straneh KZS (FCI »Mastino Napoletano«). Potrdite ali popravite.
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
