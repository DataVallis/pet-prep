# PetPrep — Mačka: specifikacija skrbi (M5-R06)

> **Status:** spec **potrjen**, 8. 10. 2026. **Odločil David 7. 10. 2026:** na začetku sta vrsti **pes in mačka**. Izbira gre najprej po **vrsti**, nato po **pasmi** (seznam z iskanjem, skalabilno). Pred gradnjo je potreben spec skrbi za mačko, z viri kot v [`REALISM_SPEC.md`](REALISM_SPEC.md). **David 8. 10. 2026 13:47:** potrdil priporočene odgovore na vsa vprašanja Q1–Q10 (§0). Kar je še označeno z **(D)**, je Claudov predlog, ki čaka potrditev (seznam na koncu §0). **Zgrajeno (skrito, `PETPREP_CATS_ENABLED=false`):** temelj vrste na strežniku (M5-R06-01), izbirnik v aplikaciji (M5-R06-02), podatki o mački po fazah z viri (M5-R06-03) **igra namesto korakov na strežniku** (M5-R06-04: igra s palico, merilnik »Igra«, rutina, opomnik, brez korakov in bolezni zaradi sprehoda) ter **pesek, praskanje in česanje na strežniku** (M5-R06-05: uporabe peska in rok za čiščenje, nered zraven peska, tedenska menjava, praskanje dan po zamujeni igri z razrešitvijo »na praskalnik + pohvala v 3 s«, česanje Maine Coona in vozel v dlaki) ter **mačja besedila obvestil in strežnika EN + SL** (M5-R06-06, 9. 10. 2026 — osnutek Claude, čaka Davidov pregled v [`CAT_TEXTS_REVIEW.md`](CAT_TEXTS_REVIEW.md)). AI videz in mačji zasloni v aplikaciji še niso zgrajeni.
> **Oznake:** številka z oznako **[Cn]** ima vir v [`docs/research/cat-data/sources.md`](../research/cat-data/sources.md). **(D)** pomeni predlog brez vira ali še neodločeno, kar čaka Davidovo potrditev. Kar je (D), se staršem in otrokom ne sme prikazati kot dejstvo.
> **Temeljno pravilo (kot pri psu):** številk o mačkah si ne izmišljujemo. Pravila igre (odstotki, ure, upadanje) so abstrakcija igre in so vedno označena kot odločitev, ne kot literatura.
> **Naslednji korak:** odgovori na Q1–Q10 so potrjeni in prenešeni v `PRODUCT_SPEC.md` §13 (*načrt*). Izvedbeni načrt je v [`docs/engineering/M5-R06_PLAN.md`](../engineering/M5-R06_PLAN.md), vrednosti z viri v [`docs/research/cat-data/data.json`](../research/cat-data/data.json) (8. 10. 2026). David je načrt potrdil 8. 10. 2026; gradnja poteka po nalogah M5-R06-01 … 09 (R06-01 … 05 zgrajeni, mačke skrite).

## 0. Vprašanja za Davida — odločeno

Vsa vprašanja Q1–Q10: ✅ **David 8. 10. 2026 13:47** — potrdil priporočeni odgovor (spodaj).

| # | Vprašanje | Odločitev |
|---|---|---|
| Q1 | **Kaj pri mački nadomesti sprehod (korake)?** | ✅ **David 8. 10. 13:47:** **Dnevna igra s palico s peresom** (mini-igra, ~60 s): odrasla muca **2× na dan**, mucek **3× na dan** (viri: 2–3 igre na dan, mladiči več [C10]; več kratkih iger čez dan [C11]). Ena mini-igra v aplikaciji velja za eno pravo igro (prava traja 10–15 min [C10], to otroku tudi povemo). **Koraki s telefona ostanejo samo za psa.** Mačja družina zato ne potrebuje dovoljenja za Apple Zdravje / Health Connect. |
| Q2 | **Kaj se zgodi, če otrok cel dan ni igral z muco?** Pes po dnevu brez sprehoda zboli. | ✅ **David 8. 10. 13:47:** **Muca ne zboli.** Ni vira, da bi en dan brez igre povzročil bolezen. Namesto tega: zamujena rutina (Care Score) in naslednji dan dogodek **»opraskala je kavč«** (glej Q10). Tako je posledica realna (dolgčas → neželeno vedenje [C8, C22]), a ne pretirana. |
| Q3 | **Mačje stranišče (pesek):** kako deluje? | ✅ **David 8. 10. 13:47:** Vsaka **uporaba peska** je rutina **»Počisti pesek«** z rokom **4 ure izven tihih ur**. Odrasla muca ima **2 uporabi na dan**, mucek **3** (viri: odrasla lula 2–4× in kaka 1–2× na dan, mladiči pogosteje [C6]; čistiti vsaj 1–2× na dan [C5, C6, C7]). Če pesek ni počiščen, muca naredi **nered zraven peska**. Takrat čistoča pade na 0 % in velja **obstoječa lestvica** (alarm po 1 h, bolezen po 6 h izven tihih ur, game over po 24 h). **Tedenska menjava vsega peska** je ena rutina na teden programa [C5, C6, C7]. |
| Q4 | **Življenjske faze in starost ob prihodu** | ✅ **David 8. 10. 13:47:** Po smernicah AAHA/AAFP 2021 [C1]: **mucek < 12 mesecev**, **mlada mačka 1–6 let**, **zrela mačka 7–10 let**, **starejša mačka od 10 let**. Ob prihodu: mucek **2 meseca** (domača mačka, ≥ 8 tednov [C13]) oz. **3 mesece** (Maine Coon, rodovniški mucki gredo od doma pri 12–13 tednih [C13]). Mlada mačka **12**, zrela **84**, starejša **120** mesecev (prvi mesec faze, kot pri psu). Pri 12-tedenskem izzivu z muckom ta v 11. (Maine Coon v 10.) tednu postane mlada mačka. |
| Q5 | **Plačljiva pasma za izziv** | ✅ **David 8. 10. 13:47:** **Maine Coon.** Je najbolj registrirana pasma v FIFe 2024 (23.775, 1. mesto [C19]). Ima dolgo dlako, ki jo je treba redno česati [C17, C18], torej pravo dodatno obveznost (kot Border Collie z gibanjem). Je velika, pozno dozori (3–5 let [C17]) in je vizualno prepoznavna za AI (čopki na ušesih, ovratnik, dolg rep [C16]). **British Shorthair** (2. mesto [C19]) ostane kandidat za drugo plačljivo pasmo, ker je za »izziv« premalo zahteven. |
| Q6 | **Cena izziva za mačko** | ✅ **David 8. 10. 13:47:** **Ista: 49,99 €**, isti izdelek `petprep_challenge_12w`, ista pravila (PAYMENTS_SPEC P1–P11). **Domača mačka (mešanka) je brezplačna** kot mešanček, izziv pa je samo s plačljivo pasmo (M5-F03). |
| Q7 | **Notranja ali zunanja mačka?** | ✅ **David 8. 10. 13:47:** V simulaciji je muca **notranja (stanovanjska)**. Igra ne more simulirati zunanjih nevarnosti, vse otrokove naloge pa so v hiši. AAFP 2024 zahteva, da notranji mački zagotovimo igro, lov in vertikalni prostor [C22], ASPCA pa priporoča notranjo mačko [C5]. Staršu ob izbiri razložimo, da je zunanja mačka v resnici pogosta odločitev družine. |
| Q8 | **Česanje** (samo Maine Coon) | ✅ **David 8. 10. 13:47:** Rutina **»Počeši muco«** **3× na teden**, z razmikom vsaj 1 dan. Viri dajejo razpon: TICA pri gosti dlaki vsak dan, CFA »vsaj nekajkrat na teden« [C17], Vetstreet enkrat na teden [C18]. Kratka mini-igra drgnjenja (kot čiščenje). Brez bolezni: če otrok česanje zamudi 2× zapored, nastane **»vozel v dlaki«**, ki ga razreši daljše česanje. Domača kratkodlaka muca te rutine nima. **Natančneje David 8. 10. 2026 ~22:20:** vozel se šteje **tedensko** — ob koncu vsakega tedna programa (tedenski rojstni dan), če manjkata ≥ 2 od 3 česanj; brez kazni (viden, naslednje česanje je ~2× daljše in ga razreši; v Care Score šteje le zamujena rutina). Zgrajeno na strežniku (M5-R06-05). |
| Q9 | **Voda in hitrosti upadanja** | ✅ **David 8. 10. 13:47:** Sveža voda **2× na dan** (zjutraj in zvečer), razmik ≥ 4 h. Vir pravi, da posodo vsak dan pomijemo in napolnimo, voda pa je na voljo ves čas [C2, C5]. Pes ima 3×. Lakota **−8 %/h** in žeja **−8 %/h** za obe pasmi (abstrakcija igre, odločitev, ne literatura). Dodatni napor Maine Coona je česanje (Q8), ne hitrejša lakota (ni vira). |
| Q10 | **Šolanje (ukazi) in praskanje** | ✅ **David 8. 10. 13:47:** Mačka v prvi različici **nima šolanja z ukazi**. Namesto tega dobi dogodek **»opraskala je kavč«** (analogija psovega grizenja). Otrok ga razreši z akcijo **»Odnesi na praskalnik in pohvali«**, pri kateri mora pohvaliti v 3 sekundah, kot svetuje AAFP [C23]. Nikoli kazen [C23]. Dogodek se zgodi samo dan po zamujeni igri (Q2), ne naključno pri mucku (za pogostost ni vira). |

**Še odprto (D) — manjši predlogi, ki jih odločitve Q1–Q10 ne pokrivajo (čakajo Davida):**
- ~~imena faz (»mucek«, »mlada / zrela / starejša mačka«) (§2, §9)~~ ✅ David 8. 10. 2026 (PR #94);
- ~~otroški samostalnik »muca« / »mucek« (§9)~~ ✅ David 8. 10. 2026; besedilo pogodbe (§9) še odprto;
- ~~psova igra z žogo pri mački odpade, crkljanje ostane (§5.5, M5-R05)~~ ✅ David 8. 10. 2026 (načrt M5-R06);
- ~~podrobnosti mini-igre s palico: ~60 s, konec z »ulovom«, ≥ 2 h med igrama (§5.2)~~ ✅ David 8. 10. 2026 ~20:40: ~60 s z »ulovom«, šteje samo, če je otrok res sodeloval (preveri strežnik), razmik 2 h od zadnje **uspešne** igre, prekinjena igra ne šteje in nima kazni (M5-R06-04);
- ~~odrasla mačka 2 obroka na dan kot poenostavitev igre (§3)~~ ✅ David 8. 10. 2026;
- ~~zamujena tedenska menjava peska → rok za čiščenje 2 h namesto 4 h (§4)~~ ✅ David 8. 10. 2026;
- ~~mucek ob prihodu že navajen na pesek, brez »luže« (§4)~~ ✅ David 8. 10. 2026;
- ~~izvor »podarjena od znancev« = posvojena (§1)~~ ✅ David 8. 10. 2026;
- iskreno besedilo za starše o igri v aplikaciji (§5.4);
- besedila obvestil (§6) — *zgrajeno kot osnutek (M5-R06-06, 9. 10. 2026), čaka Davidov pregled:* [`CAT_TEXTS_REVIEW.md`](CAT_TEXTS_REVIEW.md) (tam tudi 4 odprta vprašanja: mucek / muca, angleški zaimek, »Tvoj otrok«, pasje grizenje); »prva pomoč« pri dolgotrajni lakoti (samo izobraževalno besedilo [C24]) še odprto — strežnik za psa nima takega besedila, sodi v aplikacijo (R06-08);
- AI videz domače mačke, faze v promptu in videi stanj (§8);
- podrobnosti izbirnika (sinonimi v iskanju, filtri pri > 8 pasmah) (§10).

## 1. Kaj starš izbere (pred PIN-om otroka)

| Izbira | Možnosti | Vpliv |
|---|---|---|
| **Vrsta** (nov 1. korak) | **pes** · **mačka** (kasneje več) | pravila skrbi, gumbi, videi, besedila, pogodba |
| Načrt | brezplačno · 12-tedenski izziv (kot danes, PAYMENTS_SPEC) | — |
| Pasma | mačka: **domača mačka (mešanka)** 🆓 · **Maine Coon** 💶 (Q5) | videz, česanje, življenjska doba, starost mucka ob prihodu |
| Izvor | **kupljena** (vzreditelj) · **posvojena** (zavetišče) (D: »podarjena od znancev« šteje kot posvojena) | kot pri psu: kupljena praviloma mucek |
| Starost ob prihodu | mucek · mlada · zrela · starejša (Q4) | potrebe, videz, faza |

## 2. Starost, faze in življenjska doba

- **Čas:** enako kot pri psu, **1 teden programa = 1 mesec** starosti (PRODUCT_SPEC §4). Čas zaklepa do plačila se ne šteje.
- **Faze (AAHA/AAFP 2021 [C1]):** mucek od rojstva do 1 leta · mlada mačka (*young adult*) 1–6 let · zrela mačka (*mature adult*) 7–10 let · starejša mačka 10+ let. Smernice se pri 10 letih prekrivajo, zato starejša mačka velja **od 120 mesecev** (Q4).
  - Za razliko od psa meja starejše faze **ni** 0,75 × življenjske dobe. Za mačke ima smernica svojo izrecno mejo, in ta ima prednost.
  - Obstoječa tabela faz (`puppy`, `young`, `adult`, `senior`) se preslika takole: `puppy` → mucek, `young` → mlada, `adult` → zrela, `senior` → starejša (§11).
- **Starost ob prihodu (Q4):** domača mačka 2 meseca (≥ 8 tednov [C13]), Maine Coon 3 mesece (12–13 tednov [C13]), mlada 12, zrela 84, starejša 120 mesecev.
- **Življenjska doba (VetCompass, UK, ob rojstvu [C14]):** mešanke **11,89 let**, Maine Coon **9,71 leta**, vse mačke 11,74 leta. Mačke živijo dlje kot moški mački [C15]. V igri te številke služijo le za razlago staršem in za videz, saj meja faze pride iz [C1].
- **Rast Maine Coona:** popolnoma dozori pri 3–5 letih [C17], zato je v fazi »mlada mačka« še vidno nedorasel (navodilo za videz, §8).
- **Spanje:** odrasla mačka 12–16 ur na dan, mucek do 20 ur [C12]. Podatek je za videe in vedenje (kot `sleep_hours` pri psu), ne za pravilo.

## 3. Hranjenje in voda

| Starost | Obroki na dan (vir) | V igri |
|---|---|---|
| mucek 2–3 mesece | 4 [C3] | **4** — okna 07–09, 11–13, 15–17, 19–21 (ista kot pri psu) |
| mucek 3–6 mesecev | 2–3 [C3], 3 [C2] | **3** — okna 07–09, 13–15, 19–21 |
| mucek 6–12 mesecev | 2 [C2, C3] | **2** — okni 06–10 in 17–21 |
| mlada in zrela mačka | 1–2 [C2] | **2** (D) |
| starejša mačka (10+) | enako kot odrasla [C2] | **2** |

- **Zakaj ne več majhnih obrokov:** mačke v naravi jedo veliko majhnih obrokov in polovico dneva namenijo iskanju hrane. Smernice svetujejo razdelitev na več majhnih obrokov in igrače z ugankami za hrano [C8, C9]. Za otroka bi bilo to preveč oken, zato je 2× na dan **poenostavitev igre** (D). To staršu povemo v razlagi. Igrača s hrano (»uganka za hrano«) je kandidat za kasnejšo nadgradnjo, ne za prvo različico.
- **Vsa ostala pravila hranjenja ostanejo kot pri psu:** okno, nujni obrok pri ≤ 20 % (M3-12), obrok v tihih urah opravi starš, »najprej počisti« (samo pri neredu zraven peska, §4).
- **Voda:** potreba 55–70 ml/kg na dan [C4]. Sveža voda mora biti na voljo ves čas, posodo vsak dan pomijemo in napolnimo [C2, C5]. Voda naj bo vsaj 50 cm od hrane in peska [C4], stranišče pa daleč od hrane in vode [C3, C7, C8] (besedilo za otroka). **V igri: »Sveža voda« 2× na dan, razmik ≥ 4 h** (Q9).
- **Lakota je pri mački resna:** mačka, ki nekaj dni ne je, je v nevarnosti zamaščenih jeter (hepatična lipidoza), ki so lahko smrtna [C24, C25]. To je razlog, da lestvica za lakoto ostane enako stroga kot pri psu. Za otroka in starša je to **samo izobraževalno besedilo**, brez števila dni.

## 4. Mačje stranišče (pesek) — nadomesti »kakca«

**Iz virov:** odrasla mačka lula 2–4× in kaka 1–2× na dan, mladi mucki kakajo 1–6× na dan, starejši mucki 1–3× [C6]. Pesek čistimo **vsaj enkrat** [C5] oz. **dvakrat na dan** [C6, C7], **ves pesek pa zamenjamo enkrat na teden** [C5, C6, C7]. Običajno priporočilo je en pladenj na mačko in še enega, vendar ni podprto z dokazi [C7].

**V igri (Q3, David 8. 10. 2026):**
1. **Uporaba peska:** za vsak lokalni dan vnaprej izžrebamo čase uporabe izven tihih ur, enakomerno po delih dneva (kot čase kakca). Odrasla muca ima **2**, mucek **3** uporabe. Muca je pravilno uporabila pesek, zato to **ni nered** in čistoča ostane. Hrana in voda nista zaklenjeni.
2. **Rutina »Počisti pesek«** (mini-igra z lopatko, kot drgnjenje) z rokom **4 ure, šteto samo izven tihih ur**. Pravočasno: rutina opravljena.
3. **Rok poteče:** muca naredi **nered zraven peska**. Od tu naprej je vse **enako kot kakec pri psu**: čistoča 0 %, »najprej počisti«, faze 1–3, bolezen po 6 h izven tihih ur in game over po 24 h. Nered počisti običajna igra čiščenja, ki hkrati očisti tudi pesek.
4. **Tedenska menjava peska:** ena rutina v vsakem tednu programa (od tedenskega rojstnega dne do naslednjega). Če jo otrok zamudi, ima naslednji teden rok za čiščenje **2 h namesto 4 h** (»pesek smrdi«), dokler peska ne zamenja (✅ David 8. 10. 2026). Rok se določi ob uporabi peska in se kasneje ne spremeni.
5. **Ponoči in v šoli** (tihe ure) uporab ne žrebamo. Za muco v tem času poskrbi družina, enako kot pri psu.
6. **Mucek** nima »luže« kot mladiček psa. Mucki so ob prihodu praviloma že navajeni na pesek (✅ David 8. 10. 2026; vir ni bil iskan), zato nered nastane samo iz neočiščenega peska.
7. *Zgrajeno na strežniku (M5-R06-05, skrito):* uporabe peska po urniku (ni nered, čistoča ostane), rok 4 h / 2 h izven tihih ur, ob poteku nered zraven peska (`litter_accident`) na obstoječi lestvici, »Počisti pesek« (`POST /api/child/pet/litter/scoop`), tedenska menjava kot mini-igra, ki jo oceni strežnik (`litter-change/start|finish`), čiščenje nereda počisti tudi pesek. Rok, ki poteče med zamrznitvijo (hard stop, veterinar) ali izpadom strežnika, ne naredi nereda.

## 5. Igra — nadomesti sprehod

### 5.1 Zakaj igra
Igra in lov sta ena od petih temeljnih potreb mačke [C8]. Notranja mačka mora imeti možnost »lova«, igre in vertikalnega prostora [C22]. Viri svetujejo **2–3 igre na dan po 10–15 minut**, mladiči več [C10], ter **več kratkih iger čez dan** [C11]. Najboljša igrača je **palica s peresom**, ki se premika **stran od mačke**, kot plen, in ne tik pred njenim obrazom (mačke na manj kot 25 cm slabo vidijo [C11]). Igrače menjamo, da se muca ne naveliča [C8].

### 5.2 Mini-igra »Palica s peresom« (Q1; podrobnosti: David 8. 10. 2026 ~20:40; strežnik zgrajen M5-R06-04)
- Otrok s prstom vleče pero po zaslonu. Muca se plazi, preži in skoči. Pero, ki beži **stran** od muce, je zanjo zanimivo, mahanje tik pred njenim nosom pa ne [C11]. Muca torej nagradi pravo tehniko.
- Igra se konča z **»ulovom«** (muca ujame pero), kar je naravni zaključek lova. Ena igra traja **~60 s**. ✅ **David 8. 10. 2026 ~20:40** (vir za »ulov« ni bil najden — odločitev igre).
- **Šteje samo, če je otrok res sodeloval** (✅ David 8. 10. 2026 ~20:40): strežnik začne igro in jo na koncu oceni, kot pri šoli. Aplikacija pošlje samo premike peresa (čas in ali se je pero umaknilo **stran** od muce). Igra šteje, če je bilo dovolj premikov »stran« (vsaj 8), razporejenih čez vso minuto (v vsaki četrtini vsaj eden), če je bilo premikov »stran« vsaj polovica (pravilna tehnika [C11]), če je otrok po vsakem skoku muce v 2 s umaknil pero in če premiki niso strojno enakomerni (zaščita pred skripto). Številke so mehanika mini-igre (`config/wand.php`, Claude), ne podatki o mačkah.
- **Prekinjena, nedokončana ali neuspešna igra ne šteje, nima kazni, otrok lahko takoj začne znova** (✅ David 8. 10. 2026 ~20:40).
- **V tihih urah ni igre — muca spi** (✅ David 8. 10. 2026 ~21:5x, kot prosta igra psa): igra, ki bi segla v tihe ure, se ne začne; opomnik počaka do konca tihih ur. Če tihe ure družine ne pustijo prostora za dnevni cilj (z razmikom 2 h), se rutina »Igra« tisti dan ne pričakuje (izpeljano pravilo). Hkrati se z muco igra en otrok; pragove sodelovanja (8 premikov, odziv na skok v 2 s, preverjanje enakomernosti) je potrdil David 8. 10. 2026.
- **Dnevni cilj** je število iger: mucek **3**, mlada / zrela / starejša **2** (Q1; [C10] 2–3, mladiči več). Med dvema **uspešnima** igrama mora miniti **vsaj 2 h** (✅ David 8. 10. 2026 ~20:40, merjeno od konca zadnje uspešne igre), da so razporejene čez dan kot v resnici.
- **Merilnik »Igra«** nadomesti merilnik »Gibanje«: opravljene igre / cilj. Ob lokalni polnoči se ponastavi na 0 %, enako kot energija psa. Ni na lestvici faz 1–3 in **ni bolezni** (Q2). Opomnik pride enkrat na dan, po istem pravilu kot opomnik za sprehod (ne prej kot 2 h po koncu nočnega okna).
- **Rutina »Igra«** (§7) šteje v Care Score kot sprehod. Pri skupni muci je cilj skupen: igre vseh otrok se seštejejo, vsak otrok pa mora za »pošten delež« opraviti vsaj cilj / n iger (zaokroženo navzgor).
- **Igra je za vse načrte**, tudi za brezplačno domačo mačko, ker je osnovna skrb (kot sprehod).

### 5.3 Koraki
**Koraki s telefona ostanejo samo pri psu.** Mačja družina ne vidi kartice za Apple Zdravje / Health Connect in aplikacija ne prosi za dovoljenje. Strežnik sync korakov za mačko zavrne z 422 `steps_not_applicable` (zgrajeno M5-R06-04). Zasebnost je s tem boljša, ker zbiramo manj podatkov o otroku.

### 5.4 Iskreno do staršev (D)
Pri psu sprehod otroka spravi ven. Igra z muco pa je v aplikaciji in zahteva zaslon. To staršem povemo odkrito: **»Igra v aplikaciji traja eno minuto, prava igra z muco pa 10–15 minut, 2–3-krat na dan«** [C10]. Kasneje (po MVP) je mogoča nadgradnja z »resničnimi nalogami« (npr. naredi igračo iz kartona), ki jih potrdi starš.

### 5.5 Igra in crkljanje (M5-R05) pri mački (D)
- Psova **igra z žogo** (samo razpoloženje, plačan izziv) pri mački **odpade**, ker je igra s palico že rutina skrbi.
- **Crkljanje** (božanje) ostane enako kot pri psu: samo razpoloženje, samo plačan izziv. Mnoge mačke imajo raje pogost, a nežen in kratek stik [C8], zato je crkljanje kratko. Vabilo »crkljanje« ostane (1 na dan).
- *Zgrajeno na strežniku (M5-R06-04):* žoga za mačko vrne 422 `play_not_available`, crkljanje deluje; mačka dobi samo vabilo za crkljanje (1 na dan), ki se pokaže, ko je današnji cilj iger dosežen (mačja različica pravila »sprehod opravljen« — potrdil David 8. 10. 2026).

## 6. Zanemarjanje → obstoječa lestvica

| Potreba mačke | Metrika v igri | Faze 1–3 (≤ 30 %, ≤ 10 %, 0 % > 1 h) | Bolezen | Game over (24 h) |
|---|---|---|---|---|
| Hrana | Lakota | ✅ kot pes | ne neposredno | ✅ |
| Voda | Žeja | ✅ kot pes | ne neposredno | ✅ |
| Pesek | Čistoča (pade na 0 % šele ob **neredu zraven peska**, §4) | ✅ kot pes | ✅ po 6 h izven tihih ur (kot pes) | ✅ |
| Igra | Igra (namesto gibanja) | ❌ (kot sprehod) — en dnevni opomnik | ❌ (Q2) → naslednji dan »opraskan kavč« | ❌ |
| Česanje (Maine Coon) | — (samo rutina) | ❌ | ❌ → »vozel v dlaki«, ko ob koncu tedna manjkata ≥ 2 od 3 česanj (Q8, David 8. 10. ~22:20) | ❌ |

- **Obvestila (D):** besedila se pišejo posebej za mačko (»Tvoja muca je lačna«, »Pesek je treba počistiti«). Nikoli ne vsebujejo imen. Pravila pošiljanja so ista (PRODUCT_SPEC §6, M3-12). Obvestilo nikoli ne zahteva dejanja, ki ga aplikacija zavrne. *Zgrajeno na strežniku (M5-R06-06, osnutek Claude, čaka Davida):* vsa obvestila za muco imajo svoje besedilo EN + SL (`push.cat.*`, ženski spol: »muca je lačna«, »bo zbolela«); ko je odprto samo praskanje, obvestilo o hrani / vodi reče »najprej jo odnesi na praskalnik in jo pohvali« (čiščenje praskanja ne razreši), s praskanjem in neredom »najprej počisti nered in jo odnesi na praskalnik«.
- **Opraskan kavč (Q10):** največ en dogodek na dan, ob naključni minuti izven tihih ur, samo dan po zamujeni rutini »Igra«. Čistoča pade na 0 % kot pri psovem grizenju in velja rok **2 h izven tihih ur** (rutina čiščenja; ✅ David 8. 10. 2026 ~22:20 — natanko kot grizenje, nato obstoječa lestvica: alarm, bolezen po 6 h, game over po 24 h). Razreši ga »Odnesi na praskalnik in pohvali« [C23]: otrok odnese muco na praskalnik, ko pristane, ima 3 s za pohvalo (oceni strežnik); prepozna ali prezgodnja pohvala nima kazni, otrok poskusi znova. *Zgrajeno na strežniku (M5-R06-05).*
- **Vozel v dlaki (Maine Coon, Q8):** ✅ David 8. 10. 2026 ~22:20 — tedenski števec (≥ 2 od 3 česanj tedna zamujeni → vozel ob tedenskem rojstnem dnevu); brez kazni: viden, naslednje česanje ~2× daljše ga razreši, šteje le zamujena rutina. *Zgrajeno na strežniku (M5-R06-05).*
- **Bolezen, hard stop, zaklep do plačila, game over in Breed Downgrade** (Maine Coon → domača mačka) delujejo kot pri psu.

## 7. Rutine in Care Score (pregled za mačko)

| Rutina | Koliko | Opravljena | Zamujena |
|---|---|---|---|
| Hrana | okna po fazi (§3) | hranjenje v oknu | okno brez hranjenja |
| Voda | 2 na dan (Q9) | vsako dolivanje | manjkajoče ob koncu dneva |
| Počisti pesek | vsaka uporaba (2 ali 3 na dan) | v 4 h izven tihih ur (Q3) | rok potekel → nered zraven peska |
| Čiščenje | vsak nered (zraven peska, opraskan kavč) | v 2 h izven tihih ur (kot pes) | ni rešeno |
| Menjava peska | 1 na teden programa | kadarkoli v tednu | teden mine brez menjave |
| Igra | 3 (mucek) / 2 na dan | cilj iger dosežen | dan se konča pod ciljem |
| Česanje (samo Maine Coon) | 3 na teden (Q8), največ 1 na dan | opravljena mini-igra | ob koncu tedna programa manjkajoča česanja (≥ 2 → vozel v dlaki) |

Formula Care Score, pošten delež, semafor in pravila »rutina se ne pričakuje« ostanejo nespremenjeni (PRODUCT_SPEC §9, §11).

## 8. AI videz (Pet DNA za mačke)

- **Slog fotografije ostane:** fotorealistično, cela žival, dnevna svetloba, preprosto domače ozadje, brez ljudi, rok, besedila in imen (PRODUCT_SPEC §10). Besedo »dog« v vseh predlogah zamenja vrsta.
- **Domača mačka (mešanka):** kratka dlaka (redko poldolga), vzorci tabby (progast, marmoriran, pikast), enobarvna (črna, bela, siva, rdeča), dvobarvna z belo (»smoking«), želvovinasta, tribarvna; oči zelene, rumene / jantarne, bakrene, modre samo ob beli barvi. Viri za deleže barv domačih mačk **niso bili najdeni**, zato je seznam osnutek z `verified: false`, kot pri mešančku (D).
- **Maine Coon (FIFe [C16]):** velik, močan okvir, kvadraten obris glave, velika ušesa, **risji čopki** zaželeni, svilnata dlaka, kratka na glavi in ramenih ter daljša po hrbtu in bokih, **ovratnik**, rep vsaj tako dolg kot telo in z dolgo dlako. Dovoljene so vse barve **razen** točkastih (siamskih) vzorcev, čokoladne, lila, cimetove in »fawn«. Dovoljene so vse barve oči **razen modre** (modra samo pri beli). Teža po TICA: samci 5,9–8,2 kg, samice 4,1–5,9 kg [C17].
- **Faze v promptu (D):** mucek ima velika ušesa in glavo glede na telo, kratke noge in puhasto dlako. Mucek Maine Coona je že vidno večji, a puhast, in ima čopke. Mlada mačka Maine Coon je še nedorasla (polna velikost pri 3–5 letih [C17]). Pri starejši mački se izogibamo klišejev bolezni; videz je le malo vitkejši in dlaka manj sijoča (vira ni, D). Rast se ustvari z isto tehniko slike iz slike (isti posamezni muc).
- **Videi stanj (D):** `idle` (sedi, mežika, premika rep), `sleeping` (spi zvita v klopčič; mačke veliko spijo [C12]), `low_energy` → **»dolgčas«** (leži in gleda skozi okno), `hungry` (sedi ob prazni skledi in gleda gor), `sick` (mirno leži stisnjena, oči priprte, brez pretresljivih prizorov), `playing` (skače za peresom na vrvici, ki visi od zgoraj, brez roke). Videi vedenja: `scratching` (praska kavč) namesto `chewing`, brez `accident` (nered zraven peska prikaže aplikacija z ikono). Zvoka ni (Kling 3.0 Pro je brez zvoka), zato predenje in mijavkanje sodita k M5-R07.
- **Strošek:** isti modeli in enako število videov kot pri psu, torej ≈ 1,27 $ za osnovni nabor in ≈ 3,51–4,07 $ za polni nabor (PRODUCT_SPEC §10).

## 9. Pogodba o odgovornosti — mačka (osnutek besedila, D)

> Zavezujem se, da bom vsak dan odgovorno skrbel za svojo virtualno muco:
> • Hranil jo bom ob pravem času.
> • Vsak dan ji bom dal svežo vodo.
> • Vsak dan bom počistil njen pesek in ga enkrat na teden zamenjal.
> • Vsak dan se bom igral z njo, da bo zdrava in vesela.
> • *(samo Maine Coon)* Redno jo bom česal, da se ji dlaka ne bo zavozlala.
> • Odzval se bom na opozorila, preden pride do bolezni ali virtualnega zavetišča.
>
> Ali sprejemaš to odgovornost?

- **Otroški samostalnik:** »muca« (ženski spol) za vse mačke, »mucek« za mladiča (D). Psova besedila uporabljajo »kuža« (moški spol). Ker se v slovenščini glagoli in pridevniki ujemajo v spolu (»kuža je lačen« : »muca je lačna«), morajo biti besedila **ločena po vrsti**, ne zamenjava ene besede (§11).
- Angleščina: *cat / kitten*, ista struktura.

## 10. Izbirnik vrste → pasme (UX)

Velike kartice za vsako pasmo ne zadoščajo, ker bo pasem in vrst veliko (David, 7. 10.). Predlog (D):
1. **Vrsta:** dve veliki ploščici, **Pes** in **Mačka**, s fotografijo ali ikono. Kasneje jih je lahko več, mreža 2 × N. Ob prihodu na zaslon ni privzete izbire. »Pridruži se ljubljenčku« (obstoječi ljubljenček) tega koraka nima.
2. **Načrt** (brezplačno / izziv): kot danes, pred pasmo, ker določa, katere pasme so izbirljive.
3. **Pasma: seznam z iskanjem.**
   - Iskalno polje na vrhu išče brez šumnikov in brez razlike med velikimi in malimi črkami (»mesanka« najde »mešanka«) ter po sinonimih (»Maine Coon«, »mejnkun«; D).
   - Na vrhu je brezplačna izbira (Mešanček / Domača mačka) z značko **Brezplačno**, nato plačljive pasme po abecedi z značko **Izziv**.
   - *Zgrajeno (M5-R06-01, Claude):* vrstni red plačljivih pasem določa `breed_configs.sort_order`, ki ga ureja admin (ne samodejna abeceda); ob enakem `sort_order` odloči slug pasme. Abecedo dobimo tako, da admin nastavi `sort_order` po abecedi.
   - Vrstica ima majhno sliko, ime in eno vrstico lastnosti (npr. »Dolga dlaka · česanje 3× na teden«).
   - Zaklenjena pasma je siva, s pojasnilom ob tapu (kot M5-F03).
   - Seznam pride s **strežnika** (katalog pasem po vrsti, z zaklepom in besedili), ne iz kode aplikacije. Nova pasma tako ne potrebuje nove različice aplikacije.
   - Pri več kot ~8 pasmah se pojavijo filtri (velikost, dlaka, energija) (D).
4. **Izvor** in **starost ob prihodu:** kot danes. Besedila po vrsti (»mucek« namesto »mladiček«), starosti iz §2.
5. **Povzetek** pred PIN-om pokaže vrsto, pasmo, izvor, starost in načrt.

## 11. Technical impact (English, no code)

Impact list only. David approved Q1–Q10 on 2026-10-08 13:47. Built so far (hidden behind `PETPREP_CATS_ENABLED`): species foundation (M5-R06-01), app picker (M5-R06-02), the cat life-stage **data** with the seven new keys (M5-R06-03), **play instead of steps on the server** (M5-R06-04: `pet_care_sessions`, `POST /api/child/pet/wand/start|finish`, play meter on `energy_level`, `play` routine with fair share, `play_reminder`, `pets.play_missed_on`; steps → 422 `steps_not_applicable`, training / ball dog-only) and **litter, scratching and grooming on the server** (M5-R06-05: `litter_use` / `litter_accident` / `scratching` hygiene events, scoop deadline 4 h / 2 h while the weekly change is overdue, `POST /api/child/pet/litter/scoop`, `litter-change/start|finish`, `grooming/start|finish`, `scratching/start|finish`, routines `litter_scoop` / `litter_change` / `grooming`, matted coat, `litter_reminder`, payload blocks `litter` / `grooming` / `scratching`). Texts and media follow in M5-R06-06 … 09 (`docs/engineering/M5-R06_PLAN.md`).

**Data model**
- New `Species` enum (`dog`, `cat`) and a non-null `pets.species` column. Existing rows are backfilled to `dog`, so legacy dogs are unaffected.
- `breed_configs.species` (FK-like check) plus per-breed catalogue fields: `premium_unlock` becomes the single source of the mutt / paid rule (replaces `BreedType::isPremium()`, HANDOFF debt), display-name i18n keys, sort order and search keywords.
- `BreedType` becomes data-driven (rows in `breed_configs`), or at minimum gains `domestic_cat` and `maine_coon` with a `species()` method. The data-driven option is preferred, because the picker is meant to scale.
- `breed_stage_params`: cat rows (`domestic-cat`, `maine-coon`) reuse the existing stage values (`puppy` = kitten, and so on).
- New keys (CHECK constraint and `StageParamKey`): `play_sessions_per_day`, `play_min_gap_minutes`, `litter_uses_per_day`, `litter_scoop_deadline_hours`, `litter_full_change_days`, `grooming_sessions_per_week`, `scratching_after_missed_play`.
- `data_ref` points to `docs/research/cat-data/data.json` (to be written after approval, with `source_id` = C-ids).
- `pets.life_stage` labels per species. `LifeStage::promptCue()` becomes per species.

**Rules engine branches**
- `DailyWalkService` and the steps sync are dog only. For cats, steps return 422 `steps_not_applicable` and the midnight walk illness never applies.
- A new daily play service sets the play metric (sessions / goal), resets at midnight, sends one reminder per day, and on a missed play day schedules the next-day scratching.
- `HygieneEventService`: new `litter_use` events (scheduled like poop, not a mess), a scoop deadline that counts outside quiet hours, and on expiry a `litter_accident` mess on the existing ladder. The weekly full-change routine shortens the deadline to 2 h while overdue.
- `BehaviourEventService`: no puppy accident for cats. `scratching` replaces chewing (same mess semantics). The resolve action is "redirect + praise within 3 s", scored on the server.
- `TrainingService`: answers `training_not_available` for cats in v1.
- `PlayService` (M5-R05): ball is dog only; cuddle works for both.
- `CareScheduleService`: feed windows are reused. Water count and gap come from the breed config (2 / 240 min).
- `EscalationService` / `NotificationService`: species-aware texts. The ladder is unchanged. Energy-like handling applies to the play metric.
- `RoutineType` gains `play`, `litter_scoop`, `litter_change` and `grooming`. `RoutineLedgerService` and `CareScoreService` count them, including fair share for shared cats.
- Grooming (Maine Coon): a weekly counter and a "matted coat" event after 2 missed sessions.

**API and contract**
- `POST /api/parent/generate-pin` takes `{species, breed, origin, age_stage, plan}`. A breed that does not match the species → 422.
- New `GET /api/breeds?species=` returns the catalogue with lock state and i18n keys, so the picker no longer hard-codes `PICKER_BREEDS` / `PREMIUM_BREEDS`.
- Child and parent payloads carry `species` and the play / litter / grooming state.
- Regenerate `schema.ts` and update ARCHITECTURE §3 and DIAGRAMS.

**Media**
- `PetAppearancePrompt`: species templates for STYLE, EDIT_KEEP, VIDEO_STYLE and the negative prompt (every "dog" wording).
- `config/breed_appearance.php`: cat breeds (domestic `verified: false`; Maine Coon traits from FIFe C16).
- `PetStateEnum` video prompts per species. New behaviour video `scratching`; no `accident` for cats.
- `MediaEntitlementService` is unchanged (entitlement by payment).

**Mobile**
- Picker: species step plus a searchable breed list driven by the server (§10).
- Child HUD: Play meter and button (wand mini-game), litter button (scoop mini-game), weekly change, grooming for Maine Coon, scratching resolve. The walk overlay and the Health card are hidden for cats.
- Parent screens: species-aware labels and timeline rows.
- The contract text depends on the species.

**i18n**
- Separate species keys (`pet.dog.*`, `pet.cat.*`) for every child-facing and push string, because of Slovenian grammatical gender (kuža *je lačen* / muca *je lačna*). There is no runtime noun substitution.
- EN and SL ship together. Store listing, website (`pet-prep-website`) and FEATURES are updated when the feature ships.

**Admin**
- Filament: species filter on breed configs, stage params and pets. The AI Lab gets cat prompts.

**Tests (definition of done)**
- Pest:
  - species / breed validation (422)
  - a cat has no steps or walk illness
  - litter deadline → accident at 4 h outside quiet hours
  - overdue weekly change → 2 h deadline
  - play goal / reset / reminder, and missed play → scratching next day
  - scratching resolve within 3 s
  - grooming routine and matted coat
  - Care Score with the new routines, including fair share
  - training unavailable for cats
  - legacy dogs and existing dogs unchanged (regression)
  - catalogue endpoint
  - All time rules use `Carbon::setTestNow()` in the family timezone.
- Jest: picker species step and search (diacritics, synonyms), species-aware HUD, i18n key completeness for both species.

**Docs (in the same PRs)**
- PRODUCT_SPEC (cat rules), REALISM_SPEC §1 (species), PAYMENTS_SPEC (cat challenge), PLAY_CUDDLE_SPEC (§5.5), ARCHITECTURE, DIAGRAMS, FEATURES, audiences (parents / kids), BUILD_LOG, DECISIONS.

## 12. Kaj ni v prvi različici
Mačka zunaj hiše, več mačk na družino z deljenim peskom (pravilo n + 1 pladnjev [C7]), igrače z ugankami za hrano [C9], striženje krempljev [C23] in veterinarski obiski kot del igre. Veterinarske osnove so samo izobraževalno besedilo za starša: cepljenje od 6–8 tednov na 2–4 tedne do ≥ 16 tednov [C21], kastracija pri 4 mesecih [C20, C3] oz. do 5 mesecev [C5], letni pregled [C5]. Kandidati za kasneje: izobraževalne kartice »Pravi mucek potrebuje …« za starša (vir pri vsaki), British Shorthair kot druga plačljiva pasma, zvoki (M5-R07).
