# Welsh Terrier (Valižanski terier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-127 · 2026-11-19

| | |
|---|---|
| Ključ / slug | `welsh_terrier` / `welsh-terrier` (SL `valizanski-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1180 — v izbirniku za mudijem |
| Vir podatkov | `docs/research/dog-data/data.json` → `welsh_terrier` (viri S954–S959; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: RKC — genetska raznolikost + DNK test za primarno luksacijo leče (PLL), oboje »minimum« (S956) |
| Izvor | FCI št. 78, skupina 3 (terierji), sekcija 1 (veliki in srednje veliki terierji), Velika Britanija (S954, S955) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 123. mesto »Welsh Terrier« (S174, prebrano 2026-11-19) |
| PR-ji | pet-prep #1391 (pasma) · portret in barvne različice: glejte vrstico v `BREED_QUEUE.md` · spletna stran: pet-prep-website `feat/register-welsh-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-19 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC Breeds A–Z S956 »Up to 1 hour per day« (»up to N« → N). PDSA S957 »around an hour of exercise daily«, PetMD S958 »one or two hours« → prav tako 60 | #1391 |
| 2026-11-19 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108**; prihod 2 / 9 / 36 / 108 | AAHA S11; starejši = 0,75 × spodnja meja RKC **»Over 12 years«** (S956, PDSA S957 enako) = 108 mesecev. McMillan 2024 (S123) ni bil dosegljiv. Ni izbrano: 84 (Dogs Trust »> 7 let«, S14) | #1391 |
| 2026-11-19 | pravilo runbooka | učenje **1,0×** | Coren: »Average Working/Obedience Intelligence« (S35); številke mesta v zrcalih ni bilo mogoče prebrati (neuvrščeno bi dalo prav tako 1,0) | #1391 |
| 2026-11-19 | pravilo runbooka | teža **9–9,5 kg** (oba spola), višina do 39 cm (FCI), rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S955 »9-9,5 kg (20-21 lbs).«, »Height at shoulders not exceeding 39 cm (15 ½ ins.).«; PetMD S10 (razred »Small«) | #1391 |
| 2026-11-19 | pravilo runbooka (M5-R13) | česanje **3× na teden**; skuštrana dlaka po 2 zamujenih | RKC S956 »Grooming: More than once a week« nima številke → PDSA S957 »grooming two-three times per week« → zgornja meja 3 (PetMD S958 »once or twice a week« = alternativa) | #1391 |
| 2026-11-19 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PDSA S957 »As with all breeds, training, along with socialisation, is very important at a young age …« | #1391 |
| 2026-11-19 | pravila »Breed care tasks« (David 2026-10-24) | **ščetkanje zob vsak dan** (otrok); **frizer vsakih 8 tednov** in **kopanje vsakih 8 tednov** (opomnik staršem, ne šteje v oceno) | WebMD S959 »… brush your Welsh terrier's teeth … every day.«; PetMD S958 »A professional grooming appointment is needed every two to three months …«, »bathed every two to three months« → spodnja meja 2 meseca. Kremplji (WebMD: ko »klikajo«) in ušesa (PetMD: brez pogostosti) → brez naloge | #1391 |
| 2026-11-19 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« | ni citata o premoru po obroku | #1391 |
| 2026-11-19 | pravilo runbooka | brez poletnega opozorila za sprehod | ni ploskoobrazna pasma | #1391 |
| 2026-11-19 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družinsko življenje«**, **»družino z otroki«**; upoštevajte **»obrambni refleksi — bolje za starejše otroke«**, **»rad pobegne — potrebuje varno, visoko ograjo«**, **»izpada mu dlaka«**, **»močan lovski nagon«** | RKC S956 + PDSA S957 (ura gibanja); PDSA S957 »They love children and are always up for any game on offer, making them excellent family pets.«; WebMD S959 »not recommended for households with small children due to their defensive reflexes«, »Fences should extend 6" belowground …«; RKC »Sheds: Yes«; PetMD S958 »strong prey drive« | #1391 |
| 2026-11-19 | pravilo runbooka »New tag« (Claude) | **nov ključ** `defensive_older_children` (»obrambni refleksi — bolje za starejše otroke«) | `not_for_small_children` pravi »velik pes«, ostali otroški čipi navajajo druge razloge (majhen, krhek, občutljiv, živahen, samosvoj pes) — noben ne ustreza viru WebMD | #1391 |
| 2026-11-19 | pravilo runbooka | **brez** `small_children` (WebMD odsvetuje), `long_daily_exercise` (60 min), `frequent_grooming` (3× na teden, ni vsak dan — kot spinone), `apartment` / `large_home` (RKC »Small house«), `experienced_owner` (močan pes), `needs_company` (ni izjave), `shorter_lifespan` (»Over 12 years«); nikoli »hypoallergenic« | S956, S957, S959 | #1391 |
| 2026-11-19 | pravilo runbooka (§3, dobrobit) | brez opozorilnega čipa (RKC Breed Watch kategorija 1); **v aplikaciji da, v marketingu da** — nikoli kot »hipoalergen«, nikoli z majhnimi otroki | S956; S92 (le ploskoobrazne pasme) | #1391 |
| 2026-11-19 | pravilo runbooka (§3) | barve za AI in izbirnik: **črn z rjavimi oznakami** (privzeto za portret, FCI »for preference«), črno-sivkast z rjavimi oznakami; naravni rep | FCI S955 »Black and tan for preference, or black grizzle and tan …«; RKC S956 »Black & Tan«; uteži brez vira → `verified: false` | #1391 |
| 2026-11-19 | pravilo runbooka (§2.3) | seznam za vzreditelja: genetska raznolikost, DNK test PLL (oboje »minimum«) | RKC S956 »We strongly recommend that breeders, at a minimum, conduct these tests before breeding« | #1391 |
| 2026-11-19 | pravilo runbooka | zdravstveni ključi registra: `primary_lens_luxation`, `glaucoma`, `hip_dysplasia`, `itchy_skin_allergy`, `dry_eye`, `legg_perthes`, `kneecap_luxation`, `cataracts`, `epilepsy`, `addisons_disease`; brez odstotkov, brez novega ključa | PDSA S957, PetMD S958, WebMD S959 | #1391 |
| 2026-11-19 | predlog Claude, David lahko spremeni | EN »Welsh Terrier« (RKC / AKC), SL »valižanski terier«; iskanje tudi »welsh«, »welshie«, »velški terier«, »welsh terier« | strani KZS nisem preveril | #1391 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 3× na teden«, iskanje »welsh«, »valižanski terier« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-127 | 2026-11-19 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 108. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-127 | 2026-11-19 |
| Česanje 3× na teden, »Nova izkušnja« za mladička, ščetkanje zob vsak dan, opomnik za frizerja in kopanje vsakih 8 tednov | 🛠 | 💶 izziv | M5-R13 / R15 / R17 / R19 / R20 | 2026-11-19 |
| Barve (izbirnik: 2 barvi — črn z rjavimi oznakami (portret), črno-sivkast z rjavimi oznakami) | 🛠 | 💶 izziv | M5-R21 | 2026-11-19 |
| AI portret v registru | glejte `BREED_QUEUE.md` (vrstica 127) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/welsh-terrier`, `/sl/zivali/psi/valizanski-terier`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal valižanskega terierja — majhnega, kompaktnega črno-rjavega terierja z žičnato dlako, 123. najbolj priljubljeno pasmo po AKC 2024 in 117. pasmo psa v aplikaciji. 6 novih virov (S954–S959), med njimi strani Royal Kennel Cluba in PDSA. Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do 1 ure gibanja), česanje trikrat na teden in vsakodnevno ščetkanje zob; starši dobijo opomnik za frizerja in kopanje vsakih 8 tednov.
**Zakaj je pomembno:** PDSA pravi, da imajo otroke radi in so odlični družinski psi — WebMD pa jih zaradi obrambnih refleksov ne priporoča za dom z majhnimi otroki, in imajo močan lovski nagon. Obe plati sta v aplikaciji kot oznaki, da se družina odloča poučeno.
**Kako povedati** — starši: »Valižanski terier je majhen, a pravi terier: ura gibanja na dan, trikrat na teden česanje, vsak dan zobje in vsake dva meseca frizer. V PetPrepu boste videli, ali otrok to zmore 12 tednov zapored.« · otroci: »Valižanski terier je igriv črno-rjav terier s kodrasto brado. Z njim greš vsak dan na sprehod, mu ščetkaš zobke in ga češeš trikrat na teden.« · vlagatelji / partnerji: »117. pasma psa, ki jo je nočni zagon sam izbral po javni lestvici AKC in dodal z viri — z novo, natančnejšo oznako o otrocih, kjer obstoječe niso ustrezale viru.«

## Odprto za Davida

- **Standard FCI:** uporabljeno je besedilo FCI-Standard N°78 iz leta 2009 (S955). Stran FCI (S954) navaja novejši veljavni standard (objavljen 8. 9. 2026), ki ga zagon ni prebral — preverite višino / težo.
- **Življenjska doba / starejše obdobje:** spodnja meja RKC »Over 12 years« → starejši od 108. meseca. Vrstice v tabeli Dogs Trust (S123, McMillan 2024) ni bilo mogoče preveriti.
- **Coren:** pasma je v kategoriji »Average«, številka mesta ni zabeležena (zrcala Wikipedije je niso pokazala).
- **Nov ključ oznake** `defensive_older_children` (»obrambni refleksi — bolje za starejše otroke«): potrdite besedilo v EN / SL; prevodi v 13 jezikih spletne strani so Claudovi.
- **Ograja:** čip »rad pobegne — potrebuje varno, visoko ograjo« — vir (WebMD) govori o ograji, ki sega v zemljo (koplje pod njo), ne o visoki ograji.
- **Ime:** »valižanski terier« ni preverjen na straneh KZS.
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
