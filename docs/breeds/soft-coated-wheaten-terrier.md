# Soft Coated Wheaten Terrier (irski mehkodlaki pšenični terier)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-79 · 2026-10-30

| | |
|---|---|
| Ključ / slug | `soft_coated_wheaten_terrier` / `soft-coated-wheaten-terrier` (SL `irski-mehkodlaki-psenicni-terier`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 730 — v izbirniku za pirenejskim gorskim psom |
| Vir podatkov | `docs/research/dog-data/data.json` → `soft_coated_wheaten_terrier` (viri S608–S614; ponovno S8, S10, S11, S14, S24, S35, S45, S123, S174); seznam za vzreditelja: stran RKC (S610) |
| Izvor | FCI št. 40 »Irish Soft Coated Wheaten Terrier«, skupina 3, sekcija 1 (veliki in srednji terierji), Irska (S608, S609) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 75. mesto (S174, prebrano 2026-10-30) |
| PR-ji | pet-prep #443 (pasma, združen `3d92f0e`) · portret: #444 (zagon 40982180857) · spletna stran: pet-prep-website `feat/register-soft-coated-wheaten-terrier` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-30 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S610 »Exercise: Up to 1 hour per day« (»up to N« → N); PDSA S612 »Minimum exercise (per day): 1 hour« enako | #443 |
| 2026-10-30 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **123**; prihod 2 / 9 / 36 / 123 | AAHA S11; starejši = 0,75 × mediana McMillan 2024 **13,7 leta** (tabela Dogs Trust S123) = 123,3 → 123. Ni izbrano: 108 (spodnja meja RKC / PDSA »Over 12 years«), 84 (Dogs Trust »> 7 let«) | #443 |
| 2026-10-30 | pravilo runbooka | učenje **1,0×** (Coren 40. mesto, »povprečni«) | S35 (prebrano 2026-10-30) | #443 |
| 2026-10-30 | pravilo runbooka | teža **16–20,5 kg** in višina **46–49 cm** (oba spola), rast do 12–15 mesecev; hrana, voda in čiščenje kot pri border collieju | PDSA S612 (standarda RKC S611 in FCI S609 navajata le samce, samice »nekoliko manj«); PetMD S10 (razred »Medium«) | #443 |
| 2026-10-30 | pravilo runbooka (M5-R13) | česanje **vsak dan (7× na teden)**; skuštrana dlaka po 4 zamujenih | RKC S610 »Grooming: Every day«; PDSA S612 »Grooming is required daily …« enako; PetMD S613 »vsak dan, vsaj dvakrat na teden« in VCA S614 »vsak drugi dan« sta alternativi | #443 |
| 2026-10-30 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S613 »Early socialization and proper introductions can help a Wheaten Terrier live well with smaller pets.« | #443 |
| 2026-10-30 | pravila »Breed care tasks« (David 2026-10-24, M5-R18 / R19 / R20) | otrokova mini igra **čiščenje ušes 1× na teden**; opomnik staršem **frizer vsake 4 tedne** in **kopanje vsake 4 tedne** (nikoli točkovano) | PetMD S613 »every one or two weeks« (spodnja meja → 1 teden), »every four to six weeks« (→ 4 tedni), »bathed about monthly« (→ 4 tedni); VCA S614 »every other month« je alternativa. Zobje, kremplji: brez frekvence → brez naloge | #443 |
| 2026-10-30 | pravilo runbooka | **brez** opozorila po obroku (M5-R16) | noben vir ne svetuje premora po obroku | #443 |
| 2026-10-30 | pravilo runbooka | oznake: primeren za »družinsko življenje« (`family_pet`), »družino z otroki« (`children` — z opombo PetMD: igro vedno nadzirajte), »dom, kjer želite manj dlak« (`low_shedding`); upoštevajte »potrebuje česanje večkrat na teden« (`frequent_grooming`) in »rad ima družbo« (`needs_company`) | PDSA S612 »make great family pets«; VCA S614 »a good match for children«; RKC S610 »Sheds: No«, »Grooming: Every day«; PetMD S613 »dislike being left alone for long periods« (precedens bretonski ptičar) | #443 |
| 2026-10-30 | pravilo runbooka | **brez** `small_children`, `other_pets`, `chases_small_pets`, `apartment`, `large_home`, `active_family`, `heat_sensitive`; nikoli »hypoallergenic« | PetMD zahteva nadzor pri otrocih; VCA »usually good with other pets« ↔ PetMD »may chase smaller animals« (vira si nasprotujeta, besed »prey drive« ni); RKC »Small house«; VCA »Warm Weather 1 of 5« je le ocena, ne izjava | #443 |
| 2026-10-30 | pravilo runbooka (§3) | **brez čipa dobrobiti**; marketing: **v aplikaciji da, v marketingu da** (nikoli »hypoallergenic«, nikoli igra z majhnimi otroki brez nadzora) | RKC Breed Watch »Category 1« (S610); BVA S92 ne velja (ni plosko-obrazna pasma) | #443 |
| 2026-10-30 | pravilo runbooka (§3) | ena barva za AI in izbirnik: **pšenično rumena (clear wheaten)** = portret; naraven rep; nikoli bela, rdeča, črna ali črna s porjavelimi oznakami | RKC S611 »A good clear wheaten.«; FCI S609 odtenki od svetlo pšenične do zlato rdečkaste; utež brez vira → `verified: false` | #443 |
| 2026-10-30 | predlog Claude, David lahko spremeni | EN »Soft Coated Wheaten Terrier« (RKC / AKC), SL »Irski mehkodlaki pšenični terier«; iskanje »wheaten terrier«, »scwt«, »pšenični terier« … | opisni prevod imena FCI; slovenskega vira (KZS) za ime ni bilo mogoče najti | #443 |
| 2026-10-30 | pravilo runbooka (§2.3) | seznam za vzreditelja: genetska raznolikost (minimum); oči (BVA/KC/ISDS) in kolki (BVA/KC) (best practice) | stran RKC S610 | #443 |
| 2026-10-30 | pravilo runbooka | zdravstveni ključi registra: `hip_dysplasia`, `pra_eye_disease`, **`protein_losing_disease` (nov)**, `itchy_skin_allergy`, `addisons_disease`; brez odstotkov | PDSA S612, PetMD S613 | #443 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje vsak dan«, iskanje »irski mehkodlaki pšenični terier«, »wheaten terrier«, »scwt« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-79 | 2026-10-30 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 123. meseca; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-79 | 2026-10-30 |
| Česanje vsak dan, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-30 |
| Mini igra čiščenje ušes (1× na teden); opomnika staršem frizer in kopanje (vsake 4 tedne) | 🛠 | 💶 izziv | M5-R18 / M5-R19 / M5-R20 | 2026-10-30 |
| Barva (izbirnik: 1 standardna barva, pšenično rumena) | 🛠 | 💶 izziv | M5-R21 | 2026-10-30 |
| AI portret v registru (pšenično rumen, sprejet v prvem poskusu, zagon 40982180857, PR #444) | ✅ | — | M5-R11-04 | 2026-10-30 |
| Stran na petprep.si (`/animals/dogs/soft-coated-wheaten-terrier`, `/sl/zivali/psi/irski-mehkodlaki-psenicni-terier`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal irskega mehkodlakega pšeničnega terierja (Soft Coated Wheaten Terrier) — 75. najbolj priljubljeno pasmo po AKC 2024 in 73. plačljivo pasmo psa. 7 novih virov (S608–S614). Odrasel pes v igri potrebuje 6.000 korakov na dan (RKC: do ene ure gibanja) in česanje vsak dan; otrok mu enkrat na teden očisti ušesa, starša pa aplikacija vsake 4 tedne spomni na frizerja in kopanje.
**Zakaj je pomembno:** Wheaten skoraj ne izgublja dlake, zato ga družine pogosto izberejo — a njegova mehka, svilnata dlaka se brez vsakodnevnega česanja hitro zvalja. Rad ima ljudi in ne mara biti dolgo sam. PetPrep pokaže, ali otrok zmore prav to vsakodnevno, tiho delo, preden pride pravi pes.
**Kako povedati** — starši: »Irski mehkodlaki pšenični terier je po PDSA prijazen družinski pes, po VCA dober z otroki, a RKC zanj priporoča česanje vsak dan, PetMD pa frizerja vsake 4–6 tednov. V PetPrepu boste videli, ali vaš otrok zmore 12 tednov vsakodnevne nege.« · otroci: »Wheaten je vesel, igriv pes z mehko zlato dlako. Vsak dan ga počešeš, vsak teden mu očistiš ušesa in ga ne puščaš dolgo samega.« · vlagatelji / partnerji: »Triinsedemdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi in pravili, brez novih vprašanj.«

## Odprto za Davida

- **Ime:** »Irski mehkodlaki pšenični terier« je opisni prevod imena FCI; strani KZS za slovensko ime ni bilo mogoče najti. Potrdite ali popravite.
- **Frizer in kopanje:** PetMD (S613) daje 4–6 tednov oz. »about monthly« (→ 4 tedne, pravilo spodnje meje), VCA (S614) »every other month« (→ 8 tednov). Uporabljen je pogostejši vir (PetMD, veterinarka); če želite redkejši opomnik, je to podatkovna migracija (sejalnik samo dodaja).
- **Vročina:** VCA ima le oceno »Warm Weather: 1 of 5« brez izjave — zato brez čipa `heat_sensitive`. Želite čip tudi na podlagi ocene?
- **Ugotovitev za skupne dokumente (ne urejam jih):** nova država izvora **Irska (IE)** v `FCI_ORIGINS` in nov zdravstveni ključ registra `protein_losing_disease` (besedili EN / SL dodani na spletni strani).
- **Portret:** zagon 40982180857 (koda `3d92f0e`, brez produkcijske namestitve, 0,15 $), sprejet v prvem poskusu — realistična fotografija odraslega psa, mehka valovita pšenično rumena dlaka, nekoliko temnejša ušesa (standard to dovoljuje), črn nos, temne oči pod šopi dlake, naraven rep pokonci, svetlo siva studijska podlaga. PR #444.
