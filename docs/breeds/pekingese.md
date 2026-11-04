# Pekinezer (Pekingese)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-91 · 2026-11-04

| | |
|---|---|
| Ključ / slug | `pekingese` / `pekingese` (SL `pekinezer`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 850 — v izbirniku za anatolskim ovčarjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `pekingese` (viri S690–S695; ponovno S10, S11, S14, S24, S35, S45, S54, S92, S174); seznam za vzreditelja: seznam pasem RKC za oceno dihanja prek Dogs NSW (K40) |
| Izvor | FCI št. 207, skupina 9, sekcija 8 (japonski čin in pekinezer), Kitajska, pokroviteljstvo Velika Britanija (S691, S690) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 87. mesto »Pekingese« (S174, prebrano 2026-11-04) |
| PR-ji | pet-prep #671 (pasma, združen `7e275db`) · portret: **ni narejen** — zagon `breed-portraits.yml` 41752022895 dvakrat neuspešen (na produkcijskem strežniku »No space left on device« in baza `postgres` ni dosegljiva v enkratnem vsebniku) · spletna stran: besedila na veji pet-prep-website `feat/register-pekingese` (PR šele s portretom) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-04 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **30 min/dan → 3.000 korakov**; starejši 23 min → 2.300; mladiček 10 min × starost do 30 (pri 3 mesecih) | PDSA S693 »Your Pekingese will need a minimum of half an hour exercise every day.« in »Minimum exercise (per day) \| 30 minutes« (»minimum N« → N); stran RKC ni bila dosegljiva; 0,75 × 30 = 22,5 → 23 | #671 |
| 2026-11-04 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 (vsi psi) | #671 |
| 2026-11-04 | **predlog Claude — čaka Davida** | starejši od **108. meseca** (meja in prihod starejšega psa, `verified=false`) | 0,75 × spodnja meja PDSA **12 let** (»Over 12 years«, S693) = 9 let; runbook našteje McMillan 2024, mediano VetCompass in spodnjo mejo RKC — nobena ni bila dosegljiva (tabela Dogs Trust S123 se ni naložila, stran RKC zavrnjena) → najbližja vrstica pravila (precedens basenji). PetMD 12–14 let se ujema. Ni izbrano: 120 (McMillan 13,3 leta le prek Wikipedie, S695), 84 (Dogs Trust »> 7 let«) | #671 |
| 2026-11-04 | pravilo runbooka | učenje **0,5×** | Coren: 73. mesto »Pekingese«, skupina »Lowest« (70–79) (S35, preverjeno 2026-11-04) | #671 |
| 2026-11-04 | pravilo runbooka | teža: povprečje **5 kg** (PDSA, oba spola) v igri; register prikaže tudi zgornji meji FCI (samci do 5 kg, psice do 5,4 kg); višina 15–22 cm (PDSA); rast do 8–12 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S693 »5kg«, »15-22 cm«; FCI S690 »Le poids idéal ne dépasse pas 5 kg chez les mâles et 5,4 kg chez les femelles.«; PetMD S10 (razred »Toy«) | #671 |
| 2026-11-04 | pravilo runbooka (M5-R13) | česanje **vsak dan** (7× na teden); skuštrana dlaka po 4 zamujenih | PDSA S693 »Pekingese have really thick, long fur that will need a daily brush to stop it from getting tangled and matted.« (»daily« → 7); PetMD »at least one hour each week« (alternativa brez števila sej) | #671 |
| 2026-11-04 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PDSA S693 »It’s important to socialise your Pekingese from a young age with lots of different people, dogs and experiences.« | #671 |
| 2026-11-04 | pravila »Breed care tasks« (David 2026-10-24, M5-R15 / R18 / R20) | mini igri **ščetkanje zob vsak dan** in **čiščenje ušes 1× na teden**; opomnik staršem **obisk frizerja vsakih 6 tednov** | PetMD S694 »The best way to prevent dental disease is with daily tooth brushing«; »clean their Pekingese’s ears every week or two« (spodnja meja → 1× na teden); »Most Pekingese with a long coat require professional grooming every six to eight weeks.« (spodnja meja 6 tednov). Gube na obrazu »regular cleaning« in kremplji brez pogostosti → brez naloge; kopanje le zgornja meja (»more than twice a month … may strip«) → brez opomnika; brisanje oči vsak dan (PetMD) — vrste naloge za oči v igri ni, ostane dejstvo | #671 |
| 2026-11-04 | pravilo runbooka (M5-R14) | **opozorilo za sprehod poleti opoldne** (ploskoobrazna pasma, vir o vročini) | PDSA S693 »avoid exercising them on hotter days.«, »Instead, try walking them early in the morning or in the evening when the temperature is cooler.«; PetMD S694 »so exercise in hot weather should be avoided.« | #671 |
| 2026-11-04 | pravilo runbooka | brez opozorila po obroku (M5-R16) | noben vir ne omenja premora med hranjenjem in gibanjem | #671 |
| 2026-11-04 | pravilo runbooka | **brez oznake »primeren za«**; upoštevajte **»kratek gobček — težave z dihanjem in vročino«**, **»majhen pes — bolje za starejše otroke«**, **»rad ima družbo«**, **»veke in oči«**, **»občutljiva koža«**, »izpada mu dlaka«, »potrebuje česanje večkrat na teden« | PDSA S693: »Pekes are considered a ‘Brachycephalic’ breed …«; »they tend to do better in adult-only households or families with older children«; »This means that they can suffer from separation anxiety if they are left alone.«; »Entropion and ectropion – where the eyelids can turn inward or outwards irritating the eye.«; »… skin fold dermatitis.«; »They do shed a lot throughout the year, too, especially in spring and autumn«; dnevno česanje + frizer (PetMD S694) | #671 |
| 2026-11-04 | pravilo runbooka | **brez** `apartment` (le iz strani RKC, ki ni bila dosegljiva), `children`, `small_children`, `other_pets`, `chases_small_pets`, `often_alone`, `long_daily_exercise`, `short_muzzle_breathing`, `shorter_lifespan`; nikoli »hypoallergenic« | PDSA: »Pekes don’t really have a prey drive« + nadzor ob drugih živalih (ni izrecne izjave »primeren za«); »Over 12 years« | #671 |
| 2026-11-04 | pravilo runbooka (§3, dobrobit) | **ploskoobrazna pasma s skrbmi za dobrobit** (PDSA »Brachycephalic«, RKC jo uvršča med pasme za oceno dihanja — K40) → čip `brachycephalic_breathing`; marketing: **v aplikaciji da, v marketingu / posnetkih zaslona ne** (BVA S92 »avoiding imagery of brachycephalic dogs in advertising …«) | S693, S694, K40, S92 | #671 |
| 2026-11-04 | pravilo runbooka (§3) | barve za AI in izbirnik (vse barve so po standardu dovoljene): **rdeče zlata s črno masko (privzeta)**, plava s črno masko, sobolja, kremasta, črna, črno-rjava, bela, lisasta — nikoli albino, jetrno rjava ali merle; portret: zmerno kratek obraz z vidnim gobčkom in odprtimi nosnicami, brez debele gube čez nos | KC S692 »All colours and markings are permissible and of equal merit.«; FCI S690 razen albino in »marron (foie)«; Wikipedia S695 (tier C): večina je zlatih, rdečih ali sobolji; seznam in uteži brez vira → `verified: false` | #671 |
| 2026-11-04 | pravilo runbooka (§2.3) | seznam za vzreditelja: **ocena dihanja BOAS** po shemi Respiratory Function Grading (`boas_grading`, `other_scheme`) | stran RKC ni bila dosegljiva → seznam pasem RKC za oceno prek Dogs NSW (K40, april 2026, »Pekingese«); brez novega ključa | #671 |
| 2026-11-04 | pravilo runbooka | zdravstveni ključi registra: `flat_face_breathing`, `heat_stroke_risk`, `eyelids_turned`, `corneal_ulcers`, `dry_eye`, `pra_inherited_blindness`, `kneecap_luxation`, `dental_disease_home_brushing`; brez odstotkov, brez novega ključa | PDSA S693, PetMD S694 | #671 |
| 2026-11-04 | predlog Claude, David lahko spremeni | EN »Pekingese«, SL »Pekinezer«; iskanje tudi »pekinese«, »peke«, »lion dog«, »pekinez«, »pekinški pes« | strani KZS nisem preveril | #671 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »pekinezer«, »pekingese«, »peke« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-91 | 2026-11-04 |
| Pravila po starosti (2.000 → 3.000 korakov pri 3 mesecih, odrasel 3.000, starejši 2.300 od 108. meseca — predlog; 4 → 3 → 2 obroka; učenje 0,5×) | 🛠 | 💶 izziv | M5-R10-91 | 2026-11-04 |
| Česanje vsak dan, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-04 |
| Ščetkanje zob vsak dan in čiščenje ušes 1× na teden (mini igri), opomnik za frizerja vsakih 6 tednov, opozorilo za sprehod poleti opoldne | 🛠 | 💶 izziv | M5-R14 / M5-R15 / M5-R18 / M5-R20 | 2026-11-04 |
| Barva (izbirnik: 8 barv standarda) | 🛠 | 💶 izziv | M5-R21 | 2026-11-04 |
| AI portret v registru | 🗓 ni narejen (napaka strežnika 2026-11-04 — čaka Davida) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/pekingese`, `/sl/zivali/psi/pekinezer`) | sledi s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal pekinezerja — majhnega »levjega« psa s Kitajske z dolgo dlako in grivo; 87. najbolj priljubljeno pasmo po AKC 2024 in 82. plačljivo pasmo psa. 6 novih virov (S690–S695) in en vir za vzreditelje (K40). Odrasel pes v igri potrebuje 3.000 korakov na dan (PDSA: vsaj pol ure gibanja), česanje vsak dan, otrok mu vsak dan ščetka zobe in enkrat na teden očisti ušesa, starš dobi opomnik za frizerja vsakih 6 tednov, poleti opoldne pa aplikacija opozori, naj s sprehodom počakata.
**Zakaj je pomembno:** Pekinezer je navezan na svoje ljudi — PDSA ga priporoča le domu, kjer je nekdo ves dan doma, in raje starejšim otrokom. Je ploskoobrazna pasma: lažje se pregreje in težje diha, gube na obrazu in oči potrebujejo pozornost. PetPrep tega ne olepšuje — v izbirniku je sedem čipov »upoštevajte«, nega je vsakodnevna naloga.
**Kako povedati** — starši: »Pekinezer je majhen, a zahteven pes: vsak dan česanje in zobki, ne prenese vročine in ne mara biti sam. V PetPrepu boste videli, ali vaš otrok zmore vsakodnevno skrb.« · otroci: »Pekinezer je majhen lev z dolgo grivo — vsak dan ga počešeš in mu ščetkaš zobke, poleti pa ne hodita ven, ko je najbolj vroče.« · vlagatelji / partnerji: »Dvainosemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, opozorili o dobrobiti, nalogami nege in testi.« (v marketingu brez slik pekinezerja — BVA)

## Odprto za Davida

- **Portret ni narejen (2026-11-04):** `breed-portraits.yml` (zagon 41752022895, dva poskusa) se je na produkcijskem strežniku ustavil z »No space left on device« in »could not translate host name "postgres"«. Preverite prostor na disku strežnika (in ali vsebnik baze teče); naslednji nočni zagon nato najprej naredi portret (catch-up) in odpre PR spletne strani (besedila so pripravljena na veji `feat/register-pekingese`).
- **Starejše obdobje od 108. meseca** je moj predlog (`verified=false`): runbook za življenjsko dobo našteje McMillan 2024, VetCompass in spodnjo mejo RKC — nič ni bilo dosegljivo, uporabljena je spodnja meja PDSA 12 let (kot pri basenjiju). Wikipedia navaja McMillan 2024 13,3 leta (to bi dalo 120). Potrdite ali določite drugo vrednost (seeder je samo-vstavljalni → sprememba prek Filamenta ali podatkovne migracije).
- **Stran RKC ni bila dosegljiva** (preusmeritev na royalkennelclub.com zavrnjena): zato ni čipa »življenje v stanovanju«, ni kategorije Breed Watch in ni minimalnih testov RKC za vzreditelja. Ob naslednji priložnosti preverite.
- **Marketing:** izključen po pravilu BVA (S92) — pasma je izrazito ploskoobrazna. Če jo želite v marketingu, povejte.
- **Barve:** standard dovoli vse barve; izbirnik ponuja 8 pogostih (seznam in uteži so moji, brez vira).
- **Ime:** »Pekinezer« ni preverjen na straneh KZS. Potrdite ali popravite.
- **Oči in gube:** PetMD svetuje brisanje oči vsak dan in redno čiščenje gub, a za oči igra nima naloge, za gube pa vir ne navaja pogostosti → le dejstvo (pravilo). Če želite nalogo za oči, je to nova mehanika — potrebuje novo nalogo v ROADMAP (David).
