# Japanese Chin (Japonski čin)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-107 · 2026-11-12

| | |
|---|---|
| Ključ / slug | `japanese_chin` / `japanese-chin` (SL `japonski-cin`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1000 — v izbirniku za ruskim hrtom |
| Vir podatkov | `docs/research/dog-data/data.json` → `japanese_chin` (viri S805–S810; ponovno S10, S11, S14, S24, S35, S45, S92, S123, S174); seznam za vzreditelja: RKC (S805) |
| Izvor | FCI št. 206 »Japanese Chin« (»Japan Chin«), skupina 9, sekcija 8 (japonski čin in pekinezer), Japonska (S807, S808) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 103. mesto »Japanese Chin« (S174, prebrano 2026-11-12) |
| PR-ji | pet-prep #1076 (pasma) · portret pet-prep PR `feat/M5-R10-107-japanese-chin-portrait` · spletna stran: pet-prep-website `feat/register-japanese-chin` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-12 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **30 min/dan → 3.000 korakov**; starejši 23 min → 2.300; mladiček / mlad 10 min × starost do 30 (pri 3 mesecih) | RKC S805 »Up to 30 minutes per day« (»up to N« → N); PetMD S809 »Thirty minutes of exercise a day« se ujema | #1076 |
| 2026-11-12 | pravilo runbooka | obdobja 0 / 9 / 36 / **113**; prihod 2 / 9 / 36 / 113 | AAHA S11; starejši = round(0,75 × 12,5 let × 12) — mediana McMillan 2024 prek tabele Dogs Trust S123 »Japanese Chin 12.5 12.2 - 13.3 373 142«. Alternativa 90 (RKC »Over 10 years«) | #1076 |
| 2026-11-12 | pravilo runbooka | učenje **0,7×** | Coren 62. mesto (»Fair Working/Obedience Intelligence«, tabela Wikipedia S35) | #1076 |
| 2026-11-12 | pravilo runbooka | teža **1,8–3,2 kg** (oba spola), višina 20–28 cm, rast do 8–12 mesecev; hrana, voda in čiščenje kot pri border collieju | standard RKC S806 »Ideal weight 1.8-3.2 kgs (4-7 lbs).« (FCI teže ne navaja); PetMD S809 8–11 palcev; PetMD S10 (razred »Toy«) | #1076 |
| 2026-11-12 | pravilo runbooka (M5-R13) | česanje **2× na teden**; skuštrana dlaka po 1 zamujenem | RKC »More than once a week« nima števila → PetMD S809 »once or twice a week« → zgornja meja 2 | #1076 |
| 2026-11-12 | pravila »Breed care tasks« (David 2026-10-24) | **čiščenje ušes vsaka 2 tedna** (otrokova mini igra) in **opomnik za kopanje vsake 4 tedne** (staršu, brez točk) | PetMD S809 »Clean their ears … every two to three weeks« (spodnja meja) in »a bath may be needed monthly«; kremplji, zobje, frizer — brez pogostosti → brez naloge; oči »gently cleaning« brez pogostosti | #1076 |
| 2026-11-12 | pravilo runbooka (M5-R14) | **poletno opozorilo** za sprehod opoldne | ploskoobrazna pasma; PetMD S809 »Take special care in humid or hot weather, limiting exercise to cooler times of the day.« | #1076 |
| 2026-11-12 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S809 »enroll your Japanese Chin puppy in socialization classes at an early age (before they’re 12–16 weeks old)« | #1076 |
| 2026-11-12 | pravilo runbooka (M5-R16) | **brez** opozorila po obroku | noben vir ne navaja premora med obrokom in gibanjem | #1076 |
| 2026-11-12 | pravilo runbooka | oznake: primeren za **»življenje v stanovanju«**, **»družino z otroki«** (z nadzorom); upoštevajte **»kratek gobček — težave z dihanjem in vročino«**, **»rad ima družbo«**, **»izpada mu dlaka«** | RKC S805 »Size of home: Flat/ Apartment«, »Sheds: Yes«; PetMD S809 »generally good with children … should always be supervised«, »Japanese Chin are flat-faced (brachycephalic) dogs.«, ločitvena tesnoba | #1076 |
| 2026-11-12 | pravilo runbooka | **brez** `family_pet`, `small_children`, `other_pets` (le »when socialized as puppies«), `frequent_grooming` (PetMD: »low maintenance«), `eyelid_problems` (entropion le parafraza); nikoli »hypoallergenic« | ni A/B navedbe ali je pogojna | #1076 |
| 2026-11-12 | pravilo runbooka (§3, dobrobit, David 2026-10-10) | **v aplikaciji da, v marketingu ne** (oglasi, posnetki zaslona, objave, predstavitve) | BVA S92 odsvetuje slike ploskoobraznih psov v oglaševanju; PetMD S809 brahicefalna pasma; RKC predlaga test dihanja BOAS. RKC Breed Watch je sicer kategorija 1 | #1076 |
| 2026-11-12 | pravilo runbooka (§3) | barve za AI in izbirnik: **črno-bel** (portret, največja utež), rdeče-bel; zmeren kratek obraz z vidnim gobčkom in odprtimi nosnicami; nikoli trobarven ali enobarvno bel | RKC S806 »Black and white or red and white. Never tricolour.«; FCI S808 »White with markings of black or red.«; uteži brez vira → `verified: false` | #1076 |
| 2026-11-12 | pravilo runbooka (§2.3) | seznam za vzreditelja: genetska raznolikost (minimum), shema za oči BVA/KC/ISDS in test dihanja BOAS (best practice) — brez novega ključa | RKC S805 | #1076 |
| 2026-11-12 | pravilo runbooka | zdravstveni ključi registra: `flat_face_breathing`, `heat_stroke_risk`, **`gm2_gangliosidosis` (nov)**, `kneecap_luxation`, `cataracts`, `mitral_valve_disease`; brez odstotkov | PetMD S809 | #1076 |
| 2026-11-12 | predlog Claude, David lahko spremeni | EN »Japanese Chin«, SL **»Japonski čin«**; iskanje tudi »japanese spaniel«, »japonski cin« (brez samega »chin« — iskanje naj ne zadene kitajskega golega psa) | RKC / AKC / FCI; Wikipedija »Japanese Spaniel«; strani KZS nisem prebral | #1076 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »japanese chin«, »japonski čin« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-107 | 2026-11-12 |
| Pravila po starosti (2.000 → 3.000 korakov pri 3 mesecih, odrasel 3.000, starejši 2.300 od 113. meseca; 4 → 3 → 2 obroka; učenje 0,7×) | 🛠 | 💶 izziv | M5-R10-107 | 2026-11-12 |
| Česanje 2× na teden, čiščenje ušes vsaka 2 tedna, opomnik za kopanje vsake 4 tedne, poletno opozorilo za sprehod, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R18 / M5-R14 / M5-R17 | 2026-11-12 |
| Barva (izbirnik: črno-bel, rdeče-bel) | 🛠 | 💶 izziv | M5-R21 | 2026-11-12 |
| AI portret v registru | 🗓 po združitvi (§8) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/japanese-chin`, `/sl/zivali/psi/japonski-cin`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal japonskega čina — drobnega (1,8–3,2 kg), elegantnega psa s svilnato dlako z Japonske; 103. najbolj priljubljeno pasmo po AKC 2024 in 99. pasmo psa v aplikaciji. 6 novih virov (S805–S810). Odrasel pes v igri potrebuje 3.000 korakov na dan (RKC: do 30 minut gibanja), česanje 2× na teden, čiščenje ušes vsaka 2 tedna in kopanje vsake 4 tedne; starejši postane pri 113 mesecih (mediana 12,5 leta, McMillan 2024).
**Zakaj je pomembno:** Čin je majhen in primeren za stanovanje, a ima kratek gobček — težje diha in se hitro pregreje, zato poleti opoldne ni za sprehode. Rad ima družbo in lahko trpi, če je dolgo sam. PetPrep pokaže, ali družina zmore vsakdanjo pozornost, nego ušes in previdnost v vročini.
**Kako povedati** — starši: »Japonski čin je drobcen pes za stanovanje, a ima kratek gobček in ne prenese vročine. V PetPrepu boste videli, ali vaš otrok zmore redno nego in pozornost.« · otroci: »Čin je majhen kosmatinec — dvakrat na teden ga počešeš, vsaka dva tedna mu očistiš ušesa, poleti pa greš ven zjutraj ali zvečer.« · vlagatelji / partnerji: »Devetindevetdeseta pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, pravili nege, opozorilom za vročino; kot ploskoobrazna pasma po priporočilu BVA ni v oglasih.«

## Odprto za Davida

- **Marketing:** po pravilu (BVA S92) čin ni v oglasih, posnetkih zaslona in objavah, čeprav je RKC Breed Watch kategorija 1. Želite izjemo?
- **Kopanje vsake 4 tedne:** PetMD »a bath may be needed monthly (or whenever your Chin looks a little dirty)« — zgrajeno kot opomnik staršu po pravilu »monthly«; če je »may be needed« za vas pogojno, opomnik odstranite (podatkovna migracija).
- **Ime:** »Japonski čin« v slovenščini ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** število pasem psov v FEATURES / predstavitvah (zdaj 99) se po pravilu »Own files only« ne spreminja v tem zagonu.
