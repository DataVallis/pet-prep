# Irski seter (Irish Setter / Irish Red Setter)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-89 · 2026-11-03

| | |
|---|---|
| Ključ / slug | `irish_setter` / `irish-setter` (SL `irski-seter`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 830 — v izbirniku za bruseljskim grifonom |
| Vir podatkov | `docs/research/dog-data/data.json` → `irish_setter` (viri S675–S681; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: stran RKC (S676) |
| Izvor | FCI št. 120, skupina 7, sekcija 2 (britanski in irski ptičarji in setri), Irska (S675); ni irski rdeče-beli seter (FCI št. 330, druga pasma) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 85. mesto »Irish Setter« (S174, prebrano 2026-11-03) |
| PR-ji | pet-prep #591 (pasma, združen `b1a2998`) · portret pet-prep #594 (zagon `breed-portraits.yml` 41568028702, sprejet v prvem poskusu) · spletna stran: pet-prep-website `feat/register-irish-setter` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-03 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | stran RKC S676 »Exercise: More than 2 hours per day« (»more than N« → N); PDSA S678 »… ideally over 2 hours per day.« se ujema; 0,75 × 120 = 90 | #591 |
| 2026-11-03 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 | AAHA S11; starejši = 0,75 × spodnja meja RKC **10 let** (»Over 10 years«, S676; PDSA enako) = 7,5 leta = 90 mesecev. McMillan 2024 / VetCompass ni dosegljiv → tretji vir pravila (začasno). Ni izbrano: 116 (McMillan 12,9 leta le prek Wikipedie, S681), 84 (Dogs Trust »> 7 let«) | #591 |
| 2026-11-03 | pravilo runbooka | učenje **1,2×** | Coren: 35. mesto »Irish Setter« (skupaj s cairn terierjem in kerry blue terierjem), skupina »Above Average« (27–39) (S35, preverjeno 2026-11-03) | #591 |
| 2026-11-03 | pravilo runbooka | teža **27,2–31,8 kg** (AKC: psica 60 lb, pes okoli 70 lb), višina psi 58–67 cm / psice 55–62 cm (FCI), rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | AKC standard S680; FCI S675 in RKC S677 teže ne navajata; PDSA S678 25–32 kg (alternativa); PetMD S10 (razred »Large«) | #591 |
| 2026-11-03 | pravilo runbooka (M5-R13) | česanje **3× na teden**; skuštrana dlaka po 2 zamujenih | RKC S676 »Grooming: More than once a week« brez števila → PDSA S678 »… they'll need grooming 2 or 3 times per week.« (razpon → zgornja meja 3); PetMD S679 »daily (or at least 2-3 times a week)« = alternativa | #591 |
| 2026-11-03 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PDSA S678 »As with all breeds, early socialisation is important to ensure they grow up into confident, sociable dogs.« | #591 |
| 2026-11-03 | pravila »Breed care tasks« (David 2026-10-24, M5-R19 / R20) | mini igri **čiščenje ušes 1× na teden** in **striženje krempljev 1× na mesec** (vsake 4 tedne) | PetMD S679 »Weekly ear checks and cleaning are recommended for Irish Setters.«; »trim their toenails and fur each month.« Zobje, kopanje, frizer: brez pogostosti → brez naloge | #591 |
| 2026-11-03 | pravilo runbooka | brez opozorila po obroku (M5-R16), brez poletnega opozorila za sprehod | viri omenjajo GDV / napihnjenost in dva manjša obroka namesto enega, ne pa premora pred gibanjem; ni ploskoobrazna pasma | #591 |
| 2026-11-03 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družino z otroki«**, **»dom z drugimi ljubljenčki«**, **»veliko hišo z vrtom«**; upoštevajte **»vsak dan potrebuje veliko gibanja«**, **»rad ima družbo«**, »izpada mu dlaka«, »potrebuje česanje večkrat na teden« | RKC S676 »More than 2 hours per day«, »Large house«, »Sheds: Yes«, »More than once a week«; PDSA S678 »Irish Setters are very friendly, affectionate dogs that get on well with both children and other pets,«; PetMD S679 »They definitely like human interaction, so they need as much house time and human contact as possible.« | #591 |
| 2026-11-03 | pravilo runbooka | **brez** `family_pet`, `small_children`, `not_for_small_children`, `apartment`, `experienced_owner`, `often_alone`, `low_shedding`, `shorter_lifespan`, `chases_small_pets`, `hips_hind_legs`; nikoli »hypoallergenic« | družinski pes le v opisu cele skupine Gundog (RKC); PetMD: »toddlers are at risk of being accidentally knocked down« (opozorilo, ne odsvetovanje → v opombi); RKC »Large house«; ni izjave o lovskem nagonu; Breed Watch kategorija 1 | #591 |
| 2026-11-03 | pravilo runbooka (§3, dobrobit) | **ni pasma s skrbmi za dobrobit** (RKC Breed Watch kategorija 1) → brez opozorilnega čipa; **v aplikaciji da, v marketingu da** (nikoli kot »lahek pes za malčke«) | S676 | #591 |
| 2026-11-03 | pravilo runbooka (§3) | barva za AI in izbirnik: **samo bogato kostanjevo rdeča** (edina barva standarda; največ majhna bela lisa na prsih ali prstih); portret kostanjevo rdeč, naraven rep | FCI S675 »Rich chestnut with no trace of black; white on chest, throat, and toes.«; RKC S677, AKC S680 enako; nikoli rdeče-bel ali črn; utež brez vira → `verified: false` | #591 |
| 2026-11-03 | pravilo runbooka (§2.3) | seznam za vzreditelja: minimum »Prioritising genetic diversity« (`inbreeding_coi`) in DNK test PRA rcd4 (`dna_rcd4_pra`); najboljša praksa DNK test CLAD (**nov ključ `dna_clad`**), DNK test PRA(rcd1) (**nov ključ `dna_pra_rcd1`**), sheme za komolce, oči in kolke | stran RKC S676 | #591 |
| 2026-11-03 | pravilo runbooka | zdravstveni ključi registra: `bloat_gdv`, `hip_dysplasia`, `pra_eye_disease`, `itchy_skin_allergy`, `hypothyroidism`, `epilepsy`, `certain_cancers`; brez odstotkov, brez novega ključa | PDSA S678, PetMD S679 | #591 |
| 2026-11-03 | predlog Claude, David lahko spremeni | EN »Irish Setter«, SL »Irski seter«; iskanje tudi »irish red setter«, »red setter«, »setter«, »irski rdeči seter«, »rdeči seter«, »seter« | strani KZS nisem preveril | #591 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 3× na teden«, iskanje »irski seter«, »red setter« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-89 | 2026-11-03 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90. meseca; 4 → 3 → 2 obroka; učenje 1,2×) | 🛠 | 💶 izziv | M5-R10-89 | 2026-11-03 |
| Česanje 3× na teden, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-03 |
| Čiščenje ušes 1× na teden, striženje krempljev vsake 4 tedne (mini igri) | 🛠 | 💶 izziv | M5-R19 / M5-R20 | 2026-11-03 |
| Barva (izbirnik: kostanjevo rdeča — edina) | 🛠 | 💶 izziv | M5-R21 | 2026-11-03 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), kostanjevo rdeč, naraven rep | — | M5-R11-04 | 2026-11-03 |
| Stran na petprep.si (`/animals/dogs/irish-setter`, `/sl/zivali/psi/irski-seter`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal irskega setra — elegantnega, kostanjevo rdečega lovskega psa iz Irske; 85. najbolj priljubljeno pasmo po AKC 2024 in 82. plačljivo pasmo psa. 7 novih virov (S675–S681). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot dve uri gibanja), česanje trikrat na teden, otrok mu enkrat na teden očisti ušesa in vsake 4 tedne postriže kremplje.
**Zakaj je pomembno:** Seter je prijazen, ljubeč in se razume z otroki in drugimi živalmi (PDSA), a je zelo energičen in potrebuje veliko družbe — ni pes, ki bi ga pustili samega na vrtu (PetMD). Prav to PetPrep preizkusi: dve uri gibanja vsak dan, dvanajst tednov zapored.
**Kako povedati** — starši: »Irski seter je čudovit družinski spremljevalec, a potrebuje več kot dve uri gibanja na dan, redno česanje in veliko vaše družbe. V PetPrepu boste videli, ali vaš otrok zmore tak ritem.« · otroci: »Seter je rdeč, hiter in vesel pes — vsak dan greš z njim na dolg sprehod, ga večkrat na teden počešeš in mu enkrat na teden očistiš ušesa.« · vlagatelji / partnerji: »Dvainosemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, nalogami nege in testi za vzreditelje.«

## Odprto za Davida

- **Življenjska doba / starejše obdobje:** uporabljena je spodnja meja RKC 10 let → starejši od 90. meseca (začasno). Wikipedia navaja McMillan 2024 12,9 leta (to bi dalo 116); tabela Dogs Trust (S123) ni bila dosegljiva — preverite.
- **Malčki:** PetMD opozarja, da lahko seter malčka nehote podre; čipa »ni za majhne otroke« po pravilu ni (ni izrecnega odsvetovanja). Če ga želite, povejte.
- **Ime:** »Irski seter« ni preverjen na straneh KZS. Potrdite ali popravite.
- **Zobje, kopanje, frizer:** viri ne navajajo pogostosti → ni naloge (pravilo).
