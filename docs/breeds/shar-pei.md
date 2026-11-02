# Šarpej (Shar Pei)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-87 · 2026-11-02

| | |
|---|---|
| Ključ / slug | `shar_pei` / `shar-pei` (SL `sarpej`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 810 — v izbirniku za velikim švicarskim planšarskim psom |
| Vir podatkov | `docs/research/dog-data/data.json` → `shar_pei` (viri S660–S668; ponovno S8, S10, S11, S14, S24, S35, S45, S92, S174); seznam za vzreditelja: stran RKC (S664) |
| Izvor | FCI št. 309, skupina 2, sekcija 2.1 (molosi, mastifski tip), Kitajska (S662, S663); v ZDA »Chinese Shar-Pei« (S174) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 83. mesto »Chinese Shar-Pei« (S174, prebrano 2026-11-02) |
| PR-ji | pet-prep #529 (pasma) · portret: glej spodaj · spletna stran: šele s portretom |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-02 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | stran RKC S664 »Exercise: Up to 1 hour per day« (»up to N« → N); PDSA S660 »Your Shar Pei will need a minimum of an hour exercise every day.« (prav tako 60) | #529 |
| 2026-11-02 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **66**; prihod 2 / 9 / 36 / 66 | AAHA S11; starejši = 0,75 × mediana VetCompass **7,28 leta** (O'Neill in sod. 2023, S667, 190 poginulih psov) = 65,52 → 66. McMillan 2024 ni dosegljiv → drugi vir pravila (začasno). Ni izbrano: 90 (RKC / PDSA »Over 10 years«), 84 (Dogs Trust »> 7 let«) | #529 |
| 2026-11-02 | pravilo runbooka | učenje **1,0×** | Coren: 51. mesto »Shar Pei«, skupina »Average« (40–54) (S35, preverjeno 2026-11-02) | #529 |
| 2026-11-02 | pravilo runbooka | teža **20–27 kg** (oba spola), višina 46–51 cm (standard RKC), rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S660 »20-27 kg« (VCA 45–60 lb se ujema); standarda FCI (S663) in RKC (S665) teže ne navajata; FCI višina 44–51 cm (alternativa); PetMD S10 (razred »Medium«) | #529 |
| 2026-11-02 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | RKC S664 »Grooming: Once a week«; PDSA »A weekly brush …«, VCA »The coat needs brushing every week …« se ujemata | #529 |
| 2026-11-02 | David 2026-10-12 (M5-R16) | **opozorilo »počakaj s sprehodom« 60 min po obroku** (koraki se štejejo, brez kazni) | PDSA S660 »Remember to leave a gap between eating and exercising.« | #529 |
| 2026-11-02 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PDSA S660 »socialise your Shar Pei from a young age with lots of different people, experiences and other dogs« | #529 |
| 2026-11-02 | pravila »Breed care tasks« (David 2026-10-24) | **brez** mini iger in opomnikov za nego (gube, ušesa, zobje, kremplji, kopanje) | PDSA: gube »kept clean and dry«, ušesa »regularly monitor« — brez pogostosti; »you're unlikely to need to give them a bath«; VCA »Wrinkles should be examined regularly« — brez pogostosti → ni naloge (dejstvo ostane v registru) | #529 |
| 2026-11-02 | pravilo runbooka | oznake: primeren za »družinsko življenje« (`family_pet`); upoštevajte **»samosvoj pes — bolje za starejše otroke« (nova oznaka `independent_older_children`)**, »izpada mu dlaka«, »krajši gobček — lahko težje diha«, »veke in oči — preverite preglede oči«, »občutljiva koža«, »krajša življenjska doba«, »močan lovski nagon« | PDSA S660 »Shar Peis are devoted and loyal family pets and can get along well with older children given the right socialisation.« + »We wouldn't recommend getting a Shar Pei if you have younger children« (samostojni psi); RKC S664 »Sheds: Yes«; PDSA BOAS »… caused by their flatter face.«; RKC Breed Watch S666 »Eyelids not in normal contact with the eyeball …«, dermatitis v gubah; VetCompass S667 / VCA S661 »8 - 10 years«; PDSA »Shar Peis have a really high prey drive …« | #529 |
| 2026-11-02 | pravilo runbooka | **brez** `independent_experienced_owner` (glej »Odprto za Davida«), `children`, `small_children`, `apartment`, `large_home`, `needs_company`, `heat_sensitive`, `brachycephalic_breathing`, `food_motivated_weight`; nikoli »hypoallergenic« | PDSA: le starejši otroci; VCA »Good With Children: 1 out of 5«; RKC »Small house«; štiri ure je splošno pravilo PDSA; VCA »Becomes overheated fairly easily.«, a čip `heat_sensitive` govori o gostem kožuhu (šarpej ima kratko dlako); noben vir ne povezuje obraza z vročino | #529 |
| 2026-11-02 | pravilo runbooka (§3, dobrobit) | **pasma s skrbmi za dobrobit** (RKC Breed Watch kategorija 2: veke, dermatitis v gubah, »tight lip«; PDSA: BOAS zaradi bolj ploskega obraza) → čipi dobrobiti; marketing: **v aplikaciji da, v marketingu / posnetkih zaslona ne** (BVA S92 »avoiding imagery of brachycephalic dogs in advertising …«) | S666, S660, S92; VetCompass S668 »Urgent action is needed to reform the breed …« | #529 |
| 2026-11-02 | pravilo runbooka (§3) | barve za AI in izbirnik: **enobarvne — plavo rjava (fawn), rdeča, kremasta, črna, čokoladna, modra**; portret plavo rjav; zmerne gube le na čelu in vihru, oči odprte | FCI S663 »All solid colours acceptable except white.« (izključujoče: albino, tigrasta, lise, črno-rjava …); entropij in težke gube po telesu izključujejo (S663); izbor šestih barv in uteži brez vira → `verified: false` | #529 |
| 2026-11-02 | pravilo runbooka (§2.3) | seznam za vzreditelja: minimum očesna shema BVA/KC/ISDS; najboljša praksa DNK test POAG/PLL (**nov ključ `dna_poag_pll`**), sheme BVA/KC za komolce in kolke | stran RKC S664 | #529 |
| 2026-11-02 | pravilo runbooka | zdravstveni ključi registra: `entropion`, `skin_fold_ear_problems`, `itchy_skin_allergy`, `shorter_face_boas`, **`shar_pei_fever` (nov)**, `hip_elbow_dysplasia`; brez odstotkov | VetCompass S668 »The most common disorder of Shar Pei dogs was entropion (in-turned eyelids)«; PDSA S660 (gube, ušesa, atopija, družinska vročica šarpejev, BOAS); RKC S664 | #529 |
| 2026-11-02 | predlog Claude, David lahko spremeni | EN »Shar Pei«, SL »Šarpej«; iskanje tudi »chinese shar-pei«, »sharpei«, »šar pej«, »kitajski šarpej« | strani KZS nisem našel | #529 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »šarpej«, »sharpei« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-87 | 2026-11-02 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 66. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-87 | 2026-11-02 |
| Česanje 1× na teden, »Nova izkušnja« za mladička, opozorilo 60 min po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 | 2026-11-02 |
| Barva (izbirnik: 6 enobarvnih barv) | 🛠 | 💶 izziv | M5-R21 | 2026-11-02 |
| AI portret v registru | 🗓 glej »Odprto za Davida« | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/shar-pei`, `/sl/zivali/psi/sarpej`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal šarpeja — srednje velikega, kvadratnega psa s Kitajske s kratko, ščetinasto dlako in gubami; 83. najbolj priljubljeno pasmo po AKC 2024 in 80. plačljivo pasmo psa. 9 novih virov (S660–S668), med njimi recenzirana študija VetCompass (2023) o zdravju šarpejev. Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do ene ure gibanja), česanje enkrat na teden, mladiček vsak teden novo izkušnjo, po obroku pa aplikacija opozori, naj s sprehodom počaka.
**Zakaj je pomembno:** Šarpej je zvest, miren družinski pes, a samosvoj in trmast — PDSA ga priporoča izkušenim lastnikom in družinam s starejšimi otroki. Je pasma s skrbmi za dobrobit: najpogostejša težava so navznoter zavihane veke (entropij), gube je treba držati čiste in suhe, mediana življenjske dobe v britanski študiji je le 7,3 leta. PetPrep tega ne olepšuje — v izbirniku so čipi, starejše obdobje pa se v igri začne prej.
**Kako povedati** — starši: »Šarpej je po PDSA zvest družinski pes za izkušene lastnike in starejše otroke; ima občutljive oči, kožo in dihala ter krajšo življenjsko dobo. V PetPrepu boste videli, ali vaš otrok zmore vsakodnevno odgovornost.« · otroci: »Šarpej je miren, a ima svojo glavo — vsak dan greš z njim na sprehod, enkrat na teden ga počešeš, po jedi pa malo počakata.« · vlagatelji / partnerji: »Osemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, opozorili o dobrobiti, testi in pravili, brez novih vprašanj.« (v marketingu brez slik šarpeja — BVA)

## Odprto za Davida

- **Življenjska doba / starejše obdobje:** uporabljena je mediana VetCompass 7,28 leta (S667) → starejši od 66. meseca. RKC in PDSA pišeta »Over 10 years« (to bi dalo 90), VCA 8–10 let. McMillan 2024 ni dosegljiv; če tabela Dogs Trust (S123) pasmo vsebuje, preverite.
- **Čip `shorter_lifespan`:** dodan zaradi mediane VetCompass in zgornje meje VCA (10 let), čeprav RKC / PDSA navajata »Over 10 years« — po pravilu bi nasprotujoča vira lahko pomenila »brez čipa«. Potrdite.
- **Marketing:** izključen po pravilu BVA (S92), ker PDSA navaja BOAS zaradi bolj ploskega obraza; noben vir šarpeja izrecno ne imenuje »brahicefalen«. Če ga želite v marketingu, povejte.
- **Oznaka `independent_experienced_owner`:** PDSA »Shar Peis tend to do really well in an experienced household with owners used to the breed as they can be strong-willed.« bi ustrezala, a test šiba inuja določa, da jo ima samo šiba inu, zagon pa testov drugih pasem ne spreminja (CI je to zavrnil v prvem poskusu). Če jo želite tudi pri šarpeju, je treba popraviti `ShibaInuBreedTest`.
- **Vročina:** VCA »Becomes overheated fairly easily.« — brez čipa (`heat_sensitive` govori o gostem kožuhu); če želite nov čip ali besedilo, povejte.
- **Nega gub in ušes:** viri ne navajajo pogostosti → ni mini igre (pravilo); dejstvo je v registru.
- **Nova oznaka `independent_older_children`** (»samosvoj pes — bolje za starejše otroke«), **nov zdravstveni ključ `shar_pei_fever`** in **nov ključ testa `dna_poag_pll`** — besedila na spletni strani so v PR spletne strani.
- **Ime:** »Šarpej« je ustaljeno slovensko ime; strani KZS nisem našel. Potrdite ali popravite.
- **Barve:** standard dovoli vse enobarvne razen bele; izbor šestih barv (plavo rjava, rdeča, kremasta, črna, čokoladna, modra) in uteži sta moja izbira.
