# Kodrasti bišon (Bichon Frise)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-50 · 2026-10-20

| | |
|---|---|
| Ključ / slug | `bichon_frise` / `bichon-frise` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 460 — v izbirniku za portugalskim vodnim psom |
| Vir podatkov | `docs/research/dog-data/data.json` → `bichon_frise` (viri S409–S418; ponovno S8, S10, S11, S14, S24, S35, S45, S174); preverjanje vzreditelja iz strani RKC (S411) in AKC (K28) |
| Izvor | FCI št. 215 (Bichon à poil frisé), skupina 9 (psi za družbo), sekcija bišonov, Belgija / Francija (S409) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 46. mesto »Bichon Frise« (S174) |
| PR-ji | pet-prep #294 (pasma, združen `6d0b5bd`) · portret #295 (2026-10-20) · spletna stran #81 (2026-10-20) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-20 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **30 min/dan → 3.000 korakov**; starejši 23 min → 2.300; mladiček 10 min × starost do 30 (pri 3 mesecih) | RKC S411 »Exercise: Up to 30 minutes per day« (»do N« → N); PDSA S413 »They need around 30 minutes exercise per day.« da isto število | #294 |
| 2026-10-20 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108** mesecev; prihod 2 / 9 / 36 / 108 — **začasno** | 0,75 × spodnja meja RKC »Lifespan: Over 12 years« (S411; PDSA S413 enako). Mediana McMillan 2024 (12,5 leta) je dosegljiva le prek Wikipedije (S417, raven C) → 113 le kot alternativa; VCA / PetMD 14–15 let → 126; Dogs Trust > 7 let → 84 | #294 |
| 2026-10-20 | pravilo runbooka | učenje **1,0×** (Coren 45. mesto, »povprečni«) | S35 (45. mesto deli s sibirskim haskijem in King Charles španjelom) | #294 |
| 2026-10-20 | pravilo runbooka | teža **4–7 kg**, rast do 8–12 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S413 »4-7 kg« (FCI S410 navaja le »Approximately 5 kg«, standard RKC teže ne navaja); PetMD S10 (razred »Toy«) | #294 |
| 2026-10-20 | pravilo runbooka (M5-R13) | česanje **vsak dan** (zmršena dlaka po 4 zamujenih) | RKC S411 »Grooming: Every day« → 7; PDSA S413, VCA S414 in PetMD S416 se strinjajo; starejša stran VCA (S415) »every other day« le alternativa | #294 |
| 2026-10-20 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S416 »With proper training and socialization from puppyhood, Bichons are friendly dogs that are comfortable meeting new people and animals.« | #294 |
| 2026-10-20 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« po obroku | premora med obrokom in gibanjem ne svetuje noben vir A/B | #294 |
| 2026-10-20 | pravilo runbooka | oznake: življenje v stanovanju (`apartment`), družinsko življenje (`family_pet`), družino z otroki (`children`), dom z drugimi ljubljenčki (`other_pets`), dom, kjer želite manj dlak (`low_shedding`); upoštevajte: česanje večkrat na teden (`frequent_grooming`), rad ima družbo (`needs_company`). Brez nove oznake, **nikoli »hipoalergen«** | RKC S411 »Size of home: Flat/ Apartment«, »Sheds: No«, »Grooming: Every day«; PDSA S413 »… excellent with children and other pets alike.«; PetMD S416 »… a great family pet …«; VCA S414 »Time Alone: Limited tolerance; prefers companionship« | #294 |
| 2026-10-20 | pravilo runbooka | brez oznake za majhne otroke | runbook zahteva izrecno oceno; PetMD S416 »highly suitable for families with younger children« je izjava, ne ocena (odprto za Davida) | #294 |
| 2026-10-20 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot »hipoalergen« ali »primeren za alergike«, nikoli kot igrača ali modni dodatek (predlog Claude) | RKC Breed Watch kategorija 1 (S411, brez točk); ni pes s sploščenim obrazom | #294 |
| 2026-10-20 | pravilo runbooka (§3) | barva za AI: **čisto bela** (edina barva odraslega psa, portret); ohlapni kodri, povešena ušesa, temne okrogle oči s črnimi robovi, rep v loku čez hrbet | FCI S410 »Pure white.«; standard RKC S412 »White, but cream or apricot markings acceptable up to 18 months.« | #294 |
| 2026-10-20 | predlog Claude, David lahko spremeni | ime SL »kodrasti bišon« (slug `kodrasti-bison`); iskalni ključi tudi »bichon«, »bišon«, »bichon tenerife« | slovenski tisk (S418, raven C); KZS ni preverjen | #294 |
| 2026-10-20 | pravilo runbooka (§2.3) | preverjanje vzreditelja: minimum — pregled oči (BVA/KC/ISDS); drugo — ocena pogačice in kolkov (priporočilo ameriškega kluba pasme) | stran RKC (S411); AKC (K28); brez novega ključa testa | #294 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »kodrasti bišon«, »bichon«, »bišon« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-50 | 2026-10-20 |
| Pravila po starosti (2.000 → 3.000 korakov, odrasel 3.000, starejši 2.300 od 108 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-50 | 2026-10-20 |
| Česanje vsak dan (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-20 |
| Barva (izbirnik barve, ena barva standarda — bela) | 🛠 | 💶 izziv | M5-R21 | 2026-10-20 |
| AI portret v registru (sprejet v prvem poskusu, zagon 39453232137; čisto bel, ohlapni kodri, povešena ušesa, rep čez hrbet) | ✅ | — | M5-R11-04 | 2026-10-20 |
| Stran na petprep.si (`/animals/dogs/bichon-frise`, `/sl/zivali/psi/kodrasti-bison`) s portretom | ✅ objavljena kot »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | 2026-10-20 |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni nočni zagon je dodal kodrastega bišona — 46. najbolj priljubljeno pasmo po AKC 2024 in 46. plačljivo pasmo psa. 10 novih virov (S409–S418). Odrasel pes v igri potrebuje 3.000 korakov na dan (The Royal Kennel Club: do 30 minut gibanja), starejši od 9 let 2.300. Česanje vsak dan in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** Majhen, vesel bel pes, ki mu dlaka skoraj ne izpada — zato ga mnogi izberejo kot »psa za alergike«. Viri opozarjajo, da nobena pasma ni povsem brez alergenov, kodrasta dlaka pa potrebuje česanje vsak dan in striženje pri pasjem frizerju. Malo gibanja, a veliko nege in družbe — prav to pokaže 12 tednov PetPrepa.
**Kako povedati** — starši: »Kodrasti bišon potrebuje le pol ure gibanja na dan, a vsak dan česanje, redno striženje in nekoga doma — ne mara biti dolgo sam. V PetPrepu boste videli, ali otrok to zmore 12 tednov zapored.« · otroci: »Bišon je majhen bel kodrast pes, ki se rad igra in crklja. Vsak dan ga počešeš, da se mu kodri ne zmršijo, in ga ne puščaš predolgo samega.« · vlagatelji / partnerji: »Še ena pasma, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili. Staršem svetujemo, naj vzreditelja vprašajo za pregled oči staršev — brez statistik.«

## Odprto za Davida

- **Rešeno 24. 10. 2026 (David, M5-R14 / R15 / R18 / R19 / R20 — PR #PRNUM):** naloga samo, kjer vir navaja pogostost (razpon → spodnja meja); mini-igre otroka po modelu česanja (pošten delež, reže, poročilo, brez kazni), pasji frizer in kopanje = opomnik staršu, ne šteje v oceno; samo novi psi. Za to pasmo — zgrajeno: čiščenje ušes enkrat na 4 tedne (mesec) (mini-igra otroka); pasji frizer na 8 tednov (opomnik staršu, ne šteje v oceno); kopanje enkrat na 4 tedne (mesec) (opomnik staršu, ne šteje v oceno); brez pogostosti v viru → brez naloge (dejstvo ostane v besedilu): čiščenje gub, striženje krempljev, ščetkanje zob. Spodnje točke M5-R14 / R15 / R18 / R19 / R20 so s tem zaprte.

- **Meja starejšega psa 108 je začasna:** spodnja meja RKC (12 let). McMillan 2024 (12,5 leta → 113) le prek Wikipedije; preveriti v tabeli Dogs Trust (S123), ko bo dosegljiva.
- **Potrebe brez mehanike (needs-david → rešeno 24. 10. 2026):** striženje dlake **pri pasjem frizerju** na vsakih nekaj mesecev (PDSA S413; PetMD S416 mesečno) → **M5-R18**; ščetkanje zob (PetMD S416, VCA S414, brez pogostosti) → **M5-R15**; čiščenje ušes enkrat na mesec in kopanje vsaj enkrat na mesec (PetMD S416) → **M5-R20**; striženje krempljev (VCA S414, brez pogostosti) → **M5-R19**; čiščenje madežev od solz okoli oči (VCA S414, brez pogostosti) → **M5-R14**.
- **Majhni otroci:** PetMD (S416) piše »highly suitable for families with younger children«; brez ocene oznake `small_children` nismo dali — ali jo želite?
- **Teža:** v igri razpon PDSA 4–7 kg (FCI navaja le približno 5 kg).
- **Ime:** »kodrasti bišon« ni preverjeno pri KZS (vir le slovenski tisk).
- **Spletna stran:** nova ključa zdravja `diabetes_mellitus` in `dental_disease_home_brushing` (besede Claude).
