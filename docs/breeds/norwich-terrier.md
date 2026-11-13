# Norwich Terrier (Norwich terier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-108 · 2026-11-13

| | |
|---|---|
| Ključ / slug | `norwich_terrier` / `norwich-terrier` (SL `norwich-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1010 — v izbirniku za japonskim činom |
| Vir podatkov | `docs/research/dog-data/data.json` → `norwich_terrier` (viri S811–S817; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: RKC (S813) |
| Izvor | FCI št. 72 »Norwich Terrier«, skupina 3, sekcija 2 (mali terierji), Velika Britanija (S811, S812) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 104. mesto »Norwich Terrier« (S174, zapisano 2026-11-12) |
| PR-ji | pet-prep #1097 (pasma) · portret: sledi · spletna stran: pet-prep-website `feat/register-norwich-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-13 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček / mlad 10 min × starost do 60 (pri 6 mesecih) | RKC S813 »Up to 1 hour per day« (»up to N« → N); PetMD S814 in AKC S816 števila ne navajata | #1097 |
| 2026-11-13 | pravilo runbooka | obdobja 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 — **začasno**, dokler ni preverjena mediana McMillan 2024 | AAHA S11; starejši = 0,75 × spodnja meja RKC »Over 10 years« (S813) × 12 = 90 (kot bostonski terier). Tabela Dogs Trust S123 ni bila dosegljiva; Wikipedija (S817, raven C) navaja McMillan 14 let → 126 — ne sama. Alternativi 108 (PetMD 12 let), 84 (Dogs Trust > 7 let) | #1097 |
| 2026-11-13 | pravilo runbooka | učenje **1,2×** | Coren 38. mesto (»Above Average Working Dogs«, tabela Wikipedia S35) | #1097 |
| 2026-11-13 | pravilo runbooka | teža **5,4 kg** (oba spola, ≈ 12 funtov), višina 25 cm, rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | standard UKC S815 »Ideal weight is approximately 12 pounds« (FCI in RKC teže ne navajata); FCI S812 »Ideal height at the withers: 25 cm.«; PetMD S10 (razred »Small«) | #1097 |
| 2026-11-13 | pravilo runbooka (M5-R13) | česanje **2× na teden**; skuštrana dlaka po 1 zamujenem | RKC »More than once a week« nima števila → PetMD S814 »regular brushing (at least twice a week)« → 2 | #1097 |
| 2026-11-13 | pravila »Breed care tasks« (David 2026-10-24) | **ščetkanje zob 1× na teden** in **striženje krempljev vsake 4 tedne** (otrokovi mini igri), **opomnik za kopanje vsake 3 mesece** (staršu, brez točk) | PetMD S814 »brush your dog's teeth at least once a week«, »about once a month« (kremplji), »Baths should be limited to once every three months«; ušesa »Regularly« in trimanje »a few times a year« brez pogostosti → brez naloge | #1097 |
| 2026-11-13 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S814 »Socializing your Norwich Terrier puppy from an early age is crucial« | #1097 |
| 2026-11-13 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila po obroku in **brez** poletnega opozorila | noben vir ne navaja premora med obrokom in gibanjem; pasma ni ploskoobrazna | #1097 |
| 2026-11-13 | pravilo runbooka | oznake: primeren za **»življenje v stanovanju«**, **»družinsko življenje«**, **»družino z otroki«**; upoštevajte **»potrebuje miselne izzive«**, **»rad pobegne — potrebuje varno, visoko ograjo«**, **»izpada mu dlaka«**, **»potrebuje česanje večkrat na teden«** | RKC S813 »Size of home: Flat/ Apartment«, »Sheds: Yes«, »Grooming: More than once a week«; PetMD S814 »excellent companions for families«, »typically get along well with children and other pets«, »daily exercise and mental stimulation«, »A secure leash and a well-fenced yard are a must.« | #1097 |
| 2026-11-13 | pravilo runbooka | **brez** `small_children`, `other_pets` (PetMD hkrati: lovski nagon, »chase small animals« — nasprotje), `chases_small_pets` (noben vir ne reče »prey drive«, kot pri cairn terierju), `needs_company`; nikoli »hypoallergenic« | ni A/B navedbe ali je nasprotujoča | #1097 |
| 2026-11-13 | pravilo runbooka (§3) | **v aplikaciji da, v marketingu da** | ni ploskoobrazna; RKC Breed Watch kategorija 2 brez navedenih točk → brez čipa dobrobiti; nobeno veterinarsko združenje ne odsvetuje oglaševanja | #1097 |
| 2026-11-13 | pravilo runbooka (§3) | barve za AI in izbirnik: **rdeč** (portret, največja utež), pšenično rumen, črno-rjav, sivkast (grizzle); pokonci stoječa ušesa, žimasta dlaka z ovratnikom, naraven rep; brez belih lis | FCI S812 »All shades of red, wheaten, black and tan or grizzle.«; uteži brez vira → `verified: false` | #1097 |
| 2026-11-13 | pravilo runbooka (§2.3) | seznam za vzreditelja: genetska raznolikost (minimum) — drugih testov stran RKC ne navaja; brez novega ključa | RKC S813 | #1097 |
| 2026-11-13 | pravilo runbooka | zdravstveni ključi registra: `kneecap_luxation`, `dental_disease_home_brushing`, `collapsing_trachea` (vsi obstoječi); brez odstotkov | PetMD S814 | #1097 |
| 2026-11-13 | predlog Claude, David lahko spremeni | EN »Norwich Terrier«, SL **»Norwich terier«**; iskanje tudi »norwich terier«, »norwiški terier«, »norwich« | RKC / AKC / FCI; strani KZS nisem prebral | #1097 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »norwich terrier«, »norwich terier« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-108 | 2026-11-13 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 90. meseca; 4 → 3 → 2 obroka; učenje 1,2×) | 🛠 | 💶 izziv | M5-R10-108 | 2026-11-13 |
| Česanje 2× na teden, ščetkanje zob 1× na teden, kremplji vsake 4 tedne, opomnik za kopanje vsake 3 mesece, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R15 / M5-R19 / M5-R18 / M5-R17 | 2026-11-13 |
| Barva (izbirnik: rdeč, pšenično rumen, črno-rjav, sivkast) | 🛠 | 💶 izziv | M5-R21 | 2026-11-13 |
| AI portret v registru | sledi (portretni PR) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/norwich-terrier`, `/sl/zivali/psi/norwich-terier`) | 🗓 šele s portretom; nato »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal norwich terierja — enega najmanjših terierjev (približno 5,4 kg, 25 cm) iz Velike Britanije, s pokonci stoječimi ušesi in žimasto dlako; 104. najbolj priljubljeno pasmo po AKC 2024 in 100. pasmo psa v aplikaciji. 7 novih virov (S811–S817). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do 1 ure gibanja), česanje 2× na teden, ščetkanje zob vsak teden in striženje krempljev vsake 4 tedne; starejši postane pri 90 mesecih (začasno, po spodnji meji RKC).
**Zakaj je pomembno:** Norwich je majhen in primeren za stanovanje, a je zelo živahen terier z lovskim nagonom — potrebuje vsakodnevno gibanje, miselne izzive in varno ograjen vrt. PetPrep pokaže, ali družina zmore vsakdanje sprehode in redno nego (zobje, kremplji, dlaka), ki jo tako majhen pes potrebuje.
**Kako povedati** — starši: »Norwich terier je majhen, a zelo dejaven pes, ki rad koplje in lovi. V PetPrepu boste videli, ali vaš otrok zmore vsakodnevni sprehod in tedensko nego zob in dlake.« · otroci: »Norwich je mali raziskovalec — vsak dan greš z njim ven, dvakrat na teden ga počešeš, enkrat na teden mu očistiš zobke.« · vlagatelji / partnerji: »Stota pasma psa — samodejni nočni zagon jo je sam izbral po javni lestvici AKC in dodal z viri ter s štirimi pravili nege iz veterinarskih virov.«

## Odprto za Davida

- **Starejši pri 90 mesecih (začasno):** mediana McMillan 2024 (tabela Dogs Trust S123) v tem zagonu ni bila dosegljiva; Wikipedija (raven C) navaja 14 let → 126 mesecev. Ko bo tabela preverjena, je za spremembo potrebna podatkovna migracija (vrstice so že posejane).
- **Teža:** samo standard UKC »approximately 12 pounds« (5,4 kg, en podatek za oba spola); FCI / RKC teže ne navajata.
- **Lovski nagon:** PetMD »strong hunting instincts, which may lead your Norwich to chase small animals« — po pravilu brez čipa `chases_small_pets` (ni besed »prey drive«, kot pri cairn terierju). Želite čip vseeno?
- **Otroci:** čip »družino z otroki« po PetMD; Purina UK (raven C, ni vir) piše, da je pasma bolj za starejše otroke.
- **RKC Breed Watch kategorija 2:** točke skrbi niso bile vrnjene — brez čipa dobrobiti (kot pomeranec / leonberger).
- **Ime:** »Norwich terier« v slovenščini ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** število pasem psov v FEATURES / predstavitvah (zdaj 100) se po pravilu »Own files only« ne spreminja v tem zagonu.
