# Škotski terier (Scottish Terrier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-58 · 2026-10-23

| | |
|---|---|
| Ključ / slug | `scottish_terrier` / `scottish-terrier` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 540 — v izbirniku za samojedom |
| Vir podatkov | `docs/research/dog-data/data.json` → `scottish_terrier` (viri S472–S476; ponovno S8, S10, S11, S14, S24, S35, S45, S54, S123, S174) |
| Izvor | FCI št. 73, skupina 3, sekcija 2 (mali terierji), Velika Britanija (S472) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 54. mesto »Scottish Terrier« (S174) |
| PR-ji | pet-prep #348 (pasma, združen `aecd0bb`) · portret #350 (združen `2425100`) · spletna stran pet-prep-website #92 (združen `2cceba7`) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-23 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | PDSA S474 »Minimum exercise (per day) 1 hour« in »Scotties need around an hour of exercise daily.« (»minimum N« → N). Stran RKC (prednostni vir) ni bila dosegljiva | #348 |
| 2026-10-23 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36 mesecev; prihod 2 / 9 / 36 | AAHA S11, PetMD S10 (enako kot pri drugih psih) | #348 |
| 2026-10-23 | **predlog Claude — čaka Davida** (`verified=false`) | starejši od **90 mesecev** (tudi starost ob prihodu starejšega psa) | 0,75 × spodnja meja PDSA »Over 10 years« (S474) = 7,5 leta. Nobena vrstica pravila ni dala vrednosti: tabela Dogs Trust z medianami McMillan 2024 (S123) in stran RKC nista bili dosegljivi, članek McMillan 2024 (S54) ima pasmo le v dodatni tabeli 3, mediane VetCompass ni → najbližja vrstica (precedens samojed). Alternative: 108 (PetMD »around 12 years«, S475), 114 (McMillan 2024 12,7 leta le prek Wikipedije, S476, tier C), 84 (Dogs Trust > 7 let, S14) | #348 |
| 2026-10-23 | pravilo runbooka | učenje **0,7×** (Coren rang 65, skupina »Fair«) — uči se počasneje od mešančka | S35 (»\| 65 \| Scottish Terrier \|«) | #348 |
| 2026-10-23 | pravilo runbooka | teža 8,5–10,5 kg, višina 25–28 cm, rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | standard Kennel Cluba S473 (»8.5kg – 10.5kg.«, »25cm – 28cm«); PDSA S474 se ujema; PetMD S10 (razred »Small«) | #348 |
| 2026-10-23 | pravilo runbooka (M5-R13) | česanje **vsak dan** (7× na teden; zmršena dlaka po 4 zamujenih) | PDSA S474 »Scotties need daily grooming and will require stripping and clipping by a professional groomer every two-three months.« → 7; PetMD S475 »every few days« (brez števila) je alternativa | #348 |
| 2026-10-23 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S475 »Socializing your Scottish Terrier puppy is important for having a well-mannered, confident dog.« | #348 |
| 2026-10-23 | pravilo runbooka (M5-R16) | **brez** opozorila po obroku | noben vir ne svetuje premora med obrokom in gibanjem (citata ne izmišljujemo) | #348 |
| 2026-10-23 | pravilo runbooka | oznake: družinsko življenje (`family_pet`); upoštevajte: potrebuje česanje večkrat na teden (`frequent_grooming`), rad ima družbo (`needs_company`) | PetMD S475 »With the right training and socialization, Scottish Terriers can be good dogs for families.«, PDSA S474 »loyal and devoted to their family«; PDSA S474 vsakodnevna nega; PetMD S475 »you shouldn’t leave your dog alone for extended periods« | #348 |
| 2026-10-23 | pravilo runbooka (nasprotujoča si vira) | **brez** `other_pets` in **brez** `chases_small_pets` | PDSA S474 »get on well with other pets« proti PetMD S475 »a strong prey drive« → nobena oznaka (pravilo runbooka) | #348 |
| 2026-10-23 | pravilo runbooka | **brez** oznak `children`, `small_children`, `active_family`, `long_daily_exercise`, `apartment`, `large_home`, `sheds`, `low_shedding`, `secure_fencing`, `independent_experienced_owner`, `shorter_lifespan`; nikoli »hipoalergen« | o otrocih ni izjave A/B; ena ura gibanja ni »veliko«; stran RKC ni dosegljiva (velikost doma); o izpadanju dlake ni vira; »notorious diggers« (PetMD) ni izjava o pobegih; trmast, a noben vir ne pravi »za izkušenega lastnika«; PDSA »Over 10 years« | #348 |
| 2026-10-23 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | nobeno združenje iz pravila (BVA, UK BWG, UFAW, RVC/VetCompass, RKC) pasme ne navaja zaradi težav telesne zgradbe; kategorija Breed Watch ni znana (stran RKC ni dosegljiva). PDSA omenja težke porode (široka ramena) — zapisano le kot ozadje | #348 |
| 2026-10-23 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot hipoalergen pes, pes, ki se ne lisa, pes brez nege ali pes, ki je lahko dolgo sam | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #348 |
| 2026-10-23 | pravilo runbooka (§3) | barve za AI in izbirnik: **črn** (portret), tigrast, pšenično rumen; nobena druga barva, brez belih lis | standard Kennel Cluba S473 »Black, wheaten, or brindle of any shade.« in »Any other colour or combination of colours unacceptable.« (uteži barv brez vira → `verified: false`) | #348 |
| 2026-10-23 | predlog Claude, David lahko spremeni | ime SL »škotski terier« (slug `skotski-terier`); iskalni ključi tudi »scottie«, »skotski terier«, »aberdeen terrier« | slovenskega vira za ime ni bilo mogoče najti; KZS ni bil preverjen | #348 |
| 2026-10-23 | pravilo runbooka (§8) | AI portret: realistična fotografija odraslega psa, črna trda žičnata dlaka z brado, obrvmi in dlako na nogah, pokončni koničasti uhlji, rep pokonci, temne oči, siva studijska podlaga, naravna razmerja; sprejet v prvem poskusu (zagon 39887587278, koda `aecd0bb`, brez produkcijske namestitve) | barva portreta = najtežja barva iz `breed_appearance.php` | #350 |
| 2026-10-23 | pravilo runbooka (§2.3) | seznam za vzreditelja (vse »other_scheme«): DNK test von Willebrandove bolezni tip III, pregled pogačice, test kraniomandibularne osteopatije (CMO) | ameriški klub pasme — obvezni testi CHIC 2026 (K34), AKC (K33). Nov ključ testa `dna_cmo` | #348 |
| 2026-10-23 | pravilo runbooka | nov zdravstveni ključ registra **`scottie_cramp`** (»škotski krč«) — mirna poved brez številk; poleg tega `bleeding_disorder_vwd`, `kneecap_luxation`, `heart_muscle_disease`, `cataracts_lens_luxation`, `itchy_skin_allergy`, `deafness`. Rak sečnega mehurja ni zapisan (le Wikipedija s faktorjem tveganja) | PDSA S474 (seznam bolezni); PetMD S475 | #348 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »škotski terier«, »scottie« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-58 | 2026-10-23 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 90 mesecev — meja čaka Davida; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-58 | 2026-10-23 |
| Česanje vsak dan (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-23 |
| Barva (izbirnik barve: črn, tigrast, pšenično rumen) | 🛠 | 💶 izziv | M5-R21 | 2026-10-23 |
| AI portret v registru (črn, trimana brada in obrvi, pokončni uhlji; sprejet v prvem poskusu, zagon 39887587278) | ✅ | — | M5-R11-04 | 2026-10-23 |
| Stran na petprep.si (`/animals/dogs/scottish-terrier`, `/sl/zivali/psi/skotski-terier`) s portretom — oznaka »Kmalu«, dokler pasma ni v izdaji aplikacije | ✅ (stran) | — | M5-R11 | 2026-10-23 |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal škotskega terierja — 54. najbolj priljubljeno pasmo po AKC 2024 in 54. plačljivo pasmo psa. 5 novih virov (S472–S476) in 2 nova vira za vzreditelje (K33, K34). Odrasel pes v igri potrebuje 6.000 korakov na dan (PDSA: približno ura gibanja), starejši 4.500; uči se počasneje od mešančka (Coren: 65. mesto, ×0,7). Vsakodnevno česanje in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** »Scottie« je majhen in se zdi nezahteven, a potrebuje nego vsak dan in trimanje pri frizerju na dva do tri mesece, je trmast pri učenju in ne mara biti dolgo sam. 12 tednov PetPrepa pokaže, ali družina zmore vsakodnevno nego in potrpežljivo šolanje.
**Kako povedati** — starši: »Škotski terier ne potrebuje dolgih tekov, potrebuje pa nego vsak dan, potrpežljivo učenje in družbo. V PetPrepu boste videli, ali to zmore vaša družina 12 tednov zapored.« · otroci: »Škotski terier je majhen, pogumen pes z brado. Vsak dan ga počešeš in greš z njim na sprehod — in ne obupaš, če se trik nauči malo počasneje.« · vlagatelji / partnerji: »Štiriinpetdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Rešeno 24. 10. 2026 (David, M5-R14 / R15 / R18 / R19 / R20 — PR #PRNUM):** naloga samo, kjer vir navaja pogostost (razpon → spodnja meja); mini-igre otroka po modelu česanja (pošten delež, reže, poročilo, brez kazni), pasji frizer in kopanje = opomnik staršu, ne šteje v oceno; samo novi psi. Za to pasmo — zgrajeno: pasji frizer na 8 tednov (opomnik staršu, ne šteje v oceno). Spodnje točke M5-R14 / R15 / R18 / R19 / R20 so s tem zaprte.

- **Meja starejšega psa (90 mesecev, `verified=false`):** predlog Claude po spodnji meji PDSA »Over 10 years« — tabele McMillan 2024 (Dogs Trust S123) ta zagon ni mogel odpreti. Preverite vrstico za škotskega terierja; Wikipedija (tier C) navaja 12,7 leta → 114. Sprememba = urejanje v Filamentu ali podatkovna migracija (sejalnik le vstavlja).
- **Potreba brez mehanike:** trimanje / striženje pri pasjem frizerju na 2–3 mesece (PDSA S474, PetMD S475) — ROADMAP M5-R18.
- **Druge živali / lovski nagon:** PDSA pravi, da se dobro razume z drugimi ljubljenčki, PetMD navaja močan lovski nagon → po pravilu brez oznake. Ali želite eno od oznak?
- **Velikost:** PDSA ga uvršča med »srednje« pse (`size: medium`), po teži (8,5–10,5 kg) pa sodi v razred »Small« — preverite, ali naj filter velikosti na spletni strani ostane »srednji«.
- **Stran RKC:** ni bila dosegljiva (velikost doma, kategorija Breed Watch, testi za vzreditelja po RKC) — ponovno preverite, ko bo dosegljiva.
- **Ime:** »škotski terier« ni preverjeno pri KZS (slovenskega vira ni bilo).
