# Ravnodlaki prinašalec (Flat-Coated Retriever)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-101 · 2026-11-10

| | |
|---|---|
| Ključ / slug | `flat_coated_retriever` / `flat-coated-retriever` (SL `ravnodlaki-prinasalec`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 940 — v izbirniku za bordojsko dogo |
| Vir podatkov | `docs/research/dog-data/data.json` → `flat_coated_retriever` (viri S760–S766; ponovno S10, S11, S14, S24, S35, S45, S123, S174); seznam za vzreditelja: stran RKC (S762) |
| Izvor | FCI št. 121, skupina 8, sekcija 1 (prinašalci), Velika Britanija (S760, S761) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 97. mesto »Flat-Coated Retriever« (S174, prebrano 2026-11-10) |
| PR-ji | pet-prep #931 (pasma) · portret pet-prep PR `feat/M5-R10-101-flat-coated-retriever-portrait` · spletna stran: pet-prep-website `feat/register-flat-coated-retriever` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-10 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček / mlad 10 min × starost do 120 (pri 12 mesecih) | stran RKC S762 »Exercise: More than 2 hours per day« (»more than N« → N); PetMD S763 številke ne navaja; strani PDSA za pasmo ni | #931 |
| 2026-11-10 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **105**; prihod 2 / 9 / 36 / 105 | AAHA S11; starejši = 0,75 × mediana McMillan 2024 **11,7 leta** (tabela Dogs Trust S123) = 105,3 → 105 mesecev — prvi vir pravila, ni začasno. Ni izbrano: 84 (Dogs Trust »> 7 let«), 72 (spodnja meja PetMD 8 let) | #931 |
| 2026-11-10 | pravilo runbooka | učenje **1,5×** | Coren: 18. mesto, »Excellent Working Dogs« (11–26) — tabela Wikipedia (S35) | #931 |
| 2026-11-10 | pravilo runbooka | teža **25–36 kg** (FCI: psi 27–36, psice 25–32 kg), višina psi 59–61,5 cm / psice 56,5–59 cm (FCI), rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | standard FCI S761; PetMD S763 60–70 lb (alternativa); PetMD S10 (razred »Large«) | #931 |
| 2026-11-10 | pravilo runbooka (M5-R13) | česanje **2× na teden**; skuštrana dlaka po 1 zamujenem | RKC S762 »Grooming: More than once a week« brez števila → PetMD S763 »A twice-weekly brushing routine is ideal to maintain their luxurious coat« | #931 |
| 2026-11-10 | David 2026-10-12 (M5-R16) | opozorilo »počakaj s sprehodom« 60 min po obroku | PetMD S763 »Avoiding elevated bowls and exercise right before or after eating will also help stave off the life-threatening condition.« (napihnjenost / GDV) | #931 |
| 2026-11-10 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S763 »They generally mesh well with kids and other furry friends, especially with proper introductions and socialization.« | #931 |
| 2026-11-10 | pravila »Breed care tasks« (David 2026-10-24, M5-R19 / R20) | otrok: **čiščenje ušes 1× na teden**, **striženje krempljev vsake 4 tedne**; opomnik staršem: **kopanje vsake 4 tedne** (nikoli v točkah) | PetMD S763 »Cleaning your dog’s ears every week or two …« (razpon → spodnja meja, 1× na teden; precedens pekinezer); »… gets a bath … a nail trim) every month or two …« (razpon → spodnja meja, vsake 4 tedne). Zobje: brez pogostosti; frizer: vir ga ne omenja → brez naloge | #931 |
| 2026-11-10 | pravilo runbooka | brez poletnega opozorila za sprehod | ni ploskoobrazna pasma; vir ne svari pred vročino | #931 |
| 2026-11-10 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družinsko življenje«**, **»družino z otroki«**, **»dom z drugimi ljubljenčki«**, **»veliko hišo z vrtom«**; upoštevajte **»krajša življenjska doba«**, **»vsak dan potrebuje veliko gibanja«**, »izpada mu dlaka«, »potrebuje česanje večkrat na teden« | RKC S762 »More than 2 hours per day«, »Large house«, »Sheds: Yes«, »More than once a week«, »Lifespan: Under 10 years«; PetMD S763 »Flat-Coated Retrievers are wonderful family dogs that generally do well with children and other pets.« | #931 |
| 2026-11-10 | pravilo runbooka | **brez** `small_children`, `apartment`, `first_time_owner`, `experienced_owner`, `often_alone`, `needs_company`, `low_shedding`, `chases_small_pets`, `hips_hind_legs`; nikoli »hypoallergenic« | ni izrecne ocene za majhne otroke; RKC »Large house«; ni izraza »high prey drive«; Breed Watch kategorija 1 | #931 |
| 2026-11-10 | pravilo runbooka (§3, dobrobit) | **ni pasma s skrbmi za dobrobit** (RKC Breed Watch kategorija 1) → brez opozorilnega čipa; **v aplikaciji da, v marketingu da** | S762 | #931 |
| 2026-11-10 | pravilo runbooka (§3) | barve za AI in izbirnik: **črna** (portret, največja utež) in **jetrno rjava**; nikoli rumena ali kremna; naraven rep | FCI S761 »Colour: Black or liver only.«; AKC S765 »Disqualification-Yellow, cream or any color other than black or liver.«; uteži brez vira → `verified: false` | #931 |
| 2026-11-10 | pravilo runbooka (§2.3) | seznam za vzreditelja: minimum pregled oči z gonioskopijo (`eye_scheme`); najboljša praksa sheme za komolce in kolke | stran RKC S762 | #931 |
| 2026-11-10 | pravilo runbooka | zdravstveni ključi registra: `certain_cancers`, `bloat_gdv`, `glaucoma`, `hip_elbow_dysplasia`; brez odstotkov, brez novega ključa | PetMD S763, RKC S762 | #931 |
| 2026-11-10 | predlog Claude, David lahko spremeni | EN »Flat-Coated Retriever«, SL **»Ravnodlaki prinašalec«**; iskanje tudi »flatcoat«, »flattie«, »ravnodlaki prinasalec« | slovensko ime je dobesedni prevod (»flat-coated« = ravna dlaka, »retriever« = prinašalec, kot pri zlatem prinašalcu); strani KZS nisem našel | #931 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »ravnodlaki prinašalec«, »flatcoat« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-101 | 2026-11-10 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 105. meseca; 4 → 3 → 2 obroka; učenje 1,5×) | 🛠 | 💶 izziv | M5-R10-101 | 2026-11-10 |
| Česanje 2× na teden, »Nova izkušnja« za mladička, opozorilo po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 | 2026-11-10 |
| Nega: čiščenje ušes 1× na teden in kremplji vsake 4 tedne (otrok), opomnik za kopanje vsake 4 tedne (starši) | 🛠 | 💶 izziv | M5-R19 / M5-R20 | 2026-11-10 |
| Barva (izbirnik: črna, jetrno rjava) | 🛠 | 💶 izziv | M5-R21 | 2026-11-10 |
| AI portret v registru | 🗓 po združitvi (črn, naraven rep) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/flat-coated-retriever`, `/sl/zivali/psi/ravnodlaki-prinasalec`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal ravnodlakega prinašalca — veselega, aktivnega črnega (ali jetrno rjavega) lovskega prinašalca iz Velike Britanije; 97. najbolj priljubljeno pasmo po AKC 2024 in 93. pasmo psa v aplikaciji. 7 novih virov (S760–S766). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot dve uri gibanja), česanje dvakrat na teden, otrok mu vsak teden očisti ušesa in vsake 4 tedne pristriže kremplje; starši dobijo opomnik za kopanje.
**Zakaj je pomembno:** Ravnodlaki prinašalec je »čudovit družinski pes«, ki se razume z otroki in drugimi živalmi (PetMD) — a le, če dobi dovolj gibanja in dela za glavo. Prav to PetPrep preizkusi: dve uri gibanja vsak dan, dvanajst tednov zapored, in nega, ki je ni malo.
**Kako povedati** — starši: »Ravnodlaki prinašalec je prijazen družinski pes, ki potrebuje več kot dve uri gibanja na dan, česanje dvakrat na teden in redno nego ušes. Pasma živi nekoliko krajše od povprečja. V PetPrepu boste videli, ali vaš otrok zmore tak ritem.« · otroci: »Ravnodlaki prinašalec je črn, vesel pes, ki obožuje vodo in prinašanje — vsak dan greš z njim na dolg sprehod in mu vsak teden počistiš ušesa.« · vlagatelji / partnerji: »Triindevetdeseta pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, nalogami nege in testi za vzreditelje.«

## Odprto za Davida

- **Krajša življenjska doba:** RKC navaja »Under 10 years« (in PetMD 8–10 let), McMillan 2024 pa mediano 11,7 leta. Po pravilu runbooka ima pasma čip »krajša življenjska doba« (kot novofundlandec), starejše obdobje pa je izračunano iz mediane (105). Če čipa ne želite, povejte.
- **Kopanje in kremplji:** PetMD »every month or two« → vsake 4 tedne (spodnja meja). Če želite 8 tednov, povejte.
- **Ime:** »Ravnodlaki prinašalec« ni preverjen na straneh KZS (dobesedni prevod). Potrdite ali popravite.
- **Zobje:** vir ne navaja pogostosti → ni naloge (pravilo).
