# Dogue de Bordeaux (Bordojska doga)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-100 · 2026-11-10

| | |
|---|---|
| Ključ / slug | `dogue_de_bordeaux` / `dogue-de-bordeaux` (SL `bordojska-doga`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 930 — v izbirniku za lhasa apsom |
| Vir podatkov | `docs/research/dog-data/data.json` → `dogue_de_bordeaux` (viri S754–S759; ponovno S8, S10, S11, S14, S24, S35, S45, S92, S174, S212); seznam za vzreditelja: RKC (`listed`) — komolci, oči, kolki (minimum) |
| Izvor | FCI št. 116 »Dogue de Bordeaux«, skupina 2, sekcija 2.1 (molosi, tip doge), Francija (S754, S755) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 96. mesto »Dogue de Bordeaux« (S174, zapisano 2026-11-05; 95. mesto argentinska doga je blokirana, vrstica 99) |
| PR-ji | pet-prep #915 (pasma) · portret (sledi) · spletna stran (sledi) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-11-10 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **60 min/dan → 6.000 korakov**; starejši 45 min → 4.500; mladiček 10 min × starost do 60 (pri 6 mesecih) | RKC S756 »Exercise: Up to 1 hour per day« (»up to N« → N); PDSA S757 »a minimum of an hour exercise every day« se ujema | #915 |
| 2026-11-10 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **50**; prihod 2 / 9 / 36 / 50 | AAHA S11; starejši = 0,75 × **5,5 leta** = 49,5 → 50 — mediana VetCompass (O'Neill in sod. 2013, S212, le 21 poginov). McMillan 2024 ni bil dosegljiv; po vrstnem redu runbooka ima mediana VetCompass prednost pred pasom RKC | #915 |
| 2026-11-10 | pravilo runbooka | učenje **1,0×** | pasme ni na Corenovi lestvici (S35) → kot mešanec (precedens havanski bišon) | #915 |
| 2026-11-10 | pravilo runbooka | teža **44,9–65,8 kg** (PetMD: samci 110–145 lb, samice 99–120 lb); višina 60–68 / 58–66 cm (FCI); rast do 18–24 mesecev (razred »Giant«); hrana, voda in čiščenje kot pri border collieju | FCI S755 in PDSA S757 navajata le najmanjšo težo (samci vsaj 50 kg, samice vsaj 45 kg) — skladno; PetMD S759; S10 | #915 |
| 2026-11-10 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | RKC S756 »Grooming: Once a week«; PDSA S757 in PetMD S759 tedensko | #915 |
| 2026-11-10 | David 2026-10-12 (M5-R16) | opozorilo »počakaj s sprehodom« **60 min po obroku** | PDSA S757 »Remember to leave a gap after eating and before exercising.«; PetMD S759 opisuje zasuk želodca (GDV) | #915 |
| 2026-11-10 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) **z lastnim citatom** | PDSA S757 »Given the right socialisation from a young age, Dogues should generally get along fine with other dogs.« | #915 |
| 2026-11-10 | pravila »Breed care tasks« (David 2026-10-24, M5-R14) | **opozorilo za sprehod opoldne poleti** (vročina) | PetMD S759 »avoiding strenuous exercise in high temperatures and keeping them cool«; brahicefalna pasma (Dogs Trust Ireland S758) | #915 |
| 2026-11-10 | pravila »Breed care tasks« | **brez** naloge za gube na obrazu, ušesa, kremplje, kopanje ali frizerja | PDSA S757 gube »regularly«, ušesa »check with your vet how often«; PetMD S759 »regularly« / »occasional« — nobene pogostosti | #915 |
| 2026-11-10 | pravilo runbooka | primeren za: **veliko hišo z vrtom**; upoštevajte: krajši gobček (dihanje), občutljiva koža, kolki in zadnje noge, ne za majhne otroke, izkušen lastnik, slinjenje, izpadanje dlake, preganja male živali | RKC S756 (»Large house«, »Sheds: Yes«, Breed Watch kategorija 3); PDSA S757 (manjši otroci ne, ne za prve lastnike, »a lot of drool«, »very high prey drive«, gube, šibke zadnje noge); PetMD S759 (kratek gobček, dihanje); Dogs Trust Ireland S758 | #915 |
| 2026-11-10 | pravilo runbooka | **brez** čipov `children` (PetMD »great with kids« proti PDSA »ne z manjšimi otroki«), `other_pets`, `needs_company`, `shorter_lifespan` (viri si nasprotujejo — glej spodaj), `brachycephalic_breathing` (vir o dihanju govori o krajšem gobčku → `short_muzzle_breathing`, precedens boksar / bulmastif); nikoli »hypoallergenic« | S756–S759 | #915 |
| 2026-11-10 | pravilo runbooka (§3, BVA S92) | marketing: **v aplikaciji da, v marketingu / posnetkih zaslona ne** | brahicefalna pasma (Dogs Trust Ireland S758, FCI S755 »brachycephalic molossoid«), RKC Breed Watch kategorija 3; BVA odsvetuje podobe brahicefalnih psov v oglaševanju | #915 |
| 2026-11-10 | pravilo runbooka (§3) | barve za AI in izbirnik: **4 odtenki plave (fawn) barve** — mahagonij z rjavo masko (portret), plava s črno masko, plava brez maske, izabela; nikoli tigrasta, enobarvno rjava ali bela na glavi / telesu; zmeren gobček z odprtimi nosnicami, brez težkih gub | FCI S755 »Self-coloured, in all shades of fawn, from mahogany to isabella.«; uteži niso iz vira (`verified=false`) | #915 |
| 2026-11-10 | pravilo runbooka (§2.3) | seznam za vzreditelja: **RKC** — komolci (BVA/KC), oči (BVA/KC/ISDS), kolki (BVA/KC), vse minimum | S756 | #915 |
| 2026-11-10 | pravilo runbooka | zdravstveni ključi registra: `shorter_face_boas`, `bloat_gdv`, `hip_elbow_dysplasia`, nov `skin_fold_care` (gube na obrazu redno čistiti in osušiti); brez odstotkov | PDSA S757, PetMD S759 | #915 |
| 2026-11-10 | predlog Claude, David lahko spremeni | EN »Dogue de Bordeaux«, SL **»Bordojska doga«** (v stavku »bordojska doga«); iskanje tudi »bordeaux mastiff«, »french mastiff«, »bordoška doga« | FCI S754, RKC S756; slovenske strani KZS nisem odprl | #915 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »bordeaux«, »bordojska« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-100 | 2026-11-10 |
| Pravila po starosti (2.000 → 6.000 korakov pri 6 mesecih, odrasel 6.000, starejši 4.500 od 50. meseca; 4 → 3 → 2 obroka; učenje 1,0×) | 🛠 | 💶 izziv | M5-R10-100 | 2026-11-10 |
| Česanje 1× na teden, »Nova izkušnja« za mladička, opozorilo po obroku, opozorilo za vročino opoldne poleti | 🛠 | 💶 izziv | M5-R13 / M5-R14 / M5-R16 / M5-R17 | 2026-11-10 |
| Barva (izbirnik: 4 odtenki plave barve standarda) | 🛠 | 💶 izziv | M5-R21 | 2026-11-10 |
| AI portret v registru | 🗓 sledi takoj po združitvi | — | M5-R11-04 | 2026-11-10 |
| Stran na petprep.si (`/animals/dogs/dogue-de-bordeaux`, `/sl/zivali/psi/bordojska-doga`) | 🗓 šele s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | 2026-11-10 |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal bordojsko dogo — velikega, mišičastega francoskega molosa (samci vsaj 50 kg po FCI) z mirnim, uravnoteženim značajem; 96. najbolj priljubljeno pasmo po AKC 2024. 6 novih virov (S754–S759). Odrasel pes v igri potrebuje le 6.000 korakov na dan (RKC: do 1 ure gibanja), enkrat na teden česanje, po obroku uro počitka pred sprehodom in poleti opoldne nobenega napornega sprehoda.
**Zakaj je pomembno:** Velik, močan pes, ki potrebuje izkušenega lastnika, ni za družine z majhnimi otroki, se veliko slini in ima kratek gobček, zaradi katerega v vročini težje diha. Viri o življenjski dobi si nasprotujejo (VetCompass 5,5 leta, PetMD 5–8 let, RKC in PDSA »več kot 10 let«) — zato je igra previdna in ga postara že pri 50 mesecih.
**Kako povedati** — starši: »Bordojska doga ne potrebuje dolgih sprehodov, potrebuje pa veliko prostora, izkušenega lastnika, previdnost v vročini in po obroku. V PetPrepu boste videli, ali vaš otrok zmore skrb za velikega psa.« · otroci: »Velik, miren pes s kratkim gobčkom — poleti ga ne vodiš na dolg sprehod opoldne in po hrani mu pustiš, da počiva.« · vlagatelji / partnerji: »99. pasma v registru, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal z viri, opozorili in pravili.«

## Odprto za Davida

- **Starejše obdobje: 50 ali 90 mesecev.** Po pravilu runbooka 50 (0,75 × mediana VetCompass 5,5 leta, S212 — a le 21 poginov iz podatkov 2013). RKC (S756), PDSA (S757) in Dogs Trust Ireland (S758) pravijo »več kot 10 let« → to bi dalo **90**; PetMD (S759) 5–8 let podpira 50. McMillan 2024 za pasmo nisem mogel prebrati (le oznaka na sliki 5). Če izberete drugo vrednost, je potrebna podatkovna migracija (seeder samo vstavlja).
- **Čip »krajša življenjska doba«** ni dodan, ker si viri nasprotujejo (precedens novoškotski prinašalec). Če presodite, da velja, ga dodajte.
- **Čip za dihanje:** izbran `short_muzzle_breathing` (»krajši gobček«); Dogs Trust Ireland pravi »flat faces« — če želite strožji `brachycephalic_breathing` (»kratek gobček — težave z dihanjem in vročino«), ga zamenjajte.
- **Marketing:** izključena po pravilu BVA (S92), kot boksar in bulmastif. Če jo želite v marketingu (kot kavalirja), odločite.
- **Breed Watch kategorija 3:** posameznih točk nisem mogel prebrati (stran RKC zavrnjena); PDSA omenja podgrizen ugriz in šibke zadnje noge. Čipa za veke (`eyelid_problems`) zato ni.
- **Teža** je iz PetMD (S759); FCI in PDSA dajeta le najmanjšo težo.
- **Gube na obrazu:** viri pravijo »redno«, brez pogostosti → ni otrokove naloge (pri bulmastifu je vir rekel »vsak dan«).
- **Slovensko ime** »bordojska doga« — strani KZS ali kluba nisem odprl. Potrdite ali popravite.
