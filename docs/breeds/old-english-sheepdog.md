# Staroangleški ovčar (Old English Sheepdog)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-93 · 2026-11-04

| | |
|---|---|
| Ključ / slug | `old_english_sheepdog` / `old-english-sheepdog` (SL `staroangleski-ovcar`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 870 — v izbirniku za čau čaujem |
| Vir podatkov | `docs/research/dog-data/data.json` → `old_english_sheepdog` (viri S703–S708; ponovno S10, S11, S14, S24, S35, S45, S54, S174); seznam za vzreditelja: testi kluba pasme iz AKC (K41, `other_scheme`) |
| Izvor | FCI št. 16, skupina 1, sekcija 1 (ovčarski psi), Velika Britanija (S704, S703) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 89. mesto »Old English Sheepdog« (S174, prebrano 2026-11-04) |
| PR-ji | pet-prep #697 (pasma, združen `80c604d`) · portret pet-prep #750 (zagon `breed-portraits.yml` 41939632792 5. 11. 2026, koda `be67ea6`, brez namestitve; sprejet v prvem poskusu) · spletna stran: pet-prep-website `feat/register-old-english-sheepdog` (PR sledi) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-04 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | PDSA S705 »Your Old English Sheepdog will need a minimum of two hours exercise every day.« in »Minimum exercise: 2 hours« (»minimum N« → N); stran RKC ni bila dosegljiva | #697 |
| 2026-11-04 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 (vsi psi) | #697 |
| 2026-11-04 | **predlog Claude — čaka Davida** | starejši od **90. meseca** (meja in prihod starejšega psa, `verified=false`) | 0,75 × **10 let** — spodnja meja PDSA »Over 10 years« (S705) = 7,5 leta (tudi PetMD 10–12 let, Dogs Trust »typically 10+ years«); runbook našteje McMillan 2024, mediano VetCompass in spodnjo mejo RKC — nobena ni bila dosegljiva (tabela Dogs Trust S123 in stran RKC zavrnjeni). Ni izbrano: 109 (McMillan 12,1 leta le prek Wikipedie, S708), 84 (Dogs Trust »> 7 let«) | #697 |
| 2026-11-04 | pravilo runbooka | učenje **0,7×** | Coren: 63. mesto »Old English Sheepdog«, skupina »Fair« (55–69) (S35, preverjeno 2026-11-04) | #697 |
| 2026-11-04 | pravilo runbooka | teža **27–30 kg** (PDSA, oba spola); višina 56–61 cm (PDSA; FCI: samci od 61 cm, psice od 56 cm naprej); rast do 15–18 mesecev (razred »Large«); hrana, voda in čiščenje kot pri border collieju | PDSA S705 »27-30 kg«, »56-61 cm«; FCI S703 teže ne navaja; PetMD S10 | #697 |
| 2026-11-04 | pravilo runbooka (M5-R13) | česanje **vsak dan** (7× na teden); skuštrana dlaka po 4 zamujenih | PDSA S705 »They have long fur which will need a daily brush to make sure it doesn't get matted and tangled.«; PetMD S706 »three or four hours a week« = alternativa | #697 |
| 2026-11-04 | pravila »Breed care tasks« (David 2026-10-24, M5-R18) | **opomnik staršu za frizerja vsakih 6 tednov** (ne šteje v oceno) | PDSA S705 »you will need to take your dog to a professional groomer for a regular trim every six weeks or so.« → 42 dni | #697 |
| 2026-11-04 | pravila »Breed care tasks« | **brez drugih nalog nege** (zobje, ušesa, kremplji, kopanje) | viri ne navajajo pogostosti; PetMD »Check their eyes daily« — za oči ni vrste naloge | #697 |
| 2026-11-04 | David 2026-10-12 (M5-R16) | **opozorilo »počakaj s sprehodom« 60 min po obroku** (koraki se štejejo) | PDSA S705 »Remember to leave a gap after eating and before exercising.« (napihnjenje / GDV) | #697 |
| 2026-11-04 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PDSA S705 »a well-socialised Old English Sheepdog is likely to grow into a happy and confident dog.« | #697 |
| 2026-11-04 | pravilo runbooka (M5-R14) | **brez opozorila za sprehod v vročini** | ni ploskoobrazna pasma (FCI gobec »Strong, square and truncated«, široke nosnice); viri vročine ne omenjajo | #697 |
| 2026-11-04 | pravilo runbooka | primeren za: **aktivno družino**, **družinsko življenje**; upoštevajte: »vsak dan potrebuje veliko gibanja«, »ni za dom z majhnimi otroki«, »rad ima družbo«, »potrebuje česanje«, »močan lovski nagon« | PDSA S705: »minimum of two hours exercise every day«; »easy-going personalities … popular family pets«; »better suited to families with older children«; »separation anxiety when left alone, even for very short periods«; »a daily brush«; »the herding and prey drive is quite strong« | #697 |
| 2026-11-04 | pravilo runbooka | **brez** čipov o izpadanju dlake (PDSA »shed surprisingly little« ob rednem česanju proti Dogs Trust »shed heavily« — viri si nasprotujejo), `apartment` / `large_home` (le iz RKC), `hips_hind_legs` (Breed Watch ni bil dosegljiv), `needs_mental_stimulation` (ni dobesednega citata); nikoli »hypoallergenic« | S705, S707 | #697 |
| 2026-11-04 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** (ni ploskoobrazna, ni izrecnega veterinarskega odsvetovanja oglaševanja); nikoli z majhnimi otroki = predlog Claude | S703, S705 | #697 |
| 2026-11-04 | pravilo runbooka (§3) | barve za AI in izbirnik: **sivo-bela (privzeta)**, modro-bela, sivo prošarano-bela — telo sivo / modro / prošarano, glava, vrat, prednji del in trebuh beli; nikoli rjava ali merle; **naraven rep** (standard dovoli kupiranega ali naravnega — predlog Claude) | FCI S703 »Any shade of grey, grizzle or blue.«, »Head, neck, forequarters and under belly to be white with or without markings.«, »Any shade of brown undesirable.«; uteži brez vira → `verified: false` | #697 |
| 2026-11-04 | pravilo runbooka (§2.3) | seznam za vzreditelja: kolki, pregled oči pri oftalmologu, ščitnica, DNK-test EIC (`other_scheme`, priporočilo ameriškega kluba pasme) | AKC Herding Group K41: »Hip Evaluation«, »Ophthalmologist Evaluation«, »Thyroid Evaluation«, »Exercise-Induced Collapse (EIC) - DNA Test«; stran RKC ni bila dosegljiva | #697 |
| 2026-11-04 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia`, `bloat_gdv`, `deafness`, `cataracts`; brez odstotkov, brez novega ključa (primarna ciliarna diskinezija ostane v podatkih — nov ključ bi potreboval besedilo v vseh 15 jezikih spletne strani) | PDSA S705, PetMD S706 | #697 |
| 2026-11-04 | predlog Claude, David lahko spremeni | EN »Old English Sheepdog«, SL »Staroangleški ovčar«; iskanje tudi »bobtail«, »bob-tail«, »OES«, »bobtejl« | strani KZS nisem preveril | #697 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »staroangleški ovčar«, »bobtail« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-93 | 2026-11-04 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90. meseca — predlog; 4 → 3 → 2 obroka; učenje 0,7×) | 🛠 | 💶 izziv | M5-R10-93 | 2026-11-04 |
| Česanje vsak dan, »Nova izkušnja« za mladička, opozorilo »počakaj s sprehodom« po obroku, opomnik staršu za frizerja vsakih 6 tednov | 🛠 | 💶 izziv | M5-R13 / M5-R16 / M5-R17 / M5-R18 | 2026-11-04 |
| Barva (izbirnik: 3 barve standarda) | 🛠 | 💶 izziv | M5-R21 | 2026-11-04 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«) | — | M5-R11-04 | 2026-11-05 |
| Stran na petprep.si (`/animals/dogs/old-english-sheepdog`, `/sl/zivali/psi/staroangleski-ovcar`) | 🛠 s portretom (spletni PR sledi); »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal staroangleškega ovčarja (bobtail) — velikega, kosmatega ovčarskega psa iz Velike Britanije; 89. najbolj priljubljeno pasmo po AKC 2024 in 84. plačljivo pasmo psa. 6 novih virov (S703–S708). Odrasel pes v igri potrebuje 12.000 korakov na dan (PDSA: vsaj dve uri gibanja), česanje vsak dan, staršem aplikacija vsakih 6 tednov pripomni frizerja, po obroku pa opozori, naj s sprehodom počakata.
**Zakaj je pomembno:** Kosmati »pes iz risank« je eden najzahtevnejših za nego: dolga dlaka se brez vsakodnevnega česanja zaplete, potrebuje dve uri gibanja na dan in ne mara biti sam. PDSA ga opiše kot sproščenega družinskega psa, a bolj za družine s starejšimi otroki.
**Kako povedati** — starši: »Staroangleški ovčar je prijazen družinski pes, a vsak dan potrebuje dve uri gibanja in česanje, vsakih šest tednov pa frizerja. V PetPrepu boste videli, ali vaš otrok to zmore.« · otroci: »Bobtail je velik kosmatinec — vsak dan ga počešeš in greš z njim na dolg sprehod, po kosilu pa malo počakata.« · vlagatelji / partnerji: »Štiriinosemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, opozorili, nalogami nege in pravili.«

## Odprto za Davida

- **Portret (prej ni bil narejen, 2026-11-04):** `breed-portraits.yml` (zagon 41788053301, skupaj s pekinezerjem in čau čaujem) zažene kodo iz `main` proti produkcijski bazi, ki še nima migracije M6-W03 (PR #655: stolpec `item_media_id` v `ai_spend_ledger`). Potrebna je naslednja objava na produkcijo — samodejna dnevna ob 03:17 ali vaša ročna (Actions → CI & Deploy → Run workflow na `main`, target `production`). Naslednji nočni zagon nato najprej naredi vse tri portrete (catch-up) in odpre PR-je spletne strani. **Rešeno 5. 11. 2026:** produkcija je migracije dobila z dnevno namestitvijo `bb3450c` (4. 11.); zagon `breed-portraits.yml` 41939632792 (koda `be67ea6`) je naredil portret, sprejet v prvem poskusu — pet-prep PR #750. Šele zdaj sledi spletna stran.
- **Starejše obdobje od 90. meseca** je moj predlog (`verified=false`): runbook za življenjsko dobo našteje McMillan 2024, VetCompass in spodnjo mejo RKC — nič ni bilo dosegljivo; PDSA navaja »Over 10 years« (spodnja meja, enako kot pri basenjiju in pekinezerju), zato 0,75 × 10 let. Wikipedia navaja McMillan 2024 12,1 leta (to bi dalo 109). Potrdite ali določite drugo vrednost (seeder je samo-vstavljalni → sprememba prek Filamenta ali podatkovne migracije).
- **Naraven rep:** standard FCI dovoli kupiran (»Customarily completely docked«) ali naraven rep; igra in portret rišeta naravnega (moj predlog, kot pri nemškem kratkodlakem ptičarju). Potrdite.
- **Stran RKC ni bila dosegljiva** (preusmeritev na royalkennelclub.com zavrnjena): zato ni čipov »stanovanje« / »velika hiša«, ni kategorije Breed Watch (čip za kolke) in ni testov RKC za vzreditelja (uporabljen ameriški klub pasme prek AKC). Ob naslednji priložnosti preverite.
- **Izpadanje dlake:** PDSA (»shed surprisingly little«, če ga redno češete) in Dogs Trust (»shed heavily«) si nasprotujeta → ni čipa. Če želite čip »izpada mu dlaka«, odločite.
- **Skupni test čipa »lovski nagon«:** `BreedSuitabilityTest` je zahteval besedi »high / strong prey drive«; PDSA tu pravi »prey drive is quite strong« — vzorec testa je razširjen na to obliko (izjema v skupnem testu, navedena v PR).
- **Ime:** »Staroangleški ovčar« ni preverjen na straneh KZS. Potrdite ali popravite.
