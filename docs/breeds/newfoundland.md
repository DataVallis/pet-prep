# Novofundlandec (Newfoundland)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-51 · 2026-10-20

| | |
|---|---|
| Ključ / slug | `newfoundland` / `newfoundland` |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 470 — v izbirniku za kodrastim bišonom |
| Vir podatkov | `docs/research/dog-data/data.json` → `newfoundland` (viri S419–S426; ponovno S8, S10, S11, S14, S24, S35, S45, S123, S174) |
| Izvor | FCI št. 50, skupina 2, sekcija 2.2 (molosi, gorski tip), Kanada (S419, S420) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 47. mesto »Newfoundland« (S174) |
| PR-ji | pet-prep #302 (pasma, združen `72f637d`) · portret #303 (združen `b95e26a`) · spletna stran #82 (združen `0f729ac`) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-20 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | PDSA S423 »Minimum exercise (per day): 1 hour« (»najmanj N« → N). Stran RKC (prednostni vir) se ni naložila → PDSA po pravilu runbooka | #302 |
| 2026-10-20 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **99** mesecev; prihod 2 / 9 / 36 / 99 | 0,75 × mediana McMillan 2024 **11,0 let** (tabela Dogs Trust S123) — ni začasno. Alternativi: 84 (Dogs Trust > 7 let, S14), 81 (spodnja meja PetMD 9 let, S424) | #302 |
| 2026-10-20 | pravilo runbooka | učenje **1,2×** (Coren rang 34, skupina »Above average«) | S35 | #302 |
| 2026-10-20 | pravilo runbooka | teža 50–69 kg, rast do 18–24 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S423 »Average weight: 50-69 kg« (FCI S420 povprečje 68 / 54 kg, AKC S421 130–150 / 100–120 lb = alternativi); PetMD S10 (razred »Giant«) | #302 |
| 2026-10-20 | pravilo runbooka (M5-R13) | česanje **vsak dan** (7× na teden, zmršena dlaka po 4 zamujenih) | PDSA S423 »Grooming is a daily task, particularly when shedding.« (»daily« → 7). PetMD S424 »at least once a week« = nižji minimum, alternativa | #302 |
| 2026-10-20 | David 2026-10-12 (M5-R16) | opozorilo **»počakaj s sprehodom« 60 min po obroku** (koraki se štejejo, brez kazni) | PetMD S424 »Avoiding exercising their dog an hour before or after a meal« (napihnjenost / GDV). Ura *pred* obrokom v igri ni modelirana | #302 |
| 2026-10-20 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S424 »All dogs, even the friendly Newfoundland, need consistent socialization as puppies« | #302 |
| 2026-10-20 | pravilo runbooka | oznake: družinsko življenje (`family_pet`), družina z otroki (`children` — z opombo: igro z majhnimi otroki nadzorujte), dom z drugimi ljubljenčki (`other_pets`); upoštevajte: krajša življenjska doba (`shorter_lifespan`), rad ima družbo (`needs_company`), izpada mu dlaka (`sheds`), česanje večkrat na teden (`frequent_grooming`), se slini (`drools`). Brez nove oznake | PetMD S424 »outstanding family pet«, »get along well with children, small pets, and other dogs«, »supervise all interactions between small kids and Newfoundlands«, »some may develop separation anxiety when left alone«, »shed year-round«; PDSA S423 »Average lifespan: Under 10 years«, »Grooming is a daily task«, »tend to drool« | #302 |
| 2026-10-20 | pravilo runbooka (§3, dobrobit) | oznaka dobrobiti: **veke in oči** (`eyelid_problems`) | PDSA S423: »classed as ‘Category Two’ by The Kennel Club«; edina točka Breed Watch po načrtu Kennel Club BHCP 2018 (S425): »Conformational defects of the upper and lower eyelids (loose eyelids)« | #302 |
| 2026-10-20 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu da** — nikoli s povešenimi vekami | veterinarsko odsvetovanje oglaševanja velja le za pse s sploščenim obrazom (BVA S92) | #302 |
| 2026-10-20 | pravilo runbooka (§3) | barve za AI in izbirnik: **črn** (portret, »tradicionalna barva«), **landseer** (belo-črn), **rjav**; nikoli siv (le AKC); tesno prilegajoče se veke | FCI S420 »Color: Negro, blanco y negro y castaño.«; AKC S421 »black, brown, gray, and white and black« | #302 |
| 2026-10-20 | predlog Claude, David lahko spremeni | ime SL »novofundlandec« (slug `novofundlandec`); iskalni ključi tudi »novofundlandski pes«, »njufi«, »newfie«, »terra nova« | raba v slovenščini; KZS ni preverjen | #302 |
| 2026-10-20 | pravilo runbooka (§2.3) | seznam za vzreditelja: DNA test za cistinurijo, ocena kolkov (BVA/KC) in komolcev (BVA/KC) — minimum; srčni pregled (Colour Flow Doppler) pasemskega kluba — druga shema. Nova ključa `dna_cu`, `heart_echo_scheme` | stran RKC se ni naložila → zahteve programa Assured Breeder iz načrta Kennel Club BHCP 2018 (K29 = S425) | #302 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »novofundlandec«, »newfoundland«, »newfie« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-51 | 2026-10-20 |
| Pravila po starosti (2.000 → 6.000 korakov, odrasel 6.000, starejši 4.500 od 99 mesecev; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-51 | 2026-10-20 |
| Česanje vsak dan (rutina »Počeši«), opozorilo »počakaj s sprehodom« po obroku, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R16 / M5-R17 | 2026-10-20 |
| Barva (izbirnik barve: črn, landseer, rjav) | 🛠 | 💶 izziv | M5-R21 | 2026-10-20 |
| AI portret v registru (črn, sprejet v prvem poskusu, zagon 39517934021) | ✅ | — | M5-R11-04 | 2026-10-20 |
| Stran na petprep.si (`/animals/dogs/newfoundland`, `/sl/zivali/psi/novofundlandec`) | 🗓 »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal novofundlandca — 47. najbolj priljubljeno pasmo po AKC 2024 in 47. plačljivo pasmo psa. 8 novih virov (S419–S426). Odrasel pes v igri potrebuje 6.000 korakov na dan (PDSA: najmanj ura gibanja), starejši od 8¼ leta 4.500; uči se nekoliko hitreje kot mešanček (Coren: 34. mesto). Vsakodnevno česanje, opozorilo, naj po obroku počaka s sprehodom, in socializacija mladička so del izziva od prvega dne.
**Zakaj je pomembno:** Novofundlandec je znan kot nežni velikan in odličen družinski pes. Viri povedo tudi drugo plat: tehta do 69 kg, gosto dlako je treba česati vsak dan, slini se in je neroden pri pitju, rad je v družbi (lahko ima težave, ko je sam), po obroku ne sme takoj na sprehod (nevarnost zasuka želodca), življenjska doba pa je krajša kot pri mnogih pasmah. Prav to pokaže 12 tednov PetPrepa.
**Kako povedati** — starši: »Novofundlandec je prijazen in potrpežljiv z otroki, a ogromen pes, ki potrebuje vsakodnevno česanje, uro gibanja in družbo. V PetPrepu boste videli, ali otrok to zmore 12 tednov zapored.« · otroci: »Novofundlandec je velik kosmat plavalec — včasih je reševal ljudi iz vode. Vsak dan ga počešeš in pelješ na sprehod, po obroku pa najprej malo počakaš.« · vlagatelji / partnerji: »Sedeminštirideseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili; ko glavni vir (RKC) ni bil dosegljiv, je po pravilih uporabil naslednji vir in odprta vprašanja zapisal za Davida.«

## Odprto za Davida

- **Rešeno 24. 10. 2026 (David, M5-R14 / R15 / R18 / R19 / R20 — PR #376):** naloga samo, kjer vir navaja pogostost (razpon → spodnja meja); mini-igre otroka po modelu česanja (pošten delež, reže, poročilo, brez kazni), pasji frizer in kopanje = opomnik staršu, ne šteje v oceno; samo novi psi. Za to pasmo — zgrajeno: kopanje enkrat na 4 tedne (mesec) (opomnik staršu, ne šteje v oceno); brez pogostosti v viru → brez naloge (dejstvo ostane v besedilu): čiščenje ušes. Spodnje točke M5-R14 / R15 / R18 / R19 / R20 so s tem zaprte.

- **Potrebe brez mehanike (needs-david → rešeno 24. 10. 2026):** kopanje približno enkrat na mesec in čiščenje ušes po nasvetu veterinarja (PetMD S424) → **M5-R20**; redno brisanje sline (PetMD S424) → **potrebna nova naloga v ROADMAP — David**.
- **Česanje: viri se razlikujejo** — PDSA »a daily task« (7× na teden, zgrajeno po pravilu), PetMD »at least once a week«. Če David želi manj kot vsak dan, je potrebna podatkovna migracija.
- **Življenjska doba:** oznaka `shorter_lifespan` po pravilu (PDSA »Under 10 years«, PetMD 9–10 let), čeprav je mediana McMillan 2024 11,0 let (S123). Preveriti, ali naj oznaka ostane.
- **RKC ni bil dosegljiv** (stran pasme, Breed Watch, DNA testi so vrnili napako): manjkajo polja RKC (velikost doma in vrta, linjenje, razpon življenjske dobe); oznaka `large_home` zato ni dodana. Seznam testov za vzreditelja je iz načrta BHCP 2018 (K29) — ob naslednjem zagonu preveriti stran RKC.
- **Angleški standard FCI** (PDF) se preusmerja sam nase → uporabljena španska različica (S420).
- **Ime:** »novofundlandec« ni preverjeno pri KZS.
- **Spletna stran:** nova država izvora Kanada (CA) in nova ključa testov `dna_cu`, `heart_echo_scheme` (besede Claude).
