# Bull Terrier (bulterier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-76 · 2026-10-29

| | |
|---|---|
| Ključ / slug | `bull_terrier` / `bull-terrier` (SL `bulterier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 700 — v izbirniku za cairn terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `bull_terrier` (viri S589–S593; ponovno S8, S10, S11, S14, S24, S35, S45, S92, S174); seznam za vzreditelja: 4 testi ameriškega pasemskega kluba (AKC K33) |
| Izvor | FCI št. 11 »Bull Terrier«, skupina 3, sekcija 3 (terierji bul tipa), Velika Britanija (S589); ni miniaturni bulterier (ločena pasma) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 72. mesto »Bull Terrier« (S174, prebrano 2026-10-29) |
| PR-ji | pet-prep #435 (pasma, združen `a046859`) · portret: #436 (zagon 40799331054) · spletna stran: pet-prep-website `feat/register-bull-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-29 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | PDSA S591 »Minimum exercise (per day): 1 hour« (»minimum N« → N); stran RKC ni bila dosegljiva (pravilo RKC → PDSA); PetMD S592 brez števila | #435 |
| 2026-10-29 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 (za vse pse enako) | #435 |
| 2026-10-29 | **predlog Claude — čaka Davida** (`verified=false`) | starejši od **90. meseca** (tudi starost ob prihodu starejšega psa) | 0,75 × spodnja meja PDSA »Over 10 years« (S591) = 90. Pravilo runbooka (McMillan 2024 → VetCompass → spodnja meja RKC) v tem zagonu ni dalo vrednosti: tabela Dogs Trust (S123) in stran RKC nista bili dosegljivi. Precedens: samojed, škotski terier. Alternative: McMillan 2024 12 let le prek Wikipedije (S593, raven C) ali PetMD 12–13 let → 108; Dogs Trust »> 7 let« → 84 | #435 |
| 2026-10-29 | pravilo runbooka | učenje **0,7×** (Coren 66. mesto, »zadovoljivi«) | S35 (prebrano 2026-10-29) | #435 |
| 2026-10-29 | pravilo runbooka | teža **22–31 kg** (oba spola), višina 51–61 cm, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | standard FCI S590 »There are neither weight nor height limits.« → ključna dejstva PDSA S591; PetMD 50–70 lb kot alternativa; PetMD S10 (razred »Medium«) | #435 |
| 2026-10-29 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | PDSA S591 »Giving them a brush once a week …«; PetMD S592 »a quick weekly once-over« se ujema | #435 |
| 2026-10-29 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S591 »As long as your dog is socialised properly, they should get along really well with people of all ages.« | #435 |
| 2026-10-29 | David 2026-10-12 (M5-R16) | opozorilo **»počakaj s sprehodom« 60 min po obroku** (samo opozorilo, koraki se štejejo) | PDSA S591 »Remember to leave a gap after eating and before exercising.« | #435 |
| 2026-10-29 | pravila »Breed care tasks« (David 2026-10-24) | **brez** nalog zob, ušes, krempljev, kopanja, frizerja ali obraza; brez opozorila za vročino | PetMD: ušesa »redno«, kopanje redko — brez frekvence; ni plosko-obrazna pasma | #435 |
| 2026-10-29 | pravilo runbooka (»nova oznaka«) | **nova oznaka** `lively_older_children`: »živahen pes — bolje za starejše otroke« / »lively dog — better with older children« | PDSA S591 »However, as they can be a bit excitable, they are better with older children.« — obstoječe oznake govorijo o majhnem, krhkem, občutljivem ali velikem psu, nobena od teh ne drži | #435 |
| 2026-10-29 | pravilo runbooka | oznake: primeren za »družino z otroki« (`children` — z opombo PDSA: bolje za starejše otroke, igro vedno nadzorujte); upoštevajte `lively_older_children`, »rad ima družbo« (`needs_company`), »močan lovski nagon« (`chases_small_pets`) | PetMD S592 »affectionate with family—including children«; PDSA S591 »like to have company as much as possible«, »As they have a high prey drive …« | #435 |
| 2026-10-29 | pravilo runbooka | **brez** `family_pet`, `small_children`, `apartment`, `large_home`, `other_pets`, `sheds`, `chews_when_bored`, `sensitive_skin`, `long_daily_exercise`; nikoli »hypoallergenic« | ni izjave »družinski pes«; stran RKC ni bila dosegljiva; žvečenje pri PetMD ni potrjeno dobesedno; kožne alergije le PDSA (ni točka Breed Watch) — glej Odprto | #435 |
| 2026-10-29 | pravilo runbooka (§3) | ni pasma z oznako dobrobiti; marketing: **v aplikaciji da, v marketingu da** | nobeno veterinarsko združenje pasme ne navaja; ni plosko-obrazna pasma, BVA S92 ne velja | #435 |
| 2026-10-29 | pravilo runbooka (§3) | barve za AI in izbirnik (6): **bel** (portret), tigrast, črno tigrast, rdeč, plav, tribarven; nikoli moder, jetrno rjav ali merle | standard FCI S590 (bel; barvni: tigrast prednosten, črno tigrast, rdeč, plav, tribarven; moder in jetrn zelo nezaželena); uteži barv brez vira → `verified: false`; barva portreta je predlog Claude | #435 |
| 2026-10-29 | predlog Claude, David lahko spremeni | EN »Bull Terrier«, SL »Bulterier«; iskanje »bulterier«, »english bull terrier«, »bully«, »bul terier« | slovenskega vira (KZS) za ime ni bilo mogoče preveriti; »angleški bulterier« izpuščen, ker bi pri iskanju »angleški« zadel tudi druge pasme | #435 |
| 2026-10-29 | pravilo runbooka (§2.3) | seznam za vzreditelja (ameriški pasemski klub, raven »drugo«): pogačica, srce, sluh (BAER), urin (razmerje beljakovine : kreatinin); nov ključ `kidney_upc_scheme` | AKC K33 (stran RKC ni bila dosegljiva → brez ravni »minimum« / »best practice«) | #435 |
| 2026-10-29 | pravilo runbooka | zdravstveni ključi registra: `deafness`, `lethal_acrodermatitis` (nov), `kidney_disease_urine_screening` (nov), `heart_valve_screening`, `kneecap_luxation`, `itchy_skin_allergy`; brez odstotkov | PDSA S591, PetMD S592 | #435 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »bulterier«, »bully« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-76 | 2026-10-29 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 90. meseca — meja je predlog; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-76 | 2026-10-29 |
| Česanje 1× na teden (rutina »Počeši«), »Nova izkušnja« za mladička, opozorilo »počakaj s sprehodom« po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 | 2026-10-29 |
| Barva (izbirnik: 6 standardnih barv, privzeto bel) | 🛠 | 💶 izziv | M5-R21 | 2026-10-29 |
| AI portret v registru (bel, sprejet v prvem poskusu, zagon 40799331054, PR #436) | ✅ | — | M5-R11-04 | 2026-10-29 |
| Stran na petprep.si (`/animals/dogs/bull-terrier`, `/sl/zivali/psi/bulterier`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal bulterierja — 72. najbolj priljubljeno pasmo po AKC 2024 in 70. plačljivo pasmo psa. 5 novih virov (S589–S593). Odrasel pes v igri potrebuje 6.000 korakov na dan (PDSA: najmanj 1 ura gibanja), po obroku aplikacija opozori, naj s sprehodom počaka, česanje je enkrat na teden, mladiček pa vsak teden dobi novo izkušnjo. Prvič je nastala oznaka »živahen pes — bolje za starejše otroke«.
**Zakaj je pomembno:** Bulterier s prepoznavno jajčasto glavo je ljubeč do družine, a živahen, rad ima družbo in ima močan lovski nagon. PetPrep pokaže, ali otrok zmore vsak dan uro gibanja in ali je družina doma dovolj časa — preden pride pravi pes.
**Kako povedati** — starši: »Bulterier je ljubeč, a zelo živahen pes, ki je po PDSA bolje za družine s starejšimi otroki. Potrebuje vsaj uro gibanja na dan, družbo večino dneva in previdnost ob mačkah in malih živalih. V PetPrepu boste videli, ali vaš otrok zmore 12 tednov skrbi zapored.« · otroci: »Bulterier ima glavo v obliki jajca in ogromno energije. Vsak dan gre na sprehod, po obroku pa malo počaka, preden gre ven.« · vlagatelji / partnerji: »Sedemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi, novo oznako in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Meja starejšega psa (90. mesec):** predlog Claude (`verified=false`) iz spodnje meje PDSA »Over 10 years«. Če je mediana McMillan 2024 res 12 let (Wikipedija), bi bila meja 108. Potrdite ali spremenite (Filament ali podatkovna migracija — sejalnik samo dodaja).
- **Kožne alergije:** PDSA (S591): »Bull Terriers are more prone to developing skin allergies.« Po pravilu brez čipa `sensitive_skin` (ni točka Breed Watch). Želite čip vseeno?
- **Žvečenje:** PetMD omenja, da »jedo in žvečijo vse okoli sebe« (zapore črevesja), a citat ni potrjen dobesedno → brez čipa `chews_when_bored`.
- **Stran RKC:** ni bila dosegljiva (preusmeritev na royalkennelclub.com zavrnjena) — ni ključnih dejstev RKC (velikost doma, dlaka, izguba dlake), ni kategorije Breed Watch in ni testov RKC; gibanje in teža iz PDSA.
- **Ime:** »Bulterier« ni preverjeno pri KZS.
- **Barva portreta:** bel je predlog Claude (standard dovoli belo ali barvno — tigrasto, črno tigrasto, rdečo, plavo, tribarvno). Portret: zagon 40799331054 (koda `a046859`, brez produkcijske namestitve, 0,15 $), sprejet v prvem poskusu — realistična fotografija odraslega psa, čisto bela kratka dlaka, jajčasta glava, pokončna ušesa, črn nos, svetlo siva studijska podlaga; PR #436.
