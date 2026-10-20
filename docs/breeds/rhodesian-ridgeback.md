# Rodezijski ridgeback (Rhodesian Ridgeback)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-47 · 2026-10-19

| | |
|---|---|
| Ključ / slug | `rhodesian_ridgeback` / `rhodesian-ridgeback` (SL `rodezijski-ridgeback`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 430 — v izbirniku za šiba inujem |
| Vir podatkov | `docs/research/dog-data/data.json` → `rhodesian_ridgeback` (viri S384–S394, K25; ponovno S10, S11, S14, S24, S35, S45, S174, S263, S376) |
| Izvor | FCI št. 146, skupina 6, sekcija 3 (sorodne pasme), Južna Afrika (S384, S385) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 43. mesto »Rhodesian Ridgeback« (mesta do 42 so že v aplikaciji; S174) |
| PR-ji | pet-prep #285 (pasma) · portret — · spletna stran — |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-19 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | RKC S387 »Exercise: More than 2 hours per day« (»več kot N« → N); PDSA S390 »a minimum of two hours« enako. Alternativi: Woodgreen S392 90–120 min, PetMD S391 vsaj 45 min | #285 |
| 2026-10-19 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90** mesecev; prihod 2 / 9 / 36 / 90 | 0,75 × spodnja meja RKC »Over 10 years« (S387; PDSA S390 enako). McMillan 2024 (S376) za pasmo ne navaja mediane, mediane VetCompass nismo našli. Alternativi: 108 (McMillan 2024 12 let prek Wikipedije S394, raven C), 84 (Dogs Trust > 7 let, S14). **Začasno**, dokler ni preverjena dopolnilna tabela McMillan 2024 | #285 |
| 2026-10-19 | pravilo runbooka | učenje **1,0×** | Coren 52. mesto, razred »Average« (S35) | #285 |
| 2026-10-19 | pravilo runbooka | teža 32–36,5 kg (samica → samec), višina samci 63–69 cm, samice 61–66 cm, rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | standard FCI S385 (36,5 kg samec, 32 kg samica; RKC S388 enako); PDSA S390 31–38 kg (alternativa); PetMD S10 (razred »Large«) | #285 |
| 2026-10-19 | pravilo runbooka (M5-R13) | česanje **1× na teden** (zmršena dlaka po 1 zamujenem) | RKC S387 »Grooming: Once a week«; PDSA, Woodgreen, PetMD, AKC enako | #285 |
| 2026-10-19 | David 2026-10-12 (M5-R16) | opozorilo **»počakaj s sprehodom« 60 min po obroku** (koraki se štejejo) | PDSA S390 »Remember to leave a gap between eating and exercising as this is thought to reduce the risk of GDV.« | #285 |
| 2026-10-19 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S390 »It’s important to socialise them from a young age with different people, dogs and experiences« | #285 |
| 2026-10-19 | pravilo runbooka | primeren za: aktivno družino (`active_family`), družinski pes (`family_pet`), velik dom (`large_home`) | RKC S387 (»Large house«, »Large garden«, več kot 2 uri gibanja); FCI S385 »family pet«; PDSA S390 | #285 |
| 2026-10-19 | pravilo runbooka | upoštevajte: dolgo dnevno gibanje, izkušen lastnik (»močan pes«), ni za majhne otroke, ne mara biti sam, izpada mu dlaka, lovi male živali (na koncu) | PDSA S390 (»playful and powerful dogs«, ne za začetnike; »risk knocking over smaller children«; nikoli sam več kot štiri ure; »very high prey drive«); Woodgreen S392; RKC S387 »Sheds: Yes«. Brez nove oznake | #285 |
| 2026-10-19 | pravilo runbooka (§3) | **ni pasma z vprašanjem dobrobiti** → brez oznake dobrobiti; marketing: **v aplikaciji da, v marketingu da** — nikoli kot pes za majhne otroke ali začetnike, nikoli brez povodca na prostem | RKC Breed Watch kategorija 1 (S386, S387) | #285 |
| 2026-10-19 | pravilo runbooka (§3) | barve za AI in izbirnik: **rdeče pšenična** (portret), **svetlo pšenična**; temen gobec in ušesa dovoljena, malo bele na prsih in prstih; nos črn (portret); greben (»ridge«) kot posebna lastnost portreta; nikoli črna, črna s porjavelimi oznakami ali pes brez grebena | standard FCI S385 »Light wheaten to red wheaten.«; RKC S388, AKC S389. Teže barv brez vira (`verified: false`) | #285 |
| 2026-10-19 | pravilo runbooka (§2.3) | seznam za vzreditelja (vse »druga shema«): pregled na dermoidni sinus (shema klubov pasme), rentgen kolkov in komolcev, DNA test za JME (mladostniška miokloniška epilepsija), DNA test za DM | S263 (tabela zdravstvenih shem klubov), PDSA S390, RKC novica K25. Zdravstveni standard RKC za pasmo ne navaja testov »Good Practice« / »Best Practice« | #285 |
| 2026-10-19 | predlog Claude, David lahko spremeni | ime SL »rodezijski ridgeback« (slug `rodezijski-ridgeback`); iskalni ključi tudi »ridgeback«, »rodezijski«, »african lion dog« | raba v slovenščini; KZS ni preverjen | #285 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »ridgeback«, »rodezijski« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-47 | 2026-10-19 |
| Pravila po starosti (2.000 → 12.000 korakov, odrasel 12.000, starejši 9.000 od 90 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-47 | 2026-10-19 |
| Česanje 1× na teden (rutina »Počeši«), opozorilo po obroku, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R16 / M5-R17 | 2026-10-19 |
| Barva (izbirnik barve: rdeče pšenična, svetlo pšenična) | 🛠 | 💶 izziv | M5-R21 | 2026-10-19 |
| AI portret v registru | 🗓 po združitvi PR #285 | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/rhodesian-ridgeback`, `/sl/zivali/psi/rodezijski-ridgeback`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal rodezijskega ridgebacka — 43. najbolj priljubljeno pasmo po AKC 2024 in 43. plačljivo pasmo psa. 11 novih virov (S384–S394) in en nov vir za seznam vzreditelja (K25). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot dve uri gibanja), starejši od 7 let in pol 9.000. Česanje enkrat na teden, premor med obrokom in sprehodom ter socializacija mladička so del izziva od prvega dne.
**Zakaj je pomembno:** Ridgeback je lep, miren na videz in kratkodlak — videti je kot »preprost« velik pes. Viri povedo drugo plat: potrebuje vsaj dve uri gibanja na dan, ima zelo močan lovski nagon, ne mara biti sam več kot nekaj ur, kot mlad in velik pes lahko podre majhnega otroka in ni pes za začetnike. Prav to otrok v 12 tednih občuti na lastnih nogah.
**Kako povedati** — starši: »Rodezijski ridgeback je zvest družinski pes, a potrebuje več kot dve uri gibanja na dan, izkušenega lastnika in nekoga, ki je pogosto doma. V PetPrepu boste videli, ali otrok zmore 12.000 korakov na dan 12 tednov.« · otroci: »Ridgeback ima na hrbtu poseben greben iz dlake, ki raste v nasprotno smer. Rad teče in dolgo hodi, zato ga vsak dan veliko sprehajaš, po obroku pa malo počakaš.« · vlagatelji / partnerji: »Devetindvajseta pasma, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal (vseh plačljivih psov je zdaj 43) — z viri, testi in pravili, brez nove kode mehanik.«

## Odprto za Davida

- **Potrebe brez mehanike (needs-david):** redno čiščenje zob ali žvečilke za zobe (Woodgreen S392) → **M5-R15**; tedenski pregled in čiščenje ušes (Woodgreen S392) in kopanje vsaj enkrat na mesec (PetMD S391) → **M5-R20**; **sprehod brez povodca samo v varno ograjenem prostoru** (AKC S393, PDSA S390) → za to še ni naloge v ROADMAP — **potrebna nova naloga (David)** (enako kot pri šiba inuju).
- **Začetek starosti 90 mesecev je začasen** (spodnja meja RKC); McMillan 2024 prek Wikipedije navaja 12 let (→ 108). Preveriti dopolnilno tabelo McMillan 2024.
- **`needs_mental_stimulation`:** ni dobesednega citata o potrebi → oznake ni; David lahko odloči drugače.
- **Barva portreta:** rdeče pšenična kot najbolj tipična (brez vira za težo barv).
- **Ime:** »rodezijski ridgeback« ni preverjeno pri KZS.
- **Spletna stran:** nov izvor Južna Afrika (`ZA`), nov ključ zdravja `dermoid_sinus` in nova testa `dermoid_sinus_scheme`, `dna_jme` (besede Claude).
- **Deljena testa:** `BreedSuitabilityTest` (seznami izjem za `experienced_owner`, `not_for_small_children`, `needs_company`, `chases_small_pets` — kot pri prejšnjih pasmah) in `BreedConfigTest` (seznam Filament zdaj pokaže vse zapise — 51. pasma je padla na 2. stran; popravek velja za vse prihodnje pasme).
