# Miniature Bull Terrier (miniaturni bulterier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-109 · 2026-11-13

| | |
|---|---|
| Ključ / slug | `miniature_bull_terrier` / `miniature-bull-terrier` (SL `miniaturni-bulterier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1020 — v izbirniku za norwich terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `miniature_bull_terrier` (viri S818–S823; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: RKC (S820) |
| Izvor | FCI št. 359 »Miniature Bull Terrier«, skupina 3, sekcija 3 (bulterierji), Velika Britanija (S818, S819) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 105. mesto »Miniature Bull Terrier« (S174, prebrano 2026-11-13) |
| PR-ji | pet-prep #1103 (pasma) · portret: sledi · spletna stran: pet-prep-website `feat/register-miniature-bull-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-13 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček / mlad 10 min × starost do 60 (pri 6 mesecih) | RKC S820 »Up to 1 hour per day« (»up to N« → N); PetMD S821 števila ne navaja | #1103 |
| 2026-11-13 | pravilo runbooka | obdobja 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 — **začasno**, dokler ni preverjena mediana McMillan 2024 | AAHA S11; starejši = 0,75 × spodnja meja RKC »Over 10 years« (S820) × 12 = 90 (kot norwich terier). Tabela Dogs Trust S123 ni bila dosegljiva; Wikipedija (S823, raven C) navaja McMillan 12,2 leta → 110 — ne sama. Alternativi 99 (PetMD 11 let), 84 (Dogs Trust > 7 let) | #1103 |
| 2026-11-13 | pravilo runbooka | učenje **1,0×** | Coren pasme ne uvršča (tabela Wikipedia S35; »Bull Terrier« na 66. mestu je druga pasma) | #1103 |
| 2026-11-13 | pravilo runbooka | teža **8,2–12,7 kg** (oba spola, 18–28 funtov), višina 25–36 cm, rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | PetMD S821 »weigh 18–28 pounds«, »10–14 inches«; FCI S819 »There is no weight limit.«, »Height should not exceed 35,5 cm.«; PetMD S10 (razred »Small«, RKC »Size: Small«) | #1103 |
| 2026-11-13 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | RKC S820 »Grooming: Once a week« → 1; PetMD S821 »about once a week« se ujema | #1103 |
| 2026-11-13 | pravila »Breed care tasks« (David 2026-10-24) | **brez** naloge nege | PetMD S821: kopanje le, ko je umazan; ušesa »Ask your vet how often«; za kremplje, zobe in frizerja ni pogostosti | #1103 |
| 2026-11-13 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S821 »need early socialization to adapt well to new situations.« | #1103 |
| 2026-11-13 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila po obroku in **brez** poletnega opozorila | noben vir za to pasmo ne navaja premora med obrokom in gibanjem (nasvet PDSA S591 velja za bulterierja); pasma ni ploskoobrazna | #1103 |
| 2026-11-13 | pravilo runbooka | oznake: primeren za **»družinsko življenje«**, **»družino z otroki«**; upoštevajte **»potrebuje miselne izzive«**, **»rad ima družbo — naj bo nekdo večino dneva doma«**, **»izpada mu dlaka«** | PetMD S821 »good with children, making them a friendly family companion«, »regular exercise and mental stimulation«, »will not thrive in a home where they will be left alone for long periods«; RKC S820 »Sheds: Yes« | #1103 |
| 2026-11-13 | pravilo runbooka | **brez** `apartment` (RKC »Small house«), `small_children`, `other_pets` (PetMD »if properly socialized« — pogojno), `chases_small_pets` (za to pasmo noben vir ne omenja lovskega nagona), `chews_when_bored`; nikoli »hypoallergenic« | ni A/B navedbe ali je pogojna | #1103 |
| 2026-11-13 | pravilo runbooka (§3) | **v aplikaciji da, v marketingu da** | ni ploskoobrazna; RKC Breed Watch kategorija 2 brez navedenih točk → brez čipa dobrobiti; nobeno veterinarsko združenje ne odsvetuje oglaševanja | #1103 |
| 2026-11-13 | pravilo runbooka (§3) | barve za AI in izbirnik: **bel** (portret, največja utež), tigrast, črno tigrast, rdeč, plav, tribarven; nikoli moder, jetrno rjav ali merle; jajčasta glava, majhna pokonci stoječa ušesa | FCI S819 »For White, pure white coat.«, »Black brindle, red, fawn and tricolour acceptable.«, »Blue and liver highly undesirable.«; RKC: merle se ne registrira; uteži brez vira → `verified: false` | #1103 |
| 2026-11-13 | pravilo runbooka (§2.3) | seznam za vzreditelja: genetska raznolikost (minimum), DNK test za primarno luksacijo leče (PLL) in pregled oči BVA/KC/ISDS (dobra praksa); brez novega ključa | RKC S820 | #1103 |
| 2026-11-13 | pravilo runbooka | zdravstveni ključi registra: `primary_lens_luxation`, `deafness`, `mitral_valve_disease`, `kneecap_luxation` in **nov** `hereditary_nephritis_pkd` (dedni bolezni ledvic); brez odstotkov | PetMD S821 | #1103 |
| 2026-11-13 | predlog Claude, David lahko spremeni | EN »Miniature Bull Terrier«, SL **»miniaturni bulterier«**; iskanje tudi »mini bulterier«, »mini bull«, »bull terrier miniature« | FCI / AKC, RKC »Bull Terrier (Miniature)«; strani KZS nisem prebral | #1103 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »miniaturni bulterier«, »mini bull« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-109 | 2026-11-13 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 90. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-109 | 2026-11-13 |
| Česanje 1× na teden, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-13 |
| Barva (izbirnik: bel, tigrast, črno tigrast, rdeč, plav, tribarven) | 🛠 | 💶 izziv | M5-R21 | 2026-11-13 |
| AI portret v registru | 🗓 sledi po združitvi | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/miniature-bull-terrier`, `/sl/zivali/psi/miniaturni-bulterier`) | 🗓 šele s portretom; nato »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal miniaturnega bulterierja — manjšega brata bulterierja (do 35,5 cm, približno 8–13 kg) z značilno jajčasto glavo, iz Velike Britanije; 105. najbolj priljubljeno pasmo po AKC 2024 in 101. pasmo psa v aplikaciji. 6 novih virov (S818–S823). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do 1 ure gibanja) in česanje enkrat na teden; starejši postane pri 90 mesecih (začasno, po spodnji meji RKC).
**Zakaj je pomembno:** Mini bulterier je majhen, a poln energije in rad ima družbo — ne mara biti dolgo sam in potrebuje miselne izzive. PetPrep pokaže, ali je družina dovolj doma in ali otrok zmore vsakodnevni sprehod.
**Kako povedati** — starši: »Miniaturni bulterier je igriv in navezan na ljudi; dolgih ur samote ne prenese dobro. V PetPrepu boste videli, ali vaš otrok zmore vsakodnevni sprehod in tedensko česanje.« · otroci: »Mini bulterier je pravi klovn — vsak dan greš z njim ven, enkrat na teden ga počešeš in se z njim veliko igraš.« · vlagatelji / partnerji: »101. pasma psa — samodejni nočni zagon jo je sam izbral po javni lestvici AKC in dodal z viri, ki jih je mogoče preveriti.«

## Odprto za Davida

- **Starejši pri 90 mesecih (začasno):** mediana McMillan 2024 (tabela Dogs Trust S123) v tem zagonu ni bila dosegljiva; Wikipedija (raven C) navaja 12,2 leta → 110 mesecev. Ko bo tabela preverjena, je za spremembo potrebna podatkovna migracija (vrstice so že posejane).
- **Teža:** standard FCI teže ne določa; uporabljen PetMD 18–28 funtov (8,2–12,7 kg). Tabela AKC (S268) je enak podatek vrnila le v povzetku, ne dobesedno.
- **RKC Breed Watch kategorija 2:** točke skrbi niso bile vrnjene — brez čipa dobrobiti (kot norwich terier / pomeranec).
- **Zdravstvena shema kluba (srce, ledvice):** stran RKC nanjo povezuje, a je nisem prebral — ni na seznamu za vzreditelja.
- **Ime:** »miniaturni bulterier« v slovenščini ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupna testa drugih pasem:** iskanje »bulterier« zdaj najde tudi mini (test bulterierja v aplikaciji posodobljen), test bulterierja v zaledju je kot neobstoječi ključ uporabljal `miniature_bull_terrier` (zamenjan z `mini_bull_terrier`); `BreedSuitabilityTest` ima pasmo na seznamu izjem za `needs_company`.
- **Skupni dokumenti:** število pasem psov v FEATURES / predstavitvah (zdaj 101) se po pravilu »Own files only« ne spreminja v tem zagonu.
