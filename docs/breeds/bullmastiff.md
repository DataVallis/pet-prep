# Bullmastiff (bulmastif)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-71 · 2026-10-27

| | |
|---|---|
| Ključ / slug | `bullmastiff` / `bullmastiff` (SL `bulmastif`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 660 — v izbirniku za jack russell terierjem |
| Vir podatkov | `docs/research/dog-data/data.json` → `bullmastiff` (viri S565–S569; ponovno S8, S10, S11, S14, S35, S45, S92, S123, S174); seznam za vzreditelja: 3 testi »minimum« (RKC S567) |
| Izvor | FCI št. 157 »Bullmastiff«, skupina 2, sekcija 2.1 (molosi, tip mastifa), Velika Britanija (S565) |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 67. mesto »Bullmastiffs« (S174, prebrano 2026-10-27) |
| PR-ji | pet-prep #414 (pasma, združen `6e37d65`) · portret: #415 (zagon 40444394564) · spletna stran: pet-prep-website `feat/register-bullmastiff` |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-27 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **120 min/dan → 12.000 korakov**; starejši 90 min → 9.000; mladiček 10 min × starost do 120 (pri 12 mesecih) | RKC S567 »Exercise: More than 2 hours per day« (»more than N« → N); PDSA S568 »a minimum of two hours exercise every day« se ujema | #414 |
| 2026-10-27 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **92** mesecev; prihod 2 / 9 / 36 / 92 | AAHA S11; starejši = 0,75 × mediana McMillan 2024 **10,2 leta** (tabela Dogs Trust S123, vrstica »Bullmastiff«) = 91,8 → 92. Alternative: Dogs Trust »> 7 let« → 84; PetMD 7–9 let → 63; RKC / PDSA »Under 10 years« nima spodnje meje | #414 |
| 2026-10-27 | pravilo runbooka | učenje **0,7×** (Coren rang 69, skupina »Fair«) | S35 | #414 |
| 2026-10-27 | pravilo runbooka | teža **41–59 kg** (psi 50–59, psice 41–50), višina 64–69 / 61–66 cm, rast do 18–24 mesecev; hrana, voda in čiščenje kot pri border collieju | FCI S566; PDSA S568 41–59 kg se ujema; PetMD S569 100–130 lb je alternativa; PetMD S10 (razred »Giant«) | #414 |
| 2026-10-27 | pravilo runbooka (M5-R13) | česanje **1× na teden**; skuštrana dlaka po 1 zamujenem | RKC S567 »Grooming: Once a week«; PDSA S568 tedensko; PetMD S569 »monthly brushing« = alternativa | #414 |
| 2026-10-27 | David 2026-10-12 (M5-R16) | opozorilo **60 min po obroku** (»počakaj s sprehodom«), koraki se štejejo | PetMD S569 »Avoid exercise from one hour before to one hour after a meal.«; PDSA S568: premor po obroku zaradi GDV | #414 |
| 2026-10-27 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PDSA S568 »Socialising your Bullmastiff from a young age with lots of different people and experiences is super important.« | #414 |
| 2026-10-27 | pravilo »Breed care tasks« (David 2026-10-24, M5-R14) | čiščenje obraza **vsak dan** (mini igra otroka) | PDSA S568 »Remember to keep your Bullmastiff’s face clean and dry.« / »This might need daily attention to avoid getting any nasty infections.« (omahljivo »might« — glej Odprto); PetMD S569: ustnice »may need a daily wipe-down« | #414 |
| 2026-10-27 | pravila »Breed care tasks« | **brez** nalog ušes, nohtov, kopanja, frizerja ali zob in brez opozorila za vročino | PetMD: ušesa »vprašajte veterinarja«, nohti »frequent«, kopanje »occasional« — brez števila; noben vir ne omenja vročine | #414 |
| 2026-10-27 | pravilo runbooka | oznake: primeren za »aktivno družino« (`active_family`) in »veliko hišo z vrtom« (`large_home`); upoštevajte »vsak dan potrebuje veliko gibanja«, »krajši gobček — lahko težje diha« (`short_muzzle_breathing`), »velik pes — ni za dom z majhnimi otroki«, »močan pes — potrebuje izkušenega lastnika«, »rad ima družbo«, »krajša življenjska doba«, »se slini«, »močan lovski nagon — lahko lovi manjše živali« | RKC S567 »More than 2 hours«, »Large house« / »Large garden«, »Under 10 years«; PDSA S568 BOAS »caused by their shorter faces«, »isn’t ideal for families with younger children«, »previous experience with the breed is a huge advantage«, »someone around all the time«, »huge droolers«, »very strong prey drives« | #414 |
| 2026-10-27 | pravilo runbooka | **brez** oznak `children`, `family_pet`, `other_pets`, `first_time_owner`, `sheds` / `low_shedding`, `frequent_grooming`, `secure_fencing`; nikoli »hypoallergenic«; brez nove oznake | PetMD (otroci, druge živali) nasprotuje PDSA; RKC »Sheds: Yes« nasprotuje PDSA »don’t shed much« → nobena od obeh oznak | #414 |
| 2026-10-27 | pravilo runbooka (§3, dobrobit) | oznaka dobrobiti **»krajši gobček«** (kot boksar, ši tzu); **brez** `hips_hind_legs` / `eyelid_problems` | PDSA S568 BOAS zaradi krajšega obraza; RKC Breed Watch kategorija 2, a točke skrbi niso bile vrnjene (glej Odprto) | #414 |
| 2026-10-27 | pravilo runbooka (§3) | marketing: **v aplikaciji da, v marketingu / posnetkih zaslona ne** | BVA S92 odsvetuje slike brahicefalnih psov v oglaševanju; PDSA S568: BOAS zaradi krajšega obraza — kot pri boksarju (glej Odprto) | #414 |
| 2026-10-27 | pravilo runbooka (§3) | barve za AI in izbirnik (3): **plavo rjav s črno masko** (portret), rdeč, tigrast — vedno s črnim gobcem; zmeren gobček z odprtimi nosnicami, brez težkih gub, tesne veke; naravna ušesa in rep | FCI S566 »Any shade of brindle, fawn or red«, »Black muzzle essential«. Uteži barv brez vira → `verified: false`; barva portreta je predlog Claude | #414 |
| 2026-10-27 | predlog Claude, David lahko spremeni | EN »Bullmastiff«, SL »Bulmastif«; iskanje »bull mastiff«, »bulmastif« … (iskanje »mastiff« pokaže obe pasmi) | slovenskega vira (KZS) za ime ni bilo mogoče preveriti | #414 |
| 2026-10-27 | pravilo runbooka (§2.3) | seznam za vzreditelja: komolci, oči (BVA/KC/ISDS), kolki — vse »minimum«; brez novega ključa | RKC S567 | #414 |
| 2026-10-27 | pravilo runbooka | zdravstveni ključi registra: `shorter_face_boas`, `bloat_gdv`, `hip_elbow_dysplasia`, `eyelids_turned`, `certain_cancers`; brez odstotkov | PDSA S568 | #414 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »bullmastiff«, »bulmastif« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-71 | 2026-10-27 |
| Pravila po starosti (2.000 → 12.000 korakov pri 12 mesecih, odrasel 12.000, starejši 9.000 od 92. meseca; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-71 | 2026-10-27 |
| Česanje 1× na teden (rutina »Počeši«), opozorilo 60 min po obroku, »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R16 / M5-R17 | 2026-10-27 |
| Čiščenje obraza vsak dan (otrok) | 🛠 | 💶 izziv | M5-R14 | 2026-10-27 |
| Barva (izbirnik: 3 standardne barve, privzeto plavo rjav s črno masko) | 🛠 | 💶 izziv | M5-R21 | 2026-10-27 |
| AI portret v registru (plavo rjav s črno masko, sprejet v prvem poskusu, zagon 40444394564, PR #415) | ✅ | — | M5-R11-04 | 2026-10-27 |
| Stran na petprep.si (`/animals/dogs/bullmastiff`, `/sl/zivali/psi/bulmastif`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal bulmastifa — 67. najbolj priljubljeno pasmo po AKC 2024 in 66. plačljivo pasmo psa. 5 novih virov (S565–S569). Odrasel pes v igri potrebuje 12.000 korakov na dan (RKC: več kot dve uri gibanja), starejši 9.000 že od 92. meseca (7,7 leta) — po veliki britanski raziskavi (McMillan 2024, 2.188 psov) je mediana življenjske dobe 10,2 leta. Česanje enkrat na teden, vsakodnevno čiščenje obraza, opozorilo »počakaj s sprehodom« po obroku in socializacija mladička so del izziva od prvega dne.
**Zakaj je pomembno:** Bulmastif je velik, močan čuvaj (41–59 kg), ki se veliko slini, ima zelo močan lovski nagon in rad ima, da je nekdo vedno doma. PDSA ga ne priporoča družinam z mlajšimi otroki in svetuje izkušenega lastnika; krajši gobček lahko povzroča težave z dihanjem, nevarnost zasuka želodca pa pomeni premor med obrokom in sprehodom. PetPrep pokaže, ali družina zmore dve uri gibanja in nego vsak dan 12 tednov.
**Kako povedati** — starši: »Bulmastif je velik, zvest pes za več kot dve uri gibanja na dan; vsak dan mu je treba očistiti obraz, po obroku počakati s sprehodom in ga kot mladička veliko socializirati. Primernejši je za odrasle ali družine s starejšimi otroki in izkušene lastnike. V PetPrepu boste videli, ali vaš otrok to zmore 12 tednov zapored.« · otroci: »Bulmastif je velik, miren čuvaj — vsak dan greš z njim na dolg sprehod, mu obrišeš obraz in po hrani malo počakaš, preden greste ven.« · vlagatelji / partnerji: »Šestinšestdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi, negovalnimi nalogami in pravili, brez novih vprašanj; zaradi krajšega gobčka po pravilu BVA ni v marketingu.«

## Odprto za Davida

- **Marketing:** izključen po pravilu BVA (S92), ker PDSA navaja BOAS »zaradi krajšega obraza«; bulmastif ni na BVA-jevem seznamu brahicefalnih pasem in je le zmerno kratkega gobčka (kot boksar). Če ga želite v marketingu, spremenite `proposed_game_parameters.bullmastiff.marketing`.
- **Čiščenje obraza vsak dan:** PDSA piše »might need daily attention« (omahljivo) — po pravilu »Breed care tasks« zgrajeno kot vsak dan; po želji redkeje ali brez naloge.
- **Breed Watch kategorija 2:** posameznih točk skrbi RKC stran ni vrnila — zato brez oznak `hips_hind_legs` / `eyelid_problems` (PDSA navaja displazijo kolkov in komolcev ter entropij / ektropij). Če točke obstajajo, se oznaki dodata.
- **Ime:** »Bulmastif« ni preverjeno pri KZS.
- **Barva portreta:** plavo rjav s črno masko je predlog Claude (standard ne določa najpogostejše barve). Portret: zagon 40444394564 (koda `6e37d65`, brez produkcijske namestitve), sprejet v prvem poskusu — realistična fotografija, zmeren gobček z odprtimi nosnicami; PR #415.
