# Avstralski svilnati terier (Australian Silky Terrier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-115 · 2026-11-15

| | |
|---|---|
| Ključ / slug | `australian_silky_terrier` / `australian-silky-terrier` (SL `avstralski-svilnati-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1080 — v izbirniku za afganskim hrtom |
| Vir podatkov | `docs/research/dog-data/data.json` → `australian_silky_terrier` (viri S855–S863; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: stran RKC (S856) |
| Izvor | FCI št. 236, skupina 3, sekcija 4 (pritlikavi terierji), Avstralija (S855) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 111. mesto »Silky Terrier« (S174, zapisano 2026-11-14 v vrstici 113) |
| PR-ji | pet-prep #1131 (pasma, združen `58d7ca9`) · portret pet-prep PR `feat/M5-R10-115-australian-silky-terrier-portrait` (zagon `breed-portraits.yml` 43670136362, sprejet v prvem poskusu) · spletna stran: pet-prep-website `feat/register-australian-silky-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-15 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **30 min/dan → 3.000 korakov**; starejši 23 min → 2.300; mladiček 10 min × starost do 30 (pri 3 mesecih) | stran RKC S856 »Exercise: Up to 30 minutes per day« (»up to N« → N); PetMD S857 »at least one hour of exercise daily« (60) = alternativa; 0,75 × 30 = 22,5 → 23 | #1131 |
| 2026-11-15 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108**; prihod 2 / 9 / 36 / 108 | AAHA S11; starejši = 0,75 × spodnja meja RKC **12 let** (»Over 12 years«, S856) = 9 let = 108 mesecev. McMillan 2024 / VetCompass ni dosegljiv (le prek Wikipedije, S862) → tretji vir pravila. Ni izbrano: 84 (Dogs Trust »> 7 let«), 120 (McMillan prek Wikipedije) | #1131 |
| 2026-11-15 | pravilo runbooka | učenje **1,2×** | Coren: 37. mesto »Australian Silky Terrier«, »Above Average Working Dogs« (27–39) — tabela »The Intelligence of Dogs« (S35, prebrana prek zrcala) | #1131 |
| 2026-11-15 | pravilo runbooka | teža **okoli 4,5 kg** (PetMD in WebMD: okoli 10 lb — naša pretvorba), višina 23–26 cm (FCI; psice »so lahko nekoliko nižje«), rast do 8–12 mesecev; hrana, voda in čiščenje kot pri border collieju | PetMD S857, WebMD S858 (standardi FCI, ANKC, AKC in UKC teže ne navajajo: »v sorazmerju z višino«); FCI S855; PetMD S10 (razred »Toy«) | #1131 |
| 2026-11-15 | pravilo runbooka (M5-R13) | česanje **vsak dan** (7× na teden); skuštrana dlaka po 4 zamujenih | RKC S856 »Grooming: Every day«; PetMD S857 in WebMD S858 »at least twice a week« (2) = alternativa | #1131 |
| 2026-11-15 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S857 »Enrolling Silky puppies in socialization classes before 16 weeks of age can help them become comfortable around children, other dogs, and new people.« | #1131 |
| 2026-11-15 | pravila »Breed care tasks« (David 2026-10-24, M5-R15 / R18 / R19) | **ščetkanje zob vsak dan** in **čiščenje ušes vsaka 2 tedna** (otrokovi mini igri) ter opomnik staršem **kopanje vsake 4 tedne** (nikoli v točkah) | WebMD S858 »Brush their teeth daily to help prevent dental disease.«; PetMD S857 »every two to three weeks« (ušesa) → spodnja meja; WebMD S858 »about once a month« (PetMD »every four to six weeks« se ujema). Kremplji (»regularly«), frizer: brez pogostosti → brez naloge | #1131 |
| 2026-11-15 | pravilo runbooka | brez opozorila po obroku in brez poletnega opozorila za sprehod | noben vir ne navaja premora po obroku; ni ploskoobrazna pasma | #1131 |
| 2026-11-15 | pravilo runbooka | oznake: primeren za **»življenje v stanovanju«** in **»družino z otroki«**; upoštevajte »izpada mu dlaka«, »potrebuje česanje večkrat na teden« | RKC S856 »Flat/ Apartment«, »Sheds: Yes«, »Grooming: Every day«; PetMD S857 »they're good around other pets and children if your pup is socialized properly at a young age« (pogoj ostane v opombah) | #1131 |
| 2026-11-15 | pravilo runbooka | **brez** `chases_small_pets`, `small_children`, `other_pets`, `needs_company`, `family_pet`, `active_family`, `shorter_lifespan`; nikoli »hypoallergenic« | PetMD »the prey drive inherited from their parent breeds« in RKC »still capable of despatching rodents« — ne navajata dobesedno »high / strong prey drive« → odprto za Davida; WebMD »only moderately good with young children« → ne `small_children` | #1131 |
| 2026-11-15 | pravilo runbooka (§3, dobrobit) | RKC Breed Watch **kategorija 1**, brez točk skrbi → brez opozorilnega čipa; **v aplikaciji da, v marketingu da** | S856 »Currently no points of concern specific to this breed …« | #1131 |
| 2026-11-15 | pravilo runbooka (§3) | barva za AI in izbirnik: **modro-rjavkast** (edina barva standarda: srebrno do skrilasto modro telo, rjave noge in obraz, srebrn ali plav čopek); naraven rep, dlaka sledi obrisu telesa in se ne dotika tal (ne kot pri yorkshirskem terierju) | FCI S855 »All shades of blue and tan are acceptable …«, »Silver and white not acceptable.«; AKC S860 »Blue and tan. The blue may be silver blue, pigeon blue or slate blue, the tan deep and rich.«; uteži brez vira → `verified: false` | #1131 |
| 2026-11-15 | pravilo runbooka (§2.3) | seznam za vzreditelja: minimum »Prioritising genetic diversity« (`inbreeding_coi`); brez najboljše prakse, brez novega ključa | stran RKC S856 (»We don't currently offer a breed-specific DNA testing package for this breed«) | #1131 |
| 2026-11-15 | pravilo runbooka | zdravstveni ključi registra: `kneecap_luxation`, `legg_perthes`, `liver_shunt`, `cataracts`, `pra_inherited_blindness`, `dental_disease_tooth_brushing`; brez odstotkov, brez novega ključa (dermatitis Malassezia, Cushingova bolezen, epilepsija … ostanejo v data.json) | PetMD S857, WebMD S858 | #1131 |
| 2026-11-15 | predlog Claude, David lahko spremeni | EN »Australian Silky Terrier« (RKC / FCI; AKC »Silky Terrier«), SL »Avstralski svilnati terier«; iskanje tudi »silky terrier«, »silky«, »svilnati terier« | strani KZS nisem dosegel | #1131 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »avstralski svilnati terier«, »silky«, »svilnati terier« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-115 | 2026-11-15 |
| Pravila po starosti (2.000 → 3.000 korakov pri 3 mesecih, odrasel 3.000, starejši 2.300 od 108. meseca; 4 → 3 → 2 obroka; učenje 1,2×) | 🛠 | 💶 izziv | M5-R10-115 | 2026-11-15 |
| Česanje vsak dan, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-15 |
| Ščetkanje zob vsak dan, čiščenje ušes vsaka 2 tedna (otrok), opomnik staršem: kopanje vsake 4 tedne | 🛠 | 💶 izziv | M5-R15 / M5-R18 / M5-R19 | 2026-11-15 |
| Barva (izbirnik: modro-rjavkast, edina barva standarda) | 🛠 | 💶 izziv | M5-R21 | 2026-11-15 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), modro-rjavkast, pokončna ušesa, naraven rep | — | M5-R11-04 | 2026-11-15 |
| Stran na petprep.si (`/animals/dogs/australian-silky-terrier`, `/sl/zivali/psi/avstralski-svilnati-terier`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal avstralskega svilnatega terierja — majhnega, živahnega terierja iz Avstralije z ravno, svilnato modro-rjavkasto dlako; 111. najbolj priljubljeno pasmo po AKC 2024 in 107. pasmo psa v aplikaciji. 9 novih virov (S855–S863). Odrasel pes v igri potrebuje 3.000 korakov na dan (RKC: do 30 minut gibanja), česanje vsak dan, ščetkanje zob vsak dan in čiščenje ušes vsaka dva tedna; starši dobijo opomnik za kopanje vsake štiri tedne.
**Zakaj je pomembno:** Majhen pes ne pomeni malo dela. Svilnati terier je za stanovanje (RKC), a njegova dlaka potrebuje vsakodnevno nego, rad laja, da opozori družino (PetMD), in ima terierski lovski nagon, zato je zunaj vedno na povodcu ali za ograjo. Hitro se uči (Coren: nad povprečjem) — v igri 1,2×.
**Kako povedati** — starši: »Avstralski svilnati terier je majhen in primeren za stanovanje, a njegova dolga svilnata dlaka potrebuje česanje vsak dan, zobje ščetkanje vsak dan, ušesa pa čiščenje vsaka dva tedna. V PetPrepu boste videli, ali vaš otrok zmore ta drobni, a vsakodnevni ritem.« · otroci: »Svilnati terier je majhen, a pogumen pes s svilnato modro-rjavo dlako — vsak dan ga počešeš, mu umiješ zobe in greš z njim na kratek sprehod. Hitro se uči, zato ga boš lahko kmalu kaj naučil.« · vlagatelji / partnerji: »107. pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, tremi nalogami nege, opomnikom za starše in testi za vzreditelje.«

## Odprto za Davida

- **Življenjska doba / starejše obdobje:** uporabljena je spodnja meja RKC 12 let → starejši od 108. meseca. Wikipedija navaja McMillan 2024: 13,3 leta (45 poginov) → bilo bi 120 mesecev; tabela Dogs Trust (S123) in dodatna tabela članka nista bili dosegljivi — preverite.
- **Lovski nagon:** PetMD »the prey drive inherited from their parent breeds« (na povodcu ali za ograjo) in RKC »still capable of despatching rodents« — pravilo zahteva dobeseden »high / strong prey drive«, zato čipa »močan lovski nagon« ni. Povejte, če naj ga dodam.
- **Gibanje:** RKC »Up to 30 minutes per day« (3.000 korakov) proti PetMD »at least one hour of exercise daily« (6.000) in WebMD »greater energy requirements than many toy breeds« — po pravilu velja RKC.
- **Teža:** standardi teže ne navajajo; uporabljena je PetMD / WebMD »okoli 10 lb« (4,5 kg, naša pretvorba) — ena vrednost za oba spola.
- **Višina psic:** FCI »Females: can be slightly less.« (brez številke) → shranjen je razpon psov 23–26 cm tudi za psice.
- **Česanje:** RKC »Every day« (7×) proti PetMD / WebMD »at least twice a week« (2×) — po pravilu velja RKC.
- **Ime:** »Avstralski svilnati terier« ni preverjen na straneh KZS. Potrdite ali popravite.
