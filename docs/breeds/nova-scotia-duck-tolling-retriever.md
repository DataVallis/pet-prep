# Nova Scotia Duck Tolling Retriever (novoškotski prinašalec)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-73 · 2026-10-28

| | |
|---|---|
| Ključ / slug | `nova_scotia_duck_tolling_retriever` / `nova-scotia-duck-tolling-retriever` (SL `novoskotski-prinasalec`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 680 — v izbirniku za kitajskim golim psom |
| Vir podatkov | `docs/research/dog-data/data.json` → `nova_scotia_duck_tolling_retriever` (viri S576–S580; ponovno S8, S10, S11, S14, S24, S35, S45, S92, S123, S174); seznam za vzreditelja: 2 testa »minimum« + 6 »best practice« (RKC S578) |
| Izvor | FCI št. 312 »Nova Scotia Duck Tolling Retriever«, skupina 8, sekcija 1 (prinašalci), Kanada (S576) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 69. mesto »Nova Scotia Duck Tolling Retriever« (S174, prebrano 2026-10-28) |
| PR-ji | pet-prep #424 (pasma, združen `8b9978a`) · portret: #425 (zagon 40613987336) · spletna stran: pet-prep-website `feat/register-nova-scotia-duck-tolling-retriever` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-28 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S578 »Exercise: Up to 1 hour per day« (»up to N« → N); AKC S580 »lots of physical exercise and mental stimulation« in PetMD S579 — brez števila | #424 |
| 2026-10-28 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **119** mesecev; prihod 2 / 9 / 36 / 119 | AAHA S11; starejši = 0,75 × mediana McMillan 2024 **13,2 leta** (tabela Dogs Trust S123, vrstica »Nova Scotia Duck Tolling Retriever«) = 118,8 → 119. Alternative: PetMD 12–14 let → 108; Dogs Trust »> 7 let« → 84 | #424 |
| 2026-10-28 | pravilo runbooka | učenje **1,0×** (Coren pasme ne uvršča) | S35 (preverjeno 2026-10-28) | #424 |
| 2026-10-28 | pravilo runbooka | teža psi **20–23 kg**, psice **17–20 kg** (igra 17–23 kg), višina psi 48–51 / psice 45–48 cm, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S577 (»Guidelines«); PetMD S579 35–50 lb kot alternativa; PetMD S10 (razred »Medium«) | #424 |
| 2026-10-28 | pravilo runbooka (M5-R13) | česanje **2× na teden**; skuštrana dlaka po 1 zamujenem | RKC S578 »Grooming: More than once a week« (brez števila) → najnatančnejše število drugega vira: PetMD S579 »once or twice a week« → zgornja meja 2. AKC S580 »weekly brushing« (1) je alternativa; dnevno česanje v času menjave dlake ni modelirano | #424 |
| 2026-10-28 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | AKC S580 »Like with any breed, early socialization and puppy training courses are highly recommended.« | #424 |
| 2026-10-28 | pravila »Breed care tasks« (David 2026-10-24) | **brez** nalog zob, ušes, krempljev, kopanja, frizerja ali obraza; brez opozorila za vročino in brez premora po obroku | PetMD S579: ušesa očistite »po vsakem kopanju ali plavanju« — pogojno, brez frekvence; zob, krempljev in kopanja viri ne navajajo s številom; noben vir ne omenja premora po obroku ali vročine | #424 |
| 2026-10-28 | pravilo runbooka | oznake: primeren za »družinsko življenje« (`family_pet`), »aktivno družino« (`active_family`), »družino z otroki« (`children` — z opombo: otroci, ki znajo ravnati s psi); upoštevajte »potrebuje miselne izzive« (`needs_mental_stimulation`), »izpada mu dlaka« (`sheds`), »potrebuje česanje večkrat na teden« (`frequent_grooming`), »močan lovski nagon — lahko lovi manjše živali« (`chases_small_pets`) | PetMD S579 »Loyal and affectionate family dogs.«; AKC S580 »lots of physical exercise and mental stimulation«, »tend to be good with children«, »high prey drive around smaller animals«; RKC S578 »Sheds: Yes«, »More than once a week« | #424 |
| 2026-10-28 | pravilo runbooka (nasprotujoči si viri) | **brez** oznake `shorter_lifespan` in brez RKC-jevega »manj kot 10 let« v registru | RKC S578 »Lifespan: Under 10 years« nasprotuje medianam McMillan 2024 (13,2 leta, S123) in PetMD 12–14 let (S579) → nasprotujoči si viri, nobene oznake (precedens: akita); glej Odprto | #424 |
| 2026-10-28 | pravilo runbooka | **brez** `small_children`, `first_time_owner`, `apartment`, `large_home`, `other_pets`, `long_daily_exercise`; nikoli `low_shedding` / »hypoallergenic« | RKC »Small house« (ne stanovanje, ne velika hiša); AKC »a more difficult breed to train«; lovski nagon → namesto `other_pets` čip `chases_small_pets` | #424 |
| 2026-10-28 | pravilo runbooka (§3) | ni pasma z oznako dobrobiti; marketing: **v aplikaciji da, v marketingu da** | RKC Breed Watch kategorija 1; ni plosko-obrazna pasma, BVA S92 ne velja | #424 |
| 2026-10-28 | pravilo runbooka (§3) | barve za AI in izbirnik (3): **rdeč z belimi oznakami** (lisa, prsi, tačke, konica repa — portret), oranžen z belimi oznakami, rdeč brez bele; nikoli črn, siv, srebrn ali bel po telesu | FCI S577 »various shades of red or orange«, bele oznake na konici repa, tačkah, prsih in lisi. Uteži barv brez vira → `verified: false`; barva portreta je predlog Claude | #424 |
| 2026-10-28 | predlog Claude, David lahko spremeni | EN »Nova Scotia Duck Tolling Retriever«, SL »Novoškotski prinašalec«; iskanje »toller«, »nova scotia«, »nsdtr«, »novoškotski prinašalec« … | slovenskega vira (KZS) za ime ni bilo mogoče preveriti | #424 |
| 2026-10-28 | pravilo runbooka (§2.3) | seznam za vzreditelja: »minimum« DNA DM in DNA prcd-PRA; »best practice« DNA CEA/CH, DNA DE, DNA JADD, oči (BVA/KC/ISDS), komolci, kolki (BVA/KC); nova ključa `dna_de`, `dna_jadd` | RKC S578 (»Prioritising genetic diversity« ni test) | #424 |
| 2026-10-28 | pravilo runbooka | zdravstveni ključi registra: `hip_elbow_dysplasia`, `pra_inherited_blindness`, `degenerative_myelopathy`, `addisons_disease`; brez odstotkov | PetMD S579, RKC S578 | #424 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »toller«, »novoškotski prinašalec« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-73 | 2026-10-28 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 119. meseca; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-73 | 2026-10-28 |
| Česanje 2× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-28 |
| Barva (izbirnik: 3 standardne barve, privzeto rdeč z belimi oznakami) | 🛠 | 💶 izziv | M5-R21 | 2026-10-28 |
| AI portret v registru (rdeč z belo liso, prsmi, tačkami in konico repa, sprejet v prvem poskusu, zagon 40613987336, PR #425) | ✅ | — | M5-R11-04 | 2026-10-28 |
| Stran na petprep.si (`/animals/dogs/nova-scotia-duck-tolling-retriever`, `/sl/zivali/psi/novoskotski-prinasalec`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal novoškotskega prinašalca (»tollerja«) — 69. najbolj priljubljeno pasmo po AKC 2024 in 68. plačljivo pasmo psa. 5 novih virov (S576–S580). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do 1 ure gibanja), starejši 4.500 od 119. meseca (skoraj 10 let) — po veliki britanski raziskavi (McMillan 2024, 286 psov) je mediana življenjske dobe 13,2 leta. Česanje dvakrat na teden in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** Najmanjši prinašalec (17–23 kg), ljubeč družinski pes, ki se dobro razume z otroki — a potrebuje veliko gibanja in miselnih izzivov, izpada mu dlaka, manjše živali pa lahko lovi. PetPrep pokaže, ali otrok zmore vsak dan sprehod, igro za glavo in redno česanje.
**Kako povedati** — starši: »Novoškotski prinašalec je zvest in ljubeč družinski pes srednje velikosti, ki je rad z otroki. Potrebuje uro gibanja na dan, miselne izzive in česanje večkrat na teden; manjše živali lahko lovi. V PetPrepu boste videli, ali vaš otrok zmore 12 tednov skrbi zapored.« · otroci: »Toller je rdeč pes z belo konico repa, ki obožuje vodo in igro. Vsak dan gre na sprehod, dvakrat na teden ga počešeš, rad pa ima tudi naloge za glavo.« · vlagatelji / partnerji: »Oseminšestdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi, negovalnimi nalogami in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Življenjska doba:** RKC (S578) navaja »Lifespan: Under 10 years«, McMillan 2024 (S123) mediano 13,2 leta, PetMD 12–14 let. Viri si nasprotujejo → brez oznake `shorter_lifespan`, v registru le mediana in PetMD. Želite oznako ali RKC-jev podatek?
- **Česanje 2× na teden:** RKC »More than once a week« nima števila; po pravilu PetMD »once or twice a week« → 2 (AKC »weekly« bi bil 1). V času menjave dlake AKC svetuje dnevno česanje — sezona ni modelirana.
- **Ušesa po vodi:** PetMD svetuje čiščenje ušes po plavanju ali kopanju — pogojno, zato brez naloge.
- **Besedila standarda RKC:** orodje je stran RKC vrnilo le kot ključna dejstva in imena testov (strani standarda na royalkennelclub.com ni odprlo); ključna dejstva so bila trikrat enaka. Pri enem od treh branj je bil test CEA/CH pod »minimum«, pri dveh pod »best practice« — uporabljeno »best practice«.
- **Ime:** »Novoškotski prinašalec« ni preverjeno pri KZS.
- **Barva portreta:** rdeč z belimi oznakami je predlog Claude (standard dovoli odtenke rdeče in oranžne). Portret: zagon 40613987336 (koda `8b9978a`, brez produkcijske namestitve), sprejet v prvem poskusu — realistična fotografija odraslega psa, rdeča dlaka z belo liso, prsmi, tačkami in konico repa, siva studijska podlaga; PR #425.
