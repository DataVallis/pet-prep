# Italijanski ptičar (Bracco Italiano)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-136 · 2026-11-24

| | |
|---|---|
| Ključ / slug | `bracco_italiano` / `bracco-italiano` (SL `italijanski-pticar`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1270 — v izbirniku za belgijskim ovčarjem groenendaelom |
| Vir podatkov | `docs/research/dog-data/data.json` → `bracco_italiano` (viri S1022–S1028; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: RKC (S1024) |
| Izvor | FCI št. 202 »Bracco Italiano« (Italian Pointing Dog), skupina 7, sekcija 1 (celinski ptičarji, tip »braque«), Italija (S1022, S1023) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 132. mesto »Bracco Italiano« (S174, zapisano 2026-11-23 v vrstici 135 čakalne vrste) |
| PR-ji | pet-prep #1559 (pasma, združen `c453e62`) · portret: pet-prep PR `feat/M5-R10-136-bracco-italiano-portrait` (zagon `breed-portraits.yml` 45303443120, sprejet v prvem poskusu) · barvne različice: pet-prep PR `feat/M5-R10-136-bracco-italiano-colours` (zagon 45304884439 — belo-rjav, oranžno lisast, rjavo lisast, bel: vse 4 odobril Claude) · spletna stran: pet-prep-website `feat/register-bracco-italiano` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-24 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček / mlad 10 min × starost do 120 (pri 12 mesecih) | RKC S1024 »More than 2 hours per day« (»more than N« → N); PetMD S1027 brez števila | #1559 |
| 2026-11-24 | pravilo runbooka | obdobja 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 | #1559 |
| 2026-11-24 | **predlog Claude — čaka Davida** (`verified=false`) | starejši in prihod starejšega pri **99 mesecih** | RKC S1024 »Under 10 years« nima spodnje meje; mediana McMillan 2024 / VetCompass ni bila dosegljiva → najbližja vrstica: 0,75 × mediana 11 let iz izjave o zdravju kluba Bracco Italiano Club of America (S1028, klubska anketa, ni recenzirana) × 12 = 99. Alternativi: 90 (PetMD S1027 spodnja meja 10 let), 84 (Dogs Trust > 7 let, S14) | #1559 |
| 2026-11-24 | pravilo runbooka | učenje **1,0×** | Coren pasme ne uvršča (S35; branje 2026-11-18 je kot edino italijansko pasmo našlo malega italijanskega hrta) | #1559 |
| 2026-11-24 | pravilo runbooka | teža **25–40 kg** (oba spola), višina 58–67 / 55–62 cm, rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | standard FCI 202 (S1023), RKC standard (S1025) enako; PetMD S10 (razred »Large«) | #1559 |
| 2026-11-24 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | RKC S1024 »Once a week«; PetMD S1027 »Brushing once a week« se ujema | #1559 |
| 2026-11-24 | David 2026-10-12 (M5-R16) | **opozorilo po obroku 60 min** (samo opozorilo, koraki se štejejo) | PetMD S1027 »limiting exercise directly before or after eating« (napenjanje / GDV pri psih z globokim prsnim košem) | #1559 |
| 2026-11-24 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S1027 »ensure early socialization for well-rounded development« | #1559 |
| 2026-11-24 | pravila »Breed care tasks« (David 2026-10-24) | **brez** naloge za zobe, kremplje, ušesa, kopanje ali frizerja | PetMD S1027 samo »routine nail trimming and dental care«, »Regular ear cleaning« — brez pogostosti; Hill's in Vetstreet (pogostosti) sta vira ravni C | #1559 |
| 2026-11-24 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družino z otroki«**, **»velik dom«**; upoštevajte **»dolg dnevni sprehod«**, **»rad ima družbo«**, **»izpada mu dlaka«**, **»krajša življenjska doba«** | RKC S1024 (»More than 2 hours per day«, »Large house«, »Sheds: Yes«, »Under 10 years«); PetMD S1027 (»generally good with other dogs and with children«, »where the family is frequently away from home may not be suitable«) | #1559 |
| 2026-11-24 | pravilo runbooka | **brez** čipov `family_pet` (ni navedbe za pasmo, RKC stavek velja za vse ptičarje), `small_children`, `chases_small_pets` (PetMD »prey drive« brez »high«), `apartment`, `frequent_grooming`; nikoli »hypoallergenic« | ni A/B navedbe | #1559 |
| 2026-11-24 | pravilo runbooka (§3) | **v aplikaciji da, v marketingu da** | ni ploskoobrazna; RKC Breed Watch kategorija 1 → brez čipa dobrobiti; nobeno veterinarsko združenje ne odsvetuje oglaševanja | #1559 |
| 2026-11-24 | pravilo runbooka (§3) | barve za AI in izbirnik: **belo-oranžen** (portret), belo-rjav, oranžno lisast, rjavo lisast, bel; naraven rep, roza do rjav nos; nikoli črna, trobarvna, rumenorjava (fawn) ali z rjavimi oznakami | FCI S1023 (5 barv, izključitvene barve); uteži brez vira → `verified: false` | #1559 |
| 2026-11-24 | pravilo runbooka (§2.3) | seznam za vzreditelja: RKC — genetska raznolikost (minimum), BVA/KC komolci in kolki (dobra praksa) | RKC S1024; testi kluba BICA (oči, ledvice) samo v ozadju | #1559 |
| 2026-11-24 | pravilo runbooka | zdravstveni ključi registra: `kidney_disease_urine_screening`, `hip_elbow_dysplasia`, `bloat_gdv`, `ear_infections_prone`, `pra_inherited_blindness`, `eyelids_turned`, `itchy_skin_allergy` (vsi obstoječi); brez odstotkov (klubski odstotki vzrokov smrti niso preneseni) | PetMD S1027, BICA S1028 | #1559 |
| 2026-11-24 | predlog Claude, David lahko spremeni | EN »Bracco Italiano« (RKC / AKC), SL **»italijanski ptičar«** (dobesedno FCI »Italian Pointing Dog«); iskanje tudi »bracco«, »italian pointer«, »italijanski pticar« | FCI S1022, RKC S1024; strani KZS nisem našel | #1559 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »bracco«, »italijanski ptičar«, »italian pointer« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-136 | 2026-11-24 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 99. meseca — predlog; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-136 | 2026-11-24 |
| Česanje 1× na teden, opozorilo »počakaj s sprehodom« 60 min po obroku, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R16 / M5-R17 | 2026-11-24 |
| Barve (izbirnik: belo-oranžen, belo-rjav, oranžno lisast, rjavo lisast, bel — vse s fotografijo) | 🛠 | 💶 izziv | M5-R21 | 2026-11-24 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), bel z oranžnimi lisami na glavi in uhljih, dolgi viseči uhlji, kratka svetleča dlaka, naraven rep, sivo studijsko ozadje | — | M5-R11-04 | 2026-11-24 |
| Stran na petprep.si (`/animals/dogs/bracco-italiano`, `/sl/zivali/psi/italijanski-pticar`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal italijanskega ptičarja (bracco italiano) — kratkodlakega lovskega psa z dolgimi uhlji (25–40 kg) in 132. najbolj priljubljeno pasmo po AKC 2024. Sedem novih virov (S1022–S1028: FCI, RKC, AKC, PetMD, klub BICA). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot 2 uri gibanja), česanje 1× na teden in opozorilo, da po obroku eno uro počaka s sprehodom.
**Zakaj je pomembno:** Bracco je nežen, potrpežljiv in rad ob ljudeh, a potrebuje veliko gibanja in ni za družino, ki je pogosto od doma. PetPrep pokaže, ali družina zmore dve uri gibanja na dan.
**Kako povedati** — starši: »Italijanski ptičar potrebuje več kot dve uri gibanja na dan in rad je ob družini. V PetPrepu boste videli, ali vaš otrok zmore 12.000 korakov na dan in počaka s sprehodom po obroku.« · otroci: »Bracco ima dolga mehka ušesa in veliko energije — z njim veliko hodiš in ga enkrat na teden počešeš.« · vlagatelji / partnerji: »Samodejni nočni zagon je pasmo sam izbral po javni lestvici AKC in jo dodal s sedmimi novimi viri.«

## Odprto za Davida

- **Starejši pri 99 mesecih (predlog Claude, `verified=false`):** RKC »Under 10 years« nima spodnje meje; uporabljena je klubska mediana 11 let (S1028). Alternativi 90 (PetMD 10 let) ali 84 (Dogs Trust). Potrdite ali izberite drugo.
- **Čip »krajša življenjska doba«:** RKC »Under 10 years« proti PetMD 10–14 let in klubski medianami 11 let — čip je po pravilu runbooka; potrdite.
- **Coren:** S35 v tem zagonu ni bil dosegljiv; brez uvrstitve → 1,0. Če je pasma uvrščena, velja pravilo stopenj.
- **Ime:** »italijanski ptičar« v slovenščini ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** število pasem psov v FEATURES / predstavitvah se po pravilu »Own files only« ne spreminja v tem zagonu.
