# Anatolski ovčar (Anatolian Shepherd Dog)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-90 · 2026-11-03

| | |
|---|---|
| Ključ / slug | `anatolian_shepherd_dog` / `anatolian-shepherd-dog` (SL `anatolski-ovcar`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 840 — v izbirniku za irskim setrom |
| Vir podatkov | `docs/research/dog-data/data.json` → `anatolian_shepherd_dog` (viri S682–S689; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: stran RKC (S685) |
| Izvor | FCI št. 331 »Kangal Shepherd Dog«, skupina 2, sekcija 2.2 (molosi gorskega tipa), Turčija (S682, S683) — povezava z imenom »Anatolian Shepherd Dog« prek KUSA (S684); RKC vodi anatolskega ovčarja in turškega kangala kot dve pasmi (glej »Odprto za Davida«) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 86. mesto »Anatolian Shepherd Dog« (S174, zabeleženo 2026-11-03) |
| PR-ji | pet-prep #604 (pasma, združen `5f5d9e4`) · portret pet-prep (ta PR; zagon `breed-portraits.yml` 41591870941, sprejet v prvem poskusu) · spletna stran: pet-prep-website `feat/register-anatolian-shepherd-dog` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-03 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | stran RKC S685 »Exercise: More than 2 hours per day« (»more than N« → N). WebMD S688 »Anatolian shepherds need a medium amount of activity.« in PetMD S687 ne dajeta števila → le alternativa | #604 |
| 2026-11-03 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 | AAHA S11; starejši = 0,75 × spodnja meja RKC **10 let** (»Over 10 years«, S685) = 90 mesecev. McMillan 2024 / VetCompass ni dosegljiv → tretji vir pravila (začasno). Ni izbrano: 99 (WebMD 11–13 let, S688), 84 (Dogs Trust »> 7 let«) | #604 |
| 2026-11-03 | pravilo runbooka | učenje **1,0×** | v Corenovi lestvici ga ni (S35, preverjeno 2026-11-03) → kot mešanec | #604 |
| 2026-11-03 | pravilo runbooka | teža **36,3–68,0 kg** (AKC: psice 80–120 lb, psi 110–150 lb), višina psi 72–78 cm / psice 65–73 cm (FCI), rast do 18–24 mesecev; hrana, voda in čiščenje kot pri border collieju | AKC standard S686 (pasma s tem imenom); FCI S682 48–60 / 40–50 kg = alternativa; PetMD S10 (razred »Giant«) | #604 |
| 2026-11-03 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | RKC S685 »Grooming: More than once a week« brez števila → PetMD S687 »… only requires weekly brushing for maintenance.« (izven menjave dlake dvakrat na leto, ki se v igri ne modelira); WebMD S688 »brushed once a week« se ujema | #604 |
| 2026-11-03 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S687 »Early socialization is key to help prevent an Anatolian Shepherd puppy from thinking everything new is a threat.« | #604 |
| 2026-11-03 | David 2026-10-12 (M5-R16) | **opozorilo po obroku 60 min** (»počakaj s sprehodom«, koraki se štejejo) | PetMD S687 »… and avoid exercise around mealtimes.« (GDV) | #604 |
| 2026-11-03 | pravila »Breed care tasks« (David 2026-10-24, M5-R15) | mini igra **ščetkanje zob vsak dan** | WebMD S688 »You can take care of their teeth with daily brushing, dental treats, and dog chews.« Ušesa (po plavanju / kopanju), kremplji (»regularly«), kopanje, frizer: brez pogostosti → brez naloge | #604 |
| 2026-11-03 | pravilo runbooka | oznake: primeren za **»dom z drugimi ljubljenčki«**, **»veliko hišo z vrtom«**; upoštevajte **»vsak dan potrebuje veliko gibanja«**, **»velik pes — ni za dom z majhnimi otroki«**, »izpada mu dlaka« | PetMD S687 »When Anatolian Shepherd puppies are introduced at a young age, they typically get along well with other pets.« in »Anatolian Shepherds might be best in homes without smaller children,«; RKC S685 »Large house«, »More than 2 hours per day«, »Sheds: Yes« | #604 |
| 2026-11-03 | pravilo runbooka | **brez** `active_family` (viri si nasprotujejo glede količine gibanja), `children` / `small_children` (PetMD proti WebMD), `family_pet`, `apartment`, `experienced_owner`, `secure_fencing`, `frequent_grooming`, `low_shedding`, `shorter_lifespan`, `chases_small_pets`, `hips_hind_legs`; nikoli »hypoallergenic« | glej `not_proposed` v data.json | #604 |
| 2026-11-03 | pravilo runbooka (§3, dobrobit) | **ni pasma s skrbmi za dobrobit** (RKC Breed Watch kategorija 1) → brez opozorilnega čipa; **v aplikaciji da, v marketingu da** (nikoli z majhnimi otroki) | S685 | #604 |
| 2026-11-03 | pravilo runbooka (§3) | barvi za AI in izbirnik: **plava s črno masko** (portret) in **volčje siva s črno masko**; naravna viseča ušesa in rep | FCI S682 »Whole body should be one colour. Can be from fawn to wolf sable.« / »Must have black mask.«; AKC S686 dovoljuje vse barve, a nobene ne imenuje; uteži brez vira → `verified: false` | #604 |
| 2026-11-03 | pravilo runbooka (§2.3) | seznam za vzreditelja: minimum »Prioritising genetic diversity« (`inbreeding_coi`); najboljša praksa shema za kolke BVA/KC (`hip_scheme`) | stran RKC S685; brez novega ključa | #604 |
| 2026-11-03 | pravilo runbooka | zdravstveni ključi registra: `bloat_gdv`, `hip_elbow_dysplasia`, `entropion`; brez odstotkov, brez novega ključa | PetMD S687 | #604 |
| 2026-11-03 | predlog Claude, David lahko spremeni | EN »Anatolian Shepherd Dog«, SL »Anatolski ovčar«; iskanje tudi »anatolian shepherd«, »kangal«, »kangal shepherd dog«, »karabash«, »anatolski pastirski pes«, »kangalski ovčar« | strani KZS nisem našel | #604 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »anatolski ovčar«, »kangal« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-90 | 2026-11-03 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-90 | 2026-11-03 |
| Česanje 1× na teden, »Nova izkušnja« za mladička, opozorilo 60 min po obroku | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R16 | 2026-11-03 |
| Ščetkanje zob vsak dan (mini igra) | 🛠 | 💶 izziv | M5-R15 | 2026-11-03 |
| Barva (izbirnik: plava s črno masko, volčje siva s črno masko) | 🛠 | 💶 izziv | M5-R21 | 2026-11-03 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), plav s črno masko, naravna ušesa in rep | — | M5-R11-04 | 2026-11-03 |
| Stran na petprep.si (`/animals/dogs/anatolian-shepherd-dog`, `/sl/zivali/psi/anatolski-ovcar`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal anatolskega ovčarja — velikega, mirnega pastirskega psa čuvaja iz Turčije; 86. najbolj priljubljeno pasmo po AKC 2024 in 83. plačljivo pasmo psa. 8 novih virov (S682–S689). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot dve uri gibanja), česanje enkrat na teden, otrok mu vsak dan očisti zobe, po obroku pa eno uro počaka s sprehodom.
**Zakaj je pomembno:** Anatolski ovčar je neodvisen in zvest, a do tujcev zadržan in tako velik, da lahko po nesreči podre majhnega otroka (PetMD). PetPrep pokaže, kaj tak pes zahteva vsak dan — preden se družina zanj odloči.
**Kako povedati** — starši: »Anatolski ovčar je velik pes čuvaj, ki potrebuje veliko prostora, dolge sprehode in zgodnjo socializacijo; za dom z majhnimi otroki ni priporočljiv. V PetPrepu boste videli, ali vaš otrok zmore tak ritem.« · otroci: »Anatolski ovčar je velik, miren pes, ki pazi na svojo čredo — vsak dan greš z njim na dolg sprehod, mu očistiš zobe in ga enkrat na teden počešeš.« · vlagatelji / partnerji: »Triinosemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, nalogami nege in testi za vzreditelje.«

## Odprto za Davida

- **Identiteta pasme:** FCI pozna le »Kangal Shepherd Dog« (št. 331, Turčija); KUSA standard naslovi »Kangal Shepherd Dog [Anatolian Shepherd]«, RKC pa vodi anatolskega ovčarja in turškega kangala kot dve pasmi, ime »Anatolian Shepherd Dog« v Turčiji ni priznano (Wikipedia S689). Uporabljena je FCI 331 (skupina 2, Turčija) — potrdite ali odločite drugače (npr. brez FCI številke kot pri rat terierju).
- **Gibanje:** RKC »več kot 2 uri« → 120 min (pravilo); WebMD in PetMD govorita o zmerni potrebi (brez števila). Če želite manj, povejte.
- **Življenjska doba / starejše obdobje:** spodnja meja RKC 10 let → starejši od 90. meseca (začasno); WebMD 11–13 let bi dalo 99; McMillan 2024 / Dogs Trust (S123) ni bil dosegljiv.
- **Ime:** »Anatolski ovčar« ni preverjen na straneh KZS. Potrdite ali popravite.
- **Izkušen lastnik:** viri poudarjajo neodvisnost in »pravo družino« (PetMD), a brez izrecne izjave → čipa `experienced_owner` ni. Če ga želite, povejte.
