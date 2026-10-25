# Bernardinec (Saint Bernard)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-66 · 2026-10-25

| | |
|---|---|
| Ključ / slug | `saint_bernard` / `saint-bernard` (SL `bernardinec`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 620 — v izbirniku za nemškim ostrodlakim ptičarjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `saint_bernard` (viri S527–S535; ponovno S8, S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja iz S529 |
| Izvor | FCI št. 61 »St. Bernard«, skupina 2, sekcija 2.2 (molosi, gorski tip), Švica (S527) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 62. mesto »Saint Bernard« (S174, prebrano 2026-10-25) |
| PR-ji | pet-prep #389 (pasma) · portret: PR po združitvi (veja `feat/M5-R10-66-saint-bernard-portrait`) · spletna stran: pet-prep-website, veja `feat/register-saint-bernard` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-25 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S529 »Exercise: Up to 1 hour per day« (»up to N« → N); PDSA S532 »at least an hour of exercise every day« se ujema; PetMD S533 »at least one 30-minute walk a day« (alternativa) | #389 |
| 2026-10-25 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36 mesecev; prihod 2 / 9 / 36 | AAHA S11 (kot pri vseh psih) | #389 |
| 2026-10-25 | **predlog Claude — čaka Davida** (`verified=false`) | starejši od **63. meseca** (tudi prihod starejšega psa) | 0,75 × mediana 7,0 leta iz ankete Kennel Club / BSAVA 2004 (Adams in sod. 2010, J Small Anim Pract, tabela 3 — S534, prebrano prek ResearchGate, številke brez decimalnih vejic). Pravilo runbooka ne da vrednosti: tabele McMillan 2024 (Dogs Trust S123) zagon ni mogel odpreti, mediane VetCompass ni, RKC »Under 10 years« nima spodnje meje → najbližja vrstica (precedens bernski planšar, bloodhound). Alternative: 84 (McMillan 2024 »9.3 years«, le prek Wikipedije S535, tier C; enako Dogs Trust > 7 let, S14), 72 (PetMD 8–10 let) | #389 |
| 2026-10-25 | pravilo runbooka | učenje **0,7×** (Coren rang 65, skupina »Fair«, deljen s škotskim terierjem) | S35 | #389 |
| 2026-10-25 | pravilo runbooka | teža 54–81 kg (oba spola), višina psi 70–90 cm, psice 65–80 cm, rast do 18–24 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S532 »54kg-81kg« (standarda FCI in RKC teže ne navajata); FCI št. 61 (S528) najmanjša in največja višina; PetMD S10 (razred »Giant«) | #389 |
| 2026-10-25 | pravilo runbooka (M5-R13) | česanje **3× na teden** | RKC S529 »Grooming: More than once a week« brez števila → PDSA S532 »Brushing them at least three times a week …« → 3; PetMD S533 »once or twice weekly« (alternativa) | #389 |
| 2026-10-25 | David 2026-10-12 (M5-R16) | **opozorilo po obroku** (60 minut »počakaj s sprehodom«, koraki se štejejo) | PDSA S532 »Remember to leave a gap after eating and before exercising.«; PetMD S533 »Avoid exercise one hour before and one hour after a meal« | #389 |
| 2026-10-25 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S532 »It’s important that Saint Bernards are well socialised as puppies so they grow into confident and happy adults.« | #389 |
| 2026-10-25 | pravilo runbooka (M5-R20, David 2026-10-24) | **čiščenje ušes na 2 tedna** (otrokova mini-igra) | PetMD S533 »Cleaning their ears with a veterinarian-recommended ear cleaner every two to three weeks« → razpon, spodnja meja | #389 |
| 2026-10-25 | pravilo runbooka (»Breed care tasks«) | **brez** naloge za oči / kožne gube, kremplje, zobe, kopanje, frizerja | PDSA S532 oči in gube »regularly«, PetMD S533 kremplji »regular«, kopanje »occasional« — brez pogostosti → brez naloge (dejstvo v besedilu pasme) | #389 |
| 2026-10-25 | pravilo runbooka | oznake: družinsko življenje (`family_pet`), družino z otroki (`children` — z opozorilom: nadzor ob manjših otrocih), veliko hišo z vrtom (`large_home`); upoštevajte: krajša življenjska doba (`shorter_lifespan`), veke in oči (`eyelid_problems`), kolki in zadnje noge (`hips_hind_legs`), slabo prenaša vročino (`heat_sensitive`), rad ima družbo (`needs_company`), izpada mu dlaka (`sheds`), česanje večkrat na teden (`frequent_grooming`), se slini (`drools`) | PDSA S532 »They love nothing more than being with a family which is why so many people choose them as family pets.«, »Saint Bernards are known for being great with people of all ages – which includes children.«, »… Saint Bernards don’t do as well in the heat.«, »Saint Bernards are also known for their drooling.«; RKC S529 »Size of home: Large house«, »Lifespan: Under 10 years«, »Sheds: Yes«, »Grooming: More than once a week«; PetMD S533 »… can develop separation anxiety …« | #389 |
| 2026-10-25 | pravilo runbooka | **brez** oznak `active_family`, `long_daily_exercise`, `small_children`, `not_for_small_children`, `other_pets`, `chases_small_pets`, `apartment`, `experienced_owner`, `sensitive_skin`; brez nove oznake | 60 min na dan; PDSA svetuje nadzor ob manjših otrocih (ne odsvetuje); PetMD »low prey drive« proti PDSA »take care introducing them to cats and small pets«; kožne težave le na seznamu bolezni, ne med točkami Breed Watch | #389 |
| 2026-10-25 | pravilo runbooka (§3, dobrobit) | oznaki dobrobiti **veke in oči** (`eyelid_problems`) in **kolki in zadnje noge** (`hips_hind_legs`) | RKC Breed Watch kategorija 3 (S529, S531): »Eyelids not in normal contact with the eyeball, they are turned inwards or outwards«, »Incorrect hind conformation and/or poor rear movement«; PDSA S532 »Category Three« — kot pri angleškem mastifu | #389 |
| 2026-10-25 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli s povešenimi vekami, težko ohlapno kožo ali prekomerno težo, nikoli z nenadzorovanimi majhnimi otroki, nikoli s sodčkom okoli vratu, nikoli z obljubo o zdravju | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #389 |
| 2026-10-25 | pravilo runbooka (§3) | barve za AI in izbirnik: **rdeče-bel z rdečim plaščem in temno masko** (portret), bel z rdečimi lisami, rdeče tigrasto-bel; kratkodlaka različica; nikoli enobarvno bel ali rdeč, črn, lisast (merle) ali trobarven; tesne, zdrave veke, zmerne ustnice | RKC standard S530 »Orange, mahogany-brindle, red-brindle, white with patches on body …«; FCI S528. Uteži barv brez vira → `verified: false`; rdeči plašč za portret je predlog Claude (najbolj značilna barva po FCI) | #389 |
| 2026-10-25 | pravilo runbooka (§2.3) | seznam za vzreditelja — minimum: komolci (BVA/KC), oči (BVA/KC/ISDS), kolki (BVA/KC). Brez novega ključa | RKC S529 »We strongly recommend that breeders, at a minimum, conduct these tests before breeding« | #389 |
| 2026-10-25 | pravilo runbooka | zdravstveni ključi registra (obstoječi): `hip_elbow_dysplasia`, `bloat_gdv`, `eyelids_turned`, `heart_muscle_disease`, `certain_cancers`; brez odstotkov | PDSA S532, RKC Breed Watch S531 | #389 |
| 2026-10-25 | predlog Claude, David lahko spremeni | ime SL »Bernardinec« (slug `bernardinec`); iskalni ključi tudi »st. bernard«, »bernhardiner«, »sveti bernard«, »pes svetega bernarda« | slovenskega vira (KZS) za ime zagon ni bral | #389 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 3× na teden«, iskanje »bernardinec«, »saint bernard«, »bernhardiner« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-66 | 2026-10-25 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 63. meseca — predlog; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-66 | 2026-10-25 |
| Česanje 3× na teden, »Nova izkušnja« za mladička, opozorilo »počakaj s sprehodom« po obroku, čiščenje ušes na 2 tedna | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 / M5-R20 | 2026-10-25 |
| Barva (izbirnik barve: rdeče-bel, bel z rdečimi lisami, rdeče tigrasto-bel) | 🛠 | 💶 izziv | M5-R21 | 2026-10-25 |
| AI portret v registru | 🗓 po združitvi (zagon `breed-portraits.yml`) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/saint-bernard`, `/sl/zivali/psi/bernardinec`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije (le s portretom) | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal bernardinca — 62. najbolj priljubljeno pasmo po AKC 2024 in 62. plačljivo pasmo psa. 9 novih virov (S527–S535). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC in PDSA: do ene ure gibanja), starejši 4.500; uči se nekoliko počasneje od mešanca (Coren: 65. mesto, ×0,7). Česanje trikrat na teden, socializacija mladička, opozorilo »počakaj s sprehodom« po obroku (nevarnost zasuka želodca) in čiščenje ušes vsaka dva tedna so del izziva od prvega dne.
**Zakaj je pomembno:** Bernardinec je nežen velikan in priljubljen družinski pes, a tehta 54–81 kg, se slini, veliko izpada, ne prenaša vročine in živi manj kot deset let. Royal Kennel Club ga uvršča v Breed Watch kategorijo 3 (veke, zadnje noge). Ni pes za dolge teke — je pa pes za veliko hišo, vsakodnevno nego in družino, ki je veliko doma. PetPrep pokaže, ali družina to zmore 12 tednov.
**Kako povedati** — starši: »Bernardinec potrebuje eno uro gibanja, česanje trikrat na teden, počitek po obroku in veliko prostora. V PetPrepu boste videli, ali družina vsak dan poskrbi tudi za ušesa in dlako.« · otroci: »Bernardinec je velik in prijazen. Z njim greš vsak dan na sprehod, ga večkrat na teden počešeš — po kosilu pa najprej malo počakaš.« · vlagatelji / partnerji: »Dvainšestdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Meja starejšega psa (63 mesecev, `verified=false`):** predlog iz mediane ankete Kennel Club / BSAVA 2004 (7,0 leta, Adams 2010, S534 — vrstica tabele brez decimalnih vejic, branje Claude). Če potrdite McMillan 2024 (9,3 leta, zdaj le prek Wikipedije), bi bil starejši od 84 mesecev (podatkovna migracija ali urejanje v Filamentu).
- **Česanje:** PDSA »vsaj trikrat na teden« (3, zgrajeno) proti PetMD »enkrat ali dvakrat na teden« (dnevno med menjavo dlake) — potrdite 3.
- **Breed Watch kategorija 3:** oznaki `eyelid_problems` in `hips_hind_legs`; točke ugriz, zobovje in gibanje nimajo oznake. Pasma je po pravilu runbooka v marketingu — potrdite.
- **Kožne gube in oči:** PDSA »redno« čistiti oči in gube na ustnicah — brez pogostosti, zato brez naloge (pravilo 24. 10. 2026).
- **Teža:** noben standard je ne navaja; v igri PDSA 54–81 kg za oba spola.
- **Ime:** »Bernardinec« ni preverjeno pri KZS.
- **Barva portreta:** rdeči plašč s temno masko je predlog Claude (najbolj značilna barva po FCI).
