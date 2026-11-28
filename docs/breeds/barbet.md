# Barbet (Barbet)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-146 · 2026-11-28

| | |
|---|---|
| Ključ / slug | `barbet` / `barbet` (SL `barbet`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1370 — v izbirniku za salukijem |
| Vir podatkov | `docs/research/dog-data/data.json` → `barbet` (viri S1094–S1099; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: kolki, komolci, oči (Barbet Club of America prek AKC, K47) |
| Izvor | FCI št. 105 »Barbet« (francoski vodni pes), skupina 8, sekcija 3 (vodni psi), s preizkusom dela, Francija (S1094) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 142. mesto »Barbet« (S174, prebrano 2026-11-28: »141 Saluki«, »142 Barbet«) |
| PR-ji | pet-prep #1716 (pasma, združen `3a1acfd` 2026-11-28) · portret: zagon `breed-portraits.yml` 46091239924 (črn, sprejet v prvem poskusu), pet-prep PR #1719 (združen `f33e965`) · barvne različice: zagon 46092311507 (7/7 odobril Claude), pet-prep PR `feat/M5-R10-146-barbet-colours` · spletna stran: pet-prep-website `feat/register-barbet` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-28 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | stran RKC ni bila dosegljiva, strani PDSA / Woodgreen / Dogs Trust ni → PetMD S1095 »Expect to spend at least an hour exercising your Barbet every day.« (»at least N« → N); WebMD in AKC minut ne navajata | #1716 |
| 2026-11-28 | pravilo runbooka | obdobja 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 | AAHA S11; starejši = 0,75 × spodnja meja WebMD »over 10 years« (S1096) × 12 = 90 (precedens saluki s PDSA »Over 10 years«). Alternativi: 108 (PetMD 12–15 let), 84 (Dogs Trust > 7 let) | #1716 |
| 2026-11-28 | pravilo runbooka | učenje **1,0×** | barbet ni na Corenovi lestvici (S35, prebrano 2026-11-28 — ne »Barbet« ne »French Water Dog«) → brez mesta = 1,0 | #1716 |
| 2026-11-28 | pravilo runbooka | teža **18–27 kg** (psi) / **13,5–23 kg** (psice), višina 58–65 / 53–61 cm, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | standardi FCI (S1094), AKC (S1097) in UKC (S1098) teže ne navajajo → WebMD S1096 »Males weigh between 40 and 60 pounds (18 to 27 kilograms)«, psice 30–50 lb (13,5–23 kg); AKC 30–60 lb in PetMD 35–65 lb kot alternativi; višina FCI S1094; PetMD S10 (razred »Medium«) | #1716 |
| 2026-11-28 | pravilo runbooka (M5-R13) | česanje **3× na teden**; skuštrana dlaka po 2 zamujenih | PetMD S1095 po klubu pasme »… should be brushed two to three times a week …« (razpon → zgornja meja); PetMD »at least twice a week« (2) kot alternativa | #1716 |
| 2026-11-28 | pravila »Breed care tasks« (David 2026-10-24, M5-R15) | **ščetkanje zob vsak dan** (otrokova mini igra) | WebMD S1096 »Brushing your dog's teeth every day or two helps to keep teeth clean« — »every day or two« = razmik 1–2 dni → krajši razmik (kot »4–6 weeks« → 28) | #1716 |
| 2026-11-28 | pravila »Breed care tasks« | **brez** nalog za ušesa, kremplje, kopanje in frizerja | ni pogostosti: ušesa »regular cleaning« (PetMD) / »Check ears regularly« (WebMD), kremplji »regularly« (WebMD), kopanje »Regular bathing« (PetMD), frizer »occasional« (PetMD) | #1716 |
| 2026-11-28 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S1095 »Barbet puppies need proper socialization to be confident and adaptable as they grow and navigate the world.« | #1716 |
| 2026-11-28 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila po obroku in **brez** prepovedi sprehoda v vročini | noben vir ne svetuje premora po obroku; pasma ni ploskoobrazna (vročina le kot čip) | #1716 |
| 2026-11-28 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družino z otroki«**, **»dom z drugimi ljubljenčki«**, **»dom, kjer želite manj dlak«**; upoštevajte **»potrebuje česanje večkrat na teden«**, **»rad ima družbo«**, **»gost kožuh — slabo prenaša vročino«** | PetMD S1095 (»excellent companions for active families«, »get along well with other pets and children«, »doesn’t shed very much«, »two to three times a week«, »may not thrive … left alone«); WebMD S1096 (»gets along well with children«, »thick coat tends to make their body overheat really quickly«) | #1716 |
| 2026-11-28 | pravilo runbooka | **brez** čipov `small_children` (ni ocene za majhne otroke), `apartment` / `large_home` (ključnih podatkov RKC ni bilo; AKC »can thrive in apartments« ni podatek RKC), `long_daily_exercise` (le 60 min); nikoli »hypoallergenic« (PetMD to besedo uporablja — mi ne) | ni ustrezne A/B navedbe | #1716 |
| 2026-11-28 | pravilo runbooka (§3) | **v aplikaciji da, v marketingu da** (nikoli kot »hipoalergen« pes) | nobeno veterinarsko združenje ne odsvetuje oglaševanja; kategorija Breed Watch ni bila dosegljiva | #1716 |
| 2026-11-28 | pravilo runbooka (§3) | barve za AI in izbirnik: **črn (portret)**, rjav, siv, plavo rjav, črno-bel, rjavo-bel, svetlo plavo rjav, bel; naravna, nestrižena kodrasta dlaka z brado, naraven rep | FCI S1094 »Solid black, grey, brown, fawn, pale fawn, white or more or less pied.«; AKC S1097 »… which all may include white markings.«; uteži barv brez vira (Claude) | #1716 |
| 2026-11-28 | pravilo runbooka (§2.3) | seznam za vzreditelja: **kolki, komolci, oči** (`hip_scheme`, `elbow_scheme`, `eye_scheme`, »other_scheme«) | stran RKC A–Z ni bila dosegljiva → AKC K47 (= S1097) »Breeders on the BCA website are required to test hips, elbows, and eyes.« | #1716 |
| 2026-11-28 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia`, `pra_inherited_blindness`, `ear_infections_long_ears`, `itchy_skin_allergy`, `epilepsy`; brez odstotkov | PetMD S1095, WebMD S1096, AKC S1097 | #1716 |
| 2026-11-28 | predlog Claude, David lahko spremeni | EN »Barbet«, SL **»Barbet«** (tudi »francoski vodni pes«); iskanje »barbet«, »french water dog« — brez »vodni pes« / »francoski«, ki v izbirniku najdeta portugalskega vodnega psa / francoskega buldoga | FCI / AKC; Wikipedia S1099 (French water dog); strani KZS nisem prebral | #1716 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 3× na teden«, iskanje »barbet«, »french water dog«, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-146 | 2026-11-28 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 90. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-146 | 2026-11-28 |
| Česanje 3× na teden, ščetkanje zob vsak dan, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R15 / M5-R17 | 2026-11-28 |
| Barva (izbirnik: črn, rjav, siv, plavo rjav, črno-bel, rjavo-bel, svetlo plavo rjav, bel) | 🛠 | 💶 izziv | M5-R21 | 2026-11-28 |
| Barvne fotografije (izbirnik in spletna stran): rjav, siv, plavo rjav, črno-bel, rjavo-bel, svetlo plavo rjav, bel — vseh sedem odobril Claude (zagon `breed-portraits.yml` 46092311507); črn = portret | ✅ AI fotografije | — | M5-R21 / §8b | 2026-11-28 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), črn, naravna kodrasta dlaka z brado, naraven rep, svetlo sivo studijsko ozadje | — | M5-R11-04 | 2026-11-28 |
| Stran na petprep.si (`/animals/dogs/barbet`, `/sl/zivali/psi/barbet`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal barbeta — francoskega vodnega psa srednje velikosti z gosto, volnato, kodrasto dlako in brado (13,5–27 kg, 53–65 cm), 142. najbolj priljubljeno pasmo po AKC 2024. Šest novih virov (S1094–S1099). Odrasel pes v igri potrebuje 6.000 korakov na dan (vsaj ura gibanja), česanje 3× na teden in vsakodnevno ščetkanje zob; starejši postane pri 90 mesecih.
**Zakaj je pomembno:** Barbet je prijazen, druželjuben in rad z otroki in drugimi živalmi, a njegov kodrast kožuh potrebuje veliko nege, ne mara biti dolgo sam in v vročini se hitro pregreje. Malo mu izpada dlaka — a to ni »hipoalergen« pes.
**Kako povedati** — starši: »Barbet je nežen družinski pes, ki se dobro razume z otroki, potrebuje pa uro gibanja na dan in česanje večkrat na teden. V PetPrepu boste videli, ali vaš otrok zmore redno nego kodrastega kožuha.« · otroci: »Barbet obožuje vodo in ljudi — z njim greš vsak dan na sprehod, ga trikrat na teden počešeš in mu vsak dan umiješ zobe.« · vlagatelji / partnerji: »Nočni zagon je pasmo sam izbral po javni lestvici AKC in jo opremil s šestimi viri; ko britanski kinološki klub ni bil dosegljiv, so pravila iz standarda FCI in veterinarsko pregledanih strani PetMD in WebMD.«

## Odprto za Davida

- **Gibanje:** edini vir s številko je PetMD »at least an hour« (60 min). Strani RKC (barbet je pri RKC v uvoženem registru) ni bilo mogoče prebrati — če RKC navaja več, se cilj spremeni.
- **Življenjska doba / starejši:** stran RKC in tabela McMillan 2024 nista bili dosegljivi → spodnja meja WebMD »over 10 years« → starejši pri 90 mesecih. PetMD navaja 12–15 let (→ 108). Potrdite ali izberite drugo mejo.
- **Teža:** standardi teže ne navajajo — uporabljena je WebMD po spolu (vir B).
- **Zobje:** WebMD »every day or two« je prebran kot vsak dan (krajši razmik); vsak drugi dan bi bil 4× na teden.
- **Ime / iskanje:** slovensko »Barbet« / »francoski vodni pes« ni preverjeno na straneh KZS; iskanje »vodni pes« v izbirniku najde le portugalskega vodnega psa (da se testi drugih pasem ne spremenijo).
- **Skupni dokumenti:** število pasem psov v FEATURES / predstavitvah se po pravilu »Own files only« ne spreminja v tem zagonu.
