# Staffordshire Bull Terrier (Stafordširski bulterier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-67 · 2026-10-26

| | |
|---|---|
| Ključ / slug | `staffordshire_bull_terrier` / `staffordshire-bull-terrier` (SL `stafordsirski-bulterier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 630 — v izbirniku za bernardincem |
| Vir podatkov | `docs/research/dog-data/data.json` → `staffordshire_bull_terrier` (viri S536–S544; ponovno S8, S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: genetska raznolikost (»Good Practice«) + 4 testi »Best Practice« (RKC S538) |
| Izvor | FCI št. 76 »Staffordshire Bull Terrier«, skupina 3, sekcija 3 (terierji bulterierskega tipa), Velika Britanija (S536) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 63. mesto »Staffordshire Bull Terrier« (S174, prebrano 2026-10-26) |
| PR-ji | pet-prep #396 (pasma, združen `d25de12`) · portret: #397 (zagon 40252257895) · spletna stran: pet-prep-website `feat/register-staffordshire-bull-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-26 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S538 »Exercise: Up to 1 hour per day« (»up to N« → N); PDSA S540 »a minimum of an hour exercise every day« se ujema | #396 |
| 2026-10-26 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108** mesecev; prihod 2 / 9 / 36 / 108 | AAHA S11; starejši = 0,75 × spodnja meja RKC »Over 12 years« (S538; PDSA S540 enako, PetMD S541 12–14 let). McMillan 2024 (12 let) le prek Wikipedije (S543, tier C) — da enako 108 | #396 |
| 2026-10-26 | pravilo runbooka | učenje **1,0×** (Coren rang 49, skupina »Average«) | S35 | #396 |
| 2026-10-26 | pravilo runbooka | teža psi 12,7–17 kg, psice 11–15,4 kg (igra 11–17 kg), višina 35,5–40,5 cm, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | standard FCI S537 (RKC S539 se ujema); PetMD S10 (razred »Medium«) | #396 |
| 2026-10-26 | pravilo runbooka (M5-R13) | česanje **1× na teden** | RKC S538 »Grooming: Once a week«; PDSA S540 »a weekly brush« | #396 |
| 2026-10-26 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S541 »But they do take lots of patience and early socialization!« | #396 |
| 2026-10-26 | pravilo »Breed care tasks« (David 2026-10-24) | **brez** nalog zob, nohtov, ušes, frizerja ali kopanja; **brez** opozorila po obroku | noben vir ne navaja pogostosti (PetMD: ušesa »kot svetuje veterinar«, PDSA: kopanje le po potrebi); ni citata o premoru po obroku | #396 |
| 2026-10-26 | pravilo runbooka | oznake: primeren za »družinsko življenje« (`family_pet`) in »družino z otroki« (`children`); upoštevajte »ko se dolgočasi, grize stvari« (`chews_when_bored`), »rad ima družbo« (`needs_company`), »izpada mu dlaka« (`sheds`), »močan lovski nagon« (`chases_small_pets`, na koncu) | RKC S538 »… known as a wonderful family pet.«; FCI S537 »Highly intelligent and affectionate especially with children.«; PDSA S540 »They're known chewers …«, »… known to suffer with separation anxiety when left alone.«, »Staffies have a very strong prey drive …«; RKC S538 »Sheds: Yes« | #396 |
| 2026-10-26 | pravilo runbooka | **brez** oznak `small_children`, `not_for_small_children`, `apartment`, `large_home`, `active_family`, `other_pets`, `frequent_grooming`, `shorter_lifespan`; brez nove oznake | PDSA: morda ni primeren za družine z mlajšimi otroki (le parafraza, stavek ni bil vrnjen dobesedno); RKC »Small house«; 60 min; PetMD vs PDSA o drugih živalih; »Once a week«; »Over 12 years« | #396 |
| 2026-10-26 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | RKC Breed Watch kategorija 2 (S538), a točke skrbi niso bile vrnjene (stran Breed Watch ni bila dosegljiva) — precedens pomeranec / šetlandski ovčar | #396 |
| 2026-10-26 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli z obljubo o zdravju | ni pasma s sploščenim obrazom; veterinarsko odsvetovanje oglaševanja velja le zanje (BVA S92) | #396 |
| 2026-10-26 | pravilo runbooka (§3) | barve za AI in izbirnik (11): **tigrasta** (portret), tigrasto-bela, rdeča, črno-bela, plava, črna, modra, rdeče-bela, modro-bela, plavo-bela, bela; nikoli črna s porjavelimi oznakami (»black and tan«) ali jetrna; naravna »rose« ušesa in rep; zmerna glava z vidnim gobčkom | FCI S537 »White. Red, fawn, black, blue, or any one of these colours with white.«, »Any shade of brindle or any shade of brindle with white.«; RKC S539. Uteži barv brez vira → `verified: false`; barva portreta je predlog Claude | #396 |
| 2026-10-26 | predlog Claude, David lahko spremeni | ime SL »Stafordširski bulterier«; iskanje »staffie«, »staffy«, »stafford«, »sbt«, »stafordsirski bulterier« … | slovenskega vira (KZS) za ime ni bilo mogoče preveriti | #396 |
| 2026-10-26 | pravilo runbooka (§2.3) | seznam za vzreditelja: genetska raznolikost (»Good Practice«, minimum); DNK HC-HSF4-2, DNK L-2-HGA, komolci BVA/KC, oči BVA/KC/ISDS (»Best Practice«) — nov ključ `dna_l2hga` | RKC S538 | #396 |
| 2026-10-26 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia_arthritis`, `itchy_skin_allergy`, `cataracts`, nov `l2hga_nervous_system`, `certain_cancers`; brez odstotkov in relativnih tveganj | PDSA S540, PetMD S541, RKC S538; VetCompass S542 le v raziskavi | #396 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »staffie«, »staffy«, »stafordširski bulterier« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-67 | 2026-10-26 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 108 meseca; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-67 | 2026-10-26 |
| Česanje 1× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-26 |
| Barva (izbirnik: 11 standardnih barv, privzeto tigrast) | 🛠 | 💶 izziv | M5-R21 | 2026-10-26 |
| AI portret v registru (tigrast, sprejet v prvem poskusu, zagon 40252257895, PR #397) | ✅ | — | M5-R11-04 | 2026-10-26 |
| Stran na petprep.si (`/animals/dogs/staffordshire-bull-terrier`, `/sl/zivali/psi/stafordsirski-bulterier`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal stafordširskega bulterierja — 63. najbolj priljubljeno pasmo po AKC 2024 in 63. plačljivo pasmo psa. 9 novih virov (S536–S544). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do ene ure gibanja), starejši 4.500 od 9. leta; uči se kot povprečen pes (Coren: 49. mesto, ×1,0). Česanje enkrat na teden in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** »Staffie« je v Veliki Britaniji ena najpogostejših pasem — znan je kot čudovit družinski pes in nežen z otroki, a ima zelo močan lovski nagon (manjše živali niso dobra ideja), rad grize stvari, ko se dolgočasi, in težko prenaša samoto. Kratka dlaka je preprosta; zahtevna sta družba in gibanje vsak dan — PetPrep pokaže, ali družina to zdrži 12 tednov.
**Kako povedati** — starši: »Stafordširski bulterier potrebuje uro gibanja na dan in ne mara biti dolgo sam; z mačko ali zajcem v hiši ni dobra izbira. V PetPrepu boste videli, ali vaš otrok zmore 6.000 korakov na dan 12 tednov zapored.« · otroci: »Staffie je močan, mišičast pes, ki obožuje svojo družino. Z njim greš vsak dan ven, se z njim igraš, da se ne dolgočasi, in ga enkrat na teden počešeš.« · vlagatelji / partnerji: »Triinšestdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Meja starejšega psa (108 mesecev, pravilo runbooka, začasno):** iz spodnje meje RKC »Over 12 years«. McMillan 2024 (12 let, zdaj le prek Wikipedije S543) bi dal enako 108 — preverite v viru A/B (Dogs Trust S123 ni bil dosegljiv).
- **Breed Watch kategorija 2:** točke skrbi niso bile vrnjene → brez oznake dobrobiti; preverite stran RKC Breed Watch.
- **Vročina:** PetMD S541 »Staffies are very averse to heat.« — oznaka `heat_sensitive` ima besedilo »gost kožuh — slabo prenaša vročino«, ta pasma pa ima kratko dlako → brez oznake in brez pravila »ne na sprehod v vročini« (to je po runbooku le za pse s sploščenim obrazom). Želite novo oznako ali pravilo?
- **Občutljiva koža:** PDSA S540 »Staffies can have sensitive skin …« in VetCompass (atopijski dermatitis, S542) — oznaka `sensitive_skin` je doslej sledila točki Breed Watch, zato je ni; želite oznako?
- **Mlajši otroci:** PDSA piše, da morda ni primeren za družine z mlajšimi otroki (moč, energija) — stavek ni bil vrnjen dobesedno, zato brez `not_for_small_children`; v marketingu ga ne kažite z majhnimi otroki ali malimi živalmi (predlog Claude).
- **Ime:** »Stafordširski bulterier« ni preverjeno pri KZS.
- **Barva portreta:** tigrasta je predlog Claude (standard ne določa najpogostejše barve). Portret: zagon 40252257895 (koda `d25de12`, brez produkcijske namestitve), sprejet v prvem poskusu; PR #397.
