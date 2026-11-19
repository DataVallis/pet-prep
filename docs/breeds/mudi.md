# Mudi (Mudi)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-126 · 2026-11-19

| | |
|---|---|
| Ključ / slug | `mudi` / `mudi` (SL `mudi`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1170 — v izbirniku za angleškim pointerjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `mudi` (viri S948–S953; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: RKC ne priporoča testov pred vzrejo (`none_recommended`, S950) |
| Izvor | FCI št. 238, skupina 1 (ovčarski in pastirski psi), sekcija 1 (ovčarji), Madžarska (S948, S949) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 122. mesto »Mudi« (S174, zabeleženo 2026-11-18 v vrstici 125) |
| PR-ji | pet-prep #1385 (pasma) · portret pet-prep (sledi) · barvne različice pet-prep (sledi) · spletna stran: pet-prep-website `feat/register-mudi` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-19 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC Breeds A–Z S950 »Up to 1 hour per day« (»up to N« → N). PetMD S952 »at least one or two hours a day« → po pravilu razpona prav tako 60 | #1385 |
| 2026-11-19 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 | AAHA S11; starejši = 0,75 × spodnja meja RKC **»Over 10 years«** (S950) = 90 mesecev. McMillan 2024 (S123) in VetCompass nista dala vrednosti (pasma je v britanskem registru šele od 1. 7. 2025, S951). Ni izbrano: 84 (Dogs Trust »> 7 let«, S14), 108 (spodnja meja PetMD 12 let, S952) | #1385 |
| 2026-11-19 | pravilo runbooka | učenje **1,0×** | Coren mudija ne uvršča (S35) | #1385 |
| 2026-11-19 | pravilo runbooka | teža **8,2–13,2 kg** (18–29 lb, oba spola — PetMD; FCI in UKC teže ne navajata), višina psi 41–47 cm / psice 38–44 cm (FCI), rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | PetMD S952 »reaching 15–18.5 inches tall and 18–29 pounds.«; FCI S949 »41cm – 47cm …« / »38cm – 44cm …«; PetMD S10 (razred »Medium«) | #1385 |
| 2026-11-19 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | RKC S950 »Grooming: Once a week«; PetMD S952 »a few times a week« nima številke → številka RKC | #1385 |
| 2026-11-19 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S952 »need socialization from an early age to be comfortable around children, other pets, and new people.« | #1385 |
| 2026-11-19 | pravila »Breed care tasks« (David 2026-10-24) | **brez** naloge za zobe, kremplje, ušesa, kopanje ali frizerja | noben vir A/B ne navaja pogostosti (PetMD: ušesa po navodilu veterinarja, kopanje »on occasion«) | #1385 |
| 2026-11-19 | pravilo runbooka (M5-R16) | **brez** opozorila »počakaj s sprehodom« | ni citata o premoru po obroku | #1385 |
| 2026-11-19 | pravilo runbooka (vsaka potreba z virom) | miselni izzivi: že obstoječa šola in igra — brez nove mehanike | RKC S950 »they do require an outlet for their energy and intelligence.« | #1385 |
| 2026-11-19 | pravilo runbooka | brez poletnega opozorila za sprehod | ni ploskoobrazna pasma | #1385 |
| 2026-11-19 | pravilo runbooka | oznake: primeren za **»družinsko življenje«**; upoštevajte **»potrebuje miselne izzive«**, **»izpada mu dlaka«** | RKC S950 »Loyal and affectionate with their families«; »they do require an outlet for their energy and intelligence.«; ključni podatek »Sheds: Yes« | #1385 |
| 2026-11-19 | pravilo runbooka | **brez** `children` / `small_children` (ni izrecne izjave), `experienced_owner` (ta čip pomeni močnega psa — mudi je srednje velik), `independent_experienced_owner` (RKC S950 »best suited to an experienced owner« bi ustrezal, a ključ je po testu šiba inuja rezerviran zanj — glejte Odprto za Davida), `long_daily_exercise` / `active_family` (do 1 ure), `apartment` / `large_home` (RKC »Small house«), `chases_small_pets` (ni »high prey drive«), `secure_fencing` (PetMD »Never leave your Mudi outside unsupervised« zaradi skakanja — ni izjave o ograji), `shorter_lifespan` (RKC »Over 10 years«); nikoli »hypoallergenic« | S950, S952 | #1385 |
| 2026-11-19 | pravilo runbooka (§3, dobrobit) | brez opozorilnega čipa (RKC Breed Watch: »Currently no points of concern …«); **v aplikaciji da, v marketingu da** — nikoli kot »hipoalergen«, vedno z opombo o izkušenem lastniku | S950; S92 (le ploskoobrazne pasme) | #1385 |
| 2026-11-19 | pravilo runbooka (§3) | barve za AI in izbirnik: **črn** (privzeto za portret), bel, rumenorjav, pepelnat (moder), rjav, pepelnato rjav, marmoriran (cifra, na črni osnovi = »blue merle«); naravni rep | FCI S949 / S948: black, white, fawn, ash, liver, ash-brown, cifra (merle); UKC S953 »Patterns: Solid and Merle.«; uteži brez vira → `verified: false` | #1385 |
| 2026-11-19 | pravilo runbooka (§2.3) | seznam za vzreditelja: **»testi niso priporočeni«** (`none_recommended`) | RKC S950 »At this time this breed does not have any recommended pre-breeding health screening in place« | #1385 |
| 2026-11-19 | pravilo runbooka | zdravstveni ključi registra: `hip_elbow_dysplasia`, `epilepsy`, `cataracts`, `kneecap_luxation`; brez odstotkov, brez novega ključa | PetMD S952 | #1385 |
| 2026-11-19 | predlog Claude, David lahko spremeni | EN »Mudi« (AKC / FCI; RKC »Hungarian Mudi (Imp)«), SL »Mudi«; iskanje tudi »hungarian mudi«, »madžarski mudi«, »madzarski mudi« | strani KZS nisem dosegel | #1385 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »mudi«, »madžarski mudi« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-126 | 2026-11-19 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 90. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-126 | 2026-11-19 |
| Česanje 1× na teden, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-11-19 |
| Barve (izbirnik: 7 barv) | 🛠 | 💶 izziv | M5-R21 | 2026-11-19 |
| AI portret v registru | 🗓 sledi v tem zagonu | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/mudi`, `/sl/zivali/psi/mudi`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal mudija — srednje velikega, živahnega madžarskega ovčarja s pokončnimi ušesi in valovito, svetlečo dlako, 122. najbolj priljubljeno pasmo po AKC 2024 in 116. pasmo psa v aplikaciji. 6 novih virov (S948–S953), med njimi stran Royal Kennel Cluba, ki je pasmo priznal 1. 7. 2025. Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do 1 ure gibanja) in česanje enkrat na teden.
**Zakaj je pomembno:** Mudi je zvest in ljubeč z družino, a samostojen, glasen pes z veliko delovne vneme: RKC pravi, da najbolj sodi k izkušenemu lastniku, ki mu da izzive, red in »službo«. Prav to — vsakdanji miselni izzivi in dosledno učenje — je tisto, kar lahko družina preizkusi v PetPrepu, preden psa pripelje domov.
**Kako povedati** — starši: »Mudi ne potrebuje ur sprehodov, potrebuje pa delo za glavo in izkušeno roko. V PetPrepu boste videli, ali vaš otrok 12 tednov zapored zmore vsakodnevni sprehod, šolo in skrb.« · otroci: »Mudi je pameten madžarski ovčarček s pokončnimi ušesi. Rad se uči novih trikov — z njim greš vsak dan na sprehod in ga enkrat na teden počešeš.« · vlagatelji / partnerji: »116. pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal z viri — tokrat neposredno s strani Royal Kennel Cluba ter standardov FCI in UKC.«

## Odprto za Davida

- **Življenjska doba / starejše obdobje:** uporabljena je spodnja meja RKC »Over 10 years« → starejši od 90. meseca. PetMD navaja 12–14 let (to bi bilo 108). Vrstice v tabeli Dogs Trust (S123, McMillan 2024) ni bilo mogoče preveriti.
- **Izkušen lastnik:** RKC (S950) pravi »The Mudi is best suited to an experienced owner who can provide the stimulation, structure and purpose this spirited breed needs«, PetMD (S952) »independent thinker«. Čip »samostojen in trmast — najbolje z izkušenim lastnikom« bi ustrezal, a `ShibaInuBreedTest` zahteva, da ga ima samo šiba inu (test druge pasme zagon ne sme spreminjati). Odločite, ali naj ga dobi tudi mudi (ali nov ključ).
- **Teža:** samo PetMD (18–29 lb, brez delitve po spolu); trenutni standard FCI (2024) in UKC teže ne navajata. Starejšega besedila FCI (2004) nisem prebral.
- **Barve:** barvni razdelek standarda FCI je orodje vrnilo povzeto (ne dobesedno); seznam se ujema s stranjo FCI (S948) in UKC (S953). Marmorirana (cifra) je narisana na črni osnovi.
- **Ime:** »Mudi« ni preverjen na straneh KZS. Potrdite ali popravite (v slovenskih stavkih se beseda lahko bere kot glagol »mudi«).
- **Skupni dokumenti:** števila pasem v skupnih dokumentih (FEATURES, PARENTS …) se s to pasmo ne spremenijo samodejno — po pravilu runbooka jih zagon ne ureja.
