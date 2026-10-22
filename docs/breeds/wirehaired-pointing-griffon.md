# Francoski ostrodlaki ptičar (Wirehaired Pointing Griffon)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-55 · 2026-10-22

| | |
|---|---|
| Ključ / slug | `wirehaired_pointing_griffon` / `wirehaired-pointing-griffon` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 510 — v izbirniku za malim angleškim hrtom |
| Vir podatkov | `docs/research/dog-data/data.json` → `wirehaired_pointing_griffon` (viri S448–S456; ponovno S8, S10, S11, S14, S24, S35, S45, S123, S174) |
| Izvor | FCI št. 107, skupina 7, sekcija 1 (celinski ptičarji), Francija (S448) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 51. mesto »Wirehaired Pointing Griffon« (S174) |
| PR-ji | pet-prep #336 (pasma, združen `c8bbaa4`) · portret #337 · spletna stran — |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-22 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | RKC S450 »Exercise: More than 2 hours per day« (»more than N« → N). PetMD S453 (najmanj 2 × 20 minut) le alternativa — pravilo da prednost RKC | #336 |
| 2026-10-22 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90** mesecev; prihod 2 / 9 / 36 / 90 | 0,75 × spodnja meja RKC »Over 10 years« (S450) = 7,5 leta = 90. Tabela Dogs Trust z medianami McMillan 2024 (S123) pasme nima, mediane VetCompass ni → tretja izbira pravila (kot pri sibirskem haskiju). Alternativi: 84 (Dogs Trust > 7 let, S14), 108 (spodnja meja PetMD 12 let, S453) | #336 |
| 2026-10-22 | pravilo runbooka | učenje **1,0×** (Coren rang 46, skupina »Average«) | S35 (rang 46, skupaj s tibetanskim španjelom, angleškim lisičarjem, jack russell terierjem, hrtom …) | #336 |
| 2026-10-22 | pravilo runbooka | teža 16–32 kg, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | PetMD S453 »weigh 35–70 pounds« (standardi FCI, AKC in UKC teže ne navajajo; VCA S454 50–60 lb je znotraj razpona); PetMD S10 (razred »Medium«) | #336 |
| 2026-10-22 | pravilo runbooka (M5-R13) | česanje **1× na teden** (zmršena dlaka po 1 zamujenem) | RKC S450 »More than once a week« nima števila → najnatančnejše število drugega vira A/B: VCA S454 »demanding only weekly brushing« → 1 (kot pri bretonskem ptičarju); PetMD S453 enako tedensko | #336 |
| 2026-10-22 | pravilo runbooka (M5-R16) | **brez** opozorila po obroku | PetMD S453 svetuje počasno skledo in ne dvignjene sklede (zasuk želodca), premora med obrokom in gibanjem pa ne navede z besedami → ni vrstice (pravilo zahteva citat) | #336 |
| 2026-10-22 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S453 »Wirehaired Pointing Griffons are generally patient and gentle with children when socialized as puppies.« | #336 |
| 2026-10-22 | pravilo runbooka | oznake: aktivno družino (`active_family`), družinsko življenje (`family_pet`), družino z otroki (`children`), veliko hišo z vrtom (`large_home`); upoštevajte: vsak dan potrebuje veliko gibanja (`long_daily_exercise`), močan lovski nagon (`chases_small_pets`) | RKC S450 »Exercise: More than 2 hours per day«, »Size of home: Large house«; UKC S452 »which makes it suitable as a family pet and guardian«; FCI S449 »Very gentle with children.«; PetMD S453 »Their hunting history also means these pups can have a strong prey drive.« | #336 |
| 2026-10-22 | pravilo runbooka | **brez** oznak `small_children`, `needs_company`, `often_alone`, `other_pets`, `apartment`, `sheds`, `low_shedding`, `frequent_grooming` | majhni otroci le s pogojem socializacije (PetMD); samota: PetMD »ne mara biti dolgo sam« proti VCA »Fine with lots of exercise first« — vira se razlikujeta; močan lovski nagon, mačke »too great a temptation« (VCA); RKC »Large house«; izpadanje dlake: RKC »Yes« proti PetMD / VCA »low / minimal« — ne eno ne drugo; česanje tedensko, ne pogosteje | #336 |
| 2026-10-22 | pravilo runbooka (§3, dobrobit) | **brez** oznake dobrobiti | stran RKC (S450) ne navaja kategorije Breed Watch; noben vir ne navaja težave zaradi telesne zgradbe | #336 |
| 2026-10-22 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot pes za stanovanje, za dom z malimi živalmi ali kot pes z malo gibanja | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #336 |
| 2026-10-22 | pravilo runbooka (§3) | barve za AI in izbirnik: **jekleno siv z jetrno rjavimi lisami** (portret), jetrni roan, jetrno rjav, jetrno-bel, oranžno-bel; nikoli črn; nos vedno rjav | FCI S449 »Preferably steel grey shade with brown (liver) patches or self-coloured brown (liver) coat.«; AKC S451 »A black coat disqualifies.« (uteži barv brez vira → `verified: false`) | #336 |
| 2026-10-22 | predlog Claude, David lahko spremeni | v AI podobi in portretu **naravni (nekupiran) rep** | standarda FCI in AKC pravita, da se rep »običajno kupira«; prikazujemo zmerno, naravno podobo psa brez posega | #336 |
| 2026-10-22 | predlog Claude, David lahko spremeni | ime SL »francoski ostrodlaki ptičar« (slug `francoski-ostrodlaki-pticar`); iskalni ključi tudi »korthals«, »korthals griffon«, »griffon«, »griff«, »korthalsov grifon«, »grifon«, »ostrodlaki ptičar« | smrcek.si S456 »FRANCOSKI OSTRODLAKI PTIČAR – GRIFON« (tier C); stran lovske družine »korthalov grifon«; KZS ni preverjen | #336 |
| 2026-10-22 | pravilo runbooka (§8) | AI portret: realistična fotografija odraslega psa, jekleno siv z jetrno rjavimi lisami, rjav nos, brki in obrvi, naraven rep, siva studijska podlaga, naravna razmerja; sprejet v prvem poskusu (zagon 39708692955, koda `c8bbaa4`, brez produkcijske namestitve) | barva portreta = najtežja barva iz `breed_appearance.php` | #337 |
| 2026-10-22 | pravilo runbooka (§2.3) | seznam za vzreditelja: najmanj »Prioritising genetic diversity«, najboljša praksa BVA/KC shema za komolce in BVA/KC shema za kolke | RKC S450 (»We strongly recommend that breeders, at a minimum, conduct these tests before breeding« / »To support the breed’s health, responsible breeders should ensure they complete all tests in both categories.«). Brez novega ključa | #336 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »francoski ostrodlaki ptičar«, »korthals«, »griffon« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-55 | 2026-10-22 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-55 | 2026-10-22 |
| Česanje 1× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-22 |
| Barva (izbirnik barve: jekleno siv z jetrno rjavimi lisami, jetrni roan, jetrno rjav, jetrno-bel, oranžno-bel) | 🛠 | 💶 izziv | M5-R21 | 2026-10-22 |
| AI portret v registru (jekleno siv z jetrno rjavimi lisami, sprejet v prvem poskusu, zagon 39708692955) | ✅ | — | M5-R11-04 | 2026-10-22 |
| Stran na petprep.si (`/animals/dogs/wirehaired-pointing-griffon`, `/sl/zivali/psi/francoski-ostrodlaki-pticar`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal francoskega ostrodlakega ptičarja (Korthalsov grifon, v ZDA »Wirehaired Pointing Griffon«) — 51. najbolj priljubljeno pasmo po AKC 2024 in 51. plačljivo pasmo psa. 9 novih virov (S448–S456). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot 2 uri gibanja), starejši od 7,5 leta 9.000; uči se kot mešanček (Coren: 46. mesto). Tedensko česanje in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** Grifon je nežen do otrok in zelo navezan na družino, a je lovski pes, ki potrebuje več kot dve uri gibanja na dan, veliko hišo z vrtom in ima močan lovski nagon. Prav to pokaže 12 tednov PetPrepa — ali družina vsak dan zmore dolg sprehod.
**Kako povedati** — starši: »Francoski ostrodlaki ptičar je nežen do otrok in se rad uči, a potrebuje več kot dve uri gibanja na dan in lahko lovi manjše živali. V PetPrepu boste videli, ali družina zmore 12.000 korakov na dan 12 tednov zapored.« · otroci: »Grifon je kosmat lovski pes z brki in obrvmi. Vsak dan greš z njim na dolg sprehod, enkrat na teden ga počešeš.« · vlagatelji / partnerji: »Enainpetdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Potrebe brez mehanike (needs-david, potrebna nova naloga v ROADMAP):** počasna skleda in ne dvignjena skleda zaradi zasuka želodca (PetMD S453); ročno trimanje dlake pri nekaterih psih (PetMD S453).
- **Mediana življenjske dobe:** tabela McMillan 2024 (Dogs Trust S123) pasme nima, VetCompass tudi ne → starejši od 90 mesecev po spodnji meji RKC »Over 10 years«. Če se pojavi britanska mediana, se meja lahko spremeni (nova podatkovna migracija).
- **Rep:** standardi navajajo kupiranje; AI podoba in portret kažeta naravni rep (predlog Claude).
- **Ime:** »francoski ostrodlaki ptičar« ni preverjeno pri KZS (vir le smrcek.si, tier C); »korthals« in »grifon« sta iskalna ključa.
- **Teža:** noben standard je ne navaja; razpon 16–32 kg je PetMD (en razpon za oba spola).
