# Schipperke (Schipperke)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-110 · 2026-11-13

| | |
|---|---|
| Ključ / slug | `schipperke` / `schipperke` (SL `schipperke`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1030 — v izbirniku za miniaturnim bulterierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `schipperke` (viri S824–S829; ponovno S10, S11, S14, S24, S35, S45, S174, S771); seznam za vzreditelja: RKC (S826) |
| Izvor | FCI št. 83 »Schipperke«, skupina 1, sekcija 1 (ovčarski psi), Belgija (S824, S825) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 106. mesto »Schipperke« (S174, zapisano 2026-11-13) |
| PR-ji | pet-prep #1109 (pasma) · portret: pet-prep PR `feat/M5-R10-110-schipperke-portrait` · spletna stran: pet-prep-website `feat/register-schipperke` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-13 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček / mlad 10 min × starost do 60 (pri 6 mesecih) | RKC S826 »Up to 1 hour per day« (»up to N« → N); PetMD S827 in VCA S828 števila ne navajata | #1109 |
| 2026-11-13 | pravilo runbooka | obdobja 0 / 9 / 36 / **128**; prihod 2 / 9 / 36 / 128 | AAHA S11; starejši = 0,75 × mediana McMillan 2024 **14,2 leta** (S771, »Schipperke (x̃ = 14.2 …)«) × 12 = 127,8 → 128. Alternativa 84 (Dogs Trust > 7 let) | #1109 |
| 2026-11-13 | pravilo runbooka | učenje **1,5×** | Coren 15. mesto (»Excellent Working Dogs«, tabela Wikipedia S35) | #1109 |
| 2026-11-13 | pravilo runbooka | teža **3–9 kg** (oba spola), višina 25–33 cm (PetMD), rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S825 »Between 3 and 9 kg. An average weight of 4 to 7 kg is sought after.«; FCI višine ne navaja (PetMD S827 10–13 palcev); PetMD S10 (razred »Small«) | #1109 |
| 2026-11-13 | pravilo runbooka (M5-R13) | česanje **2× na teden**; skuštrana dlaka po 1 zamujenem | RKC »More than once a week« nima števila → VCA S828 »must be brushed at least twice a week for fifteen minutes« → 2 (PetMD »Weekly brushing« = 1 kot alternativa) | #1109 |
| 2026-11-13 | pravila »Breed care tasks« (David 2026-10-24) | **ščetkanje zob vsak dan** in **čiščenje ušes vsaka 2 tedna** (otrokovi mini igri), **opomnik za kopanje vsake 4 tedne** (staršu, brez točk) | PetMD S827 »The best way to prevent dental disease is with daily tooth brushing.«, »cleaning the ears every two weeks …«, »only needs a monthly bath«; kremplji brez pogostosti, »Schipperkes do not require professional grooming.« → brez naloge | #1109 |
| 2026-11-13 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S827 »Like all dogs, Schipperkes should begin obedience training and socialization at an early age.« | #1109 |
| 2026-11-13 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila po obroku in **brez** poletnega opozorila | noben vir ne navaja premora med obrokom in gibanjem; pasma ni ploskoobrazna | #1109 |
| 2026-11-13 | pravilo runbooka | oznake: primeren za **»življenje v stanovanju«**, **»družinsko življenje«**, **»družino z otroki«**; upoštevajte **»potrebuje miselne izzive«**, **»izpada mu dlaka«**, **»potrebuje česanje večkrat na teden«**, **»močan lovski nagon — lahko lovi manjše živali«** | RKC S826 »Size of home: Flat/ Apartment«, »Sheds: Yes«, »Grooming: More than once a week«; FCI S825 »very gentle with children«; VCA S828 »loves his family …«, »at least twice a week«; PetMD S827 »keep their mind engaged«, dolgočasje ob samoti, »some Schipperkes maintain a high prey drive …« | #1109 |
| 2026-11-13 | pravilo runbooka | **brez** `small_children` (PetMD / VCA: z otroki, ki so nežni / če je z njimi odraščal), `other_pets` (VCA »good with other animals« proti PetMD »high prey drive«), `needs_company`, `secure_fencing`; nikoli »hypoallergenic« | ni A/B navedbe ali je nasprotujoča | #1109 |
| 2026-11-13 | pravilo runbooka (§3) | **v aplikaciji da, v marketingu da** | ni ploskoobrazna; RKC Breed Watch kategorija 1 (»Currently no points of concern …«); nobeno veterinarsko združenje ne odsvetuje oglaševanja | #1109 |
| 2026-11-13 | pravilo runbooka (§3) | barva za AI in izbirnik: **črn** (edina standardna barva, portret); pokonci stoječa majhna ušesa, ovratnik, griva in »hlačke«, naraven rep; brez belih lis | FCI S825 »Totally black.«; utež brez vira → `verified: false` | #1109 |
| 2026-11-13 | pravilo runbooka (§2.3) | seznam za vzreditelja: genetska raznolikost (minimum), DNK test MPS IIIB in DNK test T-box (kratek rep) (best practice); nova ključa `dna_mps3b`, `dna_tbox` | RKC S826 | #1109 |
| 2026-11-13 | pravilo runbooka | zdravstveni ključi registra: nov `mucopolysaccharidosis_mps3b`; obstoječi `kneecap_luxation`, `legg_perthes`, `pra_inherited_blindness`, `hypothyroidism`, `dental_disease_home_brushing`; brez odstotkov | RKC S826, PetMD S827 | #1109 |
| 2026-11-13 | predlog Claude, David lahko spremeni | EN in SL **»Schipperke«** (mednarodno ime); iskanje tudi »šiperke«, »siperke«, »little captain« | FCI / RKC / AKC; strani KZS nisem prebral | #1109 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »schipperke«, »šiperke« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-110 | 2026-11-13 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 128. meseca; 4 → 3 → 2 obroka; učenje 1,5×) | 🛠 | 💶 izziv | M5-R10-110 | 2026-11-13 |
| Česanje 2× na teden, ščetkanje zob vsak dan, ušesa vsaka 2 tedna, opomnik za kopanje vsake 4 tedne, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R15 / M5-R18 / M5-R17 | 2026-11-13 |
| Barva (izbirnik: črn) | 🛠 | 💶 izziv | M5-R21 | 2026-11-13 |
| AI portret v registru | 🗓 po združitvi (zagon `breed-portraits.yml`) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/schipperke`, `/sl/zivali/psi/schipperke`) | 🗓 samo s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal schipperkeja — majhnega, čisto črnega belgijskega ovčarčka (3–9 kg) z ovratnikom in grivo, ki so ga nekoč imeli na rečnih ladjah (»mali kapitan«); 106. najbolj priljubljeno pasmo po AKC 2024 in 102. pasmo psa v aplikaciji. 6 novih virov (S824–S829). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do 1 ure gibanja), česanje 2× na teden, ščetkanje zob vsak dan in čiščenje ušes vsaka 2 tedna; starejši postane šele pri 128 mesecih (mediana 14,2 leta, McMillan 2024).
**Zakaj je pomembno:** Schipperke je majhen in dolgoživ, a zelo živahen, pameten in svojeglav čuvaj z lovskim nagonom — brez miselnih izzivov se dolgočasi in uničuje. PetPrep pokaže, ali družina zmore vsakdanje gibanje, igro in redno nego (zobje vsak dan, ušesa, dlaka), ki jo tak pes potrebuje — morda kar 14 let.
**Kako povedati** — starši: »Schipperke je majhen, a zelo bister in dejaven pes, ki dolgo živi. V PetPrepu boste videli, ali vaš otrok zmore vsakodnevni sprehod, vsakodnevno ščetkanje zob in redno česanje.« · otroci: »Schipperke je mali črni kapitan — vsak dan greš z njim ven, mu očistiš zobke in dvakrat na teden ga počešeš.« · vlagatelji / partnerji: »102. pasma psa — samodejni nočni zagon jo je sam izbral po javni lestvici AKC in dodal z viri ter s tremi pravili nege iz veterinarskih virov.«

## Odprto za Davida

- **Česanje 2× na teden:** VCA »at least twice a week«, PetMD »Weekly brushing« (1×). Po pravilu (RKC »More than once a week« brez števila) 2× — želite 1×?
- **Zobje vsak dan:** PetMD »The best way … is with daily tooth brushing« je ideal, ne najmanjša pogostost (kot pri lhasa apsu). Želite redkeje?
- **Višina:** FCI višine ne navaja (samo razmerja); 25–33 cm po PetMD.
- **Ime:** »Schipperke« v slovenščini ni preverjeno na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** število pasem psov v FEATURES / predstavitvah (zdaj 102) se po pravilu »Own files only« ne spreminja v tem zagonu.
