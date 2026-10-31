# Lagotto Romagnolo (lagotto romagnolo)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-82 · 2026-10-31

| | |
|---|---|
| Ključ / slug | `lagotto_romagnolo` / `lagotto-romagnolo` (SL `lagotto-romagnolo`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 760 — v izbirniku za ameriškim staffordshirskim terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `lagotto_romagnolo` (viri S630–S636; ponovno S8, S10, S11, S14, S24, S35, S45, S123, S174); seznam za vzreditelja: RKC (S632) |
| Izvor | FCI št. 298, skupina 8, sekcija 3 (vodni psi), Italija (S630, S631) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 78. mesto (S174, prebrano 2026-10-31) |
| PR-ji | pet-prep #462 (pasma, združen `fa49562`) · portret: PR (ta, zagon 41154129241) · spletna stran: pet-prep-website `feat/register-lagotto-romagnolo` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-31 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | RKC S632 »Exercise: More than 2 hours per day« (»more than N« → N); Woodgreen S636 »around two hours of exercise a day« se ujema (njihov povzetek »60-90 minutes« je alternativa) | #462 |
| 2026-10-31 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108**; prihod 2 / 9 / 36 / 108 | AAHA S11; starejši = 0,75 × spodnja meja RKC »Over 12 years« (S632) = 9 let = 108. Mediane McMillan 2024 ni (tabela Dogs Trust S123 nima vrstice), VetCompass tudi ne — tretja izbira runbooka (precedens havanski bišon). Ni izbrano: 84 (Dogs Trust »> 7 let«), 135 (0,75 × 15 let, PetMD / Woodgreen) | #462 |
| 2026-10-31 | pravilo runbooka | učenje **1,0×** (Coren pasme ne uvršča) | Corenova lestvica (1994, S35) je starejša od priznanja FCI (2005, S630) | #462 |
| 2026-10-31 | pravilo runbooka | teža po FCI **samci 13–16 kg, samice 11–14 kg** (igra 11–16 kg), višina samci 43–48 cm / samice 41–46 cm, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S631, standard RKC S633 enako; PetMD S10 (razred »Medium«) | #462 |
| 2026-10-31 | **predlog Claude — čaka Davida** (M5-R13) | česanje **2× na teden** (`verified: false`); skuštrana dlaka po 1 zamujenem | RKC S632 »Grooming: More than once a week« brez števila; tudi Woodgreen S636 (»a few times a week«) in PetMD S635 (»regular brushing«) števila ne navajata → nadomestna vrednost 2 (precedens pirenejski gorski pes) | #462 |
| 2026-10-31 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S635 »A Lagotto can be remarkably shy and requires extensive socialization« | #462 |
| 2026-10-31 | pravila »Breed care tasks« (David 2026-10-24, M5-R18 / R19) | opomnik staršem **frizer vsakih 6 tednov** in **kopanje vsake 4 tedne** (nikoli točkovano) | Woodgreen S636 »professional grooming every six to eight weeks …« (6–8 tednov → 6); PetMD S635 »Bathe them up to once a month« (mesec → 4 tedne). Ušesa: PetMD le »ko je bil v vodi« (pogojno) → brez naloge; zobje, kremplji: brez frekvence A/B → brez naloge | #462 |
| 2026-10-31 | pravilo runbooka (M5-R16) | **brez** opozorila po obroku | noben vir ne citira premora po jedi | #462 |
| 2026-10-31 | pravilo runbooka | oznake: primeren za »družinsko življenje« (`family_pet`) in »dom, kjer želite manj dlak« (`low_shedding`); upoštevajte »vsak dan potrebuje veliko gibanja«, »potrebuje miselne izzive«, »rad ima družbo«, »potrebuje česanje večkrat na teden« | RKC S633 »An excellent and alert family companion.«, PetMD S635 »do great in a family environment«; RKC S632 »Sheds: No«; RKC S632 + Woodgreen S636 (gibanje); PetMD S635 »constant mental and physical stimulation«; Woodgreen S636 »prone to separation-related issues if left alone too often«; RKC S632 »More than once a week« | #462 |
| 2026-10-31 | pravilo runbooka | **brez** `children`, `small_children`, `other_pets`, `apartment`, `large_home`, `chases_small_pets`, `shorter_lifespan`; nikoli »hypoallergenic« | Woodgreen ima le splošni nasvet o nadzoru ob majhnih otrocih; »Sociable with pets: Moderate«; RKC »Size of home: Small house«; ni izjave o lovskem nagonu; RKC »Over 12 years« | #462 |
| 2026-10-31 | pravilo runbooka (§3) | **brez čipa dobrobiti**; marketing: **v aplikaciji da, v marketingu da** (nikoli obljuba zdravja) | RKC Breed Watch kategorija 1 (S632); ni plosko-obrazna pasma (BVA S92 ne velja) | #462 |
| 2026-10-31 | pravilo runbooka (§3) | barve za AI in izbirnik: **rjavi roan (portret)**, belkast, bel z rjavimi lisami, bel z oranžnimi lisami, oranžni roan, rjav, oranžen; rjav nos; nikoli črn ali siv | FCI S631 / RKC S633 seznam barv; FCI: »Black coat colour, black patches or black pigmentation« izključi; AKC S634 enako; uteži brez vira → `verified: false` | #462 |
| 2026-10-31 | pravilo runbooka (§2.3) | seznam za vzreditelja: **minimum** DNA testa JE (juvenilna epilepsija) in LSD (lizosomska bolezen kopičenja); **dobra praksa** sheme BVA/KC za komolce, oči in kolke | RKC Breeds A–Z (S632); nova ključa `dna_je`, `dna_lsd` | #462 |
| 2026-10-31 | pravilo runbooka | zdravstveni ključi registra: `epilepsy`, `storage_disease` (nov), `hip_elbow_dysplasia`; brez odstotkov | PetMD S635, RKC S632 | #462 |
| 2026-10-31 | predlog Claude, David lahko spremeni | EN »Lagotto Romagnolo« (FCI / RKC / AKC), SL »Lagotto romagnolo«; iskanje »lagotto«, »lagotti«, »romagna water dog«, »truffle dog«, »pes za tartufe«, »tartufar« | ime se uporablja nespremenjeno; strani KZS ni bilo mogoče najti. »romanjski vodni pes« je le sopomenka na spletni strani (v aplikaciji ne, ker bi iskanje »vodni pes« našlo tudi portugalskega vodnega psa) | #462 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »lagotto«, »pes za tartufe« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-82 | 2026-10-31 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 108. meseca; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-82 | 2026-10-31 |
| Česanje 2× na teden (predlog), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-31 |
| Opomnika staršem: frizer (vsakih 6 tednov), kopanje (vsake 4 tedne) | 🛠 | 💶 izziv | M5-R18 / M5-R19 | 2026-10-31 |
| Barva (izbirnik: 7 standardnih barv) | 🛠 | 💶 izziv | M5-R21 | 2026-10-31 |
| AI portret v registru (rjavi roan, sprejet v prvem poskusu, zagon 41154129241) | ✅ | — | M5-R11-04 | 2026-10-31 |
| Stran na petprep.si (`/animals/dogs/lagotto-romagnolo`, `/sl/zivali/psi/lagotto-romagnolo`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal lagotta romagnola — italijanskega »psa za tartufe«, 78. najbolj priljubljeno pasmo po AKC 2024 in 75. plačljivo pasmo psa. 7 novih virov (S630–S636). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot 2 uri gibanja), česanje dvakrat na teden, starša pa aplikacija spomni na frizerja vsakih 6 tednov in kopanje vsake 4 tedne.
**Zakaj je pomembno:** Lagotto je majhen do srednje velik kodrast pes, ki se malo lisa in je po standardu RKC »odličen družinski spremljevalec« — a potrebuje veliko gibanja, miselnih izzivov in družbe, kodrasta dlaka pa redno nego. PetPrep pokaže, ali otrok zmore vse to 12 tednov, preden pride pravi pes.
**Kako povedati** — starši: »Lagotto romagnolo je po Royal Kennel Clubu živahen, inteligenten in ljubeč družinski pes, ki potrebuje več kot dve uri gibanja na dan in redno česanje. V PetPrepu boste videli, ali vaš otrok zmore takšen ritem.« · otroci: »Lagotto ima kodre kot jagnje in izvrsten nos — v Italiji z njim iščejo tartufe. Vsak dan greš z njim na dolg sprehod in ga večkrat na teden počešeš.« · vlagatelji / partnerji: »Petinsedemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Česanje 2× na teden (predlog):** RKC pravi »More than once a week«, Woodgreen »a few times a week«, PetMD »regular brushing« — nihče ne da števila. Uporabljen je nadomestni 2 (`verified: false`, `PROPOSED_R13_LAGOTTO_ROMAGNOLO`). Potrdite ali določite drugo število (podatkovna migracija).
- **Življenjska doba:** mediane McMillan 2024 / VetCompass ni; starejše obdobje (108) je iz spodnje meje RKC »Over 12 years«. PetMD in Woodgreen navajata 15–17 let — če želite, lahko starejše obdobje premaknemo (npr. 135 = 0,75 × 15 let).
- **Ušesa, zobje, kremplji:** stran veterinarske ambulante (vsebina Genesis4Pets, tier C) navaja čiščenje ušes vsak teden, zobe vsaj 2× na teden in striženje 3–4× na leto — runbook vira C ne uporabi samostojno, zato teh nalog ni. PetMD za ušesa pravi le »ko je bil v vodi«. Če želite nalogo za ušesa, povejte.
- **Ime:** »Lagotto romagnolo« se v slovenščini uporablja nespremenjeno; strani KZS ni bilo mogoče najti. Potrdite ali popravite.
- **Ugotovitev za skupne dokumente (ne urejam jih):** nov zdravstveni ključ `storage_disease` in nova testa `dna_je` / `dna_lsd` (besedila na spletni strani dodana v PR spletne strani).
- **Portret:** zagon 41154129241 (koda `fa49562`, brez produkcijske namestitve, 0,15 $), sprejet v prvem poskusu — realistična fotografija odraslega psa v rjavem roanu, gosti volnati kodri, enakomerno postrižen, rjav nos, oker oči, viseča ušesa, svetlo siva studijska podlaga.
