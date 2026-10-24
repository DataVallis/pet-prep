# Bloodhound (Bloodhound)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-62 · 2026-10-24

| | |
|---|---|
| Ključ / slug | `bloodhound` / `bloodhound` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 580 — v izbirniku za velikim šnavcerjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `bloodhound` (viri S500–S505; ponovno S8, S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja iz S502 |
| Izvor | FCI št. 84 »Chien de Saint-Hubert«, skupina 6, sekcija 1 (goniči, 1.1 veliki goniči), Belgija (S500) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 58. mesto »Bloodhound« (S174, prebrano 2026-10-24) |
| PR-ji | pet-prep #369 (pasma) · portret: sledi · spletna stran: sledi |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-24 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | RKC S502 »Exercise: More than 2 hours per day« (»more than N« → N); PDSA S503 »Minimum exercise (per day): 2 hours« se ujema | #369 |
| 2026-10-24 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36 mesecev; prihod 2 / 9 / 36 | AAHA S11 (kot pri vseh psih) | #369 |
| 2026-10-24 | **predlog Claude — čaka Davida** (`verified=false`) | starejši od **61 meseca** (tudi prihod starejšega psa) | 0,75 × mediana 6 let in 9 mesecev iz ankete Kennel Club 2004 (S505, načrt BHCP 2019). Pravilo runbooka ne da vrednosti: tabele McMillan 2024 (Dogs Trust S123) zagon ni mogel odpreti, mediane VetCompass ni, RKC »Under 10 years« nima spodnje meje → najbližja vrstica (precedens bernski planšar). Alternativa: 84 (McMillan 2024 »9.3 years«, le prek Wikipedije S504, tier C; enako Dogs Trust > 7 let, S14) | #369 |
| 2026-10-24 | pravilo runbooka | učenje **0,5×** (Coren rang 74, skupina »Lowest«) | S35 (»\| 74 \| Bloodhound \|«) | #369 |
| 2026-10-24 | pravilo runbooka | teža 40–54 kg (psi 46–54, psice 40–48), višina psi 64–72 cm, psice 58–66 cm, rast do 18–24 mesecev; hrana, voda in čiščenje kot pri border collieju | standard FCI št. 84 (S501) »Males about 46 - 54 kg.«; PDSA S503 45–50 kg (alternativa); PetMD S10 (razred »Giant«) | #369 |
| 2026-10-24 | pravilo runbooka (M5-R13) | česanje **1× na teden** | RKC S502 »Grooming: Once a week« → 1; PDSA S503 »… will only need a weekly brush …« enako | #369 |
| 2026-10-24 | David 2026-10-12 (M5-R16) | **opozorilo po obroku** (60 minut »počakaj s sprehodom«, koraki se štejejo) | PDSA S503 »Remember to leave a gap between eating and exercising as this is thought to reduce the risk of GDV.« | #369 |
| 2026-10-24 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S503 »… it’s important to socialise your Bloodhound from a young age with lots of people, dogs and experiences.« | #369 |
| 2026-10-24 | pravilo runbooka | oznake: aktivno družino (`active_family`), veliko hišo z vrtom (`large_home`); upoštevajte: veliko gibanja (`long_daily_exercise`), izkušen lastnik (`experienced_owner`), ni za dom z majhnimi otroki (`not_for_small_children`), izpada mu dlaka (`sheds`), se slini (`drools`), krajša življenjska doba (`shorter_lifespan`), veke in oči (`eyelid_problems`), močan lovski nagon (`chases_small_pets`, zadnja) | RKC S502 »Exercise: More than 2 hours per day«, »Size of home: Large house«, »Sheds: Yes«, »Lifespan: Under 10 years«; PDSA S503 »… better suited to experienced owners«, »we wouldn’t recommend having a Bloodhound if you have smaller children.«, »You’ll find that your Bloodhound is a drooler«, »Eye problems – such as ectropion, entropion and corneal ulcers.«, »Bloodhounds do have a very high prey drive, so we wouldn’t advise keeping them with smaller pets.« | #369 |
| 2026-10-24 | pravilo runbooka | **brez** oznak `family_pet`, `children`, `small_children`, `first_time_owner`, `apartment`, `other_pets`, `frequent_grooming`, `needs_company`; brez nove oznake | noben vir A/B izrecno ne pravi »družinski pes« (le Wikipedija, tier C); PDSA odsvetuje manjše otroke, začetnike in manjše živali; česanje le tedensko; PDSA: ni znan po ločitveni tesnobi | #369 |
| 2026-10-24 | pravilo runbooka (§3, dobrobit) | oznaka dobrobiti **veke in oči** (`eyelid_problems`) | RKC Breed Watch kategorija 2 (S502, »conformational concerns«); PDSA S503 »Bloodhounds are amongst a group of breeds classed as ‘Category Three’ by The Kennel Club.« in očesne težave (ektropij, entropij, razjede roženice) — kot pri angleškem mastifu | #369 |
| 2026-10-24 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli z majhnimi otroki ali ob manjših živalih, nikoli s pretirano ohlapno kožo ali povešenimi vekami, nikoli z obljubo o zdravju | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #369 |
| 2026-10-24 | pravilo runbooka (§3) | barve za AI in izbirnik: **črno-rjav** (portret), jetrno-rjav, rdeč; nikoli lisast, progast ali bel; zmerna koža in zdrave veke | FCI S501 »There are three distinct coat colours: the bicolours black and tan and liver and tan and the unicolour red.« Uteži barv brez vira → `verified: false`; črno-rjava za portret je predlog Claude (prva barva standarda) | #369 |
| 2026-10-24 | predlog Claude, David lahko spremeni | ime SL »Bloodhound« (slug `bloodhound`); iskalni ključi tudi »pes svetega Huberta«, »pes sv. Huberta«, »chien de saint-hubert«, »st. hubert hound«, »sleuth hound« | slovenskega vira (KZS) za ime ni bilo mogoče najti; FCI ime pasme je »Chien de Saint-Hubert« | #369 |
| 2026-10-24 | pravilo runbooka (§2.3) | seznam za vzreditelja — minimum: genetska raznolikost, komolci in kolki (BVA/KC); najboljša praksa: pregled oči BVA/KC/ISDS. Brez novega ključa | RKC S502 | #369 |
| 2026-10-24 | pravilo runbooka | zdravstveni ključi registra (obstoječi): `hip_elbow_dysplasia`, `bloat_gdv`, `eyelids_turned`, `itchy_skin_allergy`, `ear_infections_prone`; brez odstotkov | PDSA S503, RKC S502 | #369 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »bloodhound«, »pes svetega Huberta« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-62 | 2026-10-24 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 61 meseca — predlog; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-62 | 2026-10-24 |
| Česanje 1× na teden (rutina »Počeši«), »Nova izkušnja« za mladička, opozorilo »počakaj s sprehodom« po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 | 2026-10-24 |
| Barva (izbirnik barve: črno-rjav, jetrno-rjav, rdeč) | 🛠 | 💶 izziv | M5-R21 | 2026-10-24 |
| AI portret v registru | 🗓 | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/bloodhound`, `/sl/zivali/psi/bloodhound`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije (le s portretom) | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal bloodhounda — 58. najbolj priljubljeno pasmo po AKC 2024 in 58. plačljivo pasmo psa. 6 novih virov (S500–S505). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC in PDSA: več kot dve uri gibanja), starejši 9.000; uči se počasneje od mešanca (Coren: 74. mesto, ×0,5). Tedensko česanje, socializacija mladička in opozorilo »počakaj s sprehodom« po obroku (nevarnost zasuka želodca) so del izziva od prvega dne.
**Zakaj je pomembno:** Bloodhound je nežen, miren, a zelo velik in zelo gibčen pes z izjemnim vohom, ki se slini, ima močan lovski nagon in živi razmeroma kratko. Viri ga priporočajo izkušenim lastnikom, ne družinam z manjšimi otroki in ne domu z manjšimi živalmi. Dve uri gibanja na dan je velika obveza — PetPrep pokaže, ali jo družina zdrži 12 tednov.
**Kako povedati** — starši: »Bloodhound potrebuje več kot dve uri gibanja na dan, počitek po obroku in izkušenega lastnika. V PetPrepu boste videli, ali družina zmore 12.000 korakov na dan 12 tednov zapored.« · otroci: »Bloodhound ima najboljši nos med psi. Z njim greš vsak dan dolgo ven — a po kosilu najprej malo počakaš.« · vlagatelji / partnerji: »Oseminpetdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Meja starejšega psa (61 mesecev, `verified=false`):** predlog iz mediane ankete Kennel Club 2004 (6 let in 9 mesecev, S505). Če potrdite McMillan 2024 (9,3 leta, zdaj le prek Wikipedije), bi bil starejši od 84 mesecev (podatkovna migracija ali urejanje v Filamentu).
- **Breed Watch:** RKC stran (S502) navaja kategorijo 2, PDSA (S503) »Category Three«; posameznih točk RKC zagon ni mogel prebrati. Oznaka dobrobiti je `eyelid_problems`; ali želite še `sensitive_skin` (atopija, kožne gube)?
- **Potreba brez mehanike (ROADMAP M5-R14):** kožne gube čiste in suhe (PDSA S503), brez pogostosti v viru.
- **Potreba brez mehanike (ROADMAP M5-R20):** redni pregled ušes za znake vnetja (PDSA S503).
- **Ime:** »Bloodhound« / »pes svetega Huberta« ni preverjeno pri KZS.
- **Marketing:** pasma z oznako Breed Watch je v marketingu (pravilo runbooka) — potrdite.
- **Barva portreta:** standard nobeni barvi ne daje prednosti; črno-rjava je prva barva standarda FCI (predlog Claude).
