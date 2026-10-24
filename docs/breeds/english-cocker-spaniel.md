# Angleški koker španjel (English Cocker Spaniel)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-41 · 2026-10-15

| | |
|---|---|
| Ključ / slug | `english_cocker_spaniel` / `english-cocker-spaniel` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 370 — v izbirniku za madžarskim kratkodlakim ptičarjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `english_cocker_spaniel` (viri S335–S344; ponovno S8, S10, S11, S14, S24, S35, S45, S174) |
| Izvor | FCI št. 5, skupina 8, sekcija 2 (psi za vzganjanje divjadi), Velika Britanija (S335) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 36. mesto »English Cocker Spaniel« (S174) |
| PR-ji | pet-prep #260 (pasma) · portret #265 (2026-10-15) · spletna stran #68 |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-15 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S337 »Exercise: Up to 1 hour per day« (»do N« → N); PDSA S339 »a minimum of an hour exercise every day« da isto število | #260 |
| 2026-10-15 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **103** meseci; prihod 2 / 9 / 36 / 103 | 0,75 × mediana starosti ob smrti **11,44 leta** (VetCompass, Engdahl in sod. 2023, S342 »The median age at death was 11.44 years (IQR 9.46–13.47)«); McMillan 2024 ni bil dosegljiv → pravilo »sicer mediana VetCompass« (kot bigel). Alternative: 90 (RKC »Over 10 years«), 108 (VCA 12–14 let) | #260 |
| 2026-10-15 | pravilo runbooka | učenje **1,5×** (Coren rang 18, skupina »Excellent«) | S35 | #260 |
| 2026-10-15 | pravilo runbooka | teža 13–14,5 kg (za oba spola), rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S339 »13-14.5 kg«; PetMD S10 (razred »Medium«) | #260 |
| 2026-10-15 | pravilo runbooka (M5-R13) | česanje **vsak dan** (7× na teden; zmršena dlaka po 4 zamujenih) | RKC S337 »Grooming: Every day«; PDSA S339 »They need a daily brush …« | #260 |
| 2026-10-15 | David 2026-10-12 (M5-R16) | opozorilo »počakaj s sprehodom« 60 minut po obroku | PDSA S339 »Remember to leave at a gap after eating and before exercising.« (tak zapis ima PDSA tudi pri dobermanu in nemški dogi) | #260 |
| 2026-10-15 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S339 »Socialising your Cocker Spaniel from a young age with a variety of people, dogs and experiences will help them develop« | #260 |
| 2026-10-15 | pravilo runbooka | oznake: primerno za družinsko življenje (`family_pet`), družino z otroki (`children` — z nasvetom PDSA o nadzoru) in dom z drugimi ljubljenčki (`other_pets`); upoštevajte: česanje večkrat na teden (`frequent_grooming`), rad je — pazite na težo (`food_motivated_weight`), ko se dolgočasi, grize stvari (`chews_when_bored`), rad ima družbo (`needs_company`), izpada mu dlaka (`sheds`). Brez nove oznake | RKC S337, PetMD S340, PDSA S339, VCA S341 (citati v `data.json proposed_game_parameters.english_cocker_spaniel.suitability_tags`); brez `active_family` (do 1 ure), brez `chases_small_pets` (PDSA: nima močnega lovskega nagona), brez `apartment` / `large_home` (RKC »Small house«) | #260 |
| 2026-10-15 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot pes brez nege, s kupiranim repom kot ciljem ali zamenjan z ameriškim kokerjem (predlog Claude) | RKC Breed Watch kategorija 1 (S337, brez točk); ni pes s sploščenim obrazom | #260 |
| 2026-10-15 | pravilo runbooka (§3) | barve za AI: **zlat** (portret), črn, čokoladno rjav, črn z rjavimi oznakami, modro mešan, oranžno mešan, črno-bel, trobarven; naraven nekupiran rep; nikoli merle; lastnost `muzzle` | AKC S343 »Solid colors are black, liver or shades of red.« + večbarvni / mešani z belo; FCI S336 | #260 |
| 2026-10-15 | predlog Claude, David lahko spremeni | ime SL »angleški koker španjel«; goli iskalni ključ »cocker spaniel« / »koker španjel« in sinonim registra »Cocker Spaniel« kažeta na angleško pasmo (raba RKC / FCI / Slovenija; v ZDA je »Cocker Spaniel« ameriški — »american cocker« ostane samo ameriški) | KZS ni preverjen | #260 |
| 2026-10-15 | pravilo runbooka (§2.3) | testi za vzreditelja po RKC: genetska raznolikost, DNK AMS, DNK prcd-PRA, slikanje kolkov (minimum); DNK AON, DNK FN, pregled oči z gonioskopijo (najboljša praksa); nova ključa `dna_aon`, `dna_fn` | RKC S337 | #260 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »angleški koker«, »english cocker«, »koker španjel« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-41 | 2026-10-15 |
| Pravila po starosti (2.000 → 6.000 korakov, odrasel 6.000, starejši 4.500 od 103 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-41 | 2026-10-15 |
| Česanje vsak dan (rutina »Počeši«), opozorilo po obroku, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R16 / M5-R17 | 2026-10-15 |
| Barve (izbirnik barve, 8 barv standarda) | 🛠 | 💶 izziv | M5-R21 | 2026-10-15 |
| AI portret v registru (zlat, naraven rep; sprejet v prvem poskusu) | ✅ | — | M5-R11-04 | 2026-10-15 |
| Stran na petprep.si (`/animals/dogs/english-cocker-spaniel`, `/sl/zivali/psi/angleski-koker-spanjel`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal angleškega koker španjela — 36. najbolj priljubljeno pasmo po AKC 2024 in 37. plačljivo pasmo psa. 10 novih virov (S335–S344). Odrasel koker v igri potrebuje 6.000 korakov na dan (The Royal Kennel Club: do ene ure gibanja), starejši od 8,6 leta 4.500; uči se hitreje kot mešanček (Coren: 18. mesto). Meja starejšega psa izhaja iz britanske raziskave VetCompass (srednja starost ob smrti 11,44 leta). Česanje vsak dan, opozorilo »počakaj s sprehodom« po obroku in socializacija mladička so del izziva od prvega dne.
**Zakaj je pomembno:** Koker velja za veselega družinskega psa, a dlako je treba česati vsak dan, rad je (in se zredi) in težko ostaja sam — PDSA svetuje, naj nikoli ne bo sam več kot štiri ure. Prav tega otrok na fotografiji ne vidi, v 12 tednih PetPrepa pa to začuti.
**Kako povedati** — starši: »Koker je prijazen in potrpežljiv z otroki, a ga je treba česati vsak dan in ne sme biti sam ves dan. V PetPrepu boste videli, ali otrok zmore vsakodnevno nego 12 tednov zapored.« · otroci: »Koker je zlat (ali črn ali pikast!), z dolgimi mehkimi ušesi. Vsak dan ga počešeš, po obroku malo počakaš s sprehodom — in pazi, da ne dobi preveč priboljškov!« · vlagatelji / partnerji: »Triindvajseta pasma, ki jo je sistem sam izbral po javni lestvici AKC — z viri, testi in pregledom; meja starosti iz recenzirane raziskave VetCompass. Staršem svetujemo, naj vzreditelja vprašajo za DNK teste (prcd-PRA, AMS, AON, FN), slikanje kolkov in pregled oči staršev — brez statistik.«

## Odprto za Davida

- **Rešeno 24. 10. 2026 (David, M5-R14 / R15 / R18 / R19 / R20 — PR #376):** naloga samo, kjer vir navaja pogostost (razpon → spodnja meja); mini-igre otroka po modelu česanja (pošten delež, reže, poročilo, brez kazni), pasji frizer in kopanje = opomnik staršu, ne šteje v oceno; samo novi psi. Za to pasmo — zgrajeno: pasji frizer na 12 tednov (3 mesece) (opomnik staršu, ne šteje v oceno); brez pogostosti v viru → brez naloge (dejstvo ostane v besedilu): čiščenje ušes, striženje krempljev. Spodnje točke M5-R14 / R15 / R18 / R19 / R20 so s tem zaprte.

- **Agresivnost (VetCompass):** novica RVC (S344) med najpogosteje zabeleženimi težavami pasme navaja tudi agresivnost (»potentially moody«). Zabeleženo v `data.json` (`behaviour.aggression_vetcompass`) brez oznake (ni ključa v besednjaku), oznaka »družino z otroki« ostane (PDSA / VCA, z nasvetom o nadzoru). Ali naj dobi oznako? (ugotovitev pregleda QA)
- **Zobje:** VetCompass navaja parodontalno bolezen kot najpogostejšo težavo, a noben vir A/B ne daje nasveta za nego zob → brez potrebe po ščetkanju (M5-R15 ni povezan).
- **Potrebe brez mehanike (needs-david → rešeno 24. 10. 2026):** pasji frizer za striženje približno vsake tri mesece pri razstavnem tipu (PDSA S339) → **M5-R18**; redno striženje krempljev (VCA S341) → **M5-R19**; redno čiščenje ušes (PetMD S340, VCA S341) → **M5-R20**.
- **Iskanje:** goli »Cocker Spaniel« / »koker španjel« vodi k angleški pasmi (glej Odločitve).
- **Ime:** »angleški koker španjel« ni preverjeno pri KZS.
- **Spletna stran:** novi besedili za ključ zdravja `familial_nephropathy` in testa `dna_aon` / `dna_fn` (besede Claude).
- **Višina po FCI** (39–41 / 38–39 cm) je povzetek francoskega standarda (brez dobesednega citata); v registru je poleg višina AKC.
- **Portret:** naredi ga prvi samodejni zagon po naslednji ročni namestitvi produkcije (D18).
