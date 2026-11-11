# Leonberger (Leonberger)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-103 · 2026-11-11

| | |
|---|---|
| Ključ / slug | `leonberger` / `leonberger` (SL `leonberger`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 960 — v izbirniku za borderskim terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `leonberger` (viri S780–S786; ponovno S8, S10, S11, S14, S24, S35, S45, S123, S174); seznam za vzreditelja: stran RKC (S780) |
| Izvor | FCI št. 145, skupina 2, sekcija 2.2 (molosi, gorski tip), Nemčija (S782, S783) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 99. mesto »Leonberger« (S174, prebrano 2026-11-11) |
| PR-ji | pet-prep #990 (pasma, združen `a218e3b`) · portret pet-prep PR `feat/M5-R10-103-leonberger-portrait` (zagon `breed-portraits.yml` 42949330159, sprejet v prvem poskusu) · spletna stran: pet-prep-website `feat/register-leonberger` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-11 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček / mlad 10 min × starost do 120 (pri 12 mesecih) | stran RKC S780 »Exercise: More than 2 hours per day« (»more than N« → N); PDSA S781 »over 2 hours of exercise daily« se ujema; Woodgreen S784 »60-90 minutes« ostane alternativa | #990 |
| 2026-11-11 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 | AAHA S11; starejši = 0,75 × mediana McMillan 2024 **10,0 leta** (tabela Dogs Trust S123) = 90 mesecev — prvi vir pravila, ni začasno. Ni izbrano: 63 (spodnja meja Woodgreen / PetMD 7 let) | #990 |
| 2026-11-11 | pravilo runbooka | učenje **1,0×** | Coren pasme ne uvršča (tabela Wikipedia S35, 79 mest) → pravilo »unranked« | #990 |
| 2026-11-11 | pravilo runbooka | teža **30–50 kg** (PDSA, za oba spola; FCI in AKC teže ne navajata), višina psi 72–80 cm, psice 65–75 cm (FCI), rast do 18–24 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S781; standard FCI S783 (AKC S786 se ujema); PetMD S10 (razred »Giant«; Woodgreen »Giant«, sklepi se razvijajo v drugo leto) | #990 |
| 2026-11-11 | pravilo runbooka (M5-R13) | česanje **3× na teden**; skuštrana dlaka po 2 zamujenih | Woodgreen S784 »Brushing two to three times a week …« → razpon → zgornja meja 3 (precedens nemški kratkodlaki ptičar); RKC »Once a week« (1), PDSA »a couple of times a week« (2) in PetMD »every day« (7) ostanejo alternative | #990 |
| 2026-11-11 | David 2026-10-12 (M5-R16) | opozorilo »počakaj s sprehodom« 60 min po obroku | PetMD S785 »Avoid exercise right before and after mealtimes.« (napihnjenost / GDV) | #990 |
| 2026-11-11 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | Woodgreen S784 »Due to their size alone, early training and socialisation is essential.« | #990 |
| 2026-11-11 | pravila »Breed care tasks« (David 2026-10-24, M5-R18 / R20) | **čiščenje ušes (otrok) vsaka 2 tedna**, **opomnik za kopanje (starš) vsaka 2 tedna** | PetMD S785 »… every two to three weeks.« (ušesa) in »Typically, they should be bathed every two to four weeks.« → spodnja meja razpona; kremplji, zobje, frizer — brez pogostosti → brez naloge | #990 |
| 2026-11-11 | pravilo runbooka | brez poletnega opozorila za sprehod | ni ploskoobrazna pasma; vir ne svari pred vročino | #990 |
| 2026-11-11 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družinsko življenje«**, **»družino z otroki«**, **»veliko hišo z vrtom«**; upoštevajte **»krajša življenjska doba«**, »vsak dan potrebuje veliko gibanja«, »izpada mu dlaka«, »potrebuje česanje večkrat na teden«, **»rad ima družbo«** | RKC S780 (več kot 2 uri, »Large house«, »Large garden«, »Sheds: Yes«, »Under 10 years«); PDSA S781 »excellent family pets«; FCI S783 »marked friendliness towards children« (Woodgreen: stik z otroki naj bo pod nadzorom — v opombah); PDSA / Woodgreen česanje večkrat na teden; Woodgreen S784 težko prenaša samoto | #990 |
| 2026-11-11 | pravilo runbooka | **brez** `small_children`, `other_pets`, `chases_small_pets`, `apartment`, `often_alone`, `not_for_small_children`; nikoli »hypoallergenic« | Woodgreen: z drugimi ljubljenčki »Moderate«, nadzor pri otrocih; ni navedbe o lovskem nagonu; RKC »Large house« | #990 |
| 2026-11-11 | pravilo runbooka (§3, dobrobit) | RKC Breed Watch **kategorija 2 brez navedenih točk** → brez opozorilnega čipa; **v aplikaciji da, v marketingu da** (ni ploskoobrazna pasma; nikoli otrok sam s psom) | S780 (precedens irski volčji hrt) | #990 |
| 2026-11-11 | pravilo runbooka (§3) | barve za AI in izbirnik: **levje rumen s črno masko** (portret, največja utež), rdeč, rdečkasto rjav, peščen — vedno s črno masko; nikoli črna osnovna barva | FCI S783 »Lion yellow, red, reddish brown, also sandy …« »always with a black mask.«; AKC S786; uteži brez vira → `verified: false` | #990 |
| 2026-11-11 | pravilo runbooka (§2.3) | seznam za vzreditelja: minimum genska pestrost, komolci, oči (z gonioskopijo), kolki; »Best Practice« DNA testi **LPPN3, LPN1, LPN2, LEMP** (novi ključi `dna_lppn3`, `dna_lpn1`, `dna_lpn2`, `dna_lemp` — besedila na spletni strani) | stran RKC S780 | #990 |
| 2026-11-11 | pravilo runbooka | zdravstveni ključi registra: `hip_elbow_dysplasia`, `bloat_gdv`, `heart_muscle_disease`, `polyneuropathy`, `certain_cancers`; brez odstotkov | PDSA S781, Woodgreen S784, PetMD S785 | #990 |
| 2026-11-11 | predlog Claude, David lahko spremeni | EN in SL **»Leonberger«**; iskanje tudi »leonberg«, »leonberški pes« | mednarodno ime pasme (FCI, RKC, AKC); strani KZS nisem prebral | #990 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 3× na teden«, iskanje »leonberger«, »leonberg«, »leonberški pes« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-103 | 2026-11-11 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-103 | 2026-11-11 |
| Česanje 3× na teden, »Nova izkušnja« za mladička, opozorilo po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 | 2026-11-11 |
| Čiščenje ušes vsaka 2 tedna (otrok), opomnik za kopanje vsaka 2 tedna (starš) | 🛠 | 💶 izziv | M5-R20 / M5-R18 | 2026-11-11 |
| Barva (izbirnik: levje rumen, rdeč, rdečkasto rjav, peščen — vedno s črno masko) | 🛠 | 💶 izziv | M5-R21 | 2026-11-11 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), levje rumen s črno masko, naraven rep | — | M5-R11-04 | 2026-11-11 |
| Stran na petprep.si (`/animals/dogs/leonberger`, `/sl/zivali/psi/leonberger`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal leonbergerja — velikega (30–50 kg po PDSA), mirnega nemškega psa z levjo grivo in črno masko; 99. najbolj priljubljeno pasmo po AKC 2024 in 95. pasmo psa v aplikaciji. 7 novih virov (S780–S786). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot dve uri gibanja), česanje trikrat na teden, čiščenje ušes in kopanje vsaka dva tedna; starejši postane pri 90 mesecih (mediana McMillan 2024: 10,0 leta).
**Zakaj je pomembno:** Leonberger je »nežni velikan«, odličen družinski pes — a potrebuje veliko hišo z vrtom, več kot dve uri gibanja na dan, veliko nege in družbo; živi krajše od večine pasem. PetPrep pokaže, ali družina zmore ta ritem, preden pride pravi pes.
**Kako povedati** — starši: »Leonberger je prijazen in miren, a velik pes, ki vsak dan potrebuje več kot dve uri gibanja, česanje večkrat na teden in nekoga doma. V PetPrepu boste videli, ali vaš otrok zmore tak ritem.« · otroci: »Leonberger je kosmat velikan z levjo grivo — vsak dan greš z njim na dolg sprehod, ga trikrat na teden počešeš in mu vsaka dva tedna očistiš ušesa.« · vlagatelji / partnerji: »Petindevetdeseta pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, nalogami nege in štirimi novimi DNA testi za vzreditelje.«

## Odprto za Davida

- **Česanje 3× na teden:** viri se razlikujejo — RKC »Once a week« (1), PDSA »a couple of times a week« (2), Woodgreen »two to three times a week« (3), PetMD »every day« (7). Po pravilu runbooka (razpon → zgornja meja) je izbrano 3. Če želite drugo število, povejte.
- **Teža 30–50 kg (PDSA):** Woodgreen navaja 41–77 kg, PetMD do 170 lb (≈ 77 kg); standarda FCI in AKC teže ne navajata. Če želite drug razpon, povejte.
- **Breed Watch kategorija 2:** stran RKC navaja »This breed can be affected by conformational concerns.«, točk skrbi pa nismo dobili → brez opozorilnega čipa. Preverite, ali čip potrebujete.
- **Ime:** »Leonberger« v slovenščini ni preverjen na straneh KZS. Potrdite ali popravite.
