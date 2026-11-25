# Kerry blue terier (Kerry Blue Terrier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-139 · 2026-11-25

| | |
|---|---|
| Ključ / slug | `kerry_blue_terrier` / `kerry-blue-terrier` (SL `kerry-blue-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1300 — v izbirniku za bedlington terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `kerry_blue_terrier` (viri S1043–S1050; ponovno S10, S11, S14, S35, S45, S174); seznam za vzreditelja: AKC (K33), Dogs NZ (K45) |
| Izvor | FCI št. 3 »Kerry Blue Terrier«, skupina 3, sekcija 1 (veliki in srednji terierji), Irska (S1043, S1044) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 135. mesto »Kerry Blue Terrier« (S174, prebrano 2026-11-25) |
| PR-ji | pet-prep #1607 (pasma) · portret: pet-prep `feat/M5-R10-139-kerry-blue-terrier-portrait` · barvne različice: pet-prep `feat/M5-R10-139-kerry-blue-terrier-colours` · spletna stran: pet-prep-website `feat/register-kerry-blue-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-25 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček / mlad 10 min × starost do 60 (pri 6 mesecih) | strani RKC, PDSA in Woodgreen niso bile dosegljive / ne obstajajo → PetMD S1046 »about one hour of exercise every day« (eno število N → N) | #1607 |
| 2026-11-25 | **predlog Claude, čaka Davida** | obdobja 0 / 9 / 36 / **112**; prihod 2 / 9 / 36 / 112 (starejša meja in prihod `verified = false`) | AAHA S11; mediana McMillan 2024 **12,4 leta** je bila dosegljiva le prek Wikipedije (S1050, raven C), tabela Dogs Trust (S123) ni bila dosegljiva → 0,75 × 12,4 × 12 = 111,6 → 112 (precedens bernski planšarski pes / italijanski ptičar). Alternativi 90 (VCA spodnja meja 10 let), 108 (WebMD spodnja meja 12 let) | #1607 |
| 2026-11-25 | pravilo runbooka | učenje **1,2×** | Coren: 35. mesto, »Above Average Working Dogs« (S35) → pravilo »Above average« (27–39) | #1607 |
| 2026-11-25 | pravilo runbooka | teža **15–18 kg**, višina psi 45,5–49,5 cm, psice 44,5–48 cm, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S1043 »Dogs : 33 lbs to 40 lbs (15 to 18 kg).«, za psice »proportionately less« (brez števila — uporabljen razpon za pasmo, VCA S1048); velikostni razred »Medium« PetMD S10 | #1607 |
| 2026-11-25 | **predlog Claude, čaka Davida** (M5-R13) | česanje **4× na teden** (`verified = false`); skuštrana dlaka po 2 zamujenih | VCA S1048 »Brush and comb every other day« (vsak drugi dan = 3,5 → 4); pravilo runbooka nima vrstice za »vsak drugi dan«. PetMD / WebMD »at least weekly« → alternativa 1 | #1607 |
| 2026-11-25 | pravila »Breed care tasks« (David 2026-10-24) | **ščetkanje zob vsak dan**, **čiščenje ušes vsaka 2 tedna** (otrokovi mini igri), **opomnik za frizerja vsakih 6 tednov**, **opomnik za kopanje vsake 4 tedne** (staršu, brez točk) | PetMD S1046 »daily toothbrushing«, »every two to four weeks« (spodnja meja), »about every six weeks«, »about once a month«. Kremplji brez pogostosti → brez naloge | #1607 |
| 2026-11-25 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | WebMD S1047 »Owners must be willing to spend time training and socializing their pets.« | #1607 |
| 2026-11-25 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila po obroku in **brez** poletnega opozorila | noben vir ne navaja premora; pasma ni ploskoobrazna | #1607 |
| 2026-11-25 | pravilo runbooka | oznake: primeren za **družinsko življenje**, **otroke**, **dom, kjer želite manj dlak**; upoštevajte **pogosto nego dlake** in da **rad ima družbo** | PetMD S1046 »make wonderful pets for families …«, WebMD S1047 »Kerries are affectionate and good with children.« (opozorilo o uvajanju v opombah), »sheds very little«; VCA S1048 česanje vsak drugi dan; PetMD o samoti | #1607 |
| 2026-11-25 | pravilo runbooka | **brez** čipa `chases_small_pets` (viri pravijo »may be tempted to chase«, nihče ne imenuje »high prey drive«); nikoli »hypoallergenic« (PetMD »a good fit for some people with allergies« ostane le opomba) | pravilo §3 | #1607 |
| 2026-11-25 | pravilo runbooka (§3) | **v aplikaciji da, v marketingu da** | ni ploskoobrazna; ni čipa dobrobiti | #1607 |
| 2026-11-25 | pravilo runbooka (§3) | barve za AI in izbirnik: **moder** (portret, največja utež), moder s črnimi odtenki, temno skrilasto moder, svetlo modrosiv; nikoli črn, rjav ali bel odrasel pes | FCI S1043 »Blue of any shade with or without black points.«; AKC S1045 »from the deep slate to light blue gray«; mladički se rodijo črni (AKC); uteži brez vira → `verified: false` | #1607 |
| 2026-11-25 | pravilo runbooka (§2.3) | seznam za vzreditelja: ocena kolkov in pregled oči (ameriški klub, `other_scheme`), DNK test von Willebrandove bolezni 1 (Dogs NZ, obvezno) | K33, K45; stran RKC ni bila dosegljiva | #1607 |
| 2026-11-25 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia`, `dry_eye`, `bleeding_disorder_vwd`, `inherited_ataxia` (PNA); brez odstotkov. Kožne ciste / spikuloza niso v registru (ni ustreznega ključa) | WebMD S1047, VCA S1048, PetMD S1046 | #1607 |
| 2026-11-25 | predlog Claude, David lahko spremeni | EN »Kerry Blue Terrier«, SL **»Kerry blue terier«** (kot »Bedlington terier«); iskanje tudi »kerry blue«, »kerry« | FCI / AKC; strani KZS nisem našel | #1607 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 4× na teden«, iskanje »kerry«, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-139 | 2026-11-25 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 112. meseca — predlog; 4 → 3 → 2 obroka; učenje 1,2×) | 🛠 | 💶 izziv | M5-R10-139 | 2026-11-25 |
| Česanje 4× na teden, ščetkanje zob vsak dan, čiščenje ušes vsaka 2 tedna, opomnika za frizerja (6 tednov) in kopanje (4 tedne), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R15 / M5-R18 / M5-R14 / M5-R19 / M5-R17 | 2026-11-25 |
| Barva (izbirnik: 4 odtenki modre po standardu) | 🛠 | 💶 izziv | M5-R21 | 2026-11-25 |
| AI portret v registru | 🗓 po združitvi (runbook §8) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/kerry-blue-terrier`, `/sl/zivali/psi/kerry-blue-terier`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal kerry blue terierja — srednje velikega irskega terierja (15–18 kg, 44,5–49,5 cm) z mehko, valovito modro dlako; mladički se rodijo črni in posvetlijo do modre barve. 135. najbolj priljubljena pasma po AKC 2024. Osem novih virov (S1043–S1050). Odrasel pes v igri potrebuje 6.000 korakov na dan (PetMD: okoli ura gibanja), česanje 4× na teden, vsakodnevno ščetkanje zob, čiščenje ušes vsaka 2 tedna ter frizerja vsakih 6 tednov.
**Zakaj je pomembno:** Kerry malo linja, a je ena najzahtevnejših pasem za nego dlake. PetPrep pokaže, ali družina zmore redno nego in dovolj družbe za psa, ki ne mara biti sam.
**Kako povedati** — starši: »Kerry blue terier je ljubeč družinski pes, ki malo linja, a potrebuje veliko nege dlake, zob in ušes. V PetPrepu boste videli, ali vaš otrok to zmore.« · otroci: »Kerry je moder kodrast pes — štirikrat na teden ga počešeš, vsak dan mu očistiš zobe in greš z njim ven.« · vlagatelji / partnerji: »Samodejni nočni zagon je pasmo sam izbral po javni lestvici AKC in jo dodal z osmimi viri in petimi pravili nege iz veterinarskih virov.«

## Odprto za Davida

- **Starejša meja 112 mesecev (predlog):** mediana McMillan 2024 12,4 leta je bila vidna le prek Wikipedije; tabela Dogs Trust (S123), PMC in stran RKC niso bile dosegljive. Potrdite 112 ali izberite 90 (VCA) / 108 (WebMD).
- **Česanje 4× na teden (predlog):** VCA »every other day«; PetMD in WebMD »at least weekly«. Potrdite ali izberite drugo število.
- **Teža psic:** standarda FCI / AKC pravita le »proportionately less« — uporabljen razpon 15–18 kg za oba spola. Potrdite.
- **Mladički so črni:** standard dovoli črno do 18 mesecev; slike mladička v igri so lahko temnejše od portreta odraslega psa.
- **Kožne ciste / spikuloza:** v virih (VCA, WebMD), a za register ni ustreznega ključa — nov ključ potrebuje odločitev.
- **Stran RKC, PDSA, Woodgreen:** ni bila dosegljiva / ne obstaja — gibanje in nega sta iz PetMD / VCA / WebMD.
- **Ime:** »Kerry blue terier« v slovenščini ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** število pasem psov v FEATURES / predstavitvah se po pravilu »Own files only« ne spreminja v tem zagonu.
