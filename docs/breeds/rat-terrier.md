# Rat terier (Rat Terrier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-85 · 2026-11-02

| | |
|---|---|
| Ključ / slug | `rat_terrier` / `rat-terrier` (SL `rat-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 790 — v izbirniku za basenjijem |
| Vir podatkov | `docs/research/dog-data/data.json` → `rat_terrier` (viri S647–S651; ponovno S8, S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: Rat Terrier Club of America (K39) in AKC (K38) |
| Izvor | ameriška pasma, AKC skupina terierjev (standard ratificiran 2009; S650, S651); **standarda FCI nisem našel** (pasma ni pri RKC; v registru ni podatka FCI) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 81. mesto (S174, prebrano 2026-11-02) |
| PR-ji | pet-prep #514 (pasma) · portret: glej spodaj · spletna stran: šele s portretom |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-02 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **30 min/dan → 3.000 korakov**; starejši 23 min → 2.300; mladiček 10 min × starost do 30 (pri 3 mesecih) | Rat Terrier Club of America S650 »need at least 30 minutes of exercise a day« (»at least N« → N). Strani RKC / PDSA za to ameriško pasmo ni, zato je pasemski klub najmočnejši vir s številko. WebMD S649 »at least 20 to 30 minutes« → 20 (alternativa) | #514 |
| 2026-11-02 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 (za vse pse) | #514 |
| 2026-11-02 | **predlog Claude — čaka Davida** | starejši od **108. meseca** (tudi prihod starejšega 108; `verified: false`) | 0,75 × spodnja meja VCA »12 - 16 years« (S647; tudi PetMD S648 »12–18 years«) = 9 let. Mediane McMillan 2024 / VetCompass ni (britanski podatki, pasma pri RKC ni registrirana) → najbližja vrstica pravila (precedens basenji, aljaški malamut). Ni izbrano: 135 (spodnja meja 15 let kluba S650 / WebMD S649), 84 (Dogs Trust »> 7 let«) | #514 |
| 2026-11-02 | pravilo runbooka | učenje **1,0×** | Coren pasme ne razvršča (S35) → pravilo »unranked« | #514 |
| 2026-11-02 | pravilo runbooka | teža **4,5–11,3 kg** (10–25 lb, oba spola, obe velikosti), višina 25–46 cm (10–18 in, mini in standard), rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | PetMD S648 »This is a relatively small dog that reaches only 10–25 pounds, both in the standard and the miniature size.«; standard AKC (S650) teže ne navaja; WebMD S649 8–35 lb (alternativa); PetMD S10 (razred »Small«) | #514 |
| 2026-11-02 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | VCA S647 »Brushing once a week will remove dead hair.«; PetMD »every week or so« in WebMD »once a week« se ujemata | #514 |
| 2026-11-02 | pravila »Breed care tasks« (David 2026-10-24, M5-R19) | otrokova mini igra **kremplji 1× na teden**; staršem opomnik **kopanje vsake 4 tedne** | PetMD S648 »Pet parents need to keep up with weekly nail trims, …« (WebMD »monthly or as needed« — alternativa); WebMD S649 »a monthly bath is typically enough for most« (PetMD: ne pogosteje kot enkrat na mesec). Zobje: le »annual dental cleanings« pri veterinarju, ušesa brez rutine → brez naloge | #514 |
| 2026-11-02 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S648 »Early (and consistent!) socialization and training is a must.« | #514 |
| 2026-11-02 | pravilo runbooka (M5-R16) | **brez** opozorila po obroku | noben vir ne svetuje premora po obroku | #514 |
| 2026-11-02 | pravilo runbooka | oznake: primeren za »družino z otroki« (`children`); upoštevajte »potrebuje miselne izzive«, »rad ima družbo«, »izpada mu dlaka«, »rad je — pazite na težo« | RTCA S650 »Rat Terriers are good with children, especially if they are raised with them from puppyhood.« (VCA S647 »Playful and very good with children.«; opozorilo o nadzoru ostane v opombah); PetMD S648 »Ratties need plenty of physical and mental stimulation«, »this is not a breed that enjoys being left alone for long periods«, »they shed mildly throughout the year, …«, »Rat Terriers tend to be good eaters, …« in »prone to obesity« | #514 |
| 2026-11-02 | pravilo runbooka | **brez** `apartment` | RTCA »do ok in an apartment« ni ključni podatek RKC »Flat/ Apartment« — pravilo dovoli čip le iz RKC | #514 |
| 2026-11-02 | pravilo runbooka | **brez** `chases_small_pets` | PetMD »their natural prey drive reflects that« in VCA »especially ill-advised around rodents« ne imenujeta »high / strong prey drive« — pravilo (David 2026-10-12) zahteva dobesedno navedbo; dejstvo ostane v besedilu registra | #514 |
| 2026-11-02 | pravilo runbooka | **brez** `family_pet`, `small_children`, `active_family`, `long_daily_exercise`, `large_home`, `low_shedding`, `shorter_lifespan`; nikoli »hypoallergenic« | ni izjave o družinskem psu; »raised with them« je pogoj; 30 min je pod mejo 2 ur; izpada mu dlaka; 12–16 let | #514 |
| 2026-11-02 | pravilo runbooka (§3) | **brez čipa dobrobiti**; marketing: **v aplikaciji da, v marketingu da** (nikoli obljuba zdravja; nikoli kot pes za dom z glodavci ali malimi živalmi; nikoli s kupiranim repom) | ni plosko-obrazna pasma (BVA S92 ne velja); pasma ni pri RKC (brez Breed Watch) | #514 |
| 2026-11-02 | pravilo runbooka (§3) | barve za AI in izbirnik (vse »pied« — lise barve na belem): **trobarven črn-rjavkast-bel (portret)**, rdeče-bel, čokoladno-rjavkasto-bel, črno-bel, marelično-bel, limonasto-bel, modro-rjavkasto-bel, plavo-bel; nikoli tigrast, merle ali enobarven brez bele; **naravni dolgi rep** | standard AKC S650 »Acceptable colors with or without "tan points", include the predominate Black: or Chocolate, Red, Apricot, Blue, Fawn, Tan, Lemon, or White.«, »Brindle or Merle color patterns are disqualifications.«; rep je po standardu lahko kupiran, naravno kratek ali naravno dolg — portret kaže naravnega (precedens doberman); uteži brez vira → `verified: false` | #514 |
| 2026-11-02 | pravilo runbooka (§2.3) | seznam za vzreditelja: **druga shema** — pregled srca, kolkov, pogačic, DNA test za primarno luksacijo leče | RTCA Health Statement 2025 (K39) »Cardiac Evaluation«, »Hip Dysplasia«, »Patellar Luxation«, »Primary Lens Luxation (DNA)«; AKC (K38) navaja iste štiri; brez novih ključev | #514 |
| 2026-11-02 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia`, `kneecap_luxation`, `legg_perthes`, `primary_lens_luxation`, `weight_gain`, `skin_allergies`; brez odstotkov | PetMD S648 | #514 |
| 2026-11-02 | predlog Claude, David lahko spremeni | EN »Rat Terrier«, SL »Rat terier«; iskanje »rat terrier«, »rat terier«, »rattie«, »ratty«, »american rat terrier«, »ameriški rat terier«, »terier za podgane« | slovenskega imena nisem našel; strani KZS nisem preverjal | #514 |
| 2026-11-02 | Claude (izvedba po pravilih runbooka) | izvoz registra: nova možnost `fci_standard: false` v `DOG_FACTS` (brez podatka FCI, kot `coren_rank: false` pri havanskem bišonu) | prva pasma brez standarda FCI; izvoz sicer zahteva »FCI No. …« | #514 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »rat terier«, »rattie« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-85 | 2026-11-02 |
| Pravila po starosti (2.000 → 3.000 korakov pri 3 mesecih, odrasel 3.000, starejši 2.300 od 108. meseca — predlog; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-85 | 2026-11-02 |
| Česanje 1× na teden, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-02 |
| Mini igra kremplji (1× na teden), opomnik staršem kopanje (vsake 4 tedne) | 🛠 | 💶 izziv | M5-R19 | 2026-11-02 |
| Barva (izbirnik: 8 standardnih barv) | 🛠 | 💶 izziv | M5-R21 | 2026-11-02 |
| AI portret v registru | 🗓 glej »Odprto za Davida« | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/rat-terrier`, `/sl/zivali/psi/rat-terier`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal rat terierja — majhnega, mišičastega ameriškega terierja, vzrejenega za lov na podgane in delo na kmetiji; 81. najbolj priljubljeno pasmo po AKC 2024 in 78. plačljivo pasmo psa. 5 novih virov (S647–S651). Odrasel pes v igri potrebuje 3.000 korakov na dan (pasemski klub: vsaj 30 minut gibanja), česanje enkrat na teden, otrok mu enkrat na teden pristriže kremplje, starši pa ga enkrat na mesec okopajo.
**Zakaj je pomembno:** Rat terier je majhen in z otroki prijazen, a poln energije: potrebuje miselne izzive, ne mara biti dolgo sam, izpada mu dlaka in rad je, zato je treba paziti na težo. PetPrep pokaže, ali otrok vsak dan zmore sprehod, igro in skrb — tudi tedenske kremplje — 12 tednov, preden pride pravi pes.
**Kako povedati** — starši: »Rat terier je po pasemskem klubu dober z otroki, posebej če z njimi odrašča, a potrebuje vsaj pol ure gibanja na dan, veliko miselnih izzivov in družbo. V PetPrepu boste videli, ali vaš otrok zmore takšen ritem.« · otroci: »Rat terier je majhen, hiter terier — vsak dan greš z njim na sprehod, enkrat na teden ga počešeš in mu pristrižeš kremplje, pa paziš, da ne poje preveč.« · vlagatelji / partnerji: »Oseminsedemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj; prva pasma brez standarda FCI.«

## Odprto za Davida

- **Starejše obdobje (predlog 108):** za to ameriško pasmo ni mediane McMillan 2024 / VetCompass in ni strani RKC; uporabljena je spodnja meja VCA »12 - 16 years« (`verified: false`, `PROPOSED_R10_RAT_TERRIER_SENIOR`). Pasemski klub in WebMD navajata 15–18 let (→ 135). Potrdite ali izberite.
- **Gibanje 30 min:** vir je pasemski klub (Rat Terrier Club of America, S650, uvrščen kot B), ker RKC / PDSA strani ni. Najnižji cilj korakov v aplikaciji (3.000, kot havanski bišon). Če želite drugačen vir, povejte.
- **Kremplji 1× na teden:** PetMD »weekly nail trims«, WebMD »monthly or as needed« — vzet je PetMD (brez pogoja). Če želite 1× na 4 tedne, povejte.
- **Brez standarda FCI:** strani FCI nisem preverjal; v registru ni podatka »FCI št.«. Izvoz ima novo možnost `fci_standard: false`. Če FCI pasmo vodi (npr. začasno), dopolnite identiteto.
- **Ime:** »Rat terier« je predlog (slovenskega imena nisem našel). Potrdite ali popravite.
- **Dve velikosti:** standard AKC pozna mini (10–13 in) in standard (13–18 in); igra ju vodi kot eno pasmo (višina 25–46 cm), portret kaže standardno velikost.
- **Ugotovitev za skupne dokumente (ne urejam jih):** prva pasma brez standarda FCI (`fci_standard: false` v izvozu); spletna stran bo pasmo pokazala brez vrstice »FCI«.
