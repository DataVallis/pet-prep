# Samojed (Samoyed)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-57 · 2026-10-22

| | |
|---|---|
| Ključ / slug | `samoyed` / `samoyed` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 530 — v izbirniku za Chesapeake Bay retriverjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `samoyed` (viri S463–S471; ponovno S10, S11, S14, S24, S35, S45, S54, S123, S174) |
| Izvor | FCI št. 212, skupina 5, sekcija 1 (nordijski vlečni psi), severna Rusija in Sibirija (S463) — nov izvor `RU` |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 53. mesto »Samoyed« (S174) |
| PR-ji | pet-prep #346 (pasma, združen `c5c6bc6`) · portret #347 (združen `46f8adf`) · spletna stran #91 (združen `cfab30b`) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-22 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | PDSA S467 »We recommend a minimum of two hours exercise every day for your Samoyed.« (»minimum N« → N). Stran RKC (prednostni vir) ni bila dosegljiva. Alternativi: Woodgreen S468 »90-120 minutes« (→ 90), PetMD S469 »at least two hours a day« | #346 |
| 2026-10-22 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36 mesecev; prihod 2 / 9 / 36 | AAHA S11, PetMD S10 (enako kot pri drugih psih) | #346 |
| 2026-10-22 | **predlog Claude — čaka Davida** (`verified=false`) | starejši od **90 mesecev** (tudi starost ob prihodu starejšega psa) | 0,75 × spodnja meja PDSA »Over 10 years« (S467) = 7,5 leta. Nobena vrstica pravila ni dala vrednosti: tabela Dogs Trust z medianami McMillan 2024 (S123) in stran RKC nista bili dosegljivi, članek McMillan 2024 (S54) ima pasmo le v dodatni tabeli 3, mediane VetCompass ni → najbližja vrstica (precedens mali ameriški ovčar). Alternative: 108 (PetMD / Woodgreen 12–14 let), 118 (McMillan 2024 13,1 leta le prek Wikipedije, S470, tier C), 84 (Dogs Trust > 7 let, S14) | #346 |
| 2026-10-22 | pravilo runbooka | učenje **1,2×** (Coren rang 33, skupina »Above average«) | S35 (»33 \| Samoyed«) | #346 |
| 2026-10-22 | pravilo runbooka | teža 16–29 kg, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S467 »16-29 kg« (noben standard ne navaja teže); Woodgreen S468 16–30 kg in PetMD S469 35–65 lb se ujemata; višina FCI S464 (psi 57 ± 3 cm, psice 53 ± 3 cm); PetMD S10 (razred »Medium«) | #346 |
| 2026-10-22 | pravilo runbooka (M5-R13) | česanje **vsak dan** (7× na teden; zmršena dlaka po 4 zamujenih) | PDSA S467 »They shed very often, but even when they’re not shedding they’ll need grooming on daily basis.« → 7; PetMD S469 »daily brushing« se ujema; Woodgreen S468 »several times a week« (brez števila) je alternativa | #346 |
| 2026-10-22 | David 2026-10-12 (M5-R16) | **opozorilo po obroku** (60 min »počakaj s sprehodom«, koraki se štejejo) | PDSA S467 »Remember to leave a gap after eating and before exercising.« | #346 |
| 2026-10-22 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S469 »As with all dog breeds, Samoyed puppies respond best to training and socialization early in life.« | #346 |
| 2026-10-22 | pravilo runbooka | oznake: aktivno družino (`active_family`), družinsko življenje (`family_pet`), družino z otroki (`children`); upoštevajte: vsak dan potrebuje veliko gibanja (`long_daily_exercise`), potrebuje miselne izzive (`needs_mental_stimulation`), rad ima družbo (`needs_company`), rad pobegne — potrebuje varno, visoko ograjo (`secure_fencing`), pri igri lahko »pase« otroke (`may_herd_children`), izpada mu dlaka (`sheds`), potrebuje česanje večkrat na teden (`frequent_grooming`), **gost kožuh — slabo prenaša vročino (`heat_sensitive`, nova oznaka)**, močan lovski nagon (`chases_small_pets`, na koncu) | PDSA S467 (gibanje, družina in otroci z nadzorom, največ štiri ure sam, »very accomplished escape artists«, »shed very often«, »grooming on daily basis«, »strong prey drive«); Woodgreen S468 (miselni izzivi, »round up« mlajše otroke); PetMD S469 (družina, »do not do well in hot temperatures«) | #346 |
| 2026-10-22 | pravilo runbooka (nova oznaka) | nova oznaka **`heat_sensitive`** (upoštevajte): SL »gost kožuh — slabo prenaša vročino«, EN »thick coat — struggles in hot weather« | nobena obstoječa oznaka ne opisuje vročine pri gostem kožuhu (`brachycephalic_breathing` / `short_muzzle_breathing` govorita o kratkem gobčku); PetMD S469, Woodgreen S468 | #346 |
| 2026-10-22 | pravilo runbooka | **brez** oznak `small_children`, `other_pets`, `apartment`, `large_home`, `low_shedding`, `first_time_owner`, `shorter_lifespan`, `experienced_owner`; nikoli »hipoalergen« | majhni otroci: PDSA in Woodgreen svetujeta nadzor; druge živali: Woodgreen »Moderate«, močan lovski nagon; stran RKC ni dosegljiva (velikost doma); PDSA »shed very often«, PetMD »not considered to be a hypoallergenic dog breed«; PDSA priporoča izkušenega lastnika, a oznaka `experienced_owner` govori o »močnem psu« (odprto za Davida) | #346 |
| 2026-10-22 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | nobeno veterinarsko združenje pasme ne navaja zaradi težav telesne zgradbe; kategorija Breed Watch ni znana (stran RKC ni dosegljiva) | #346 |
| 2026-10-22 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot hipoalergen pes, pes, ki se ne lisa, pes z malo gibanja, pes, ki je lahko dolgo sam, ali pes za vroče podnebje | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #346 |
| 2026-10-22 | pravilo runbooka (§3) | barve za AI in izbirnik: **bel** (portret), krem, belo-piškotni; nikoli bež, nobena druga barva, nikoli modre ali raznobarvne oči | FCI S464 »Blanc pur, crème ou blanc et biscuit« (uteži barv brez vira → `verified: false`); AKC-jev »all biscuit« (S465) ni v standardu FCI → ni uporabljen | #346 |
| 2026-10-22 | predlog Claude, David lahko spremeni | ime SL »samojed« (slug `samojed`); iskalni ključi tudi »sammy«, »samojedski pes«, »samoyede« | splošno rabljeno slovensko ime (siol.net S471, tier C); KZS ni bil preverjen | #346 |
| 2026-10-23 | pravilo runbooka (§8) | AI portret: realistična fotografija odraslega psa, čisto bel gost kožuh z ovratnikom, temne oči, črn nos in ustnice v »nasmešku«, pokončni uhlji, rep zavihan čez hrbet, siva studijska podlaga, naravna razmerja; sprejet v prvem poskusu (zagon 39879002227, koda `c5c6bc6`, brez produkcijske namestitve) | barva portreta = najtežja barva iz `breed_appearance.php` | #347 |
| 2026-10-22 | pravilo runbooka (§2.3) | seznam za vzreditelja (vse »other_scheme«): rentgen kolkov (shema BVA/KC), pregled oči (BVA/KC), pregled srca, DNK test RD/OSD, DNK test XLPRA1 | PDSA S467 (kolki, oči), ameriški klub pasme (K32). Novi ključi testov `cardiac_exam` (splošen pregled srca — ne `heart_scheme`, ki navaja bolezen mitralne zaklopke), `dna_rd_osd`, `dna_xlpra1` | #346 |
| 2026-10-22 | pravilo runbooka | nov zdravstveni ključ registra **`hereditary_nephritis`** (dedna bolezen ledvic) — mirna poved brez številk; poleg tega `hip_dysplasia`, `cataracts`, `diabetes_mellitus`, `certain_cancers` | PetMD S469 »SHG is a genetic kidney disorder often seen in Samoyeds.«; Woodgreen S468 (X-vezani dedni nefritis) | #346 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »samojed«, »sammy« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-57 | 2026-10-22 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90 mesecev — meja čaka Davida; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-57 | 2026-10-22 |
| Česanje vsak dan (rutina »Počeši«), opozorilo po obroku (60 min), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R16 / M5-R17 | 2026-10-22 |
| Barva (izbirnik barve: bel, krem, belo-piškotni) | 🛠 | 💶 izziv | M5-R21 | 2026-10-22 |
| AI portret v registru (čisto bel, »nasmešek«, rep čez hrbet; sprejet v prvem poskusu, zagon 39879002227) | ✅ | — | M5-R11-04 | 2026-10-23 |
| Stran na petprep.si (`/animals/dogs/samoyed`, `/sl/zivali/psi/samojed`) s portretom; oznaka »Kmalu«, dokler pasma ni v izdaji aplikacije | ✅ (stran) · 🗓 (v aplikaciji) | — | M5-R11 | 2026-10-23 |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal samojeda — 53. najbolj priljubljeno pasmo po AKC 2024 in 53. plačljivo pasmo psa. 9 novih virov (S463–S471). Odrasel pes v igri potrebuje 12.000 korakov na dan (PDSA: vsaj dve uri gibanja), starejši 9.000; uči se hitreje od mešančka (Coren: 33. mesto, ×1,2). Vsakodnevno česanje, opozorilo »počakaj s sprehodom« po obroku in socializacija mladička so del izziva od prvega dne. Prva pasma z novo oznako »gost kožuh — slabo prenaša vročino«.
**Zakaj je pomembno:** Samojed s svojim »nasmeškom« in belim kožuhom je ena najbolj »fotogeničnih« pasem — in prav zato pogosto kupljena na hitro. V resnici potrebuje vsaj dve uri gibanja, česanje vsak dan, varno ograjen vrt in družbo (največ štiri ure sam), dlako pa izgublja zelo veliko. 12 tednov PetPrepa to pokaže brez olepševanja.
**Kako povedati** — starši: »Samojed je prijazen družinski pes, a potrebuje vsaj dve uri gibanja in česanje vsak dan, ne mara biti dolgo sam in slabo prenaša vročino. V PetPrepu boste videli, ali družina to zmore 12 tednov zapored.« · otroci: »Samojed je bel puhast pes, ki se zdi, kot da se smeje. Vsak dan greš z njim na dolg sprehod — a ne takoj po jedi — in ga vsak dan počešeš.« · vlagatelji / partnerji: »Triinpetdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Meja starejšega psa (90 mesecev, `verified=false`):** predlog Claude po spodnji meji PDSA »Over 10 years« — tabele McMillan 2024 (Dogs Trust S123) ta zagon ni mogel odpreti. Preverite vrstico za samojeda; Wikipedija (tier C) navaja 13,1 leta → 118. Sprememba = urejanje v Filamentu ali podatkovna migracija (sejalnik le vstavlja).
- **Potrebe brez mehanike:** pasji frizer na 6–8 tednov in nikoli striženje kožuha (Woodgreen S468) — ROADMAP M5-R18; kremplji — M5-R19; ušesa — M5-R20; zobje — M5-R15 (Woodgreen S468 »Regular nail trims, ear checks, and dental care are also important.«); vročina in senca (PetMD S469, Woodgreen S468) — razširitev M5-R14 (zdaj le za pse s kratkim gobčkom) ali nova naloga v ROADMAP.
- **Izkušen lastnik:** PDSA S467 »We would recommend an experienced owner for this breed« — oznaka `experienced_owner` pravi »močan pes«, `independent_experienced_owner` pa »samostojen in trmast« (ni dobesednega citata) → brez oznake. Ali želite novo oznako?
- **Stran RKC:** ni bila dosegljiva (velikost doma, kategorija Breed Watch, testi za vzreditelja po RKC) — ponovno preverite, ko bo dosegljiva.
- **Ime:** »samojed« ni preverjeno pri KZS.
- **Nadzor otrok:** PDSA in Woodgreen svetujeta nadzor → oznaka `children` brez `small_children`.
