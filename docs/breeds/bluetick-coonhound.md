# Bluetick coonhound (Bluetick Coonhound)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-141 · 2026-11-25

| | |
|---|---|
| Ključ / slug | `bluetick_coonhound` / `bluetick-coonhound` (SL `bluetick-coonhound`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1320 — v izbirniku za irskim terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `bluetick_coonhound` (viri S1060–S1061; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: `not_found` |
| Izvor | ZDA (Dogs NSW S1061: »originating in the United States«); AKC skupina goničev. **Ni priznan pri FCI in RKC** (po prebranih virih) → v registru brez podatka FCI, brez strani RKC / PDSA |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 137. mesto »Bluetick Coonhound« (S174, vrstica 141 čakalne vrste) |
| PR-ji | pet-prep #1620 (pasma) · portret in barvne različice: sledita · spletna stran: pet-prep-website `feat/register-bluetick-coonhound` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-25 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **40 min/dan → 4.000 korakov**; starejši 30 min → 3.000; mladiček 10 min × starost do 40 (pri 4 mesecih) | Dogs NSW S1061 »Exercise: 40 min/day« (ena številka N → N; precedens ameriški goli terier). Strani RKC / PDSA za to pasmo ne obstajajo; PetMD S1060 zahteva »physical exercise every day« brez minut | #1620 |
| 2026-11-25 | pravilo runbooka | obdobja mladiček / mlad / odrasel: 0 / 9 / 36; prihod 2 / 9 / 36 | AAHA S11, PetMD S10 | #1620 |
| 2026-11-25 | **predlog Claude — čaka Davida** | starejši od **99. meseca** (tudi prihod starejšega psa); `verified: false` | najbližja vrstica pravila: 0,75 × spodnja meja PetMD »average lifespan of 11–12 years« (S1060). Mediane McMillan 2024 / VetCompass ni (pasma je v Veliki Britaniji redka), strani RKC ni. VCA »12 to 14 years« je prišel le kot povzetek (ne dobesedno) → 108 bi bila alternativa | #1620 |
| 2026-11-25 | pravilo runbooka | učenje **1,0×** | pasma ni v Corenovi lestvici (S35, prebrano 2026-11-25: le »Black and Tan Coonhound«, druga pasma) → pravilo »unranked« | #1620 |
| 2026-11-25 | pravilo runbooka | teža **24,9–36,3 kg** (psi, 55–80 lb) / **20,4–29,5 kg** (psice, 45–65 lb), višina 55,9–68,6 / 53,3–63,5 cm, rast do 15–18 mesecev; hrana, voda in čiščenje kot pri border collieju | standard pri Dogs NSW S1061 »Weight for males 55 to 80 pounds, females 45 to 65 pounds.«; PetMD S10 (razred »Large«) | #1620 |
| 2026-11-25 | pravilo runbooka (M5-R13) | **česanje 1× na teden** | PetMD S1060 »The Bluetick’s short fur needs only a once-a-week brushing to control shedding.« | #1620 |
| 2026-11-25 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s **splošno vrstico** | vir ne navaja potrebe psa samega (PetMD: »Talk to your Bluetick Coonhound breeder about how they approach socialization.«) → brez lastnega citata | #1620 |
| 2026-11-25 | pravila »Breed care tasks« (David 2026-10-24) | **brez** nalog za zobe, ušesa, kremplje, kopanje ali frizerja | noben vir ne navaja pogostosti (PetMD: ušesa »how often« vprašajte veterinarja; kopanje »depend on how they spend their time outdoors«) | #1620 |
| 2026-11-25 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« | PetMD S1060 omenja napenjanje želodca in »exercising right after eating« le kot dejavnik tveganja, brez nasveta o premoru — ni dobesednega citata | #1620 |
| 2026-11-25 | pravilo runbooka (M5-R14) | brez poletnega opozorila za sprehod | ni ploskoobrazna pasma | #1620 |
| 2026-11-25 | pravilo runbooka | oznake (upoštevajte): **»močan pes — potrebuje izkušenega lastnika«**, **»potrebuje miselne izzive«**, **»rad pobegne — potrebuje varno, visoko ograjo«**; primeren za: brez | PetMD S1060 »Blueticks may be better suited for older children, and experienced pet parents who know how to train and manage a dog …«, »… extensive mental stimulation and physical exercise every day.«, »… leashes and fenced yards are a must.« | #1620 |
| 2026-11-25 | pravilo runbooka | **brez** čipov za otroke (Dogs NSW »gets along well with children« proti PetMD »better suited for older children« — viri si nasprotujejo), `needs_company` (PetMD ločitvena tesnoba proti Dogs NSW »Often alone«), `chases_small_pets` (ni citata »high / strong prey drive«), `family_pet`, `active_family`, `apartment`, `large_home`, `sheds` (PetMD le »moderate shedding«); **nikoli »hypoallergenic«** | S1060, S1061 | #1620 |
| 2026-11-25 | pravilo runbooka (§3, dobrobit) | brez opozorilnega čipa; **v aplikaciji da, v marketingu da** — nikoli kot lahek pes za majhne otroke ali začetnike | nobeno veterinarsko združenje ne odsvetuje oglaševanja te pasme (S92 le ploskoobrazne pasme); pasma ni v RKC (brez Breed Watch) | #1620 |
| 2026-11-25 | pravilo runbooka (§3) | AI videz: kratka sijoča dlaka, dolga nizko nasajena ušesa, temno rjave oči, črn nos, rep visoko v polmesecu; 2 barvi za izbirnik: **modro pegast z rjavimi oznakami** (privzeta, portret) in **modro pegast** (brez rjavih oznak); uteži brez vira → `verified: false` | standard S1061 »Preferred color is a dark blue, thickly mottled body, spotted by various shaped black spots …«, »With or without tan markings …«, »No other colors allowed.«; PetMD S1060 »they commonly sport tan markings as well.« | #1620 |
| 2026-11-25 | pravilo runbooka (§2.3) | seznam za vzreditelja: `not_found` | ni strani RKC; stran OFA CHIC orodje ni smelo odpreti | #1620 |
| 2026-11-25 | pravilo runbooka | zdravstveni ključi registra: `bloat_gdv`, `ear_infections_long_ears` (oba obstoječa); brez odstotkov | PetMD S1060 | #1620 |
| 2026-11-25 | predlog Claude, David lahko spremeni | EN »Bluetick Coonhound« (AKC), SL »bluetick coonhound«; iskanje tudi »bluetick«, »blue tick coonhound«, »coonhound« | slovenskega imena pri KZS nisem našel | #1620 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »bluetick«, »coonhound« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-141 | 2026-11-25 |
| Pravila po starosti (2.000 → 4.000 korakov do 4. meseca, odrasel 4.000, starejši 3.000 od 99. meseca — predlog; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-141 | 2026-11-25 |
| Tedensko česanje, »nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / R17 | 2026-11-25 |
| Barve (izbirnik: 2 barvi) | 🛠 | 💶 izziv | M5-R21 | 2026-11-25 |
| AI portret v registru | 🗓 sledi po združitvi | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/bluetick-coonhound`, `/sl/zivali/psi/bluetick-coonhound`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal bluetick coonhounda — ameriškega goniča z modro pegasto dlako, 137. najbolj priljubljeno pasmo po AKC 2024 in 132. pasmo psa v aplikaciji (poleg mešančka). 2 nova vira (PetMD, Dogs NSW s standardom pasme). Odrasel pes v igri potrebuje 4.000 korakov na dan (Dogs NSW: 40 minut), otrok ga enkrat na teden počeše.
**Zakaj je pomembno:** goniča vodi nos — PetMD pravi, da sta povodec in dobro ograjen vrt nujna, pes potrebuje veliko miselnega dela, je glasnejši od povprečja in sodi k izkušenemu lastniku. To so stvari, ki jih družina mora vedeti, preden se odloči.
**Kako povedati** — starši: »Bluetick coonhound je prijazen, a samosvoj gonič, ki sledi vonju in glasno laja. V PetPrepu boste videli, ali otrok zmore vsakodnevni sprehod in tedensko česanje 12 tednov zapored.« · otroci: »Bluetick coonhound je velik pes z modrimi pegami in dolgimi ušesi. Vsak dan greš z njim na sprehod, enkrat na teden pa ga lepo počešeš.« · vlagatelji / partnerji: »132. pasma psa, ki jo je nočni zagon sam izbral po javni lestvici AKC in dodal z viri — tudi za pasmo, ki je FCI in britanski klub ne priznavata, z jasno označenim predlogom za odločitev.«

## Odprto za Davida

- **Starejše obdobje (99. mesec):** predlog Claude — 0,75 × spodnja meja PetMD 11 let; vrstica je `verified: false`. VCA (le povzetek) navaja 12–14 let → 108. Potrdite ali določite drugo mejo.
- **Gibanje 40 min:** po pravilu iz Dogs NSW (S1061 »40 min/day«); PetMD (S1060) in VCA (povzetek »Very high«) pasmo opišeta kot zelo energično — 40 minut je morda malo. Potrdite ali spremenite.
- **Premor po obroku (M5-R16):** PetMD (S1060) »exercising right after eating« navaja kot dejavnik tveganja za napenjanje želodca, ne pa kot nasvet o premoru → po pravilu brez opozorila. Odločite, ali pasma dobi opozorilo »počakaj s sprehodom«.
- **Otroci in samota:** viri si nasprotujejo (Dogs NSW: dober z otroki, pogosto sam doma; PetMD: bolje s starejšimi otroki, ločitvena tesnoba) → brez čipov `children` / `older_children` / `needs_company`. Odločite.
- **Ime:** »bluetick coonhound« ni preverjen na straneh KZS. Potrdite ali popravite.
- **FCI:** status pri FCI ta zagon ni preveril na strani FCI (viri pasme FCI ne omenjajo).
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
