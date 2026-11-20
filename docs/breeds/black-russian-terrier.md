# Ruski črni terier (Black Russian Terrier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-131 · 2026-11-20

| | |
|---|---|
| Ključ / slug | `black_russian_terrier` / `black-russian-terrier` (SL `ruski-crni-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1220 — v izbirniku za tibetanskim španjelom |
| Vir podatkov | `docs/research/dog-data/data.json` → `black_russian_terrier` (viri S984–S992; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: RKC (S986) |
| Izvor | FCI št. 327, skupina 2, sekcija 1.4 (pinči in šnavcerji — Tchiorny terier), Rusija (S984, S985) — po RKC in AKC delovni pes |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 127. mesto »Black Russian Terrier« (S174, prebrano 2026-11-20: »126 Tibetan Spaniel«, »127 Black Russian Terrier«, »128 Neopolitan Mastiff«) |
| PR-ji | pet-prep #1445 (pasma, združen `fa046ed`) · portret pet-prep (zagon `breed-portraits.yml` 44692875656, sprejet v prvem poskusu) · barvne različice pet-prep (sledijo) · spletna stran: pet-prep-website `feat/register-black-russian-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-20 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | RKC S986 »More than 2 hours per day« (»more than N« → N); Purina UK S991 (C) »a good two hours walking per day« se ujema | #1445 |
| 2026-11-20 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 | AAHA S11; starejši = 0,75 × spodnja meja RKC »Over 10 years« (S986) = 7,5 leta = 90 mesecev. McMillan 2024 pri viru A/B ni bil berljiv (Nature PDF: pasma le na sliki 5, tabela Dogs Trust zavrnjena). Ni izbrano: 84 (Dogs Trust »> 7 let«), 98 (McMillan 2024 prek Wikipedije, vir C) | #1445 |
| 2026-11-20 | pravilo runbooka | učenje **1,0×** | pasma ni na Corenovem seznamu (S35 ta zagon ni bil ponovno prebran) → pravilo »unranked« | #1445 |
| 2026-11-20 | pravilo runbooka | teža **samci 50–60 kg, samice 45–50 kg**, višina samci 72–76 cm, samice 68–72 cm (FCI), rast do 18–24 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S985 »Males: 50 – 60 kgs. Females: 45 – 50 kgs.«; RKC standard S987 68–77 / 66–72 cm; PetMD S10 (razred »Giant«) | #1445 |
| 2026-11-20 | pravilo runbooka (M5-R13) | česanje **vsak dan**; skuštrana dlaka po 4 zamujenih | RKC S986 »Grooming: Every day« (→ 7); VCA S990 »at least once a week«, PetMD S989 »at least a few times a week« (alternativi) | #1445 |
| 2026-11-20 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S989 »Early and consistent socialization is important for this breed …« | #1445 |
| 2026-11-20 | David 2026-10-12 (M5-R16) | **opozorilo »počakaj s sprehodom« 60 min po obroku** (koraki se štejejo) | PetMD S989 »Because Black Russian Terriers are susceptible to bloat, avoid strenuous exercise right before and after mealtimes.« | #1445 |
| 2026-11-20 | pravila »Breed care tasks« (David 2026-10-24, M5-R14 / R18 / R20) | **pregled ušes enkrat na teden**, **kremplji enkrat na teden** (otrokove mini igre), **obisk frizerja vsakih 6 tednov** (opomnik staršem) | PetMD S989 »Check the ears weekly …«; VCA S990 »Weekly pedicures«, »trimming by a professional groomer should occur every six to eight weeks.« (razpon → spodnja meja). Zobje: ni vira; kopanje: VCA »periodic« brez števila → brez naloge | #1445 |
| 2026-11-20 | pravilo runbooka | brez poletne prepovedi sprehoda | ni ploskoobrazna pasma | #1445 |
| 2026-11-20 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družinsko življenje«**, **»družino z otroki«**, **»veliko hišo z vrtom«**, **»dom, kjer želite manj dlak«**; upoštevajte **»vsak dan potrebuje veliko gibanja«**, **»potrebuje česanje«**, **»velik pes — ni za dom z majhnimi otroki«** | RKC S986 (»More than 2 hours per day«, »Large house«, »Sheds: No«, »Every day«); PetMD S989 »devoted to their families«, »can be good with children when supervised« (nadzor v opombi); VCA S990 »Children \| Older kids« → `not_for_small_children` (čip `older_children` govori o majhnem psu; v deljenem testu `BreedSuitabilityTest` dodan na seznam izjem, kot pri drugih velikih pasmah) | #1445 |
| 2026-11-20 | pravilo runbooka | **brez** `small_children`, `apartment`, `other_pets`, `chases_small_pets` (ni vira o lovskem nagonu), `experienced_owner` (le Purina, vir C), `needs_company`; nikoli »hypoallergenic« | S986, S989, S990 | #1445 |
| 2026-11-20 | pravilo runbooka (§3, dobrobit) | RKC Breed Watch **kategorija 1**, nobeno veterinarsko združenje pasme ne navaja zaradi težav s telesno zgradbo → brez opozorilnega čipa; **v aplikaciji da, v marketingu da** (nikoli z majhnimi otroki samimi) | S986; S92 (le ploskoobrazne pasme) | #1445 |
| 2026-11-20 | pravilo runbooka (§3) | barve za AI in izbirnik: **črn** (privzeto za portret), **črn s sivimi dlakami**; nikoli bele lise ali sive zaplate; naravna ušesa in rep | FCI S985 »Black, black with insignificant intermingling grey hair is permissible.«; uteži brez vira → `verified: false` | #1445 |
| 2026-11-20 | pravilo runbooka (§2.3) | seznam za vzreditelja: genetska raznolikost, kolki in komolci (BVA/RKC) — minimum; DNA HUU, DNA JLPP, prcd-PRA — najboljša praksa | RKC S986 | #1445 |
| 2026-11-20 | pravilo runbooka | zdravstveni ključi registra: `hip_elbow_dysplasia`, `pra_inherited_blindness`, `bladder_stones` (hiperurikozurija, precedens dalmatinec), `laryngeal_paralysis_polyneuropathy`, `bloat_gdv`; brez odstotkov, brez novega ključa | VCA S990, PetMD S989, RKC S986 | #1445 |
| 2026-11-20 | predlog Claude, David lahko spremeni | EN »Black Russian Terrier« (AKC; RKC / FCI »Russian Black Terrier«), SL »Ruski črni terier«; iskanje tudi »russian black terrier«, »tchiorny terrier«, »brt«, »črni ruski terier« | strani KZS nisem dosegel | #1445 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »ruski črni terier«, »black russian terrier« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-131 | 2026-11-20 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-131 | 2026-11-20 |
| Česanje vsak dan, »Nova izkušnja« za mladička, opozorilo 60 min po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 | 2026-11-20 |
| Ušesa in kremplji vsak teden (otrok); opomnik za frizerja vsakih 6 tednov (starši) | 🛠 | 💶 izziv | M5-R14 / R18 / R20 | 2026-11-20 |
| Barve (izbirnik: 2 barvi — črn (portret), črn s sivimi dlakami) | 🛠 | 💶 izziv | M5-R21 | 2026-11-20 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), črn, brada in obrvi, viseča ušesa, naraven rep, sivo studijsko ozadje | — | M5-R11-04 | 2026-11-20 |
| Stran na petprep.si (`/animals/dogs/black-russian-terrier`, `/sl/zivali/psi/ruski-crni-terier`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal ruskega črnega terierja — velikega (45–60 kg), močnega delovnega psa, ki so ga v Sovjetski zvezi vzredili za varovanje, 127. najbolj priljubljeno pasmo po AKC 2024 in 122. pasmo psa v aplikaciji (poleg mešančka). 9 novih virov (S984–S992). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot dve uri gibanja), vsakodnevno česanje, tedenski pregled ušes in krempljev ter frizerja na 6 tednov; po obroku aplikacija opozori, naj s sprehodom počaka (nevarnost zasuka želodca).
**Zakaj je pomembno:** Pod gosto črno dlako se skriva velika obveznost: dve uri gibanja vsak dan, vsakodnevno česanje in redno striženje — in pes, ki ni za dom z majhnimi otroki. Življenjska doba po RKC več kot 10 let.
**Kako povedati** — starši: »Ruski črni terier je miren in zvest, a ogromen in zahteven: dve uri gibanja, česanje vsak dan in frizer vsakih šest tednov. V PetPrepu boste videli, ali vaš otrok zmore ta ritem.« · otroci: »Ruski črni terier ima brado in obrvi kot dedek — vsak dan ga počešeš, pogledaš ušesa in greš z njim na dolg sprehod.« · vlagatelji / partnerji: »122. pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal z viri — ključne podatke je vzel s strani Royal Kennel Club, telesne mere iz standarda FCI.«

## Odprto za Davida

- **Življenjska doba:** McMillan 2024 pri viru A/B ni bil berljiv (Nature PDF ima pasmo le na sliki 5, tabela Dogs Trust je bila zavrnjena); Wikipedija (vir C) navaja 10,9 leta iz »2024 UK study« → starejši bi bil 98 namesto 90 mesecev. Ob ročnem pregledu tabele Dogs Trust lahko mejo popravite.
- **Coren:** stran Wikipedije s Corenovo lestvico (S35) ta zagon ni bila dosegljiva; pasma je obravnavana kot neuvrščena (1,0×). Ob ročnem pregledu potrdite.
- **Ime:** »Ruski črni terier« ni preverjen na straneh KZS (po besednem redu FCI »Russian Black Terrier«). Potrdite ali popravite.
- **Kremplji:** VCA »Weekly pedicures« sem prebral kot tedensko obrezovanje krempljev (precedens pirenejski planšar, veliki švicarski planšar). Povejte, če naj bo brez naloge.
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
