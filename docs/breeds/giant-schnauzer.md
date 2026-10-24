# Veliki šnavcer (Giant Schnauzer)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-61 · 2026-10-24

| | |
|---|---|
| Ključ / slug | `giant_schnauzer` / `giant-schnauzer` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 570 — v izbirniku za malim italijanskim hrtom |
| Vir podatkov | `docs/research/dog-data/data.json` → `giant_schnauzer` (viri S491–S499; ponovno S8, S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja K35 |
| Izvor | FCI št. 181 »Riesenschnauzer«, skupina 2, sekcija 1 (pinčerji in šnavcerji, 1.2 šnavcerji), Nemčija (S491) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 57. mesto »Giant Schnauzer« (S174, prebrano 2026-10-24) |
| PR-ji | pet-prep #367 (pasma, združen `6dba4d5`) · portret #368 · spletna stran: pet-prep-website, veja `feat/register-giant-schnauzer` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-24 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | RKC S495 »Exercise: More than 2 hours per day« (»more than N« → N); PDSA S496 »Minimum exercise (per day): 2 hours« se ujema; PetMD S497 »at least 40-60 minutes« — alternativa | #367 |
| 2026-10-24 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108** mesecev; prihod 2 / 9 / 36 / 108 | 0,75 × spodnja meja RKC »Over 12 years« (S495) = 9 let = 108. Tabele Dogs Trust z medianami McMillan 2024 (S123) ta zagon ni mogel odpreti, mediane VetCompass ni → tretja izbira pravila (kot pri malem italijanskem hrtu). Alternativi: 109 (McMillan 2024 »12.1 years«, le prek Wikipedije S498, tier C); 84 (Dogs Trust > 7 let, S14) | #367 |
| 2026-10-24 | pravilo runbooka | učenje **1,2×** (Coren rang 28, skupina »Above average«) | S35 (rang 28) | #367 |
| 2026-10-24 | pravilo runbooka | teža 35–47 kg, rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | standard FCI št. 181 (S492) »Rüden und Hündinnen : 35 bis 47 kg.« (edini standard s težo); PDSA S496 40–45 kg (alternativa); višina RKC S494 psi 65–70 cm, psice 60–65 cm; PetMD S10 (razred »Large«) | #367 |
| 2026-10-24 | pravilo runbooka (M5-R13) | česanje **vsak dan (7× na teden)** (zmršena dlaka po 4 zamujenih) | RKC S495 »Grooming: Every day« → 7; PDSA S496 »The coat of Giant Schnauzers requires daily grooming and regular trimming.« enako; PetMD S497 tedensko (alternativa) | #367 |
| 2026-10-24 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S496 »Giant Schnauzers can make lovable pets for families with older children, given the right socialisation as puppies.« | #367 |
| 2026-10-24 | pravilo runbooka (M5-R16) | **brez** opozorila po obroku | PDSA S496 navaja GDV le na seznamu bolezni, brez nasveta o premoru med hranjenjem in gibanjem; PetMD S497 napihnjenosti ne omenja | #367 |
| 2026-10-24 | pravilo runbooka | oznake: aktivno družino (`active_family`), družinsko življenje (`family_pet`), veliko hišo z vrtom (`large_home`), dom, kjer želite manj dlak (`low_shedding`); upoštevajte: veliko gibanja (`long_daily_exercise`), izkušen lastnik (`experienced_owner`), ni za dom z majhnimi otroki (`not_for_small_children`), rad ima družbo (`needs_company`), česanje večkrat na teden (`frequent_grooming`) | RKC S495 »Exercise: More than 2 hours per day«, »Size of home: Large house«, »Sheds: No«, »Grooming: Every day«; PDSA S496 »… lovable pets for families with older children, given the right socialisation as puppies.«, »They're quite strong-minded, and are ideally suited to experienced dog owners.«; PetMD S497 »not recommended for first-time pet parents or families with small children, small dogs, or cats.«, »Giant Schnauzers don't do well in apartments or in homes where people aren't home much.« | #367 |
| 2026-10-24 | pravilo runbooka | **brez** oznak `children`, `small_children`, `first_time_owner`, `apartment`, `other_pets`, `chases_small_pets`, `sheds`; brez nove oznake | le starejši otroci (PDSA, PetMD); PetMD: ne za majhne pse in mačke; noben vir ne govori o močnem lovskem nagonu; RKC »Sheds: No« | #367 |
| 2026-10-24 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | RKC Breed Watch kategorija 1 (S495) | #367 |
| 2026-10-24 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot pes za majhne otroke ali začetnike, pes, ki je ves dan sam, »hipoalergen«, ali s kupiranimi uhlji / repom | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #367 |
| 2026-10-24 | pravilo runbooka (§3) | barve za AI in izbirnik: **črn** (portret), poper in sol (s temno masko), črno-srebrn; nikoli bel, rjav ali lisast; naravni uhlji in rep (nikoli kupirani) | RKC S494 »Any other colour or combination of colours unacceptable.«; FCI S491 / S493 (črno-srebrna od 1. 7. 2021). Uteži barv brez vira → `verified: false`; črna za portret je predlog Claude | #367 |
| 2026-10-24 | predlog Claude, David lahko spremeni | ime SL »veliki šnavcer« (slug `veliki-snavcer`); iskalni ključi tudi »veliki snavcer«, »riesenschnauzer«, »riesen«, »munich schnauzer« | običajno slovensko ime (MrPet.si S499, tier C); pri KZS ni preverjeno | #367 |
| 2026-10-24 | pravilo runbooka (§8) | AI portret: realistična fotografija odraslega psa, črna žičasta dlaka z obrvmi in brado, naravni povešeni uhlji in nekupiran rep, svetla studijska podlaga, naravna razmerja (dlaka nekoliko daljša, netrimana); sprejet v prvem poskusu (zagon 40052551564, koda `6dba4d5`, brez produkcijske namestitve, 0,15 $) | barva portreta = najtežja barva iz `breed_appearance.php` | #368 |
| 2026-10-24 | pravilo runbooka (§2.3) | seznam za vzreditelja — minimum: genetska raznolikost, komolci in kolki (BVA/RKC), DNK prcd-PRA; najboljša praksa: DCM, PRA5, pregled oči BVA/KC/ISDS (tudi legla), HUU. Nova ključa testov `dna_dcm`, `dna_pra5` | RKC S495; BSAVA K35 (uradna DNK shema za DCM pri velikem šnavcerju, 2018) | #367 |
| 2026-10-24 | pravilo runbooka | novi zdravstveni ključi registra **`panosteitis`**, **`urinary_incontinence`**, **`osteochondritis`** — mirne povedi brez številk; ostali: `hip_dysplasia`, `bloat_gdv`, `heart_muscle_disease`, `hypothyroidism`, `bladder_stones`, `pra_eye_disease` | PDSA S496, PetMD S497, RKC S495 | #367 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »veliki šnavcer«, »riesenschnauzer«, »šnavcer« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-61 | 2026-10-24 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 108 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-61 | 2026-10-24 |
| Česanje vsak dan (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-24 |
| Barva (izbirnik barve: črn, poper in sol, črno-srebrn) | 🛠 | 💶 izziv | M5-R21 | 2026-10-24 |
| AI portret v registru (črn, sprejet v prvem poskusu, zagon 40052551564) | ✅ | — | M5-R11-04 | 2026-10-24 |
| Stran na petprep.si (`/animals/dogs/giant-schnauzer`, `/sl/zivali/psi/veliki-snavcer`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal velikega šnavcerja — 57. najbolj priljubljeno pasmo po AKC 2024 in 57. plačljivo pasmo psa. 9 novih virov (S491–S499) in en nov vir za seznam vzreditelja (K35). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC in PDSA: več kot dve uri gibanja), starejši od 9 let 9.000; uči se hitreje od mešanca (Coren: 28. mesto, ×1,2). Vsakodnevno česanje in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** Veliki šnavcer je močan, energičen delovni pes z žičasto dlako, ki jo je treba česati vsak dan in jo občasno strokovno trimati. Viri ga priporočajo izkušenim lastnikom, družinam s starejšimi otroki in domu, kjer je nekdo večino dneva. Dve uri gibanja na dan je velika obveza — PetPrep pokaže, ali jo družina zdrži 12 tednov.
**Kako povedati** — starši: »Veliki šnavcer potrebuje več kot dve uri gibanja na dan, vsakodnevno česanje in nekoga, ki je veliko doma. V PetPrepu boste videli, ali družina zmore 12.000 korakov na dan 12 tednov zapored.« · otroci: »Veliki šnavcer je velik pes z brado in obrvmi. Z njim greš vsak dan dolgo ven in ga vsak dan počešeš.« · vlagatelji / partnerji: »Sedeminpetdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Rešeno 24. 10. 2026 (David, M5-R14 / R15 / R18 / R19 / R20 — PR #376):** naloga samo, kjer vir navaja pogostost (razpon → spodnja meja); mini-igre otroka po modelu česanja (pošten delež, reže, poročilo, brez kazni), pasji frizer in kopanje = opomnik staršu, ne šteje v oceno; samo novi psi. Za to pasmo — zgrajeno: čiščenje ušes enkrat na teden (mini-igra otroka); brez pogostosti v viru → brez naloge (dejstvo ostane v besedilu): pasji frizer. Spodnje točke M5-R14 / R15 / R18 / R19 / R20 so s tem zaprte.

- **Potreba brez mehanike (ROADMAP M5-R18):** strokovno trimanje (»stripping«) pri pasjem frizerju na dva do štiri mesece in redno striženje (PetMD S497, PDSA S496); nikoli britje.
- **Potreba brez mehanike (ROADMAP M5-R20):** pregled ušes vsak teden (PetMD S497).
- **Mediana življenjske dobe:** tabele McMillan 2024 (Dogs Trust S123) ta zagon ni mogel odpreti; Wikipedija (S498, tier C) navaja 12,1 leta iz McMillan 2024 → če jo potrdite v viru, bi bil starejši od 109 mesecev namesto 108 (nova podatkovna migracija).
- **Česanje:** RKC in PDSA »vsak dan« (7× na teden, pravilo runbooka); PetMD le »weekly brushing« — če želite manj, je potrebna podatkovna migracija.
- **Ime:** »veliki šnavcer« ni preverjeno pri KZS.
- **Barva portreta:** standard nobeni barvi ne daje prednosti; črna je izbrana kot prva barva standarda RKC (predlog Claude).
