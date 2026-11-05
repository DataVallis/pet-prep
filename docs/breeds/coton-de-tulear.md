# Coton de Tulear (Coton de Tulear)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-96 · 2026-11-05

| | |
|---|---|
| Ključ / slug | `coton_de_tulear` / `coton-de-tulear` (SL `coton-de-tulear`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 900 — v izbirniku za srednjim šnavcerjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `coton_de_tulear` (viri S721–S728; ponovno S8, S10, S11, S14, S24, S35, S45, S54, S92, S174); seznam za vzreditelja: RKC (`listed`) — genetska raznolikost (minimum), pregled oči BVA/KC/ISDS (dobra praksa) |
| Izvor | FCI št. 283 »Coton de Tuléar«, skupina 9, sekcija 1 (bišoni in sorodne pasme), Madagaskar, pokroviteljstvo Francija (S721, S722) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 92. mesto »Coton de Tulear« (S174, prebrano 2026-11-05) |
| PR-ji | pet-prep #773 (pasma, združen `ce50ae6`) · portret pet-prep (ta PR; zagon `breed-portraits.yml` 41982964268, koda `ce50ae6`, brez namestitve; sprejet v prvem poskusu) · spletna stran: pet-prep-website `feat/register-coton-de-tulear` (PR sledi) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-05 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **30 min/dan → 3.000 korakov**; starejši 23 min → 2.300; mladiček 10 min × starost do 30 (pri 3 mesecih) | RKC S724 »Exercise: Up to 30 minutes per day« (»up to N« → N); PDSA S725 »Minimum exercise« 30 minut in »around 30-40 minutes exercise per day« se ujemata | #773 |
| 2026-11-05 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **108**; prihod 2 / 9 / 36 / 108 | AAHA S11; starejši = 0,75 × **12 let** — spodnja meja RKC »Lifespan: Over 12 years« (S724; PDSA S725 enako). McMillan 2024 za pasmo ni bil dosegljiv (le oznaka na sliki 5; tabela Dogs Trust S123 zavrnjena), mediane VetCompass ni | #773 |
| 2026-11-05 | pravilo runbooka | učenje **1,0×** | pasme ni na Corenovi lestvici (S35, 79 mest, preverjeno 2026-11-05) → kot mešanec (precedens havanski bišon) | #773 |
| 2026-11-05 | pravilo runbooka | teža **samci 4–6 kg, samice 3,5–5 kg** (FCI; v igri 3,5–6); višina samci 26–28 cm, samice 23–25 cm (FCI); rast do 8–12 mesecev (razred »Toy«); hrana, voda in čiščenje kot pri border collieju | FCI S722 »Weight: Males: From 4 kg to a maximum of 6 kg. Females: From 3,5 kg to a maximum of 5 kg.«; PetMD S10 | #773 |
| 2026-11-05 | pravilo runbooka (M5-R13) | česanje **vsak dan (7× na teden)**; skuštrana dlaka po 4 zamujenih | RKC S724 »Grooming: Every day«; PDSA S725 »Daily grooming is needed to keep their coat in tip-top condition.«; PetMD S726 »three to four times per week« = alternativa (4) | #773 |
| 2026-11-05 | pravila »Breed care tasks« (David 2026-10-24, M5-R20) | otrokova mini igra **nega ušes 1× na teden** | PetMD S726 »Check your Coton de Tulear's ears at least once a week for signs of infection.« (precedens papillon, aljaški malamut: tedenski pregled ušes) | #773 |
| 2026-11-05 | pravila »Breed care tasks« (M5-R19) | opomnik staršu za **kopanje vsake 4 tedne** (ne šteje v oceno) | PetMD S726 »… aside from a once-a-month bath.« → 28 dni | #773 |
| 2026-11-05 | pravila »Breed care tasks« | **brez** nalog za zobe, kremplje in frizerja | noben vir ne navaja pogostosti (RKC standard: dlaka se ne striže, razen okoli tačk, S723) | #773 |
| 2026-11-05 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S726 »With proper training and early socialization, however, your Coton de Tulear puppy will learn that every passing person isn't something to be concerned about.« (in »Socialization is equally important.«) | #773 |
| 2026-11-05 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila »počakaj s sprehodom« po obroku in **brez** opozorila za vročino | viri premora po obroku ne omenjajo; ni ploskoobrazna pasma (RKC S723 »Muzzle straight with definite underjaw.«, »Nose black with open nostrils.«) | #773 |
| 2026-11-05 | pravilo runbooka | primeren za: **življenje v stanovanju**, **družinsko življenje**, **družino z otroki**, **dom z drugimi ljubljenčki**, **dom, kjer želite manj dlak**; upoštevajte: »potrebuje česanje večkrat na teden«, »rad ima družbo« | RKC S724 »Size of home: Flat/ Apartment«, »Sheds: No«, »Grooming: Every day«; PDSA S725 »… making them excellent family pets.«; PetMD S726 »They generally get along great with children and other pets—especially when introductions are done properly—…«, »… can develop separation anxiety if her pet parents leave her alone for more than a few hours at a time.« | #773 |
| 2026-11-05 | pravilo runbooka | **brez** čipov `small_children` (PetMD s pogojem pravilnega uvajanja), `often_alone` (ločitvena tesnoba), `large_home`, `first_time_owner`, `chases_small_pets`, `sheds`, `shorter_lifespan`; nikoli »hypoallergenic« (PetMD sam: »There is actually no such thing as a truly hypoallergenic dog.«) | S724–S726 | #773 |
| 2026-11-05 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli kot hipoalergen pes ali kot pes, ki lahko ostane sam | RKC Breed Watch kategorija 1 (S724); ni izrecnega veterinarskega odsvetovanja oglaševanja | #773 |
| 2026-11-05 | pravilo runbooka (§3) | barva za AI in izbirnik: **bela** (edina barva standarda; portret); nikoli črne lise, rjava, trobarvna ali lise; dlaka naravna, nestrižena | FCI S722 »Ground colour: White.« (rahle svetlo sive / limonaste sence le na ušesih); RKC S723 »Any other colour or combination of colours unacceptable.«; utež ni iz vira (`verified=false`) | #773 |
| 2026-11-05 | pravilo runbooka (§2.3) | seznam za vzreditelja: **RKC** — genetska raznolikost (minimum), pregled oči BVA/KC/ISDS (dobra praksa); stran omenja tudi zdravstveno shemo kluba pasme (ni prebrana) | S724 | #773 |
| 2026-11-05 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia`, `bleeding_disorder_vwd`, `kneecap_luxation`, `pra_inherited_blindness`; brez odstotkov, brez novega ključa. Degenerativna mielopatija ostane le v `data.json` (besedilo na spletni strani obljublja DNA test, RKC pa ga za pasmo ne navaja — precedens bernski planšar) | PDSA S725, PetMD S726 | #773 |
| 2026-11-05 | predlog Claude, David lahko spremeni | EN in SL **»Coton de Tulear«** (v stavku »coton de tulear«); iskanje tudi »coton«, »coton de tuléar«, »cotonček«, »tulear«, »royal dog of madagascar« | FCI S721; slovenska raba v tisku (Svet24, S728, tier C) | #773 |
| 2026-11-05 | pravilo runbooka (§2.2) | izvor Madagaskar je nov v izvozu registra (`FCI_ORIGINS` → `MG`); besede za spletno stran v PR-ju spletne strani | FCI S721 | #773 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »coton«, »cotonček« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-96 | 2026-11-05 |
| Pravila po starosti (2.000 → 3.000 korakov pri 3 mesecih, odrasel 3.000, starejši 2.300 od 108. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-96 | 2026-11-05 |
| Česanje vsak dan, »Nova izkušnja« za mladička, nega ušes 1× na teden, opomnik staršu za kopanje (4 tedni) | 🛠 | 💶 izziv | M5-R13 / M5-R17 / M5-R19 / M5-R20 | 2026-11-05 |
| Barva (izbirnik: 1 barva standarda — bela) | 🛠 | 💶 izziv | M5-R21 | 2026-11-05 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«; bela, naravna nestrižena dlaka, rep čez hrbet) | — | M5-R11-04 | 2026-11-05 |
| Stran na petprep.si (`/animals/dogs/coton-de-tulear`, `/sl/zivali/psi/coton-de-tulear`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal coton de tulear — majhnega (3,5–6 kg), belega psa z Madagaskarja z mehko dlako kot bombaž (»coton« pomeni bombaž, Tulear je mesto na Madagaskarju); 92. najbolj priljubljeno pasmo po AKC 2024. 8 novih virov (S721–S728). Odrasel pes v igri potrebuje 3.000 korakov na dan (RKC: do pol ure gibanja), a česanje **vsak dan**; enkrat na teden mu otrok pregleda in očisti ušesa, staršem aplikacija vsake 4 tedne pripomni kopanje.
**Zakaj je pomembno:** Majhen, prijazen pes za stanovanje, ki se razume z otroki in drugimi živalmi — a ne mara biti dolgo sam in njegova dolga bela dlaka brez vsakodnevnega česanja hitro postane skuštrana. Prav vsakodnevna, »nevidna« skrb je tisto, kar otrok v PetPrepu preizkusi pred pravim psom.
**Kako povedati** — starši: »Coton de tulear potrebuje malo sprehoda, a vsak dan česanje in družbo — ni pes, ki bi ga lahko pustili samega cel dan. V PetPrepu boste videli, ali vaš otrok zmore vsakodnevno nego.« · otroci: »Bel kosmatinec kot oblaček — vsak dan ga počešeš, enkrat na teden mu pogledaš ušesa in rad je s tabo.« · vlagatelji / partnerji: »96. pasma v registru, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal z viri, nalogami nege in pravili — prvi pes z Madagaskarja.«

## Odprto za Davida

- **Starejše obdobje: 108 ali 128 mesecev.** Po pravilu runbooka 108 (0,75 × spodnja meja RKC »Over 12 years«). Wikipedia (S727, tier C) navaja **14,2 leta** iz študije 2024 (McMillan 2024, 44 psov) — to bi dalo **128**, a vrednosti v članku nisem mogel preveriti (pasma je le na sliki 5, tabela Dogs Trust zavrnjena). Če potrdite 14,2 leta, je potrebna podatkovna migracija (seeder samo vstavlja).
- **Česanje: 7 ali 4 na teden.** RKC »Every day« in PDSA »Daily« → 7 (uporabljeno); PetMD »three to four times per week« → 4. Odločite, če želite redkejše.
- **Ušesa** slonijo na enem stavku PetMD (S726, tedenski pregled ušes, tier B); noben drug vir ne navaja pogostosti. Potrdite ali odstranite.
- **Slovensko ime** »coton de tulear« je iz tiska (S728, tier C); strani KZS ali kluba nisem našel. Potrdite ali popravite.
- **Degenerativna mielopatija** (PetMD S726) ni na spletni strani, ker skupni stavek obljublja DNA test, ki ga RKC za pasmo ne navaja. Odločite, ali želite ožji ključ brez obljube testa.
- **Portret:** narejen 2026-11-05 (zagon 41982964268, brez produkcijske namestitve), sprejet v prvem poskusu. **Spletna stran:** Madagaskar je nov izvor — besede »Madagascar / Madagaskar« dodane v PR spletne strani.
