# Islandski ovčar (Icelandic Sheepdog)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-147 · 2026-11-28

| | |
|---|---|
| Ključ / slug | `icelandic_sheepdog` / `icelandic-sheepdog` (SL `islandski-ovcar`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1380 — v izbirniku za barbetom |
| Vir podatkov | `docs/research/dog-data/data.json` → `icelandic_sheepdog` (viri S1100–S1109; ponovno S10, S11, S14, S24, S35, S45, S174); seznam za vzreditelja: oči in kolki (Dogs Victoria, K48) |
| Izvor | FCI št. 289 »Icelandic Sheepdog«, skupina 5 (špici in pasme pratipa), sekcija 3 (nordijski čuvaji in pastirski psi), brez preizkusa dela, Islandija (S1100) — nova država izvora `IS` v izvozu registra |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 143. mesto »Icelandic Sheepdog« (S174, prebrano 2026-11-28: »142 Barbet«, »143 Icelandic Sheepdog«) |
| PR-ji | pet-prep #1723 (pasma, združen `866723b` 2026-11-28) · portret: zagon `breed-portraits.yml` 46101350384 (rdeče-bel, sprejet v prvem poskusu), pet-prep PR `feat/M5-R10-147-icelandic-sheepdog-portrait` · barvne različice: `feat/M5-R10-147-icelandic-sheepdog-colours` · spletna stran: 🗓 pet-prep-website `feat/register-icelandic-sheepdog` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-28 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **40 min/dan → 4.000 korakov**; starejši 30 min → 3.000; mladiček 10 min × starost do 40 (pri 4 mesecih) | stran RKC ni bila dosegljiva, strani PDSA / Woodgreen / Dogs Trust ni → Dogs NSW S1104 »40 min/day« (ena številka N → N); WebMD, PetMD, VCA in AKC minut ne navajajo | #1723 |
| 2026-11-28 | pravilo runbooka | obdobja 0 / 9 / 36 / **108**; prihod 2 / 9 / 36 / 108 | AAHA S11; starejši = 0,75 × spodnja meja WebMD »12 to 14 years« (S1102; PetMD S1101 enako) × 12 = 108 (precedens barbet / saluki). Alternativa: 84 (Dogs Trust > 7 let) | #1723 |
| 2026-11-28 | pravilo runbooka | učenje **1,0×** | islandski ovčar ni na Corenovi lestvici (S35, prebrano 2026-11-28 — ne »Icelandic Sheepdog« ne »Iceland Dog«) → brez mesta = 1,0 | #1723 |
| 2026-11-28 | pravilo runbooka | teža **11,3–13,6 kg** (oba spola), višina 46 / 42 cm (idealna), rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | standard FCI (S1100) teže ne navaja → WebMD S1102 »… 25 to 30 pounds in weight.« (naša pretvorba); PetMD enako, VCA 20–30 lb kot alternativa; višina FCI S1100 »Ideal height at withers: Male: 46 cm. Female: 42 cm.«; PetMD S10 (razred »Medium«) | #1723 |
| 2026-11-28 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | PetMD S1101 »Brush your dog at least once a week to help with shedding and minimize matting.«; AKC in Dogs Victoria enako; VCA »Brush a few times a week« (brez številke) kot alternativa | #1723 |
| 2026-11-28 | pravila »Breed care tasks« (David 2026-10-24, M5-R15) | **ščetkanje zob vsak dan** (otrokova mini igra) | WebMD S1102 »Brush your Icelandic sheepdog's teeth with a toothpaste formulated for dogs every day.« | #1723 |
| 2026-11-28 | pravila »Breed care tasks« (M5-R18) | **čiščenje ušes vsake 4 tedne** (otrokova mini igra) | PetMD S1101 »once a month and after bathing to keep the ears clear of wax and debris.« (»once a month« → 28 dni; »after bathing« ni posebej modelirano) | #1723 |
| 2026-11-28 | pravila »Breed care tasks« (M5-R20) | **opomnik za kopanje vsake 4 tedne** (staršem, se ne točkuje) | WebMD S1102 »A bath every 4 to 6 weeks is suitable for most Icelandic sheepdogs.« (razpon → spodnja meja 28); PetMD »every month or two« se ujema | #1723 |
| 2026-11-28 | pravila »Breed care tasks« | **brez** nalog za kremplje in frizerja | kremplji WebMD »before they're clicking on the ground« (brez pogostosti); frizer PetMD »a professional groomer won’t be needed …« | #1723 |
| 2026-11-28 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S1101 »Begin socializing your Icelandic Sheepdog puppy when they're young, and talk to your vet about how to do so safely.« | #1723 |
| 2026-11-28 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila po obroku in **brez** prepovedi sprehoda v vročini | noben vir ne svetuje premora po obroku; pasma ni ploskoobrazna | #1723 |
| 2026-11-28 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»družinsko življenje«**, **»družino z otroki«**; upoštevajte **»rad ima družbo«**, **»izpada mu dlaka«**, **»rad pobegne — potrebuje varno, visoko ograjo«** | WebMD S1102 (»best suited to an active family«, »loving family dogs«, »loving and patient with small children«, »may be unhappy if they're regularly left on their own«); PetMD S1101 (»shed year-round«); AKC S1106 (»a secure fence is essential«) | #1723 |
| 2026-11-28 | pravilo runbooka | **brez** čipov `small_children` (WebMD ni ocena; VCA »Excellent« je pogojen s socializacijo), `other_pets` (pogojen), `chases_small_pets` (lovi avtomobile, ne »high prey drive«), `frequent_grooming` (viri si nasprotujejo), `apartment` / `large_home` (ključnih podatkov RKC ni bilo); nikoli »hypoallergenic« | ni ustrezne A/B navedbe | #1723 |
| 2026-11-28 | pravilo runbooka (§3) | **v aplikaciji da, v marketingu da** | nobeno veterinarsko združenje ne odsvetuje oglaševanja; kategorija Breed Watch ni bila dosegljiva | #1723 |
| 2026-11-28 | pravilo runbooka (§3) | barve za AI in izbirnik: **rdeče-bel (portret)**, trobarven (črn, rjavkast, bel), kremasto-bel, rdeče sobolji z belo, sivo-bel, čokoladno-rjavkasto-bel, bel z rdečimi lisami; vedno z belimi oznakami, nikoli pretežno bel, nikoli »sedlo« goniča, nikoli modre / rumene oči; naraven zavihan rep | FCI S1100 »White markings always accompany the main colour.« (rdeča / plava od kremaste do rdečkasto rjave, siva, čokoladna, črna, lisasta); UKC S1107; uteži barv brez vira (Claude) | #1723 |
| 2026-11-28 | pravilo runbooka (§2.3) | seznam za vzreditelja: **oči, kolki** (`eye_scheme`, `hip_scheme`, »other_scheme«) | stran RKC A–Z ni bila dosegljiva → Dogs Victoria K48 »Breeding stock should be eye tested by an Ophthalmologist and Hip Dysplasia (HD) scored.« | #1723 |
| 2026-11-28 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia`, `kneecap_luxation`, `eye_conditions`; brez odstotkov, brez novega ključa | WebMD S1102, VCA S1103, PetMD S1101 (komolčna displazija je v istem vnosu kot pogačica — brez lastnega ključa) | #1723 |
| 2026-11-28 | predlog Claude, David lahko spremeni | EN »Icelandic Sheepdog«, SL **»islandski ovčar«** (tudi »islandski špic«); iskanje »icelandic sheepdog«, »islandski ovčar«, »islandski ovcar«, »icelandic spitz«, »iceland dog«, »islandski špic«, »íslenskur fjárhundur« | FCI / AKC; Wikipedia S1109 (druga imena); strani KZS nisem prebral | #1723 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje po imenih EN / SL, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-147 | 2026-11-28 |
| Pravila po starosti (2.000 → 4.000 korakov pri 4 mesecih, odrasel 4.000, starejši 3.000 od 108. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-147 | 2026-11-28 |
| Česanje 1× na teden, ščetkanje zob vsak dan, čiščenje ušes vsake 4 tedne, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R15 / M5-R18 / M5-R17 | 2026-11-28 |
| Opomnik staršem za kopanje vsake 4 tedne | 🛠 | 💶 izziv | M5-R20 | 2026-11-28 |
| Barva (izbirnik: rdeče-bel, trobarven, kremasto-bel, rdeče sobolji z belo, sivo-bel, čokoladno-rjavkasto-bel, bel z rdečimi lisami) | 🛠 | 💶 izziv | M5-R21 | 2026-11-28 |
| Barvne fotografije (izbirnik in spletna stran) | 🗓 po portretu | — | M5-R21 / §8b | — |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), rdeč z belimi oznakami, pokončna ušesa, zavit košat rep, svetlo sivo studijsko ozadje | — | M5-R11-04 | 2026-11-28 |
| Stran na petprep.si (`/animals/dogs/icelandic-sheepdog`, `/sl/zivali/psi/islandski-ovcar`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |
| »Ali si vedel?« | 🛠 samodejno le **2** dejstvi (življenjska doba, konec rasti) — manj kot 4: gibanje (Dogs NSW »40 min/day«) je v registru brez kvalifikatorja, teža je v citatu v funtih, citat FCI za izvor ne vsebuje »Iceland«, višina / velikost / dlaka se s citati ne ujemajo dobesedno | — | M5-R09e | 2026-11-28 |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal islandskega ovčarja — čvrstega nordijskega pastirskega špica nekoliko pod srednjo velikostjo (11,3–13,6 kg, 42–46 cm) s pokončnimi ušesi in zavihanim repom, 143. najbolj priljubljeno pasmo po AKC 2024 in prvo pasmo iz Islandije v aplikaciji. Deset novih virov (S1100–S1109). Odrasel pes v igri potrebuje 4.000 korakov na dan (40 minut gibanja), česanje 1× na teden, vsakodnevno ščetkanje zob, čiščenje ušes vsake 4 tedne in kopanje vsake 4 tedne; starejši postane pri 108 mesecih.
**Zakaj je pomembno:** Islandski ovčar je vesel, prijazen družinski pes, potrpežljiv z otroki, a ne mara biti dolgo sam, celo leto mu izpada dlaka in rad pobegne za avtomobili — potrebuje varno ograjo.
**Kako povedati** — starši: »Islandski ovčar je ljubeč družinski pes, ki rad sodeluje z otroki; potrebuje 40 minut gibanja na dan, tedensko česanje in varno ograjen vrt. V PetPrepu boste videli, ali vaš otrok zmore vsakodnevno skrb in redno nego.« · otroci: »Islandski ovčar je mali pastir s košatim zavihanim repom — z njim greš vsak dan na sprehod, ga enkrat na teden počešeš, mu vsak dan umiješ zobe in mu enkrat na mesec očistiš ušesa.« · vlagatelji / partnerji: »Nočni zagon je pasmo sam izbral po javni lestvici AKC in jo opremil z desetimi viri; ko britanski kinološki klub ni bil dosegljiv, so pravila iz standarda FCI, avstralskih kinoloških zvez in veterinarsko pregledanih strani WebMD in PetMD.«

## Odprto za Davida

- **Gibanje:** edini vir s številko je Dogs NSW »40 min/day« (40 min). Strani RKC (pasma je pri RKC v uvoženem registru) ni bilo mogoče prebrati — če RKC navaja drugače, se cilj spremeni.
- **Življenjska doba / starejši:** stran RKC in tabela McMillan 2024 nista bili dosegljivi → spodnja meja WebMD »12 to 14 years« → starejši pri 108 mesecih. Potrdite ali izberite drugo mejo (Dogs Trust > 7 let → 84).
- **Teža:** standardi teže ne navajajo — uporabljena je WebMD (vir B), za oba spola enako; Dogs NSW navaja »Small (under 11kg)«.
- **Majhni otroci:** VCA ocenjuje »With Kids« z »Excellent«, a s pogojem socializacije → čipa `small_children` ni.
- **Lajanje:** PetMD »can also bark a lot« — za lajanje v besednjaku ni ključa; nov čip bi bil nova odločitev.
- **»Ali si vedel?«:** generator je zapisal le 2 dejstvi (manj kot 4). Islandija (`IS`) še ni v seznamu držav `scripts/generate-breed-facts.mjs` (in na spletni strani so potrebne besede EN / SL za novo državo izvora), citat FCI za izvor pa države ne navaja — dejstvo o izvoru zato manjka.
- **Ime / iskanje:** slovensko »islandski ovčar« / »islandski špic« ni preverjeno na straneh KZS.
- **Skupni dokumenti:** število pasem psov v FEATURES / predstavitvah se po pravilu »Own files only« ne spreminja v tem zagonu.
