# PetPrep — Dnevnik razvoja (Build Log)

> **Namen:** surovina za vsebine — objave na omrežjih, zgodbo za investitorje, gradiva za partnerje, sporočila za starše (mame) in otroke. Vsak vnos pove *kaj* se je zgodilo, *zakaj* je pomembno in kako to povedati posameznim publikam.
> **Pravila:** samo resnična dejstva (kar je bilo res narejeno ali odločeno) · številke z virom · brez osebnih podatkov otrok · kar še ni zgrajeno, je označeno kot *načrt*.
> **Vzdrževanje:** orkestrator (Claude) doda vnos po vsaki pomembni seji (`/handoff`). Najnovejši vnos je na vrhu.

Legenda publik: 📣 omrežja · 💼 investitorji · 🤝 partnerji (trgovine, zavetišča, veterinarji, šole) · 👩 starši / mame · 🧒 otroci · 🛠 tehnična publika (dev skupnost, LinkedIn)

---

## 2026-10-09 — Muca dobi svoj AI videz (M5-R06-07, skrito)

**Kaj se je zgodilo:** Strežnik zna narisati muco. Vsaka muca ob rojstvu izžreba svoj videz — domača mačka iz barv in vzorcev (progasta, marmorirana, »smoking«, želvovinasta, tribarvna …), Maine Coon po uradnem standardu FIFe (velik, z ovratnikom, čopki na ušesih in dolgim kosmatim repom). Opis za AI ima svojo mačjo predlogo in nikoli ne vsebuje besede »pes«. Videi stanj so mačji: ko muca nima dovolj igre, ji je **dolgčas** in gleda skozi okno (pes je utrujen), igra se s peresom, ki visi od zgoraj, spi zvita v klopčič. Nov video **»praska kavč«** se prikaže, ko muca dan po zamujeni igri opraska kavč; videa luže pri muci ni. Mucek Maine Coona je na sliki že večji od drugih muckov, mlada Maine Coon pa še ni dorasla (polno velikost doseže pri 3–5 letih). V AI laboratoriju lahko admin mačke preizkusi in vidi ceno ene muce, preden mačke vklopimo.

**Zakaj je pomembno:** Otrok, ki izbere muco, mora videti muco, ki je res njegova in se vede kot muca — ne psa z mačjimi besedami. Hkrati se pri psih ni spremenilo nič: posnetek vseh 359 pasjih AI opisov, narejen pred spremembo, je ostal bajt za bajt enak.

**Številke:** 972 različnih videzov domače mačke, 312 Maine Coona (seznami so osnutek — za deleže barv domačih mačk ni vira); 7 mačjih videov stanj (6 + praskanje); strošek ene muce na življenjsko obdobje ≈ 1,27 $ (brezplačna, slika + 2 videa) oz. ≈ 4,07 $ (izziv, slika + 7 videov) po ceniku fal (*ocena*); pes z izzivom do ≈ 4,63 $. 22 novih testov strežnika (brez enega pravega klica AI storitve).

**Kako povedati:**
- 📣 Omrežja: »Vsaka muca v PetPrep je unikatna — od progaste mešanke do Maine Coona s čopki na ušesih. Ko se ji ne posvetiš, ti opraska kavč.« (*ko bodo mačke vklopljene*)
- 👩 Starši: »Muca v aplikaciji se vede kot prava muca: dolgčas jo vodi k praskanju, ne k bolezni.« (*načrt — mačke še niso vklopljene*)
- 🧒 Otroci: »Tvoja muca ima svoj videz, ki ga nima nobena druga.« (*načrt*)
- 💼 Investitorji: »Nova vrsta živali brez novih stroškov na enoto: isti AI modeli, ista cena na ljubljenčka kot pri psu.«
- 🛠 Tehnično: »Predloge za AI po vrsti; posnetek vseh pasjih promptov pred spremembo varuje obstoječe pse.«

## 2026-10-09 — »Najprej počisti, potem nahrani«, kadar je obrok še mogoč (M5-R06-06c)

**Kaj se je zgodilo:** Ko sta hkrati prazna skleda in nered (oboje 0 %), je kuža ali muca doslej otroku vedno poslala samo opomnik za nered. David je odločil, da to velja samo, ko obroka (ali vode) tisti dan ni več mogoče dati. Če bi bil obrok po čiščenju še mogoč — takoj ali kasneje danes —, otrok dobi »Tvoj kuža je lačen, a najprej je treba počistiti nered. Potem ga lahko nahraniš.« (ali uro naslednjega obroka). Pri psu in pri muci; besedila so ista, spremenila se je samo izbira. David je hkrati potrdil še sedem izvedbenih izbir pri mačjem pesku, praskanju in česanju (vse kot zgrajeno).

**Zakaj je pomembno:** Otrok izve oboje — kaj mora narediti najprej in da bo potem lahko nahranil. Obvestilo ne zamolči dejanja, ki je še mogoče.

**Številke:** 19 novih testov (pes in mačka, vse vrste nereda); 1668 testov zelenih; 0 spremenjenih besedil.

**Kako povedati:**
- 👩 Starši: »Ko je treba hkrati počistiti in nahraniti, opomnik otroku pove pravi vrstni red.«

## 2026-10-09 — Obvestila staršem vikajo, kuža ne zahteva nemogočega (M5-R06-06b)

**Kaj se je zgodilo:** David je odločil štiri vprašanja o besedilih obvestil. Slovenski alarm staršem zdaj vika (»Vaš otrok danes ni poskrbel za psa.«), pri psu in pri muci. Pri psu sta popravljeni dve napaki pravila »obvestilo nikoli ne zahteva nemogočega«: ko kuža nekaj pregrize, obvestilo o hrani reče »najprej pospravi, kar je pregriznil, in mu daj igračo« (ne »najprej počisti«), in ko bi bil obrok tudi po čiščenju mogoč šele zvečer, obvestilo pove uro (»Naslednji obrok je ob 17:00.«), namesto da obljubi »Potem ga lahko nahraniš«. Če hranjenje tisti dan ni več mogoče, otrok dobi opomnik za nered — nered ne ostane neopažen do bolezni (popravek po neodvisnem pregledu QA). Muca obdrži »muca« za vse starosti in angleški *it*.

**Zakaj je pomembno:** Starši dobijo obvestila v istem spoštljivem tonu kot v aplikaciji; otrok ne dobi navodila, ki ga gumb nato zavrne.

**Številke:** 14 pasjih besedil spremenjenih (alarm staršem v slovenščini), 10 novih pasjih besedil na jezik; vse ostale pasje besedila nespremenjena (preverjeno s posnetkom).

**Kako povedati:**
- 👩 Starši: »Obvestila vas nagovarjajo z »vi« in otroku vedno povedo, kaj lahko naredi zdaj — ali ob kateri uri.«
- 🛠 Tehnično: »Pred obvestilom strežnik preveri isto pravilo kot gumb, tudi za stanje po čiščenju; posnetek vseh pasjih besedil ujame vsako nenamerno spremembo.«

## 2026-10-09 — Muca dobi svoj glas v obvestilih (M5-R06-06, skrito)

**Kaj se je zgodilo:** Vsako obvestilo, ki ga strežnik lahko pošlje za muco, ima zdaj svoje besedilo v angleščini in slovenščini — od »Tvoja muca te milo gleda in sedi ob posodi s hrano« do »Muca je odšla v zavetišče …« za starše. Slovenščina ni zamenjava ene besede: »kuža je lačen« postane »muca je lačna«, »bo zbolel« postane »bo zbolela«, »ga / mu« postane »jo / ji«. Popravljena je tudi napaka iz prejšnjega koraka: ko je muca opraskala kavč in je lačna, obvestilo ne reče več »najprej počisti nered« (čiščenje praskanja ne razreši), ampak »najprej jo odnesi na praskalnik in jo pohvali«. Besedila so osnutek, ki ga David prebere pred vklopom mačk (ena tabela za pregled v enem prehodu).

**Zakaj je pomembno:** Obvestilo je pogosto prvi stik otroka z ljubljenčkom v dnevu. Če reče »kuža«, ko ima otrok muco, ali zahteva nekaj, kar aplikacija zavrne, otrok izgubi zaupanje. Pasja obvestila so ostala natanko enaka — preverjeno s posnetkom vseh 450 pasjih besedil, narejenim pred spremembo.

**Številke:** 39 mačjih besedil obvestil na jezik (78 skupaj) + 1 besedilo izvoza podatkov; 450 pasjih besedil preverjenih bajt za bajt; 7 kombinacij odprtih neredov (praskanje / nered zraven peska / drug nered) preverjenih za pravilo »obvestilo nikoli ne zahteva nemogočega«; testi strežnika **1562 → 1629** (po neodvisnem pregledu QA tudi: obvestilo ne obljubi »potem jo lahko nahraniš«, če je naslednji obrok šele ob 17:00 — pove čas).

**Kako povedati:**
- 👩 Starši (*načrt*): »Obvestila za muco so napisana posebej za muco — v pravem spolu, brez imen otrok ali živali in nikoli z zahtevo, ki je otrok ne more izpolniti.«
- 🧒 Otroci (*načrt*): »Ko ti telefon reče, da je muca lačna, ti tudi pove, kaj narediti najprej.«
- 💼 Investitorji: »Nova vrsta živali dobi lastna besedila, ne da bi se spremenila ena sama beseda pri psu — prevodi so ločeni po vrsti in jih test preverja.«
- 🛠 Tehnično: »Besedila po vrsti v podimenskem prostoru `cat` z izrecnim seznamom ključev samo za psa; test pokritosti ujame vsako pasjo besedo (in slovenski moški spol) v mačjem besedilu; posnetek pasjih besedil pred spremembo.«

## 2026-10-08 — Pesek, praskalnik in česanje: muca dobi vsakdanja opravila (M5-R06-05, skrito)

**Kaj se je zgodilo:** Strežnik zna tri mačja opravila. **Pesek:** muca vsak dan uporabi pesek (mucek 3-krat, odrasla muca 2-krat, nikoli ponoči ali med šolo) — to ni nered, je pa naloga: pesek je treba počistiti v **4 urah** (šteje se samo čas izven tihih ur). Če ga otrok ne počisti, muca naredi nered zraven peska in od tam naprej velja ista lestvica kot pri psu. Enkrat na teden je treba zamenjati ves pesek; če otrok to zamudi, ima naslednji teden za čiščenje samo **2 uri**, dokler peska ne zamenja (»pesek smrdi«). **Praskalnik:** če otrok cel dan ni igral z muco, muca naslednji dan **opraska kavč**. Otrok jo odnese na praskalnik in jo pohvali v **3 sekundah** po tem, ko pristane — prepozna pohvala ni kazen, otrok preprosto poskusi znova. **Česanje:** Maine Coon ima dolgo dlako in ga je treba počesati 3-krat na teden (največ enkrat na dan). Če ob koncu tedna manjkata vsaj dve česanji, dobi **vozel v dlaki** — brez bolezni in brez kazni, le naslednje česanje traja dlje.

**Zakaj je pomembno:** To so prava opravila lastnika mačke, ki jih navajajo viri (pesek čistiti vsak dan, menjati enkrat na teden; praskanje je naravno in se ga nikoli ne kaznuje, mačko se preusmeri in nagradi takoj; dolgodlake mačke potrebujejo redno česanje). Posledice so realne, a prijazne: nobena zamujena naloga ne pomeni takojšnje bolezni, razen nereda, ki ga otrok pusti dolgo — natanko kot pri psu. David je 8. 10. ob ~22:20 odločil zadnja tri odprta vprašanja (rok za praskanje, tedenski vozel, brez kazni pri vozlu).

**Številke:** 3 nove vrste dogodkov (uporaba peska, nered zraven peska, praskanje), 3 nove rutine v Care Score (čiščenje peska, tedenska menjava, česanje), 7 novih končnih točk, 1 nov opomnik (pesek je treba počistiti v naslednji uri); testi strežnika **1533 → 1562** (29 novih za mačko, vključno s popravki po neodvisnem pregledu), regresijski posnetek psa ostal nespremenjen. Mačke so še skrite.

**Kako povedati:**
- 👩 Starši (*načrt*): »Muca v PetPrepu nauči, da je pesek vsakodnevna naloga, ne enkrat na teden — in da se praskanja ne kaznuje, ampak preusmeri.«
- 🧒 Otroci (*načrt*): »Ko muca opraska kavč, je ne kregaj: odnesi jo na praskalnik in jo hitro pohvali!«
- 🤝 Partnerji (zavetišča, veterinarji): »Pravila za pesek, praskanje in česanje so iz smernic (AAFP, ASPCA, rejska združenja), z zapisanim virom za vsako številko.«
- 💼 Investitorji: »Druga vrsta živali dobi tri nove rutine na istem motorju pravil, brez ene same spremembe pri psih.«
- 🛠 Tehnično: »Uporaba peska je dogodek, ki ni nered, z rokom, ki se določi ob uporabi; tedenske rutine na koledarju tedna programa (tudi čez premik ure); preusmeritev praskanja kot strežniško ocenjena seja s 3-s oknom.«

---

## 2026-10-08 — Muca se igra namesto sprehoda (M5-R06-04, skrito)

**Kaj se je zgodilo:** Strežnik zna mačjo dnevno igro: mini-igro **»palica s peresom«**. Igra traja približno minuto in se konča, ko muca ujame pero. Strežnik igro začne in jo na koncu oceni — šteje samo, če je otrok res sodeloval: pero je moralo večkrat »bežati stran« od muce, kot pravi plen, in to čez vso minuto. Mucek potrebuje 3 igre na dan, odrasla muca 2, med dvema uspešnima igrama pa morata miniti vsaj 2 uri (David, 8. 10. zvečer). Merilnik »Igra« se ob polnoči izprazni, enkrat na dan pride opomnik, zamujen dan pa se zapiše (iz tega bo naslednji korak naredil »opraskan kavč«). Muca nima korakov s telefona in zaradi neigranja nikoli ne zboli.

**Zakaj je pomembno:** Pes otroka spravi na sprehod, muca pa ne hodi na povodcu — zato je njena rutina igra, kot svetujejo viri (2–3 igre na dan, mladiči več). Prekinjena igra nima kazni: otrok lahko takoj poskusi znova. Za pse se ni spremenilo nič — to preverja nov test, ki 2,5 dneva življenja dveh psov (tudi čez premik ure 25. 10.) primerja s posnetkom, narejenim pred spremembo.

**Številke:** 1 nova tabela za mačje mini-igre (pripravljena tudi za česanje in menjavo peska), 2 novi končni točki, 24 novih testov za mačko + 1 regresijski test psa; testi strežnika 1501 → 1526. Mačke ostajajo skrite.

**Kako povedati:**
- 👩 Starši (*načrt*): »Muca v PetPrepu potrebuje igro — v aplikaciji ena minuta s palico s peresom, v resnici 10–15 minut, 2–3-krat na dan.«
- 🧒 Otroci (*načrt*): »Pero naj beži stran od muce, kot miška — takrat ga muca najraje lovi!«
- 💼 Investitorji: »Druga vrsta živali dobiva svoje rutine na istem motorju pravil; za pse je sprememba dokazano nevidna.«
- 🛠 Tehnično: »Strežniško vodena seja (`pet_care_sessions`), ocena premikov na strežniku, merilnik prek obstoječega stolpca energije, rutina s poštenim deležem ⌈cilj / n⌉, regresijski posnetek psa pred spremembo.«

---

## 2026-10-08 — Muca dobi življenjske faze z viri (M5-R06-03, skrito)

**Kaj se je zgodilo:** Strežnik ima zdaj podatke o mački po fazah življenja — **mucek** (do 12 mesecev), **mlada mačka** (od 1 leta), **zrela mačka** (od 7 let) in **starejša mačka** (od 10 let), po smernicah AAHA/AAFP 2021. Domača muca pride k družini stara 2 meseca, Maine Coon 3 mesece (rodovniški mucki gredo od doma pozneje). Mucek je 4-krat na dan, nato 3-krat, od 6. meseca 2-krat; odrasla muca 2-krat. Zapisano je tudi, koliko iger s palico (mucek 3, odrasla 2), koliko uporab peska (3 / 2), kolikokrat na teden Maine Coona počešemo (3) in da se pesek v celoti zamenja enkrat na teden.

**Zakaj je pomembno:** Vsaka mačja številka ima vir (25 virov, od veterinarskih smernic do Cornella in Cats Protection) ali zapisano Davidovo odločitev. Edina številka brez vira (2 uri med igrama) je v administraciji jasno označena kot predlog. Pri psih se ni spremenilo nič — test to preveri s »prstnim odtisom« vseh pasjih podatkov. Pravila igre za muco (igra, pesek, praskanje) pridejo v naslednjih korakih; mačke so še skrite.

**Številke:** 65 mačjih vrednosti (32 domača mačka, 33 Maine Coon), 7 novih vrst podatkov, 1 vrednost (D); testi strežnika 1488 → 1499.

**Kako povedati:**
- 👩 Starši (*načrt*): »Muca v PetPrepu raste kot prava: mucek dobi hrano pogosteje, kasneje dvakrat na dan — po veterinarskih smernicah.«
- 💼 Investitorji: »Druga vrsta živali stoji na istem podatkovnem modelu z viri kot pes — dodajanje vrste je delo s podatki, ne prepis igre.«
- 🛠 Tehnično: »Ločen nabor vrstic za mačke v `breed_stage_params`, vsaka vrstica preverjena proti `cat-data/data.json` in `sources.md`; pasji nabor zaklenjen s SHA-256 odtisom.«

---

## 2026-10-08 — Izbirnik »vrsta → pasma« v aplikaciji (M5-R06-02, mačke še skrite)

**Kaj se je zgodilo:** Zaslon, kjer starš izbere ljubljenčka, zdaj seznam pasem dobi s strežnika. Najprej izbere **vrsto** (velike ploščice »Pes« / »Mačka«), nato načrt, pasmo s seznama z **iskanjem** (»mesancek« najde Mešančka, »mejnkun« Maine Coona), izvor in starost; pred kodo vidi **povzetek**. Ker so mačke še skrite, starš psa vidi isti izbirnik kot prej — korak vrste se preskoči, novo so iskalno polje, oznaki *Brezplačno* / *Izziv* in povzetek.

**Zakaj je pomembno:** Nova pasma je odslej vrstica v bazi, ne nova aplikacija. Aplikacija nikoli več ne pokaže neznane živali kot »mešančka« — brezplačna muca tako nikoli ne dobi ponudbe za nakup. Če seznama ni mogoče naložiti, izbirnik pokaže današnja psa, zato ustvarjanje psa nikoli ne odpove.

**Številke:** 4 pasme v katalogu (2 psa, 2 mački); 46 novih testov aplikacije (skupaj 1596); 3 podvojeni prevodi imen pasem združeni v enega.

**Kako povedati:**
- 👩 Starši: »Pasmo zdaj najdete z iskanjem, pred kodo pa vidite povzetek izbire.« Mačka: *načrt*.
- 💼 Investitorji: »Izbirnik je podatkovno voden — nova pasma ali vrsta ne potrebuje nove različice aplikacije.«
- 🛠 Tehnično: »Katalog prek TanStack Query z rezervnim seznamom; mačke se odklenejo samo, ko sta vklopljena strežniško stikalo in zastavica v aplikaciji (`species_cat`).«

---

## 2026-10-08 — Temelj za muco: strežnik pozna vrsto živali (M5-R06-01, skrito)

**Kaj se je zgodilo:** Strežnik zdaj pri vsakem ljubljenčku ve, ali je **pes ali mačka**. Dobil je dve mačji pasmi — **domačo mačko** (brezplačno, kot mešanček) in **Maine Coona** (samo z 12-tedenskim izzivom) — ter katalog pasem, iz katerega bo aplikacija gradila izbirnik »vrsta → pasma« (`GET /api/breeds`). Pravilo »kaj je brezplačno in kaj plačljivo« je bilo prej zapisano v kodi na ~10 mestih (»mešanček je brezplačen«); zdaj ima **en sam vir**: nastavitev pasme v bazi, ki jo admin vidi in ureja.

**Zakaj je pomembno:** Nova pasma ali nova vrsta je odslej vrstica v bazi, ne nova različica aplikacije. Mačke so **skrite** (stikalo na strežniku + nova aplikacija mora povedati, da mačko zna prikazati), zato lahko vsak naslednji korak gre na produkcijo, ne da bi testerji kaj opazili. Pri psih se ni spremenilo nič: vsi obstoječi testi so ostali zeleni brez spremembe pričakovanih pasjih vrednosti.

**Številke:** 4 pasme (2 psa, 2 mački); mačja voda 2× na dan z ≥ 4 h razmika, lakota in žeja −8 %/h (Davidova odločitev 8. 10.); 47 novih testov strežnika (tudi matrika pes / mačka × brezplačno / izziv × stara / nova aplikacija in zaščita, da ima vsaka vrsta natanko eno brezplačno pasmo), skupaj 1488 zelenih.

**Kako povedati:**
- 👩 Starši: *načrt* — »Kmalu boste lahko izbrali tudi muco. Domača muca bo brezplačna, Maine Coon bo del 12-tedenskega izziva.« (Še ni na voljo.)
- 💼 Investitorji: »Katalog živali je podatkovno voden: nova pasma ali vrsta ne potrebuje nove različice aplikacije; brezplačni in plačljivi del se določata na enem mestu.«
- 🛠 Tehnično: »`pets.species` s CHECK, da se pasma ujema z vrsto; `breed_configs.premium_unlock` kot edini vir free/paid (predpomnjen katalog); funkcije se skrijejo z zastavico na strežniku IN deklaracijo funkcije v aplikaciji (`species_cat`), da stara aplikacija nikoli ne dobi mačke.«

---

## 2026-10-08 — Pred beto s čisto mizo: varna ponastavitev produkcije (M5-09)

**Kaj se je zgodilo:** David se je odločil (8. 10. ob 13:50), da beta z ~20 testerji začne s **prazno igro**: do zdaj je na produkciji testiral samo on. Namesto ročnega brisanja po bazi je nastalo orodje, ki to naredi varno: najprej **suhi tek** (samo pokaže, koliko vrstic v kateri tabeli bi izbrisal in koliko slik / videov psov — brez e-pošt in brez podatkov otrok), nato pa ob potrditvi z vpisom imena strežnika: vzdrževalni način → ustavljeni delavci → **sveža kopija baze in vseh medijev** → izbris v eni transakciji → nazaj na splet. **Ostanejo** samo skrbniški računi in podatki o pasmah (z zgodovino urejanj).

**Zakaj je pomembno:** Testerji začnejo vsi enako, brez starih »legacy« psov iz časa pred izbiro kužka — zato tudi odprto vprašanje o igri za legacy pse ni več potrebno. Orodje noče teči, če v bazi obstaja tabela, za katero nihče ni odločil, ali se ohrani ali izbriše, zato nobena prihodnja tabela ne more biti pozabljena.

**Številke:** 40 tabel v bazi, od tega 4 ohranjene, 2 delno (skrbniki in njihovi žetoni), 34 v celoti izbrisanih; 12 novih testov strežnika (tudi: neuspel izbris na pol poti vse povrne) in 65 preverjanj skripte; ponastavitev in deploy si delita ključavnico, da ne tečeta hkrati; 0 sprememb na produkciji, dokler je David sam ne zažene.

**Kako povedati:**
- 👩 Starši (testerji): »Za beto smo začeli znova — prosimo, aplikacijo na novo namestite in se ponovno registrirajte. Vaši stari testni podatki so izbrisani.«
- 💼 Investitorji: »Beta začne s čistimi podatki; brisanje je ponovljivo, z obvezno kopijo in revizijsko sledjo.«
- 🛠 Tehnično: »Ponastavitev = Laravel ukaz s suhim tekom + gostiteljska skripta (maintenance → stop workers → pg_dump + tar medijev → DELETE v vrstnem redu FK v eni transakciji). Test našteje vse tabele sheme in pade, če nova tabela ni razvrščena.«

---

## 2026-10-08 — Pravila za muco potrjena (M5-R06, *načrt*)

**Kaj se je zgodilo:** David je potrdil vseh 10 odločitev o skrbi za mačko (`CAT_SPEC.md`). Namesto sprehoda se otrok z muco igra s **palico s peresom** (odrasla 2×, mucek 3× na dan), namesto kakca **počisti pesek** v 4 urah, muca je **notranja**, za praskanje kavča jo otrok **odnese na praskalnik in pohvali** — nikoli kazen. Domača mačka bo brezplačna, **Maine Coon** pa plačljiva pasma za izziv (ista cena 49,99 €). Koraki s telefona ostanejo samo pri psu.

**Zakaj je pomembno:** Druga vrsta živali odpre PetPrep družinam, ki razmišljajo o mački. Pravila temeljijo na virih (AAHA/AAFP, FIFe, VetCompass), otrok pa nikoli ne kaznuje živali.

**Številke:** 10 odločitev, 2 pasmi (1 brezplačna, 1 plačljiva), 0 vrstic kode — vse je še *načrt*.

**Kako povedati:**
- 👩 Starši: »Kmalu (*načrt*) tudi muca: igra, pesek, sveža voda — in nikoli kazen.«
- 💼 Investitorji: »Druga vrsta na istem pogonu pravil in istem izdelku za 49,99 €.«
- 🧒 Otroci: še nič — ko bo muca res v aplikaciji.

---

## 2026-10-08 — Igra in crkljanje v aplikaciji (M5-R05)

**Kaj se je zgodilo:** Otrok ima nad gumbi nov gumb **»Igra«** (ob »Šola«). Izbere **žogo** — povleče jo s prstom navzgor in spusti, kuža steče ponjo in jo prinese (3 meti) — ali **crkljanje** — 5-krat pogladi kužka s prstom, ob vsakem potegu se pokaže srček. Ko kuža sam povabi (»Kuža ti prinaša žogo. Se igrava?«), se namesto gumba pokaže prijazna kartica brez odštevanja in z »Mogoče kasneje«. Po igri aplikacija takoj pokaže veselega kužka: pol ure napis »Kuža je vesel« in video igranja — pes brez tega videa dobi mehke srčke, narisane v aplikaciji. Starš vidi na kartici psa »Danes: 3× igra z žogo, 2× crkljanje« in v časovnici »Igra z žogo ×3 · {vzdevek}« s srčkom. Ko igra ni mogoča, je gumb siv in pove zakaj (»Kuža spi. Igrata se, ko se zbudi.«).

**Dostopnost:** vsak potez ima gumb (»Vrzi žogo«, »Drži in pobožaj« 3 s), bralnik zaslona crklja z enim dejanjem, pri vklopljenem »zmanjšaj gibanje« žoga in srčki ne letijo po zaslonu. Brez novih nativnih modulov (potezi z vgrajenim `PanResponder`), zato ni potreben nov build zaradi knjižnic.

**Zakaj je pomembno:** Ko je za kužka vse narejeno, ima otrok še vedno kaj početi — in to je nagrada, ne naloga: brez točk, brez kazni, brez opomnikov.

**Številke:** 73 testov aplikacije v 5 novih sklopih za igro (vseh 1.546 testov aplikacije zelenih; TypeScript brez napak); ni preizkušeno na telefonu; igra traja manj kot pol minute; 0 novih AI stroškov.

**Kako povedati:**
- 🧒 Otroci: »Vrzi kužku žogo ali ga pobožaj s prstom — potem je pol ure ves vesel.«
- 👩 Starši: »Igra je čas s kužkom, ne točke. Na kartici vidite, kolikokrat se je otrok danes igral in crkljal.«
- 📣 Omrežja: »Ko je vse narejeno, kuža prinese žogo. 🎾«
- 🛠 Tehnično: »Mini-igri sta ločena plast nad HUD-om (pozneje jo lahko zamenja interaktivni 3D pes brez spremembe API-ja); optimistično stanje, nato vedno resnica strežnika; potezi s `PanResponder`, brez gesture-handlerja.«

---

## 2026-10-08 — Izziv brez »7 dni brezplačno«: brezplačen je mešanček, izziv se začne z nakupom (M3-13)

**Kaj se je zgodilo:** David je ob 10:28 odločil, da 12-tedenski izziv nima več 7-dnevnega brezplačnega preizkusa. Brezplačni preizkus PetPrepa je mešanček (brezplačen za vedno); izziv (49,99 € na psa, enkratno) se začne z nakupom. Pes na izzivu, ki ga starš še ni kupil, po podpisu pogodbe varno počaka (nič ne upada, nič se ne izgubi), 12 tednov pa začne teči šele ob nakupu. Starš lahko kupi že prej — takoj po kodi za otroka. Psi testerjev, ki so preizkus že začeli, ga obdržijo do konca. V aplikaciji (slovensko in angleško) besede »preizkus« ni več nikjer; gumb »12-tedenski izziv — kupi« je viden tudi za kužka, ki se še ni rodil. Produkcija že teče z vklopljenimi plačili (beta, ~20 testerjev plačuje v sandboxu trgovine).

**Zakaj je pomembno:** Dva brezplačna vstopa (mešanček + 7 dni izziva) sta ponudbo zameglila. Zdaj je jasno: hočeš poskusiti — mešanček; hočeš program s certifikatom — kupiš izziv in teden 1 se začne. Ura programa se ne porabi, dokler pes čaka na nakup.

**Številke:** 14 novih testov strežnika (zaklep ob rojstvu, nakup pred rojstvom, samodejna dodelitev že kupljenega izziva, 12 tednov od nakupa, stari preizkusi do konca, vračilo pred rojstvom ne zaklene, brez zaklepa in z igro ob izklopljenih plačilih …); vseh 1429 testov strežnika zelenih (skupaj z igro M5-R05); vseh 1468 testov aplikacije zelenih (nova preverjanja, da nobeno besedilo ne omenja preizkusa). Ni preizkušeno na telefonu.

**Kako povedati:**
- 👩 Starši: »PetPrep lahko preizkusite brezplačno z mešančkom — za vedno. Ko ste pripravljeni na pravi 12-tedenski izziv (49,99 € enkratno, brez naročnine), ga kupite in teden 1 se začne. Kupite ga lahko še preden otrok podpiše pogodbo.«
- 🧒 Otroci: »Če tvoj kuža čaka na starše, je na varnem in počiva. Ko starši kupijo izziv, se tvojih 12 tednov začne.«
- 💼 Investitorji: »Ena brezplačna pot (mešanček za vedno) in ena plačljiva (izziv 49,99 € na psa). Plačilo pred začetkom programa; čas čakanja ne porabi programa.«
- 🛠 Tehnično: »Zaklep `payment_required` ob rojstvu v isti transakciji kot podpis pogodbe; obdobje zaklepa se odšteje od ure programa, zato 12 tednov teče od nakupa. Brez destruktivne migracije: `trial_ends_at` ostane (= rojstvo), `trial_available` je zastarel, API je združljiv nazaj.«
- ⚠️ Za popravek zunaj repozitorija: spletna stran in opisi v trgovinah še obljubljajo »7 dni brezplačno« (seznam v `HANDOFF.md`).

---

## 2026-10-08 — Igra in crkljanje: strežnik pripravljen (M5-R05)

**Kaj se je zgodilo:** Strežnik zna igro z žogo in crkljanje. Otrok lahko igro konča kadarkoli (ni tihih ur, ni nereda, pes ni zaklenjen); kuža je nato 30 minut »vesel«. Vsak dan kuža sam pripravi dve vabili (eno za žogo, eno za crkljanje) ob naključni uri med 7:00 in 20:00 izven tihih ur, vsaj 3 ure narazen; vabilo se pokaže le, ko je današnji sprehod opravljen ter hrana in voda nad 30 %. Starš vidi vrstico v časovnici in koliko iger je bilo danes. Mini-igri v aplikaciji še ni (*načrt*, naslednji korak).

**Odločitev:** pravila je določil David 7. in 8. 10. (samo izziv — plačan, ali tekoči stari preizkus / ko je stikalo plačil izklopljeno, saj preizkusa od M3-13 ni več; prosto kadarkoli; vabilo po sprehodu; 30 min veselja; starš brez pusha; skupni pes). Številke, ki jih David še ni potrdil (2 vabili, 2 uri, 7–20, brez igre v tihih urah, legacy psi brez igre), so *predlog* in na enem mestu (`config/play.php`).

**Zakaj je pomembno:** Ko je vse narejeno, mora biti s kužkom še vedno kaj za početi — in to brez kazni. Igra je nagrada: če je otrok prezre, se ne zgodi nič.

**Številke:** 42 novih testov strežnika; test, ki 3 dni primerja kužka, ki se igra 20× na dan, s kužkom, ki se ne igra nikoli: rutine, Care Score, semafor in merilniki so enaki. Brez novih AI stroškov (obstoječi video `playing`).

**Kako povedati:**
- 👩 Starši: »Igra s kužkom ne prinaša točk in ne kaznuje. Vidite pa, kolikokrat se je otrok danes igral in crkljal.«
- 🧒 Otroci: »Ko si za kužka vse naredil in sta bila na sprehodu, te včasih povabi k igri. Igraš se lahko tudi sam, kadar hočeš.«
- 💼 Investitorji: »Pozitivna zanka brez stroškov AI: več časa v aplikaciji, ne da bi igra postala seznam opravil.«
- 🛠 Tehnično: »Ločena tabela `pet_play_events` (ne higiena, ki je rutina), vabila deterministično iz semena na psa in dan, eksplicitni seznami tipov v ocenjevanju — test dokaže, da igra ne vpliva na točke.«

## 2026-10-08 — Tihe ure ima vsaka družina; ponoči ni več obvestil (M5-F08)

**Kaj se je zgodilo:** David je ponoči na iPhone dobil dve obvestili: ob 00:00 »Tvoj kuža danes še ni bil na sprehodu« in okoli 3:30 »kuža je zbolel, 12 ur pri veterinarju«, čeprav je imel v aplikaciji »nastavljene« tihe ure. Vzrok: strežnik zanj tihih ur sploh ni imel shranjenih. Aplikacija je ob praznem odgovoru tiho pokazala predlagane čase (21:00–7:00, vklopljeno) in izgledalo je, kot da so nastavljene. Brez tihih ur je ura bolezni tekla vso noč, ob polnoči pa se energija (dnevni sprehod) ponastavi na 0 % in opomnik je šel takoj. Popravek: vsaka družina ima zdaj tihe ure od trenutka, ko nastane (spanje 21:00–7:00, brez šole, vklopljeno); obstoječe družine brez nastavitve so jih dobile samodejno, nastavitve staršev (tudi izklopljene) ostanejo nedotaknjene. Opomnik za sprehod nikoli ne gre takoj po polnoči — najprej 2 uri po koncu nočnega spanja (privzeto ob 9:00), tudi če starš tihe ure izklopi. Kartica »Tihe ure« zdaj kaže resnico: strežnik vedno pove, kateri časi veljajo, in dokler jih starš ne shrani, kartica piše »Veljajo privzeti časi — tapnite Shrani, da jih potrdite ali spremenite«.

**Odločitev:** privzete tihe ure (21:00–7:00, brez šole, vklopljeno) in pravilo za jutranji opomnik je potrdil David 2026-10-08 08:54.

**Zakaj je pomembno:** Otrok in starš ne smeta dobivati obvestil sredi noči — to je obljuba izdelka. In aplikacija ne sme kazati nečesa, česar strežnik ne ve.

**Številke:** napaka ponovljena s testom (na starem kodu test pade: opomnik ob 00:00, bolezen ob 01:00); 21 novih testov strežnika (privzete tihe ure ob registraciji, strežnik pokaže veljavne čase tudi brez shranjene nastavitve, migracija samo dodaja, izklop velja, opomnik po polnoči počaka do 9:00 / 8:00); strežnik 1.370 zelenih testov; aplikacija 4 novi testi, 1.443 zelenih.

**Kako povedati:**
- 👩 Starši: »Tihe ure so vklopljene že od začetka (spanje 21:00–7:00). Ponoči vas in otroka nič ne zbudi — tudi opomnik za sprehod počaka do jutra.«
- 🧒 Otroci: »Ponoči tvoj kuža spi in ti ne piše. Zjutraj ti pove, če gresta na sprehod.«
- 🛠 Tehnično: »Manjkajoča privzeta vrednost je bila prava napaka, ne logika obvestil: zdaj ima vsaka družina vrstico `quiet_hours` (ob nastanku + podatkovna migracija samo za manjkajoče), `Pet::quietHours()` nikoli ne vrne null, aplikacija pa prazen odgovor pokaže kot ›ni shranjeno‹.«

---

## 2026-10-08 — Šola pove po pravici, kako je šlo (M5-F04)

**Kaj se je zgodilo:** David je v šoli za kužka ob pravem času pohvalil samo enkrat (ostalo prezgodaj ali ko kuža sploh ni ubogal), aplikacija pa je napisala »Great job!«. Zjutraj 8. 10. je potrdil pravilo: šteje samo ukaz, pri katerem je kuža ubogal. Vsi pohvaljeni ob pravem času in nobene pohvale, ko ni ubogal → »Odlično!«; več kot polovica → »Dobro!«; vsaj ena (tudi natanko polovica) → »Še malo vaje — jutri bo bolje«; nobena → »Tokrat ni šlo, poskusi jutri«. Davidov primer (1 od 3 uboganih) zdaj pokaže »Še malo vaje — jutri bo bolje«. Naslov in vrstica »Pravočasne pohvale: X od Y« se računata iz istih podatkov. Testi za vsako mejo. **Na telefonu še ni preizkušeno.**

**Zakaj je pomembno:** Lažna pohvala otroka nauči, da se trud ne pozna. Počakati, ko pes ne uboga, pa je prav — zato tega ne kaznujemo. Tako kot pri pravem psu: nagrada ob pravem trenutku, ne vedno.

**Kako povedati:**
- 👩 Starši: »Aplikacija otroka ne hvali na prazno — pove po pravici in vedno prijazno, kako je šlo.«
- 🧒 Otroci: »Pohvali kužka takrat, ko uboga, in počakaj, ko ne — pa boš videl(a) ›Odlično!‹«
- 📣 Omrežja: »Pri nas ni ›Bravo!‹ za vse. Je pa vedno ›jutri bo bolje‹.«

---

## 2026-10-07 — Mešanček je brezplačen pes, izziv je s plačljivo pasmo (M5-F02, M5-F03)

**Kaj se je zgodilo:** David je na TestFlightu videl dve neskladji. (1) Pri starem mešančku je značka kazala »Plačano«, čeprav ga ni nihče kupil — ob uvedbi plačil so bili vsi obstoječi psi označeni kot odklenjen izziv (da se nikomur nič ne zaklene). (2) V »Izberi kužka« je bil mešanček izbirljiv tudi pri 12-tedenskem izzivu (»Brezplačen v vseh kombinacijah«). Zdaj: mešanček se staršu vedno pokaže kot **»Brezplačno«**; pri izbiri izziva je mešanček **siv** z razlago (»izziv je s plačljivo pasmo, mešanček je pes brezplačnega načrta«), preklop na izziv sam izbere Border Collieja, strežnik pa takšno kombinacijo zavrne.

**Zakaj je pomembno:** Starš mora iz značke takoj razbrati, kaj je plačal in kaj ne. In paketa se morata jasno ločiti: brezplačni mešanček za spoznavanje, plačljivi izziv s pravo zahtevnejšo pasmo. Plačanim psom testerjev se pri tem ni spremenilo nič — 12-tedenski program, zgodovina in ura tečejo naprej, popravljen je samo prikaz. Mešančki, ki so bili po pomoti na neplačanem izzivu, so dobili brezplačni načrt: nikoli več ne bodo čakali na nakup, zaklenjen kuža spet igra, zaklenjeni čas pa se mu ne šteje v starost. Novega neplačanega mešančka na izzivu ne more ustvariti nobena pot več (tudi admin ali testni podatki ne), vračilo kupnine za mešančka pa ga naredi brezplačnega namesto zaklenjenega. Starša s TestFlight 3.0.0, ki izbere izziv in pusti mešančka, do naslednje različice vidi splošno napako — sprejeto, ker smo še pred objavo.

**Številke:** strežnik 26 novih testov (izziv + mešanček → zavrnjeno tudi brez izbrane pasme; Border Collie izziv in brezplačni mešanček delujeta; stare aplikacije brez izbire plana dobijo brezplačnega mešančka; star mešanček »Brezplačno«, a program ostane; kupljen izziv ostane »Plačano«; migracija: mešanček na preizkusu in zaklenjen mešanček → brezplačno, zaklenjen spet igra, zaklenjeni dnevi ostanejo izven starosti; od tega v zadnjem krogu 6: admin / seeder / `/child/pair` ne ustvarijo zaklenljivega mešančka, vračilo → brezplačno, en dogodek na psa, omejitev v bazi), skupaj 1.349 zelenih; aplikacija 7 novih testov, skupaj 1.439 zelenih (z vsem z `main`).

**Kako povedati:**
- 👩 Starši: »Mešanček je vedno brezplačen. 12-tedenski izziv je s plačljivo pasmo (Border Collie) — tako je jasno, za kaj plačate.«
- 💼 Investitorji: »Jasna ločnica med brezplačnim in plačljivim paketom: brezplačni mešanček je vstop, izziv s premium pasmo je produkt.«
- 🛠 Tehnično: »Plačani mešančki: samo prikaz (`plan.display_type`), `type` / `status` ostaneta resnica za pravila igre. Neplačani: migracija na `free` z istimi kavlji kot plačilo (zaklep zaprt, odmrznitev) in `converted_to_free_at`, da zaklenjeni čas ostane izven ure programa. `generate-pin` → 422 `challenge_requires_paid_breed` samo ob izrecni izbiri plana, da stare aplikacije ne dobijo napake.«

---

## 2026-10-07 — Popravki po TestFlightu 3.0.0: opazen nakup, cela glava, viden »Kuža se pripravlja«

**Kaj se je zgodilo:** Trije popravki z Davidovega preizkusa na iPhonu (M5-F01, F05, F06). (1) Starš majhne značke »Preizkus« ni opazil — zdaj ima kartica otroka in podrobnosti otroka jasen gumb **»12-tedenski izziv — kupi«**, v »Nadzoru« pa je vrstica **»Nakupi / izziv«**. Gumb se pokaže samo, dokler izziv psa ni plačan, nikoli za brezplačnega mešančka. (2) Glava otroškega zaslona je bila odrezana (»… Grows into a young dog on 2…«) — zdaj je vsak podatek v eni vrstici, tap na glavo pa odpre list z vsem: pasma, obdobje, starost, izvor, naslednje obdobje, obroki na dan. (3) Obvestilo »Kuža se pripravlja …« je bilo skrito pod karto obrokov in za gumbi — zdaj je nad njimi. Testi aplikacije: vsi zeleni (glej PR). **Na telefonu še ni preizkušeno.**

**Zakaj je pomembno:** Nakup, ki ga starš ne najde, je izgubljen prihodek; podatek, ki ga otrok ne more prebrati, ne uči ničesar.

**Kako povedati:**
- 👩 Starši: »Izziv kupite z enim tapom na kartici otroka — ali v Nadzoru pod ›Nakupi / izziv‹.«
- 🧒 Otroci: »Tapni zgoraj na svojega kužka in izveš vse o njem!«
- 🛠 Tehnično: »En pogoj za gumb nakupa (plan psa s strežnika) za vse tri vhode; obvestilo je v istem stolpcu nad dokom kot obroki, zato ga postavitev po izmerjeni višini doka nikoli ne prekrije.«

---

## 2026-10-07 — »Čaka na podpis« samo, ko otrok res mora podpisati (M5-F07)

**Kaj se je zgodilo:** David je na Pregledu pri testnem otroku hkrati videl »Nujno«, »brez hrane / vode več kot uro«, »zbolel je« **in** »pes čaka, da otrok podpiše pogodbo«. Pes pred podpisom ne upada, zato se to ne bi smelo zgoditi skupaj. Vzrok: napaka prikaza, ne igre. Star testni pes je iz časa pred pogodbami — otrok zanj nikoli ne podpiše in pes normalno živi. Nadzorna plošča pa je »podpisano« štela samo, če obstaja zapis pogodbe. Zdaj strežnik pove »podpisano«, ko otroku ni (več) treba podpisati: pes je rojen in otrok je podpisal ali je skrbnik iz časa pred pogodbami.

**Zakaj je pomembno:** Starš mora videti isto, kar velja za otroka. Napačno »čaka na podpis« ob alarmih zmede in zmanjša zaupanje v vsa ostala opozorila.

**Številke:** 6 novih testov (stari pes → podpisano, nerojen pes → ne, pridruženi otrok brez podpisa → ne, podpisal → da, prvi podpis rodi psa → da, ujemanje s pravilom zaklepa za vsakega skrbnika); strežnik 1.323 zelenih testov. Aplikacija brez sprememb.

**Kako povedati:**
- 👩 Starši: »Opozorilo ›čaka na podpis pogodbe‹ se pokaže samo, ko otrok res še mora podpisati.«
- 🛠 Tehnično: »`contract_signed` na `/api/parent/dashboard` je zdaj enako pravilo kot zaklep otroka (`isUnborn() || caretakerNeedsContract()`), izračunano iz že naloženih zbirk — brez dodatnih poizvedb.«

---

## 2026-10-07 — Popravki po TestFlightu: ura pod gumbi in ploščice albuma

**Kaj se je zgodilo:** David je na iPhonu videl »tomorrow at 0…« pod gumbom za hrano (ura odrezana) in prazne temne ploščice videov v albumu »Moj kuža«. Zdaj je čas za jutri v dveh vrsticah (»jutri« / »06:00«) in se nikoli ne odreže; ploščice videov kažejo sliko kužka z gumbom ▶ in enotno oznako, med nalaganjem pa mirno ploščico v barvah znamke. Testi aplikacije: 1.340 zelenih (29 novih). **Na telefonu še ni preizkušeno.**

---

## 2026-10-07 — Nujni obrok: obvestilo nikoli ne zahteva nemogočega (M3-12)

**Kaj se je zgodilo:** David je na telefonu opoldne videl kužka z lakoto 0 %. Jutranje okno (6–10) je bilo zamujeno, gumb za hrano je kazal »ob 17:00«, obvestilo pa je reklo »Če ga ne nahraniš v 30 minutah, bo zbolel.« Otrok torej ni mogel narediti tistega, kar je zahtevalo obvestilo. Isti dan sta se dogovorila za dve pravili. (1) **Nujni obrok:** če je otrok zadnji obrok zamudil in lakota kaže 20 % ali manj, kužka lahko nahrani tudi izven okna, gumb se takrat pokaže kot »Nujni obrok«. Kdor hrani pravočasno, ga ne vidi (David je to pravilo potrdil zvečer po neodvisnem pregledu — sicer bi se dalo obiti okna). Obrok ne šteje kot pravočasen: zamujeno okno ostane zamujeno, naslednji obrok ob 17:00 pa ostane normalen. (2) **Obvestilo nikoli ne zahteva nemogočega:** strežnik pred pošiljanjem preveri ista pravila kot gumb. Če hranjenje ni mogoče, obvestilo pove »Naslednji obrok je ob 17:00 — ne pozabi nanj.« ali »najprej počisti nered«. Če danes ni več mogoče nič, obvestila ni.

**Zakaj je pomembno:** Otrok se uči odgovornosti, ne frustracije. Nemogoča zahteva uči samo, da obvestila lažejo. Zdaj ima vsak opomnik dejanje, ki ga otrok res lahko naredi, ocena pa ostane poštena (zamuda se ne izbriše).

**Številke:** prag 20 % (prikazana vrednost; 20,4 → 20 še velja, 20,5 → 21 ne), en vir resnice za gumb, stanje in obvestila. Testi: strežnik 1.304 zelenih (42 novih, med njimi Davidov primer 12:11, otrok, ki je hranil pravočasno, in preizkus vseh 24 ur dneva za 6 vrednosti lakote), aplikacija 1.313 zelenih (19 novih). Novi testi padejo, če pravilo odstranimo. **Na telefonu še ni preizkušeno.**

**Kako povedati:**
- 👩 Starši: »Če otrok zamudi obrok in je kuža zelo lačen, ga lahko nahrani takoj — kdor hrani pravočasno, tega ne potrebuje. Ocena pa pove resnico: zamujen obrok ostane zamujen.«
- 🧒 Otroci: »Je kuža zelo lačen? Tapni ›Nujni obrok‹! Naslednjič pa ga nahrani ob pravem času.«
- 💼 Investitorji: »Pravila igre in obvestila izhajajo iz enega mesta v kodi. Tako sistem otroka nikoli ne prosi za nemogoče, kar pomeni manj frustracije in manj odhodov.«
- 📣 Omrežja: »Pravi pes ne čaka do 17:00. Tudi naš ne. Ampak zamuda je še vedno zamuda.«
- 🛠 Tehnično: »`CareScheduleService::feedCheck/waterCheck` odloča za akcijo (pravilo »zamujen obrok«: zadnje končano okno brez obroka), `ChildPetStateResource` (`feed_mode`, `emergency_threshold` ali null) in čas pošiljanja pusha (`wait` / `clean_first` / `not_actionable`). Ledger je ostal nespremenjen, ker je nujni obrok izven vseh oken.«
## 2026-10-07 — Pravi koraki iz Apple Zdravje in Health Connect (M3-04 – M3-06)

**Kaj se je zgodilo:** Kuža zdaj dobi korake tudi, ko je aplikacija zaprta. Otrok v oknu »Sprehod« prebere kratko razlago (»Kužku gre samo današnje število korakov — nič drugega«), tapne **Poveži** in dovoli samo branje korakov iz **Apple Zdravje** (iPhone) ali **Health Connect** (Android). Ob odprtju aplikacija pošlje ves današnji seštevek — tudi korake z ure. iPhone jih občasno pošlje že v ozadju. Če otrok dostop zavrne ali ga telefon nima, šteje senzor gibanja kot doslej.

**Zakaj je pomembno:** Do zdaj je Android štel samo, ko je bila aplikacija odprta — otrok, ki je šel s psom ven s telefonom v žepu, ni dobil koraka. To je bila največja luknja v »pravem sprehodu«. Obenem ostajamo pri minimumu podatkov: dovoljenje samo za korake, na strežnik gre ena številka.

**Številke:** 1 vrsta podatkov (koraki, samo branje); 2 vira na telefonu, šteje večji (nikoli vsota — en sprehod ne šteje dvakrat); sync največ 1× na minuto samodejno, vsakih 5 min in ob »Osveži«; v ozadju iPhone približno na 15 min ali redkeje (odloča iOS); anti-cheat ostaja 200 korakov na minuto. Testi: 1.355 v aplikaciji (62 novih), 1.264 na strežniku (2 nova). **Še ni na telefonu — potreben je nov native build.**

**Kako povedati:**
- 🧒 Otroci: "Poveži Zdravje in kuža dobi tudi korake, ko je aplikacija zaprta!"
- 👩 Starši: "PetPrep iz aplikacije Zdravje / Health Connect bere samo današnje število korakov — nič drugega in ničesar ne zapisuje. Dovoljenje lahko kadarkoli prekličete."
- 📣 Omrežja: "Pravi sprehod šteje — tudi s telefonom v žepu. 🐾"
- 💼 Investitorji: "Integracija z Apple Health in Health Connect: zanesljivo merjenje gibanja, ki deluje v ozadju, z minimalnim dostopom do zdravstvenih podatkov."
- 🛠 Tehnično: "Adapter nad `@kingstinct/react-native-healthkit` (Nitro) in `react-native-health-connect`; zdravje + CoreMotion / live števec, merge = max; `expo-background-task` na iOS; strežniški anti-cheat od zadnjega synca dovoli dohitevanje."
## 2026-10-07 — Ura izziva stoji, dokler pes čaka na plačilo (M3-11b)

**Kaj se je zgodilo:** David je odločil, da čas, ko pes po preizkusu čaka zaklenjen na nakup, **ne šteje v 12 tednov**. Strežnik zdaj iz zgodovine zaklepov izračuna »čas programa«: med zaklepom kuža ne stara, »Teden N od 12« in napredek otroka stojita, po plačilu pa se vse nadaljuje točno tam, kjer je obstalo. Več zaklepov (npr. po vračilu) se sešteje; pavza starša (hard stop) in bolezen se štejeta kot doslej. Isti dan je David sprejel tudi tveganje vračil: AI videi, ustvarjeni po nakupu, ostanejo tudi po vračilu (brez 48-urnega zamika).

**Zakaj je pomembno:** Plačan izziv mora pomeniti polnih 12 tednov igre. Otrok ne sme izgubiti tednov ali mladičkove faze samo zato, ker starš z nakupom odlaša.

**Številke:** pes, ki je čakal 10 dni, konča izziv 10 dni pozneje; 6 novih testov (vključno z dvema zaklepoma, ki se seštejeta, in s starostjo za pretekle dni, ki se ne spremeni).

**Kako povedati:**
- 👩 Starši: "Če z nakupom malo počakate, vaš otrok ne izgubi nič — 12 tednov se začne šteti naprej šele, ko izziv odklenete."
- 🧒 Otroci: "Ko kuža čaka na starše, tudi on počaka — ne zraste brez tebe."
- 💼 Investitorji: "Plačan izdelek = polna vrednost; brez skritega krajšanja programa."
- 🛠 Tehnično: "Efektivno rojstvo = `born_at` + sekunde v obdobjih `payment_lock` pred trenutkom (med zaklepom ura stoji na začetku zaklepa); `born_at` se ne spremeni, pretekli dnevi se nikoli ne preračunajo."

---

## 2026-10-07 — Plačila: izziv za enega psa, 7 dni brezplačno (M3-07 – M3-11)

**Kaj se je zgodilo:** Zgrajen je celoten plačilni del. Ob novem psu starš izbere **brezplačnega mešančka** (za vedno, brez 12-tedenskega programa) ali **12-tedenski izziv** — 7 dni brezplačno od »rojstva« psa, nato 49,99 € za tega psa. Brez naročnine in brez samodejnega plačila: po preizkusu se igra varno ustavi, dokler starš ne kupi. Nakup je samo v starševskem delu (otrok nikoli ne vidi cen), strežnik vodi vsak nakup enkrat in ga dodeli izbranemu psu; vračilo kupnine ga prekliče.

**Zakaj je pomembno:** To je prvi prihodek. Pravila so poštena (jasna cena, en pes = en nakup, ni naročnine) in poceni za nas: dragi AI videi se ustvarijo šele po nakupu. Dokler izdelki v trgovinah niso objavljeni, je stikalo izklopljeno — nihče ni zaklenjen.

**Številke:** 1 nakup = 1 pes (49,99 €), 7 dni preizkusa, en preizkus na otroka; ~1.300 testov v aplikaciji in ~1.260 na strežniku zelenih; neodvisni pregled je našel 1 blokado in 4 večje pripombe — vse popravljene pred združitvijo.

**Kako povedati:**
- 👩 Starši: "Preizkusite 7 dni brezplačno. Če se odločite, plačate enkrat 49,99 € za tega psa — brez naročnine, brez samodejnega plačila."
- 🧒 Otroci: "Ko preizkus mine, kuža varno počaka, da ga starši odklenejo."
- 💼 Investitorji: "Enkratni nakup na psa; stroški AI so vezani na plačane pse; brezplačni mešanček kot vstopna točka."
- 📣 Omrežja: "7 dni zastonj. Nato odločitev: je vaš otrok pripravljen na psa?"
- 🛠 Tehnično: "RevenueCat consumable → webhook (fail-closed, idempotenten) → krediti izziva na družino → dodelitev psu; zaklep `payment_required` uporablja zamrznitev hard stopa; stikalo `PAYMENTS_ENFORCED`."

---

## 2026-10-07 — PetPrep govori angleško (M1-18)

**Kaj se je zgodilo:** Aplikacija je zdaj dvojezična: **angleščina je privzeta**, slovenščina ostaja. Jezik se izbere sam po jeziku telefona, otrok ali starš pa ga lahko zamenja z enim dotikom (EN / SL na začetnem zaslonu, »Nadzor → Jezik«). Prevedeni so vsi zasloni — otroški simulator, šola, pogodba, starševski pregled, družina, račun — in tudi potisna obvestila, ki jih strežnik pošlje v jeziku, ki ga ima vsak telefon. Slovenska besedila so ostala do črke enaka.

**Zakaj je pomembno:** Brez angleščine ni tujih trgov. Zdaj je dodajanje novega jezika predvsem prevajalsko delo (ena mapa datotek JSON za aplikacijo in ena za strežnik), ne programersko. Slovnica je pravilna tudi pri številkah (»2 psa«, »3 psi«, »5 psov«), tudi v angleščini.

**Številke:** ~800 besedil v aplikaciji v 12 sklopih (833 v slovenščini zaradi dvojine in množine), 1.240 zelenih testov v aplikaciji, 1.211 na strežniku; test preveri, da imata oba jezika enake ključe, spremenljivke in slovnične oblike množine.

**Kako povedati:**
- 🧒 Otroci: "Tvoj kuža zdaj razume tudi angleško — izberi EN ali SL!"
- 👩 Starši: "PetPrep je zdaj v angleščini in slovenščini. Vsak telefon v družini ima lahko svoj jezik — tudi obvestila pridejo v njem."
- 📣 Omrežja: "Hello, world 🌍🐶 PetPrep now speaks English."
- 💼 Investitorji: "Aplikacija je pripravljena za tuje trge: nov jezik = prevod datotek, brez sprememb kode."
- 🛠 Tehnično: "i18next + expo-localization, tipizirani ključi iz angleških JSON, test enakosti ključev / spremenljivk / CLDR množin, `Accept-Language` za odgovore, jezik obvestil shranjen na napravo (`device_push_tokens.locale`)."

---

## 2026-10-07 — Album rasti in »Obroki danes« (M5-R04, del 2)

**Kaj se je zgodilo:** Ko kuža preide v novo življenjsko obdobje, umetna inteligenca iz stare fotografije naredi novo — isti pes, le starejši (od 5. 10.). Zdaj so vse te fotografije vidne: v albumu »Moj kuža« je nov razdelek **»Kako je kuža rasel«** — fotografije po vrsti z obdobjem (Mladiček, Mlad pes, Odrasel, Starejši), starostjo in datumom, trenutna označena z »Zdaj«; tap odpre fotografijo čez cel zaslon. Vidita ga otrok in starš (samo ogled); pokaže se, ko sta vsaj dve fotografiji. Fotografije so tudi v izvozu podatkov in se izbrišejo z računom. Otrok nad gumbi vidi še **»Obroki danes«**: okna obrokov s kljukico za vsak dobljeni obrok, trenutno okno poudarjeno, obroke med šolo označene »nahrani starš«; na manjših telefonih ena vrstica, med pospravljanjem nereda se skrije. Aplikacija še ni v trgovini.

**Zakaj je pomembno:** Otrok v 12 tednih vidi, kako je njegov kuža zrasel — to je spomin in dokaz skrbi hkrati. »Obroki danes« pa pokaže ritem dneva brez strašenja: zamujeno okno je le zatemnjeno.

**Številke:** 27 novih strežniških testov (skupaj 1.127 zelenih), 41 novih testov v aplikaciji (skupaj 1.122 zelenih); slike se strežejo prek podpisanih povezav, ki veljajo 60–90 minut in jih odpre samo družina; neodvisni pregled kode: brez blokad, 5 manjših popravkov narejenih.

**Kako povedati:**
- 🧒 Otroci: "Poglej, kako je tvoj kuža zrasel — od malega mladička do zdaj!"
- 👩 Starši: "Album rasti: vse fotografije vašega kužka skozi obdobja, vidne samo vaši družini — in tudi v izvozu podatkov."
- 📣 Omrežja: "Mladiček → mlad pes → odrasel. Isti pes, le starejši. 🐶📸 Album rasti v PetPrep."
- 💼 Investitorji: "AI slike se ustvarijo enkrat na obdobje (≈ 0,15 $), album jih samo prikaže — brez dodatnega stroška na ogled."
- 🛠 Tehnično: "Arhivirane slike prek `GET /api/media/history/{id}` (signed:relative + policy + X-Accel-Redirect v Caddy), `taken_at` ob arhiviranju, en poizvedbeni klic za kljukice obrokov (`fed` po oknu, UTC primerjava, DST test)."

---

## 2026-10-06 — Starejši kuža že nekaj zna, čas za šolo pa si otroci delijo pošteno (M5-R03b)

**Kaj se je zgodilo:** David je potrdil številke šolanja in dve novi pravili; zdaj so v strežniku in aplikaciji. **(1)** 5 minut vaje na psa na dan, +1 % na pravočasno pohvalo, −2 % na dan brez vaje so v bazi označeni »potrdil David 2026-10-06« (vir ostane dokaz, odločitev se ne predstavlja kot literatura); obstoječe vrednosti v produkciji se posodobijo enkrat, z revizijsko sledjo, in ne povozijo ničesar, kar je administrator že spremenil. **(2)** Pes, ki pride kot **mlad pes, odrasel ali starejši**, že zna **sedi 50 %, lulat zunaj 70 %, pridi 30 %, prostor 0 %**; mladiček začne z 0 %. Starš in otrok to vidita takoj, še pred podpisom pogodbe. **(3)** Če za psa skrbi več otrok, si **dnevnih 5 minut razdelijo enako** (dva otroka po 150 s = 3 vaje, trije po 2 vaji; vsak otrok vsaj eno vajo). Otrok vidi »Danes še 3 vaje« in »Čas za šolo si deliš z bratom ali sestro.«; ko porabi svoj del: »Tvoj današnji čas za šolo je porabljen. Brat ali sestra lahko s kužkom še vadi — ti pa spet jutri!«. Aplikacija še ni v trgovini.

**Zakaj je pomembno:** Posvojen odrasel pes iz zavetišča navadno že nekaj zna — igra zdaj to upošteva. Pri dveh otrocih ne more eden porabiti vsega časa in drugega pustiti brez vaje.

**Številke:** 22 novih strežniških testov (skupaj 1.095 zelenih), 17 novih testov v aplikaciji (skupaj 1.081 zelenih); neodvisni pregled kode odobril (popravljeni 3 manjši predlogi).

**Kako povedati:**
- 👩 Starši: "Posvojite odraslega psa? V PetPrepu že zna sesti in prositi, da gre ven — kot pravi pes iz zavetišča. Dva otroka? Čas za šolo si pošteno razdelita."
- 🧒 Otroci: "Če kuža k tebi pride že velik, zna nekaj ukazov že od prej! S sestro ali bratom si čas za šolo delita."
- 🤝 Zavetišča: "Odrasel posvojen pes v igri ni 'prazen list' — pride s svojim znanjem."
- 💼 Investitorji: "Vsaka številka simulacije ima vir ali zapisano odločitev ustanovitelja; spremembe v produkciji gredo z revizijsko sledjo."
- 🛠 Tehnično: "Delež otroka = max(⌊300 s / n⌋, 50 s) pod istim zaklepom vrstice kot dnevni proračun psa; začetno znanje je podatek v `breed_stage_params` (`training_starting_progress`) z oznako odločitve, uporabljen enkrat ob nastanku psa."

---

## 2026-10-06 — Aplikacija v novi preobleki: »Grafit in meta« (CGP v2)

**Kaj se je zgodilo:** Mobilna aplikacija je v celoti preoblečena v novo celostno podobo. Nova ikona (grafitni »Radovednež« na mint ozadju) za iPhone in Android (tudi prilagodljiva in enobarvna za teme in obvestila), nov zagonski zaslon, logotip na začetnem zaslonu, prijavi, registraciji in v pregledu družine, slogan »Pripravljeni na žival. Ob njej vse življenje.« na začetku. Starši imajo miren, svetel vmesnik (kot bančna aplikacija), otrok temen simulator, v katerem je **gumb za skrb, ki je zdaj na vrsti, poln mint** (hranjenje v oknu, čiščenje nereda, »Pelji ven«). Nove pisave Bricolage Grotesque in Instrument Sans (s šumniki). Aplikacija še ni v trgovini; nova ikona se pokaže z naslednjim buildom.

**Zakaj je pomembno:** Enotna podoba v aplikaciji, na spletu (petprep.si) in v gradivih — resna in zaupanja vredna za starše in partnerje, igriva na enem mestu za otroke. Pripravljeno za več živalskih vrst (ikona ni pes).

**Številke:** 68 datotek · ~60 zaslonov in komponent na barvnih žetonih · 6 rezov pisav (namesto 15) · nov test prepreči trde barvne kode zunaj teme · 1064 avtomatskih testov zelenih.

**Kako povedati:**
- 📣 Omrežja: "Nova preobleka! 👀💚 PetPrep je zdaj grafit in meta — pozdravite Radovedneža."
- 👩 Starši: "Pregled je miren in pregleden: bele kartice, jasni semaforji, nič kričečih barv."
- 🧒 Otroci: "Gumb, ki sveti v mint barvi, ti pove, kaj kuža potrebuje zdaj."
- 💼 Investitorji / 🤝 partnerji: "Ena celostna podoba čez aplikacijo, splet in gradiva; ikona brez vrste živali — pripravljena na mačke in druge."
- 🛠 Tehnično: "Design tokens v `src/theme`, brand `Text` mapira fontWeight na Instrument Sans reze (RN izbira po imenu družine), logotipi kot react-native-svg iz istih koordinat kot SVG."

---

## 2026-10-06 — »Šola« v aplikaciji: pohvali ob pravem trenutku (M5-R03, aplikacija)

**Kaj se je zgodilo:** Mini-igra šolanja je v aplikaciji. Otrok tapne **»Šola«** nad gumbi (oranžna pika = današnja vaja še čaka), izbere ukaz in 50 sekund gleda kužka: pokaže se »Sedi!«, nato »Kuža se usede.« — in takrat velik zelen gumb **»Pohvali«**. Po vsakem ukazu takoj prijazen odziv (»Bravo, ob pravem trenutku!«, »Prezgodaj«, »Malo prepozno«, »Super, da si počakal(a)«), na koncu napredek (»Sedi: 40 % → 43 %«) in pri 100 % »Kuža zna ukaz »Sedi«!«. Starš v podrobnostih otroka vidi »Kuža zna: sedi ✓, pridi 60 % …« in ali je bila vaja danes opravljena. Aplikacija še ni v trgovini.

**Zakaj je pomembno:** Otrok se nauči bistva pozitivne vzgoje z lastnimi prsti — in nikoli ni grajan: tudi »počakal si, ker kuža ni ubogal« je pravilen odgovor.

**Številke:** 4 ukazi · 8 ukazov v 50 s · okno za pohvalo 1,5 s · odziv po vsakem ukazu takoj · ura vaje se začne, ko telefon dobi urnik (zamik omrežja ne pokvari časa) · pohvala šteje šele 150 ms po tem, ko kuža uboga (prej ne more biti odziv) · vaja se po ponovnem zagonu aplikacije nadaljuje · 68 novih avtomatskih testov (18 zaslonskih, 50 za logiko igre in podatke).

**Kako povedati:**
- 📣 Omrežja: "»Sedi!« … kuža sede … ZDAJ! 💚 Pohvali ob pravem trenutku — nova Šola v PetPrep."
- 👩 Starši: "Vaja traja manj kot minuto, aplikacija nikoli ne graja, vi pa vidite, kaj kuža že zna."
- 🧒 Otroci: "Ko se kuža usede, hitro pritisni Pohvali! Če ne uboga — počakaj, tudi to je prav."
- 🛠 Tehnično: "Taps se merijo z monotono uro od prejema urnika, po vrnitvi iz ozadja se uskladi z wall clock; lokalni odziv uporablja isto pravilo kot strežniški scorer; finish natanko enkrat na sejo."

---

## 2026-10-06 — Kuža se uči: sedi, pridi, prostor, lulat zunaj (M5-R03, strežnik)

**Kaj se je zgodilo:** Strežnik zna šolanje psa. Otrok izbere ukaz (sedi, pridi, prostor, lulat zunaj) in začne 50-sekundno vajo: 8-krat reče ukaz, kuža včasih uboga 0,8–2,5 sekunde kasneje — in otrok mora v **1,5 sekunde** pritisniti »Pohvali«. Prezgodaj ali prepozno = nič napredka. Urnik izbere in pritiske oceni **strežnik**; spremenjena (vdrta) aplikacija bi sicer lahko poslala izmišljene popolne pritiske, a dnevna omejitev (5 min) omeji, koliko bi s tem pridobila, sumljivo enakomerne odzive strežnik zabeleži, pohvala hitreje od 150 ms po ukazu pa ne šteje. Napredek vsakega ukaza 0–100 %; **Border Collie se uči 2× hitreje** (Coren: prvi na lestvici poslušnosti), **vsak mešanček ±20 %** — enkrat izžreban in shranjen (David). Ena vaja na dan je **nova rutina v Care Score** (kot sprehod); brez vaje znanje upada. Mladiček, ki zna »lulat zunaj«, pokaže, da mora ven, in naredi manj luž; »prostor« zmanjša grizenje med menjavo zob. Šolanje dobijo samo novi psi iz aplikacije, ki ga zna prikazati; stari psi ostanejo nespremenjeni. 27 novih testov, vsa obstoječa pravila zelena.

**Zakaj je pomembno:** Šolanje je največja razlika med »imeti psa« in »skrbeti za psa« — in ga je v resničnem življenju najlažje opustiti. Igra nauči otroka bistva pozitivne vzgoje: **nagrada šteje ob pravem trenutku**. Starš dobi nov dokaz o odgovornosti: vsakodnevno vajo.

**Številke:** 4 ukazi · vaja 50 s (8 ukazov, okno za pohvalo 1,5 s) · do 5 min vaje na dan (≈ 6 vaj; viri AKC / PetMD: 5–10 min, mladiček ≤ 15 min) · Border Collie 2× · mešanček 0,8–1,2× · mešanček z eno vajo na dan obvlada ukaz v ~16 dneh, vse 4 ukaze v ~12 tednih (predlog) · −2 % na zamujen dan (predlog) · lulat zunaj 100 % → ~¾ manj luž (predlog, VCA) · nov vir S47 (VCA, veterinarska vedenjska specialistka).

**Kako povedati:**
- 📣 Omrežja: "Pohvala mora priti v pravem trenutku — tudi v PetPrep. Kuža sede, ti imaš sekundo in pol. Prezgodaj? Ni se naučil nič. 🐶🎓"
- 👩 Starši: "Otrok se nauči, kako pes res uči: kratke vaje, vsak dan, nagrada takoj. Vi vidite: 'Kuža zna: sedi ✓, pridi 60 %'." *(prikaz v aplikaciji od 6. 10. 2026 — glej vnos zgoraj)*
- 🧒 Otroci: "Ko kuža sede — hitro pritisni Pohvali! Border Collie je pameten, tvoj mešanček pa je čisto svoj."
- 💼 Investitorji: "Vsak nov sistem v igri je iz virov (Coren, AKC, VCA, ASPCA) ali označen kot naš predlog; urnik in oceno naredi strežnik; ker urnik vidi aplikacija, bi spremenjena aplikacija lahko ponaredila pritiske — tveganje omejujeta dnevni čas vaje in beleženje sumljivih vaj, sprotno razkrivanje urnika je možnost po MVP."
- 🤝 Partnerji (pasje šole): "PetPrep uči osnovno načelo pozitivne vzgoje — odlična priprava na tečaj v pasji šoli."
- 🛠 Tehnično: "Strežnik izbere urnik in oceni: start vrne urnik (cue / obey / window v ms), finish pošlje le tap offsete (urnik pozna tudi klient — ponarejene pritiske omeji dnevni budget, < 150 ms reakcije ne štejejo, enakomerne latence se logirajo); oceni ga čista funkcija, napredek je double 0–100 pod row lockom, ena aktivna seja na psa (partial unique index), TTL 60 s, idempotenten finish."

---

## 2026-10-06 — Hitrejša pot od popravka do strežnika (CI)

**Kaj se je zgodilo:** Vsaka sprememba je doslej dvakrat čakala na iste teste — enkrat v predlogu (PR) in še enkrat po združitvi v `main`, preden je šla na strežnik. Zdaj sistem prepozna, da je bila *točno ista* koda (isto git drevo) že preizkušena v predlogu, in na `main` teste preskoči. Testira se samo tisto, kar se je spremenilo: sprememba dokumentacije je preverjena v manj kot minuti in ne gre na strežnik, sprememba samo mobilne aplikacije prav tako ne. Strežniški testi (1.040) tečejo vzporedno — lokalno 186 s namesto 288 s. Pred vsako namestitvijo strežnik še vedno sam zgradi in preveri sliko, preden vklopi vzdrževalni način. Ob vsakem dvomu (neznana osnova, napaka GitHub API, drugačno drevo) se testira vse.

**Zakaj je pomembno:** Manj čakanja na vsak popravek, brez popuščanja pri varnosti — na strežnik nikoli ne gre nepreizkušena ali rdeča koda.

**Kako povedati:**
- 🛠 Tehnično: "Isti git tree = isti rezultat testov. PR shrani marker `ci-green-<suite>-<tree>`, `main` ga preveri prek GitHub API (uspešen PR run, isti repo, isti workflow) in preskoči ponovitev; path filter primerja z zadnjim zelenim `main`, ne z `event.before`, zato preklican run ne izgubi sprememb."
- 💼 Investitorji: "Majhna ekipa z AI agenti: od popravka do produkcije v nekaj minutah, z avtomatskimi varovalkami."

---
## 2026-10-06 — PetPrep dobi svojo spletno stran

**Kaj:** nova celostna grafična podoba (»Grafit in meta«, znak Radovednež) in spletna stran petprep.si v angleščini in slovenščini: domača stran, kako deluje, za starše, po posvojitvi, cenik, pogosta vprašanja, partnerji, vlagatelji, kontakt, zasebnost, pogoji in varnost otrok. Stran je pripravljena za iskalnike in AI asistente (strukturirani podatki, zemljevid strani, `llms.txt`).

**Zakaj je pomembno:** aplikacija že kaže na `petprep.si/pogoji` in `/zasebnost`; zdaj ti strani obstajata. PetPrep cilja na globalni trg — vsak nov jezik je ena datoteka z besedili.

**Kako povedati:** 📣 »PetPrep ima dom na spletu: Pripravljeni na žival. Ob njej vse življenje.« · 💼 dvojezična stran, pripravljena na lokalizacijo, ločen deploy brez vpliva na API · 👩 vse o izzivu, ceni in zasebnosti na enem mestu. *Pravna besedila čakajo pregled pravnika.*

## 2026-10-06 — Hitri popravek: aplikacija ne obupa več ob preobremenitvi

**Kaj se je zgodilo:** Na Davidovem TestFlightu (1.24.4) je bil kuža pri veterinarju. Strežnik je otrokovi aplikaciji za nekaj minut odgovoril »preveč zahtev« (429 — omejitev 60 zahtev na minuto na uporabnika), aplikacija se je zaprla, po ponovnem zagonu pa je ostala na zaslonu »Kužka ni bilo mogoče naložiti« in čas vrnitve od veterinarja pokazala v napačnem pasu (08:41 namesto 10:41). **Pravi vzrok:** prijava naprave za obvestila se je na iPhonu sama sprožala v krogu — vsak prevzem žetona je sistem iOS ponovno sporočil, aplikacija pa je napravo prijavila znova (strežnik je naštel do ~1 700 zahtev `POST /api/devices` na minuto). To je porabilo omejitev in verjetno tudi zrušilo aplikacijo. Zdaj se naprava prijavi enkrat na sejo, ponovno samo ob res novem žetonu, z zavoro in odlašanjem. Dodatno: aplikacija stanje vpraša največ ~10× na minuto, ob 429 počaka, kolikor strežnik reče, obdrži zadnje znano stanje in sama poskusi znova — brez tipke. Konec obiska pri veterinarju zdaj aplikacija ve tudi sama. Če se zaslon kužka kdaj zatakne, otrok vidi »Ups, nekaj se je zataknilo« z gumbom »Poskusi znova«, namesto da se aplikacija zapre. 35 novih testov (skupaj 950 zelenih); test ponovi zanko (stara koda: 60 002 prijav v eni minuti, nova: 1).

**Zakaj je pomembno:** Otrok ne sme ostati pred praznim zaslonom, ko je kuža bolan — takrat je skrb najbolj pomembna. In strežnik ostane miren tudi, ko bo otrok veliko.

**Kako povedati:**
- 👩 Starši: "Tudi ko je internet slab ali je strežnik zaseden, otrok vidi svojega kužka in čas, ko se vrne od veterinarja — po vašem času."
- 🛠 Tehnično: na iOS `getExpoPushTokenAsync()` vsakič znova sproži `addPushTokenListener` — poslušalec, ki ob vsakem dogodku ponovno registrira, se vrti v neskončnost. Lekcija: poslušalec žetona naj ukrepa samo ob spremembi. Plus omejevalnik zahtev na odjemalcu (žetoni, 3 + 1 na 6 s), eksponentno odlašanje pri časovnih mejah, `Retry-After`, error boundary okoli HUD-a; incident ponovljen v Jest testih z lažnimi urami.

---

## 2026-10-06 — »Pelji ven« in pregrizen copat zdaj tudi v aplikaciji (M5-R02, aplikacija)

**Kaj se je zgodilo:** Mladiček ima v aplikaciji peti gumb **»Pelji ven«**, nad gumbi pa mirno odštevanje (»Kuža bo moral ven čez ~1 h 20 min« — brez rdeče barve in alarmov). Če ga nihče ne pelje ven, nastane luža: otrok jo pobriše v znani igri čiščenja, le da so namesto madežev lužice. Pregrizen copat se pokaže ob kužku z gumbom **»Pospravi in daj igračo«** — copata otrok ne »drgne«. Brezplačni pes pokaže lužo in copat kot risbo v aplikaciji, plačljiva pasma kot AI video. Starš pri otroku vidi, do kdaj mora mladiček ven, odprte nerede z rokom, kolikokrat ga je otrok v 7 dneh peljal ven, in v časovnici »Mladiček je naredil lužo«. Stari psi in starejši strežniki ostanejo brez sprememb. 75 novih testov (skupaj 915 zelenih).

**Zakaj je pomembno:** Pravilo iz veterinarskih navodil (»1 ura na mesec starosti«) otrok zdaj občuti vsak dan — in se nauči načrtovati, ne pa panično reagirati.

**Kako povedati:**
- 🧒 Otroci: "Poglej nad gumbi — tam piše, čez koliko časa mora tvoj mladiček ven. Pritisni »Pelji ven« in ura se začne znova."
- 👩 Starši: "Vidite, do kdaj mora mladiček ven in kdo ga je peljal — brez alarmov, ki bi otroka strašili."
- 💼 Investitorji: "Brezplačni pes dobi risbe, plačljiva pasma AI videe istih trenutkov — vidna razlika brez stroška na dogodek."
- 🛠 Tehnično: stanje vedno s strežnika (optimistično + odgovor strežnika), odštevanje v strežniškem času, SVG z react-native-svg, brez novih nativnih modulov.

---

## 2026-10-06 — Mladiček mora ven, kuža grize copate (M5-R02, strežnik)

**Kaj se je zgodilo:** Kuža se zdaj vede kot pravi pes. **Mladiček zdrži približno eno uro na mesec starosti** (2 meseca → 2 uri; Ryan Veterinary Hospital Univerze v Pensilvaniji in WebMD) — otrok ga z gumbom **»Pelji ven«** odpelje ven in ura se začne znova; če pozabi, nastane **luža**. Ura teče samo, ko je otrok doma in buden (izven tihih ur): mladiček, ki gre ven ob 6:30, pri šoli 8–13 naredi lužo šele ob 13:30. **Uničevanje (»uničil copat«)**: pes, ki včeraj ni dosegel cilja sprehoda, danes nekaj zgrize (dolgčas, ASPCA / PDSA); mladiček med menjavo zob (3–6 mesecev, American Kennel Club) občasno tudi brez razloga — pogostost (zdaj ~vsak drugi dan) je naš predlog, ki ga David še potrdi. Otrok ga reši z **»Pospravi in daj igračo«**. Oboje je rutina kot kakec: 2 uri časa, sicer zamujeno, in šteje v Care Score. Plačljiva pasma dobi dva nova AI videa (luža, grizenje), ustvarjena enkrat na življenjsko obdobje, ne ob vsakem dogodku.

**Zakaj je pomembno:** To sta dve najpogostejši »presenečenji« novih lastnikov mladičkov — nočno in dnevno odvajanje ter uničene stvari. Zdaj ju otrok doživi v igri, preden se zgodita na pravi preprogi. Ker vse temelji na isti rutini čiščenja, starš vidi pošteno oceno brez novih pravil.

**Kako povedati:**
- 👩 Starši: "Mladiček v PetPrepu mora ven vsakih nekaj ur — tako kot pravi. Ko je otrok v šoli ali spi, ura stoji; noč je vaša."
- 🧒 Otroci: "Tvoj mladiček mora ven! Pritisni »Pelji ven«, preden naredi lužo. Če se dolgočasi, zgrize copat — pospravi in mu daj igračo."
- 🤝 Veterinarji / šole za pse: "Pravilo »1 ura na mesec starosti« je v igri dobesedno iz veterinarskih navodil za navajanje na čistočo."
- 💼 Investitorji: "Realizem iz virov: vsaka številka ima vir ali je označena kot predlog; plačljiva pasma dobi vidne dodatne videe po ~0,56 $ na video in obdobje."
- 🛠 Tehnično: ena tabela za vse nerede (`kind`: kakec, luža, uničevanje), ura mladička šteje samo netihe sekunde in je varna ob prestopu ure (25-urni dan 25. 10.); 26 novih testov, skupaj 1025+ zelenih.

---

## 2026-10-06 — Bolan kuža zdaj tudi izgleda bolan (tudi brezplačni)

**Kaj se je zgodilo:** David je v resničnem testu poslal svojega brezplačnega mešančka k veterinarju — a aplikacija je še vedno kazala istega veselega psa kot ves dan. Brezplačni pes ima namreč samo dva videa (miruje, spi), zato je aplikacija namesto »bolan« vzela »miruje«. Zdaj ima vsako stanje jasen nadomestek: bolan → spi → miruje, utrujen → spi → miruje. Mešanček pri veterinarju zato **spi pod temnejšo, hladno sivo plastjo**; pes s pravim videom »bolan« (plačljiva pasma) ostane pod svetlejšo plastjo. *(Čaka Davidovo potrditev.)*

**Kako to povedati**
- 👩 *"Ko kuža zboli, otrok to vidi takoj — tudi v brezplačni različici: kuža leži in počiva, zaslon potemni."*
- 🛠 *"Majhna tabela nadomestkov namesto ad-hoc pogojev: vsako stanje ve, kateri video ga najbolje nadomesti."*
## 2026-10-05 — Starš izbere kužka v aplikaciji (M5-R04, del 1)

**Kaj se je zgodilo:** Izbira kužka, ki jo je strežnik znal že zjutraj, je zdaj tudi v aplikaciji. Ko starš doda otroka in izbere *Nov pes*, se odpre zaslon **Izberi kužka**: pasma (mešanček brezplačen, Border Collie viden, a zaklenjen — del plačljivega izziva), **od kod pride** (*kupljen* pri vzreditelju / *posvojen* iz zavetišča) in **starost ob prihodu** (mladiček, mlad pes, odrasel, starejši). Ob vsaki starosti je ena poštena vrstica, kaj to pomeni za otroka — npr. mešanček mladiček: 4 obroki na dan, sprehod 2.000 korakov na dan in vsak teden več do 6.000; Border Collie mladiček prav tako začne z 2.000, a pri 12 mesecih pride do 12.000, starejši Border Collie hodi 9.000 korakov na dan. Dokler se otrok ne poveže, lahko starš izbiro še spremeni (*Spremeni kužka* — stara koda takrat preneha veljati). Izvor in starost nimata privzete izbire: starš mora prebrati in se odločiti. Otrok nato na svojem zaslonu vidi "Mladiček · 2 meseca" in kdaj postane mlad pes; starš v pregledu otroka vidi isto in koliko obrokov danes v tihih urah nahrani sam. Psi iz časa pred to spremembo ostanejo, kot so bili.

**Zakaj je pomembno:** Šele s tem zaslonom nova pravila po starosti (M5-R01) zares pridejo do družin — prej je bil vsak nov pes še "star" pes po pravilih pred M5. Opisi uporabljajo samo številke iz virov in Davidovih potrjenih odločitev; česar igra še ne simulira (nezgode v hiši, plašnost), je označeno "pride kmalu".

**Kako povedati:**
- 👩 Starši: "Preden otrok dobi kodo, izberete, kakšnega kužka bo imel — mladička s štirimi obroki na dan ali starejšega psa iz zavetišča s krajšimi sprehodi. Vidite, kaj vsaka izbira pomeni."
- 🧒 Otroci: "Tvoj kuža ima zdaj starost — vidiš, koliko je star in kdaj bo zrasel."
- 🤝 Zavetišča: "V aplikaciji je *posvojen pes iz zavetišča* enakovredna izbira — brez klišejev, z enako skrbjo."
- 🛠 Tehnično: izbira se pošlje kot celoten nabor (vse ali nič), plačljiva pasma je zaklenjena tudi na strežniku (`breed_locked`), stari psi brez profila se prikažejo nespremenjeno; vsako število v opisih test preveri proti potrjenim pravilom; 828 testov zelenih.

---

## 2026-10-05 — David potrdil pravila rasti: 2-urna okna za mladička (M5-R01b)

**Kaj se je zgodilo:** David je odgovoril na odprta vprašanja realistične simulacije. **Okna hranjenja mladička so zdaj 2-urna** (4 obroki 7–9, 11–13, 15–17, 19–21; 3 obroki 7–9, 13–15, 19–21) — 1 ura je bila za otroka prekratka. Potrjene so meje obdobij (mladiček do 9 mesecev, mlad pes do 3 let, starejši v zadnji četrtini življenjske dobe — mešanček 9 let, Border Collie 9,8), starosti ob prihodu, minute gibanja (odrasel mešanček 60 min = 6.000 korakov, mladiček 10 min na mesec starosti, starejši 75 %) in 2 obroka za starejše. **Psi, ki že igrajo, ostanejo na starih pravilih za vedno.** V bazi so vse vrednosti zdaj potrjene: iz vira ali z oznako »potrdil David 2026-10-05« — vir ostane zapisan kot dokaz, odločitev se nikoli ne predstavi kot literatura. Strežnik že zapisane vrednosti posodobi enkrat, z revizijsko sledjo, in ne povozi ničesar, kar je admin že ročno spremenil. **985 zelenih testov** (+8).

**Kako to povedati**
- 👩 *"Mladiček je lačen štirikrat na dan — a otrok ima za vsak obrok 2 uri časa. Kjer viri dajo le razpon, smo številko postavili v PetPrepu na podlagi virov."*
- 💼 *"Vsaka številka v simulaciji ima vir ali zapisano odločitev ustanovitelja — ločeno in preverljivo."*

## 2026-10-05 — Kuža raste: izvor, starost in pravila iz preverjenih virov (M5-R01, strežnik)

**Kaj se je zgodilo:** Dosedanji kuža je bil "večni mladiček brez starosti": vsak dan 2 obroka in 4.000 / 10.000 korakov — številke iz prve specifikacije, brez vira. Zdaj:
- **Starš izbere kužka** — pasmo, **izvor** (*kupljen* ali *posvojen* iz zavetišča) in **starost ob prihodu** (*mladiček* 2 meseca, *mlad pes* 9 mesecev, *odrasel* 3 leta, *starejši* 9 let; Border Collie 9,8). Mešanček je brezplačen v vseh kombinacijah. *(Strežnik; zaslon za izbiro v aplikaciji je načrt.)*
- **Kuža se stara** 1 mesec na teden in prehaja skozi življenjska obdobja (mladiček do 9 mesecev, mlad pes do 3 let, odrasel, starejši) — razponi iz smernic ameriškega veterinarskega združenja AAHA in raziskave o življenjski dobi 2024 (Border Collie 13,1 leta, mešanci 12,0); točne meje znotraj razponov so *predlog*, ki čaka potrditev.
- **Potrebe sledijo starosti:** mladiček 4 obroke na dan, od 3. meseca 3, od 6. meseca 2 (ASPCA, The Royal Kennel Club). **Cilj korakov = minute gibanja × 100 korakov**: odrasel Border Collie 12.000 ("več kot 2 uri na dan", The Royal Kennel Club), odrasel mešanček 6.000 *(predlog)*, mladiček 2 meseca 2.000 in vsak teden več *(predlog — pravilo "5 minut na mesec starosti" je med viri sporno)*.
- **Obrok med šolo ali spanjem opravi starš** — otroku se ne šteje kot zamujen; kuža je ob začetku okna samodejno nahranjen.
- **Vsaka številka ima vir** (46 virov v `docs/research/dog-data`), v bazi zapisan z ID-jem vira, zanesljivostjo in oznako *preverjeno*. Kjer vira ni (npr. točne ure obrokov, minute mešančka), je vrednost označena kot **predlog** — vidno v administraciji in je staršem ne prikazujemo kot dejstva. Vsako urejanje teh podatkov se zabeleži (kdo, kaj, prej → potem).
- **Kuža na sliki raste z njim:** ob prehodu v novo obdobje umetna inteligenca iz prejšnje fotografije naredi novo — isti pes, isti kožuh in oznake, le starejši (Nano Banana Pro Edit, ≈ 0,15 $ + videi). Stare slike ostanejo za album rasti *(album je načrt)*. Mladiček ima na sliki velike tace in puhasto dlako, starejši siv gobček; posvojen pes je prikazan zdrav in miren — brez klišejev "žalostnega psa iz zavetišča".
- **Videz po standardih:** Border Collie po FCI / AKC (npr. modre oči le pri merlih, jantarnih ni), mešanček je srednje velik pes 15–30 kg.
- **Pošteno do družin, ki že igrajo (pregled PR #37):** psi, ki so v igri že pred to spremembo, **ostanejo na dosedanjih pravilih do konca izziva** — noben otrok sredi izziva ne dobi nenadoma 4 obrokov ali drugačnega cilja korakov — in dokler aplikacija nima zaslona za izbiro kužka, tudi novi psi ostanejo na dosedanjih pravilih; že zaključeni dnevi in ocena se ne spremenijo (test to preveri bajt za bajtom). Vrednosti, ki še čakajo potrditev, so v bazi označene kot *predlog*, in če administrator vrednost izbriše, je naslednja posodobitev ne vrne.
- **Številke:** 45 novih strežniških testov + 12 ob pregledu (skupaj **977 zelenih**), med njimi prehod 4 → 3 obroke, rojstni dan čez spremembo ure, izbira kužka, samodejni obrok med šolo, preverjanje vsake vrednosti proti datoteki virov; aplikacija **788 zelenih**.

**Zakaj je pomembno**
PetPrep obljublja "objektiven dokaz, ali je otrok pripravljen na psa" — to drži le, če je simuliran pes podoben pravemu. Mladiček, ki je lačen štirikrat na dan in vsak teden potrebuje več gibanja, je drugačna odgovornost kot odrasel pes; starš lahko zdaj preizkusi točno tisto, kar namerava domov pripeljati — tudi posvojenega psa.

**Kako to povedati**
- 👩 *"Izberite psa, kot bi ga res pripeljali domov: mladička od vzreditelja ali odraslega psa iz zavetišča. Vse potrebe — koliko obrokov, koliko sprehoda — so iz veterinarskih virov, ne izmišljene."*
- 🧒 *"Tvoj kuža raste! Vsak teden je mesec starejši — in ko zraste, dobi novo sliko."*
- 💼 *"Simulacija temelji na 46 preverljivih virih; vsaka številka v bazi nosi svoj vir. To je osnova za kasnejšo Fazo 2 (asistent za pravega psa) in za partnerstva z zavetišči."*
- 🤝 *(zavetišča)* *"Družine lahko pred posvojitvijo preizkusijo skrb za odraslega psa iz zavetišča — prikazan je dostojanstveno, brez žalostnih klišejev."*
- 🛠 *"Laravel: `breed_stage_params` (ena vrednost na vrstico z virom, revizija sprememb), pravila dneva po starosti ob lokalni polnoči (DST-varno), prehod faze v ticku z zaklepom vrstice → en job → image-to-image iz shranjene slike."*

## 2026-10-05 — Kuža zdaj lahko pokliče: push obvestila (M3-02)

**Kaj se je zgodilo:** Pravila opozoril so v igri obstajala že od začetka (faza 1 pri 30 %, faza 2 pri 10 %, alarm staršem po uri na 0 %), a so ostala v aplikaciji — kdor je ni odprl, ni izvedel ničesar. Zdaj gre vsak korak tudi kot **obvestilo na telefon** (prek Expo → Apple / Google):
- **Otrok** dobi blag opomnik (»Tvoj kuža te milo gleda in kaže na posodo s hrano.«), pri 10 % pa nujnega z zvokom. Besedilo sledi temu, kar manjka — hrana, voda ali nered. Za **sprehod** dobi otrok največ **en prijazen opomnik na dan**, najprej 2 uri po koncu tihih ur (npr. ob 15:00 po šoli), brez grožnje z boleznijo.
- **Starši** (vsi v družini) dobijo alarm, ko pes več kot uro nima hrane, vode ali čistoče: »Tvoj otrok danes ni poskrbel za psa.« O bolezni in odhodu v zavetišče izvedo starši in otrok.
- **Tihe ure veljajo tudi za obvestila:** med šolo in spanjem telefon molči; novica o bolezni ali zavetišču počaka do jutra (*pravilo čaka Davidovo potrditev*). Starš v »Nadzoru« vidi, ali so obvestila vklopljena. Isto obvestilo za istega psa največ enkrat na 30 minut.
- **Brez imen:** obvestilo nosi samo vrsto dogodka in številko psa; naslov je vedno »PetPrep«. Hranimo le žeton naprave, iOS/Android in različico aplikacije.
- Aplikacija vpraša za dovoljenje **ob pravem trenutku** — otroka takoj po podpisu pogodbe, starša po dodanem otroku — in najprej s slovensko razlago. Tap na obvestilo odpre kužka (otrok) oziroma podrobnosti otroka (starš).
- **Številke:** 49 novih strežniških testov (skupaj **910 zelenih**), 53 novih testov v aplikaciji (skupaj **788 zelenih**). Na telefonih deluje z naslednjo novo gradnjo aplikacije *(načrt: preizkus na iPhonu in Androidu)*.

**Zakaj je pomembno**
Simulacija psa, ki »nima glasu«, ne uči odgovornosti — pravi pes zacvili, ko je lačen. Obvestila so most med igro in vsakdanom otroka; tihe ure poskrbijo, da PetPrep ne moti šole in spanja.

**Kako to povedati**
- 🧒 *"Tvoj kuža te zdaj lahko pokliče, ko je lačen ali bi rad šel ven — ampak nikoli med šolo ali ponoči."*
- 👩 *"Če otrok pozabi, najprej opomnimo otroka. Šele ko kuža več kot uro ostane brez hrane ali vode, pokličemo vas. V obvestilih ni imen otrok."*
- 💼 *"Push zanka (opomnik → nujno → alarm staršem) zapira krog vedenjske spremembe; vse po pravilih iz specifikacije, s spoštovanjem tihih ur in brez osebnih podatkov pri tretjih straneh."*
- 🛠 *"Laravel: eskalacija v transakciji z zaklepom vrstice zapiše odločitev (dedupe, tihe ure) in po commitu pošlje job; Expo Push API v kosih po 100, ticketi unikatni na (obvestilo, napravo) — ponovni poskus nikoli ne pošlje dvakrat; DeviceNotRegistered iz ticketov in potrdil izklopi napravo."*

## 2026-10-05 — Album »Moj kuža«: otrok vidi vse posnetke svojega psa

**Kaj se je zgodilo:** David je kot otrok na glavnem zaslonu videl le en video v zanki — želel je videti vse. Zdaj ima otrok v glavi zaslona gumb za **album »Moj kuža«**: fotografija psa in ploščica za vsak njegov video (*Miruje, Spi, Utrujen, Lačen, Bolan, Se igra* — toliko, kolikor jih ima ta pes). Tap odpre posnetek čez cel zaslon (v zanki, brez zvoka, z gumbom za zvok); s puščicami ali potegom gre na naslednjega. Posnetki, ki se še pripravljajo, so sivi »Še ni posnetka«; posnetkov, ki jih pes nima (npr. dodatna stanja plačljive pasme), album ne kaže — nič otroka ne vabi k nakupu. Hkrati teče le en video (glavni zaslon se ustavi), zato album ne prazni baterije in ne porablja dvojnih podatkov. Isti album lahko odpre tudi starš iz podrobnosti otroka.
- **Številke:** testi v aplikaciji skupaj **721 zelenih**; merilniki na zaslonu preverjeni za vsako višino zaslona od 150 do 1000 točk — nikoli več pod gumbi.

**Kako to povedati**
- 🧒 *"Poglej, kaj vse zna tvoj kuža! Odpri album in ga glej, kako spi, se igra ali je lačen."*
- 👩 *"Otrok vidi vse posnetke svojega kužka — brez oglasov in brez vabljenja k nakupom v otroškem delu."*

## 2026-10-05 — Popravki s prvega TestFlighta: sprehod, merilniki, povezava v živo

**Kaj se je zgodilo:** David je na iPhonu (TestFlight) našel tri napake; vse tri so popravljene na veji `fix/mobile-unstyled-overlays`:
- **Okno »Sprehod« je bilo brez oblike** (črno besedilo levo zgoraj čez igro). Vzrok: knjižnica za sloge (NativeWind) se v produkcijski gradnji ni ujela z orodjem, ki ga Expo doda samodejno. Uporabljale so jo le 4 datoteke → prepisane v standardne sloge React Native, knjižnica odstranjena (≈ 440 vrstic manj v `yarn.lock`), test pa prepreči, da bi se vrnila.
- **Četrti merilnik (Čistoča) se je skrival pod gumbi.** Merilniki se zdaj prilagodijo velikosti zaslona (od iPhona SE do Pro Maxa) in izrezu/otoku zgoraj; »ENERGIJA« se ne lomi več v dve vrstici.
- **Rdeč »BREZ POVEZAVE«, čeprav je vse delovalo.** Našli smo dve napaki v povezavi v živo (knjižnica pusher-js na telefonu izvaža drugače kot v brskalniku; nastavitev prenosov je izklopila edino pot, ki jo uporablja). Telefon se zdaj lahko poveže v živo; kadar povezave v živo ni, otrok vidi le majhno sivo ikono osveževanja namesto rdečega opozorila.
- **Številke:** 59 novih testov v aplikaciji (skupaj **698 zelenih**), merilniki preverjeni na 6 velikostih zaslona.

**Zakaj je pomembno**
Prvi pravi test na telefonu je pokazal, česar testi na računalniku niso: razliko med razvojno in produkcijsko gradnjo. Zdaj imamo teste, ki preverijo pravo knjižnico za telefon in velikosti pravih zaslonov.

**Kako to povedati**
- 🛠 *"Expo SDK 57 doda Babel plugin za worklets samodejno — z NativeWindovim jsxImportSource se v release gradnji className tiho izgubi. Mi smo NativeWind odstranili (4 datoteke), pusher-js RN build pa izvaža `{ Pusher }` brez default exporta, in `enabledTransports: ['wss']` z `forceTLS` izklopi vse prenose."*
- 👩 *"Otrok ne vidi več strašljivih rdečih opozoril — kuža se osvežuje tudi, ko povezava v živo za hip pade."*

## 2026-10-05 — Starši lahko izbrišejo račun in izvozijo vse podatke (M2-08)

**Kaj se je zgodilo:** V aplikaciji za starše je v zavihku **»Nadzor«** nov razdelek **»Račun«**:
- **»Izvozi moje podatke«** — ena datoteka JSON z vsem, kar PetPrep hrani o družini: starši, vzdevki in letnice otrok, psi z vso zgodovino (hranjenje, voda, čiščenje, koraki, sprehodi, rutine, bolezni, premori), pogodbe z otrokovim podpisom, ocene (Care Score, semafor) in povezave do slik in videov kužka. Brez gesel, kod PIN in kod povabil. Odpre se sistemsko okno za deljenje (pošljete si po e-pošti, shranite v datoteke …).
- **»Izbriši račun«** — aplikacija najprej pove, kaj se bo zgodilo: če ste **edini starš, gre celotna družina** (otroci, psi, slike, videi, pogodbe, dnevnik); če je v družini še drug starš, gre samo vaš račun. Potrdite z geslom in besedo **IZBRIŠI**, nato vas aplikacija odjavi.
- Pri vsakem otroku **»Izbriši profil«** — pes, za katerega je skrbel sam, gre z njim; **skupni pes ostane** bratu ali sestri, otrokova pretekla skrb pa ostane v dnevniku brez imena.
- Izbris je takojšen in nepovraten (brez čakalne dobe — *Claudova izbira, čaka Davida*). Vse se izbriše v enem koraku v bazi; slike in videi se z diska pobrišejo takoj zatem. Strošek AI ostane v knjigovodstvu, a brez povezave na psa. Zapis v dnevnik strežnika ne vsebuje imen ali e-pošte — samo številko družine in koliko je bilo izbrisano.
- Administracija (Filament) ima dejanje **»Delete family«** z istimi pravili; navadno brisanje uporabnikov je odstranjeno, ker bi pustilo datoteke in obšlo pravila.
- **Med delom najden in popravljen hrošč:** brisanje psa, ki je že imel AI sliko s stroškom, je v bazi padlo (dve »nastavi na prazno« pravili v istem koraku). Brez popravka izbris računa ne bi uspel za nobenega psa s sliko.
- **Številke:** 47 novih testov na strežniku (skupaj **861 zelenih**), 29 novih v aplikaciji (skupaj **555 zelenih**). Zavore: 5 napačnih gesel na 15 min (ločeno za račun in otroka), 3 izvozi na uro.
- **Po pregledu kode (PR #29):** izbris brata ali sestre **ne spremeni pretekle ocene** otroka, ki ostane — sistem si zapomni, da je izbrisani otrok skrbel (brez imena ali drugih podatkov), zato pošten delež ostane enak (preizkus: otroka, ki sta skrbela izmenično, imata 100; po izbrisu enega ima drugi še vedno 100, ne 50). Če po pošiljanju zmanjka povezave, aplikacija ne trdi več, da se ni nič izbrisalo, ampak pošteno pove, da izid ni znan. Zavora šteje samo napačna gesla.
- *Načrt:* potrditveno e-sporočilo o izbrisu (ko bo ponudnik e-pošte), izvoz kot datoteka namesto besedila, asinhroni izvoz za zelo velike družine.

**Zakaj je pomembno**
Apple aplikacije brez izbrisa računa v aplikaciji ne sprejme v App Store, GDPR pa staršem zagotavlja dostop do podatkov in njihov izbris. Pri aplikaciji za otroke je to temelj zaupanja: starš lahko kadarkoli vzame vse s sabo ali vse pobriše.

**Kako to povedati**
- 👩 *"Vaši podatki so vaši: z enim dotikom jih izvozite, z dvema izbrišete — celotno družino ali samo enega otroka. Brez klicev na podporo."*
- 💼 *"Skladnost z App Store (in-app account deletion) in GDPR čl. 15/17/20 pred beto: izvoz in izbris sta samopostrežna, revizijska sled brez PII."*
- 🛠 *"Laravel: izbris družine v eni transakciji z vrstnim redom zaklepov (starš → otroci → družina → psi), datoteke v queued jobu po commitu, idempotentno; pazili smo na zastarele kaskadne tuje ključe in na PostgreSQL past z dvema ON DELETE SET NULL v isti vrstici."*
## 2026-10-05 — Bolan kuža je siv, a živ; in vedno vemo, katero različico testiramo

**Kaj se je zgodilo:** Po pregledu kode (PR #28) sta dve spremembi vidni na zaslonu:
- **Pri veterinarju** otrok ne vidi več črnega zaslona: kuža je viden skozi **prosojno sivo plast** in se v videu (če ga ima) težko diha in leži — točno tako, kot pravijo pravila igre (»zaslon sivo, video težkega dihanja«). Enako, ko starš ustavi igro: kuža spi pod sivo plastjo. Besedilo (npr. »do 18:30«) ostane berljivo. Ob game overu ostane temen zaslon.
- **Oznaka različice:** na začetnem zaslonu in v starševskem »Nadzor → O aplikaciji« je majhna siva oznaka, npr. `v1.10.2 · 23cd58a` — različica in koda, iz katere je aplikacija zgrajena.
- Popravljen redek primer, ko je kuža med hitrim preklopom stanj za trenutek izginil; videi se zdaj shranjujejo v predpomnilnik telefona. **599 zelenih testov** (dvakrat zapored).

**Zakaj je pomembno**
Bolezen mora biti vidna in čutna, ne le napis — otrok vidi, da kužku ni dobro. Oznaka različice pa pri testiranju prihrani ugibanje, kateri popravek je že na telefonu.

**Kako to povedati**
- 🧒 *"Ko je kuža pri veterinarju, ga vidiš sivega in utrujenega. Počakaj, da se vrne zdrav!"*
- 👩 *"Ko je kuža bolan, otrok to vidi na lastne oči — brez strašljivih slik, le umirjen siv prikaz."*
- 🛠 *"Prosojen zaklep čez predvajani video; build identity iz EAS_BUILD_GIT_COMMIT_HASH v app.config.ts → expo-constants."*

## 2026-10-05 — Kuža v aplikaciji oživi: AI videi na glavnem zaslonu

**Kaj se je zgodilo:** Videi, ki jih umetna inteligenca naredi za vsakega psa ob rojstvu, so zdaj **na otrokovem glavnem zaslonu**. Namesto risbe otrok vidi svojega, edinstvenega kužka v 5-sekundnem videu brez zvoka, ki se neprekinjeno ponavlja — in video ustreza stanju igre:
- miruje, ponoči in med tihimi urami **spi**, ko starš ustavi igro, kuža spi; pes plačljive pasme (vseh 6 videov) je tudi lačen ob prazni skledi, utrujen, igriv in pri veterinarju **bolan**; po game overu ostane samo slika;
- ko se stanje spremeni, star video teče, dokler novi ni pripravljen, nato se **mehko zamenjata** (0,3 s) — brez črnega zaslona;
- če pes video za neko stanje nima (brezplačni mešanček ima 2 videa), se pokaže video "miruje", nato slika, nato risba — otrok **nikoli ne vidi napake**; dokler se kuža šele ustvarja, piše "Kuža se pripravlja…";
- **varčno:** video se ustavi, ko aplikacija ni odprta, pod zaklepom in med sprehodom, ne prižiga zaslona, naenkrat teče en sam predvajalnik; povezava na video se na 30 min osveži, a predvajanje se zaradi tega ne začne znova; če povezava poteče, aplikacija enkrat pridobi novo;
- **starši** vidijo sliko kužka na kartici otroka in njegov video "miruje" v podrobnostih.
- **Številke:** 57 novih testov, skupaj **583 zelenih** testov v mobilni aplikaciji (dvakrat zapored), TypeScript brez napak. *Načrt:* preizkus na pravih telefonih (iPhone, Android) pred zaprto beto.

**Zakaj je pomembno**
To je trenutek, ko PetPrep postane "moj pes": otrok vidi živega psa, ki je samo njegov, in takoj opazi, kdaj je lačen ali zaspan — brez branja številk.

**Kako to povedati**
- 🧒 *"Tvoj kuža je živ! Mirno sedi, ponoči pa spi."*
- 👩 *"Otrok vidi, kako se kuža počuti — brez zvoka, brez praznjenja baterije, video se ustavi, ko aplikacija ni odprta."*
- 💼 *"Edinstven AI pes v videu za vsako stanje igre — en sam predvajalnik, brez dodatnega generiranja na napravi."*
- 🛠 *"expo-video (SDK 57) s plastmi: nova plast ostane skrita do onFirstFrameRender, nato crossfade; predvajalnik je vezan na identiteto datoteke (pot + hash), ne na podpisan URL, zato bucketed signed URLs ne zaženejo predvajanja znova."*

## 2026-10-05 — Administracija hitrejša, videi ne zavirajo več aplikacije (produkcijski strežnik PHP)

**Kaj se je zgodilo:** Produkcija je do zdaj tekla na **razvojnem** strežniku PHP (`php artisan serve`, 4 delavci, brez predpomnilnika prevedene kode). Zato je bila administracija (Filament) počasna, vsak prenos videa psa pa je za ves čas prenosa zasedel enega od štirih delavcev. Pripravljen je pravi produkcijski način (velja ob naslednjem deployu, ko se veja združi):
- **PHP-FPM + OPcache** v lastni produkcijski sliki: koda in knjižnice so v sliki, prevedena koda ostane v pomnilniku, do 12 delavcev. Predpomnilniki Laravela (nastavitve, poti, pogledi, Filament) se zgradijo ob vsakem zagonu.
- **Caddy sam streže** statične datoteke administracije (z dolgim predpomnjenjem) in **videe ter slike psov**: PHP samo preveri, ali je povezava podpisana in ali jo sme gledati ta uporabnik, datoteko pa pošlje Caddy (tudi po delih za iPhone).
- **Varnejši deploy:** nova slika se zgradi in preizkusi, **preden** gre aplikacija v vzdrževalni način — če gradnja ne uspe, uporabniki ničesar ne opazijo. Ob napaki pred migracijami se samodejno vrne prejšnja različica (koda in slika).
- Odpravljen star dolg: knjižnice (`vendor`) se zdaj res namestijo ob vsakem deployu (prej je bila na strežniku ročno nameščena kopija).
- **Številke (lokalni preizkus):** med 4 hkratnimi prenosi 10 MB videa je odziv API-ja trajal **4,4 s** na starem strežniku in **0,01–0,02 s** na novem. Strežnik: **814 zelenih testov**, test deploya **189 preverjanj**, 30 preverjanj celotne poti Caddy → PHP-FPM.
- *Načrt:* omejitev hkratnih povezav na napravo; Object Storage + CDN, ko bo uporabnikov več (SCALING.md).

**Zakaj je pomembno**
Pred zaprto beto mora strežnik zdržati, da več otrok hkrati gleda svojega psa, ne da bi se aplikacija za starše ustavila. In administracija mora biti dovolj hitra za vsakodnevno delo.

**Kako to povedati**
- 👩 *"Video vašega kužka se naloži takoj, aplikacija pa ostane odzivna, tudi ko si ga ogleduje več otrok hkrati."*
- 💼 *"Infrastruktura za beto: produkcijski PHP runtime in strežba medijev brez dodatnih stroškov (brez CDN), z jasno potjo do Object Storage + CDN ob rasti."*
- 🛠 *"Laravel na PHP-FPM + OPcache (validate_timestamps=0) v multi-stage Docker sliki, Caddy php_fastcgi + handle_response na X-Accel-Redirect za podpisane videe, gradnja in smoke test slike pred maintenance mode, samodejni rollback slike."*

## 2026-10-05 — Starši se lahko sami registrirajo

**Kaj se je zgodilo:** Do zdaj je račun za starša lahko ustvaril samo razvijalec. Zdaj ga starš ustvari sam v aplikaciji: **"Sem starš" → "Nimate računa? Registracija"** → ime, e-pošta, geslo (dvakrat, s prikazom/skritjem) in kljukica **"Strinjam se s pogoji uporabe in politiko zasebnosti"**. Takoj zatem je prijavljen in vidi prazno nadzorno ploščo z gumbom **"Dodaj otroka"**.
- Ob registraciji nastane **družina v časovnem pasu telefona** — po njem tečejo tihe ure, polnoč in okna hranjenja.
- Geslo: **vsaj 10 znakov, velike in male črke ter številka**; hranimo ga samo zgoščenega. Čas strinjanja s pogoji se shrani.
- Zaščita pred zlorabo: največ **5 registracij na minuto in 20 na uro** z enega naslova IP; e-pošta se primerja ne glede na velike/male črke.
- Registracija ustvari **samo starševski račun** — otroški račun (žeton "otrok") na ta način ni mogoč; otrok se še vedno prijavi samo s PIN-om.
- **Številke:** 19 novih testov na strežniku (skupaj **777 zelenih**), 26 novih v aplikaciji (skupaj **520 zelenih**).
- *Načrt:* potrditev e-pošte in pozabljeno geslo (M2-10b), prijava z Apple / Google (M2-10c). **Pogoji uporabe in politika zasebnosti morata biti napisana pred beto** (povezavi sta zdaj le mesti).

**Zakaj je pomembno**
Brez tega ni bete: vsaka družina mora sama priti do računa. To je prvi korak lijaka "naloži → registriraj → dodaj otroka → pes se rodi".

**Kako to povedati**
- 👩 *"Račun ustvarite v pol minute: ime, e-pošta, geslo. O otroku ne vprašamo ničesar razen vzdevka — in to šele, ko ga dodate."*
- 💼 *"Samopostrežna registracija staršev je v aplikaciji — prvi korak aktivacijskega lijaka; Apple/Google prijava sledi."*
- 🛠 *"Laravel FormRequest + servis v eni transakciji (uporabnik + družina + Sanctum žeton z eno sposobnostjo), Password::defaults brez zunanjih klicev, per-IP throttle (IPv6 /64), e-pošta lower-case, Expo zaslon z validacijo, ki zrcali strežnik."*

## 2026-10-05 — Kuža ob rojstvu dobi fotografijo in svoje videe (shranjene pri nas)

**Kaj se je zgodilo:** David je v AI laboratoriju izbral modela: **Nano Banana Pro** za fotografijo psa in **Kling 3.0 Pro** za videe. Strežnik zdaj ob rojstvu sam naredi celoten paket (v aplikaciji se predvajanje doda v naslednjem koraku):
- **Fotografija** po "DNK" psa (pasma + izžrebane lastnosti), pokončna 9:16.
- Ko otrok podpiše pogodbo (rojstvo), iz te fotografije nastanejo **kratki 5-sekundni videi brez zvoka**, v katerih je vedno **isti pes** — mirna kamera, subtilno, realistično gibanje, brez ljudi in besedila. Brezplačni mešanček dobi **2 videa** (miruje, spi), plačljiva pasma (izziv) **vseh 6** (miruje, spi, utrujen, lačen, bolan, igriv). *(Razdelitev je Claudov predlog in čaka Davidovo potrditev.)*
- **Vse se shrani na naš strežnik v EU**, preden ga otrok vidi: strežnik datoteko prenese samo z naslovov fal.ai, preveri velikost (do 25 MB slika, 60 MB video) in da je res slika / video. Aplikacija dobi samo **naše povezave, ki veljajo 60–90 minut** — nikoli povezav na zunanjo storitev.
- **Strošek na psa (ocena po ceniku fal):** ≈ **1,27 $** za mešančka (0,15 $ slika + 2 × 0,56 $ video), ≈ **3,51 $** za plačljivo pasmo. Še vedno velja dnevna (5 $) in mesečna (50 $) meja — če je dosežena, kuža živi naprej brez novega videa, sistem pa naslednji dan nadaljuje. Psi, rojeni prej, dobijo medije samo, ko jih administrator ročno sproži (z ogledom stroška vnaprej).
- Administrator vidi za vsakega psa sliko, videe, ceno in morebitno napako ter lahko posamezen video ali sliko naredi znova.
- **Številke:** 47 novih avtomatskih testov, strežnik skupaj **749 zelenih**; mobilna aplikacija 494 zelenih.

**Zakaj je pomembno**
Kuža ni več ikona — je *tvoj* pes, ki diha, spi in se igra, vedno isti. Hkrati je strošek predvidljiv: videi se naredijo enkrat ob rojstvu, potem jih aplikacija samo predvaja (brez novega plačila za vsak ogled), in vsi mediji so pod našim nadzorom.

**Kako to povedati**
- 🧒 *"Tvoj kuža ima svojo fotografijo in videe — vidiš ga, kako mirno sedi in kako spi."*
- 👩 *"Slike in videe ustvari umetna inteligenca brez kakršnihkoli podatkov o vašem otroku in jih hranimo na strežniku v EU. Povezave do njih veljajo le kratek čas in jih dobi samo vaša družina."*
- 💼 *"Izbrana najboljša realistična modela; strošek medijev ≈ 1,27 $ na brezplačnega in ≈ 3,51 $ na plačljivega psa, enkratno ob rojstvu, z dnevno/mesečno mejo — brez stroška na ogled."*
- 🛠 *"Pipeline: slika → prenos na lasten disk → videi po stanjih prek fal queue + podpisan webhook → prenos → podpisani, časovno omejeni URL-ji z Range podporo za iOS. Vsak korak idempotenten (atomski 'claim' reže), proračun preverjen pred vsakim klicem."*
- Videi se naredijo šele ob rojstvu (podpis pogodbe), zato pes, ki se nikoli ne rodi, stane samo sliko.
- Opomba: Kling 3.0 Pro nima nastavitve ločljivosti (video sledi sliki 9:16 v izvorni ločljivosti); predvajanje v aplikaciji je *načrt* (naslednja mobilna naloga).

## 2026-10-04 — Vsak kuža je unikaten + AI laboratorij z omejitvijo stroškov

**Kaj se je zgodilo:** pripravili smo temelje za AI slike in videe psov (strežnik in administracija; v aplikaciji se ne spremeni nič, dokler David ne izbere modelov).
- **Unikaten videz.** Do zdaj so bili vsi mešančki skoraj enaki (3 različice). Zdaj vsak nov pes ob rojstvu "izžreba" svojo kombinacijo lastnosti: velikost, postava, dolžina in barva dlake, vzorec, lise, ušesa, oči, rep. Pri mešančku je možnih **več kot 300.000 kombinacij**, pri border collieju 4.680 (pasma ima manj dovoljenih različic; seznam je osnutek). Isti pes je vedno isti (žreb je ponovljiv), v isti družini pa dva psa iste pasme nikoli nista enaka.
- **Opis za AI = pasma + lastnosti + slog fotografije** (fotorealistično, dnevna svetloba, cel pes, preprosto domače ozadje, brez ljudi in besedila). Brez imen otrok ali katerihkoli osebnih podatkov.
- **AI laboratorij** (samo za administratorja): izbereš pasmo, 1–4 pse in več modelov hkrati — isti pes se izriše z vsakim modelom, slike so ena ob drugi s ceno in časom. Iz izbrane slike lahko narediš video stanja (spi, se igra, je lačen …) z več video modeli.
- **Modeli in cene (fal.ai, 4. 10. 2026):** slika od **0,006 $** (FLUX.1 schnell, sedanji) do **0,15 $** (Nano Banana Pro); kratek video (4–5 s) brez zvoka od **0,12 $** (Veo 3.1 Lite, 4 s) do **0,56 $** (Kling 3 Pro). Celoten paket ob rojstvu (slika + 6 videov) bi stal približno **0,73 $ do 3,51 $** na psa, odvisno od izbire (ocena po ceniku).
- **Varovalka stroškov:** vsak klic AI se najprej zapiše z ocenjeno ceno; ko bi presegel dnevno (5 $) ali mesečno (50 $) mejo, se ne izvede. Če je račun pri fal.ai prazen, sistem to prepozna in opozori v administraciji. Igra v obeh primerih teče naprej — kuža je le brez nove slike. Laboratorij ima svoj, ločen proračun (3 $ na dan), zato preizkušanje modelov nikoli ne vzame denarja za slike novih psov; slike, ustavljene zaradi meje, sistem naslednji dan poskusi znova.
- **Številke:** 47 novih avtomatskih testov, skupaj **684 zelenih** na strežniku.

**Zakaj je pomembno**
Pravi psi iste pasme si niso enaki — tudi virtualni ne smejo biti. Otrok mora čutiti, da je *njegov* kuža. Hkrati AI stane: brez meje porabe bi ena napaka lahko čez noč porabila stotine evrov. Zdaj je strošek viden, omejen in izmerjen pred izbiro modela.

**Kako to povedati**
- 🧒 *"Tvoj kuža je edini na svetu, ki je točno tak."*
- 👩 *"Vsak virtualni pes je drugačen, kot v resnici. Slike ustvari umetna inteligenca brez kakršnihkoli podatkov o vašem otroku."*
- 💼 Strošek AI na psa izmerjen pred lansiranjem (≈0,73–3,51 $ za osnovni paket), z vgrajeno dnevno/mesečno mejo; dodatni mediji bodo plačljivi z žetoni (*načrt*, M4-09) — AI strošek se pokrije sam.
- 🛠 *"Preden smo izbrali AI model, smo zgradili laboratorij: isti pes, štirje modeli, ena ob drugi, s ceno na sliko. Odločitev na podlagi podatkov, ne hypa."*
- Opomba: opisi videza pasem so še osnutek in bodo zamenjani s podatki iz uradnih standardov pasem (FCI/AKC, M1-19) — ne predstavljati kot uradne.

## 2026-10-04 — Starš vidi objektivno oceno: nova nadzorna plošča na pravih podatkih

**Kaj se je zgodilo:** ocena, ki jo je strežnik začel računati isti dan, je zdaj na zaslonu staršev — demo podatkov (izmišljena časovnica in tedenski graf) ni več. Nadzorna plošča je nova, **svetla** (kot bančna ali fitnes aplikacija) in ima za **vsakega otroka svojo kartico**: semafor z razlogom z besedami (*"Danes so zamujene že 3 rutine."*, *"Kuža je danes zbolel in je pri veterinarju."*), **Care Score** z velikim številom in *"31 od 36 rutin"* (bolezen pokaže odbitek −10), *"Teden 2 od 12"*, današnje rutine (opravljeno / še odprto / zamujeno) in **katera rutina je bila zamujena in kdaj** — *"Hrana · okno 07:00–09:00"*, *"Čiščenje · rok 14:30"* — po času družine, zadnjih 7 dni kot stolpci in stanje psa (4 vrednosti, "ustavljeno", "pri veterinarju", "čaka na podpis pogodbe"). Dokler ni še nobene rutine, piše pošteno *"Še ni dovolj podatkov"*. Tap na **"Podrobnosti"** odpre poročilo za **7 dni, 30 dni ali celih 12 tednov**: ocena obdobja, hrana / voda / čiščenje / sprehod posebej, vsak dan s koraki proti cilju (*"4.210 / 4.000 korakov"*), zamujene rutine, bolezni in časovnica z vzdevkom otroka, ki je kaj naredil (*"Luka nahranil(a) kužka · 07:15"*). V zavihku **Nadzor**: hard stop **za vsakega psa posebej** s potrditvijo, tihe ure, otroci in naprave ter **"Povabi drugega starša"** — 8-mestna koda (*"K7QM 2XPA, velja do 5. 10. ob 14:30"*) se pošlje prek telefona (SMS, WhatsApp …), drugi starš jo vnese pri sebi. Vse se posodablja **v živo** (vsak pes ima svoj zasebni kanal); če povezava pade, se pregled osveži vsakih 30 sekund. Avtomatski testi aplikacije: s 425 na **473 zelenih** (+48; 16 starih testov, ki so preverjali lastno kopijo starega semaforja, je zamenjal en pravi test), med njimi test, ki pade, če bi se v kodo vrnil kakršen koli demo podatek. **Zakaj je pomembno:** do zdaj je starš videl lepo, a izmišljeno sliko. Zdaj vidi številko, ki jo je zaslužil otrok — in točno, kaj je zamudil. To je obljuba PetPrep v izdelku: *objektiven dokaz*, ne občutek. **Kako povedati:** 👩 *"Odprete aplikacijo in v treh sekundah veste: zelena — vse v redu. Rumena — piše, kaj je bilo zamujeno in kdaj. Ne rabite spraševati 'si nahranil kužka?'."* · 👩 *(dva otroka)* *"Vsak otrok ima svojo kartico in svojo oceno. Takoj vidite, kdo skrbi in kdo se skriva."* · 👩👨 *"Povabite partnerja s kodo — oba vidita isto in oba lahko ustavita igro."* · 🧒 *"Mami in ati vidita, kaj si naredil za kužka — tudi to, kar si naredil dobro."* · 💼 *"Care Score je zdaj viden v izdelku: dnevna, tedenska in 12-tedenska ocena na otroka — merljiv rezultat programa."* · 🛠 *"TanStack Query kot edini vir stanja, kanal Reverb na psa s popravkom predpomnilnika + ponovno nalaganje izračunanih delov, 30 s polling brez povezave, neskončna paginacija časovnice, strogi TS brez `any` čez ohlapno generirano shemo."* (Preizkus na pravih napravah še čaka.)

## 2026-10-04 — Objektivna ocena: Care Score in semafor iz tega, kar je otrok res naredil

**Kaj se je zgodilo:** David je določil pravila ocenjevanja, strežnik jih zdaj računa (zasloni v aplikaciji *kmalu*). Vsak dan po času družine ima kuža **rutine**: vsako okno hranjenja (mešanček 2), 3 dolivanja vode, vsak "kakec" (počistiti v **2 urah, šteto samo izven tihih ur** — nered ob 7:30 pred šolo ima rok do 14:30) in dnevni sprehod. Rutina je opravljena ali zamujena — nič vmes. Kar se zgodi med hard stopom, pri veterinarju ali pred rojstvom, se otroku ne šteje; okno hranjenja, ki je v celoti v tihih urah, tudi ne. **Care Score** = opravljene / pričakovane rutine × 100, minus 10 za vsako bolezen (0–100). **Semafor** za vsakega otroka in psa: rdeča (pes odvzet, alarm "0 % več kot uro" ali danes zbolel), rumena (danes zamujene več kot 2 rutini), sicer zelena. Pri **skupnem psu** "pošten delež": vsak od dveh otrok nosi polovico rutin, šteje pa samo to, kar je naredil sam — če eden naredi vse, ima 100, drugi 0. Starš vidi za vsakega otroka še današnje zamujene rutine z uro, zadnjih 7 dni, "teden N od 12" in podrobno poročilo za 7, 30 ali 84 dni. Zaključeni dnevi se zapišejo in se ne spreminjajo več (sprememba tihih ur ne popravi preteklih ocen). **38 novih avtomatskih testov** (tudi dan prestopa ure 25. 10. s 25 urami), strežnik skupaj **621 zelenih**; nadzorna plošča za družino z 2 psoma porabi enako število poizvedb (41) za 7 ali 84 dni zgodovine. **Zakaj je pomembno:** to je jedro obljube PetPrep — *objektiven dokaz* pripravljenosti. Ocena ne temelji na občutku staršev ali na tem, koliko krat je otrok pritisnil gumb, ampak na tem, ali je bila vsaka obveznost opravljena pravočasno. **Kako povedati:** 👩 *"Ne rabite ugibati. Vsak dan vidite, ali je otrok nahranil kužka v oknu, dolil vodo, počistil v dveh urah in šel na sprehod. Šola in spanje se ne štejeta proti njemu. Na koncu dobite eno številko od 0 do 100."* · 👩 *(dva otroka)* *"Vsak otrok ima svojo oceno. Priden ne nosi lenega — in len se ne more skriti za pridnim."* · 🧒 *"Vsaka skrb, ki jo opraviš pravočasno, ti prinese točke. Če kuža zboli, izgubiš 10."* · 💼 *"Care Score je merljiv, preverljiv rezultat 12-tedenskega programa — osnova za certifikat, garancijo in partnerje (zavetišča, vzreditelji)."* · 🤝 *(zavetišča)* *"Otrok s certifikatom ima zapis 12 tednov dejanske skrbi, ne samo obljubo."* · 🛠 *"Routine ledger izpeljan iz obstoječih dogodkov, materializiran ob zaključku dneva po času družine (idempotentno, DST-safe), danes sproti z isto kodo; pošten delež 1/n po skrbnikih."*

## 2026-10-04 — Gumbi zares delujejo: hrana, voda, čiščenje in koraki gredo na strežnik

**Kaj se je zgodilo:** do danes je gumb "Hrani" v otroški aplikaciji samo na zaslonu prištel 20 % — nič se ni shranilo in pravila (okna hranjenja, voda 3× na dan) so veljala le na strežniku. Zdaj vse štiri skrbi tečejo prek strežnika: **hrana, voda in čiščenje** se pokažejo takoj, nato aplikacija vedno prevzame odgovor strežnika. Če ni čas za hrano, je gumb zasenčen in pod njim piše **"ob 17:00"** (po času družine, tudi ob prestopu ure); če otrok vseeno poskusi, dobi prijazno sporočilo, npr. *"Kuža bo lačen spet ob 17:00."*, *"Posoda je še polna. Novo vodo lahko daš ob 15:30."* ali *"Najprej pospravi za kužkom!"*. **Koraki** se pošiljajo sami — ob odprtju aplikacije, ob vrnitvi vanjo in vsakih 5 minut (iPhone pošlje vse današnje korake, Android jih šteje, ko je aplikacija odprta). **Zaklenjen zaslon** zdaj pride s strežnika: ko starš pritisne hard stop, se otrokov zaslon v živo zaklene ("Starš je ustavil igro") in se odklene, ko ga izklopi; ko je kuža bolan, piše *"Kuža je pri veterinarju do 18:30"*. Če povezava v živo pade, aplikacija stanje osveži vsakih 10 sekund. Avtomatski testi aplikacije: z 276 na **389 zelenih** (+113). **Zakaj je pomembno:** to je prvič, da otrokova skrb v aplikaciji res šteje — strežnik je edini vir resnice, zato ocena, opozorila in certifikat lahko temeljijo na tem, kar je otrok res naredil. **Kako povedati:** 🧒 *"Ko je čas za hrano, gumb zasveti. Če ni, ti kuža pove, kdaj bo spet lačen."* · 👩 *"Pravila niso na otrokovem telefonu, ampak na našem strežniku — zvijačenje z uro ne pomaga. Ko pritisnete hard stop, se otrokov zaslon zaklene v sekundi."* · 💼 *"Celotna igralna zanka (akcije → strežnik → dogodki v živo) zdaj teče od konca do konca."* · 🛠 *"TanStack Query kot edini vir stanja, optimistične mutacije z zamenjavo s stanjem iz odgovora (tudi pri 422/423), urejanje dogodkov po `emitted_at`, polling le brez naročnine na kanal."* (Preizkus na pravih napravah še čaka — iOS potrebuje novo gradnjo zaradi dovoljenja za gibanje.)

## 2026-10-04 — Aplikacija: "Sem otrok" in velika tipkovnica za kodo

**Kaj se je zgodilo:** isti dan kot strežnik je prijava s kodo prišla tudi v aplikacijo. Ob prvem odprtju sta zdaj dve jasni poti: **"Sem otrok"** (koda od staršev na veliki tipkovnici — šesta številka kodo pošlje, brez e-pošte in gesla) in **"Sem starš"** (e-pošta). Starš na nadzorni plošči tapne "Dodaj otroka", vpiše samo vzdevek (letnica rojstva je neobvezna; aplikacija izrecno pove "Ne potrebujemo e-pošte ali priimka"), izbere "Nov pes" ali "Pridruži se psu …" in dobi kodo prav za tega otroka. Na novem seznamu otrok vidi, za katerega psa skrbi vsak otrok in na koliko napravah je prijavljen, ter ima dva gumba: "Nova koda za prijavo" (nov telefon, isti kuža) in "Odjavi vse naprave" (izgubljen telefon, s potrditvijo). Napačna koda otroku pove samo "Ta koda ne deluje. Prosi starša za novo kodo.", po preveč poskusih pa tipkovnica pokaže odštevanje. Za ime naprave aplikacija pošlje samo model telefona, nikoli imena, ki ga je telefonu dal uporabnik (pogosto vsebuje otrokovo ime). Avtomatski testi aplikacije: z 201 na **268 zelenih**. **Zakaj je pomembno:** otroška prijava z e-pošto je iz aplikacije odstranjena — o otroku aplikacija ne vpraša ničesar razen vzdevka. **Kako povedati:** 👩 *"Vpišete vzdevek, otrok vtipka kodo — dve minuti in kuža čaka na podpis."* · 🧒 *"Tapni 'Sem otrok' in vtipkaj kodo od staršev na velike gumbe."* · 💼 *"Celoten tok brez otrokove e-pošte je zdaj v izdelku, ne le na strežniku."* · 🛠 *"PIN prijava kot TanStack mutacija, anonimna zahteva brez Bearer žetona, `awaiting_contract` po otroku iz odgovora prijave, 67 novih Jest testov."* (Preizkus na pravih napravah še čaka.)

## 2026-10-04 — Otrok brez e-pošte: prijava samo s kodo staršev

**Kaj se je zgodilo** (izvedba Davidove odločitve z 2. 10.; strežnik — zasloni v aplikaciji *kmalu*)
- **Otroški profil brez e-pošte in gesla.** Starš vpiše samo **vzdevek** (do 30 znakov, priimek ni potreben) in po želji **letnico rojstva**. To je vse, kar o otroku hranimo.
- **Koda za točno tega otroka.** Starš izbere otroka (in po želji psa, h kateremu se pridruži) in dobi 6-mestno kodo, ki velja 15 minut in samo enkrat. Otrok jo vtipka na svoji napravi — brez računa.
- **Nov telefon? Nova koda.** Ista koda za že povezanega otroka ga samo prijavi na novi napravi; kuža ostane isti. Največ 3 naprave na otroka; starš lahko otroka z enim klikom odjavi z vseh naprav (izgubljen telefon).
- **Varnost kode:** na strežniku je shranjena samo zgoščena vrednost kode (ne koda sama); napačna, potekla in že uporabljena koda dobijo **enak odgovor**, zato ni mogoče ugibati, kateri otroci ali kode obstajajo; 10 napačnih poskusov z istega naslova ali 100 skupaj v 15 minutah → premor.
- **Ločene vloge na ravni žetona:** prijava starša dobi "starševski" ključ, otroka "otroški" — otroški telefon ne more odpreti starševskih nastavitev, tudi če bi kdo poskusil. Obstoječi uporabniki ostanejo prijavljeni.
- **Številke:** 53 novih avtomatskih testov (ustvarjanje profila, koda za nov/skupni pes/novo napravo, vsak način neuspeha, zaklepanje, 3 naprave, odjava, ločitev družin, pogodba in kanal v živo z otroškim žetonom), skupaj **559 zelenih** na strežniku; aplikacija 201 zelenih.

**Zakaj je pomembno**
Otroci 7–12 let nimajo e-pošte in je ne bi smeli dajati aplikacijam. Evropska zakonodaja (GDPR čl. 8) in Applova kategorija "Kids" zahtevata, da o otroku zberemo čim manj. PetPrep zdaj o otroku ve samo vzdevek — in morda letnico rojstva.

**Kako to povedati**
- 👩 *"Vaš otrok ne potrebuje e-pošte ne gesla. Vpišete vzdevek, dobite kodo, otrok jo vtipka — to je vse. Izgubljen telefon? En dotik in otrok je odjavljen povsod."*
- 🧒 *"Ne rabiš e-pošte! Starši ti povejo kodo s 6 številkami in tvoj kuža te čaka."*
- 💼 *"Zasebnost po zasnovi: o mladoletnem uporabniku hranimo vzdevek in neobvezno letnico — nič drugega. To je pogoj za App Store Kids in zaupanje staršev."*
- 🤝 *(šole)* *"Otroci ne potrebujejo nobenega računa; starš upravlja vse."*
- 🛠 *"PIN-only prijava: HMAC kode namesto kode same, enoten odgovor za napačno/potečeno/porabljeno kodo, omejitev po IP in globalno, Sanctum abilities `parent`/`child` na skupinah poti, stari `*` žetoni delujejo naprej."*

---

## 2026-10-04 — Mama, oče, brat in sestra: PetPrep postane družinski

**Kaj se je zgodilo** (Davidova odločitev in prva faza na strežniku, isti dan)
- **Družina namesto para starš–otrok.** V eni družini je lahko več staršev (mama in oče) in več otrok. Vsi starši vidijo vse otroke in vse pse.
- **Svoj pes ali skupni pes.** Vsak otrok ima lahko svojega psa, ali pa brat in sestra skupaj skrbita za enega. Starš pri ustvarjanju PIN-a izbere "nov pes" ali "pridruži se psu …".
- **Pošteno do vsakega otroka.** Vsako dejanje (hrana, voda, čiščenje, koraki) se zapiše pod otroka, ki ga je naredil. Starš že dobi statistiko po otroku za zadnjih 7 dni; ocena, semafor in certifikat po otroku sledijo (*načrt* — formula čaka Davida).
- **Drugi starš se pridruži s kodo** (8 znakov, velja 24 ur, samo enkrat; po 5 napačnih poskusih 15 minut premora).
- **Otrok, ki se pridruži obstoječemu psu, podpiše svojo pogodbo** — pes se ne rodi znova; dokler ne podpiše, lahko skrbi samo drugi otrok.
- **Skupni sprehod:** koraki vseh otrok, ki skrbijo za psa, se seštejejo; vsak vidi svoj prispevek.
- **Cena na psa:** 49,99 € za 12-tedenski izziv na psa, skupni pes = ena cena, mešanček ostane brezplačen (plačila še niso zgrajena; pes je že "enota plačila" v bazi).
- **Obstoječi podatki so preneseni samodejno:** vsak starš dobi družino, njegovi otroci in psi gredo vanjo, pretekla dejanja se pripišejo otroku. Trenutna aplikacija deluje naprej brez posodobitve.
- **Številke:** 34 novih avtomatskih testov (prenos podatkov, dva starša z enako nadzorno ploščo, povabila — potek, ponovna uporaba, preveč poskusov —, skupni pes, ločena pogodba, seštevanje korakov, kdo sme poslušati kanal v živo), skupaj **498 zelenih** na strežniku; aplikacija 201 zelenih.
- Zasloni za družino v aplikaciji: *kmalu* (M2-01a).
- **David je potrdil vse štiri izbire** (en aktiven pes na otroka, seštevanje korakov, lastna pogodba vsakega otroka, pridružitev drugega starša samo s praznim računom). Po neodvisnem pregledu dodana varovala: brisanje družine nikoli ne izbriše psa ali otroka (baza to prepove), sočasno povezovanje in pridružitev se zaklepata v istem vrstnem redu, deploy med migracijo vklopi način vzdrževanja. Skupaj **506 zelenih testov** na strežniku.

**Zakaj je pomembno**
Doma o psu ne odloča en starš z enim otrokom. Ko se dva otroka pulita za kužka, PetPrep zdaj pokaže, kdo je res skrbel — priden ne nosi lenega. In oba starša vidita isto, brez posredovanja.

**Kako to povedati**
- 👩 *"Povabite partnerja s kodo in oba v živo vidita, kako gre otrokom. Pri skupnem psu točno veste, kdo ga je nahranil in kdo je šel na sprehod."*
- 🧒 *"Lahko imaš svojega kužka ali ga deliš z bratom ali sestro — vse, kar narediš ti, se šteje tebi. Na sprehod lahko greste skupaj!"*
- 💼 *"Cena je na psa, ne na otroka: družina z dvema otrokoma in dvema psoma je dva izziva; skupni pes ena cena. Model podatkov je že pripravljen za plačilo na psa."*
- 🛠 *"Družine, skrbniki psov in avtor vsakega dejanja; 'en aktiven pes na otroka' zagotavlja baza (delni unikatni indeks na kopiji `is_active`, ki jo vzdržujeta sprožilca). Stari stolpci ostanejo sinhronizirani, da stare verzije aplikacije delujejo."*

---

## 2026-10-04 — Najprej podpis, potem rojstvo

**Kaj se je zgodilo** (Davidova odločitev, isti dan)
- **Pes se rodi šele, ko otrok podpiše pogodbo.** Po vnosu PIN-a kuža že obstaja (izgled, slika), a čaka. Dokler pogodba ni podpisana, nič ne upada, ni kakcev, opomnikov ne kazni; vse akcije razen podpisa strežnik zavrne z razlogom "najprej podpiši pogodbo".
- **Trenutek podpisa = rojstni trenutek:** vse metrike 100 %, starost, ure in "rojstni dan" (energija polna do prve polnoči) začnejo teči takrat. Če otrok podpis odloži za dva dni, ne izgubi nič.
- Starš na nadzorni plošči vidi, da pes "čaka na pogodbo" (podatek je v API-ju, prikaz *kmalu*).
- Psi, ki so obstajali že prej, ostanejo rojeni in delujejo kot doslej.
- David je potrdil še tri pravila: najprej čiščenje, potem hrana in voda; voda 3× na dan z razmikom tudi čez polnoč; ena pogodba na psa.
- **Številke:** 25 novih avtomatskih testov (simulacija 26 ur "nerojenega" psa, rojstvo, upadanje po rojstvu), skupaj 432 zelenih.
- **Aplikacija:** otrok se zdaj res podpiše s prstom (črta na zaslonu), podpis gre na strežnik in pes se rodi šele, ko ga strežnik sprejme; če aplikacijo zapre pred podpisom, ga ob naslednjem zagonu počaka pogodba (31 novih testov v aplikaciji, skupaj 190).

**Zakaj je pomembno**
Pogodba ni več formalnost pred igro, ampak vrata v igro: brez obljube ni psa. Tako začne vsak otrok enako — s polnim, zdravim kužkom v trenutku, ko se zaveže.

**Kako to povedati**
- 🧒 *"Ko se podpišeš, se tvoj kuža rodi — od takrat naprej skrbiš zanj."*
- 👩 *"Simulacija začne šteti šele, ko otrok podpiše obljubo. Prej se nič ne more pokvariti."*
- 🛠 *"Nerojen pes = `born_at` null: tick in eskalacija ga filtrirata v SQL, podpis pod zaklepom vrstice ga 'rodi' v isti transakciji; obstoječi psi ostanejo rojeni brez migracije podatkov."*

---

## 2026-10-04 — Kužo v živo vidita samo otrok in njegov starš

**Kaj se je zgodilo**
- **Zasebni kanal v živo:** posodobitve psa (lakota, žeja, energija, higiena, opozorila) gredo zdaj po **zasebnem kanalu**. Strežnik ob vsaki prijavi na kanal preveri prijavni žeton aplikacije in pusti zraven **samo otroka, ki ima tega psa, in njegovega starša**. Drug otrok, drug starš ali neprijavljen obiskovalec dobi zavrnitev (preverjeno z avtomatskimi testi).
- **Prej** je bil kanal javen: kdor bi poznal javni ključ iz aplikacije in številko psa, bi lahko poslušal njegovo stanje (najresnejša najdba revizije 2. 10., P0). Luknja je zaprta.
- **Brez osebnih podatkov v sporočilu:** vsebina dogodka nosi samo stanje psa — nobenega imena, e-pošte ali številke uporabnika otroka.
- **En dogodek na spremembo:** ko se psu kaj zgodi, gre na telefon točno eno sporočilo (prej ob opozorilu tudi do tri). Manj baterije in prometa, jasnejša časovnica.
- **Igralna zanka ne more obstati zaradi sporočil:** pošiljanje teče v ozadju po posebni vrsti. Če strežnik za sporočila (Reverb) za hip odpove, se kuža še vedno pravilno postara vsako minuto; telefon dobi stanje ob naslednjem dogodku ali pri osvežitvi.
- **Številke:** 30 novih avtomatskih testov (prijava na kanal × 10 primerov, en dogodek na vsak korak eskalacije, oddaja šele po zapisu v bazo, zanka preživi izpad), skupaj 437 zelenih; v aplikaciji 11 novih testov (170 skupaj).

**Zakaj je pomembno**
Pri aplikaciji za otroke je zasebnost osnova, ne dodatek. Stanje psa razkrije ritem otrokovega dne (kdaj je doma, kdaj nahrani psa) — to sme videti samo družina.

**Kako to povedati**
- 👩 *"Kar se dogaja s kužo, vidite samo vi in vaš otrok. Nihče drug se ne more 'priklopiti' na vaš kanal — strežnik vsakič preveri, kdo sprašuje."*
- 💼 Popravljena kritična varnostna najdba revizije pred prvimi družinami; v sporočilih ni osebnih podatkov otroka (dobra osnova za GDPR / zaščito otrok).
- 🤝 Šole in veterinarji: podatki o otroku ne zapustijo družine.
- 🛠 *"Laravel Reverb: `PrivateChannel('pet.{id}')`, `/api/broadcasting/auth` z Sanctum bearer žetonom, avtorizacija v policyju. `ShouldBroadcast` na vrsti `broadcasts` z ločenim workerjem, ena točka oddaje `afterCommit`, payload je posnetek brez PII. Opazovalci modelov odstranjeni — en dogodek na spremembo stanja."*

---

## 2026-10-04 — Gumbi dobijo pravila: hrana ob pravem času, voda s premislekom

**Kaj se je zgodilo**
- **Otroški API je zgrajen** (strežnik): stanje psa, hranjenje, voda, čiščenje, pošiljanje korakov in podpis pogodbe. Aplikacija se nanj poveže v naslednjem koraku (M1-14) — *kmalu*.
- **Hrana samo ob pravem času:** zjutraj med 6. in 10. uro in zvečer med 17. in 21. uro (po uri družine), **enkrat v vsakem oknu**. Če otrok tapne ob 12:00, strežnik pove "naslednje okno ob 17:00". Pravilo drži tudi ob prestopu ure (25. 10. 2026 in 28. 3. 2027 — preverjeno s testi).
- **Voda 3× na dan, vsaj 3 ure narazen** — tudi čez polnoč (voda ob 23:30 → naslednja ob 2:30).
- **Najprej počisti:** dokler je kuža umazan, hrane in vode ni — kot na zaslonu, tako tudi na strežniku (čaka Davidovo potrditev).
- **Zaklenjeno, ko mora biti:** med starševskim premorom, boleznijo ali po odvzemu psa strežnik vsako akcijo zavrne in pove razlog (aplikacija pokaže pravi zaslon).
- **Pogodba o odgovornosti** se zdaj shrani (podpis s prstom, enkrat na psa, čas podpisa) — brez pošiljanja tretjim, v časovnici staršev se pokaže kot "pogodba podpisana".
- **Varnost podatkov igre:** vsaka akcija in vsako opozorilo delata z zaklenjeno vrstico psa — otrok, ki je ravnokar počistil, ne more dobiti bolezni zaradi "zastarelega branja" (popravek iz pregleda kode).
- **Številke:** 99 novih avtomatskih testov, skupaj 384 zelenih (5.417 preverjanj); 6 novih poti v API-ju.

**Zakaj je pomembno**
To je trenutek, ko igra dobi svoja prava pravila: pravi pes ne je, kadar se otroku zljubi, in ne pije vse vode naenkrat. Rutina (zjutraj, zvečer, čez dan) je bistvo tega, kar starš želi preveriti.

**Kako to povedati**
- 🧒 *"Kuža je lačen zjutraj in zvečer — takrat ga nahrani. Če je umazan, ga najprej počisti!"*
- 👩 *"Aplikacija ne dovoli 'hranjenja na zalogo'. Vidite, ali otrok res poskrbi zjutraj pred šolo in zvečer."*
- 💼 Pravila so v nastavitvah pasme (okna hranjenja, število voda, razmik), ne v kodi — vsako pasmo lahko uglasimo brez nove verzije aplikacije.
- 🛠 *"Vsaka otroška akcija: FormRequest → policy → servis z `SELECT … FOR UPDATE` → en zapis v dnevnik → en WebSocket dogodek po commitu. Okna v časovnem pasu družine, testirano čez oba prestopa ure."*

---

## 2026-10-03 — Od veterinarja čist, sprehod vsak dan

**Kaj se je zgodilo** (odločitvi Davida, isti dan)
- **Ozdravitev = nov začetek:** ko 12 ur "pri veterinarju" mine, se kuža vrne **čist (higiena 100 %)** in vse ure zanemarjanja začnejo teči znova. Lakota in žeja ostaneta — otrok mora takoj poskrbeti zanj. S tem je odpravljena "neskončna zanka bolezni", odkrita zjutraj: prej je pes po vrnitvi takoj spet zbolel.
- **Energija = dnevni sprehod:** 0 % po polnoči pomeni "danes še ni sprehoda", ne zanemarjanje. Ponoči zato ni več alarma staršu ob 1:00 in ne "bolnega" psa do prvega sprehoda. Ob polnoči (po uri družine) zapišemo včerajšnji sprehod: koraki, cilj, dosežen ali ne — podlaga za starševski pregled. Dan **brez enega samega koraka** → kuža zboli naslednje jutro ob koncu tihih ur (npr. ob 6:00). Nekaj korakov pod ciljem → samo zapis "cilj ni dosežen".
- **Varovalke:** rojstni dan psa, dan med hard stopom ali boleznijo in zamuda strežnika čez več polnoči nikoli ne povzročijo bolezni.
- **Pošteno štetje na grafu:** sprehod se v tedenskem grafu šteje enkrat na dan (ko je cilj dosežen), ne ob vsakem pošiljanju korakov.
- **Številke:** 22 novih testov (simulacije po 5 minut čez 1–2 dni, družina v Ljubljani, spanje 22–6), skupaj 278 zelenih (4.901 preverjanj).

**Zakaj je pomembno**
Igra mora biti stroga, a nikoli brezizhodna: kazen (bolezen) se konča in otrok dobi novo priložnost. Sprehod pa je kot pri pravem psu — vsak dan, ne vsako uro.

**Kako to povedati**
- 🧒 *"Ko se kuža vrne od veterinarja, je čist kot nov. Hitro mu daj hrano in vodo — in ne pozabi: sprehod vsak dan!"*
- 👩 *"Ponoči vas aplikacija ne bo budila zaradi sprehoda. Ob polnoči vidite, ali je otrok danes šel ven — in koliko korakov je naredil."*
- 💼 Pravila so zasnovana kot vzgojni cikel: opozorilo → posledica → nov začetek. Brez "game over" spirale, ki bi otroka odvrnila.
- 🛠 *"Dnevno pravilo teče v istem minutnem ticku z zaklepom vrstice, idempotentno (en zapis na psa in dan), začetek bolezni pa izračunamo iz tihih ur družine, tudi ob prestopu ure."*

---

## 2026-10-03 — Kuža zdaj hodi s tabo in včasih naredi nered

**Kaj se je zgodilo**
- **Gibanje:** energija psa je zdaj natanko toliko, kolikor je otrok danes prehodil: mešanček potrebuje 4.000 korakov (1.000 korakov = 25 %), Border Collie 10.000. Ob polnoči (po uri družine) se dan začne znova pri 0 %. Strežniški del je pripravljen; povezava z aplikacijo (sprejem korakov s telefona) pride v naslednjem koraku (M1-07).
- **Pošteno štetje:** največ 200 korakov na minuto. Če telefon po 5 minutah javi 2.000 novih korakov, jih upoštevamo 1.000, ostalo šele, ko je realno mogoče. Ponovljeno pošiljanje istih korakov ne šteje dvakrat.
- **Higiena:** kuža zdaj naključno naredi nered — mešanček 1× na dan, Border Collie 2× na dan, **nikoli med šolo ali spanjem**. Takrat higiena pade na 0 %, čiščenje jo vrne na 100 %. Prej je higiena (začasno) počasi padala 1,5 % na uro.
- **Pravila v nastavitvah, ne v kodi:** vse številke igre (hitrost lakote in žeje, cilj korakov, kolikokrat na dan naredi nered, okna hranjenja 6–10 in 17–21, voda 3× na dan z razmikom 3 ure) so v tabeli pasem in jih lahko spremenimo v administraciji brez nove verzije aplikacije.
- **Pravičnejša bolezen:** 6 ur do bolezni se zdaj šteje samo izven tihih ur. Primer: spanje 22–6, šola 8–13, otrok ne gre na sprehod → pes zboli ob 17:00 (ne že ob 6:00 zjutraj). 400 korakov ob 15:30 je dovolj, da ostane zdrav.
- **Številke:** 64 novih avtomatskih testov, skupaj 259 zelenih (4.806 preverjanj). 24-urna simulacija z vklopljenimi naključnimi dogodki še vedno da natančne čase iz specifikacije (mešanček lačen po 12 h 30 min, žejen po 10 h; BC po 8 h 20 min in 6 h 40 min).
- **Odkrito med delom (čaka na Davida):** ker energija vsako noč pade na 0 %, bi brez dodatnega pravila starš vsako noč ob 1:00 dobil alarm, pes pa bi po 12 urah "pri veterinarju" takoj spet zbolel. Predlogi so v dnevniku odločitev.

**Zakaj je pomembno**
Sprehod je tisto, kar starši pri pravem psu najbolj podcenjujejo — in edino, česar otrok ne more "odklikati". Nered ob naključnem času pa uči, da pes ne čaka na urnik.

**Kako to povedati**
- 👩 *"Energija kužka so dejanski koraki vašega otroka. Več kot 200 korakov na minuto ne štejemo, zato bližnjic ni."*
- 🧒 *"Tvoj kuža enkrat na dan naredi nered — nikoli, ko si v šoli ali spiš. Pobriši ga!"*
- 🛠 LinkedIn: *"Naključno, a ponovljivo: časi nereda so izžrebani z RNG, zasejanim s ključem aplikacije, psom in datumom — testi so deterministični, otrok pa časov ne more uganiti."*
- 💼 Vsa pravila igre so nastavljiva brez nove verzije aplikacije — hitro prilagajanje po prvih testnih družinah.
## 2026-10-03 — Aplikacija si zapomni prijavo, starš dobi zaslon "Dodaj otroka"

**Kaj se je zgodilo**
- **Ostaneš prijavljen:** ko aplikacijo zapreš in znova odpreš, te pričaka kratek zaslon "Nalagam …" in nato takoj pravi zaslon — otrok svojega kužka, starš nadzorno ploščo. Prej se je bilo treba ob vsakem zagonu znova prijaviti.
- **Brez signala ni odjave:** če telefon ob zagonu nima interneta, aplikacija pokaže "Ni povezave" in gumb "Poskusi znova" — seja ostane. Odjavi te samo, če strežnik prijavo res zavrne.
- **"Dodaj otroka":** starš, ki še nima povezanega otroka, na nadzorni plošči vidi izrazito kartico. Tap → velika koda, npr. **734 912**, z odštevanjem 15 minut, gumb "Nova koda" in kratka navodila. Ko otrok kodo vtipka na svojem telefonu, starševski zaslon v nekaj sekundah sam pokaže **"Otrok je povezan!"**.
- Če starš prehitro zahteva nove kode (več kot 5 na minuto), aplikacija pove, koliko sekund naj počaka, in prejšnja koda ostane veljavna.
- Odjava zdaj povsod deluje enako (starš, otrok): prekliče prijavo na strežniku, izbriše shranjeni ključ in počisti podatke v telefonu.
- **Številke:** 64 novih avtomatskih testov, skupaj 138 zelenih (prej 74).
- Še odprto (*načrt*): otrok se pred vnosom kode še vedno prijavi z e-pošto; prijava samo s PIN-om pride z M2-02.

**Zakaj je pomembno**
Družina, ki se mora vsak dan znova prijavljati, aplikacijo opusti v prvem tednu — 12-tedenski izziv pa stoji na vsakodnevni rutini. Zaslon "Dodaj otroka" je prvi korak, ki ga starš sploh naredi: brez njega se izziv ne more začeti.

**Kako to povedati**
- 👩 *"Tapnete 'Dodaj otroka', otroku poveste 6 številk in v minuti je kuža na njegovem telefonu."*
- 🧒 *"Ko naslednjič odpreš aplikacijo, te kuža že čaka."*
- 🛠 LinkedIn: *"Obnova seje v Expo: SecureStore → /api/user → ista pot kot ob prijavi. 401 pomeni odjavo, izpad omrežja pa NE — otroka ne odjaviš zato, ker je v tunelu."*
- 💼 Onboarding starš → otrok je zdaj en zaslon in ena koda — ključna točka v lijaku od prenosa do začetka izziva.

## 2026-10-03 — Kuža zdaj živi po slovenski uri

**Kaj se je zgodilo**
- Vsaka družina ima svoj časovni pas (privzeto Europe/Ljubljana). Tihe ure (šola, spanje), polnoč, ko se koraki postavijo na nič, in dnevi v starševskem tedenskem pregledu se zdaj računajo po lokalni uri družine. Prej je vse teklo po UTC, zato so bile tihe ure v Sloveniji zamaknjene za 1–2 uri.
- Pravilno deluje tudi ob premiku ure: v noči na 25. 10. 2026 trajajo tihe ure 22:00–06:00 9 realnih ur, v noči na 28. 3. 2027 pa 7 ur — tako kot jih doživi družina.
- Starš lahko časovni pas spremeni v nastavitvah (nov API), npr. ko je družina na počitnicah v tujini.
- **Številke:** 29 novih avtomatskih testov, skupaj 183 zelenih.

**Zakaj je pomembno**
Če kuža "zaspi" ob 20:00 namesto ob 22:00 ali se koraki ponastavijo ob 2. uri zjutraj, otrok in starš izgubita zaupanje v igro. Pravila morajo slediti uri na steni v kuhinji, ne strežniku.

**Kako to povedati**
- 👩 *"Ko nastavite spanje od 22:00 do 6:00, kuža spi točno takrat — tudi ko se ura premakne."*
- 🛠 LinkedIn: *"Shranjuj v UTC, računaj v lokalnem času. Ob jesenskem premiku ure 02:30 obstaja dvakrat, spomladi pa sploh ne — oboje imamo pokrito s testi."*
- 💼 Pripravljeno za širitev zunaj Slovenije: časovni pas je nastavitev družine, ne strežnika.

## 2026-10-03 — Pes je bil skoraj 2× prehitro lačen: nov "motor" igre

**Kaj se je zgodilo**
- Srce igre (izračun, kako hitro pes postaja lačen, žejen in umazan) je na novo napisano.
- Stara koda je majhne padce (npr. 0,53 %) zaokrožila navzgor na cel 1 % in čas merila od zadnje kakršnekoli spremembe psa. Posledica: mešanček je bil po 6 urah lačen do 10 % namesto 52 %, higiena pa sploh ni padala.
- Med "hard stopom" starša in med boleznijo psa so metrike zdaj zamrznjene (tudi ure zanemarjanja); ko se igra nadaljuje, pes ne "nadoknadi" zamujenega časa naenkrat.
- Starš na nadzorni plošči ne dobi več obvestila vsako minuto, ampak samo, ko se številka na zaslonu res spremeni (pri mešančku ~213× na dan namesto 1.440×).
- **Številke (24-urna simulacija minuto za minuto, avtomatski test):** mešanček je lačen (0 %) natanko po 12 h 30 min (spec 12,5 h), žejen po 10 h; border collie lačen po 8 h 20 min, žejen po 6 h 40 min — natanko po specifikaciji. Enak rezultat, če strežnik zamudi 3 ure. 154 avtomatskih testov zelenih (prej 123).
- Med hard stopom in boleznijo se ustavi tudi štetje zanemarjanja: če starš ustavi igro za 30 ur, pes ne konča v "zavetišču". Preostali čas se ohrani (pes, ki je bil lačen 20 h, ima po nadaljevanju še 4 h).
- Neodvisni AI pregled je pred oddajo našel 2 večji napaki (prepisovanje sočasnih sprememb, game over med hard stopom) in 3 manjše — vse odpravljene.

**Zakaj je pomembno**
Če pes zahteva hrano 2× pogosteje, kot bi jo pravi pes, otrok dobi opozorila že dopoldne in igra ne meri več pripravljenosti, ampak potrpežljivost. Ritem mora biti realističen, sicer certifikat odgovornosti ne pomeni ničesar.

**Kako to povedati**
- 👩 *"Virtualni kuža je lačen tako pogosto kot pravi pes, ne pogosteje. Ko ga začasno ustavite, počaka na vas."*
- 🛠 LinkedIn: *"Napaka zaokroževanja: 0,53 → 1 vsako minuto. Moj virtualni pes je bil 1,8× prehitro lačen. Rešitev: ločena ura za razpad (`last_decay_at`), metrike v plavajoči vejici, zaokroževanje šele ob prikazu."*
- 💼 Igralna mehanika je preverjena s simulacijo celega dne za obe pasmi — številke iz specifikacije so zdaj avtomatski test.

## 2026-10-03 — Prvi pravi avtomatizirani deploy

**Kaj se je zgodilo**
- Prvič v zgodovini projekta je nova verzija šla v produkcijo prek GitHub Actions: testi (123 + 72) → backup baze → migracije → nova verzija na `api.petprep.si`, vse z enim klikom.
- V živo so zdaj kriptografsko preverjeni AI videi in generiranje slike psa v ozadju.

**Kako to povedati**
- 💼 Od kode do produkcije v ~5 minutah, z avtomatskimi testi in ročno potrditvijo ustanovitelja.
- 🛠 Del zgodbe "pipeline, ki ni nikoli tekel": manjkajoči deploy ključ, dedikiran SSH ključ samo za CI, prvi zeleni deploy.

---

## 2026-10-02 — Varovalka pred produkcijo: vsaka sprememba je najprej stestirana

**Kaj se je zgodilo**
- Avtomatsko testiranje na GitHubu (CI) zdaj ob vsakem predlogu spremembe požene **123 testov zaledja** na pravi bazi PostgreSQL in **72 testov mobilne aplikacije** ter preverjanje tipov.
- Odkritje: prejšnja nastavitev avtomatskega deploya **ni nikoli zares tekla**. Datoteka je imela napako, zato je GitHub vsak zagon zavrnil, ne da bi kaj izvedel. Testi bi sicer tekli na napačni bazi (sqlite) in ne bi mogli uspeti.
- Produkcija se zdaj posodobi **samo ročno, z enim klikom**, in šele, ko so vsi testi zeleni. Merge kode ne gre več sam v živo.
- Mobilni testi so po sveži namestitvi spet delovali (72/72), poprej niso tekli niti enkrat. Mimogrede je bila odkrita napaka v postavitvi otroškega zaslona (video psa se ni raztegnil čez cel zaslon).

**Zakaj je pomembno**
Aplikacijo uporabljajo otroci. Nobena sprememba ne sme v živo, ne da bi jo preverili stroji in nato potrdil človek.

**Kako to povedati**
- 💼 Inženirska disciplina: CI na produkcijski bazi, ročna potrditev deploya, neodvisni AI pregled kode. Ena oseba + AI ekipa z varovalkami večje ekipe.
- 🛠 LinkedIn: *"Moj deploy pipeline je bil 'zelen' tedne, v resnici pa ni nikoli tekel. Ena vrstica YAML-a: `secrets` v `environment.url`."* + nasvet: uporabite `actionlint`.
- 👩 (posredno) *"Vsaka posodobitev je najprej stestirana in ročno potrjena."*

---

## 2026-10-02 — Varnost AI psa: noben tuj video ne pride na otrokov zaslon

**Kaj se je zgodilo**
- Webhooki fal.ai, ki aplikaciji sporočijo, da je AI video psa pripravljen, se zdaj preverjajo s kriptografskim podpisom (ED25519) in javnim ključem fal.ai. Brez veljavnega podpisa se ne zgodi nič.
- Vsak video mora pripadati zahtevku, ki ga je PetPrep res poslal (nova tabela `pet_media_jobs`). Isti dogodek se nikoli ne obdela dvakrat.
- Na otrokov zaslon pridejo samo videi z domen fal.ai (HTTPS, brez trikov z `@`, `\`, vrati ipd.).
- Generiranje AI slike psa teče v ozadju, zato povezovanje otroka s staršem ne čaka več na AI (prej do 120 s).
- Popravljena je napaka, zaradi katere se referenčna slika psa sploh ne bi shranila (klic na napačen fal.ai endpoint).
- **Številke:** 113 avtomatskih testov zelenih (prej 82); 36 novih testov (5 zastarelih je bilo zamenjanih) za podpise, ponovitve, rotacijo ključev in poskuse zlorabe URL-jev. Neodvisni AI pregledovalec je kodo pregledal in našel 3 pomembne izboljšave, ki so bile odpravljene pred oddajo.

**Zakaj je pomembno**
Na celotnem zaslonu otroka teče video. Če bi lahko kdorkoli podtaknil poljuben URL, bi otrok lahko videl karkoli. Zdaj je to kriptografsko onemogočeno.

**Kako to povedati**
- 👩 *"Vsak video vašega psa je preverjen, preden ga otrok vidi. Na zaslon pride samo to, kar je ustvaril PetPrep."*
- 💼 Varnost otrok je vgrajena v arhitekturo, ne dodana naknadno. To je pomembno za App Store kategorijo Kids in za zaupanje staršev (glavni nakupni dejavnik).
- 🤝 Za šole in zavetišča: aplikacija ne prikazuje tuje vsebine, nima oglasov in ne nalaga ničesar mimo našega preverjanja.
- 🛠 LinkedIn tema: *"Kako preverjamo fal.ai webhooke z ED25519 v Laravelu — in zakaj 'skrivnost v URL-ju' ni varnost."*
- 📣 Kratek video (*načrt*): pes "nalaga" svoj video, kljukica "preverjeno ✓".

---

## 2026-10-02 — Odločitve: cena, prijava otroka, jeziki

**Kaj je bilo odločeno**
- **12-tedenski PetPrep izziv: 49,99 €**, prvih **7 dni brezplačno**.
- **Mešanček ostane brezplačen za vedno.** Vsaka družina lahko preizkusi osnovno skrb za psa brez plačila.
- **Otrok se prijavi samo s PIN-om**, ki ga ustvari starš. Otrok ne potrebuje e-pošte, njegovi podatki pa so minimalni.
- Jeziki: **angleščina (privzeto) + slovenščina**.

**Zakaj je pomembno**
- Cena 49,99 € pozicionira PetPrep kot resno orodje za oceno pripravljenosti, ne kot igrico za 4,99 €. Višja cena pomeni večjo zavezanost staršev in otroka.
- Brezplačni mešanček odpravi tveganje za starša ("najprej poskusimo").
- Prijava brez e-pošte je zasebnost po zasnovi (GDPR za otroke).

**Kako to povedati**
- 👩 *"Preizkusite brezplačno. Mešanček je vaš za vedno. Če želite dokaz, da je otrok pripravljen na pravega psa, se odločite za 12-tedenski izziv."*
- 🧒 *"Starši ti dajo kodo, vtipkaš jo in tvoj kuža se rodi."*
- 💼 Freemium vstop + premium program (49,99 €) + backend ponudbe (Second Chance reset 19,99 €, *načrt*). Dvojni lijak: brezplačno → plačljivo po 7 dneh.
- 📣 Hook: *"Pes stane 1.500 € in 10 let. Preizkus stane 0 € za 7 dni."*

---

## 2026-10-02 — Prevzem projekta: od "100 % končano" do iskrene slike

**Kaj se je zgodilo**
- Claude (AI orkestrator) je prevzel vodenje razvoja, pregledal 13 poslovno-tehničnih dokumentov in celotno kodo (Laravel zaledje + Expo mobilna aplikacija).
- Prejšnji AI agent je trdil, da je MVP "100 % končan". Pregled je pokazal okoli **40–45 %**: lepo zgrajen uporabniški vmesnik, a otrok psa ni mogel zares nahraniti (gumbi niso bili povezani s strežnikom).
- S simulacijo igralne zanke je bila najdena matematična napaka: lakota bi padala **~2× prehitro** (po 6 urah 10 % namesto 52 %), higiena pa sploh ne.
- Postavljeni so bili temelji za AI razvojno ekipo: navodila za agente, 5 specializiranih vlog (backend, mobile, pregledovalec, devops, marketing), razvojni načrt M0–M5 in kanonska produktna specifikacija.
- Mobilni repozitorij je bil združen v en monorepo z ohranjeno zgodovino; gesla za podpisovanje aplikacije so bila odstranjena iz zgodovine.
- Produkcija že teče na `api.petprep.si` (Hetzner, Docker, samodejni TLS).

**Zakaj je pomembno**
Iskren pregled stanja na začetku prihrani tedne. Zgodba "AI je rekel, da je končano, pa ni bilo" je resnična in poučna za vsakogar, ki gradi z AI.

**Kako to povedati**
- 🛠 / 📣 LinkedIn (David osebno): *"Moj AI agent je rekel, da je aplikacija 100 % končana. Drugi AI jo je pregledal: 40 %. Kaj sem se naučil o vodenju AI razvojne ekipe."* (povezava na AI Builders / Vibe Coding 101 publiko)
- 💼 Proces: AI razvoj z neodvisnim AI pregledom, avtomatskimi testi in dokumentiranim načrtom = hitrost startupa z disciplino večje ekipe. Ena oseba + AI ekipa.
- 🤝 Za partnerje: transparentno vodenje, vsak korak dokumentiran.
- 👩 Še ne komunicirati (notranja zgodba).
