# Angleški mastif (Mastiff)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-45 · 2026-10-19

| | |
|---|---|
| Ključ / slug | `mastiff` / `mastiff` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 410 — v izbirniku za dolgodlakim škotskim ovčarjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `mastiff` (viri S370–S376; ponovno S8, S10, S11, S14, S24, S35, S45, S54, S92, S174) |
| Izvor | FCI št. 264, skupina 2, sekcija 2.1 (molosi, tip doge), Velika Britanija (S371, S372) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 40. mesto »Mastiff« (S174) |
| PR-ji | pet-prep #272 (pasma) · portret: čaka na namestitev produkcije · spletna stran #74 |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-19 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | PDSA S370 »Minimum exercise (per day) \| 1 hour« (»najmanj N« → N). Stran RKC (prednostni vir) se ni naložila → PDSA po pravilu runbooka | #272 |
| 2026-10-19 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **81** mesecev; prihod 2 / 9 / 36 / 81 | 0,75 × mediana McMillan 2024 **9,0 let** (popravljena slika 3, S54; besedilo članka S376) — ni začasno. Alternativa: 84 (Dogs Trust > 7 let, S14). Enako kot rotvajler | #272 |
| 2026-10-19 | pravilo runbooka | učenje **0,5×** (Coren rang 72 »Mastiff«, skupina »Lowest«, kot beagle) | S35 | #272 |
| 2026-10-19 | pravilo runbooka | teža 80–85 kg, rast do 18–24 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S370 »Average weight \| 80kg-85kg« (FCI S371 in AKC S373 teže ne navajata; PetMD S374 120–230 lb = alternativa); PetMD S10 (razred »Giant«) | #272 |
| 2026-10-19 | pravilo runbooka (M5-R13) | česanje **1× na teden** (zmršena dlaka po 1 zamujenem) | PDSA S370 »They need grooming just once a week, but their facial wrinkles will need daily cleaning.«; PetMD S374 »Weekly brushing …« | #272 |
| 2026-10-19 | David 2026-10-12 (M5-R16) | opozorilo **»počakaj s sprehodom« 60 min po obroku** (koraki se štejejo, brez kazni) | PetMD S374 »Restrict exercise for an hour before and after mealtimes« (napihnjenost / GDV). Ura *pred* obrokom v igri ni modelirana | #272 |
| 2026-10-19 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S370 »As with all breeds, early socialisation is important to ensure they grow up into confident, sociable dogs.« | #272 |
| 2026-10-19 | pravilo runbooka | oznake: družinsko življenje (`family_pet`), družina z otroki (`children` — z opombo: otroci, ki znajo ravnati z živalmi), dom z drugimi ljubljenčki (`other_pets` — če se spoznajo kot mladiči); upoštevajte: ni za dom z majhnimi otroki (`not_for_small_children`), krajša življenjska doba (`shorter_lifespan`), izpada mu dlaka (`sheds`), se slini (`drools`). Brez nove oznake | PetMD S374 »Yes, Mastiffs are great family dogs, and their calm nature is suitable for homes with children«, »they may be best suited in homes without toddlers or babies«, »tend to do well with other pets, especially when introduced during puppyhood«, »moderately shedding coats«, »known to be excessive droolers«; PDSA S370 »Average lifespan \| Under 10 years« | #272 |
| 2026-10-19 | pravilo runbooka (§3, dobrobit) | oznake dobrobiti: **veke in oči** (`eyelid_problems`), **občutljiva koža** (`sensitive_skin`), **kolki in zadnje noge** (`hips_hind_legs`) | PDSA S370: »classed as ‘Category Three’ by The Kennel Club« (vidne težave zaradi pretiravanj); »Eyelid problems … due to excessive skin over the face and eyes«, atopija, displazija kolkov in komolcev. Točke Breed Watch (ohlapna koža obraza z napakami vek, dermatitis v kožnih gubah, šibek zadek) le prek kopije pasemskega kluba (S375, stopnja C, ob PDSA) — RKC-jeva stran se ni naložila | #272 |
| 2026-10-19 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli z malčki ali dojenčki, nikoli s pretiranimi gubami ali povešenimi vekami | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92); noben vir A/B mastifa ne imenuje tako | #272 |
| 2026-10-19 | pravilo runbooka (§3) | barve za AI in izbirnik: **plava** (portret), **marelična**, **tigrasta** — vedno s temno masko, ušesi in nosom; zmerna koža (lastnost `skin`), tesne veke, odprte nosnice (`muzzle`); nikoli preveč bele | FCI S371 »Apricot, fawn or brindle.«; AKC S373 »Muzzle, ears, and nose must be dark in color«; FCI »Excessive white on body, chest or feet is unacceptable.« | #272 |
| 2026-10-19 | predlog Claude, David lahko spremeni | ime SL »angleški mastif« (slug `angleski-mastif`); iskalni ključi tudi »mastif«, »english mastiff«, »old english mastiff« | raba v slovenščini; KZS ni preverjen | #272 |
| 2026-10-19 | pravilo runbooka (§2.3) | seznam za vzreditelja: `not_found` (le splošni seznam za psa) | stran RKC z zdravstvenimi testi se ni naložila; PDSA le splošno svetuje preverjanje staršev po shemah Canine Health Schemes | #272 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »angleški mastif«, »mastif«, »mastiff« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-45 | 2026-10-19 |
| Pravila po starosti (2.000 → 6.000 korakov, odrasel 6.000, starejši 4.500 od 81 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-45 | 2026-10-19 |
| Česanje 1× na teden (rutina »Počeši«), opozorilo »počakaj s sprehodom« po obroku, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R16 / M5-R17 | 2026-10-19 |
| Barva (izbirnik barve: plava, marelična, tigrasta) | 🛠 | 💶 izziv | M5-R21 | 2026-10-19 |
| AI portret v registru | 🗓 čaka na naslednjo ročno namestitev produkcije (D18) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/mastiff`, `/sl/zivali/psi/angleski-mastif`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal angleškega mastifa — 40. najbolj priljubljeno pasmo po AKC 2024 in 41. plačljivo pasmo psa. 7 novih virov (S370–S376). Odrasel pes v igri potrebuje 6.000 korakov na dan (PDSA: najmanj ura gibanja), starejši od 6¾ leta 4.500; uči se počasneje kot mešanček (Coren: 72. mesto). Česanje enkrat na teden, opozorilo, naj po obroku počaka s sprehodom, in socializacija mladička so del izziva od prvega dne.
**Zakaj je pomembno:** Mastif je miren »nežni velikan«, ki ga marsikdo vidi kot lahkega družinskega psa. Viri povedo tudi drugo plat: tehta okoli 80 kg, živi praviloma manj kot 10 let, slini se, gube na obrazu je treba čistiti vsak dan, po obroku ne sme takoj na sprehod (nevarnost zasuka želodca), The Kennel Club pa ga uvršča med pasme, pri katerih pretiran videz povzroča zdravstvene težave. Prav to pokaže 12 tednov PetPrepa.
**Kako povedati** — starši: »Angleški mastif je miren in prijazen, a ogromen pes: ni za dom z malčki, potrebuje uro gibanja, čiščenje gub in previdnost po obroku. V PetPrepu boste videli, ali otrok to zmore 12 tednov zapored.« · otroci: »Mastif je eden največjih psov na svetu — miren velikan, ki se rad slini. Vsak dan ga pelješ na sprehod, a po obroku najprej malo počakaš.« · vlagatelji / partnerji: »Sedemindvajseta pasma, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal (vseh plačljivih psov je zdaj 41) — z viri, testi in pravili; tudi ko glavni vir (RKC) ni bil dosegljiv, je zagon po pravilih uporabil naslednji vir in odprta vprašanja zapisal za Davida.«

## Odprto za Davida

- **Potrebe brez mehanike (needs-david):** vsakodnevno čiščenje gub na obrazu in redno čiščenje oči (PDSA S370, PetMD S374) → **M5-R14**; čiščenje ušes po vodi in kopanje na nekaj mesecev (PetMD S374) → **M5-R20**.
- **RKC ni bil dosegljiv** (vsi naslovi — stran pasme, standard, Breed Watch — so vrnili napako): manjkajo polja RKC (velikost doma in vrta, česanje, linjenje, razpon življenjske dobe) in zdravstveni testi za vzreditelja (`not_found`). Velikost je zato PDSA »Large« (doga je pri RKC »Extra large«), oznaka `large_home` ni dodana. Ob naslednjem zagonu, ko bo RKC dosegljiv, preveriti.
- **Breed Watch kategorija 3** — točke so iz kopije pasemskega kluba (S375, stopnja C), ob njih PDSA. Pasma ostaja v marketingu (ni veterinarskega odsvetovanja); ali jo David kljub kategoriji 3 želi v marketingu?
- **Kratek gobec:** standard pravi »Muzzle short«; poljudni članek (Science News, stopnja C) mastife omenja med psi s »sploščenim nosom«. Noben vir A/B mastifa ne imenuje brahicefalnega (razred lobanje v McMillan 2024, tabela S5, ni bil dosegljiv) → brez oznake za dihanje. Preveriti.
- **Ime:** »angleški mastif« ni preverjeno pri KZS.
- **Spletna stran:** nov ključ zdravja `skin_allergy_face_folds` (besede Claude).
- **Portret:** naredi ga prvi samodejni zagon po naslednji ročni namestitvi produkcije (D18).
