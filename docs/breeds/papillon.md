# Papijon (Papillon)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-48 · 2026-10-20

| | |
|---|---|
| Ključ / slug | `papillon` / `papillon` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 440 — v izbirniku za rodezijskim ridgebackom |
| Vir podatkov | `docs/research/dog-data/data.json` → `papillon` (viri S395–S401; ponovno S8, S10, S11, S14, S24, S35, S45, S174); preverjanje vzreditelja K26, K27 |
| Izvor | FCI št. 77 (kontinentalni pritlikavi španjel, različici papijon — pokončna ušesa — in falen — povešena ušesa), skupina 9, sekcija 9, Belgija / Francija (S395, S396); igra gradi papijona s pokončnimi ušesi |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 44. mesto »Papillon« (S174) |
| PR-ji | pet-prep #288 (pasma, združen `75652ff`) · portret #289 (2026-10-20) · spletna stran (sledi) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-20 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **30 min/dan → 3.000 korakov**; starejši 23 min → 2.300; mladiček 10 min × starost do 30 (pri 3 mesecih) | RKC S397 »Exercise: Up to 30 minutes per day« (»do N« → N); PDSA S398 »Around 30 minutes exercise daily is usually sufficient …« da isto število | #288 |
| 2026-10-20 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108** mesecev; prihod 2 / 9 / 36 / 108 — **začasno** | 0,75 × spodnja meja RKC »Lifespan: Over 12 years« (S397; PDSA S398 enako). McMillan 2024 (14,5 leta → 131) je dosegljiv samo prek Wikipedije (S401, raven C), PetMD 14–16 let (S400) → 126; tabela Dogs Trust (S123) ni bila dosegljiva. Alternativa: 84 (Dogs Trust > 7 let) | #288 |
| 2026-10-20 | pravilo runbooka | učenje **1,8×** (Coren rang 8, skupina »Brightest«) | S35 (tudi S401) | #288 |
| 2026-10-20 | pravilo runbooka | teža 1,5–5 kg (razpon obeh težnih kategorij FCI), rast do 8–12 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S396: pod 2,5 kg (najmanj 1,5 kg) ter 2,5–4,5 kg (psi) / 2,5–5 kg (psice); PDSA S398 »2-4 kg« samo alternativa; PetMD S10 (razred »Toy«) | #288 |
| 2026-10-20 | pravilo runbooka (M5-R13) | česanje **3× na teden** (zmršena dlaka po 2 zamujenih) | RKC S397 »Grooming: More than once a week« (brez števila) → PDSA S398 »… two-three times per week is usually enough …« → zgornja meja 3; PetMD S400 »a few times a week« (brez števila) | #288 |
| 2026-10-20 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S398 »Training, along with socialisation, is very important at a young age« | #288 |
| 2026-10-20 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« po obroku | noben vir A/B pri papijonu ne omenja premora med obrokom in gibanjem | #288 |
| 2026-10-20 | pravilo runbooka | oznake: primeren za življenje v stanovanju (`apartment`), družinsko življenje (`family_pet`), družino z otroki (`children`), dom z drugimi ljubljenčki (`other_pets`); upoštevajte: česanje večkrat na teden (`frequent_grooming`), potrebuje miselne izzive (`needs_mental_stimulation`), rad ima družbo (`needs_company`), izpada mu dlaka (`sheds`). Brez nove oznake | RKC S397 »Size of home: Flat/ Apartment«, »Grooming: More than once a week«, »Sheds: Yes«; PDSA S398; PetMD S400 »Papillons are great family dogs because they are affectionate«, »Papillons live well with other dogs, cats, kids, adults, and seniors alike.«, »They are active, playful, and need daily exercise and mental stimulation.«, »However, some Papillons may develop separation anxiety.« | #288 |
| 2026-10-20 | pravilo runbooka | oznaka za otroke **z opozorilom v virih**, brez oznake za majhne otroke | PetMD S400: zelo majhni ali zelo razigrani otroci lahko majhnega psa po nesreči poškodujejo (zabeleženo v `behaviour.children`) | #288 |
| 2026-10-20 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot igrača ali modni dodatek in nikoli z majhnimi otroki brez nadzora (predlog Claude) | RKC Breed Watch kategorija 1 (S397, brez točk); ni pes s sploščenim obrazom | #288 |
| 2026-10-20 | pravilo runbooka (§3) | barve za AI: **bela z lisami** — rdeča (portret, najvišja utež), soboljasta, tribarvna (črno-bela s porjavelimi oznakami), črna, limonasta; barva vedno čez obe ušesi in oči, bela lisa po sredini glave; nikoli povsem bel ali brez bele; pokončna »metuljeva« ušesa | FCI S396 »All colours are admitted on a coat with a white background.«; AKC-standard S399 »Always parti-color or white with patches of any color(s).«, izključitev »An all white dog or a dog with no white.« (izbira petih primerov in uteži sta naši, `verified => false`) | #288 |
| 2026-10-20 | predlog Claude, David lahko spremeni | ime SL »papijon« (slug `papijon`); iskalni ključi tudi »phalène«, »falen«, »butterfly dog« (ne »kontinentalni pritlikavi španjel« — beseda »pritlikavi« bi v iskanju zadela tudi pritlikavega šnavcerja in pomeranca) | raba v slovenskih medijih; KZS ni preverjen | #288 |
| 2026-10-20 | pravilo runbooka (§2.3) | preverjanje vzreditelja: DNA-test Pap-PRA1 (progresivna atrofija mrežnice pri papijonih), DNA-test vWD1 (von Willebrandova bolezen), DNA-test NAD (nevroaksonska distrofija) — vse »druga shema« | RKC K26 (stran DNA-testa Pap-PRA1), RKC K27 (paket DNA-testov za papijona); stran RKC (S397) testov za minimum / najboljšo prakso ne navaja | #288 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 3× na teden«, iskanje »papijon«, »papillon«, »phalène« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-48 | 2026-10-20 |
| Pravila po starosti (2.000 → 3.000 korakov, odrasel 3.000, starejši 2.300 od 108 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-48 | 2026-10-20 |
| Česanje 3× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-20 |
| Barva (izbirnik barve, pet barv standarda — vse bele z lisami) | 🛠 | 💶 izziv | M5-R21 | 2026-10-20 |
| AI portret v registru (sprejet v prvem poskusu; bela z rdečimi lisami, pokončna metuljeva ušesa, bela lisa po sredini glave) | ✅ | — | M5-R11-04 | 2026-10-20 |
| Stran na petprep.si (`/animals/dogs/papillon`, `/sl/zivali/psi/papijon`) | 🗓 šele s portretom; nato »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal papijona — 44. najbolj priljubljeno pasmo po AKC 2024 in 44. plačljivo pasmo psa. 7 novih virov (S395–S401) in 2 nova vira za preverjanje vzreditelja (K26, K27). Odrasel papijon v igri potrebuje 3.000 korakov na dan (The Royal Kennel Club: do 30 minut gibanja), starejši od 9 let 2.300; uči se hitreje kot mešanček (Coren: 8. mesto, med desetimi najbistrejšimi pasmami). Česanje 3× na teden in socializacija mladička sta del izziva od prvega dne.
**Zakaj je pomembno:** Papijon tehta le 1,5–5 kg in potrebuje malo sprehodov, zato deluje kot »lahek« pes. Viri pa pravijo: je zelo bister in potrebuje vsakodnevne miselne izzive, nekateri težko ostajajo sami, dlako je treba česati večkrat na teden, zobe ščetkati vsak dan (PetMD), majhni otroci pa ga lahko po nesreči poškodujejo. Prav to pokaže 12 tednov PetPrepa.
**Kako povedati** — starši: »Papijon potrebuje kratke sprehode, a veliko pozornosti — igre za glavo, česanje večkrat na teden in družbo. V PetPrepu boste videli, ali otrok to zmore 12 tednov zapored.« · otroci: »Papijon je majhen pes z ušesi kot metuljeva krila. Je zelo pameten in se hitro uči — vsak dan ga pelješ na kratek sprehod, ga večkrat na teden počešeš in se z njim nežno igraš.« · vlagatelji / partnerji: »Trideseta pasma, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal (vseh plačljivih psov je zdaj 44) — z viri, testi in pravili. Staršem svetujemo, naj vzreditelja vprašajo za DNA-teste staršev (Pap-PRA1, vWD1, NAD) — brez statistik.«

## Odprto za Davida

- **Meja starejšega psa 108 je začasna:** spodnja meja RKC (12 let). McMillan 2024 navaja 14,5 leta (prek Wikipedije, S401) → 131 mesecev; PetMD 14–16 let → 126; preveriti v tabeli Dogs Trust (S123), ko bo dosegljiva.
- **Potrebe brez mehanike (needs-david):** ščetkanje zob **vsak dan** (PetMD S400) → **M5-R15**; pregled ušes **vsak teden** (PetMD S400, tudi resice na ušesih, ki se rade zmršijo) → **M5-R20**; striženje krempljev **na 4–6 tednov** (PetMD S400) → **M5-R19**.
- **Otroci:** oznaka `children` temelji na PetMD (S400) »live well with … kids«; isti vir opozarja na zelo majhne otroke. Ali naj papijon dobi tudi oznako `older_children` (noben vir ne pravi izrecno »bolje za starejše otroke«)?
- **Teža:** v igri razpon obeh kategorij FCI 1,5–5 kg; PDSA navaja 2–4 kg.
- **Ime:** »papijon« ni preverjeno pri KZS.
- **Spletna stran:** nov izvor FCI »Belgija / Francija« (`BE-FR`), nova ključa zdravja `open_fontanelle` in `collapsing_trachea`, nova ključa testov `dna_pap_pra1` in `dna_nad` (besede Claude).
