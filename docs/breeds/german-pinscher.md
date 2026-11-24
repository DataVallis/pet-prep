# Nemški pinč (German Pinscher)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-137 · 2026-11-24

| | |
|---|---|
| Ključ / slug | `german_pinscher` / `german-pinscher` (SL `nemski-pinc`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1280 — v izbirniku za italijanskim ptičarjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `german_pinscher` (viri S1029–S1034; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: RKC (S1031) |
| Izvor | FCI št. 184 »German Pinscher (Deutscher Pinscher)«, skupina 2, sekcija 1 (pinči in šnavcerji), Nemčija (S1029, S1030) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 133. mesto »German Pinscher« (S174, zapisano 2026-11-24) |
| PR-ji | pet-prep #1564 (pasma) · portret pet-prep PR `feat/M5-R10-137-german-pinscher-portrait` · barvne različice pet-prep PR `feat/M5-R10-137-german-pinscher-colours` · spletna stran: pet-prep-website `feat/register-german-pinscher` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-24 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček / mlad 10 min × starost do 60 (pri 6 mesecih) | RKC S1031 »Up to 1 hour per day« (»up to N« → N); PetMD S1032 in WebMD S1033 minut ne navajata | #1564 |
| 2026-11-24 | pravilo runbooka | obdobja 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 — **začasno**, dokler ni preverjena mediana McMillan 2024 | AAHA S11; starejši = 0,75 × spodnja meja RKC »Over 10 years« (S1031) × 12 = 90 (kot norfolk terier). Pasme ni na strani Dogs Trust (S15) ne v besedilu članka McMillan 2024; alternativi 108 (PetMD / WebMD 12 let), 84 (Dogs Trust > 7 let) | #1564 |
| 2026-11-24 | pravilo runbooka | učenje **1,0×** | Coren nemškega pinča ne uvršča (S35: doberman 5., afenpinč in pritlikavi pinč 37.) → pravilo »unranked« | #1564 |
| 2026-11-24 | pravilo runbooka | teža **14–20 kg**, višina 45–50 cm (oba spola), rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S1030 »Weight : Dogs and bitches: 14 to 20 kg.«, »Height at withers : Dogs and bitches: 45 to 50 cm .«; PetMD S10 (razred »Medium«) | #1564 |
| 2026-11-24 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | RKC S1031 »Once a week«; PetMD »Weekly brushing is sufficient« in WebMD »weekly brushing« se ujemata (2–3× le med močnim linjanjem — sezone ne modeliramo) | #1564 |
| 2026-11-24 | pravila »Breed care tasks« (David 2026-10-24) | **čiščenje ušes 1× na teden** in **striženje krempljev vsake 4 tedne** (otrokovi mini igri), **opomnik za kopanje vsakih 6 tednov** (staršu, brez točk) | PetMD S1032 »Weekly ear cleanings are recommended«, »about every six to eight weeks« (kopanje → spodnja meja); WebMD S1033 »nails trimmed monthly«. Zobje: WebMD navaja dnevno ščetkanje le kot eno od treh možnosti → brez naloge; frizer: ni vira | #1564 |
| 2026-11-24 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S1032 »German Pinscher puppies need to be socialized consistently from a young age« | #1564 |
| 2026-11-24 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila po obroku in **brez** poletnega opozorila | noben vir ne navaja premora med obrokom in gibanjem; pasma ni ploskoobrazna | #1564 |
| 2026-11-24 | pravilo runbooka | oznake: primeren za **»družinsko življenje«**, **»družino z otroki«**; upoštevajte **»samostojen in trmast — najbolje z izkušenim lastnikom«**, **»potrebuje miselne izzive«**, **»izpada mu dlaka«**, **»močan lovski nagon — lahko lovi manjše živali«** | RKC S1031 »fitting well into family life«, »Sheds: Yes«; PetMD S1032 »can be good with children« (z nadzorom), »opportunities for mental stimulation are important«, »strong prey drive«; WebMD S1033 »best suited for an experienced dog owner«, »manipulative and mischievous« | #1564 |
| 2026-11-24 | pravilo runbooka | **brez** `experienced_owner` (čip govori o močnem psu — WebMD navaja trmo, zato `independent_experienced_owner` kot pri šiba inu), `secure_fencing` (PetMD ograjo omenja zaradi lovskega nagona, ne zaradi pobegov), `apartment` (RKC »Small house«), `small_children` (WebMD »a little rough with young children«), `needs_company` (ni vira); nikoli »hypoallergenic« | ni A/B navedbe ali čip ne ustreza | #1564 |
| 2026-11-24 | pravilo runbooka (§3) | **v aplikaciji da, v marketingu da** | ni ploskoobrazna; RKC Breed Watch kategorija 1 (brez točk skrbi) → brez čipa dobrobiti | #1564 |
| 2026-11-24 | pravilo runbooka (§3) | barve za AI in izbirnik: **črn s porjavelimi oznakami** (portret, največja utež), **rdeč**; naravna povešena ušesa v obliki črke V (nikoli kupirana), naraven rep | FCI S1030 »Self coloured : Deer red …« / »Black and Tan : Lacquer black with red or brown markings.«; RKC (S1031) dodaja še »Blue & Tan« in »Fawn«, ki ju standard FCI ne pozna → ne rišemo; uteži brez vira → `verified: false` | #1564 |
| 2026-11-24 | pravilo runbooka (§2.3) | seznam za vzreditelja: RKC minimum **»Prioritising genetic diversity«**, brez imenovanih testov | RKC S1031 | #1564 |
| 2026-11-24 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia`, `cataracts`, `bleeding_disorder_vwd` (vsi obstoječi); brez odstotkov | PetMD S1032, WebMD S1033 | #1564 |
| 2026-11-24 | predlog Claude, David lahko spremeni | EN »German Pinscher«, SL **»Nemški pinč«** (kot »Pritlikavi pinč«); iskanje tudi »deutscher pinscher«, »srednji pinč«, »pinč« | FCI / RKC / AKC; strani KZS nisem prebral | #1564 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »nemški pinč«, »german pinscher« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-137 | 2026-11-24 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 90. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-137 | 2026-11-24 |
| Česanje 1× na teden, čiščenje ušes vsak teden, striženje krempljev vsake 4 tedne, opomnik za kopanje vsakih 6 tednov, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R20 / M5-R19 / M5-R18 / M5-R17 | 2026-11-24 |
| Barva (izbirnik: črn s porjavelimi oznakami, rdeč) | 🛠 | 💶 izziv | M5-R21 | 2026-11-24 |
| AI portret v registru | 🗓 po združitvi (črn s porjavelimi oznakami) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/german-pinscher`, `/sl/zivali/psi/nemski-pinc`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal nemškega pinča — gladkodlakega psa srednje velikosti (14–20 kg, 45–50 cm) iz Nemčije, prednika dobermana in pritlikavega pinča; 133. najbolj priljubljeno pasmo po AKC 2024. Šest novih virov (S1029–S1034). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do 1 ure gibanja), česanje 1× na teden, čiščenje ušes vsak teden in striženje krempljev vsake 4 tedne; starejši postane pri 90 mesecih (začasno, po spodnji meji RKC).
**Zakaj je pomembno:** Nemški pinč je živahen, pameten in navezan na družino, a trmast, z močnim lovskim nagonom in potrebo po miselnih izzivih. PetPrep pokaže, ali družina zmore dnevni sprehod, igro za glavo in redno nego.
**Kako povedati** — starši: »Nemški pinč je družinski pes, ki potrebuje izkušeno, dosledno vodenje in vsak dan nekaj za glavo. V PetPrepu boste videli, ali vaš otrok zmore dnevni sprehod ter tedensko nego ušes in dlake.« · otroci: »Pinč je hiter in pameten — vsak dan greš z njim ven, se z njim igraš, enkrat na teden mu očistiš ušesa in ga počešeš.« · vlagatelji / partnerji: »Samodejni nočni zagon je pasmo sam izbral po javni lestvici AKC in jo dodal s šestimi viri in tremi pravili nege iz veterinarskih virov.«

## Odprto za Davida

- **Starejši pri 90 mesecih (začasno):** mediane McMillan 2024 za nemškega pinča nisem našel (tabela Dogs Trust S123 ni bila dosegljiva); PetMD / WebMD navajata 12–14 let (→ 108). Za spremembo bo potrebna podatkovna migracija (vrstice so že posejane).
- **Kopanje:** vsakih 6 tednov po PetMD (»six to eight weeks«); WebMD piše »every four to six weeks« (→ vsake 4 tedne). Potrdite.
- **Barve:** RKC našteva tudi modro s porjavelimi oznakami in »fawn«; po standardu FCI sta le rdeča in črna s porjavelimi oznakami. Želite še kateri?
- **Portret:** črn s porjavelimi oznakami (WebMD črno navaja kot najpogostejšo osnovno barvo — moja razlaga); FCI ima rdečo na prvem mestu.
- **Ime:** »Nemški pinč« v slovenščini ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** število pasem psov v FEATURES / predstavitvah se po pravilu »Own files only« ne spreminja v tem zagonu.
