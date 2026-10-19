# Šiba inu (Shiba Inu)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-46 · 2026-10-19

| | |
|---|---|
| Ključ / slug | `shiba_inu` / `shiba-inu` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 420 — v izbirniku za angleškim mastifom |
| Vir podatkov | `docs/research/dog-data/data.json` → `shiba_inu` (viri S377–S383; ponovno S8, S10, S11, S14, S24, S35, S45, S174, S376) |
| Izvor | FCI št. 257, skupina 5, sekcija 5 (azijski špici in sorodne pasme), Japonska (S377) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 42. mesto »Shiba Inu« (41. mesto, zahodnoškotski beli terier, je že v aplikaciji; S174) |
| PR-ji | pet-prep #273 (pasma, `1b383d4`) · portret: čaka na namestitev produkcije · spletna stran #75 |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-19 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S378 »Exercise: Up to 1 hour per day« (»do N« → N). PetMD S381 in Dogs Trust S382 minut ne navajata | #273 |
| 2026-10-19 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **131** mesecev; prihod 2 / 9 / 36 / 131 | 0,75 × mediana McMillan 2024 **14,6 leta** (rezultati objavljenega članka, S376) — ni začasno. Alternativi: 90 (spodnja meja RKC »Over 10 years«, S378), 84 (Dogs Trust > 7 let, S14). Najpoznejši začetek starosti med psi v aplikaciji | #273 |
| 2026-10-19 | pravilo runbooka | učenje **1,0×** (ni na Corenovi lestvici → kot mešanček; enako havanski bišon) | S35 (tabela ne navaja pasme) | #273 |
| 2026-10-19 | pravilo runbooka | teža 7,7–10,4 kg, rast do 9–12 mesecev; hrana, voda in čiščenje kot pri border collieju | PetMD S381 »typically weighing 17–23 pounds« = povprečja standarda AKC S380 (»approximately 23 pounds for males, 17 pounds for females«); FCI S377 teže ne navaja; PetMD S10 (razred »Small«) | #273 |
| 2026-10-19 | pravilo runbooka (M5-R13) | česanje **1× na teden** (zmršena dlaka po 1 zamujenem) | RKC S378 »Grooming: Once a week«; PetMD S381 in AKC S383 brez števila. Močno linjenje enkrat do dvakrat na leto (PetMD) v igri ni modelirano | #273 |
| 2026-10-19 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S381 »Shiba Inu can thrive with proper socialization and desensitization when they are puppies.« | #273 |
| 2026-10-19 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« po obroku | noben vir ne svetuje premora med obrokom in gibanjem | #273 |
| 2026-10-19 | pravilo runbooka (»nova oznaka«) | **nova oznaka** `independent_experienced_owner` — »samostojen in trmast — najbolje z izkušenim lastnikom« / »independent and stubborn — best with an experienced owner« | AKC S383 »Due to their stubborn nature, a Shiba Inu is a better pick for an experienced dog owner.«; PetMD S381 izkušeni lastniki s potrpežljivostjo. Obstoječa `experienced_owner` pravi »močan pes«, česar noben vir o šibi ne trdi | #273 |
| 2026-10-19 | pravilo runbooka | upoštevajte: bolje za starejše otroke (`older_children`), potrebuje miselne izzive (`needs_mental_stimulation`), izpada mu dlaka (`sheds`). Brez oznake »primeren za« | AKC S383 »happiest in a home with experienced, attentive dog owners and older children«; PetMD S381 »might not be a good fit for families with very young children«, »require lots of mental stimulation and exercise«; RKC S378 »Sheds: Yes«, Dogs Trust S382 »shed heavily«. RKC »Small house« → ni `apartment`; PetMD odsvetuje stanovanja s skupnimi stenami (»screaming«) | #273 |
| 2026-10-19 | pravilo runbooka (§3) | **ni pasma z vprašanjem dobrobiti** → brez oznake dobrobiti; marketing: **v aplikaciji da, v marketingu da** — nikoli kot pes za majhne otroke ali začetnike, nikoli brez povodca na prostem | RKC Breed Watch kategorija 1 (S378, S379) | #273 |
| 2026-10-19 | pravilo runbooka (§3) | barve za AI in izbirnik: **rdeča** (portret), **črna s porjavelimi oznakami**, **sezamova** — vedno z »urajiro« (smetanasto bela spodnja stran, lica, strani gobca); nikoli krem, bela ali lisasta | FCI S377 »Colour: Red, black and tan, sesame, black sesame, red sesame.«; AKC S380 (tri barve z urajiro; krem / bela / lisasta = »very serious fault«) | #273 |
| 2026-10-19 | pravilo runbooka (§2.3) | seznam za vzreditelja: **najboljša praksa** — rentgen kolkov (BVA/KC), DNA test za gangliozidozo GM1 in GM2 | RKC S379 (starejši naslov strani pasme; seznam »Good Practice« je prazen) | #273 |
| 2026-10-19 | predlog Claude, David lahko spremeni | ime SL »šiba inu« (slug `siba-inu`); iskalni ključi tudi »shiba«, »shiba inu«, »japanese shiba inu«, »japonski šiba inu« | raba v slovenščini; KZS ni preverjen | #273 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »šiba inu«, »shiba« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-46 | 2026-10-19 |
| Pravila po starosti (2.000 → 6.000 korakov, odrasel 6.000, starejši 4.500 od 131 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-46 | 2026-10-19 |
| Česanje 1× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-19 |
| Nova oznaka »samostojen in trmast — najbolje z izkušenim lastnikom« | 🛠 | 💶 izziv | M5-R10-46 | 2026-10-19 |
| Barva (izbirnik barve: rdeča, črna s porjavelimi oznakami, sezamova) | 🛠 | 💶 izziv | M5-R21 | 2026-10-19 |
| AI portret v registru | 🗓 čaka na naslednjo ročno namestitev produkcije (D18) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/shiba-inu`, `/sl/zivali/psi/siba-inu`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal šiba inu — 42. najbolj priljubljeno pasmo po AKC 2024 in 42. plačljivo pasmo psa. 7 novih virov (S377–S383). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do ene ure gibanja), starejši šele od dobrih 10 let in pol 4.500 — šiba je ena najdlje živečih pasem (mediana 14,6 leta v veliki britanski študiji). Česanje enkrat na teden in socializacija mladička sta del izziva od prvega dne; nova oznaka pove, da je šiba samostojna in trmasta.
**Zakaj je pomembno:** Šiba je majhna, čedna in zelo priljubljena na spletu — videti je kot »lahek« pes. Viri povedo drugo plat: je samostojna in trmasta, najbolje se znajde pri izkušenem lastniku in s starejšimi otroki, potrebuje miselne izzive, močno linja, brez povodca sme le v ograjenem prostoru, zobe pa ji je treba ščetkati vsak dan. In živi lahko 15 let — to je dolgo obdobje odgovornosti.
**Kako povedati** — starši: »Šiba inu je majhen, a samosvoj pes: potrebuje potrpežljivega, doslednega lastnika, uro gibanja na dan in veliko izzivov za glavo, živi pa lahko tudi 15 let. V PetPrepu boste videli, ali otrok vzdrži 12 tednov.« · otroci: »Šiba je japonski pes, ki ima rad svojo glavo. Sprehajaš ga vsak dan, ga enkrat na teden počešeš in mu pomagaš spoznavati svet, ko je še mladiček.« · vlagatelji / partnerji: »Osemindvajseta pasma, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal (vseh plačljivih psov je zdaj 42) — z viri, testi in pravili; kjer obstoječa oznaka ni ustrezala virom, je zagon po pravilih dodal novo, namesto da bi pretiraval.«

## Odprto za Davida

- **Potrebe brez mehanike (needs-david):** vsakodnevno ščetkanje zob (PetMD S381) → **M5-R15**; čiščenje ušes enkrat na teden pri psu z alergijami in redno kopanje (PetMD S381) → **M5-R20**; **sprehod brez povodca samo v ograjenem prostoru** (AKC S383) → za to še ni naloge v ROADMAP — **potrebna nova naloga (David)**.
- **Nova oznaka** `independent_experienced_owner` (besedilo Claude): ali jo David sprejme ali raje razširi obstoječo `experienced_owner` (ki pravi »močan pes«)?
- **Otroci:** `older_children` pravi »majhen pes — bolje za starejše otroke«; viri pa razlog vidijo v tesnobi in značaju (PetMD, AKC), ne v velikosti. FCI šibo imenuje »Small-sized dog«, zato oznaka ni napačna — a David lahko želi drugo besedilo.
- **Ni podatkov** o puščanju samega doma, sobivanju z drugimi ljubljenčki, lovskem nagonu (le lovska zgodovina) → ni oznak `needs_company`, `other_pets`, `chases_small_pets`.
- **Velikost:** RKC »Small-Medium« → nova kategorija `small_medium` v registru (besede na spletni strani Claude); Dogs Trust »Small«.
- **Ime:** »šiba inu« ni preverjeno pri KZS.
- **Spletna stran:** nova ključa zdravja `skin_ear_allergies`, `gum_disease_daily_brushing` in testa `dna_gm1`, `dna_gm2` (besede Claude); izvor Japonska.
- **Portret:** naredi ga prvi samodejni zagon po naslednji ročni namestitvi produkcije (D18).
