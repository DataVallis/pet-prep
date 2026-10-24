# Portugalski vodni pes (Portuguese Water Dog)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-49 · 2026-10-20

| | |
|---|---|
| Ključ / slug | `portuguese_water_dog` / `portuguese-water-dog` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 450 — v izbirniku za papijonom |
| Vir podatkov | `docs/research/dog-data/data.json` → `portuguese_water_dog` (viri S402–S408; ponovno S8, S10, S11, S14, S24, S35, S45, S174); preverjanje vzreditelja iz strani RKC (S404) |
| Izvor | FCI št. 37 (Cão de Água Português), skupina 8 (prinašalci, šarivci, vodni psi), sekcija vodnih psov, Portugalska (S402) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 45. mesto »Portuguese Water Dog« (S174) |
| PR-ji | pet-prep #291 (pasma, združen `17afff8`) · portret #292 (2026-10-20) · spletna stran #79 (2026-10-20) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-20 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S404 »Exercise: Up to 1 hour per day« (»do N« → N); PDSA S405 »Around an hour of exercise is needed daily.« in VCA S406 »at least an hour a day« dajo isto število | #291 |
| 2026-10-20 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90** mesecev; prihod 2 / 9 / 36 / 90 — **začasno** | 0,75 × spodnja meja RKC »Lifespan: Over 10 years« (S404; PDSA S405 enako, VCA S406 10–14 let). Mediane McMillan 2024 / VetCompass za pasmo nismo našli (Wikipedija S408 je ne navaja, tabela Dogs Trust S123 ni bila dosegljiva). Alternativa: 84 (Dogs Trust > 7 let) | #291 |
| 2026-10-20 | pravilo runbooka | učenje **1,0×** (Coren pasme ni uvrstil → kot mešanček) | S35 (Havanese / šiba inu precedens) | #291 |
| 2026-10-20 | pravilo runbooka | teža 16–25 kg (psi 19–25, psice 16–22), rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | standard RKC S403 (standard FCI v PDF ni bil dosegljiv); PDSA S405 »16-25 kg«; PetMD S10 (razred »Medium«) | #291 |
| 2026-10-20 | pravilo runbooka (M5-R13) | česanje **vsak dan** (zmršena dlaka po 4 zamujenih) | RKC S404 »Grooming: Every day« → 7; PDSA S405 »Grooming is a daily requirement …«; VCA S406 »at least twice a week (preferably more)« in PetMD S407 »a few times a week« samo alternativi | #291 |
| 2026-10-20 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S405 »Training, along with socialisation, is very important at a young age in order for Portuguese Water Dogs to grow up into confident, sociable dogs.« | #291 |
| 2026-10-20 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« po obroku | VCA S406 napihnjenost (bloat) le našteje med boleznimi, premora med obrokom in gibanjem ne svetuje noben vir A/B | #291 |
| 2026-10-20 | pravilo runbooka | oznake: družinsko življenje (`family_pet`), družino z otroki (`children`), veliko hišo z vrtom (`large_home`), dom z drugimi ljubljenčki (`other_pets`), dom, kjer želite manj dlak (`low_shedding`); upoštevajte: česanje večkrat na teden (`frequent_grooming`). Brez nove oznake, **nikoli »hipoalergen«** | PDSA S405 »… get on well with other pets and make excellent family pets.«; PetMD S407 »… can be around children and other animals.«; RKC S404 »Size of home: Large house«, »Sheds: No«, »Grooming: Every day« | #291 |
| 2026-10-20 | pravilo runbooka | oznaka za otroke **z opozorilom v virih**, brez oznake za majhne otroke | VCA S406 v hitrih dejstvih »Fine with older kids« (zabeleženo v `behaviour.children`) | #291 |
| 2026-10-20 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot »hipoalergen« ali »primeren za alergike« in nikoli kot pes z malo dela (česanje vsak dan + pasji frizer) (predlog Claude) | RKC Breed Watch kategorija 1 (S404, brez točk); ni pes s sploščenim obrazom | #291 |
| 2026-10-20 | pravilo runbooka (§3) | barve za AI: **črna** (portret, najvišja utež), črna z belim, rjava, rjava z belim, bela; tradicionalno »levje« striženje po standardu (gobec in zadnji del ostrižena, čop na koncu repa); povešena srčasta ušesa | standard RKC S403 »Black, white, various shades of brown, black and white, brown and white.« / »Any other colour or combination of colours unacceptable.« (uteži so naše, `verified => false`) | #291 |
| 2026-10-20 | predlog Claude, David lahko spremeni | ime SL »portugalski vodni pes« (slug `portugalski-vodni-pes`); iskalni ključi tudi »portie«, »pwd«, »cão de água« | raba v slovenskih virih; KZS ni preverjen | #291 |
| 2026-10-20 | pravilo runbooka (§2.3) | preverjanje vzreditelja: minimum — genska pestrost, DNA-test prcd-PRA; najboljša praksa — rentgen komolcev in kolkov (BVA/KC), DNA-test GM1 (gangliozidoza), DNA-test EOPRA (zgodnja progresivna atrofija mrežnice) | stran RKC (S404); nov ključ testa `dna_eopra` | #291 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »portugalski vodni pes«, »vodni pes«, »portie« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-49 | 2026-10-20 |
| Pravila po starosti (2.000 → 6.000 korakov, odrasel 6.000, starejši 4.500 od 90 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-49 | 2026-10-20 |
| Česanje vsak dan (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-20 |
| Barva (izbirnik barve, pet barv standarda) | 🛠 | 💶 izziv | M5-R21 | 2026-10-20 |
| AI portret v registru (sprejet v prvem poskusu; črn, levje striženje s čopom na repu, povešena ušesa) | ✅ | — | M5-R11-04 | 2026-10-20 |
| Stran na petprep.si (`/animals/dogs/portuguese-water-dog`, `/sl/zivali/psi/portugalski-vodni-pes`) s portretom | ✅ objavljena kot »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | 2026-10-20 |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni nočni zagon je dodal portugalskega vodnega psa — 45. najbolj priljubljeno pasmo po AKC 2024 in 45. plačljivo pasmo psa. 7 novih virov (S402–S408). Odrasel pes v igri potrebuje 6.000 korakov na dan (The Royal Kennel Club: do 1 ure gibanja), starejši od 7,5 leta 4.500. Česanje vsak dan in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** Ker mu dlaka skoraj ne izpada, ga mnogi iščejo kot »psa za alergike«. Viri pravijo drugače: dokazov za hipoalergene pasme ni, dlako pa je treba česati vsak dan in jo večkrat na leto dati ostriči pasjemu frizerju. Je energičen delovni pes, ki ga veseli voda. Prav to pokaže 12 tednov PetPrepa.
**Kako povedati** — starši: »Portugalskemu vodnemu psu dlaka skoraj ne izpada, a to ni manj dela: česanje vsak dan, frizer večkrat na leto in uro gibanja na dan. V PetPrepu boste videli, ali otrok to zmore 12 tednov zapored.« · otroci: »Portugalski vodni pes je bil nekoč ribiški pomočnik in rad plava. Vsak dan ga pelješ na sprehod in ga počešeš, da se mu kodri ne zmršijo.« · vlagatelji / partnerji: »Še ena pasma, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili. Staršem svetujemo, naj vzreditelja vprašajo za DNA-teste in rentgen staršev — brez statistik.«

## Odprto za Davida

- **Rešeno 24. 10. 2026 (David, M5-R14 / R15 / R18 / R19 / R20 — PR #376):** naloga samo, kjer vir navaja pogostost (razpon → spodnja meja); mini-igre otroka po modelu česanja (pošten delež, reže, poročilo, brez kazni), pasji frizer in kopanje = opomnik staršu, ne šteje v oceno; samo novi psi. Za to pasmo — zgrajeno: ščetkanje zob vsak dan (mini-igra otroka); pasji frizer enkrat na 4 tedne (mesec) (opomnik staršu, ne šteje v oceno); brez pogostosti v viru → brez naloge (dejstvo ostane v besedilu): kopanje, čiščenje ušes, striženje krempljev. Spodnje točke M5-R14 / R15 / R18 / R19 / R20 so s tem zaprte.

- **Meja starejšega psa 90 je začasna:** spodnja meja RKC (10 let). Mediane McMillan 2024 / VetCompass nismo našli; preveriti v tabeli Dogs Trust (S123), ko bo dosegljiva.
- **Potrebe brez mehanike (needs-david → rešeno 24. 10. 2026):** striženje in oblikovanje dlake **pri pasjem frizerju** večkrat na leto (PDSA S405; PetMD S407 na 4–6 tednov) → **M5-R18**; ščetkanje zob **vsak dan** (PetMD S407) → **M5-R15**; čiščenje ušes in kopanje, ko se zmoči ali umaže (PetMD S407) → **M5-R20**; striženje krempljev s pazljivostjo na kožico med prsti (VCA S406, brez pogostosti) → **M5-R19**.
- **Otroci:** oznaka `children` temelji na PetMD (S407); VCA (S406) piše »Fine with older kids«. Oznake `older_children` nismo dali (njeno besedilo velja za majhnega psa) — ali jo želite?
- **Velik dom:** `large_home` (»veliko hišo z vrtom«) po pravilu iz RKC »Large house«, čeprav RKC za vrt navaja »Small/ medium garden« (kot pri basetu).
- **Velikost:** RKC »Medium«, PDSA »Large« — v registru »medium« (pravilo: polje RKC).
- **Portret:** levje striženje po standardu RKC (S403); pri hišnih ljubljenčkih je pogosto tudi enakomerno »prinašalsko« striženje, ki ga standard ne opisuje.
- **Ime:** »portugalski vodni pes« ni preverjeno pri KZS.
- **Spletna stran:** nov izvor FCI »Portugalska« (`PT`), nova ključa zdravja `gm1_gangliosidosis` in `addisons_disease`, nov ključ testa `dna_eopra` (besede Claude).
