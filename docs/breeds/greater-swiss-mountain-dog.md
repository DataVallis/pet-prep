# Veliki švicarski planšarski pes (Greater Swiss Mountain Dog)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-86 · 2026-11-02

| | |
|---|---|
| Ključ / slug | `greater_swiss_mountain_dog` / `greater-swiss-mountain-dog` (SL `veliki-svicarski-plansarski-pes`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 800 — v izbirniku za rat terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `greater_swiss_mountain_dog` (viri S652–S659; ponovno S8, S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: stran RKC (S654) |
| Izvor | FCI št. 58, skupina 2, sekcija 3 (švicarski planšarski in govedarski psi), Švica (S652, S653); v Veliki Britaniji »Great Swiss Mountain Dog« (S654) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 82. mesto (S174, prebrano 2026-11-02) |
| PR-ji | pet-prep #519 (pasma) · portret: sledi · spletna stran: šele s portretom |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-02 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | stran RKC S654 »Exercise: Up to 1 hour per day« (»up to N« → N); WebMD S658 in VCA S657 le »moderate« (brez številke) | #519 |
| 2026-11-02 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 | AAHA S11; starejši = 0,75 × spodnja meja RKC »Over 10 years« (S654) = 7,5 leta. Mediane McMillan 2024 / VetCompass nisem našel → tretji vir pravila (precedens nemški ostrodlaki ptičar). Ni izbrano: 84 (Dogs Trust »> 7 let«), 72 (PetMD / WebMD 8 let) | #519 |
| 2026-11-02 | pravilo runbooka | učenje **1,0×** | Coren pasme ne razvršča (S35, preverjeno 2026-11-02) → pravilo »unranked« | #519 |
| 2026-11-02 | pravilo runbooka | teža **samci 52–64 kg, samice 39–50 kg** (WebMD 115–140 / 85–110 lb), višina 65–72 / 60–68 cm (FCI), rast do 18–24 mesecev; hrana, voda in čiščenje kot pri border collieju | WebMD S658 »Healthy males can weigh anywhere from 115 to 140 pounds.« (pregledala veterinarka); standarda FCI (S653) in AKC (S655) teže ne navajata; PetMD S656 in VCA S657 kot alternativi; PetMD S10 (razred »Giant«) | #519 |
| 2026-11-02 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | RKC S654 »Grooming: Once a week« (VCA »Brush a few times a week.« brez številke — alternativa) | #519 |
| 2026-11-02 | pravila »Breed care tasks« (David 2026-10-24, M5-R15 / R19 / R20) | otrokove mini igre: **zobje vsak dan**, **ušesa 1× na teden**, **kremplji 1× na teden**; brez opomnika za kopanje in frizerja | WebMD S658 »Brush their teeth daily«; PetMD S656 »Greater Swiss Mountain Dogs require weekly ear cleanings to prevent infections from developing.« (VCA enako); VCA S657 »Weekly ear cleanings and pedicures, plus periodic baths, …« (WebMD »Trim their nails as needed« — alternativa); kopanje »periodic« / »whenever … too dirty« → brez pogostosti | #519 |
| 2026-11-02 | David 2026-10-12 (M5-R16) | **opozorilo »počakaj s sprehodom« 60 min po obroku** (koraki se štejejo, brez kazni) | PetMD S656 »avoid using elevated food bowls and do not exercise your dog directly before or after mealtimes« (napenjanje / GDV) | #519 |
| 2026-11-02 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S656 »Early and consistent socialization is equally important for them to grow into well-mannered companions.« | #519 |
| 2026-11-02 | pravilo runbooka | oznake: primeren za »družinsko življenje« (`family_pet`), »družino z otroki« (`children`), »veliko hišo z vrtom« (`large_home`); upoštevajte »potrebuje miselne izzive«, »izpada mu dlaka«, »rad je — pazite na težo«, »gost kožuh — slabo prenaša vročino« | PetMD S656 »This makes them great family dogs who are gentle with children.« (opozorilo o nadzoru ostane v opombah); RKC S654 »Large house« / »Large garden«, »Sheds: Yes«; PetMD »Swissies need to stay active and mentally stimulated …«; WebMD S658 »The breed does have problems with obesity, so keep treats to a minimum …«; VCA S657 »Care must be taken during even mild weather to limit exercise to the coolest times of day.« | #519 |
| 2026-11-02 | pravilo runbooka | **brez** `small_children`, `needs_company`, `chases_small_pets`, `experienced_owner`, `apartment`, `active_family`, `long_daily_exercise`, `low_shedding`, `shorter_lifespan`; nikoli »hypoallergenic« | VCA ocena »Older kids«; VCA »Fine as a trained adult« (sam doma); PetMD govori o pastirskem nagonu, ne o »prey drive«; VCA »Needs an assertive owner« ne govori o izkušnjah / močnem psu; RKC »Large house«; 1 ura je pod mejo 2 ur; izpada mu dlaka; »Over 10 years« | #519 |
| 2026-11-02 | pravilo runbooka (§3) | **brez čipa dobrobiti**; marketing: **v aplikaciji da, v marketingu da** (nikoli obljuba zdravja; nikoli kot pes, ki ne linja, ali pes za majhno stanovanje) | RKC Breed Watch kategorija 1 (S654); ni plosko-obrazna pasma (BVA S92 ne velja) | #519 |
| 2026-11-02 | pravilo runbooka (§3) | barva za AI in izbirnik: **samo trobarven črn-rjavkast-bel** (tudi portret); kratka do srednje dolga gosta dvojna dlaka | FCI S653 »Typically tricolour. Main colour black with symmetrical, reddish-brown (tan) markings and clean white markings.«, izključujoča napaka »Other than tricolour coat.«; AKC S655 enako; utež brez vira → `verified: false` | #519 |
| 2026-11-02 | pravilo runbooka (§2.3) | seznam za vzreditelja: minimum »genetska raznolikost«, najboljša praksa sheme BVA/KC za komolce in kolke | stran RKC S654; brez novih ključev | #519 |
| 2026-11-02 | pravilo runbooka | zdravstveni ključi registra: `hip_elbow_dysplasia`, `bloat_gdv`, **`splenic_torsion` (nov)**, `epilepsy`, `eyelid_problems`, `urinary_incontinence`; brez odstotkov | RKC S654, PetMD S656, WebMD S658 »This condition is more common in this breed than in other large dog breeds.« (zasuk vranice) | #519 |
| 2026-11-02 | predlog Claude, David lahko spremeni | EN »Greater Swiss Mountain Dog«, SL »Veliki švicarski planšarski pes«; iskanje tudi »great swiss mountain dog«, »swissy«, »grosser schweizer sennenhund«, »švicarski planšar« | ime se uporablja v slovenskem tisku (Žurnal24); strani KZS nisem preverjal | #519 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »švicarski planšar«, »swissy« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-86 | 2026-11-02 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 90. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-86 | 2026-11-02 |
| Česanje 1× na teden, »Nova izkušnja« za mladička, opozorilo 60 min po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 | 2026-11-02 |
| Mini igre: zobje vsak dan, ušesa in kremplji 1× na teden | 🛠 | 💶 izziv | M5-R15 / M5-R20 / M5-R19 | 2026-11-02 |
| Barva (izbirnik: 1 standardna barva — trobarven) | 🛠 | 💶 izziv | M5-R21 | 2026-11-02 |
| AI portret v registru | 🗓 sledi | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/greater-swiss-mountain-dog`, `/sl/zivali/psi/veliki-svicarski-plansarski-pes`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal velikega švicarskega planšarskega psa — težkega, mišičastega trobarvnega vlečnega psa iz Švice, »velikega brata« bernskega planšarja s kratko dlako; 82. najbolj priljubljeno pasmo po AKC 2024 in 79. plačljivo pasmo psa. 8 novih virov (S652–S659). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do ene ure gibanja), česanje enkrat na teden, otrok mu vsak dan umije zobe, enkrat na teden očisti ušesa in pristriže kremplje, po obroku pa aplikacija opozori, naj s sprehodom počaka.
**Zakaj je pomembno:** Tak pes je prijazen do otrok in družine, a zelo velik (do 64 kg), slabo prenaša vročino, izpada mu dlaka in hitro se zredi; pri velikih psih z globokim prsnim košem je nevarno napenjanje želodca, zato po jedi ni divjanja. PetPrep pokaže, ali otrok vsak dan zmore sprehod, nego in mirno rutino po obroku — 12 tednov, preden pride pravi pes.
**Kako povedati** — starši: »Veliki švicarski planšarski pes je po PetMD odličen družinski pes, nežen z otroki, a potrebuje veliko hišo z vrtom, vsakodnevno nego zob in tedensko nego ušes. V PetPrepu boste videli, ali vaš otrok zmore takšno odgovornost.« · otroci: »Švicarski planšar je velik, močan in prijazen — vsak dan greš z njim na sprehod, mu umiješ zobe, enkrat na teden ga počešeš, mu očistiš ušesa in pristrižeš kremplje, po jedi pa malo počakata.« · vlagatelji / partnerji: »Devetinsedemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Življenjska doba:** mediane McMillan 2024 / VetCompass nisem našel; uporabljena je spodnja meja RKC »Over 10 years« → starejši od 90. meseca (pravilo). PetMD / WebMD navajata 8–11 let, Wikipedia (C) ameriško anketo kluba z mediano 6,75 leta — ni uporabljeno. Če tabela Dogs Trust (S123) pasmo vsebuje, preverite.
- **Teža:** noben standard je ne navaja; vzeta je WebMD (po spolu). PetMD in VCA navajata podobne razpone.
- **Ime:** »Veliki švicarski planšarski pes« se uporablja v slovenskem tisku; strani KZS nisem preverjal. Potrdite ali popravite.
- **Oznaka `experienced_owner`:** VCA piše »Needs an assertive owner and a job to do.« — po pravilu brez čipa (čip govori o močnem psu). Če ga želite, povejte.
- **Nov zdravstveni ključ registra `splenic_torsion`** (zasuk vranice, WebMD) — besedilo na spletni strani je v PR spletne strani.
