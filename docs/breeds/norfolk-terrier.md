# Norfolk terier (Norfolk Terrier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-134 · 2026-11-23

| | |
|---|---|
| Ključ / slug | `norfolk_terrier` / `norfolk-terrier` (SL `norfolk-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1250 — v izbirniku za finskim laponskim psom |
| Vir podatkov | `docs/research/dog-data/data.json` → `norfolk_terrier` (viri S1009–S1018; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: RKC (S1011) |
| Izvor | FCI št. 272 »Norfolk Terrier«, skupina 3, sekcija 2 (mali terierji), Velika Britanija (S1009, S1010) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 130. mesto »Norfolk Terrier« (S174, zapisano 2026-11-23) |
| PR-ji | pet-prep #1527 (pasma) · portret: sledi · barvne različice: sledijo · spletna stran: pet-prep-website `feat/register-norfolk-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-23 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček / mlad 10 min × starost do 60 (pri 6 mesecih) | RKC S1011 »Up to 1 hour per day« (»up to N« → N); PDSA S1012 in Woodgreen S1013 »around an hour« se ujemata | #1527 |
| 2026-11-23 | pravilo runbooka | obdobja 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 — **začasno**, dokler ni preverjena mediana McMillan 2024 | AAHA S11; starejši = 0,75 × spodnja meja RKC »Over 10 years« (S1011) × 12 = 90 (kot norwich terier). Wikipedija (S1017, raven C) navaja McMillan 13,5 leta → 122 — ne sama. Alternativi 108 (Woodgreen / PetMD 12 let), 84 (Dogs Trust > 7 let) | #1527 |
| 2026-11-23 | pravilo runbooka | učenje **0,7×** | Coren 56. mesto (»Fair Working/Obedience Intelligence«, tabela Wikipedia S35); PDSA: osnovno šolanje in odpoklic nista vedno lahka | #1527 |
| 2026-11-23 | pravilo runbooka | teža **5,0–5,4 kg** (oba spola, 11–12 funtov), višina 25 cm, rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | standard UKC S1016 »Weight ranges from 11 to 12 pounds« (FCI in RKC teže ne navajata; PDSA 5–5,5 kg); FCI S1010 »Ideal height at the withers: 25 cm.«; PetMD S10 (razred »Small«) | #1527 |
| 2026-11-23 | pravilo runbooka (M5-R13) | česanje **2× na teden**; skuštrana dlaka po 1 zamujenem | RKC »More than once a week« nima števila → Woodgreen S1013 »once or twice a week« → 2 (zgornja meja); PDSA »a couple of times per week« se ujema | #1527 |
| 2026-11-23 | pravila »Breed care tasks« (David 2026-10-24) | **ščetkanje zob vsak dan** in **čiščenje ušes 1× na teden** (otrokovi mini igri), **opomnik za kopanje vsake 4 tedne** in **opomnik za frizerja / ročno trimanje vsakih 12 tednov** (staršu, brez točk) | WebMD S1015 »daily brushing«; PetMD S1014 »every one or two weeks« (ušesa → spodnja meja), »about every four to six weeks« (kopanje → spodnja meja); PDSA S1012 »stripping three to four times per year« → vsake 3 mesece (kot žimavi foksterier). Kremplji: noben vir ne navaja pogostosti → brez naloge | #1527 |
| 2026-11-23 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PDSA S1012 »They will also need early socialisation with other household pets.« | #1527 |
| 2026-11-23 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila po obroku in **brez** poletnega opozorila | noben vir ne navaja premora med obrokom in gibanjem; pasma ni ploskoobrazna | #1527 |
| 2026-11-23 | pravilo runbooka | oznake: primeren za **»življenje v stanovanju«**, **»družino z otroki«**; upoštevajte **»rad ima družbo«**, **»rad pobegne — potrebuje varno, visoko ograjo«**, **»potrebuje česanje večkrat na teden«**, **»močan lovski nagon — lahko lovi manjše živali«** | RKC S1011 »Size of home: Flat/ Apartment«, »Grooming: More than once a week«; Woodgreen S1013 »gentle and patient with children« (z nadzorom), »don't enjoy being left alone for long stretches of time«, »a secure garden with an escape-proof fence is a must«, »They have a strong prey drive«; PDSA S1012 česanje + trimanje | #1527 |
| 2026-11-23 | pravilo runbooka | **brez** `sheds` (RKC »Yes« proti Woodgreen »Minimal« in Dogs Trust »shed very little« — nasprotje), `family_pet` (ni izrecne navedbe), `small_children` (Woodgreen zahteva nadzor), `other_pets`, `needs_mental_stimulation` (samo parafraza PetMD); nikoli »hypoallergenic« | ni A/B navedbe ali je nasprotujoča | #1527 |
| 2026-11-23 | pravilo runbooka (§3) | **v aplikaciji da, v marketingu da** | ni ploskoobrazna; RKC Breed Watch kategorija 1 (brez točk skrbi) → brez čipa dobrobiti; nobeno veterinarsko združenje ne odsvetuje oglaševanja | #1527 |
| 2026-11-23 | pravilo runbooka (§3) | barve za AI in izbirnik: **rdeč** (portret, največja utež), pšenično rumen, črno-rjav, sivkast (grizzle); povešena ušesa v obliki črke V, žimasta dlaka, naraven rep; brez belih lis | FCI S1010 »All shades of red, wheaten, black and tan or grizzle.«; uteži brez vira → `verified: false` | #1527 |
| 2026-11-23 | pravilo runbooka (§2.3) | seznam za vzreditelja: **RKC ne priporoča testov pred vzrejo** (`none_recommended`) | RKC S1011 »At this time this breed does not have any recommended pre-breeding health screening in place« | #1527 |
| 2026-11-23 | pravilo runbooka | zdravstveni ključi registra: `kneecap_luxation`, `hip_dysplasia_arthritis`, `cataracts_lens_luxation`, `mitral_valve_disease`, `dental_disease_home_brushing` (vsi obstoječi); brez odstotkov | PDSA S1012, Woodgreen S1013, PetMD S1014, WebMD S1015 | #1527 |
| 2026-11-23 | predlog Claude, David lahko spremeni | EN »Norfolk Terrier«, SL **»Norfolk terier«** (kot »Norwich terier«); iskanje tudi »norfolk terier«, »norfolški terier«, »norfolk« | RKC / AKC / FCI; strani KZS nisem prebral | #1527 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »norfolk terrier«, »norfolk terier« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-134 | 2026-11-23 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 90. meseca; 4 → 3 → 2 obroka; učenje 0,7×) | 🛠 | 💶 izziv | M5-R10-134 | 2026-11-23 |
| Česanje 2× na teden, ščetkanje zob vsak dan, čiščenje ušes vsak teden, opomnik za kopanje vsake 4 tedne, opomnik za frizerja vsakih 12 tednov, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R15 / M5-R20 / M5-R18 / M5-R17 | 2026-11-23 |
| Barva (izbirnik: rdeč, pšenično rumen, črno-rjav, sivkast) | 🛠 | 💶 izziv | M5-R21 | 2026-11-23 |
| AI portret v registru | 🗓 sledi v tem zagonu | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/norfolk-terrier`, `/sl/zivali/psi/norfolk-terier`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal norfolk terierja — enega najmanjših terierjev (5–5,4 kg, 25 cm) iz Velike Britanije, s povešenimi ušesi in žimasto dlako; 130. najbolj priljubljeno pasmo po AKC 2024. 10 novih virov (S1009–S1018), med njimi strani PDSA in Woodgreen. Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do 1 ure gibanja), česanje 2× na teden, ščetkanje zob vsak dan in čiščenje ušes vsak teden; starejši postane pri 90 mesecih (začasno, po spodnji meji RKC).
**Zakaj je pomembno:** Norfolk je majhen in primeren za stanovanje, a je pravi terier: rad koplje, rad pobegne skozi luknjo v ograji, ima močan lovski nagon in ne mara biti dolgo sam. PetPrep pokaže, ali družina zmore vsakodnevni sprehod, redno nego in družbo, ki jo tak pes potrebuje.
**Kako povedati** — starši: »Norfolk terier je majhen, a zelo samosvoj pes, ki ne mara samote in potrebuje varno ograjen vrt. V PetPrepu boste videli, ali vaš otrok zmore vsakodnevni sprehod ter dnevno nego zob in tedensko nego ušes.« · otroci: »Norfolk je mali raziskovalec — vsak dan greš z njim ven, mu očistiš zobke, enkrat na teden pogledaš ušesa in ga dvakrat na teden počešeš.« · vlagatelji / partnerji: »Samodejni nočni zagon je pasmo sam izbral po javni lestvici AKC in jo dodal z desetimi viri ter s štirimi pravili nege iz veterinarskih in dobrodelnih virov.«

## Odprto za Davida

- **Starejši pri 90 mesecih (začasno):** mediana McMillan 2024 (tabela Dogs Trust S123) v tem zagonu ni bila dosegljiva na ravni A/B; Wikipedija (raven C) navaja 13,5 leta → 122 mesecev. Ko bo tabela preverjena, je za spremembo potrebna podatkovna migracija (vrstice so že posejane).
- **Teža:** samo standard UKC 11–12 funtov (5,0–5,4 kg, en razpon za oba spola); FCI / RKC teže ne navajata; Woodgreen 5–7 kg.
- **Dlaka izpada?** RKC »Sheds: Yes«, Woodgreen in Dogs Trust »malo« → po pravilu brez čipa. Želite enega od čipov?
- **Frizer:** opomnik vsakih 12 tednov po PDSA »three to four times per year«; Woodgreen piše »about three times a year« (vsake 4 mesece). Potrdite.
- **Otroci:** čip »družino z otroki« po Woodgreen (z nadzorom) in PetMD.
- **Ime:** »Norfolk terier« v slovenščini ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** število pasem psov v FEATURES / predstavitvah se po pravilu »Own files only« ne spreminja v tem zagonu.
