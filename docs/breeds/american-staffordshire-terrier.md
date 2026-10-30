# American Staffordshire Terrier (ameriški staffordshirski terier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-81 · 2026-10-30

| | |
|---|---|
| Ključ / slug | `american_staffordshire_terrier` / `american-staffordshire-terrier` (SL `ameriski-staffordshirski-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 750 — v izbirniku za irskim volčjim hrtom |
| Vir podatkov | `docs/research/dog-data/data.json` → `american_staffordshire_terrier` (viri S625–S629; ponovno S8, S10, S11, S14, S24, S35, S45, S123, S174); seznam za vzreditelja: ni najden (`not_found`) |
| Izvor | FCI št. 286, skupina 3, sekcija 3 (terierji bulterierskega tipa), ZDA (S625, S626) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 77. mesto (S174, prebrano 2026-10-30) |
| PR-ji | pet-prep #448 (pasma) · portret: sledi · spletna stran: sledi (le s portretom) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-30 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | PetMD S628 »at least 60 minutes of exercise daily« (»at least N« → N). Pasme Royal Kennel Club ne priznava — strani RKC (in PDSA) z »Exercise:« ni; VCA S629 minut ne navaja | #448 |
| 2026-10-30 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **113**; prihod 2 / 9 / 36 / 113 | AAHA S11; starejši = 0,75 × mediana McMillan 2024 **12,5 leta** (tabela Dogs Trust S123) = 112,5 → 113. Ni izbrano: 108 (spodnja meja PetMD / VCA 12 let), 84 (Dogs Trust »> 7 let«) | #448 |
| 2026-10-30 | pravilo runbooka | učenje **1,2×** (Coren 34. mesto, »nadpovprečni«) | S35 (prebrano 2026-10-30) | #448 |
| 2026-10-30 | pravilo runbooka | teža **18,1–31,8 kg** (40–70 lb, oba spola), višina po FCI samci 46–48 cm / samice 43–46 cm, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | PetMD S628 (standarda FCI S626 in AKC S627 teže ne navajata: »Height and weight should be in proportion.«); VCA S629 enako; PetMD S10 (razred »Medium«, večina 40–60 lb) | #448 |
| 2026-10-30 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | VCA S629 »Weekly brushing helps remove loose hair …« (»Once a week« → 1); PetMD S628 »once every few weeks« je alternativa (redkeje) | #448 |
| 2026-10-30 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S628 »socialization is important—especially for American Staffordshire puppies.« | #448 |
| 2026-10-30 | David 2026-10-12 (M5-R16) | **60 min opozorila »počakaj s sprehodom«** po obroku (koraki štejejo, brez kazni) | PetMD S628 »always avoid exercise immediately after feeding« (GDV) | #448 |
| 2026-10-30 | pravila »Breed care tasks« (David 2026-10-24, M5-R19 / R20) | otrokova mini igra **čiščenje ušes vsake 4 tedne**; opomnik staršem **kopanje vsake 4 tedne** (nikoli točkovano) | PetMD S628 »Ear cleanings can be done during bath times (around every 4-6 weeks)« in »Stick with bathing no more than every 4-6 weeks« (spodnja meja → 4 tedni); VCA S629 »as required« je alternativa. Zobje, kremplji, frizer: brez frekvence → brez naloge | #448 |
| 2026-10-30 | pravilo runbooka | oznake: primeren za »družino z otroki« (`children` — z opombo VCA: z nadzorom); upoštevajte »rad ima družbo« (`needs_company`) in »močan lovski nagon« (`chases_small_pets`) | PetMD S628 »patient and loving with children«, »prone to anxiety … separation anxiety« (precedens stafordširski bulterier), »naturally strong prey drive« | #448 |
| 2026-10-30 | pravilo runbooka | **brez** `family_pet`, `small_children`, `other_pets`, `low_shedding`, `sheds`, `apartment`, `large_home`, `heat_sensitive`; nikoli »hypoallergenic« | ni izjave »družinski pes«; VCA »Generally good with supervision«; VCA »reactivity toward other dogs«; le PetMD »isn't a big shedder« (brez drugega vira in brez ključnih podatkov RKC) | #448 |
| 2026-10-30 | pravilo runbooka (§3) | **brez čipa dobrobiti**; marketing: **v aplikaciji da, v marketingu da** (nikoli igra z majhnimi otroki brez nadzora, nikoli obljuba zdravja) | ni plosko-obrazna pasma (BVA S92 ne velja); RKC je ne priznava → ni Breed Watch | #448 |
| 2026-10-30 | pravilo runbooka (§3) | barve za AI in izbirnik: **tigrasta (portret)**, modra, črna; naravna (nekupirana) ušesa in rep; nikoli črna s porjavelimi oznakami, jetrna, merle ali pretežno bela | FCI S626 »Any colour, solid, particolour, or patched is permissible;« (> 80 % bele, black and tan in liver niso zaželeni); seznam barv PetMD S628; AKC S627 »uncropped preferred«, »Not docked.«; uteži brez vira → `verified: false` | #448 |
| 2026-10-30 | predlog Claude, David lahko spremeni | EN »American Staffordshire Terrier« (FCI / AKC), SL »Ameriški staffordshirski terier«; iskanje »amstaff«, »staffordshire terrier«, »ameriški staffordshirski terier« … | zapis, ki ga uporablja Royal Canin Slovenija (vir C); namenoma ne »stafordširski«, da iskanje »stafordširski« še naprej najde le stafordširskega bulterierja; strani KZS ni bilo mogoče najti | #448 |
| 2026-10-30 | pravilo runbooka (§2.3) | seznam za vzreditelja: **ni najden** (prikaže se splošni pasji seznam) | RKC strani ni (pasma ni priznana); PetMD / VCA naštevata bolezni, ne testov; stran OFA CHIC ni vrnila vrstic | #448 |
| 2026-10-30 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia`, `pra_eye_disease`, `itchy_skin_allergy`; brez odstotkov | PetMD S628, VCA S629 | #448 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »ameriški staffordshirski terier«, »amstaff« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-81 | 2026-10-30 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 113. meseca; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-81 | 2026-10-30 |
| Česanje 1× na teden, »Nova izkušnja« za mladička, opozorilo po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 | 2026-10-30 |
| Mini igra čiščenje ušes (vsake 4 tedne); opomnik staršem kopanje (vsake 4 tedne) | 🛠 | 💶 izziv | M5-R19 / M5-R20 | 2026-10-30 |
| Barva (izbirnik: tigrasta, modra, črna) | 🛠 | 💶 izziv | M5-R21 | 2026-10-30 |
| AI portret v registru | 🗓 sledi takoj po združitvi | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/american-staffordshire-terrier`, `/sl/zivali/psi/ameriski-staffordshirski-terier`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal ameriškega staffordshirskega terierja (American Staffordshire Terrier, »AmStaff«) — 77. najbolj priljubljeno pasmo po AKC 2024 in 74. plačljivo pasmo psa. 5 novih virov (S625–S629). Odrasel pes v igri potrebuje 6.000 korakov na dan (PetMD: vsaj 60 minut gibanja), česanje enkrat na teden in uro premora med obrokom in sprehodom; otrok mu vsake 4 tedne očisti ušesa, starša pa aplikacija spomni na kopanje.
**Zakaj je pomembno:** AmStaff je močan, mišičast pes, ki je po PetMD potrpežljiv in ljubeč z otroki — a hitro postane tesnoben, če je sam, in ima močan lovski nagon. PetPrep pokaže, ali otrok zmore vsakodnevno gibanje, družbo in skrbno socializacijo mladička, preden pride pravi pes.
**Kako povedati** — starši: »Ameriški staffordshirski terier je po PetMD potrpežljiv in ljubeč z otroki, potrebuje pa vsaj uro gibanja na dan in ne mara samote. V PetPrepu boste videli, ali vaš otrok zmore 12 tednov takšne skrbi.« · otroci: »AmStaff je močan, prijazen pes s kratko svetlečo dlako. Vsak dan greš z njim ven, po jedi pa malo počakaš s sprehodom.« · vlagatelji / partnerji: »Štiriinsedemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Ime:** »Ameriški staffordshirski terier« je zapis Royal Canin Slovenija (vir C); strani KZS za slovensko ime ni bilo mogoče najti. Namenoma ni »stafordširski« (kot pri »stafordširski bulterier«), ker bi iskanje »stafordširski« sicer našlo dve pasmi. Potrdite ali popravite (sprememba = drug slug SL na spletni strani).
- **Viri brez RKC / PDSA:** pasme Royal Kennel Club ne priznava, zato so minute gibanja, teža in nega iz PetMD (S628, veterinarka) in VCA (S629) — oba tier B. Če želite drug vir (npr. FCI / klub pasme), povejte.
- **Pasme »bull« tipa in zakonodaja:** nekatere države (in zavarovalnice) za pse bulterierskega tipa predpisujejo posebna pravila. Runbook pasmo izključi iz marketinga le ob veterinarskem nasvetu proti oglaševanju (danes le plosko-obrazne pasme, BVA S92), zato je v marketingu. Želite drugače?
- **Kopanje:** PetMD (S628) pravi »no more than every 4-6 weeks« (največ), ušesa pa čistimo ob kopanju »around every 4-6 weeks« — uporabljen je ritem 4 tedne za oba. VCA (S629) »as required«. Če je opomnik za kopanje odveč, je to podatkovna migracija (sejalnik samo dodaja).
- **Seznam za vzreditelja:** ni najden (RKC strani ni, OFA CHIC ni vrnil vrstic). Če poznate shemo (npr. NCL / cerebelarna ataksija — DNA test), dodamo z virom.
- **Ugotovitev za skupne dokumente (ne urejam jih):** nič novega — država izvora ZDA (US) in zdravstveni ključi že obstajajo.
