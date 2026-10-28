# Cairn Terrier (cairn terier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-75 · 2026-10-28

| | |
|---|---|
| Ključ / slug | `cairn_terrier` / `cairn-terrier` (SL `cairn-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 690 — v izbirniku za novoškotskim prinašalcem |
| Vir podatkov | `docs/research/dog-data/data.json` → `cairn_terrier` (viri S583–S588; ponovno S8, S10, S11, S14, S24, S35, S45, S92, S123, S174); seznam za vzreditelja: 6 testov ameriškega pasemskega kluba (AKC K33) |
| Izvor | FCI št. 4 »Cairn Terrier«, skupina 3, sekcija 2 (mali terierji), Velika Britanija (S583) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 71. mesto »Cairn Terrier« (S174, prebrano 2026-10-28); 69. (boykin španjel) in 70. (biewer terier) sta blokirana |
| PR-ji | pet-prep #427 (pasma, združen `53b897b`) · portret: #428 (zagon 40647367119) · spletna stran: pet-prep-website `feat/register-cairn-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-28 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | PDSA S585 »Minimum exercise (per day): 1 hour« (»minimum N« → N); stran RKC ni bila dosegljiva (pravilo RKC → PDSA); VCA S588 in PetMD S587 brez števila | #427 |
| 2026-10-28 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **126** mesecev; prihod 2 / 9 / 36 / 126 | AAHA S11; starejši = 0,75 × mediana McMillan 2024 **14,0 leta** (tabela Dogs Trust S123, vrstica »Cairn Terrier«) = 126. Alternative: Dogs Trust »12+ let« → 108; PDSA »več kot 10 let« → 90; Dogs Trust »> 7 let« → 84 | #427 |
| 2026-10-28 | pravilo runbooka | učenje **1,2×** (Coren 35. mesto, »nadpovprečni«) | S35 (prebrano 2026-10-28) | #427 |
| 2026-10-28 | pravilo runbooka | teža **6–7,5 kg** (oba spola), višina 28–31 cm, rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | standard Kennel Club S584 (»Ideally 6kg – 7.5kg«); PDSA 6–8 kg in PetMD 13–14 lb kot alternativi; PetMD S10 (razred »Small«) | #427 |
| 2026-10-28 | pravilo runbooka (M5-R13) | česanje **2× na teden**; skuštrana dlaka po 1 zamujenem | PDSA S585 »grooming 1-2 times per week« → zgornja meja 2; VCA S588 »once or twice weekly« se ujema; PetMD S587 »weekly« (1) je alternativa | #427 |
| 2026-10-28 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S585: socializirati »at a young age with cats and other small household pets« | #427 |
| 2026-10-28 | pravila »Breed care tasks« (David 2026-10-24) | **brez** nalog zob, ušes, krempljev, kopanja, frizerja ali obraza; brez opozorila za vročino in brez premora po obroku | ročno trimanje »dvakrat na leto« (PDSA, VCA) je daljše od vseh obdobij opomnikov (precedens: nemški ostrodlaki ptičar); ušesa, kopanje in obiski trimanja pri PetMD brez frekvence; noben vir ne omenja premora po obroku ali vročine | #427 |
| 2026-10-28 | pravilo runbooka | oznake: primeren za »družinsko življenje« (`family_pet`), »družino z otroki« (`children` — z opombo: igro vedno nadzorujte), »dom, kjer želite manj dlak« (`low_shedding`); upoštevajte »potrebuje miselne izzive« (`needs_mental_stimulation`) | PDSA S585 »once socialised, they get on very well with children and make excellent family pets.«; PetMD S587 »… should always be supervised«, »They also need lots of exercise and mental stimulation«; Dogs Trust S586 »Medium length with low shedding« | #427 |
| 2026-10-28 | pravilo runbooka | **brez** `chases_small_pets` | noben vir ne uporabi besed »prey drive« (PDSA »strong hunting instincts«, PetMD »something to chase«) — pravilo zahteva dobeseden citat; glej Odprto | #427 |
| 2026-10-28 | pravilo runbooka | **brez** `small_children`, `first_time_owner`, `apartment`, `large_home`, `other_pets`, `long_daily_exercise`, `frequent_grooming`, `needs_company`; nikoli »hypoallergenic« | stran RKC ni bila dosegljiva (velikost doma); 1 ura gibanja ni dolga; česanje 1–2× na teden ni »jasno več kot enkrat«; o samoti noben vir | #427 |
| 2026-10-28 | pravilo runbooka (§3) | ni pasma z oznako dobrobiti; marketing: **v aplikaciji da, v marketingu da** | nobeno veterinarsko združenje pasme ne navaja; ni plosko-obrazna pasma, BVA S92 ne velja | #427 |
| 2026-10-28 | pravilo runbooka (§3) | barve za AI in izbirnik (6): **pšenično rumen** (portret), rdeč, kremast, siv, tigrast (sivo tigrast), skoraj črn — vsi s temnejšimi ušesi in gobcem; nikoli povsem črn, bel ali črno-rjav | standard Kennel Club S584 »Cream, wheaten, red, grey or nearly black.«, »Brindling in all these colours acceptable.«; uteži barv brez vira → `verified: false`; barva portreta je predlog Claude | #427 |
| 2026-10-28 | predlog Claude, David lahko spremeni | EN »Cairn Terrier«, SL »Cairn terier«; iskanje »cairn«, »cairnie«, »cairn terier«, »kern terier« … | slovenskega vira (KZS) za ime ni bilo mogoče preveriti | #427 |
| 2026-10-28 | pravilo runbooka (§2.3) | seznam za vzreditelja (ameriški pasemski klub, raven »drugo«): pogačica, srce, ultrazvok ledvic, pregled oči (okularna melanoza), DNA GLD, jetra (PSVA / MVD); novi ključi `kidney_scheme`, `dna_gld`, `liver_shunt_scheme` | AKC K33 (stran RKC ni bila dosegljiva → brez ravni »minimum« / »best practice«) | #427 |
| 2026-10-28 | pravilo runbooka | zdravstveni ključi registra: `kneecap_luxation`, `glaucoma` (nov), `cataracts`, `itchy_skin_allergy`, `liver_shunt`, `bleeding_disorder_vwd`, `legg_perthes`; brez odstotkov | PDSA S585, PetMD S587 | #427 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »cairn«, »cairn terier« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-75 | 2026-10-28 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 126. meseca; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-75 | 2026-10-28 |
| Česanje 2× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-28 |
| Barva (izbirnik: 6 standardnih barv, privzeto pšenično rumen) | 🛠 | 💶 izziv | M5-R21 | 2026-10-28 |
| AI portret v registru (pšenično rumen s temnejšimi ušesi in gobcem, sprejet v prvem poskusu, zagon 40647367119, PR #428) | ✅ | — | M5-R11-04 | 2026-10-28 |
| Stran na petprep.si (`/animals/dogs/cairn-terrier`, `/sl/zivali/psi/cairn-terier`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal cairn terierja — 71. najbolj priljubljeno pasmo po AKC 2024 in 69. plačljivo pasmo psa. 6 novih virov (S583–S588). Odrasel pes v igri potrebuje 6.000 korakov na dan (PDSA: najmanj 1 ura gibanja), starejši 4.500 od 126. meseca (10,5 leta) — po veliki britanski raziskavi (McMillan 2024, 2.585 psov) je mediana življenjske dobe 14,0 leta, ena daljših v aplikaciji. Česanje dvakrat na teden in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** Majhen (6–7,5 kg), neustrašen in dolgoživ škotski terier, ki se — ko je socializiran — odlično razume z otroki in malo izgublja dlako. A ima »neskončno energije«, močan lovski nagon in potrebuje miselne izzive. PetPrep pokaže, ali otrok zmore vsak dan sprehod, igro za glavo in redno česanje — in to morda 14 let.
**Kako povedati** — starši: »Cairn terier je majhen, pogumen družinski pes, ki ima rad otroke in malo izgublja dlako. Potrebuje vsaj uro gibanja na dan, miselne izzive in česanje enkrat do dvakrat na teden — in živi pogosto 14 let. V PetPrepu boste videli, ali vaš otrok zmore 12 tednov skrbi zapored.« · otroci: »Cairn terier je kosmat mali pes iz Škotske, ki rad koplje in raziskuje. Vsak dan gre na sprehod, dvakrat na teden ga počešeš, rad pa ima tudi igre za glavo.« · vlagatelji / partnerji: »Devetinšestdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC (dve blokirani pasmi je preskočil) in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Lovski nagon:** PDSA (S585) piše o »strong hunting instincts« in svetuje zgodnjo socializacijo z mačkami in malimi živalmi; PetMD (S587) pravi, da lahko manjše živali vidi kot nekaj za lovljenje. Ker noben vir ne reče »prey drive«, po pravilu ni čipa `chases_small_pets`. Želite čip vseeno?
- **Ročno trimanje:** PDSA in VCA: dvakrat na leto (odstranjevanje odmrle dlake); PetMD: »occasional hand-stripping appointments«. Daljše od vseh obdobij opomnikov → brez opomnika za frizerja.
- **Stran RKC:** ni bila dosegljiva (preusmeritev na royalkennelclub.com zavrnjena) — ni ključnih dejstev RKC (velikost doma, dlaka), ni kategorije Breed Watch in ni testov RKC; gibanje iz PDSA.
- **Ime:** »Cairn terier« ni preverjeno pri KZS.
- **Barva portreta:** pšenično rumen je predlog Claude (standard dovoli kremasto, pšenično, rdečo, sivo ali skoraj črno, tudi tigrasto). Portret: zagon 40647367119 (koda `53b897b`, brez produkcijske namestitve, 0,15 $), sprejet v prvem poskusu — realistična fotografija odraslega psa, pšenično rumena kosmata dlaka s temnejšimi ušesi in gobcem, pokončna ušesa, svetlo siva studijska podlaga (rep zaradi kota ni viden); PR #428.
