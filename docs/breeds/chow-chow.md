# Čau čau (Chow Chow)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-92 · 2026-11-04

| | |
|---|---|
| Ključ / slug | `chow_chow` / `chow-chow` (SL `cau-cau`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 860 — v izbirniku za pekinezerjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `chow_chow` (viri S696–S702; ponovno S10, S11, S14, S24, S35, S45, S54, S174); seznam za vzreditelja: ni najden (`not_found`) |
| Izvor | FCI št. 205, skupina 5, sekcija 5 (azijski špici in sorodne pasme), Kitajska, pokroviteljstvo Velika Britanija (S697, S696) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 88. mesto »Chow Chow« (S174, prebrano 2026-11-04) |
| PR-ji | pet-prep #682 (pasma, združen `388a153`) · portret: **ni narejen** — zagon `breed-portraits.yml` 41764816596 dvakrat neuspešen (na produkcijskem strežniku »No space left on device« in baza `postgres` ni dosegljiva v enkratnem vsebniku) · spletna stran: PR šele s portretom |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-04 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | PDSA S700 »Your Chow Chow will need a minimum of one hour exercise every day.« in »Minimum exercise (per day): 1 hour« (»minimum N« → N); stran RKC ni bila dosegljiva; PetMD S701 »A couple of 20-minute walks every day is enough.« (40 min) = alternativa | #682 |
| 2026-11-04 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 (vsi psi) | #682 |
| 2026-11-04 | **predlog Claude — čaka Davida** | starejši od **90. meseca** (meja in prihod starejšega psa, `verified=false`) | 0,75 × **10 let** — edina meja PDSA »Under 10 years« (S700) = 7,5 leta; runbook našteje McMillan 2024, mediano VetCompass in spodnjo mejo RKC — nobena ni bila dosegljiva (tabela Dogs Trust S123 in stran RKC zavrnjeni). Ni izbrano: 109 (McMillan 12,1 leta le prek Wikipedie, S702), 84 (Dogs Trust »> 7 let«), 72 (spodnja meja PetMD 8 let) | #682 |
| 2026-11-04 | pravilo runbooka | učenje **0,5×** | Coren: 76. mesto »Chow Chow«, skupina »Lowest« (70–79) (S35, preverjeno 2026-11-04) | #682 |
| 2026-11-04 | pravilo runbooka | teža **20–30 kg** (PDSA, oba spola); višina samci 48–56 cm, psice 46–51 cm (FCI); rast do 15–18 mesecev (razred »Large«); hrana, voda in čiščenje kot pri border collieju | PDSA S700 »20-30 kg«; FCI S696 »48 - 56 cm« / »46 - 51 cm«; standardi teže ne navajajo; PetMD S10 | #682 |
| 2026-11-04 | pravilo runbooka (M5-R13) | česanje **vsak dan** (7× na teden); skuštrana dlaka po 4 zamujenih | PDSA S700 »Chow Chows need brushing every day to keep their fur in good condition.«; PetMD S701 »every other day« = alternativa | #682 |
| 2026-11-04 | David 2026-10-12 (M5-R16) | **opozorilo »počakaj s sprehodom« 60 min po obroku** (koraki se štejejo) | PDSA S700 »Remember to leave a gap after eating and before exercising.« (napihnjenje / GDV) | #682 |
| 2026-11-04 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PDSA S700 »it's really important to make sure your Chow Chow is well-socialised from a young age« | #682 |
| 2026-11-04 | pravila »Breed care tasks« (David 2026-10-24) | **brez nalog nege** (zobje, ušesa, kremplji, gube, kopanje, frizer) | viri ne navajajo pogostosti: PDSA gube »kept clean and dry«, PetMD »Regular bathing …«, »Regularly scheduled trips to a professional groomer …« → le dejstvo | #682 |
| 2026-11-04 | pravilo runbooka (M5-R14) | **brez opozorila za sprehod v vročini** | ni ploskoobrazna pasma (FCI gobec »Moderate in length«); PetMD S701 le »more likely to overheat because of their thick coats and short snout« → čip `heat_sensitive` | #682 |
| 2026-11-04 | pravilo runbooka | **brez oznake »primeren za«**; upoštevajte: »ni za dom z majhnimi otroki«, »rad ima družbo«, »potrebuje miselne izzive«, »gost kožuh — slabo prenaša vročino«, »veke in oči«, »občutljiva koža«, »krajša življenjska doba«, »izpada mu dlaka«, »potrebuje česanje«, »močan lovski nagon« | PDSA S700: »We really wouldn't recommend having a Chow Chow if you have small children«; »may not tolerate being alone«; »clever and get bored easily … puzzle toys«; entropion; gube »kept clean and dry«; »Under 10 years«; »shed pretty much all year round«; »brushing every day«; »a high prey drive can come quite naturally to them«; PetMD S701 pregrevanje | #682 |
| 2026-11-04 | pravilo runbooka | **brez** `family_pet` (PDSA »in the right household« je pogojno), `children`, `older_children`, `experienced_owner` (vir ne pravi »močan«), `independent_experienced_owner` (besedilo čipa pravi »trmast« — vir tega ne pravi), `apartment` (le iz RKC), `brachycephalic_breathing`, `hips_hind_legs` (Breed Watch ni bil dosegljiv); nikoli »hypoallergenic« | S700, S696 | #682 |
| 2026-11-04 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** (ni ploskoobrazna, ni izrecnega veterinarskega odsvetovanja oglaševanja); nikoli z majhnimi otroki = predlog Claude | S696, S700 | #682 |
| 2026-11-04 | pravilo runbooka (§3) | barve za AI in izbirnik (le enobarvne po standardu): **rdeča (privzeta)**, črna, kremasta, plava (fawn), modra, bela — nikoli lise, večbarvnost ali merle; portret: zmeren gobec, čiste odprte oči | FCI S696 »Whole coloured black, red, blue, fawn, cream or white, frequently shaded but not in patches or parti-coloured«; AKC S699 navede rdečo prvo; uteži brez vira → `verified: false` | #682 |
| 2026-11-04 | pravilo runbooka (§2.3) | seznam za vzreditelja: **ni najden** (`not_found`) — prikaže se le splošni seznam | stran RKC ni bila dosegljiva; PDSA / PetMD naštejeta bolezni, ne testov | #682 |
| 2026-11-04 | pravilo runbooka | zdravstveni ključi registra: `hip_elbow_dysplasia`, `bloat_gdv`, `entropion`, `skin_fold_infections`, `panosteitis`, `hypothyroidism`; brez odstotkov, brez novega ključa | PDSA S700, PetMD S701 | #682 |
| 2026-11-04 | predlog Claude, David lahko spremeni | EN »Chow Chow«, SL »Čau čau«; iskanje tudi »chow«, »chow-chow«, »cau cau«, »čau-čau«, »čavčav«, »kitajski levji pes« | strani KZS nisem preveril | #682 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »čau čau«, »chow chow« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-92 | 2026-11-04 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 90. meseca — predlog; 4 → 3 → 2 obroka; učenje 0,5×) | 🛠 | 💶 izziv | M5-R10-92 | 2026-11-04 |
| Česanje vsak dan, »Nova izkušnja« za mladička, opozorilo »počakaj s sprehodom« po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R16 / M5-R17 | 2026-11-04 |
| Barva (izbirnik: 6 barv standarda) | 🛠 | 💶 izziv | M5-R21 | 2026-11-04 |
| AI portret v registru | 🗓 ni narejen (napaka strežnika 2026-11-04 — čaka Davida) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/chow-chow`, `/sl/zivali/psi/cau-cau`) | 🗓 sledi s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal čau čauja — kitajskega »levjega« špica z gosto grivo; 88. najbolj priljubljeno pasmo po AKC 2024 in 83. plačljivo pasmo psa. 7 novih virov (S696–S702). Odrasel pes v igri potrebuje 6.000 korakov na dan (PDSA: vsaj ura gibanja), česanje vsak dan, po obroku pa aplikacija opozori, naj s sprehodom počakata (napihnjenje želodca).
**Zakaj je pomembno:** PDSA ga odsvetuje družinam z majhnimi otroki, priporoča ga izkušenim lastnikom in domu, kjer je nekdo ves dan doma. Gosta dlaka pomeni vsakodnevno česanje in slabo prenašanje vročine; živi krajše od mnogih pasem. PetPrep tega ne olepšuje — v izbirniku je deset čipov »upoštevajte«.
**Kako povedati** — starši: »Čau čau je lep, a zahteven pes: vsak dan česanje, ni za majhne otroke in ne mara biti sam. V PetPrepu boste videli, ali vaš otrok zmore vsakodnevno skrb.« · otroci: »Čau čau je kot majhen lev z gosto grivo — vsak dan ga počešeš, po kosilu pa malo počakata, preden gresta ven.« · vlagatelji / partnerji: »Triinosemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, opozorili, nalogami nege in pravili.«

## Odprto za Davida

- **Portret ni narejen (2026-11-04):** `breed-portraits.yml` (zagon 41764816596, dva poskusa, skupaj s pekinezerjem) se je na produkcijskem strežniku ustavil z »No space left on device« in »could not translate host name "postgres"«. Sprostite prostor na disku strežnika (in preverite, ali vsebnik baze teče); naslednji nočni zagon nato najprej naredi oba portreta (catch-up) in odpre PR spletne strani.
- **Starejše obdobje od 90. meseca** je moj predlog (`verified=false`): runbook za življenjsko dobo našteje McMillan 2024, VetCompass in spodnjo mejo RKC — nič ni bilo dosegljivo; PDSA navaja »Under 10 years« (le zgornja meja), zato 0,75 × 10 let. Wikipedia navaja McMillan 2024 12,1 leta (to bi dalo 109) — kar je v nasprotju s PDSA. Potrdite ali določite drugo vrednost (seeder je samo-vstavljalni → sprememba prek Filamenta ali podatkovne migracije).
- **Čip »krajša življenjska doba«** sledi pravilu (PDSA »Under 10 years«), čeprav McMillan 2024 prek Wikipedie navaja 12,1 leta. Če McMillan potrdite, čip odstranite.
- **Stran RKC ni bila dosegljiva** (preusmeritev na royalkennelclub.com zavrnjena): zato ni čipa »življenje v stanovanju«, ni kategorije Breed Watch (čip za kolke / zadnje noge) in ni testov RKC za vzreditelja. Ob naslednji priložnosti preverite.
- **Izkušen lastnik:** PDSA ga priporoča »experienced owners with a good knowledge of the breed«, FCI ga opiše kot »Independent«. Obstoječa čipa ne ustrezata (`experienced_owner` govori o močnem psu, `independent_experienced_owner` o »trmastem« — vir tega ne pravi), zato čipa ni. Če ga želite, potrebuje nov ključ ali spremembo besedila (David).
- **Velikost:** PDSA ima pasmo pod »Large« (in v razdelku srednjih psov); 20–30 kg je na meji med srednjim in velikim razredom rasti (igre ne spremeni).
- **Ime:** »Čau čau« ni preverjen na straneh KZS. Potrdite ali popravite.
