# Tibetanski španjel (Tibetan Spaniel)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-130 · 2026-11-20

| | |
|---|---|
| Ključ / slug | `tibetan_spaniel` / `tibetan-spaniel` (SL `tibetanski-spanjel`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1210 — v izbirniku za ameriškim golim terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `tibetan_spaniel` (viri S980–S983; ponovno S10, S11, S14, S24, S35, S45, S174, S771); seznam za vzreditelja: ni preverjen (stran RKC ni bila dosegljiva) |
| Izvor | FCI št. 231, skupina 9, sekcija 5 (tibetanske pasme), Tibet (Kitajska), pokroviteljstvo Velika Britanija (S980) — po FCI pes družabnik, ne španjel po tipu |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 126. mesto »Tibetan Spaniel« (S174, zapisano 2026-11-20 v vrstici 129 čakalne vrste) |
| PR-ji | pet-prep #1435 (pasma, združen `8972a21`) · portret pet-prep (zagon `breed-portraits.yml` 44665785472, sprejet v prvem poskusu) · barvne različice pet-prep (zagon `breed-portraits.yml` 44667389260, 5/5 odobril Claude) · spletna stran: pet-prep-website `feat/register-tibetan-spaniel` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-20 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | stran RKC ni bila dosegljiva → po pravilu PDSA: S981 »Tibetan Spaniels need around an hour of exercise every day …« (»around N« → N; ključni podatek »1 hour« se ujema); WebMD S982 »at least one hour of exercise every day« enako; PetMD S983 »minimal« (brez števila) | #1435 |
| 2026-11-20 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **137**; prihod 2 / 9 / 36 / 137 | AAHA S11; starejši = 0,75 × mediana McMillan 2024 **15,2 leta** (S771, »Tibetan Spaniel (x̃ = 15.2 …)«) = 136,8 → 137 mesecev — prva izbira runbooka, potrjeno. Ni izbrano: 84 (Dogs Trust »> 7 let«) | #1435 |
| 2026-11-20 | pravilo runbooka | učenje **1,0×** | Coren 46. mesto »Tibetan Spaniel« (deljeno z angleškim lisičarjem, hrtom …), skupina »Average« (40–54) (S35) | #1435 |
| 2026-11-20 | pravilo runbooka | teža **4,1–6,8 kg** (oba spola — FCI »Ideal«), višina okoli 25,4 cm (FCI), rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S980 »Weight: Ideal 4,1 to 6,8 kg.«, »Height at the withers: About 25,4 cm.«; PDSA S981 4–7 kg, 25 cm; PetMD S10 (razred »Small«) | #1435 |
| 2026-11-20 | pravilo runbooka (M5-R13) | česanje **2× na teden**; skuštrana dlaka po 1 zamujenem | PDSA S981 »Grooming a couple of times per week is usually enough …« (»a couple« → 2, precedens border terier); WebMD »Regular«, PetMD »occasionally« brez števila | #1435 |
| 2026-11-20 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S983 »Begin socializing your Tibetan Spaniel puppy as soon as you bring them home.« | #1435 |
| 2026-11-20 | pravila »Breed care tasks« (David 2026-10-24, M5-R15 / R18 / R19 / R20) | **ščetkanje zob vsak dan**, **pregled ušes enkrat na teden**, **kremplji vsake 4 tedne** (otrokove mini igre), **kopanje vsake 4 tedne** (opomnik staršem) | WebMD S982 »brushing their teeth daily …«, »checking their ears for debris at least once a week«, »check if they need a trim at least once monthly«, »giving them a bath roughly every month«; PetMD S983 česanje resic na ušesih »once a week« se ujema. Frizer: PetMD »at home«, brez pogostosti → brez naloge | #1435 |
| 2026-11-20 | pravilo runbooka | brez opozorila po obroku in brez poletne prepovedi sprehoda | noben vir ne navaja premora po obroku; ni ploskoobrazna pasma (FCI: gobček »Medium in length«) — vročina je le čip | #1435 |
| 2026-11-20 | pravilo runbooka | oznake: primeren za **»aktivno družino«** in **»družinsko življenje«**; upoštevajte **»rad ima družbo«**, **»bolje za starejše otroke«**, **»potrebuje varno ograjo«**, **»slabo prenaša vročino«** | PDSA S981 (okoli ure gibanja, »will happily join in with family games«; »will make good family pets«); WebMD S982 »prone to separation anxiety«, »better suited for families with older children«, »a secure fenced yard«, »Hot weather can trigger overheating and respiratory problems« (`needs_company`, `older_children`, `secure_fencing` — v deljenem testu `BreedSuitabilityTest` dodan na sezname izjem, kot pri drugih pasmah s temi čipi) | #1435 |
| 2026-11-20 | pravilo runbooka | **brez** `children` / `small_children` (WebMD le starejši otroci), `apartment` (PetMD »house or apartment«, a ključnih podatkov RKC ni bilo), `other_pets` (PetMD le ob socializaciji), `chases_small_pets` (ni vira o lovskem nagonu), `frequent_grooming`, `sheds` / `low_shedding` (ni vira); nikoli »hypoallergenic« | S981–S983 | #1435 |
| 2026-11-20 | pravilo runbooka (§3, dobrobit) | nobeno veterinarsko združenje pasme ne navaja zaradi težav s telesno zgradbo → brez opozorilnega čipa; **v aplikaciji da, v marketingu da**; kategorija RKC Breed Watch ni preverjena | S92 (le ploskoobrazne pasme); stran RKC ni bila dosegljiva | #1435 |
| 2026-11-20 | pravilo runbooka (§3) | barve za AI in izbirnik: **zlat** (privzeto za portret), rdeč, sobolji, kremast, črn z rjavimi oznakami, črn — FCI dovoli vse barve razen merle; šest pogostih barv izbral Claude; naravna, nepostrižena dlaka | FCI S980 »All colours and mixture of colours permissible, except merle which is unacceptable.«; uteži brez vira → `verified: false` | #1435 |
| 2026-11-20 | pravilo runbooka (§2.3) | seznam za vzreditelja: **ni preverjen** (`not_checked`) — razdelek RKC o testih ni bil dosegljiv | runbook: brez vnosa → `not_checked` | #1435 |
| 2026-11-20 | pravilo runbooka | zdravstveni ključi registra: `kneecap_luxation`, `pra_inherited_blindness`, `hip_dysplasia`, `entropion`, `extra_eyelashes`; brez odstotkov, brez novega ključa (češnjevo oko, jetrni šant, kila, alergije ostanejo v data.json) | PDSA S981, WebMD S982, PetMD S983 | #1435 |
| 2026-11-20 | predlog Claude, David lahko spremeni | EN »Tibetan Spaniel«, SL »Tibetanski španjel«; iskanje tudi »tibbie«, »tibetanski spanjel« | strani KZS nisem dosegel | #1435 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »tibetanski španjel«, »tibbie« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-130 | 2026-11-20 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 137. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-130 | 2026-11-20 |
| Česanje 2× na teden, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-20 |
| Ščetkanje zob vsak dan, ušesa vsak teden, kremplji vsake 4 tedne (otrok); opomnik za kopanje vsake 4 tedne (starši) | 🛠 | 💶 izziv | M5-R15 / R18 / R19 / R20 | 2026-11-20 |
| Barve (izbirnik: 6 barv; fotografije za vse — zlat (portret), rdeč, sobolji, kremast, črn z rjavimi oznakami, črn) | 🛠 | 💶 izziv | M5-R21 | 2026-11-20 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), zlat, grivast vrat, košat rep zavihan čez hrbet, zmeren gobček | — | M5-R11-04 | 2026-11-20 |
| Stran na petprep.si (`/animals/dogs/tibetan-spaniel`, `/sl/zivali/psi/tibetanski-spanjel`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal tibetanskega španjela — majhnega (4–7 kg), živahnega psa družabnika iz Tibeta s svilnato dlako, 126. najbolj priljubljeno pasmo po AKC 2024 in 121. pasmo psa v aplikaciji (poleg mešančka). 4 novi viri (S980–S983). Odrasel pes v igri potrebuje 6.000 korakov na dan (PDSA: okoli ene ure gibanja), česanje 2× na teden, vsak dan ščetkanje zob, vsak teden pregled ušes, na 4 tedne kremplje in kopanje.
**Zakaj je pomembno:** Je ena najdlje živečih pasem v britanski raziskavi McMillan 2024 (mediana 15,2 leta) — otrok z njim podpiše obljubo za 15 let in več. Rad ima družbo in ga ne gre puščati samega (WebMD: nagnjen k ločitveni tesnobi), bolje se ujame s starejšimi otroki in ne mara vročine.
**Kako povedati** — starši: »Tibetanski španjel je majhen, a ni igrača: uro gibanja na dan, redna nega in veliko družbe — in lahko živi več kot 15 let. V PetPrepu boste videli, ali vaš otrok zmore ta ritem.« · otroci: »Tibetanski španjel ima grivo kot majhen lev in košat rep — vsak dan mu umiješ zobe, dvakrat na teden ga počešeš in greš z njim na sprehod.« · vlagatelji / partnerji: »121. pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal z viri — ko RKC ni bil dosegljiv, je po pravilu uporabil PDSA, za življenjsko dobo pa recenzirano raziskavo McMillan 2024.«

## Odprto za Davida

- **Barvne različice:** vseh 5 (rdeč, sobolji, kremast, črn z rjavimi oznakami, črn) je 2026-11-20 odobril Claude — isti pes, poza in ozadje kot portret; nobena barva ne čaka Davida.

- **Barve:** standard FCI dovoli vse barve razen merle; za AI in izbirnik sem izbral šest pogostih (zlat, rdeč, sobolji, kremast, črn z rjavimi oznakami, črn). Povejte, če želite drugačen nabor.
- **Stran RKC:** ključni podatki RKC (velikost doma, izpadanje dlake, Breed Watch) in testi za vzreditelje niso bili dosegljivi (preusmeritev na royalkennelclub.com) → seznam za vzreditelja je »ni preverjen«; ob ročnem pregledu dopolnite.
- **Kremplji:** WebMD »check if they need a trim at least once monthly« sem prebral kot nalogo vsake 4 tedne (kot pri kitajskem čopastem psu, kjer je tedenski pregled postal tedenska naloga). Povejte, če naj bo brez naloge.
- **Ime:** »Tibetanski španjel« ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
