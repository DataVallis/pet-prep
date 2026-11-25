# Irski terier (Irish Terrier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-140 · 2026-11-25

| | |
|---|---|
| Ključ / slug | `irish_terrier` / `irish-terrier` (SL `irski-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1310 — v izbirniku za kerry blue terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `irish_terrier` (viri S1051–S1059; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: RKC (S1053) |
| Izvor | FCI št. 139 »Irish Terrier«, skupina 3, sekcija 1 (veliki in srednji terierji), Irska (S1051, S1052) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 136. mesto »Irish Terrier« (S174, prebrano 2026-11-25) |
| PR-ji | pet-prep #1614 (pasma) · portret: glej vrstico v `BREED_QUEUE.md` · spletna stran: pet-prep-website `feat/register-irish-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-25 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček / mlad 10 min × starost do 60 (pri 6 mesecih) | RKC S1053 »Up to 1 hour per day« (»up to N« → N); PDSA S1055, Woodgreen S1056 in PetMD S1057 se ujemajo (okoli ene ure) | #1614 |
| 2026-11-25 | pravilo runbooka | obdobja 0 / 9 / 36 / **108**; prihod 2 / 9 / 36 / 108 — **začasno**, dokler ni preverjena mediana McMillan 2024 | AAHA S11; starejši = 0,75 × spodnja meja RKC »Over 12 years« (S1053; PDSA S1055 enako) × 12 = 108. Tabela Dogs Trust (S123) in PMC nista bili dosegljivi; Wikipedija (S1059, vir C) navaja 13,5 leta → ne uporabimo. Alternativi 117 (PetMD / WebMD 13 let), 84 (Dogs Trust > 7 let) | #1614 |
| 2026-11-25 | pravilo runbooka | učenje **1,0×** | Coren: skupina »Average Working/Obedience Intelligence« (S35, zrcalo Wikipedije brez številk mest) → 1,0 (kot velški terier) | #1614 |
| 2026-11-25 | pravilo runbooka | teža **samci 12,25 kg, samice 11,4 kg**, višina okoli 45,5 cm, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S1051 »Weight: Dogs 27lbs (12.25kg).«, »Bitches 25lbs (11.4kg).«, »Approximately 18 inches (45.5cm).«; PetMD S10 (razred »Medium«); PDSA 11–12 kg, Woodgreen 11–15 kg | #1614 |
| 2026-11-25 | pravilo runbooka (M5-R13) | česanje **3× na teden**; skuštrana dlaka po 2 zamujenih | RKC S1053 »More than once a week« (brez števila) → PDSA S1055 »grooming 2 or 3 times per week« → zgornja meja 3. Alternativi: Woodgreen 2×, PetMD 1× | #1614 |
| 2026-11-25 | pravila »Breed care tasks« (David 2026-10-24) | **ščetkanje zob vsak dan** in **čiščenje ušes 1× na teden** (otrokovi mini igri), **opomnik za frizerja vsaka 2 meseca** (staršu, brez točk) | WebMD S1058 »Brush their teeth on a daily basis.«; Woodgreen S1056 »Eyes, ears and mouth should be checked weekly« in »clipped every couple of months by a professional groomer«. Kremplji: WebMD »on a regular basis« (brez pogostosti) → brez naloge; kopanje: ni vira | #1614 |
| 2026-11-25 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PDSA S1055 »socialisation and training at a young age are very important« | #1614 |
| 2026-11-25 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila po obroku in **brez** poletnega opozorila | noben vir ne navaja premora med obrokom in gibanjem; pasma ni ploskoobrazna | #1614 |
| 2026-11-25 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družinsko življenje«**, **»družino z otroki«**; upoštevajte **»potrebuje česanje večkrat na teden«**, **»močan lovski nagon — lahko lovi manjše živali«** | RKC S1053 »Up to 1 hour per day«, »More than once a week«; PDSA S1055 »high energy levels«, »2 or 3 times per week«; Woodgreen S1056 »excellent family dogs … gentle and affectionate with the children in their own household« (nadzor v opombah), »strong prey drive« | #1614 |
| 2026-11-25 | pravilo runbooka | **brez** čipov za izpadanje dlake (RKC »Sheds: Yes« proti Woodgreen »Minimal« → nasprotujoča vira), `small_children` (WebMD »fantastic with young children«, Woodgreen svetuje starejše otroke), `needs_company`, `secure_fencing`, `apartment`; nikoli »hypoallergenic« | ni A/B navedbe ali viri si nasprotujejo | #1614 |
| 2026-11-25 | pravilo runbooka (§3) | **v aplikaciji da, v marketingu da** | ni ploskoobrazna; RKC Breed Watch kategorija 2 (S1054) z eno točko »Cracked and/or corny pads« — dedna bolezen blazinic, ne pretirana zgradba → brez čipa dobrobiti | #1614 |
| 2026-11-25 | pravilo runbooka (§3) | barve za AI in izbirnik: **rdeč** (portret, največja utež), **rdeče pšeničen**, **rumeno rdeč**; ušesa v obliki črke V, naraven (nekupiran) rep | FCI S1051 »Colour: Should be “whole coloured” being red, red-wheaten or yellow-red.«; uteži brez vira → `verified: false` | #1614 |
| 2026-11-25 | pravilo runbooka (§2.3) | seznam za vzreditelja: RKC minimum **»Prioritising genetic diversity«**, najboljša praksa **DNK test za dedno hiperkeratozo blazinic (HFH)** (nov ključ `dna_hfh`) | RKC S1053 | #1614 |
| 2026-11-25 | pravilo runbooka | zdravstveni ključi registra: `footpad_hyperkeratosis` (nov), `bladder_stones`; displazija kolkov in seznam WebMD sta le parafrazi → izpuščena; brez odstotkov | PDSA S1055 | #1614 |
| 2026-11-25 | predlog Claude, David lahko spremeni | EN »Irish Terrier«, SL **»Irski terier«**; iskanje »irish terrier«, »irski terier« (samo »irish« ne — najde tudi irskega setra in irskega volčjega hrta) | FCI / RKC; strani KZS nisem prebral | #1614 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 3× na teden«, iskanje »irski terier«, »irish terrier«, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-140 | 2026-11-25 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 108. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-140 | 2026-11-25 |
| Česanje 3× na teden, ščetkanje zob vsak dan, čiščenje ušes vsak teden, opomnik za frizerja vsaka 2 meseca, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R15 / M5-R18 / M5-R14 / M5-R17 | 2026-11-25 |
| Barva (izbirnik: rdeč, rdeče pšeničen, rumeno rdeč) | 🛠 | 💶 izziv | M5-R21 | 2026-11-25 |
| AI portret v registru | 🗓 po združitvi (runbook §8) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/irish-terrier`, `/sl/zivali/psi/irski-terier`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal irskega terierja — živahnega, žilavega rdečega terierja srednje velikosti (okoli 12 kg, 45,5 cm) z Irske; 136. najbolj priljubljeno pasmo po AKC 2024. Devet novih virov (S1051–S1059). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do 1 ure gibanja), česanje 3× na teden, vsakodnevno ščetkanje zob in tedensko čiščenje ušes; starejši postane pri 108 mesecih (začasno, po spodnji meji RKC).
**Zakaj je pomembno:** Irski terier je pogumen, energičen in navezan na družino, a z močnim lovskim nagonom in žično dlako, ki potrebuje redno nego. PetPrep pokaže, ali družina zmore dnevni sprehod in nego dlake, zob ter ušes.
**Kako povedati** — starši: »Irski terier je energičen družinski pes z močnim lovskim nagonom. V PetPrepu boste videli, ali vaš otrok zmore dnevni sprehod, česanje trikrat na teden in vsakodnevno ščetkanje zob.« · otroci: »Irski terier je rdeč in poln energije — vsak dan greš z njim ven, mu ščetkaš zobe in ga večkrat na teden počešeš.« · vlagatelji / partnerji: »Samodejni nočni zagon je pasmo sam izbral po javni lestvici AKC in jo dodal z devetimi viri in štirimi pravili nege iz veterinarskih virov.«

## Odprto za Davida

- **Starejši pri 108 mesecih (začasno):** mediane McMillan 2024 v viru A/B nisem našel (tabela Dogs Trust S123 ni bila dosegljiva); Wikipedija navaja 13,5 leta (→ 122), PetMD / WebMD 13–15 let (→ 117). Za spremembo bo potrebna podatkovna migracija (vrstice so že posejane).
- **Coren:** številka mesta ni bila berljiva (le skupina »Average«); drugo zrcalo namiguje na isto mesto kot bostonski terier (54) — skupina in množitelj 1,0 ostaneta enaka.
- **Ušesa:** Woodgreen piše »checked weekly« (pregled); kot pri drugih pasmah je to tedensko čiščenje ušes. Potrdite.
- **Družba:** Woodgreen piše, da so raje ob ljudeh kot sami dlje časa; čipa `needs_company` nisem dodal (ni navedbe o ločitveni tesnobi).
- **Ime:** »Irski terier« v slovenščini ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** število pasem psov v FEATURES / predstavitvah se po pravilu »Own files only« ne spreminja v tem zagonu.
