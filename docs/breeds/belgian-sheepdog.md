# Belgijski ovčar groenendael (Belgian Sheepdog)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-135 · 2026-11-23

| | |
|---|---|
| Ključ / slug | `belgian_sheepdog` / `belgian-sheepdog` (SL `belgijski-ovcar-groenendael`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 1260 — v izbirniku za norfolk terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `belgian_sheepdog` (viri S1019–S1021; ponovno S10, S11, S14, S24, S35, S45, S174, S312, S313, S318, S831); seznam za vzreditelja: RKC (S1019) |
| Izvor | FCI št. 15 »Chien de Berger Belge« (belgijski ovčar), skupina 1, sekcija 1 (ovčarji), Belgija — dolgodlaka črna različica groenendael (S312, S831) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 131. mesto »Belgian Sheepdog« (S174, prebrano 2026-11-23) |
| PR-ji | pet-prep #1535 (pasma, združen `2c324eb`) · portret pet-prep PR `feat/M5-R10-135-belgian-sheepdog-portrait` (zagon `breed-portraits.yml` 45126325622, sprejet v prvem poskusu) · barvne različice: niso potrebne (ena standardna barva — portret je fotografija v izbirniku) · spletna stran: pet-prep-website `feat/register-belgian-sheepdog` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-23 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček / mlad 10 min × starost do 120 (pri 12 mesecih) | RKC S1019 »More than 2 hours per day« (»more than N« → N); WebMD S1021 »almost two hours« se ujema | #1535 |
| 2026-11-23 | pravilo runbooka | obdobja 0 / 9 / 36 / **90**; prihod 2 / 9 / 36 / 90 — **začasno**, dokler ni preverjena mediana McMillan 2024 | AAHA S11; starejši = 0,75 × spodnja meja RKC »Over 10 years« (S1019) × 12 = 90 (kot malinois / tervuren). Wikipedija navaja McMillan 2024 le za malinoisa in tervurena. Alternativi 108 (PetMD / WebMD 12 let), 84 (Dogs Trust > 7 let) | #1535 |
| 2026-11-23 | pravilo runbooka | učenje **1,5×** | Coren 15. mesto »Belgian Sheepdog« (»Excellent Working Dogs«, tabela Wikipedia S35) | #1535 |
| 2026-11-23 | pravilo runbooka | teža **20–30 kg** (samci 25–30, samice 20–25), višina 60–66 / 56–62 cm, rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | standard FCI 15 (S313, skupen vsem štirim različicam); PetMD S10 (razred »Medium«) | #1535 |
| 2026-11-23 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | RKC »More than once a week« nima števila → PetMD S1020 »Regular, weekly brushings« (WebMD S1021 enako) → 1 | #1535 |
| 2026-11-23 | pravila »Breed care tasks« (David 2026-10-24) | **ščetkanje zob vsak dan** in **čiščenje ušes 1× na mesec** (otrokovi mini igri), **opomnik za kopanje vsake 4 tedne** (staršu, brez točk) | WebMD S1021 »It's best to do this daily.« (zobje), »a monthly bath« (kopanje); PetMD S1020 »once or twice a month« (ušesa → spodnja meja). Kremplji: samo »regularly« → brez naloge; frizer: brez vira | #1535 |
| 2026-11-23 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PetMD S1020 »Make sure you also have time to train and socialize them early, as it's important for their growth and development.« | #1535 |
| 2026-11-23 | pravilo runbooka (M5-R16 / M5-R14) | **brez** opozorila po obroku in **brez** poletnega opozorila | noben vir ne navaja premora med obrokom in gibanjem; pasma ni ploskoobrazna | #1535 |
| 2026-11-23 | pravilo runbooka | oznake: primeren za **»aktivno družino«**, **»velik dom z vrtom«**; upoštevajte **»dolg dnevni sprehod«**, **»lahko »pase« otroke«**, **»potrebuje miselne izzive«**, **»rad ima družbo«**, **»izpada mu dlaka«**, **»potrebuje česanje večkrat na teden«** | RKC S1019 (»More than 2 hours per day«, »Large house«, »Large garden«, »Sheds: Yes«, »More than once a week«); PetMD S1020 (aktivne družine, »isn't acceptable to herd: children«, »mental stimulation«, tedensko česanje); WebMD S1021 »aren't a good choice for people who need to be away from them for long hours« | #1535 |
| 2026-11-23 | pravilo runbooka | **brez** čipa »družino z otroki« (PetMD »tend to do well with children« proti WebMD »try to scare the children away« — nasprotje), `family_pet`, `small_children`, `chases_small_pets` (samo pašni nagon, brez »prey drive«), `apartment`; nikoli »hypoallergenic« | ni A/B navedbe ali je nasprotujoča | #1535 |
| 2026-11-23 | pravilo runbooka (§3) | **v aplikaciji da, v marketingu da** | ni ploskoobrazna; RKC Breed Watch kategorija 1 (brez točk skrbi) → brez čipa dobrobiti; nobeno veterinarsko združenje ne odsvetuje oglaševanja | #1535 |
| 2026-11-23 | pravilo runbooka (§3) | barva za AI in izbirnik: samo **črn** (portret); pokončna trikotna ušesa, dolga dlaka z ovratnikom, naraven košat rep; brez belih lis | FCI S831 »Groenendael: Only uniform black.«; utež brez vira → `verified: false`; ena barva → brez barvnih različic (§8b) | #1535 |
| 2026-11-23 | pravilo runbooka (§2.3) | seznam za vzreditelja: RKC — genetska raznolikost (minimum), BVA/KC komolci in kolki, BVA/KC/ISDS oči (dobra praksa) | RKC S1019 | #1535 |
| 2026-11-23 | pravilo runbooka | zdravstveni ključi registra: `hip_elbow_dysplasia`, `pra_inherited_blindness`, `hypothyroidism`, `epilepsy` (vsi obstoječi); občutljivost na anestezijo samo v ozadju; brez odstotkov | PetMD S1020, RKC S1019 | #1535 |
| 2026-11-23 | predlog Claude, David lahko spremeni | EN »Belgian Sheepdog« (AKC), SL **»belgijski ovčar groenendael«** (kot malinois / tervuren); iskanje tudi »groenendael«, »belgian shepherd groenendael«, »grunendal« | AKC S174, RKC S1019, FCI S312; strani KZS nisem prebral | #1535 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »groenendael«, »belgian sheepdog« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-135 | 2026-11-23 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 90. meseca; 4 → 3 → 2 obroka; učenje 1,5×) | 🛠 | 💶 izziv | M5-R10-135 | 2026-11-23 |
| Česanje 1× na teden, ščetkanje zob vsak dan, čiščenje ušes 1× na mesec, opomnik za kopanje vsake 4 tedne, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R15 / M5-R18 / M5-R17 | 2026-11-23 |
| Barva (izbirnik: črn — edina standardna barva, fotografija = portret) | 🛠 | 💶 izziv | M5-R21 | 2026-11-23 |
| AI portret v registru | ✅ AI fotografija (oznaka »AI-generated photo«), povsem črn, pokončna trikotna ušesa, dolga dlaka z ovratnikom, košat naraven rep, sivo studijsko ozadje | — | M5-R11-04 | 2026-11-23 |
| Stran na petprep.si (`/animals/dogs/belgian-sheepdog`, `/sl/zivali/psi/belgijski-ovcar-groenendael`) | 🛠 s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal belgijskega ovčarja groenendaela — dolgodlako, povsem črno različico belgijskega ovčarja (20–30 kg) in 131. najbolj priljubljeno pasmo po AKC 2024. Trije novi viri (S1019–S1021: RKC, PetMD, WebMD) in standard FCI 15. Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot 2 uri gibanja), česanje 1× na teden, ščetkanje zob vsak dan in čiščenje ušes enkrat na mesec; starejši postane pri 90 mesecih (začasno, po spodnji meji RKC).
**Zakaj je pomembno:** Groenendael je lep, pameten delovni pes, ki potrebuje veliko gibanja, miselnih izzivov in družbe — ni pes za družino, ki je ves dan od doma. PetPrep pokaže, ali družina zmore dve uri gibanja na dan in redno nego.
**Kako povedati** — starši: »Belgijski ovčar groenendael potrebuje več kot dve uri gibanja na dan in ne mara biti dolgo sam. V PetPrepu boste videli, ali vaš otrok zmore 12.000 korakov na dan ter vsakodnevno nego zob.« · otroci: »Groenendael je črn, puhast pastirski pes — z njim veliko hodiš, se igraš in ga enkrat na teden počešeš.« · vlagatelji / partnerji: »Samodejni nočni zagon je pasmo sam izbral po javni lestvici AKC in jo dodal s tremi novimi viri ter s tremi pravili nege iz veterinarskih virov.«

## Odprto za Davida

- **Starejši pri 90 mesecih (začasno):** mediana McMillan 2024 za groenendaela ni bila dosegljiva (Wikipedija navaja le malinoisa 12 let in tervurena 13,8 leta). Ko bo tabela preverjena, je za spremembo potrebna podatkovna migracija.
- **Česanje:** RKC »More than once a week« bi pomenilo vsaj 2×; po pravilu je 1× (PetMD / WebMD »weekly«), kot pri tervurenu. Potrdite ali spremenite.
- **Otroci:** čipa »družino z otroki« ni (PetMD da, WebMD opozarja) — potrdite.
- **Ime:** »belgijski ovčar groenendael« v slovenščini ni preverjen na straneh KZS. Potrdite ali popravite.
- **Skupni dokumenti:** število pasem psov v FEATURES / predstavitvah se po pravilu »Own files only« ne spreminja v tem zagonu.
