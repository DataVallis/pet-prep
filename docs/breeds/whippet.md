# Mali angleški hrt (Whippet)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-54 · 2026-10-21

| | |
|---|---|
| Ključ / slug | `whippet` / `whippet` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 500 — v izbirniku za avstralskim govedarjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `whippet` (viri S442–S447; ponovno S8, S10, S11, S14, S24, S35, S45, S123, S174) |
| Izvor | FCI št. 162, skupina 10, sekcija 3 (kratkodlaki hrti), Velika Britanija (S442) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 50. mesto »Whippet« (S174) |
| PR-ji | pet-prep #333 (pasma) · portret — · spletna stran — |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-21 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | PDSA S444 »Minimum exercise (per day): 1 hour« in »Your Whippet will need a minimum of an hour exercise every day.« (»minimum N« → N). Stran RKC (prednostni vir) 2026-10-21 ni bila dosegljiva → PDSA (pravilo runbooka) | #333 |
| 2026-10-21 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **121** mesecev; prihod 2 / 9 / 36 / 121 | 0,75 × mediana McMillan 2024 **13,4 leta** (tabela Dogs Trust S123) = 120,6 → 121 — ni začasno. Alternativi: 84 (Dogs Trust > 7 let, S14), 108 (spodnja meja PDSA 12 let, S444) | #333 |
| 2026-10-21 | pravilo runbooka | učenje **1,0×** (Coren rang 51, skupina »Average«) | S35 (rang 51, skupaj s šarpejem in žimastim foksterierjem) | #333 |
| 2026-10-21 | pravilo runbooka | teža 11–18 kg, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S444 »Average weight: 11-18 kg« (standard KC teže ne navaja; PetMD S445 25–40 lb se ujema); PetMD S10 (razred »Medium«) | #333 |
| 2026-10-21 | pravilo runbooka (M5-R13) | česanje **1× na teden** (zmršena dlaka po 1 zamujenem) — z mehko krtačo (občutljiva koža) | PDSA S444 »a weekly brush is usually more than enough«; PetMD S445 »on a weekly basis« se ujema | #333 |
| 2026-10-21 | David 2026-10-12 (M5-R16) | opozorilo »počakaj s sprehodom, pes prebavlja« **60 min** po obroku (koraki se štejejo, brez kazni) | PDSA S444 »Remember to leave a gap between eating and exercising.«; PetMD S445 navaja zasuk želodca (GDV) | #333 |
| 2026-10-21 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S444 »As long as you socialise your Whippet with other dogs from a young age they should be fine meeting them out and about.« | #333 |
| 2026-10-21 | pravilo runbooka (»nova oznaka«) | **nova oznaka** `sensitive_older_children` — »občutljiv pes — bolje za starejše otroke« / »sensitive dog — better with older children« | PDSA S444 »we'd recommend a home with slightly older children«; PetMD S445 »A home with loud, rambunctious little kids might be too much for these sensitive pups.« Obstoječa `older_children` pravi »majhen pes«, `fragile_older_children` »krhke kosti«, `not_for_small_children` »velik pes« — nič ne velja za srednje velikega hrta | #333 |
| 2026-10-21 | pravilo runbooka | oznake: družinsko življenje (`family_pet`), družino z otroki (`children` — izrecna izjava, nadzor in priporočilo starejših otrok v opombah); upoštevajte: občutljiv pes — bolje za starejše otroke (`sensitive_older_children`), rad ima družbo (`needs_company`), močan lovski nagon (`chases_small_pets`) | PDSA S444 »Whippets are a popular choice for families as they are so calm and gentle around children.«, »Like most dogs, Whippets dislike being left alone and can suffer with separation anxiety.«, »Whippets have a really high prey drive, so we wouldn’t recommend keeping them with smaller pets.« | #333 |
| 2026-10-21 | pravilo runbooka | **brez** oznak `small_children`, `other_pets`, `first_time_owner`, `apartment`, `large_home`, `sheds`, `low_shedding`, `long_daily_exercise` | majhni otroci odsvetovani; zelo močan lovski nagon; PDSA »popular with first-time owners although training a Whippet can take some time!« je priljubljenost, ne priporočilo; stanovanje / velika hiša / izpadanje dlake le iz strani RKC (ni bila dosegljiva); dlaka izpada »fairly minimal« (S444) — ne eno ne drugo | #333 |
| 2026-10-21 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | kategorija Breed Watch ni bila pridobljena (stran RKC nedosegljiva), PDSA kategorije ne navaja; noben vir ne navaja težave zaradi telesne zgradbe | #333 |
| 2026-10-21 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot pes za majhne otroke, za dom z malimi živalmi ali pes, ki je lahko sam | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #333 |
| 2026-10-21 | pravilo runbooka (§3) | barve za AI in izbirnik: **plavo rjav** (portret), tigrast, črn, moder (siv), plavo rjav z belo, tigrasto-bel; nikoli merle; zdravo vitek hrt, nikoli shujšan | standard Kennel Club S443 »Any colour or mixture of colours, except merle.« (uteži barv brez vira → `verified: false`) | #333 |
| 2026-10-21 | predlog Claude, David lahko spremeni | ime SL »mali angleški hrt« (slug `mali-angleski-hrt`); iskalni ključi tudi »whippet«, »vipet«, »angleški hrt«, »hrt«, »snap dog« | RTV SLO S447 »Mali angleški hrti ali whippeti …« (tier C); KZS ni preverjen | #333 |
| 2026-10-21 | pravilo runbooka (§2.3) | seznam za vzreditelja: pregled srca (avskultacija) plemenskih psov, pregled oči pri veterinarskem oftalmologu, test sluha BAER — vse `other_scheme`. Brez novega ključa | AKC Hound Group (K30) »Cardiac Exam«, »Ophthalmologist Evaluation«, »BAER Testing«; izjava Kennel Club o srcu (K31) »all breeding stock should be auscultated prior to breeding« (nasvet, uradne sheme za srce v VB ni). Stran RKC nedosegljiva | #333 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »mali angleški hrt«, »whippet«, »vipet« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-54 | 2026-10-21 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 121 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-54 | 2026-10-21 |
| Česanje 1× na teden (rutina »Počeši«), opozorilo »počakaj s sprehodom« po obroku, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R16 / M5-R17 | 2026-10-21 |
| Barva (izbirnik barve: plavo rjav, tigrast, črn, moder, plavo rjav z belo, tigrasto-bel) | 🛠 | 💶 izziv | M5-R21 | 2026-10-21 |
| Nova oznaka »občutljiv pes — bolje za starejše otroke« | 🛠 | — | M5-R10-54 | 2026-10-21 |
| AI portret v registru | 🗓 | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/whippet`, `/sl/zivali/psi/mali-angleski-hrt`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal malega angleškega hrta (whippeta) — 50. najbolj priljubljeno pasmo po AKC 2024 in 50. plačljivo pasmo psa. 6 novih virov (S442–S447). Odrasel pes v igri potrebuje 6.000 korakov na dan (PDSA: vsaj ura gibanja), starejši od 10 let 4.500; uči se kot mešanček (Coren: 51. mesto). Tedensko česanje, opozorilo po obroku in socializacija mladička so del izziva od prvega dne. Nova oznaka: »občutljiv pes — bolje za starejše otroke«.
**Zakaj je pomembno:** Whippet je miren, nežen hišni pes, ki pa ima zelo močan lovski nagon, ne mara biti sam in potrebuje tek brez povodca na varovanem prostoru. Viri jasno povedo, da je primernejši za družine s starejšimi otroki in brez malih živali. Prav to pokaže 12 tednov PetPrepa — ali je nekdo dovolj doma in ali otrok zmore vsakodnevni sprehod.
**Kako povedati** — starši: »Mali angleški hrt je miren in nežen do otrok, a ne mara biti sam in rad lovi manjše živali. Potrebuje vsaj uro gibanja na dan. V PetPrepu boste videli, ali družina zmore 6.000 korakov na dan 12 tednov zapored.« · otroci: »Whippet je miren in nežen pes, ki zelo rad teče, doma pa rad leži na mehki odeji. Vsak dan greš z njim na sprehod, enkrat na teden ga nežno počešeš.« · vlagatelji / partnerji: »Petdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi, pravili in eno novo, s citati podprto oznako.«

## Odprto za Davida

- **Potrebe brez mehanike (needs-david, potrebna nova naloga v ROADMAP):** plašček pozimi in debelo, mehko ležišče (PDSA S444; PetMD S445 »ne prenaša mraza in mokrote«); tek in igra brez povodca na varovanem prostoru (PDSA S444).
- **Stran RKC ni bila dosegljiva** (nov in star naslov): ni ključnih podatkov RKC, ni kategorije Breed Watch, seznam za vzreditelja le iz AKC (K30) in izjave Kennel Club (K31) — ob naslednji priložnosti preverite.
- **Oznaka `children`:** PDSA »calm and gentle around children«, a priporoča starejše otroke — prikazani sta obe oznaki (`children` in nova `sensitive_older_children`); če želite le eno, odločite vi.
- **Nova oznaka `sensitive_older_children`** (besedilo Claude) — preverite besedilo »občutljiv pes — bolje za starejše otroke«.
- **Ime:** »mali angleški hrt« ni preverjeno pri KZS (vir le RTV SLO, tier C); »whippet« je iskalni ključ.
- **Skupni dokumenti (pravilo »Own files only« — zagon jih ne ureja):** seznam besednjaka »Upoštevajte« v `docs/engineering/ARCHITECTURE.md` (`GET /api/breeds`) se konča pri `older_children` — manjkata `independent_experienced_owner` (M5-R10-46) in nova `sensitive_older_children`; prav tako `PRODUCT_SPEC.md` §oznake.
- **Spletna stran:** nov ključ zdravja `mitral_valve_disease` (bolezen mitralne zaklopke) — besede Claude.
