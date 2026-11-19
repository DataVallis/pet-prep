# Norwegian Elkhound (Norveški losovec)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-128 · 2026-11-19

| | |
|---|---|
| Ključ / slug | `norwegian_elkhound` / `norwegian-elkhound` (SL `norveski-losovec`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1190 — v izbirniku za valižanskim terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `norwegian_elkhound` (viri S960–S966; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: ni preverjen (strani RKC niso bile dosegljive) |
| Izvor | FCI št. 242 (»Norwegian Elkhound Grey«, Norsk Elghund Grå), skupina 5 (špici in pasme primitivnega tipa), sekcija 2 (nordijski lovski psi), Norveška (S960) — črni norveški losovec (FCI 268) je druga pasma in ni v aplikaciji |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 124. mesto »Norwegian Elkhound« (S174, zabeleženo 2026-11-19 v vrstici 127 čakalne vrste) |
| PR-ji | pet-prep #1400 (pasma) · portret pet-prep (zagon `breed-portraits.yml`) · barvne različice: ni potrebe (le ena standardna barva) · spletna stran: pet-prep-website `feat/register-norwegian-elkhound` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-19 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | PetMD S963 »One hour of exercise a day is recommended for this breed« (ena številka N → N). Strani RKC / PDSA niso bile dosegljive. Vetstreet S966 (vir C) 2 × 20–30 min — ni uporabljeno | #1400 |
| 2026-11-19 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 | #1400 |
| 2026-11-19 | **predlog Claude — čaka Davida** | starejši od **108. meseca** (tudi prihod starejšega psa); `verified: false` | najbližja vrstica pravila: 0,75 × spodnja meja PetMD »12–15 years« (S963; WebMD S964 enako). McMillan 2024 (Dogs Trust S123), VetCompass in pas RKC niso bili dosegljivi. Alternativa: Wikipedija (S965, vir C) navaja McMillan 2024 12,9 leta → 116 | #1400 |
| 2026-11-19 | pravilo runbooka | učenje **1,2×** | Coren mesto 36 »Above Average Working Dogs« (S35) | #1400 |
| 2026-11-19 | pravilo runbooka | teža **21,8–24,9 kg** (psi ok. 55 lb, psice ok. 48 lb), višina 52 / 49 cm (FCI idealno), rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | AKC S961 »Weight for dogs about 55 pounds, for bitches about 48 pounds.« (UKC S962 enako; FCI teže ne navaja); FCI S960 »Ideal height 52 cm« / »49 cm«; PetMD S10 (razred »Medium«) | #1400 |
| 2026-11-19 | pravilo runbooka (M5-R13) | česanje **vsak dan**; skuštrana dlaka po 4 zamujenih | WebMD S964 »You should brush your dog's coats for at least two minutes every day.«; PetMD S963 »… every day …« | #1400 |
| 2026-11-19 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S963 »When socialized properly as puppies and trained early, Norwegian Elkhounds make wonderful family dogs.« | #1400 |
| 2026-11-19 | pravila »Breed care tasks« (David 2026-10-24) | **ščetkanje zob vsak dan** (otrok) | WebMD S964 »brush their teeth daily«. Kremplji (»as needed«), ušesa (le pregled), kopanje (»a couple of times a year«) in frizer (spomladi in jeseni) → brez naloge (pravila nimajo takega obdobja) | #1400 |
| 2026-11-19 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« | ni citata o premoru po obroku | #1400 |
| 2026-11-19 | pravilo runbooka | brez poletnega opozorila za sprehod (ni ploskoobrazna pasma) — namesto tega čip »slabo prenaša vročino« | PetMD S963 »this thick-coated breed is prone to overheating.« | #1400 |
| 2026-11-19 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družinsko življenje«**, **»družino z otroki«**; upoštevajte **»potrebuje miselne izzive«**, **»rad pobegne — potrebuje varno, visoko ograjo«**, **»potrebuje česanje večkrat na teden«**, **»izpada mu dlaka«**, **»gost kožuh — slabo prenaša vročino«** | PetMD S963 (ura gibanja, miselni izzivi, »wonderful family dogs«, »great around children of all ages, though all interactions … supervised«, »secure, fenced area or on a leash«, »a lot of shedding«, »prone to overheating«); WebMD S964 (česanje vsak dan) | #1400 |
| 2026-11-19 | pravilo runbooka | **brez** `small_children` (nadzor; WebMD »not always friendly with … young children«), `chases_small_pets` (PetMD ne imenuje lovskega nagona), `long_daily_exercise` (60 min), `apartment` / `large_home` (ni ključnih podatkov RKC), `needs_company` (ni izjave), `independent_experienced_owner` (ni izjave o izkušenem lastniku); nikoli »hypoallergenic« | S963, S964 | #1400 |
| 2026-11-19 | pravilo runbooka (§3, dobrobit) | brez opozorilnega čipa; **v aplikaciji da, v marketingu da** — nikoli kot »hipoalergen« | nobeno veterinarsko združenje ne odsvetuje oglaševanja te pasme (S92 le ploskoobrazne pasme) | #1400 |
| 2026-11-19 | pravilo runbooka (§3) | barva za AI in izbirnik: le **siva** (edina standardna barva) → brez barvnih različic | FCI S960 »Grey, of various shades.«; AKC S961 »Disqualifications: An overall color other than gray.«; utež brez vira → `verified: false` | #1400 |
| 2026-11-19 | pravilo runbooka (§2.3) | seznam za vzreditelja: `not_found` | strani RKC niso bile dosegljive; PetMD / WebMD ne navajata testov staršev | #1400 |
| 2026-11-19 | pravilo runbooka | zdravstveni ključi registra: **nov** `fanconi_kidney_disease`, `pra_inherited_blindness`, `hip_dysplasia`, `kneecap_luxation`, `hypothyroidism`, `weight_gain`; brez odstotkov | PetMD S963, WebMD S964. Obstoječi `fanconi_syndrome` omenja test DNK staršev, ki ga noben vir za to pasmo ne navaja → lasten ključ | #1400 |
| 2026-11-19 | predlog Claude, David lahko spremeni | EN »Norwegian Elkhound« (RKC / AKC), SL »norveški losovec«; iskanje tudi »elkhound«, »elkie«, »norsk elghund«, »losovec« | strani KZS nisem našel | #1400 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »elkhound«, »norveški losovec« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-128 | 2026-11-19 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 108. meseca — predlog; 4 → 3 → 2 obroka; učenje 1,2×) | 🛠 | 💶 izziv | M5-R10-128 | 2026-11-19 |
| Česanje vsak dan, »Nova izkušnja« za mladička, ščetkanje zob vsak dan | 🛠 | 💶 izziv | M5-R13 / R15 / R17 | 2026-11-19 |
| Barve (ena standardna barva — siva; izbirnik brez izbire) | 🛠 | 💶 izziv | M5-R21 | 2026-11-19 |
| AI portret v registru | 🗓 po združitvi (§8) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/norwegian-elkhound`, `/sl/zivali/psi/norveski-losovec`) | 🗓 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal norveškega losovca — srednje velikega sivega nordijskega špica z zavitim repom, ki so ga stoletja uporabljali za lov na lose, 124. najbolj priljubljeno pasmo po AKC 2024 in 118. pasmo psa v aplikaciji. 7 novih virov (S960–S966), med njimi standardi FCI, AKC in UKC. Odrasel pes v igri potrebuje 6.000 korakov na dan (PetMD: ura gibanja), vsakodnevno česanje in ščetkanje zob.
**Zakaj je pomembno:** PetMD pravi, da je ob pravi socializaciji odličen družinski pes in dober z otroki vseh starosti (pod nadzorom) — a zelo izpada dlaka, potrebuje miselne izzive, rad odtava za nosom in se hitro pregreje. Vse to so oznake v aplikaciji, da se družina odloča poučeno.
**Kako povedati** — starši: »Norveški losovec je prijazen in vztrajen, a terja vsakodnevno delo: ura gibanja, vsak dan česanje in zobje, ograjen vrt ali povodec. V PetPrepu boste videli, ali otrok to zmore 12 tednov zapored.« · otroci: »Norveški losovec je sivi kosmati pes vikingov z repkom v obroču. Z njim greš vsak dan na sprehod, ga počešeš in mu ščetkaš zobke.« · vlagatelji / partnerji: »118. pasma psa, ki jo je nočni zagon sam izbral po javni lestvici AKC in dodal z viri — tudi tam, kjer britanski klub ni bil dosegljiv, z jasno označenim predlogom za odločitev.«

## Odprto za Davida

- **Starejše obdobje (108. mesec):** predlog Claude — 0,75 × spodnja meja PetMD 12 let; vrstica je `verified: false`. Wikipedija (vir C) navaja McMillan 2024 12,9 leta → 116. Potrdite ali določite drugo mejo (npr. iz tabele Dogs Trust S123, če ima vrstico za pasmo).
- **Stran RKC:** ključni podatki RKC (gibanje, velikost doma, izpadanje dlake, življenjska doba, Breed Watch) in testi za vzreditelje niso bili dosegljivi (preusmeritev na royalkennelclub.com) → gibanje iz PetMD, seznam za vzreditelja »ni preverjen«. Ob ročnem pregledu strani RKC dopolnite.
- **Lovski nagon:** PetMD piše, da lahko »odbezlja za manjšimi živalmi, kot so zajci, veverice in celo mačke«, a ne uporabi besed »prey drive« → po pravilu runbooka brez čipa `chases_small_pets`. Odločite, ali ga pasma dobi.
- **Ograja:** čip »rad pobegne — potrebuje varno, visoko ograjo« — vir (PetMD) govori o varnem ograjenem prostoru ali povodcu (pes odtava), ne izrecno o visoki ograji.
- **Nov zdravstveni ključ** `fanconi_kidney_disease`: besedilo na spletni strani (15 jezikov) je Claudovo — potrdite.
- **Ime:** »norveški losovec« ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
