# Bradati koli (Bearded Collie)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-144 · 2026-11-27

| | |
|---|---|
| Ključ / slug | `bearded_collie` / `bearded-collie` (SL `bradati-koli`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1350 — v izbirniku za gladkodlakim foksterierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `bearded_collie` (viri S1076–S1083; ponovno S10, S11, S14, S24, S35, S45, S123, S174); seznam za vzreditelja: ni preverjen (seznam testov RKC ni bil vrnjen) |
| Izvor | FCI št. 271 »Bearded Collie«, skupina 1, sekcija 1 (ovčarski psi), Velika Britanija (S1076) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 140. mesto »Bearded Collie« (S174, prebrano 2026-11-27) |
| PR-ji | pet-prep #1673 (pasma, združen `512cb5c` 2026-11-27) · portret: zagon `breed-portraits.yml` 45933513338 (skrilasto siv z belimi lisami, sprejet v prvem poskusu), pet-prep PR `feat/M5-R10-144-bearded-collie-portrait` · barvne različice: pet-prep PR `feat/M5-R10-144-bearded-collie-colours` · spletna stran: pet-prep-website `feat/register-bearded-collie` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-27 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček / mlad 10 min × starost do 60 (pri 6 mesecih) | RKC S1078 »Exercise: Up to 1 hour per day« (»up to N« → N); PDSA S1079 »1 hour« in »1-2 hours« → spodnja meja 60 se ujema | #1673 |
| 2026-11-27 | pravilo runbooka | obdobja 0 / 9 / 36 / **125**; prihod 2 / 9 / 36 / 125 | AAHA S11; starejši = 0,75 × mediana **13,9 leta** (McMillan 2024 prek tabele Dogs Trust S123, vrstica zabeležena 2026-11-11; Wikipedia S1083 se ujema) × 12 = 125,1 → 125. Alternativi 108 (RKC »Over 12 years«), 84 (Dogs Trust > 7 let) | #1673 |
| 2026-11-27 | pravilo runbooka | učenje **1,2×** | Coren 34. mesto »Bearded Collie« (skupina »Above Average«, S35, zabeleženo 2026-10-20) → 1,2 | #1673 |
| 2026-11-27 | pravilo runbooka | teža **20–25 kg** (oba spola), višina samci 53–56 cm, samice 51–53 cm, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | standarda FCI (S1076) in KC (S1077) teže ne navajata → PDSA S1079 »20-25kg«; PetMD 45–55 lb, WebMD 40–50 lb kot alternativi; višina FCI »Males 53– 56cm«, »Females 51–53 cm«; PetMD S10 (razred »Medium«) | #1673 |
| 2026-11-27 | pravilo runbooka (M5-R13) | česanje **vsak dan (7× na teden)**; skuštrana dlaka po 4 zamujenih | RKC S1078 »Grooming: Every day« (»Every day« → 7); PDSA »daily grooming«, PetMD (klub pasme) 10–15 minut vsak dan, WebMD »on a daily basis« se ujemajo | #1673 |
| 2026-11-27 | pravila »Breed care tasks« (David 2026-10-24) | **brez** nalog za zobe, ušesa, kremplje, kopanje in frizerja | ni pogostosti za pasmo: WebMD »brushing their teeth daily« je splošen stavek o psih; PetMD ušesa »regularly«; WebMD kremplji »on a regular basis«; PetMD kopanje »routine bathing«; o frizerju ni vira (dlake se po standardu ne striže) | #1673 |
| 2026-11-27 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S1081 »Begin socializing your Bearded Collie puppy as soon as you bring them home, …« | #1673 |
| 2026-11-27 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila po obroku in **brez** poletnega opozorila | noben vir ne navaja premora med obrokom in gibanjem; pasma ni ploskoobrazna | #1673 |
| 2026-11-27 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družinsko življenje«**, **»družino z otroki«**, **»veliko hišo z vrtom«**; upoštevajte **»potrebuje miselne izzive«**, **»pri igri lahko »pase« otroke«**, **»rad ima družbo«**, **»potrebuje česanje večkrat na teden«**, **»izpada mu dlaka«** | RKC S1078 (»Up to 1 hour per day«, »Large house«, »Large garden«, »Every day«, »Sheds: Yes«); PDSA S1079 (»high energy levels«, »perfect family pets«, »herd at home too – adults and children included!«, »daily grooming«); PetMD S1081 »gentle around children« (nadzor); WebMD S1082 (tesnoba, če je dolgo sam; telesna in miselna zaposlitev) | #1673 |
| 2026-11-27 | pravilo runbooka | **brez** čipov `small_children` (nadzor, pasenje otrok), `chases_small_pets` (noben vir ne omenja močnega lovskega nagona — le pasenje), `chews_when_bored` (WebMD: grizenje zaradi slabe vzgoje), `apartment`, `long_daily_exercise`; nikoli »hypoallergenic« (WebMD: »not a hypoallergenic breed«) | ni A/B navedbe | #1673 |
| 2026-11-27 | pravilo runbooka (§3) | **v aplikaciji da, v marketingu da** (nikoli kot hipoalergen, nikoli ostrižen) | RKC Breed Watch »Category 1« (S1078) → brez čipa dobrobiti; nobeno veterinarsko združenje ne odsvetuje oglaševanja | #1673 |
| 2026-11-27 | pravilo runbooka (§3) | barve za AI in izbirnik: **skrilasto siv z belimi lisami** (portret), črn, moder, rjav, rdečkasto plav in peščen — vsi z belimi lisami; dlaka nikoli ostrižena, nikoli merle | FCI S1076 »Slate grey, reddish-fawn, black, blue, all shades of grey, brown and sandy with or without white markings.«, »Never merle/dapple.«; »z belimi lisami« za vse barve (Claude — standard dovoli z ali brez) | #1673 |
| 2026-11-27 | pravilo runbooka (§2.3) | seznam za vzreditelja: **ni preverjen** (`not_found`) — prikazan je le splošni seznam za pse | stran RKC A–Z ni bila dosegljiva; stran RKC (S1078) je vrnila naslov »Important health schemes and tests« brez seznama | #1673 |
| 2026-11-27 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia_arthritis`, `addisons_disease`, `pra_inherited_blindness`, `itchy_skin_allergy`, `autoimmune_conditions` (IMHA), `hypothyroidism`; brez odstotkov | PetMD S1081, PDSA S1079, WebMD S1082 | #1673 |
| 2026-11-27 | predlog Claude, David lahko spremeni | EN »Bearded Collie«, SL **»Bradati koli«**; iskanje tudi »beardie«, »highland collie«, »bradati collie« | FCI / RKC / AKC; strani KZS nisem prebral | #1673 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »bradati koli«, »beardie«, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-144 | 2026-11-27 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 125. meseca; 4 → 3 → 2 obroka; učenje 1,2×) | 🛠 | 💶 izziv | M5-R10-144 | 2026-11-27 |
| Česanje vsak dan, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-27 |
| Barva (izbirnik: skrilasto sivo-bel, črno-bel, modro-bel, rjavo-bel, rdečkasto plavo-bel, peščeno-bel) | 🛠 | 💶 izziv | M5-R21 | 2026-11-27 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), skrilasto siv z belimi lisami, nestrižena kosmata dlaka z brado, svetlo sivo studijsko ozadje | — | M5-R11-04 | 2026-11-27 |
| Stran na petprep.si (`/animals/dogs/bearded-collie`, `/sl/zivali/psi/bradati-koli`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal bradatega kolija — srednje velikega škotskega ovčarja z dolgo, kosmato dlako in brado (20–25 kg, 51–56 cm), 140. najbolj priljubljeno pasmo po AKC 2024. Osem novih virov (S1076–S1083). Odrasel pes v igri potrebuje 6.000 korakov na dan (do 1 ure gibanja), **vsakodnevno česanje** in miselne izzive; starejši postane pri 125 mesecih (mediana 13,9 leta).
**Zakaj je pomembno:** Bradati koli je nežen, igriv družinski pes — a njegova dolga dvojna dlaka potrebuje česanje vsak dan, rad »pase« otroke in ne mara biti dolgo sam. To so stvari, ki jih starši najpogosteje podcenijo.
**Kako povedati** — starši: »Bradati koli je prijazen in igriv, a njegova dlaka potrebuje česanje vsak dan, rad ima družbo in pri igri lahko pase otroke. V PetPrepu boste videli, ali vaš otrok zmore vsakodnevno česanje in sprehod.« · otroci: »Bradati koli ima dolgo kosmato dlako in brado — vsak dan ga počešeš in greš z njim ven, rad pa se tudi igra in uči.« · vlagatelji / partnerji: »Nočni zagon je pasmo sam izbral po javni lestvici AKC, jo opremil z osmimi viri in pravili nege; ko standard teže ne navaja, je teža iz veterinarske dobrodelne organizacije PDSA.«

## Odprto za Davida

- **Teža:** standarda FCI in KC teže ne navajata — uporabljena je PDSA »20-25kg« (vir B).
- **Življenjska doba:** mediana 13,9 leta je iz vrstice tabele Dogs Trust, zabeležene 2026-11-11 (v tem zagonu je bila tabela zavrnjena); Wikipedia navaja isto.
- **Zobje:** WebMD omenja vsakodnevno ščetkanje zob le v splošnem stavku o psih → naloga ni dodana. Če želite, jo dodamo (7× na teden).
- **Barve:** vseh šest barv je v izbirniku »z belimi lisami« (standard dovoljuje tudi brez); »moder« in »skrilasto siv« sta si lahko podobna.
- **Iskanje:** slovensko ime vsebuje »koli«, zato iskanje »koli« zdaj najde border collieja in bradatega kolija — test iskanja (`catalogue.test.ts`) je zato prilagojen (ena vrstica); seznam izjem za `needs_company` v `BreedSuitabilityTest` dopolnjen.
- **Ime:** »Bradati koli« v slovenščini ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** število pasem psov v FEATURES / predstavitvah se po pravilu »Own files only« ne spreminja v tem zagonu.
