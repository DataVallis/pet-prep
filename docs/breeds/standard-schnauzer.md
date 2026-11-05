# Srednji šnavcer (Standard Schnauzer)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-95 · 2026-11-05

| | |
|---|---|
| Ključ / slug | `standard_schnauzer` / `standard-schnauzer` (SL `srednji-snavcer`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 890 — v izbirniku za keeshondom |
| Vir podatkov | `docs/research/dog-data/data.json` → `standard_schnauzer` (viri S711–S720; ponovno S10, S11, S14, S24, S35, S45, S54, S92, S174); seznam za vzreditelja: RKC (`listed`) — genetska raznolikost (minimum), DNA test za DCM in rentgen kolkov BVA/KC (dobra praksa) |
| Izvor | FCI št. 182 »Schnauzer«, skupina 2, sekcija 1 (pinčem in šnavcerjem podobni psi), Nemčija (S711, S712) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 91. mesto »Standard Schnauzer« (S174, prebrano 2026-11-05) |
| PR-ji | pet-prep #758 (pasma) · portret: sledi · spletna stran: sledi |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-05 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S714 »Exercise: Up to 1 hour per day« (»up to N« → N); PDSA S715 »around an hour of exercise daily« se ujema; PetMD S717 »at least 30 minutes« = alternativa | #758 |
| 2026-11-05 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 | AAHA S11; starejši = 0,75 × **10 let** — spodnja meja RKC »Lifespan: Over 10 years« (S714; PDSA S715 enako). McMillan 2024 za pasmo ni bil dosegljiv (le slika 5, dodatna tabela 3 ni dosegljiva; tabela Dogs Trust S123 zavrnjena), mediane VetCompass ni | #758 |
| 2026-11-05 | pravilo runbooka | učenje **1,5×** | Coren: 18. mesto »Standard Schnauzer«, skupina »Excellent« (11–26) (S35, preverjeno 2026-11-05) | #758 |
| 2026-11-05 | pravilo runbooka | teža **14–20 kg** (FCI, oba spola); višina 45–50 cm (FCI); rast do 12–15 mesecev (razred »Medium«); hrana, voda in čiščenje kot pri border collieju | FCI S712 »Weight : Dogs and bitches: 14 to 20 kg.«, »Height at withers : Dogs and bitches: 45 to 50 cm«; PetMD S10 | #758 |
| 2026-11-05 | pravilo runbooka (M5-R13) | česanje **3× na teden**; skuštrana dlaka po 2 zamujenih | RKC S714 »Grooming: More than once a week« (brez števila) → PDSA S715 »Grooming is needed 2-3 times a week« → zgornja meja 3 (kot pri pritlikavem šnavcerju); VCA S716 »once or twice weekly« in PetMD S717 »Twice-weekly brushing« = alternativa (2) | #758 |
| 2026-11-05 | pravila »Breed care tasks« (David 2026-10-24, M5-R19 / M5-R20) | otrokova mini igra **striženje krempljev 1× na teden** in **čiščenje ušes 1× na teden** | PetMD S717 »nail trims and ear cleanings every one to two weeks.« → spodnja meja, 1× na teden | #758 |
| 2026-11-05 | pravila »Breed care tasks« (M5-R19 / M5-R18) | opomnik staršu za **kopanje vsake 4 tedne** in za **frizerja vsakih 12 tednov** (ne šteje v oceno) | PetMD S717 »Monthly bathing with oatmeal-based dog shampoos is recommended.« → 28 dni; »quarterly professional appointments with an experienced Schnauzer groomer.« → 84 dni; VCA S716 striženje »every two to three months« = alternativa (8 tednov) | #758 |
| 2026-11-05 | pravila »Breed care tasks« | **brez** naloge za zobe | vir ne navaja pogostosti | #758 |
| 2026-11-05 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S717 »early socialization and training is necessary.« | #758 |
| 2026-11-05 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila »počakaj s sprehodom« po obroku in **brez** opozorila za vročino | viri premora po obroku ne omenjajo; ni ploskoobrazna pasma (FCI gobec »Ending in a blunt wedge«, S712) | #758 |
| 2026-11-05 | pravilo runbooka | primeren za: **družinsko življenje**, **družino z otroki**; upoštevajte: »potrebuje miselne izzive«, »potrebuje česanje večkrat na teden« | PetMD S717 »devoted to their family, including other family pets and children.«; PDSA S715 »… will get on well with children if they're introduced at an early age.«; VCA S716 »Playful and good with children.«, »Games and tricks provide needed mental exercise.«; RKC S714 / PDSA S715 česanje | #758 |
| 2026-11-05 | pregled (koordinator, 2026-11-05) | **brez** čipa za lastnika: ne `experienced_owner` (čip govori o »močnem psu«, PetMD S717 pa le »This breed does best with patient, experienced pet parents.« — besedilo se ne ujema, precedens nemški ostrodlaki ptičar) ne `independent_experienced_owner` (noben citat ne poveže trmastega psa z izkušenim lastnikom, precedens shiba inu; VCA S716 »can be quite headstrong« je opomba o šolanju) | S716, S717 | #758 |
| 2026-11-05 | pravilo runbooka | **brez** čipov `small_children` (PDSA s pogojem zgodnjega uvajanja), `apartment` / `large_home` (RKC »Small house«), `other_pets` (VCA »Usually …« in »Can be aggressive toward strange dogs.«), `sheds` / `low_shedding` (viri si nasprotujejo), `chases_small_pets` (noben vir ne navaja močnega lovskega nagona), `shorter_lifespan`; nikoli »hypoallergenic« | S714–S717 | #758 |
| 2026-11-05 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot hipoalergen pes, kot pes za začetnike ali s kupiranimi ušesi / repom | RKC Breed Watch kategorija 1 (S714); ni izrecnega veterinarskega odsvetovanja oglaševanja | #758 |
| 2026-11-05 | pravilo runbooka (§3) | barve za AI in izbirnik: **poper in sol** (privzeto, portret) in **črn** — barvi standarda; nikoli črno-srebrn (le pritlikavi / veliki), bel, rjav ali lise; naravna ušesa in rep. Ključi, vrednosti in napisi kot pri pritlikavem in velikem šnavcerju | FCI S712 »the aim is a medium shading with evenly distributed, well pigmented pepper colouring.«, »Pure black with black undercoat.«; AKC S718 »Pepper and salt or pure black.«; uteži niso iz vira (`verified=false`) | #758 |
| 2026-11-05 | pravilo runbooka (§2.3) | seznam za vzreditelja: **RKC** — genetska raznolikost (minimum), DNA test za DCM, shema kolkov BVA/KC (dobra praksa) | S714 | #758 |
| 2026-11-05 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia`, `heart_muscle_disease`, `skin_comedo_allergy`, `pra_inherited_blindness`, `hypothyroidism`, `bladder_stones`; brez odstotkov, brez novega ključa. Zožitev pljučne zaklopke in hemofilija A ostaneta le v `data.json` (ni ključa z besedilom na spletni strani) | PDSA S715, RKC S714, PetMD S717 | #758 |
| 2026-11-05 | predlog Claude, David lahko spremeni | EN »Standard Schnauzer« (AKC; RKC / FCI »Schnauzer«), SL **»srednji šnavcer«**; iskanje tudi »srednji snavcer«, »mittelschnauzer«, »schnauzer«, »šnavcer« | Klub šnavcerjev in pinčev Slovenije (S720, tier C) | #758 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 3× na teden«, iskanje »srednji šnavcer«, »mittelschnauzer« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-95 | 2026-11-05 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 90. meseca; 4 → 3 → 2 obroka; učenje 1,5×) | 🛠 | 💶 izziv | M5-R10-95 | 2026-11-05 |
| Česanje 3× na teden, »Nova izkušnja« za mladička, striženje krempljev in čiščenje ušes 1× na teden, opomnika staršu za kopanje (4 tedni) in frizerja (12 tednov) | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R18 / M5-R19 / M5-R20 | 2026-11-05 |
| Barva (izbirnik: 2 barvi standarda — poper in sol, črn) | 🛠 | 💶 izziv | M5-R21 | 2026-11-05 |
| AI portret v registru | 🗓 po naslednji produkcijski objavi | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/standard-schnauzer`, `/sl/zivali/psi/srednji-snavcer`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal srednjega šnavcerja — srednje velikega (14–20 kg), čokatega psa iz Nemčije z žimnato dlako, košatimi obrvmi in brado, »originalnega« šnavcerja med pritlikavim in velikim; 91. najbolj priljubljeno pasmo po AKC 2024. 10 novih virov (S711–S720). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do ene ure gibanja), česanje 3× na teden, enkrat na teden mu otrok postriže kremplje in očisti ušesa; staršem aplikacija vsake 4 tedne pripomni kopanje in vsakih 12 tednov frizerja.
**Zakaj je pomembno:** Srednji šnavcer je zvest družinski pes, ki se hitro uči (Coren: 18. mesto), a se hitro tudi naveliča in je trmast — potrebuje potrpežljivega, izkušenega lastnika, miselne izzive in redno nego dlake. To je točno tisto, kar otrok v PetPrepu preizkusi pred pravim psom.
**Kako povedati** — starši: »Srednji šnavcer je predan družini in dober z otroki, a potrebuje izkušeno roko, igre za glavo in nego dlake večkrat na teden. V PetPrepu boste videli, ali vaš otrok to zmore.« · otroci: »Šnavcer z brado in obrvmi — trikrat na teden ga počešeš, enkrat na teden mu postrižeš kremplje in očistiš ušesa, rad se uči novih trikov.« · vlagatelji / partnerji: »Tretji šnavcer v aplikaciji in še ena pasma, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, štirimi nalogami nege in pravili.«

## Odprto za Davida

- **Starejše obdobje: 90 ali 117 mesecev.** Po pravilu runbooka 90 (0,75 × spodnja meja RKC »Over 10 years«). Wikipedia (S719, tier C) navaja mediano **13 let** iz študije 2024 (verjetno McMillan 2024) — to bi dalo **117**, a vrednosti v članku nisem mogel preveriti (pasma je le na sliki 5, tabela Dogs Trust zavrnjena). Če potrdite 13 let, je potrebna podatkovna migracija (seeder samo vstavlja).
- **Frizer: 12 ali 8 tednov.** PetMD »quarterly« → 12 tednov (uporabljeno); VCA »every two to three months« → spodnja meja 8 tednov. Odločite, če želite pogostejši opomnik.
- **Ušesa in kremplji** slonita na enem stavku PetMD (S717, »nail trims and ear cleanings every one to two weeks«, tier B); noben drug vir ne navaja pogostosti. Potrdite ali odstranite.
- **Slovensko ime** »srednji šnavcer« je s strani Kluba šnavcerjev in pinčev Slovenije (S720, tier C); strani KZS nisem preveril. Potrdite ali popravite.
- **Izpadanje dlake:** viri si nasprotujejo (RKC »Sheds: Yes« proti VCA »below average« in PetMD »minimal«) → ni čipa ne »izpada mu dlaka« ne »malo izpada«. Odločite, če želite enega.
- **Izkušen lastnik — dejstvo brez čipa:** PetMD (S717) piše »This breed does best with patient, experienced pet parents.«, PDSA (S715) »They can be stubborn …«, VCA (S716) »can be quite headstrong«. Čip `experienced_owner` (»močan pes — potrebuje izkušenega lastnika«) se z besedilom ne ujema, za `independent_experienced_owner` (»samostojen in trmast — najbolje z izkušenim lastnikom«) pa ni enega citata, ki bi povezal oboje (pri shiba inu: AKC »Due to their stubborn nature, a Shiba Inu is a better pick for an experienced dog owner.«). Zato pasma nima čipa za lastnika — odločite, ali želite `independent_experienced_owner` (ali nov ključ).
- **Zdravstveno besedilo za kožo:** ključ `skin_comedo_allergy` (kot pri pritlikavem šnavcerju) omenja tudi kožne alergije, viri za srednjega šnavcerja pa navajajo le Schnauzer comedo sindrom (folikularni dermatitis). Morda nov, ožji ključ — odločite.
- **Portret in spletna stran:** sledita (ločena PR-ja).
