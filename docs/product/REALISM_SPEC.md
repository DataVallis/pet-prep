# PetPrep — Realistična simulacija psa (izvor, starost, rast, vedenje, šolanje)

> **Status:** odločitve sprejete (§7); strežniški del faz in profila izveden (M5-R01, §8) — David, 5. 10. 2026 ("čim bolj realna simulacija"). Ko so odločitve sprejete, se pravila prenesejo v `PRODUCT_SPEC.md`, naloge v `ROADMAP.md` (epik **M5**).
> **Temeljno pravilo:** vse številke o pasmah, starosti, rasti, prehrani, vedenju in učljivosti pridejo **iz preverljivih virov** (M1-19) — nič si ne izmišljujemo. V igri so označene z virom.

## 1. Kaj starš izbere ob ustvarjanju psa (pred PIN-om otroka)
| Izbira | Možnosti (osnutek) | Vpliv |
|---|---|---|
| Vrsta | pes (mačke niso v MVP) | — |
| Pasma | mešanček, Border Collie, … (seznam iz virov) | velikost, energija, prehrana, učljivost, videz |
| Izvor | **posvojen** (zavetišče) / **kupljen** (vzreditelj) | posvojen: lahko odrasel, neznana preteklost (npr. plašnost, ni vajen hiše); kupljen: praviloma mladiček |
| Starost ob prihodu | **mladiček** (8–12 tednov), **mlad pes** (6–18 mesecev), **odrasel** (2–7 let), *starejši (8+ let)* | potrebe, vedenje, rast, slika |
| (pozneje) spol, velikost | — | — |

## 2. Starost in rast
- Čas: **1 realni teden = 1 mesec** psove starosti (obstoječe pravilo) → 12 tednov = 12 mesecev.
- Mladiček, ki pride pri 2 mesecih, je ob koncu izziva star ~14 mesecev — prehod mladiček → mlad pes → (skoraj) odrasel.
- **Faze** (meje po pasmi/velikosti iz virov): mladiček · mladostnik · odrasel · starejši. Vsaka faza ima svoje potrebe (število obrokov, spanje, gibanje, vedenje).
- **Slika in videi rastejo s psom:** ob prehodu faze (in npr. vsak mesec pri mladičku) se ustvari nova referenčna slika istega psa (image-to-image iz prejšnje → ohranjena identiteta: barva, oznake), nato videi stanj. Strošek: ~0,15 $ slika + videi po fazah — v proračunu (M4-07).

## 3. Vedenje glede na starost in izvor
| Pojav | Kdaj | V igri |
|---|---|---|
| Lulanje / kakanje po stanovanju | mladiček, posvojen pes brez navad | pogostejši "nered" dogodki; manj z vsakim napredkom pri navajanju na čistočo |
| Uničevanje (grizenje pohištva, čevljev) | mladiček (menjava zob), dolgčas pri visokoenergijskih pasmah | dogodek "kuža je nekaj uničil" — otrok mora pospraviti in dati igračo; preprečuje se z gibanjem in šolanjem |
| Več obrokov | mladiček 3–4× na dan → odrasel 2× | okna hranjenja po fazi (iz virov) |
| Več spanja | mladiček, starejši | več spanja v videih, manj gibanja |
| Plašnost / prilagajanje | posvojen pes, prvi tedni | počasnejši napredek pri šolanju, posebni opomniki (mirnost) |
| Potrebe po gibanju | po pasmi in starosti | dnevni cilj korakov po fazi (mladiček krajši sprehodi!) |

## 4. Šolanje (dresura)
- Ukazi (osnutek): **navajanje na čistočo**, sedi, prostor/ostani, pridi (odpoklic), hoja na povodcu, pusti.
- Otrok vsak dan izvede **kratko vajo** (mini-igra, nekaj minut): pravilen časovni trenutek nagrade, ponavljanje. Napredek po ukazu 0–100 %.
- **Učljivost po pasmi** iz virov (npr. raziskava Stanley Coren, *The Intelligence of Dogs* — rangiranje poslušnosti; AKC opisi temperamenta). Border Collie hitro, nekatere pasme počasneje; mešanček povprečno z naključjem.
- Šolanje **zmanjša** nered, uničevanje, pobege; neredno šolanje → napredek počasi upada.
- Šolanje šteje kot rutina v Care Score (predlog) in je vidno staršu ("Kuža zna: sedi ✓, pridi 60 %").
- **Stanje (6. 10. 2026, M5-R03):** ✅ **strežnik** — ukazi sedi, pridi, prostor, lulat zunaj (David); mini-igra s pravim trenutkom za pohvalo, ki jo oceni strežnik; Border Collie 2×, mešanček ±20 % na psa (David); 5 min na dan, +1 / −2 točki, učinka na luže in uničevanje (predlogi, *čaka Davida*); dnevna rutina v Care Score; staršu vidno. ⏳ **aplikacija** (zaslon vaje, gumb »Pohvali«, prikaz pri staršu) — *načrt*. Podrobno: PRODUCT_SPEC §5 / §11.

## 5. Kaj to pomeni za aplikacijo in strežnik (pregled)
- Nov korak pri staršu: **"Izberi kužka"** (pasma, izvor, starost) pred PIN-om (nadgradnja M2-04).
- Pravila igre postanejo odvisna od **faze** (ne samo pasme): okna hranjenja, cilj korakov, pogostost nereda, nova vrsta dogodkov (uničevanje), šolanje.
- Novi mediji po fazah (rast), nova stanja videov (npr. "grize copat", "luža").
- Ocena (Care Score) vključi šolanje in odziv na vedenjske dogodke.

## 6. Predpogoj: podatki iz virov (M1-19, razširjeno)
Za vsako pasmo in fazo zbrati z viri: velikost/teža po starosti (rastne krivulje), število obrokov, dnevno gibanje, spanje, starost čistoče (navajanje), obdobje menjave zob, učljivost (rang + vir), tipične vedenjske težave. Viri: FCI/AKC standardi, veterinarska literatura (WSAVA smernice prehrane, rastne krivulje), Coren, kinološke zveze. Vsaka številka v bazi ima `source`.

## 7. Odločitve (David, 5. 10. 2026)
- **Šolanje:** kratka dnevna vaja (mini-igra nekaj minut: ukaz → pravi trenutek za nagrado); napredek po ukazu 0–100 %, hitrost po učljivosti pasme; brez vaje napredek počasi upada.
- **Izbira ob začetku:** vse štiri starosti takoj (mladiček, mlad pes, odrasel, starejši) in oba izvora (kupljen, posvojen).
- **Cena:** vse izbire (starost, izvor) za vse — mešanček brezplačen v vseh kombinacijah; plačljive so pasme in 12-tedenski izziv s certifikatom.
- **Podatki:** najprej raziskava z viri (tabela z virom za vsako številko) → David pregleda in potrdi → uvoz → šele nato gradnja M5.
- **Odgovori na odprta vprašanja M5-R01 (David, 5. 10. 2026):**
  - **Okna hranjenja mladička 2 uri** (začetki ostanejo): 4 obroki 07–09, 11–13, 15–17, 19–21; 3 obroki 07–09, 13–15, 19–21; 2 obroka nespremenjeno (06–10 / 17–21).
  - **Meje faz kot predlagane:** mladiček < 9 mesecev, mlad pes < 36, starejši od 0,75 × življenjske dobe (mešanček 108, Border Collie 118 mesecev); starost ob prihodu mladiček 2 meseca, ostale faze prvi mesec faze.
  - **Gibanje:** mešanček odrasel 60 min, mladiček / mlad pes 10 min × starost v mesecih do odraslega cilja, starejši 75 % odraslega; **starejši 2 obroka**.
  - **Obstoječi (legacy) psi ostanejo na starih pravilih za vedno**, tudi po koncu izziva; novi psi gredo skozi izbiro kužka.
  - Kjer viri ne dajo točne številke, vrednost postavi PetPrep na podlagi virov in te odločitve (v podatkih »potrdil David 2026-10-05«, vir ostane dokaz).

## 8. Stanje izvedbe (posodobljeno 6. 10. 2026)
| Del | Stanje | Kje |
|---|---|---|
| §1 Izbira ob ustvarjanju (pasma, izvor, starost) | ✅ **strežnik** (M5-R01) — `POST /api/parent/generate-pin {breed, origin, age_stage}`; mešanček brezplačen v vseh kombinacijah; ✅ **aplikacija** (M5-R04, 5. 10. 2026): "Izberi kužka" pred PIN-om, plačljive pasme vidne a zaklenjene, obdobje / starost / izvor / naslednje obdobje pri otroku in staršu | PRODUCT_SPEC §3 |
| §2 Starost in faze | ✅ strežnik — starost = ob prihodu + tedni; faze iz virov, meje potrdil David (mladiček < 9 mes., mlad < 36 mes., starejši od 108 / 118 mes.); pravila faze od lokalne polnoči po tedenskem rojstnem dnevu | PRODUCT_SPEC §4, `breed_stage_params` |
| §2 Slika raste s psom | ✅ strežnik — ob prehodu faze nova referenčna slika istega psa (image-to-image, Nano Banana Pro Edit) + videi; stare slike v zgodovini (album pozneje) | PRODUCT_SPEC §10 |
| §3 Več obrokov (mladiček 4 → 3 → 2) | ✅ strežnik — okna po fazi (2-urna, David 5. 10.), obrok v tihih urah opravi starš | PRODUCT_SPEC §5 |
| §3 Potrebe po gibanju po fazi | ✅ strežnik — cilj korakov = minute × 100 (minute potrdil David 5. 10.) | PRODUCT_SPEC §5 |
| §3 Več spanja | 🟡 podatki shranjeni (S28), uporaba v videih / vedenju še ne | `breed_stage_params.sleep_hours` |
| §3 Nered (luža mladička), uničevanje | ✅ **strežnik** (M5-R02, David 6. 10. 2026) — mladiček zdrži 1 h na mesec starosti (S30 / S31), ura teče samo izven tihih ur, »Pelji ven« jo ponastavi; uničevanje ob menjavi zob (3–6 mes., verjetnost 0,5 / dan — *nepreverjen predlog*) ali dan po zamujenem sprehodu, reši se s »Pospravi in daj igračo«; oboje je rutina čiščenja (2 h) in zniža čistočo; plačljiva pasma dobi videa luže in grizenja. ⏳ **aplikacija** (gumbi, ikone, videi) | PRODUCT_SPEC §5 / §10 / §11 |
| §3 Plašnost posvojenega psa, nered posvojenega odraslega psa (S39 / S40) | ⏳ odprto vprašanje (DECISIONS 6. 10.) | ROADMAP |
| §4 Šolanje | ✅ **strežnik** (M5-R03, David 6. 10. 2026) — 4 ukazi, mini-igra »Pohvali ob pravem trenutku« (urnik in ocena na strežniku), Border Collie 2× / mešanček ±20 %, 5 min na dan, upad brez vaje, rutina v Care Score, manj luž (lulat zunaj) in manj grizenja med menjavo zob (prostor) — številke vaje in učinkov so *nepreverjeni predlogi*; ⏳ **aplikacija** | PRODUCT_SPEC §5 / §11 |
| §6 Podatki iz virov | ✅ uvoženo (insert-only); od 5. 10. vse vrednosti potrjene — iz vira ali z Davidovo odločitvijo (»potrdil David 2026-10-05«, enkratna podatkovna migracija za produkcijo); kar admin označi kot NEPODPRTO, je vidno v Filamentu | `docs/research/dog-data/`, DECISIONS 5. 10. |
