# Toy fox terier (Toy Fox Terrier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-112 · 2026-11-14

| | |
|---|---|
| Ključ / slug | `toy_fox_terrier` / `toy-fox-terrier` (SL `toy-fox-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1050 — v izbirniku za belgijskim ovčarjem tervurenom |
| Vir podatkov | `docs/research/dog-data/data.json` → `toy_fox_terrier` (viri S833–S839; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: American Toy Fox Terrier Club (K42) |
| Izvor | ameriška pasma, AKC skupina pritlikavih psov (priznana 2003, standard velja od 2016; S835, S837); UKC od 1936; **standarda FCI ni** (pasma ni pri FCI in ne pri RKC; v registru ni podatka FCI) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 108. mesto (S174, zabeleženo 2026-11-14) |
| PR-ji | pet-prep #1123 (pasma, združen `8f915f7`) · portret: pet-prep portretni PR (zagon `breed-portraits.yml` 43550212898 14. 11. 2026, koda `8f915f7`, brez namestitve; sprejet v prvem poskusu) · spletna stran: pet-prep-website `feat/register-toy-fox-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-14 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | PetMD S833 »regular exercise—at least an hour every day—is crucial …« (»at least N« → N). Strani RKC / PDSA za to ameriško pasmo ni, zato je veterinarsko pregledan PetMD najmočnejši vir s številko; WebMD S834 brez minut | #1123 |
| 2026-11-14 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11 (za vse pse) | #1123 |
| 2026-11-14 | **predlog Claude — čaka Davida** | starejši od **117. meseca** (tudi prihod starejšega 117; `verified: false`) | 0,75 × spodnja meja PetMD »13–15 years« (S833; WebMD S834 enako) = 9,75 let. Mediane McMillan 2024 / VetCompass iz vira A/B nisem dosegel (tabela Dogs Trust S123 ni bila dosegljiva) → najbližja vrstica pravila (precedens rat terier). Wikipedija (S837, tier C) navaja McMillan 2024 12,9 let → 116 (alternativa); ni izbrano: 84 (Dogs Trust »> 7 let«) | #1123 |
| 2026-11-14 | pravilo runbooka | učenje **1,0×** | Coren pasme ne razvršča (S35, preverjeno 2026-11-14; »Smooth Fox Terrier« je druga pasma) → pravilo »unranked« | #1123 |
| 2026-11-14 | pravilo runbooka | teža **1,8–4,1 kg** (4–9 lb, oba spola), višina 21,5–28 cm, rast do 8–12 mesecev; hrana, voda in čiščenje kot pri border collieju | PetMD S833 »… weighing 4–9 pounds and standing 8.5–11.5 inches tall.« (WebMD S834 enako); standard AKC (S835) teže ne navaja, višina »21.5cm – 28cm … acceptable«; UKC S838 prednostno 4,5–7,5 lb (alternativa); PetMD S10 (razred »Toy«) | #1123 |
| 2026-11-14 | pravilo runbooka (M5-R13) | česanje **2× na teden**; skuštrana dlaka po 1 zamujenem | PetMD S833 »It's helpful to brush their coat once or twice a week …« (razpon → zgornja meja); WebMD S834 »Weekly brushing …« → 1 (alternativa, odprto za Davida) | #1123 |
| 2026-11-14 | pravila »Breed care tasks« (David 2026-10-24, M5-R15 / R18 / R19) | otrokove mini igre: **zobje vsak dan**, **ušesa 1× na teden**, **kremplji vsake 4 tedne** | WebMD S834 »Aim to trim your dog’s nails every month and brush their teeth daily with dog toothpaste.«, »Wash your TFT’s ears every week …«. Kopanje »occasional baths« in frizer brez pogostosti → brez opomnika | #1123 |
| 2026-11-14 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | WebMD S834 »Early training and socialization will ensure your dog grows to be a friendly and well-mannered companion.« | #1123 |
| 2026-11-14 | pravilo runbooka (M5-R16) | **brez** opozorila po obroku; brez opozorila za vročino | noben vir ne svetuje premora po obroku; ni plosko-obrazna pasma | #1123 |
| 2026-11-14 | pravilo runbooka | oznake (upoštevajte): »majhen pes — bolje za starejše otroke« (`older_children`), »rad ima družbo« (`needs_company`), »močan lovski nagon« (`chases_small_pets`); **brez oznake »primeren za«** | WebMD S834 »TFTs are not recommended for families with small children because they are skittish and prone to biting.«, »TFTs may show separation anxiety when they're left alone for long.«; PetMD S833 »… still maintain a strong prey drive« | #1123 |
| 2026-11-14 | pravilo runbooka | **brez** `children`, `small_children`, `apartment`, `other_pets`, `family_pet`, `low_shedding`, `food_motivated_weight`; nikoli »hypoallergenic« | WebMD odsvetuje majhne otroke; »apartments« (WebMD) ni ključni podatek RKC; »accommodate other pets« nasprotuje lovskemu nagonu; »excellent companions for … families« le kot povzetek; malo izpadanja dlake brez RKC »Sheds: No« | #1123 |
| 2026-11-14 | pravilo runbooka (§3) | **brez čipa dobrobiti**; marketing: **v aplikaciji da, v marketingu da** (nikoli z majhnimi otroki, nikoli kot pes za dom z malimi živalmi, nikoli s kupiranim repom) | ni plosko-obrazna pasma (BVA S92 ne velja); pasma ni pri RKC (brez Breed Watch) | #1123 |
| 2026-11-14 | pravilo runbooka (§3) | barve za AI in izbirnik: **trobarven (portret: belo telo, črna glava z rjavkastimi oznakami)**, belo-čokoladno-rjavkast, belo-rjavkast, belo-črn; nikoli enobarven, moder / siv; **naravni rep** | standard AKC S835 »Tri-colour: Predominately black head with sharply defined tan markings on cheeks, lips, and eye dots.« + tri druge kombinacije, telo več kot pol belo; UKC S838 izključi »Maltese (Blue/Grey)«; rep je po AKC kupiran, UKC naravnega ne kaznuje — portret kaže naravnega (precedens rat terier); uteži brez vira → `verified: false` | #1123 |
| 2026-11-14 | pravilo runbooka (§2.3) | seznam za vzreditelja: **druga shema** — DNA PLL, pregled pogačic (OFA), DNA CHG, DNA SCA | American Toy Fox Terrier Club (K42) »The following health conditions have been identified in the Toy Fox Terrier.«, »Except for Patellar Luxation, these conditions have DNA tests available.«; nov ključ `dna_chg` | #1123 |
| 2026-11-14 | pravilo runbooka | zdravstveni ključi registra: `kneecap_luxation`, `legg_perthes`, `bleeding_disorder_vwd`, `primary_lens_luxation`, `hypothyroidism`; brez odstotkov | PetMD S833, ATFTC S839 (demodikoza le v podatkih, brez ključa) | #1123 |
| 2026-11-14 | predlog Claude, David lahko spremeni | EN »Toy Fox Terrier«, SL »Toy fox terier«; iskanje »toy fox terrier«, »toy fox terier«, »tft«, »american toy terrier«, »amertoy« | drugi imeni iz Wikipedije (S837), »TFT« iz WebMD (S834); slovenskega imena nisem našel, strani KZS nisem preverjal | #1123 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 2× na teden«, iskanje »toy fox«, »amertoy« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-112 | 2026-11-14 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 117. meseca — predlog; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-112 | 2026-11-14 |
| Česanje 2× na teden, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-14 |
| Mini igre zobje (vsak dan), ušesa (1× na teden), kremplji (vsake 4 tedne) | 🛠 | 💶 izziv | M5-R15 / R18 / R19 | 2026-11-14 |
| Barva (izbirnik: 4 standardne barve) | 🛠 | 💶 izziv | M5-R21 | 2026-11-14 |
| AI portret v registru (trobarven, naravni rep) | ✅ AI fotografija (oznaka »AI-generated photo«) | — | M5-R11-04 | 2026-11-14 |
| Stran na petprep.si (`/animals/dogs/toy-fox-terrier`, `/sl/zivali/psi/toy-fox-terier`) | 🛠 s portretom (spletni PR `feat/register-toy-fox-terrier`, v živo z naslednjo dnevno namestitvijo spletne strani); »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | 2026-11-14 |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal toy fox terierja — drobnega (1,8–4,1 kg), atletskega ameriškega terierja z gladko, svilnato dlako; 108. najbolj priljubljeno pasmo po AKC 2024. 7 novih virov (S833–S839). Odrasel pes v igri potrebuje 6.000 korakov na dan (PetMD: vsaj uro gibanja), česanje dvakrat na teden, otrok mu vsak dan umije zobe, vsak teden očisti ušesa in vsake 4 tedne pristriže kremplje.
**Zakaj je pomembno:** Majhen pes ni »enostaven« pes: toy fox terier je poln energije, ne mara biti dolgo sam, ima močan lovski nagon in ga WebMD ne priporoča družinam z majhnimi otroki. PetPrep pokaže, ali otrok vsak dan zmore sprehod in drobno nego — tudi vsakodnevno umivanje zob — 12 tednov, preden pride pravi pes.
**Kako povedati** — starši: »Toy fox terier je majhen, a potrebuje vsaj uro gibanja na dan, družbo in vsakodnevno nego zob; viri ga priporočajo starejšim otrokom. V PetPrepu boste videli, ali vaš otrok zmore takšen ritem.« · otroci: »Toy fox terier je drobcen, hiter terier — vsak dan greš z njim na sprehod in mu umiješ zobke, enkrat na teden mu očistiš ušesa.« · vlagatelji / partnerji: »Še ena plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Starejše obdobje (predlog 117):** za to ameriško pasmo ni dosegljive mediane McMillan 2024 / VetCompass iz vira A/B in ni strani RKC; uporabljena je spodnja meja PetMD »13–15 years« (`verified: false`, `PROPOSED_R10_TOY_FOX_TERRIER_SENIOR`). Wikipedija navaja McMillan 2024 12,9 let (52 smrti) → 116. Potrdite ali izberite.
- **Gibanje 60 min:** vir je PetMD (S833, B), ker RKC / PDSA strani ni. Če želite drugačen vir, povejte.
- **Česanje 2× na teden:** PetMD »once or twice a week« (zgornja meja po pravilu), WebMD »Weekly brushing« → 1. Potrdite ali izberite 1.
- **Zobje vsak dan in kremplji vsake 4 tedne:** oba iz istega stavka WebMD (S834, pregledal zdravnik MD, ne veterinar). Če je to za pasmo pretežko ali vir prešibak, povejte.
- **Brez oznake »primeren za«:** viri brez RKC ne dajo nobene oznake `suits`; v izbirniku se pokažejo le tri »upoštevajte«.
- **Brez standarda FCI:** strani FCI nisem preverjal; v registru ni podatka »FCI št.« (kot pri rat terierju).
- **Ime:** »Toy fox terier« je predlog (slovenskega imena nisem našel). Potrdite ali popravite.
- **VCA:** stran VCA o pasmi je orodje vrnilo le kot povzetek (ne dobesedno), zato je nisem uporabil kot vir.
