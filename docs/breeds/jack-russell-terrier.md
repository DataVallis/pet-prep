# Jack Russell Terrier (Jack Russell terier; AKC »Russell Terrier«)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-70 · 2026-10-27

| | |
|---|---|
| Ključ / slug | `russell_terrier` / `jack-russell-terrier` (SL `jack-russell-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 650 — v izbirniku za airedalskim terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `russell_terrier` (viri S557–S564; ponovno S8, S10, S11, S14, S35, S45, S123, S174); seznam za vzreditelja: 1 test »minimum« + 3 »Best Practice« (RKC S559) |
| Izvor | FCI št. 345 »Jack Russell Terrier«, skupina 3, sekcija 2 (mali terierji), Velika Britanija, razvita v Avstraliji (S557) — ni parson russell terier (FCI 339) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 66. mesto »Russell Terrier« (S174, prebrano 2026-10-27; 65. mesto boykin španjel je blokiran) |
| PR-ji | pet-prep #412 (pasma, združen `6dbbc32`) · portret: #413 (zagon 40429933614) · spletna stran: pet-prep-website `feat/register-jack-russell-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-27 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S559 »Exercise: Up to 1 hour per day« (»up to N« → N); PDSA S560 »a minimum of an hour« se ujema; PetMD S561 »at least 90 minutes to two hours« = alternativa (ob neskladju velja RKC) | #412 |
| 2026-10-27 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **120** mesecev; prihod 2 / 9 / 36 / 120 | AAHA S11; starejši = 0,75 × mediana McMillan 2024 **13,3 leta** (tabela Dogs Trust S123, vrstica »Jack Russell Terrier«) = 119,7 → 120. Alternative: VetCompass 2022 12,72 leta (S562) → 114; RKC »Over 10 years« → 90 | #412 |
| 2026-10-27 | pravilo runbooka | učenje **1,0×** (Coren rang 46, skupina »Average«) | S35 (Wikipedija hkrati piše, da JRT ni bil uvrščen — tudi »brez ranga« da 1,0) | #412 |
| 2026-10-27 | pravilo runbooka | teža **5–6 kg** (oba spola), višina 25–30 cm, rast do 8–12 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S558 »1 kg na vsakih 5 cm višine« (25 cm ≈ 5 kg, 30 cm 6 kg); PDSA S560 4–6 kg in PetMD S561 9–15 lb sta alternativi; PetMD S10 (razred »Toy«) | #412 |
| 2026-10-27 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | RKC S559 »Grooming: Once a week«; PDSA S560 in PetMD S561 se ujemata | #412 |
| 2026-10-27 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S560 »It's important to socialise your Jack Russell Terrier from a young age with lots of different people, dogs and experiences.« | #412 |
| 2026-10-27 | pravilo »Breed care tasks« (David 2026-10-24, M5-R15) | ščetkanje zob **3× na teden** (mini igra otroka) | PetMD S561 (»Considerations for Pet Parents«) »Can I brush a dog's teeth at least three times a week?« | #412 |
| 2026-10-27 | pravila M5-R16 / »Breed care tasks« | **brez** opozorila po obroku in brez nalog ušes, nohtov, kopanja ali frizerja | noben vir ne navaja premora po obroku; PetMD za kopanje in ušesa »vprašajte veterinarja«, nohti niso omenjeni | #412 |
| 2026-10-27 | pravilo runbooka | oznake: primeren za »družinsko življenje« (`family_pet`), »začetnike« (`first_time_owner`), »življenje v stanovanju« (`apartment`); upoštevajte »majhen pes — bolje za starejše otroke« (`older_children`), »izpada mu dlaka« (`sheds`), »močan lovski nagon — lahko lovi manjše živali« (`chases_small_pets`) | PDSA S560 »… can make good family pets if they have been trained and socialised properly …«, »… a popular choice for first-time owners.«, »They're better suited to families with slightly older children …«, »Jack Russells have a very high prey drive and love to chase.«; RKC S559 »Size of home: Flat/ Apartment«, »Sheds: Yes« | #412 |
| 2026-10-27 | pravilo runbooka | **brez** oznak `children`, `small_children`, `other_pets`, `needs_company`, `active_family`, `long_daily_exercise`, `secure_fencing`, `frequent_grooming`, `shorter_lifespan`; nikoli »hypoallergenic«; brez nove oznake | PDSA: manj strpen z mlajšimi otroki, manjših živali ne priporoča; le splošno pravilo »največ štiri ure sam«; 60 min; kopanje po vrtu ni pobeg | #412 |
| 2026-10-27 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | RKC Breed Watch kategorija 1 — ni točk skrbi (S559) | #412 |
| 2026-10-27 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli z obljubo o zdravju | ni pasma s sploščenim obrazom; veterinarsko odsvetovanje oglaševanja velja le zanje (BVA S92) | #412 |
| 2026-10-27 | pravilo runbooka (§3) | barve za AI in izbirnik (3): **bel z rjavimi lisami** (portret), tribarven (bel s črnimi in rjavimi lisami), bel s črnimi lisami; vedno pretežno bel; gladka dlaka na portretu (»broken« in »rough« sta tudi standardni); naravna ušesa in nekupiran rep | FCI S558 »White must predominate with black and/or tan markings.« Uteži barv brez vira → `verified: false`; barva portreta je predlog Claude | #412 |
| 2026-10-27 | predlog Claude, David lahko spremeni | EN ime **»Jack Russell Terrier«** (FCI / RKC; AKC »Russell Terrier« je sopomenka), ključ `russell_terrier` iz vrste; SL »Jack Russell terier«; iskanje »jack russell«, »russell terrier«, »jrt«, »džek rasel« … | v Evropi bolj znano ime; slovenskega vira (KZS) za ime ni bilo mogoče preveriti | #412 |
| 2026-10-27 | pravilo runbooka (§2.3) | seznam za vzreditelja: DNK test PLL (»minimum«), DNK testa LOA in SCA ter pregled oči BVA/KC/ISDS (»Best Practice«); nova ključa `dna_pll`, `dna_loa`, `dna_sca` | RKC S559 | #412 |
| 2026-10-27 | pravilo runbooka | zdravstveni ključi registra: `kneecap_luxation`, `primary_lens_luxation` (nov), `inherited_ataxia` (nov), `legg_perthes`, `deafness`, `periodontal_disease`; brez odstotkov | PDSA S560, PetMD S561 | #412 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »jack russell«, »russell terrier«, »jrt« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-70 | 2026-10-27 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 120. meseca; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-70 | 2026-10-27 |
| Česanje 1× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-27 |
| Ščetkanje zob 3× na teden (otrok) | 🛠 | 💶 izziv | M5-R15 | 2026-10-27 |
| Barva (izbirnik: 3 standardne barve, privzeto bel z rjavimi lisami) | 🛠 | 💶 izziv | M5-R21 | 2026-10-27 |
| AI portret v registru (bel z rjavimi lisami, sprejet v prvem poskusu, zagon 40429933614, PR #413) | ✅ | — | M5-R11-04 | 2026-10-27 |
| Stran na petprep.si (`/animals/dogs/jack-russell-terrier`, `/sl/zivali/psi/jack-russell-terier`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal jack russell terierja (pri AKC »Russell Terrier«) — 66. najbolj priljubljeno pasmo po AKC 2024 in 65. plačljivo pasmo psa. 8 novih virov (S557–S564). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do ene ure gibanja), starejši 4.500 šele od 10. leta — po veliki britanski raziskavi (McMillan 2024, 19.166 psov) je mediana življenjske dobe 13,3 leta, med najdaljšimi. Česanje enkrat na teden, ščetkanje zob trikrat na teden in socializacija mladička so del izziva od prvega dne.
**Zakaj je pomembno:** Majhen pes ni »lahek« pes: jack russell je živahen, pameten delovni terier z zelo močnim lovskim nagonom, ki rad koplje in laja, kadar je vznemirjen. PDSA ga priporoča družinam z malo starejšimi otroki in ne domovom z malimi živalmi. Ker dolgo živi, je to odločitev za več kot desetletje. PetPrep pokaže, ali družina zmore vsakodnevno gibanje in nego 12 tednov.
**Kako povedati** — starši: »Jack russell je majhen, a zelo energičen pes za približno uro gibanja na dan; potrebuje tedensko česanje, ščetkanje zob in veliko socializacije kot mladiček. Bolje se znajde z malo starejšimi otroki in brez zajčkov ali hrčkov v hiši. V PetPrepu boste videli, ali vaš otrok to zmore 12 tednov zapored.« · otroci: »Jack russell je majhen terier z ogromno energije — vsak dan greš z njim ven, mu trikrat na teden umiješ zobe in ga naučiš spoznavati nove ljudi in pse.« · vlagatelji / partnerji: »Petinšestdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC (preskočil je pasmo brez virov) in dodal — z viri, testi, negovalnimi nalogami in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Ime v aplikaciji:** »Jack Russell Terrier« / »Jack Russell terier« (FCI / RKC) namesto AKC »Russell Terrier« — predlog Claude; ključ ostane `russell_terrier`. Ime ni preverjeno pri KZS.
- **Gibanje 60 min:** RKC »Up to 1 hour«, PDSA »a minimum of an hour«; PetMD (ZDA) piše 90–120 minut. Pravilo da 60; po želji spremenite.
- **Zobje 3× na teden:** vir je vprašanje v PetMD-jevem seznamu »Considerations for Pet Parents« (»Can I brush a dog's teeth at least three times a week?«) — brano kot pogostost po pravilu »Breed care tasks«.
- **Coren:** Wikipedija ima JRT na 46. mestu, a v besedilu piše, da ni bil uvrščen; obe branji dasta učenje 1,0.
- **Lovski nagon in vrt:** PetMD svetuje sprehode na povodcu ali v varno ograjenem prostoru, PDSA omenja kopanje — brez oznake `secure_fencing` (ni izjave o pobegu).
- **Barva portreta:** bel z rjavimi lisami je predlog Claude (standard ne določa najpogostejše barve). Portret: zagon 40429933614 (koda `6dbbc32`, brez produkcijske namestitve), sprejet v prvem poskusu; PR #413.
